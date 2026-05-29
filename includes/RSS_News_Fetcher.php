<?php
class Crane_RSS_News_Fetcher {

    /**
     * Fetch news from sports RSS feeds or NewsData API and post directly to the blog
     */
    public static function fetch_and_post_news() {
        $api_key = get_option( 'crane_api_newsdata' );
        $results = array();

        // Browser impersonation header for scraping consistency
        $headers = array(
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/115.0.0.0 Safari/537.36'
        );

        if ( $api_key ) {
            // Priority: NewsData.io API
            $api_url = "https://newsdata.io/api/1/news?apikey={$api_key}&q=football&category=sports&language=en";
            $response = wp_remote_get( $api_url, array( 'timeout' => 15, 'headers' => $headers ) );
            if ( ! is_wp_error( $response ) ) {
                $body = wp_remote_retrieve_body( $response );
                $data = json_decode( $body, true );
                if ( isset( $data['results'] ) ) {
                    $results = $data['results'];
                }
            }
        } 
        
        // Fallback: Custom RSS Feeds configured in Settings page
        if ( empty( $results ) ) {
            $custom_rss = get_option( 'crane_custom_rss_feeds' );
            $rss_urls = array();

            if ( ! empty( $custom_rss ) ) {
                // Split by newlines and sanitize URLs
                $lines = explode( "\n", $custom_rss );
                foreach ( $lines as $line ) {
                    $line = trim( $line );
                    if ( ! empty( $line ) && filter_var( $line, FILTER_VALIDATE_URL ) ) {
                        $rss_urls[] = $line;
                    }
                }
            }

            // Default fallback if no custom RSS links are saved
            if ( empty( $rss_urls ) ) {
                $rss_urls[] = 'https://feeds.bbci.co.uk/sport/football/rss.xml';
                $rss_urls[] = 'https://www.skysports.com/rss/12040';  // Sky Sports Football
                $rss_urls[] = 'https://www.goal.com/feeds/en/news';   // Goal.com
            }

            // Loop through RSS links and aggregate articles
            foreach ( $rss_urls as $rss_url ) {
                $rss_resp = wp_remote_get( $rss_url, array( 'timeout' => 15, 'headers' => $headers ) );
                if ( is_wp_error( $rss_resp ) ) {
                    error_log( 'Crane News Fetcher: Error fetching RSS feed ' . $rss_url . ' — ' . $rss_resp->get_error_message() );
                    continue;
                }

                $rss_body = wp_remote_retrieve_body( $rss_resp );
                
                // Parse SimpleXML with error handling
                $previous_use_errors = libxml_use_internal_errors( true );
                $xml = simplexml_load_string( $rss_body );
                if ( ! $xml ) {
                    libxml_clear_errors();
                    libxml_use_internal_errors( $previous_use_errors );
                    continue;
                }
                libxml_use_internal_errors( $previous_use_errors );

                if ( isset( $xml->channel->item ) ) {
                    foreach ( $xml->channel->item as $item ) {
                        $title       = (string) $item->title;
                        $link        = (string) $item->link;
                        $description = (string) $item->description;
                        $pubDate     = (string) $item->pubDate;
                        
                        // Extract image URL from standard RSS structure or Media namespaces
                        $image_url = '';
                        
                        // 1. Parse standard <enclosure> tags
                        if ( isset( $item->enclosure ) ) {
                            $enc = $item->enclosure;
                            if ( isset( $enc['url'] ) && ( isset( $enc['type'] ) && strpos( (string) $enc['type'], 'image' ) !== false ) ) {
                                $image_url = (string) $enc['url'];
                            }
                        }
                        
                        // 2. Parse namespaces (e.g. <media:content> or <media:thumbnail>)
                        if ( empty( $image_url ) ) {
                            $namespaces = $item->getNameSpaces( true );
                            if ( isset( $namespaces['media'] ) ) {
                                $media = $item->children( $namespaces['media'] );
                                if ( isset( $media->content ) ) {
                                    foreach ( $media->content as $content_tag ) {
                                        if ( isset( $content_tag['url'] ) ) {
                                            $image_url = (string) $content_tag['url'];
                                            break;
                                        }
                                    }
                                }
                                if ( empty( $image_url ) && isset( $media->thumbnail ) ) {
                                    $image_url = (string) $media->thumbnail->attributes()->url;
                                }
                            }
                        }
                        
                        $results[] = array(
                            'title'       => $title,
                            'link'        => $link,
                            'description' => $description,
                            'pubDate'     => $pubDate,
                            'content'     => $description,
                            'image_url'   => $image_url
                        );
                    }
                }
            }
        }

        if ( empty( $results ) ) {
            error_log( 'Crane News Fetcher: No news found (API or RSS).' );
            return false;
        }

        // Allow script to run longer for scraping full articles
        if ( function_exists('set_time_limit') ) {
            @set_time_limit(300);
        }

        $imported = 0;
        $scraped_this_run = 0;
        $max_scrapes = 50; // Ensure all 5 articles are fully scraped

        // Fetch dynamic category mapping
        $target_category_slug = get_option( 'crane_news_import_category', '' );
        $category_id = 0;
        if ( ! empty( $target_category_slug ) ) {
            $cat_obj = get_category_by_slug( $target_category_slug );
            if ( $cat_obj ) {
                $category_id = $cat_obj->term_id;
            }
        }

        foreach ( $results as $item ) {
            if ( $imported >= 5 ) {
                break;
            }

            $title = sanitize_text_field( $item['title'] );
            $link  = esc_url_raw( $item['link'] );
            $guid  = md5( $link ); 

            // Prevent duplicates immediately
            $existing_post_query = new WP_Query( array(
                'post_type'      => 'post',
                'post_status'    => 'any',
                'meta_key'       => '_crane_rss_guid',
                'meta_value'     => $guid,
                'posts_per_page' => 1,
                'fields'         => 'ids'
            ) );

            if ( $existing_post_query->have_posts() ) {
                continue; // Skip already aggregate files
            }

            $item_content = ! empty( $item['content'] ) ? $item['content'] : $item['description'];
            $scraped_image = '';

            // Robust Content & Fallback Image Scraping
            if ( $scraped_this_run < $max_scrapes && ! empty( $link ) ) {
                $scrap_res = wp_remote_get( $link, array( 'timeout' => 12, 'headers' => $headers ) );
                if ( ! is_wp_error( $scrap_res ) && wp_remote_retrieve_response_code( $scrap_res ) === 200 ) {
                    $html = wp_remote_retrieve_body( $scrap_res );
                    $scraped_this_run++;
                    
                    $full_body = '';

                    // Match core article layouts
                    if ( preg_match( '/<article[^>]*>(.*?)<\/article>/is', $html, $matches ) ) {
                        $full_body = $matches[1];
                    } elseif ( preg_match( '/<div[^>]*class=["\'][^"\']*(article-body|entry-content|post-content|article-content)[^"\']*["\'][^>]*>(.*?)<\/div>/is', $html, $matches ) ) {
                        $full_body = $matches[2];
                    } elseif ( preg_match( '/<main[^>]*>(.*?)<\/main>/is', $html, $matches ) ) {
                        $full_body = $matches[1];
                    } else {
                        // Concat individual paragraph lines as secondary fallback
                        if ( preg_match_all( '/<p\b[^>]*>.*?<\/p>/is', $html, $p_matches ) ) {
                            $full_body = implode( "\n", $p_matches[0] );
                        }
                    }

                    if ( ! empty( $full_body ) ) {
                        // Strip structure widgets, navigation scripts, header, and footer elements
                        $full_body = preg_replace( '/<(script|style|nav|header|footer|iframe|form|button|aside)[\s>].*?<\/\1>/is', '', $full_body );
                        // Strip metadata, byline, sharing and author blocks
                        $full_body = preg_replace( '/<(div|section|span|p|ul|li)\b[^>]*class=["\'][^"\']*(byline|author|meta|datetime|share-bar|social-share|entry-meta|post-meta|article-meta|author-card|author-details|article-info|published-date|date-published|reporter|correspondent)[^"\']*["\'][^>]*>.*?<\/\1>/is', '', $full_body );
                        $item_content = $full_body;
                    }

                    // Scrape Featured Image from Webpage Meta Properties (Always try for high quality)
                    if ( preg_match( '/<meta[^>]+(?:property|name)=["\']og:image["\'][^>]+content=["\']([^"\']+)["\']/i', $html, $img_matches ) ) {
                        $scraped_image = esc_url_raw( $img_matches[1] );
                    } elseif ( preg_match( '/<meta[^>]+content=["\']([^"\']+)["\'][^>]+(?:property|name)=["\']og:image["\']/i', $html, $img_matches ) ) {
                        $scraped_image = esc_url_raw( $img_matches[1] );
                    } elseif ( preg_match( '/<meta[^>]+(?:property|name)=["\']twitter:image["\'][^>]+content=["\']([^"\']+)["\']/i', $html, $img_matches ) ) {
                        $scraped_image = esc_url_raw( $img_matches[1] );
                    } else {
                        // Fetch first normal post body img
                        if ( preg_match( '/<img[^>]+src=["\']([^"\']+\.(?:jpg|jpeg|png|webp))["\']/i', $full_body ?: $html, $img_matches ) ) {
                            $scraped_image = esc_url_raw( $img_matches[1] );
                        }
                    }
                    // If scraping yielded nothing useful, keep original RSS description as $item_content
                } else {
                    // Scrape blocked (403, redirect, WP_Error) — keep RSS description unchanged
                    $http_code = is_wp_error( $scrap_res ) ? $scrap_res->get_error_message() : wp_remote_retrieve_response_code( $scrap_res );
                    error_log( 'Crane News Fetcher: Could not scrape ' . $link . ' (' . $http_code . ') — falling back to RSS description.' );
                }
            }

            // --- STRICT SANITIZATION LAYER & LINK STRIPPING ---
            
            // 1. First, strip massive junk blocks like figures, asides, and media placeholders
            $item_content = preg_replace( '/<(script|style|nav|header|footer|iframe|form|button|aside|figure|figcaption|video|audio)[\s>].*?<\/\1>/is', '', $item_content );
            // Strip metadata, byline, sharing and author blocks from raw text as well
            $item_content = preg_replace( '/<(div|section|span|p|ul|li)\b[^>]*class=["\'][^"\']*(byline|author|meta|datetime|share-bar|social-share|entry-meta|post-meta|article-meta|author-card|author-details|article-info|published-date|date-published|reporter|correspondent)[^"\']*["\'][^>]*>.*?<\/\1>/is', '', $item_content );
            
            // 2. Extract ONLY the paragraph texts. This eliminates random div-based bylines and wrappers.
            // Using \b boundary so we don't accidentally match <picture> or other tags starting with 'p'
            if ( preg_match_all( '/<p\b[^>]*>(.*?)<\/p>/is', $item_content, $p_matches ) ) {
                $raw_paragraphs = $p_matches[1];
            } else {
                $raw_paragraphs = preg_split( '/(<\/p>|<br\s*\/?>|<\/div>)/i', $item_content );
            }

            $cleaned_paragraphs = array();
            
            // Expanded blacklist targeting standard news site boilerplate
            $blacklist = array(
                'advertisement', 'sponsored', 'click here', 'read more', 'follow us', 
                'originally published', 'copyright', 'all rights reserved', 'signup',
                'newsletter', 'subscribe', 'terms of use', 'privacy policy',
                'image source,', 'image caption,', 'figure caption,', 'image caption',
                'enable javascript', 'video can not be played', 'related topics',
                'more on this story', 'latest arsenal news', 'latest news', 'bbc sport'
            );

            // Regex for specific dynamic junk (e.g. "By [Name] [Role]", "Published 45 minutes ago")
            $junk_patterns = array(
                '/^By\s+[A-Z][a-zA-Z\s]+(reporter|correspondent|writer)$/i',
                '/^Published\s+\d+\s+(minutes|hours|days)\s+ago$/i',
                '/^Published\s+\d{1,2}\s+(January|February|March|April|May|June|July|August|September|October|November|December)\s+\d{4}$/i'
            );

            foreach ( $raw_paragraphs as $p ) {
                // Strip links natively to prevent anchor bleed
                $p = preg_replace( '/<a\b[^>]*>(.*?)<\/a>/is', '$1', $p );
                
                // Clean leading byline/metadata prefix if any
                $p_text = trim( strip_tags( $p ) );
                $metadata_prefix_pattern = '/^(?:[Ff]ootball\s+[Rr]eporter|[Pp]ublished\s*\d*\s*(?:minutes|hours|days|weeks)?\s*ago|[Bb]y\s*[A-Z][a-zA-Z]*(?:\s+[A-Z][a-zA-Z]*){0,3}|[Ss]enior\s+[Ff]ootball\s+[Cc]orrespondent|[Ff]ootball\s+[Cc]orrespondent|[Rr]eporter|[Cc]orrespondent|[Ww]riter|[Ee]ditor|\s)+/';
                $p_cleaned_text = preg_replace( $metadata_prefix_pattern, '', $p_text );
                
                if ( empty( $p_cleaned_text ) || strlen( $p_cleaned_text ) < 15 ) {
                    continue; // Skip tiny empty paragraphs or metadata-only paragraphs
                }
                
                // Update paragraph text to exclude the stripped metadata prefix
                if ( strlen( $p_cleaned_text ) < strlen( $p_text ) ) {
                    $p = $p_cleaned_text;
                }
                
                $p_stripped = trim( strip_tags( strtolower( $p ) ) );

                $should_strip = false;
                
                // Check substring blacklist
                foreach ( $blacklist as $word ) {
                    if ( strpos( $p_stripped, $word ) !== false ) {
                        $should_strip = true;
                        break;
                    }
                }

                // Check regex patterns
                if ( ! $should_strip ) {
                    foreach ( $junk_patterns as $pattern ) {
                        if ( preg_match( $pattern, trim( strip_tags( $p ) ) ) ) {
                            $should_strip = true;
                            break;
                        }
                    }
                }

                if ( ! $should_strip ) {
                    // Re-wrap in clean P tag
                    $cleaned_paragraphs[] = '<p>' . trim( strip_tags( $p, '<strong><em><b><i>' ) ) . '</p>';
                }
            }
            
            $sanitized_content = implode( "\n", $cleaned_paragraphs );

            // Defensive check — if cleaned content is too short (less than 100 characters), skip it
            $content_text = strip_tags( $sanitized_content );
            if ( strlen( $content_text ) < 100 ) {
                error_log( 'Crane News Fetcher: Skipping "' . $title . '" — insufficient content after cleaning (' . strlen( $content_text ) . ' chars).' );
                continue;
            }

            $admin_users = get_users( array( 'role' => 'administrator', 'fields' => 'ids', 'number' => 1 ) );
            $author_id   = ! empty( $admin_users ) ? $admin_users[0] : 1;

            $raw_date = ! empty( $item['pubDate'] ) ? $item['pubDate'] : 'now';
            $date = gmdate( 'Y-m-d H:i:s', strtotime( $raw_date ) );

            $post_data = array(
                'post_title'    => $title,
                'post_content'  => $sanitized_content,
                'post_status'   => 'publish',
                'post_type'     => 'post',
                'post_author'   => $author_id,
                'post_date'     => $date,
            );

            // Assign Dynamic Category if mapped
            if ( $category_id > 0 ) {
                $post_data['post_category'] = array( $category_id );
            }

            // Insert post safely
            $post_id = wp_insert_post( $post_data );

            if ( $post_id && ! is_wp_error( $post_id ) ) {
                update_post_meta( $post_id, '_crane_rss_guid', $guid );
                update_post_meta( $post_id, '_crane_rss_source', $link );
                
                // Prefer scraped high-quality webpage image, fallback to RSS feed image
                $final_img = ! empty( $scraped_image ) ? $scraped_image : ( ! empty( $item['image_url'] ) ? $item['image_url'] : '' );
                
                // Enhance BBC low-quality thumbnails if we fell back to them
                if ( ! empty( $final_img ) && strpos( $final_img, 'bbci.co.uk' ) !== false ) {
                    $final_img = str_replace( '/240/', '/1024/', $final_img );
                    $final_img = str_replace( '/320/', '/1024/', $final_img );
                }
                
                if ( ! empty( $final_img ) ) {
                    $final_img = self::resolve_absolute_url( $final_img, $link );
                    self::set_featured_image( $post_id, $final_img );
                }
                
                $imported++;
            }
        }

        error_log( "Crane RSS Fetcher: Aggregated {$imported} news articles." );
        return $imported;
    }

