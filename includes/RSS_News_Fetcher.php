<?php
class Crane_RSS_News_Fetcher {

    /**
     * Fetch news from sports RSS and post to the blog
     */
    public static function fetch_and_post_news() {
        $api_key = get_option( 'crane_api_newsdata' );
        $results = array();

        if ( $api_key ) {
            // Priority: NewsData.io API
            $api_url = "https://newsdata.io/api/1/news?apikey={$api_key}&q=football&category=sports&language=en";
            $response = wp_remote_get( $api_url, array( 'timeout' => 15 ) );
            if ( ! is_wp_error( $response ) ) {
                $body = wp_remote_retrieve_body( $response );
                $data = json_decode( $body, true );
                if ( isset( $data['results'] ) ) {
                    $results = $data['results'];
                }
            }
        } 
        
        // Fallback: BBC Sport RSS if API fails or key is missing
        if ( empty( $results ) ) {
            $rss_url  = 'http://feeds.bbci.co.uk/sport/football/rss.xml';
            $rss_resp = wp_remote_get( $rss_url, array( 'timeout' => 15 ) );
            if ( ! is_wp_error( $rss_resp ) ) {
                $rss_body = wp_remote_retrieve_body( $rss_resp );
                $xml = simplexml_load_string( $rss_body );
                if ( $xml && isset( $xml->channel->item ) ) {
                    foreach ( $xml->channel->item as $item ) {
                        $results[] = array(
                            'title'       => (string) $item->title,
                            'link'        => (string) $item->link,
                            'description' => (string) $item->description,
                            'pubDate'     => (string) $item->pubDate,
                            'content'     => (string) $item->description, // RSS usually only has desc
                            'image_url'   => '' // RSS might not have easy images
                        );
                    }
                }
            }
        }

        if ( empty( $results ) ) {
            error_log( 'Crane News Fetcher: No news found (API or RSS).' );
            return false;
        }

        $imported = 0;
        $scraped_this_run = 0;
        $max_scrapes = 5; 

        foreach ( $results as $item ) {
            $title = sanitize_text_field( $item['title'] );
            $link  = esc_url_raw( $item['link'] );
            $guid  = md5( $link ); 

            // [Hardened] Enhanced Content Scraping Fallback
            $item_content = ! empty( $item['content'] ) ? $item['content'] : $item['description'];
            
            // If content is suspiciously short (shorter than 500 chars), try to scrape the source link
            if ( $scraped_this_run < $max_scrapes && strlen( strip_tags( $item_content ) ) < 500 && ! empty( $link ) ) {
                $scrap_res = wp_remote_get( $link, array( 'timeout' => 8 ) );
                if ( ! is_wp_error( $scrap_res ) && wp_remote_retrieve_response_code( $scrap_res ) === 200 ) {
                    $html = wp_remote_retrieve_body( $scrap_res );
                    $scraped_this_run++;
                    // Look for common article containers
                    if ( preg_match( '/<article[^>]*>(.*?)<\/article>/is', $html, $matches ) ) {
                        $full_body = $matches[1];
                    } elseif ( preg_match( '/<main[^>]*>(.*?)<\/main>/is', $html, $matches ) ) {
                        $full_body = $matches[1];
                    } else {
                        $full_body = $item_content;
                    }

                    // Clean up: Remove scripts, styles, and huge chunks of non-text
                    $full_body = preg_replace( '/<(script|style|nav|header|footer)[^>]*>.*?<\/\1>/is', '', $full_body );
                    $full_body = wp_kses_post( $full_body );
                    
                    if ( strlen( strip_tags( $full_body ) ) > strlen( strip_tags( $item_content ) ) ) {
                        $item_content = $full_body;
                    }
                }
            }

            $desc = wp_kses_post( $item_content );
            $date = ! empty( $item['pubDate'] ) ? $item['pubDate'] : current_time( 'mysql' );

            // Check if post already exists via our custom GUID meta
            $existing_post_query = new WP_Query( array(
                'post_type'      => 'post',
                'post_status'    => 'any',
                'meta_key'       => '_crane_rss_guid',
                'meta_value'     => $guid,
                'posts_per_page' => 1,
                'fields'         => 'ids' // Only get IDs for performance
            ) );

            if ( $existing_post_query->have_posts() ) {
                continue; // Skip if we already posted this news
            }

            // [Hardened] Defensive Check: Ensure we don't have blank titles or content
            if ( empty( $title ) || empty( $desc ) ) {
                continue;
            }

            // Prepare post content
            $content = $desc;
            // [Hardened] Meta tagging to identify the source if needed
            $post_data = array(
                'post_title'    => $title,
                'post_content'  => $content,
                'post_status'   => 'publish',
                'post_type'     => 'post',
                'post_author'   => 1, // Defaulting to Admin
                'post_date'     => $date ? $date : current_time( 'mysql' ),
            );

            // Insert the post
            $post_id = wp_insert_post( $post_data );

            if ( ! is_wp_error( $post_id ) ) {
                // Save our unique identifier to prevent duplicates next time
                update_post_meta( $post_id, '_crane_rss_guid', $guid );
                update_post_meta( $post_id, '_crane_rss_source', $link );
                
                // [Hardened] Sideload Featured Image
                $img_url = ! empty( $item['image_url'] ) ? $item['image_url'] : '';
                if ( ! empty( $img_url ) ) {
                    self::set_featured_image( $post_id, $img_url );
                }
                
                $imported++;
            }
        }

        error_log( "Crane RSS Fetcher: Imported {$imported} new sports articles." );
        return $imported;
    }

    /**
     * [Hardened] Sideload image from URL and set as Featured Image
     */
    private static function set_featured_image( $post_id, $url ) {
        require_once( ABSPATH . 'wp-admin/includes/image.php' );
        require_once( ABSPATH . 'wp-admin/includes/file.php' );
        require_once( ABSPATH . 'wp-admin/includes/media.php' );

        // Avoid timeout on large images
        $attachment_id = media_sideload_image( $url, $post_id, null, 'id' );

        if ( ! is_wp_error( $attachment_id ) ) {
            set_post_thumbnail( $post_id, $attachment_id );
        }
    }

    /**
     * Handle Manual Fetch from Admin
     */
    public static function handle_manual_fetch() {
        if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( $_POST['_wpnonce'], 'crane_manual_fetch_news' ) ) {
            wp_die( 'Security check failed.' );
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Unauthorized.' );
        }

        $imported = self::fetch_and_post_news();
        
        $redirect_url = admin_url( 'admin.php?page=crane-api-settings' );
        if ( $imported !== false ) {
            $redirect_url = add_query_arg( 'news_imported', $imported, $redirect_url );
        }
        
        wp_redirect( $redirect_url );
        exit;
    }
}
