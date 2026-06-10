<?php
/**
 * Prediction API Service for Crane Bets
 * Fetches live match data from API-Football (RapidAPI) and club logos from TheSportsDB
 * Caches aggressively to stay within 100 req/day free tier
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Crane_Prediction_API_Service {

    // API-Football Direct API
    private static $api_host = 'v3.football.api-sports.io';
    private static $api_base = 'https://v3.football.api-sports.io';

    /**
     * Boot the service: Hook ActionScheduler actions
     */
    public static function boot() {
        add_action( 'crane_sync_predictions_as_v2', array( __CLASS__, 'sync_predictions' ) );
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
                'x-apisports-key'  => $api_key,
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
     * Fetch a club logo (cached permanently).
     * Uses a fast built-in popular teams mapping, queries API-Football if available,
     * and strictly validates TheSportsDB results to avoid wrong logo assignments.
     */
    public static function get_team_logo( $team_name ) {
        $cache_key = 'crane_logo_' . sanitize_title( $team_name );
        $cached = get_option( $cache_key );
        if ( $cached !== false ) {
            return $cached === 'none' ? '' : $cached;
        }

        $team_norm = strtolower( trim( preg_replace( '/[^a-z0-9]/i', '', $team_name ) ) );

        // 1. Built-in Popular Team Mapping to prevent any API calls for top clubs
        $popular_logos = array(
            'parissaintgermain'    => 'https://media.api-sports.io/football/teams/85.png',
            'psg'                  => 'https://media.api-sports.io/football/teams/85.png',
            'parissg'              => 'https://media.api-sports.io/football/teams/85.png',
            'saintetienne'         => 'https://media.api-sports.io/football/teams/228.png',
            'nice'                 => 'https://media.api-sports.io/football/teams/84.png',
            'ogcnice'              => 'https://media.api-sports.io/football/teams/84.png',
            'asse'                 => 'https://media.api-sports.io/football/teams/228.png',
            'stetienne'            => 'https://media.api-sports.io/football/teams/228.png',
            'brest'                => 'https://media.api-sports.io/football/teams/106.png',
            'rennes'               => 'https://media.api-sports.io/football/teams/94.png',
            'staderennais'         => 'https://media.api-sports.io/football/teams/94.png',
            'lille'                => 'https://media.api-sports.io/football/teams/79.png',
            'lens'                 => 'https://media.api-sports.io/football/teams/116.png',
            'reims'                => 'https://media.api-sports.io/football/teams/93.png',
            'toulouse'             => 'https://media.api-sports.io/football/teams/96.png',
            'montpellier'          => 'https://media.api-sports.io/football/teams/82.png',
            'strasbourg'           => 'https://media.api-sports.io/football/teams/95.png',
            'lehavre'              => 'https://media.api-sports.io/football/teams/1063.png',
            'auxerre'              => 'https://media.api-sports.io/football/teams/77.png',
            'angers'               => 'https://media.api-sports.io/football/teams/76.png',
            'nantes'               => 'https://media.api-sports.io/football/teams/83.png',
            'arsenal'              => 'https://media.api-sports.io/football/teams/42.png',
            'chelsea'              => 'https://media.api-sports.io/football/teams/49.png',
            'manchesterunited'     => 'https://media.api-sports.io/football/teams/33.png',
            'manunited'            => 'https://media.api-sports.io/football/teams/33.png',
            'manutd'               => 'https://media.api-sports.io/football/teams/33.png',
            'manchestercity'       => 'https://media.api-sports.io/football/teams/50.png',
            'mancity'              => 'https://media.api-sports.io/football/teams/50.png',
            'liverpool'            => 'https://media.api-sports.io/football/teams/40.png',
            'tottenham'            => 'https://media.api-sports.io/football/teams/47.png',
            'tottenhamhotspur'     => 'https://media.api-sports.io/football/teams/47.png',
            'realmadrid'           => 'https://media.api-sports.io/football/teams/541.png',
            'barcelona'            => 'https://media.api-sports.io/football/teams/529.png',
            'fcbarcelona'          => 'https://media.api-sports.io/football/teams/529.png',
            'atleticomadrid'       => 'https://media.api-sports.io/football/teams/530.png',
            'bayernmunich'         => 'https://media.api-sports.io/football/teams/157.png',
            'bayern'               => 'https://media.api-sports.io/football/teams/157.png',
            'dortmund'             => 'https://media.api-sports.io/football/teams/165.png',
            'borussiadortmund'     => 'https://media.api-sports.io/football/teams/165.png',
            'juventus'             => 'https://media.api-sports.io/football/teams/496.png',
            'acmilan'              => 'https://media.api-sports.io/football/teams/489.png',
            'milan'                => 'https://media.api-sports.io/football/teams/489.png',
            'intermilan'           => 'https://media.api-sports.io/football/teams/505.png',
            'inter'                => 'https://media.api-sports.io/football/teams/505.png',
            'napoli'               => 'https://media.api-sports.io/football/teams/492.png',
            'roma'                 => 'https://media.api-sports.io/football/teams/497.png',
            'asroma'               => 'https://media.api-sports.io/football/teams/497.png',
            'marseille'            => 'https://media.api-sports.io/football/teams/81.png',
            'olympiquemarseille'   => 'https://media.api-sports.io/football/teams/81.png',
            'monaco'               => 'https://media.api-sports.io/football/teams/91.png',
            'asmonaco'             => 'https://media.api-sports.io/football/teams/91.png',
            'lyon'                 => 'https://media.api-sports.io/football/teams/80.png',
            'olympiquelyonnais'    => 'https://media.api-sports.io/football/teams/80.png',
            'ajax'                 => 'https://media.api-sports.io/football/teams/194.png',
            'feyenoord'            => 'https://media.api-sports.io/football/teams/197.png',
            'psv'                  => 'https://media.api-sports.io/football/teams/197.png',
            'psveindhoven'         => 'https://media.api-sports.io/football/teams/197.png',
            'porto'                => 'https://media.api-sports.io/football/teams/229.png',
            'fcporto'              => 'https://media.api-sports.io/football/teams/229.png',
            'benfica'              => 'https://media.api-sports.io/football/teams/230.png',
        );

        if ( isset( $popular_logos[$team_norm] ) ) {
            $logo = $popular_logos[$team_norm];
            update_option( $cache_key, $logo, false );
            return $logo;
        }

        $logo = '';
        $api_failed = false;

        // 2. Try API-Football Search (100% accurate) if key is set
        $apif_key = self::get_api_key();
        if ( ! empty( $apif_key ) ) {
            // Introduce a 1-second sleep to prevent hitting the 10 req/min rate limit on free tier
            sleep(1);

            $is_rapidapi = ( strlen( $apif_key ) > 35 );
            if ( $is_rapidapi ) {
                $url = 'https://api-football-v1.p.rapidapi.com/v3/teams?search=' . urlencode( $team_name );
                $headers = array(
                    'x-rapidapi-key'  => $apif_key,
                    'x-rapidapi-host' => 'api-football-v1.p.rapidapi.com',
                );
            } else {
                $url = 'https://v3.football.api-sports.io/teams?search=' . urlencode( $team_name );
                $headers = array(
                    'x-apisports-key' => $apif_key,
                );
            }

            $response = wp_remote_get( $url, array(
                'timeout' => 12,
                'headers' => $headers,
            ) );

            if ( is_wp_error( $response ) ) {
                $api_failed = true;
            } else {
                $code = wp_remote_retrieve_response_code( $response );
                if ( $code !== 200 && $code !== 201 ) {
                    $api_failed = true;
                } else {
                    $body = json_decode( wp_remote_retrieve_body( $response ), true );
                    if ( ! empty( $body['errors'] ) ) {
                        $api_failed = true;
                    } elseif ( ! empty( $body['response'] ) && is_array( $body['response'] ) ) {
                        $best_match = null;
                        $best_score = PHP_INT_MAX;

                        foreach ( $body['response'] as $item ) {
                            $candidate = $item['team']['name'] ?? '';
                            $candidate_norm = strtolower( preg_replace( '/[^a-z0-9]/i', '', $candidate ) );

                            $is_match = ( $candidate_norm === $team_norm ) || ( levenshtein( $team_norm, $candidate_norm ) <= 3 );
                            if ( ! $is_match && strlen( $team_norm ) >= 3 && strlen( $candidate_norm ) >= 3 ) {
                                if ( strpos( $candidate_norm, $team_norm ) !== false || strpos( $team_norm, $candidate_norm ) !== false ) {
                                    $is_match = true;
                                }
                            }

                            if ( $is_match ) {
                                $dist = levenshtein( $team_norm, $candidate_norm );
                                if ( $dist < $best_score ) {
                                    $best_score = $dist;
                                    $best_match = $item['team'];
                                }
                            }
                        }

                        if ( $best_match ) {
                            $logo = $best_match['logo'] ?? '';
                        }
                    }
                }
            }
        }

        // 3. Fallback: Try TheSportsDB with STRICT similarity check (edit distance <= 3 + substring matching)
        if ( empty( $logo ) ) {
            $url = self::$logo_base . '/searchteams.php?t=' . urlencode( $team_name );
            $response = wp_remote_get( $url, array( 'timeout' => 10 ) );

            if ( ! is_wp_error( $response ) ) {
                $body = json_decode( wp_remote_retrieve_body( $response ), true );
                if ( ! empty( $body['teams'] ) && is_array( $body['teams'] ) ) {
                    foreach ( $body['teams'] as $team ) {
                        $candidate = $team['strTeam'] ?? '';
                        $candidate_norm = strtolower( preg_replace( '/[^a-z0-9]/i', '', $candidate ) );

                        $is_match = ( $candidate_norm === $team_norm ) || ( levenshtein( $team_norm, $candidate_norm ) <= 3 );
                        if ( ! $is_match && strlen( $team_norm ) >= 3 && strlen( $candidate_norm ) >= 3 ) {
                            if ( strpos( $candidate_norm, $team_norm ) !== false || strpos( $team_norm, $candidate_norm ) !== false ) {
                                $is_match = true;
                            }
                        }

                        if ( $is_match ) {
                            if ( ! empty( $team['strBadge'] ) ) {
                                $logo = $team['strBadge'];
                            } elseif ( ! empty( $team['strTeamBadge'] ) ) {
                                $logo = $team['strTeamBadge'];
                            }
                            break;
                        }
                    }
                }
            }
        }

        // Cache response (even if empty, so we don't spam requests for unmapped/failed logos)
        // BUT do not cache 'none' if the API call failed due to rate limiting or connection errors
        if ( ! empty( $logo ) ) {
            update_option( $cache_key, $logo, false );
        } elseif ( ! $api_failed ) {
            update_option( $cache_key, 'none', false );
        }

        return $logo;
    }

    /**
     * MAIN SYNC: Fetch today's fixtures and create/update prediction posts
     * This runs via WP Cron every 30 minutes
     */
    public static function sync_predictions() {
        // Always clean up old predictions first (delete 24h old and older)
        self::cleanup_old_predictions();

        $source = get_option( 'crane_prediction_source', 'forebet_odds' );
        if ( ! in_array( $source, array( 'api_football', 'all' ), true ) ) {
            error_log( 'Crane Predictions Sync: API-Football not selected. Skipping.' );
            return;
        }

        $api_key = self::get_api_key();
        if ( empty( $api_key ) ) {
            error_log( 'Crane Predictions Sync: No API key set. Skipping.' );
            return;
        }

        // Search for nearest date with active matches in Lagos timezone (up to 14 days)
        $tz = new DateTimeZone( 'Africa/Lagos' );
        $today_dt = new DateTime( 'now', $tz );
        
        $fixtures = array();
        $target_date = '';
        $target_year = (int)$today_dt->format( 'Y' );

        // DB Guard check: Do we already have matches for today in the database?
        $has_today_query = new WP_Query( array(
            'post_type'      => 'crane_prediction',
            'meta_key'       => 'match_date',
            'meta_value'     => $today_dt->format( 'Y-m-d' ),
            'posts_per_page' => 1,
            'post_status'    => 'any',
            'fields'         => 'ids',
        ) );
        $has_today = $has_today_query->have_posts();
        wp_reset_postdata();

        if ( $has_today ) {
            // If today matches already exist locally, lock target to today (do not jump to future dates)
            $target_date = $today_dt->format( 'Y-m-d' );
            $target_year = (int)$today_dt->format( 'Y' );

            $fixtures = self::api_request( '/fixtures', array(
                'date'     => $target_date,
                'timezone' => 'Africa/Lagos',
            ), 120 ); // Cache for 120 minutes

            if ( ! is_array( $fixtures ) ) {
                $fixtures = array();
            }

            // ── Priority league ID top-up ─────────────────────────────────────
            $priority_league_ids = array( 1, 4, 9, 2, 3, 848 );
            $existing_ids = array();
            foreach ( $fixtures as $f ) {
                if ( isset( $f['fixture']['id'] ) ) {
                    $existing_ids[ $f['fixture']['id'] ] = true;
                }
            }

            foreach ( $priority_league_ids as $lid ) {
                $extra = self::api_request( '/fixtures', array(
                    'league'   => $lid,
                    'season'   => $target_year,
                    'date'     => $target_date,
                    'timezone' => 'Africa/Lagos',
                ), 120 );

                if ( is_array( $extra ) && ! empty( $extra ) ) {
                    foreach ( $extra as $ef ) {
                        $efid = $ef['fixture']['id'] ?? 0;
                        if ( $efid && ! isset( $existing_ids[ $efid ] ) ) {
                            $fixtures[]              = $ef;
                            $existing_ids[ $efid ]   = true;
                        }
                    }
                }
            }
        } else {
            // Loop up to 14 days starting from today to find the nearest date with matches
            for ( $i = 0; $i < 14; $i++ ) {
                $current_dt = clone $today_dt;
                if ( $i > 0 ) {
                    $current_dt->modify( "+{$i} days" );
                }
                $check_date = $current_dt->format( 'Y-m-d' );
                $check_year = (int)$current_dt->format( 'Y' );

                $fixtures = self::api_request( '/fixtures', array(
                    'date'     => $check_date,
                    'timezone' => 'Africa/Lagos',
                ), 120 ); // Cache for 120 minutes

                if ( ! is_array( $fixtures ) ) {
                    $fixtures = array();
                }

                // ── Priority league ID top-up for this check date ─────────────────
                $priority_league_ids = array( 1, 4, 9, 2, 3, 848 );
                $existing_ids = array();
                foreach ( $fixtures as $f ) {
                    if ( isset( $f['fixture']['id'] ) ) {
                        $existing_ids[ $f['fixture']['id'] ] = true;
                    }
                }

                foreach ( $priority_league_ids as $lid ) {
                    $extra = self::api_request( '/fixtures', array(
                        'league'   => $lid,
                        'season'   => $check_year,
                        'date'     => $check_date,
                        'timezone' => 'Africa/Lagos',
                    ), 120 );

                    if ( is_array( $extra ) && ! empty( $extra ) ) {
                        foreach ( $extra as $ef ) {
                            $efid = $ef['fixture']['id'] ?? 0;
                            if ( $efid && ! isset( $existing_ids[ $efid ] ) ) {
                                $fixtures[]              = $ef;
                                $existing_ids[ $efid ]   = true;
                            }
                        }
                    }
                }

                // Filter out completed/cancelled matches to see if we have actionable matches
                $active_count = 0;
                $skip_statuses = [ 'FT', 'AET', 'PEN', 'CANC', 'ABD', 'AWD', 'WO', 'PST' ];
                foreach ( $fixtures as $fixture ) {
                    $status_short = isset($fixture['fixture']['status']['short']) ? $fixture['fixture']['status']['short'] : 'NS';
                    if ( ! in_array( $status_short, $skip_statuses, true ) ) {
                        $active_count++;
                    }
                }

                if ( $active_count > 0 ) {
                    $target_date = $check_date;
                    $target_year = $check_year;
                    error_log( 'Crane API-Football: Nearest date with active matches found on ' . $target_date . ' with ' . $active_count . ' fixtures.' );
                    break;
                }
            }
        }

        if ( empty( $fixtures ) || empty( $target_date ) ) {
            error_log( 'Crane Predictions Sync: No upcoming fixtures returned in the next 14 days.' );
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

            // ── Skip finished / cancelled / postponed matches ─────────────────
            // These are useless for predictions and caused PENDING cards on the front page.
            $skip_statuses = [ 'FT', 'AET', 'PEN', 'CANC', 'ABD', 'AWD', 'WO', 'PST' ];
            if ( in_array( $status_short, $skip_statuses, true ) ) continue;

            // ── Parse match time in WAT (Africa/Lagos) ────────────────────────
            // API-Football sends ISO 8601 UTC timestamps; we convert to WAT for display.
            $match_dt = new DateTime( $match_date );       // Parse as UTC
            $match_dt->setTimezone( $tz );                 // Convert to Africa/Lagos (WAT = UTC+1)

            // Live status — keep a human-readable LIVE string
            $live_statuses = array( '1H', '2H', 'HT', 'ET', 'P', 'LIVE', 'BT' );
            if ( in_array( $status_short, $live_statuses, true ) ) {
                $elapsed = isset($fixture['fixture']['status']['elapsed']) ? $fixture['fixture']['status']['elapsed'] : '';
                $time_display = 'LIVE ' . $elapsed . "'";
            } else {
                // Not started — store clean 24-hr WAT time (e.g. "20:45")
                $time_display = $match_dt->format( 'H:i' );
            }

            // Use WAT date for match_date meta (not raw UTC date from the API string)
            $match_date_wat = $match_dt->format( 'Y-m-d' );

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
            update_post_meta( $post_id, 'match_date', $match_date_wat );

            // NEW: Fetch and Store Real API Prediction (Hardened)
            $existing_tip = get_post_meta( $post_id, '_crane_free_tip', true );
            static $pred_count = 0;
            $pred_limit = 5; // Max 5 premium API tips per sync cycle to save quota

            if ( empty( $existing_tip ) && $status_short === 'NS' && $pred_count < $pred_limit ) {
                $last_check = (int) get_post_meta( $post_id, '_crane_last_api_check', true );
                $now = time();
                
                // 24-hour cooldown (86400 seconds) to prevent spamming empty API predictions
                if ( ! $last_check || ( $now - $last_check ) > 86400 ) {
                    $pred_data = self::api_request( '/predictions', array( 'fixture' => $fixture_id ) );
                    update_post_meta( $post_id, '_crane_last_api_check', $now );
                    $pred_count++; // Increment quota counter since we made an API request
                    
                    // Array Safety Check (Fatal Error Prevention)
                    if ( is_array( $pred_data ) && ! empty( $pred_data ) && isset( $pred_data[0]['predictions'] ) ) {
                        $winner_name = !empty($pred_data[0]['predictions']['winner']['name']) ? $pred_data[0]['predictions']['winner']['name'] : '';
                        if ( $winner_name ) {
                            update_post_meta( $post_id, '_crane_free_tip', $winner_name . ' Win' );
                        }
                        
                        // Persist the entire rich analysis block for the Single Template
                        update_post_meta( $post_id, '_crane_prediction_analysis', json_encode( $pred_data[0] ) );
                    }
                }
            }

            $synced++;
        }

        // Clear transients
        delete_transient( 'crane_front_matches_html' );
        delete_transient( 'crane_front_locker_preview' );
        delete_transient( 'crane_front_matches_pool' );

        // Purge page caches
        if ( class_exists( 'Crane_Free_Prediction_Scraper' ) ) {
            Crane_Free_Prediction_Scraper::purge_page_caches();
        }

        error_log( 'Crane Predictions Sync: ' . $synced . ' matches synced for ' . $target_date );
        return $synced;
    }

    /**
     * Fetch and store odds for today's matches (separate call to save API quota)
     * Runs once per day
     */
    public static function sync_odds() {
        $source = get_option( 'crane_prediction_source', 'forebet_odds' );
        if ( ! in_array( $source, array( 'api_football', 'all' ), true ) ) {
            return;
        }

        $api_key = self::get_api_key();
        if ( empty( $api_key ) ) return;

        // Retrieve unique match dates from the database to query odds
        global $wpdb;
        $dates = $wpdb->get_col( "
            SELECT DISTINCT pm.meta_value 
            FROM {$wpdb->postmeta} pm
            JOIN {$wpdb->posts} p ON p.ID = pm.post_id
            WHERE p.post_type = 'crane_prediction' 
              AND p.post_status = 'publish' 
              AND pm.meta_key = 'match_date'
        " );

        if ( empty( $dates ) ) return;

        foreach ( $dates as $target_date ) {
            $odds_data = self::api_request( '/odds', array(
                'date'     => $target_date,
                'timezone' => 'Africa/Lagos',
                'bookmaker' => 8, // Bet365
            ), 360 ); // Cache for 6 hours (odds don't change that much)

            if ( ! $odds_data ) continue;

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
        }

        // Clear transients
        delete_transient( 'crane_front_matches_html' );
        delete_transient( 'crane_front_locker_preview' );
        delete_transient( 'crane_front_matches_pool' );

        // Purge page caches
        if ( class_exists( 'Crane_Free_Prediction_Scraper' ) ) {
            Crane_Free_Prediction_Scraper::purge_page_caches();
        }

        error_log( 'Crane Odds Sync: Complete for target dates: ' . implode( ', ', $dates ) );
    }

    /**
     * Clean up old predictions (older than 24 hours based on match datetime)
     * Runs daily to keep the homepage fresh
     */
    public static function cleanup_old_predictions() {
        $tz  = new DateTimeZone( 'Africa/Lagos' );
        $now = new DateTime( 'now', $tz );
        $now_timestamp = $now->getTimestamp();

        $all_posts = new WP_Query( array(
            'post_type'      => 'crane_prediction',
            'posts_per_page' => 500,
            'fields'         => 'ids',
            'post_status'    => 'any',
        ) );

        $deleted = 0;
        foreach ( $all_posts->posts as $pid ) {
            $match_date = get_post_meta( $pid, 'match_date', true );
            if ( empty( $match_date ) ) {
                // If no match_date exists, fall back to post_date. Delete if post is older than 2 days.
                $post_date = get_post_field( 'post_date', $pid );
                if ( $post_date && ( $now_timestamp - strtotime( $post_date ) ) > 2 * 86400 ) {
                    wp_delete_post( $pid, true );
                    $deleted++;
                }
                continue;
            }

            $match_time = get_post_meta( $pid, 'match_time', true ) ?: '00:00';
            // Clean match_time if it contains LIVE or other text, extract HH:MM if possible
            $time_part = '00:00';
            if ( preg_match( '/(\d{1,2}):(\d{2})/', $match_time, $matches ) ) {
                $time_part = sprintf( '%02d:%02d', intval( $matches[1] ), intval( $matches[2] ) );
            }

            try {
                $match_datetime = new DateTime( $match_date . ' ' . $time_part, $tz );
                $match_timestamp = $match_datetime->getTimestamp();
                if ( ( $now_timestamp - $match_timestamp ) >= 86400 ) {
                    wp_delete_post( $pid, true );
                    $deleted++;
                }
            } catch ( Exception $e ) {
                // If parsing fails, delete if the match_date is strictly before today
                $today = $now->format( 'Y-m-d' );
                if ( $match_date < $today ) {
                    wp_delete_post( $pid, true );
                    $deleted++;
                }
            }
        }
        wp_reset_postdata();

        if ( $deleted > 0 ) {
            error_log( "Crane API Cleanup: Removed {$deleted} predictions older than 24 hours." );
        }
    }

    /**
     * Manual sync trigger from admin
     */
    public static function handle_manual_sync() {
        check_admin_referer( 'crane_manual_sync' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );

        $source  = get_option( 'crane_prediction_source', 'forebet_odds' );
        $api_key = self::get_api_key();

        $status = 'success';
        $synced = 0;
        $reason = '';

        if ( ! in_array( $source, array( 'api_football', 'all' ), true ) ) {
            $status = 'skipped';
            $src_labels = [
                'forebet'      => 'Forebet Only',
                'odds_api'     => 'The Odds API Only',
                'forebet_odds' => 'Forebet + The Odds API',
            ];
            $label  = isset( $src_labels[ $source ] ) ? $src_labels[ $source ] : $source;
            $reason = 'API-Football sync skipped — current source is set to "' . $label . '". Change the Prediction Source to "All Sources" or "API-Football Only" to enable this sync.';
        } elseif ( empty( $api_key ) ) {
            $status = 'error';
            $reason = 'No API-Football key configured. Please enter your API key in the field above and save before syncing.';
        } else {
            $synced = self::sync_predictions();
            if ( $synced === null || $synced === false ) {
                $synced = 0;
            }
            self::sync_odds();

            if ( (int) $synced > 0 ) {
                $reason = sprintf( 'Successfully imported/updated %d prediction matches from API-Football.', $synced );
            } else {
                $reason = 'No new predictions were imported. Existing matches are already up to date, or no upcoming fixtures were found in the next 14 days.';
                $status = 'info';
            }
        }

        $redirect = admin_url( 'admin.php?page=crane-api-settings'
            . '&apif_status=' . urlencode( $status )
            . '&apif_synced=' . intval( $synced )
            . '&apif_reason=' . urlencode( $reason )
        );
        wp_redirect( $redirect );
        exit;
    }
}