    /**
     * Sideload image from URL and set as Featured Image
     */
    private static function set_featured_image( $post_id, $url ) {
        require_once( ABSPATH . 'wp-admin/includes/image.php' );
        require_once( ABSPATH . 'wp-admin/includes/file.php' );
        require_once( ABSPATH . 'wp-admin/includes/media.php' );

        // Sideload media asset to the server with proper timeouts
        $attachment_id = media_sideload_image( $url, $post_id, null, 'id' );

        if ( ! is_wp_error( $attachment_id ) ) {
            set_post_thumbnail( $post_id, $attachment_id );
        }
    }

    /**
     * Helper to resolve relative source URLs into absolute URLs
     */
    private static function resolve_absolute_url( $url, $base_url ) {
        if ( empty( $url ) || empty( $base_url ) ) {
            return $url;
        }

        // If already absolute with protocol
        if ( preg_match( '/^https?:\/\//i', $url ) ) {
            return $url;
        }

        // If protocol-relative (starts with //)
        if ( strpos( $url, '//' ) === 0 ) {
            $parsed_base = parse_url( $base_url );
            $scheme = ! empty( $parsed_base['scheme'] ) ? $parsed_base['scheme'] : 'https';
            return $scheme . ':' . $url;
        }

        // Extract base components
        $parsed_base = parse_url( $base_url );
        $host = ! empty( $parsed_base['host'] ) ? $parsed_base['host'] : '';
        $scheme = ! empty( $parsed_base['scheme'] ) ? $parsed_base['scheme'] : 'https';
        
        if ( empty( $host ) ) {
            return $url;
        }

        // Absolute relative path (starts with single /)
        if ( strpos( $url, '/' ) === 0 ) {
            return $scheme . '://' . $host . $url;
        }

        // Relative relative path (e.g. img/pic.jpg)
        $path = ! empty( $parsed_base['path'] ) ? $parsed_base['path'] : '/';
        $dir = dirname( $path );
        if ( $dir === '.' || $dir === '/' || $dir === '\\' ) {
            $dir = '';
        }
        return $scheme . '://' . $host . rtrim( $dir, '/' ) . '/' . $url;
    }

    /**
     * Handle Manual Fetch from Admin Action
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
