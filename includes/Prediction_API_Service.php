<?php
/**
 * Prediction API Service for Crane Bets
 * Fetches live match data from API-Football (RapidAPI) and club logos from TheSportsDB
 * Caches aggressively to stay within 100 req/day free tier
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Crane_Prediction_API_Service {

    // API-Football via RapidAPI
    private static $api_host = 'api-football-v1.p.rapidapi.com';
    private static $api_base = 'https://api-football-v1.p.rapidapi.com/v3';

    /**
     * Boot the service: Hook ActionScheduler actions
     */
    public static function boot() {
        add_action( 'crane_sync_predictions_as', array( __CLASS__, 'sync_predictions' ) );
        add_action( 'crane_cleanup_old_predictions_as', array( __CLASS__, 'cleanup_old_predictions' ) );
    }

    // TheSportsDB (free, no key needed for basic)
    private static $logo_base = 'https://www.thesportsdb.com/api/v1/json/3';

    /**
     * Get the stored API key
     */
    private static function get_api_key() {
        if ( defined( 'CRANE_API_FOOTBALL_KEY' ) ) {
            return CRANE_API_FOOTBALL_KEY;
        }
        return get_option( 'crane_api_football_key', '' );
    }

    /**
     * Make a cached request to API-Football
     */
    private static function api_request( $endpoint, $params = array(), $cache_minutes = 5 ) {
        $api_key = self::get_api_key();
        if ( empty( $api_key ) ) return null;

        // Build cache key from endpoint + params
        $cache_key = 'crane_apif_' . md5( $endpoint . serialize( $params ) );
        $cached = get_transient( $cache_key );
        if ( $cached !== false ) return $cached;

        $url = self::$api_base . $endpoint;
        if ( ! empty( $params ) ) {
            $url .= '?' . http_build_query( $params );
        }

        $response = wp_remote_get( $url, array(
            'timeout' => 15,
            'headers' => array(
                'x-rapidapi-host' => self::$api_host,
                'x-rapidapi-key'  => $api_key,
            ),
        ) );

        if ( is_wp_error( $response ) ) {
            error_log( 'Crane API-Football Error: ' . $response->get_error_message() );
            return null;
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( $code !== 200 ) {
            error_log( 'Crane API-Football HTTP ' . $code . ': ' . wp_remote_retrieve_body( $response ) );
            return null;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( ! $body || empty( $body['response'] ) ) return null;

        // Cache the result
        set_transient( $cache_key, $body['response'], $cache_minutes * 60 );
        return $body['response'];
    }

    /**
     * Fetch a club logo from TheSportsDB (cached permanently)
     */
    public static function get_team_logo( $team_name ) {
        $cache_key = 'crane_logo_' . sanitize_title( $team_name );
        $cached = get_option( $cache_key );
        if ( $cached ) return $cached;

        // Try TheSportsDB search
        $url = self::$logo_base . '/searchteams.php?t=' . urlencode( $team_name );
        $response = wp_remote_get( $url, array( 'timeout' => 10 ) );

        if ( is_wp_error( $response ) ) return '';

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        $logo = '';

        if ( ! empty( $body['teams'][0]['strBadge'] ) ) {
            $logo = $body['teams'][0]['strBadge'];
        } elseif ( ! empty( $body['teams'][0]['strTeamBadge'] ) ) {
            $logo = $body['teams'][0]['strTeamBadge'];
        }

        // Cache permanently (logos don't change)
        if ( $logo ) {
            update_option( $cache_key, $logo, false );
        }

        return $logo;
    }

    /**
     * MAIN SYNC: Fetch today's fixtures and create/update prediction posts
     * This runs via WP Cron every 30 minutes
     */
    public static function sync_predictions() {
        $api_key = self::get_api_key();
        if ( empty( $api_key ) ) {
            error_log( 'Crane Predictions Sync: No API key set. Skipping.' );
            return;
        }

        // Get today's date in Lagos timezone
        $tz = new DateTimeZone( 'Africa/Lagos' );
        $today = ( new DateTime( 'now', $tz ) )->format( 'Y-m-d' );

        // Fetch fixtures for today
        $fixtures = self::api_request( '/fixtures', array(
            'date'     => $today,
            'timezone' => 'Africa/Lagos',
        ), 15 ); // Cache for 15 minutes

        if ( ! $fixtures ) {
            error_log( 'Crane Predictions Sync: No fixtures returned for ' . $today );
            return;
        }

        $synced = 0;

        // Get existing fixtures map [fixture_id => post_id]
        $existing_query = new WP_Query( array(
            'post_type'      => 'crane_prediction',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'post_status'    => 'any'
        ) );

        $existing_fixtures = array();
        if ( $existing_query->have_posts() ) {
            foreach ( $existing_query->posts as $pid ) {
                $fid = get_post_meta( $pid, 'fixture_id', true );
                if ( $fid ) $existing_fixtures[ $fid ] = $pid;
            }
        }
        wp_reset_postdata();

        foreach ( $fixtures as $fixture ) {
            $fixture_id   = $fixture['fixture']['id'];
            $status_short = isset($fixture['fixture']['status']['short']) ? $fixture['fixture']['status']['short'] : 'NS';
            $match_date   = isset($fixture['fixture']['date']) ? $fixture['fixture']['date'] : '';

            // Parse time
            $match_dt = new DateTime( $match_date, $tz );
            $time_display = $match_dt->format( 'H:i' );

            // Live status
            $live_statuses = array( '1H', '2H', 'HT', 'ET', 'P', 'LIVE', 'BT' );
            if ( in_array( $status_short, $live_statuses ) ) {
                $elapsed = isset($fixture['fixture']['status']['elapsed']) ? $fixture['fixture']['status']['elapsed'] : '';
                $time_display = 'LIVE ' . $elapsed . "'";
            } elseif ( in_array( $status_short, array( 'FT', 'AET', 'PEN' ) ) ) {
                $time_display = 'FT';
            }

            // Teams
            $home_name = isset($fixture['teams']['home']['name']) ? $fixture['teams']['home']['name'] : 'Home';
            $away_name = isset($fixture['teams']['away']['name']) ? $fixture['teams']['away']['name'] : 'Away';
            $home_logo = isset($fixture['teams']['home']['logo']) ? $fixture['teams']['home']['logo'] : '';
            $away_logo = isset($fixture['teams']['away']['logo']) ? $fixture['teams']['away']['logo'] : '';

            // If API-Football logos are missing, try TheSportsDB
            if ( empty( $home_logo ) ) $home_logo = self::get_team_logo( $home_name );
            if ( empty( $away_logo ) ) $away_logo = self::get_team_logo( $away_name );

            // League
            $league = isset($fixture['league']['name']) ? $fixture['league']['name'] : 'Unknown League';

            // Goals (for live/finished matches)
            $home_goals = isset($fixture['goals']['home']) ? $fixture['goals']['home'] : null;
            $away_goals = isset($fixture['goals']['away']) ? $fixture['goals']['away'] : null;
            if ( $home_goals !== null && $away_goals !== null && in_array( $status_short, array_merge( $live_statuses, array( 'FT', 'AET', 'PEN' ) ) ) ) {
                $time_display .= ' (' . $home_goals . '-' . $away_goals . ')';
            }

            // Odds (use defaults if not available from this endpoint)
            $odd1 = '—';
            $oddX = '—';
            $odd2 = '—';

            if ( isset( $existing_fixtures[ $fixture_id ] ) ) {
                // UPDATE existing post
                $post_id = $existing_fixtures[ $fixture_id ];
                wp_update_post( array(
                    'ID'         => $post_id,
                    'post_title' => $home_name . ' vs ' . $away_name,
                ) );
            } else {
                // CREATE new post
                $post_id = wp_insert_post( array(
                    'post_title'  => $home_name . ' vs ' . $away_name,
                    'post_type'   => 'crane_prediction',
                    'post_status' => 'publish',
                    'post_content' => '',
                ) );
            }
            wp_reset_postdata();

            if ( ! $post_id || is_wp_error( $post_id ) ) continue;

            // Set all meta fields matching front-page.php expectations
            update_post_meta( $post_id, 'fixture_id', $fixture_id );
            update_post_meta( $post_id, 'team1_name', $home_name );
            update_post_meta( $post_id, 'team2_name', $away_name );
            update_post_meta( $post_id, 'team1_logo', $home_logo );
            update_post_meta( $post_id, 'team2_logo', $away_logo );
            update_post_meta( $post_id, 'match_league', $league );
            update_post_meta( $post_id, 'match_time', $time_display );
            update_post_meta( $post_id, 'match_odd1', $odd1 );
            update_post_meta( $post_id, 'match_oddX', $oddX );
            update_post_meta( $post_id, 'match_odd2', $odd2 );
            update_post_meta( $post_id, 'match_status', $status_short );
            update_post_meta( $post_id, 'match_date', $today );

            // NEW: Fetch and Store Real API Prediction (Hardened)
            $existing_tip = get_post_meta( $post_id, '_crane_free_tip', true );
            static $pred_count = 0;
            $pred_limit = 10; // Max 10 premium API tips per sync cycle to save quota

            if ( empty( $existing_tip ) && $status_short === 'NS' && $pred_count < $pred_limit ) {
                $pred_data = self::api_request( '/predictions', array( 'fixture' => $fixture_id ) );
                
                // Array Safety Check (Fatal Error Prevention)
                if ( is_array( $pred_data ) && isset( $pred_data[0]['predictions'] ) ) {
                    $winner_name = !empty($pred_data[0]['predictions']['winner']['name']) ? $pred_data[0]['predictions']['winner']['name'] : '';
                    if ( $winner_name ) {
                        update_post_meta( $post_id, '_crane_free_tip', $winner_name . ' Win' );
                    }
                    
                    // Persist the entire rich analysis block for the Single Template
                    update_post_meta( $post_id, '_crane_prediction_analysis', json_encode( $pred_data[0] ) );
                    
                    $pred_count++;
                }
            }

            $synced++;
        }

        error_log( 'Crane Predictions Sync: ' . $synced . ' matches synced for ' . $today );
    }

    /**
     * Fetch and store odds for today's matches (separate call to save API quota)
     * Runs once per day
     */
    public static function sync_odds() {
        $api_key = self::get_api_key();
        if ( empty( $api_key ) ) return;

        $tz = new DateTimeZone( 'Africa/Lagos' );
        $today = ( new DateTime( 'now', $tz ) )->format( 'Y-m-d' );

        $odds_data = self::api_request( '/odds', array(
            'date'     => $today,
            'timezone' => 'Africa/Lagos',
            'bookmaker' => 8, // Bet365
        ), 360 ); // Cache for 6 hours (odds don't change that much)

        if ( ! $odds_data ) return;

        foreach ( $odds_data as $match_odds ) {
            $fixture_id = isset($match_odds['fixture']['id']) ? $match_odds['fixture']['id'] : 0;
            if ( ! $fixture_id ) continue;

            // Find our post
            $existing = new WP_Query( array(
                'post_type'   => 'crane_prediction',
                'meta_key'    => 'fixture_id',
                'meta_value'  => $fixture_id,
                'posts_per_page' => 1,
                'fields'      => 'ids',
            ) );

            if ( ! $existing->have_posts() ) { wp_reset_postdata(); continue; }
            $post_id = $existing->posts[0];
            wp_reset_postdata();

            // Parse 1X2 odds
            $bookmakers = isset($match_odds['bookmakers']) ? $match_odds['bookmakers'] : array();
            foreach ( $bookmakers as $bm ) {
                $bets = isset($bm['bets']) ? $bm['bets'] : array();
                foreach ( $bets as $bet ) {
                    if ( ( isset($bet['name']) ? $bet['name'] : '' ) === 'Match Winner' ) {
                        foreach ( $bet['values'] as $v ) {
                            if ( $v['value'] === 'Home' )  update_post_meta( $post_id, 'match_odd1', $v['odd'] );
                            if ( $v['value'] === 'Draw' )  update_post_meta( $post_id, 'match_oddX', $v['odd'] );
                            if ( $v['value'] === 'Away' )  update_post_meta( $post_id, 'match_odd2', $v['odd'] );
                        }
                        break 2; // Got what we need
                    }
                }
            }
        }

        error_log( 'Crane Odds Sync: Complete for ' . $today );
    }

    /**
     * Clean up old predictions (older than 2 days)
     * Runs daily to keep the homepage fresh
     */
    public static function cleanup_old_predictions() {
        $tz = new DateTimeZone( 'Africa/Lagos' );
        $cutoff = ( new DateTime( '-2 days', $tz ) )->format( 'Y-m-d' );

        $old_posts = new WP_Query( array(
            'post_type'   => 'crane_prediction',
            'meta_key'    => 'match_date',
            'meta_value'  => $cutoff,
            'meta_compare' => '<',
            'posts_per_page' => 500, // execution timeout block offset
            'fields'      => 'ids',
        ) );

        foreach ( $old_posts->posts as $pid ) {
            wp_delete_post( $pid, true );
        }
        wp_reset_postdata();

        error_log( 'Crane Cleanup: Removed predictions older than ' . $cutoff );
    }

    /**
     * Manual sync trigger from admin
     */
    public static function handle_manual_sync() {
        check_admin_referer( 'crane_manual_sync' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );

        self::sync_predictions();
        self::sync_odds();

        wp_redirect( admin_url( 'admin.php?page=crane-api-settings&crane_msg=Predictions+synced+successfully' ) );
        exit;
    }
}
