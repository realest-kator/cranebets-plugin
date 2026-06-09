<?php
/**
 * Free Prediction Scraper for Crane Bets
 *
 * Sources:
 *  1. Forebet.com  — HTML scrape, no key needed (mathematical predictions)
 *  2. The Odds API — free key, real bookmaker odds converted to predictions
 *
 * Stores predictions in the same crane_prediction CPT format as Prediction_API_Service,
 * so the existing theme card renders them identically.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Crane_Free_Prediction_Scraper {

    /**
     * Boot the service: Register hooks
     */
    public static function boot() {
        add_action( 'admin_post_crane_manual_scraper_fetch', array( __CLASS__, 'handle_manual_fetch' ) );
        add_action( 'crane_sync_predictions_cron_v2', array( __CLASS__, 'run_cron_sync' ) );
    }

    /**
     * Wrapper for cron to avoid returning value/output
     */
    public static function run_cron_sync() {
        self::run();
    }

    /** Forebet page URLs */
    const FOREBET_TODAY         = 'https://www.forebet.com/en/football-tips-and-predictions-for-today';
    const FOREBET_AFRICA        = 'https://www.forebet.com/en/football/africa-predictions-statistics';
    const FOREBET_INTERNATIONAL = 'https://www.forebet.com/en/football/international-predictions';
    const FOREBET_WORLD_CUP     = 'https://www.forebet.com/en/football-tips-and-predictions-for-today/world-cup';

    /** The Odds API base */
    const ODDS_API_BASE   = 'https://api.the-odds-api.com/v4/sports';

    /**
     * Top-tier league / tournament priority list (used for filtering/sorting)
     * Includes international tournaments so they are never downgraded to "other".
     */
    private static $european_leagues = [
        // International tournaments — highest priority
        'world cup', 'fifa world cup', 'wc 2026', 'world cup 2026',
        'uefa nations league', 'nations league',
        'copa america', 'copa américa',
        'concacaf gold cup', 'gold cup',
        'euro 2024', 'euro 2028', 'european championship',
        'afcon', 'africa cup', 'caf',
        // Top European club leagues
        'premier league', 'championship', 'la liga', 'bundesliga', 'serie a',
        'ligue 1', 'eredivisie', 'liga portugal', 'primeira liga',
        'scottish premiership', 'league one', 'league two',
        'allsvenskan', 'eliteserien', 'süper lig',
        // Club cups
        'champions league', 'europa league', 'conference league',
        'uefa champions league', 'uefa europa league', 'uefa conference league',
        'copa libertadores', 'copa sudamericana',
        // Middle East / Asia premium
        'saudi pro league', 'saudi premiere league',
    ];

    /**
     * Nigerian / African league keywords (priority when EU is off-season)
     */
    private static $african_leagues = [
        'npfl', 'nigeria', 'caf', 'ghana', 'egypt', 'south africa',
        'ethiopia', 'kenya', 'tanzania', 'senegal', 'morocco', 'tunisia',
    ];

    /**
     * The Odds API soccer sport keys to query.
     * International tournaments are listed first so they consume quota before
     * lower-priority club leagues when the 8-sport-call cap is hit.
     */
    private static $odds_sport_keys = [
        // ★ International Tournaments — always top priority
        'soccer_fifa_world_cup',                    // FIFA World Cup 2026
        'soccer_uefa_nations_league',               // UEFA Nations League
        'soccer_conmebol_copa_america',             // Copa América
        'soccer_concacaf_gold_cup',                 // CONCACAF Gold Cup

        // Top 5 European Leagues
        'soccer_epl',
        'soccer_spain_la_liga',
        'soccer_germany_bundesliga',
        'soccer_italy_serie_a',
        'soccer_france_ligue_one',

        // European Club Cups
        'soccer_uefa_champs_league',
        'soccer_uefa_europa_league',
        'soccer_uefa_europa_conference_league',

        // Other European Leagues
        'soccer_netherlands_eredivisie',
        'soccer_portugal_primeira_liga',
        'soccer_england_league1',
        'soccer_turkey_super_league',
        'soccer_greece_super_league',
        'soccer_sweden_allsvenskan',
        'soccer_norway_eliteserien',

        // Americas
        'soccer_usa_mls',
        'soccer_brazil_campeonato',
        'soccer_argentina_primera_division',
        'soccer_conmebol_copa_libertadores',

        // Middle East
        'soccer_saudi_arabio_premiere_league',

        // Africa
        'soccer_nigeria_npfl',
        'soccer_africa_caf_champions_league',
        'soccer_south_africa_premier_division',
        'soccer_ghana_premier_league',
    ];

    /**
     * Full browser header to reduce block chance
     */
    private static function browser_headers() {
        return [
            'User-Agent'      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
            'Accept'          => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language' => 'en-US,en;q=0.9',
            'Accept-Encoding' => 'gzip, deflate',
            'Cache-Control'   => 'no-cache',
            'Referer'         => 'https://www.google.com/',
        ];
    }

    // =========================================================================
    // MAIN ENTRY POINTS
    // =========================================================================

    /**
     * Run all enabled free sources based on admin setting
     */
    public static function run() {
        // Always clean up old predictions first (delete yesterday and older)
        self::cleanup_old_predictions();

        $source = get_option( 'crane_prediction_source', 'forebet_odds' );
        $imported = 0;

        if ( in_array( $source, [ 'forebet', 'forebet_odds', 'all' ], true ) ) {
            $imported += self::scrape_forebet();
        }

        if ( in_array( $source, [ 'odds_api', 'forebet_odds', 'all' ], true ) ) {
            $imported += self::fetch_odds_api();
        }

        // Invalidate the front-page matches transient so fresh data is shown immediately
        delete_transient( 'crane_front_matches_html' );
        delete_transient( 'crane_front_locker_preview' );

        error_log( "Crane Free Scraper: Total {$imported} predictions imported." );
        return $imported;
    }

    /**
     * Delete crane_prediction posts whose match_date is before today (WAT).
     * Runs at the start of every scrape cycle.
     */
    public static function cleanup_old_predictions() {
        $tz      = new DateTimeZone( 'Africa/Lagos' );
        $today   = ( new DateTime( 'now', $tz ) )->format( 'Y-m-d' );

        $old = new WP_Query( [
            'post_type'      => 'crane_prediction',
            'post_status'    => 'any',
            'posts_per_page' => 200,
            'fields'         => 'ids',
            'meta_query'     => [
                'relation' => 'OR',
                [
                    // Has a match_date set and it is before today
                    'key'     => 'match_date',
                    'value'   => $today,
                    'compare' => '<',
                    'type'    => 'DATE',
                ],
                [
                    // Also delete posts where match_date is not set at all but post is older than 2 days
                    'key'     => 'match_date',
                    'compare' => 'NOT EXISTS',
                ],
            ],
        ] );

        $deleted = 0;
        foreach ( $old->posts as $pid ) {
            // Extra guard: if match_date is today, skip
            $match_date = get_post_meta( $pid, 'match_date', true );
            if ( $match_date === $today ) continue;

            // If no match_date, only delete posts older than 2 days
            if ( empty( $match_date ) ) {
                $post_date = get_post_field( 'post_date', $pid );
                if ( $post_date && strtotime( $post_date ) > strtotime( '-2 days' ) ) continue;
            }

            wp_delete_post( $pid, true );
            $deleted++;
        }
        wp_reset_postdata();

        if ( $deleted > 0 ) {
            error_log( "Crane Cleanup: Deleted {$deleted} past predictions (before {$today})." );
        }
        return $deleted;
    }

    // =========================================================================
    // SOURCE 1 — FOREBET SCRAPER
    // =========================================================================

    /**
     * Scrape Forebet today page, plus international/World Cup pages when active.
     * Falls back to African leagues when EU is genuinely off-season.
     */
    public static function scrape_forebet() {
        // Check transient — only scrape if not done in last 6 hours
        if ( get_transient( 'crane_forebet_last_run' ) ) {
            error_log( 'Crane Forebet: Skipped (cached, less than 6h).' );
            return 0;
        }

        $html = self::fetch_url( self::FOREBET_TODAY );
        if ( ! $html ) {
            error_log( 'Crane Forebet: Could not fetch today page (blocked or down).' );
            return 0;
        }

        $matches = self::parse_forebet_html( $html );

        // ── International / World Cup page fetch ──────────────────────────────
        // Always attempt the international predictions page when a major
        // tournament is active (World Cup, Euros, Copa América, etc.).
        if ( self::is_major_tournament_active() ) {
            error_log( 'Crane Forebet: Major tournament active — fetching international predictions page.' );

            $intl_html = self::fetch_url( self::FOREBET_INTERNATIONAL );
            if ( $intl_html ) {
                $intl_matches = self::parse_forebet_html( $intl_html );
                $matches      = array_merge( $matches, $intl_matches );
                error_log( 'Crane Forebet: International page added ' . count( $intl_matches ) . ' matches.' );
            }

            // Also try the dedicated World Cup predictions page
            $wc_html = self::fetch_url( self::FOREBET_WORLD_CUP );
            if ( $wc_html ) {
                $wc_matches = self::parse_forebet_html( $wc_html );
                $matches    = array_merge( $matches, $wc_matches );
                error_log( 'Crane Forebet: World Cup page added ' . count( $wc_matches ) . ' matches.' );
            }
        }

        // ── Separate priority (EU/International) from other ───────────────────
        $priority = [];
        $other    = [];
        foreach ( $matches as $m ) {
            if ( self::is_european_league( $m['league'] ) ) {
                $priority[] = $m;
            } else {
                $other[] = $m;
            }
        }

        // Deduplicate by home+away pair (cross-page duplicates)
        $seen      = [];
        $deduped   = [];
        $all_found = array_merge( $priority, $other );
        foreach ( $all_found as $m ) {
            $key = strtolower( $m['home'] . '|' . $m['away'] );
            if ( ! isset( $seen[ $key ] ) ) {
                $seen[ $key ] = true;
                $deduped[]    = $m;
            }
        }

        // ── Off-season fallback ───────────────────────────────────────────────
        $to_store = $priority;
        if ( empty( $priority ) || self::is_off_season() ) {
            error_log( 'Crane Forebet: EU off-season or no priority matches — fetching African leagues.' );
            $africa_html = self::fetch_url( self::FOREBET_AFRICA );
            if ( $africa_html ) {
                $africa_matches = self::parse_forebet_html( $africa_html );
                // Prioritise Nigerian leagues within Africa results
                usort( $africa_matches, function( $a, $b ) {
                    $a_is_ng = ( stripos( $a['league'], 'nigeria' ) !== false || stripos( $a['league'], 'npfl' ) !== false );
                    $b_is_ng = ( stripos( $b['league'], 'nigeria' ) !== false || stripos( $b['league'], 'npfl' ) !== false );
                    return (int) $b_is_ng - (int) $a_is_ng;
                } );
                $to_store = array_merge( $to_store, $africa_matches );
            } else {
                $to_store = $deduped; // use everything we found
            }
        } else {
            $to_store = $deduped; // include all deduped matches (priority first)
        }

        // Raise cap to 40 during major tournaments, 20 otherwise
        $cap      = self::is_major_tournament_active() ? 40 : 20;
        $to_store = array_slice( $to_store, 0, $cap );

        $imported = 0;
        foreach ( $to_store as $match_data ) {
            if ( self::store_prediction( $match_data, 'forebet' ) ) {
                $imported++;
            }
        }

        // Cache for 6 hours so we don't hammer Forebet
        set_transient( 'crane_forebet_last_run', true, 6 * HOUR_IN_SECONDS );
        error_log( "Crane Forebet: Imported {$imported} predictions (cap={$cap})." );
        return $imported;
    }

    /**
     * Parse Forebet HTML and extract match rows
     *
     * @param string $html Raw HTML from Forebet
     * @return array Array of match data arrays
     */
    private static function parse_forebet_html( $html ) {
        if ( empty( $html ) ) return [];
        $matches = [];

        // --- Strategy 1: Find div.rcnt rows (known Forebet class) using lookahead ---
        if ( preg_match_all( '/(<div[^>]*class=["\'][^"\']*\brcnt\b[^"\']*["\'][^>]*>.*?)(?=<div[^>]*class=["\'][^"\']*\brcnt\b[^"\']*["\']|<!--|<section|<\/body>|$)/is', $html, $rows ) ) {
            foreach ( $rows[1] as $row_html ) {
                $m = self::extract_match_from_row( $row_html );
                if ( $m ) $matches[] = $m;
            }
        }

        // --- Strategy 2: Table rows fallback ---
        if ( empty( $matches ) ) {
            if ( preg_match_all( '/<tr[^>]*class=["\'][^"\']*match[^"\']*["\'][^>]*>(.*?)<\/tr>/is', $html, $rows ) ) {
                foreach ( $rows[1] as $row_html ) {
                    $m = self::extract_match_from_row( $row_html );
                    if ( $m ) $matches[] = $m;
                }
            }
        }

        // --- Strategy 3: JSON-LD or embedded JSON data ---
        if ( empty( $matches ) ) {
            if ( preg_match_all( '/<script[^>]*type=["\']application\/ld\+json["\'][^]*>(.*?)<\/script>/is', $html, $json_blocks ) ) {
                foreach ( $json_blocks[1] as $block ) {
                    $data = json_decode( trim( $block ), true );
                    if ( is_array( $data ) && isset( $data['name'] ) && isset( $data['homeTeam'] ) ) {
                        $matches[] = [
                            'home'       => sanitize_text_field( $data['homeTeam']['name'] ?? '' ),
                            'away'       => sanitize_text_field( $data['awayTeam']['name'] ?? '' ),
                            'league'     => sanitize_text_field( $data['sport'] ?? 'Football' ),
                            'time'       => sanitize_text_field( $data['startDate'] ?? '' ),
                            'prediction' => '1',
                            'home_prob'  => '45%',
                            'draw_prob'  => '30%',
                            'away_prob'  => '25%',
                            'score'      => '',
                            'short_tag'  => '',
                        ];
                    }
                }
            }
        }

        // Two-pass resolution: resolve empty/generic leagues using short_tag mapping
        $tag_to_league = [];
        foreach ( $matches as $m ) {
            if ( ! empty( $m['short_tag'] ) && ! empty( $m['league'] ) && $m['league'] !== 'Football' ) {
                $tag_to_league[ $m['short_tag'] ] = $m['league'];
            }
        }
        foreach ( $matches as $k => $m ) {
            if ( ( empty( $m['league'] ) || $m['league'] === 'Football' ) && ! empty( $m['short_tag'] ) ) {
                if ( isset( $tag_to_league[ $m['short_tag'] ] ) ) {
                    $matches[$k]['league'] = $tag_to_league[ $m['short_tag'] ];
                } elseif ( strtolower($m['short_tag']) === 'us4' ) {
                    $matches[$k]['league'] = 'USA - USL League Two';
                }
            }
            unset( $matches[$k]['short_tag'] );
        }

        error_log( 'Crane Forebet Parser: Found ' . count( $matches ) . ' matches.' );
        return $matches;
    }

    /**
     * Extract one match from a Forebet HTML row
     */
    private static function extract_match_from_row( $row_html ) {
        // Home team
        $home = '';
        if ( preg_match( '/<span[^>]*class=["\']*(?:homeTeam|home)[^"\']*["\'][^>]*>(?:<span[^>]* itemprop="name"[^>]*>)?(.*?)(?:<\/span>){1,2}/is', $row_html, $hp ) ) {
            $home = trim( strip_tags( $hp[1] ) );
        }

        // Away team
        $away = '';
        if ( preg_match( '/<span[^>]*class=["\']*(?:awayTeam|away)[^"\']*["\'][^>]*>(?:<span[^>]* itemprop="name"[^>]*>)?(.*?)(?:<\/span>){1,2}/is', $row_html, $ap ) ) {
            $away = trim( strip_tags( $ap[1] ) );
        }

        if ( empty( $home ) || empty( $away ) ) return null;
        if ( strlen( $home ) > 50 || strlen( $away ) > 50 ) return null; // sanity check

        // Time / Date (Forebet shows times in GMT/UTC by default when scraped)
        $time_str = '';
        if ( preg_match( '/<span[^>]*class=["\'][^"\']*date_bah[^"\']*["\'][^>]*>(.*?)<\/span>/is', $row_html, $m ) ) {
            $time_str = trim( strip_tags( $m[1] ) );
        } elseif ( preg_match( '/(\d{2}:\d{2})/', $row_html, $m ) ) {
            $time_str = $m[1];
        }

        $date = '';
        $time = '';
        if ( ! empty( $time_str ) ) {
            if ( preg_match( '/(\d{2})\/(\d{2})\/(\d{4})\s+(\d{2}):(\d{2})/', $time_str, $dt ) ) {
                // We have a full datetime string: DD/MM/YYYY HH:MM in GMT
                $day   = $dt[1];
                $month = $dt[2];
                $year  = $dt[3];
                $hour  = $dt[4];
                $min   = $dt[5];
                
                try {
                    $utc_dt = new DateTime( "$year-$month-$day $hour:$min", new DateTimeZone( 'UTC' ) );
                    // Convert to WAT (Africa/Lagos, which is UTC+1)
                    $utc_dt->setTimezone( new DateTimeZone( 'Africa/Lagos' ) );
                    $date = $utc_dt->format( 'Y-m-d' );
                    $time = $utc_dt->format( 'H:i' );
                } catch ( Exception $e ) {
                    $date = "$year-$month-$day";
                    $time = "$hour:$min";
                }
            } elseif ( preg_match( '/(\d{2}):(\d{2})/', $time_str, $t_only ) ) {
                // Only time is present, assume today's date in GMT
                $hour = $t_only[1];
                $min  = $t_only[2];
                try {
                    $today_gmt = new DateTime( 'now', new DateTimeZone( 'UTC' ) );
                    $today_gmt->setTime( intval($hour), intval($min) );
                    $today_gmt->setTimezone( new DateTimeZone( 'Africa/Lagos' ) );
                    $date = $today_gmt->format( 'Y-m-d' );
                    $time = $today_gmt->format( 'H:i' );
                } catch ( Exception $e ) {
                    $time = sprintf( '%02d:%02d', (intval($hour) + 1) % 24, intval($min) );
                }
            }
        }

        // League — build "Country - League" from getstag() onclick to avoid ambiguous names
        // getstag(this, matchId, 'Country', 'League Name', 'url-slug', 'cc')
        $league = 'Football';
        if ( preg_match( "/onclick=[\"']getstag\\([^,]*,\\s*[^,]*,\\s*'([^']*)',\\s*'([^']*)'/is", $row_html, $lp ) ) {
            $country   = trim( $lp[1] );
            $lge_name  = trim( $lp[2] );
            if ( ! empty( $lge_name ) ) {
                // Combine country + league for disambiguation (e.g. 'Canada - Premier League')
                $league = ! empty( $country ) ? $country . ' - ' . $lge_name : $lge_name;
            }
        } elseif ( preg_match( '/<span[^>]*class=["\'][^"\']*country_league[^"\']*["\'][^>]*>(.*?)<\/span>/is', $row_html, $m ) ) {
            $league = trim( strip_tags( $m[1] ) );
        } elseif ( preg_match( '/<span[^>]*class=["\'][^"\']*league[^"\']*["\'][^>]*>(.*?)<\/span>/is', $row_html, $m ) ) {
            $league = trim( strip_tags( $m[1] ) );
        }

        // short_tag extraction
        $short_tag = '';
        if ( preg_match( '/<span[^>]*class=["\']shortTag["\'][^>]*>(.*?)<\/span>/is', $row_html, $st_match ) ) {
            $short_tag = trim( strip_tags( $st_match[1] ) );
        }

        // Prediction (1, X, or 2)
        $prediction = '';
        if ( preg_match( '/<span[^>]*class=["\'][^"\']*forepr[^"\']*["\'][^>]*>(.*?)<\/span>/is', $row_html, $m ) ) {
            $raw = trim( strip_tags( $m[1] ) );
            if ( in_array( $raw, [ '1', 'X', '2', '1X', 'X2', '12' ], true ) ) {
                $prediction = $raw;
            }
        }

        // Probabilities (three numbers like 55, 28, 17)
        $home_prob = '';
        $draw_prob = '';
        $away_prob = '';
        if ( preg_match( '/<div[^>]*class=["\']fprc["\'][^>]*>(.*?)<\/div>/is', $row_html, $fprc_block ) ) {
            if ( preg_match_all( '/<span>(\d+)<\/span>|<span[^>]*class=["\']fpr["\'][^>]*>(\d+)<\/span>/is', $fprc_block[1], $pm ) ) {
                $probs = [];
                foreach ( $pm[0] as $match_val ) {
                    $probs[] = trim( strip_tags( $match_val ) );
                }
                if ( isset( $probs[0] ) ) $home_prob = $probs[0] . '%';
                if ( isset( $probs[1] ) ) $draw_prob = $probs[1] . '%';
                if ( isset( $probs[2] ) ) $away_prob = $probs[2] . '%';
            }
        }

        // Fallback to old behavior
        if ( empty( $home_prob ) && preg_match_all( '/<div[^>]*class=["\'][^"\']*predict[^"\']*["\'][^>]*>(\d+)<\/div>/is', $row_html, $pm ) ) {
            if ( isset( $pm[1][0] ) ) $home_prob = $pm[1][0] . '%';
            if ( isset( $pm[1][1] ) ) $draw_prob = $pm[1][1] . '%';
            if ( isset( $pm[1][2] ) ) $away_prob = $pm[1][2] . '%';
        }

        // Predicted correct score — match ALL ex_sc elements, pick the one that looks like '## - ##'
        $score = '';
        if ( preg_match_all( '/<(?:div|span)[^>]*class=["\'][^"\']*ex_sc[^"\']*["\'][^>]*>(.*?)<\/(?:div|span)>/is', $row_html, $sc_all ) ) {
            foreach ( $sc_all[1] as $sc_raw ) {
                $sc_clean = trim( strip_tags( $sc_raw ) );
                // Only accept format like '1 - 0' or '2 - 2' (with spaces around dash)
                if ( preg_match( '/^\d+\s+-\s+\d+$/', $sc_clean ) ) {
                    $score = $sc_clean;
                    break;
                }
            }
        }

        return [
            'home'       => sanitize_text_field( $home ),
            'away'       => sanitize_text_field( $away ),
            'league'     => sanitize_text_field( $league ),
            'time'       => sanitize_text_field( $time ),
            'date'       => sanitize_text_field( $date ),
            'prediction' => $prediction,
            'home_prob'  => $home_prob,
            'draw_prob'  => $draw_prob,
            'away_prob'  => $away_prob,
            'score'      => $score,
            'short_tag'  => sanitize_text_field( $short_tag ),
        ];
    }

    // =========================================================================
    // SOURCE 2 — THE ODDS API
    // =========================================================================

    /**
     * Fetch predictions from The Odds API (real bookmaker odds)
     */
    public static function fetch_odds_api() {
        $api_key = get_option( 'crane_odds_api_key', '' );
        if ( empty( $api_key ) ) {
            error_log( 'Crane Odds API: No API key configured.' );
            return 0;
        }

        // Cache check — 6 hours
        if ( get_transient( 'crane_oddsapi_last_run' ) ) {
            error_log( 'Crane Odds API: Skipped (cached, less than 6h).' );
            return 0;
        }

        $is_off_season = self::is_off_season();
        $sport_keys    = self::$odds_sport_keys;

        // In true off-season (no major tournament), prioritise African leagues
        // International tournament keys are already at the top of the array,
        // so we only need to reshuffle when genuine off-season AND no tournament.
        if ( $is_off_season && ! self::is_major_tournament_active() ) {
            $african    = [ 'soccer_nigeria_npfl', 'soccer_africa_caf_champions_league', 'soccer_south_africa_premier_division', 'soccer_ghana_premier_league' ];
            $rest       = array_diff( $sport_keys, $african );
            $sport_keys = array_merge( $african, $rest );
        }

        $imported  = 0;
        $processed = 0;

        // Raise the per-run sport call cap during major tournaments
        $sport_call_cap = self::is_major_tournament_active() ? 12 : 8;

        foreach ( $sport_keys as $sport_key ) {
            if ( $processed >= $sport_call_cap ) break; // Limit API credit usage

            $url = self::ODDS_API_BASE . "/{$sport_key}/odds/?" . http_build_query( [
                'apiKey'   => $api_key,
                'regions'  => 'eu',
                'markets'  => 'h2h',
                'dateFormat' => 'iso',
                'oddsFormat' => 'decimal',
            ] );

            $response = wp_remote_get( $url, [ 'timeout' => 15 ] );
            if ( is_wp_error( $response ) ) {
                error_log( 'Crane Odds API Error: ' . $response->get_error_message() );
                continue;
            }

            $code = wp_remote_retrieve_response_code( $response );
            if ( $code !== 200 ) {
                error_log( "Crane Odds API HTTP {$code} for {$sport_key}" );
                // If 401/422 key error, stop immediately
                if ( in_array( $code, [ 401, 403, 422 ], true ) ) break;
                continue;
            }

            $events = json_decode( wp_remote_retrieve_body( $response ), true );
            if ( empty( $events ) || ! is_array( $events ) ) continue;

            $processed++;

            // Process up to 5 events per sport
            $count = 0;
            foreach ( $events as $event ) {
                if ( $count >= 5 ) break;
                $match = self::parse_odds_event( $event, $sport_key );
                if ( $match && self::store_prediction( $match, 'odds_api' ) ) {
                    $imported++;
                    $count++;
                }
            }
        }

        set_transient( 'crane_oddsapi_last_run', true, 6 * HOUR_IN_SECONDS );
        error_log( "Crane Odds API: Imported {$imported} predictions." );
        return $imported;
    }

    /**
     * Parse one Odds API event into our match format
     */
    private static function parse_odds_event( $event, $sport_key ) {
        if ( empty( $event['home_team'] ) || empty( $event['away_team'] ) ) return null;
        if ( empty( $event['bookmakers'] ) ) return null;

        $home = $event['home_team'];
        $away = $event['away_team'];

        // Match time (convert UTC to WAT = Africa/Lagos)
        $time = '';
        $date = '';
        if ( ! empty( $event['commence_time'] ) ) {
            $tz   = new DateTimeZone( 'Africa/Lagos' );
            $dt   = new DateTime( $event['commence_time'], new DateTimeZone( 'UTC' ) );
            $dt->setTimezone( $tz );
            $time = $dt->format( 'H:i' );
            $date = $dt->format( 'Y-m-d' );
        }

        // League name from sport key
        $league = self::sport_key_to_league( $sport_key );

        // Get best H2H odds (use first bookmaker with all 3 outcomes)
        $odd1 = 0;
        $oddX = 0;
        $odd2 = 0;

        foreach ( $event['bookmakers'] as $bookie ) {
            foreach ( $bookie['markets'] ?? [] as $market ) {
                if ( $market['key'] !== 'h2h' ) continue;
                foreach ( $market['outcomes'] ?? [] as $outcome ) {
                    $name  = $outcome['name'] ?? '';
                    $price = (float) ( $outcome['price'] ?? 0 );
                    if ( $name === $home )   $odd1 = $price;
                    if ( $name === 'Draw' )  $oddX = $price;
                    if ( $name === $away )   $odd2 = $price;
                }
                if ( $odd1 > 0 && $oddX > 0 && $odd2 > 0 ) break 2;
            }
        }

        if ( $odd1 <= 0 || $odd2 <= 0 ) return null;

        // Convert decimal odds to implied probabilities (normalised)
        $raw_home = $odd1 > 0 ? ( 1 / $odd1 ) : 0;
        $raw_draw = $oddX > 0 ? ( 1 / $oddX ) : 0;
        $raw_away = $odd2 > 0 ? ( 1 / $odd2 ) : 0;
        $total    = $raw_home + $raw_draw + $raw_away;

        $home_prob = $total > 0 ? round( ( $raw_home / $total ) * 100 ) . '%' : '';
        $draw_prob = $total > 0 ? round( ( $raw_draw / $total ) * 100 ) . '%' : '';
        $away_prob = $total > 0 ? round( ( $raw_away / $total ) * 100 ) . '%' : '';

        // Prediction: lowest odds = most likely winner
        $prediction = '1';
        if ( $oddX > 0 && $oddX < $odd1 && $oddX < $odd2 ) {
            $prediction = 'X';
        } elseif ( $odd2 < $odd1 ) {
            $prediction = '2';
        }

        return [
            'home'       => $home,
            'away'       => $away,
            'league'     => $league,
            'time'       => $time,
            'date'       => $date,
            'prediction' => $prediction,
            'home_prob'  => $home_prob,
            'draw_prob'  => $draw_prob,
            'away_prob'  => $away_prob,
            'score'      => '',
            'odd1'       => $odd1 > 0 ? number_format( $odd1, 2 ) : '',
            'oddX'       => $oddX > 0 ? number_format( $oddX, 2 ) : '',
            'odd2'       => $odd2 > 0 ? number_format( $odd2, 2 ) : '',
        ];
    }

    // =========================================================================
    // STORAGE
    // =========================================================================

    /**
     * Deduplicate and store one prediction as a crane_prediction post
     *
     * @param array  $data   Match data from any source
     * @param string $source 'forebet' or 'odds_api'
     * @return bool True if newly inserted
     */
    private static function store_prediction( $data, $source ) {
        $home = sanitize_text_field( $data['home'] ?? '' );
        $away = sanitize_text_field( $data['away'] ?? '' );
        if ( empty( $home ) || empty( $away ) ) return false;

        // Use the date provided in the scraped/fetched data, fallback to WAT current date
        $match_date = ! empty( $data['date'] ) ? sanitize_text_field( $data['date'] ) : '';
        if ( empty( $match_date ) ) {
            $wat = new DateTimeZone( 'Africa/Lagos' );
            $match_date = ( new DateTime( 'now', $wat ) )->format( 'Y-m-d' );
        }

        $guid  = md5( strtolower( $home ) . strtolower( $away ) . $match_date );

        // Deduplication check 1: Direct GUID lookup
        $existing = new WP_Query( [
            'post_type'      => 'crane_prediction',
            'post_status'    => 'any',
            'meta_key'       => '_crane_pred_guid',
            'meta_value'     => $guid,
            'posts_per_page' => 1,
            'fields'         => 'ids',
        ] );
        if ( $existing->have_posts() ) return false;

        // Deduplication check 2: 3-day window fuzzy cross-source check (resolves date-shifts/timezone differences)
        $date_window = [
            gmdate( 'Y-m-d', strtotime( '-1 day' ) ),
            gmdate( 'Y-m-d' ),
            gmdate( 'Y-m-d', strtotime( '+1 day' ) ),
        ];
        $existing_window = new WP_Query( [
            'post_type'      => 'crane_prediction',
            'post_status'    => 'any',
            'posts_per_page' => 150,
            'meta_query'     => [
                [
                    'key'     => 'match_date',
                    'value'   => $date_window,
                    'compare' => 'IN',
                ]
            ]
        ] );

        if ( $existing_window->have_posts() ) {
            foreach ( $existing_window->posts as $ex_post ) {
                $ex_home = get_post_meta( $ex_post->ID, 'team1_name', true );
                $ex_away = get_post_meta( $ex_post->ID, 'team2_name', true );
                if ( self::teams_are_similar( $ex_home, $home ) && self::teams_are_similar( $ex_away, $away ) ) {
                    error_log( "Crane Scraper: Blocked duplicate match found via fuzzy matching: {$home} vs {$away} (Existing: {$ex_home} vs {$ex_away})" );
                    return false;
                }
            }
        }

        // Build the VERDICT label
        $verdict = self::build_verdict( $home, $away, $data['prediction'] ?? '1' );

        // Get logos via TheSportsDB (reuse existing method if available)
        $home_logo = '';
        $away_logo = '';
        if ( class_exists( 'Crane_Prediction_API_Service' ) ) {
            $home_logo = Crane_Prediction_API_Service::get_team_logo( $home );
            $away_logo = Crane_Prediction_API_Service::get_team_logo( $away );
        }

        $admin_users = get_users( [ 'role' => 'administrator', 'fields' => 'ids', 'number' => 1 ] );
        $author_id   = ! empty( $admin_users ) ? $admin_users[0] : 1;

        $post_id = wp_insert_post( [
            'post_title'  => $home . ' vs ' . $away,
            'post_status' => 'publish',
            'post_type'   => 'crane_prediction',
            'post_author' => $author_id,
            'post_date'   => current_time( 'mysql' ),
        ] );

        if ( ! $post_id || is_wp_error( $post_id ) ) return false;

        // Core card fields (identical to Prediction_API_Service)
        update_post_meta( $post_id, 'team1_name',    $home );
        update_post_meta( $post_id, 'team2_name',    $away );
        update_post_meta( $post_id, 'team1_logo',    $home_logo );
        update_post_meta( $post_id, 'team2_logo',    $away_logo );
        update_post_meta( $post_id, 'match_league',  sanitize_text_field( $data['league'] ?? 'Football' ) );
        update_post_meta( $post_id, 'match_time',    sanitize_text_field( $data['time'] ?? '' ) );
        update_post_meta( $post_id, 'match_date',    $match_date );
        update_post_meta( $post_id, 'match_status',  'NS' );

        // Odds (available from Odds API; empty string from Forebet)
        update_post_meta( $post_id, 'match_odd1',    sanitize_text_field( $data['odd1'] ?? '' ) );
        update_post_meta( $post_id, 'match_oddX',    sanitize_text_field( $data['oddX'] ?? '' ) );
        update_post_meta( $post_id, 'match_odd2',    sanitize_text_field( $data['odd2'] ?? '' ) );

        // The main verdict shown on the card
        update_post_meta( $post_id, '_crane_free_tip', $verdict );

        // Predicted score (Forebet only)
        if ( ! empty( $data['score'] ) ) {
            update_post_meta( $post_id, 'match_predicted_score', sanitize_text_field( $data['score'] ) );
        }

        // Source tag
        update_post_meta( $post_id, 'prediction_source', $source );

        // Deduplication key
        update_post_meta( $post_id, '_crane_pred_guid', $guid );

        // Build _crane_prediction_analysis JSON (for the probability bars on single page)
        if ( ! empty( $data['home_prob'] ) ) {
            $analysis = [
                'predictions' => [
                    'advice'  => $verdict,
                    'percent' => [
                        'home' => $data['home_prob'],
                        'draw' => $data['draw_prob'],
                        'away' => $data['away_prob'],
                    ],
                ],
                'comparison' => [],
            ];
            update_post_meta( $post_id, '_crane_prediction_analysis', json_encode( $analysis ) );
        }

        return true;
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    /**
     * Format the verdict string shown on the card
     */
    private static function build_verdict( $home, $away, $prediction ) {
        switch ( strtoupper( trim( $prediction ) ) ) {
            case '1':
                return strtoupper( $home ) . ' WIN';
            case '2':
                return strtoupper( $away ) . ' WIN';
            case 'X':
                return 'DRAW';
            case '1X':
                return strtoupper( $home ) . ' OR DRAW';
            case 'X2':
                return 'DRAW OR ' . strtoupper( $away );
            case '12':
                return strtoupper( $home ) . ' OR ' . strtoupper( $away );
            default:
                return strtoupper( $home ) . ' WIN';
        }
    }

    /**
     * Map Odds API sport key to a human-readable league name
     */
    private static function sport_key_to_league( $key ) {
        $map = [
            // International Tournaments
            'soccer_fifa_world_cup'                    => 'FIFA World Cup 2026',
            'soccer_uefa_nations_league'               => 'UEFA Nations League',
            'soccer_conmebol_copa_america'             => 'Copa América',
            'soccer_concacaf_gold_cup'                 => 'CONCACAF Gold Cup',
            // Top European Club Leagues
            'soccer_epl'                               => 'Premier League',
            'soccer_spain_la_liga'                     => 'La Liga',
            'soccer_germany_bundesliga'                => 'Bundesliga',
            'soccer_italy_serie_a'                     => 'Serie A',
            'soccer_france_ligue_one'                  => 'Ligue 1',
            // European Club Cups
            'soccer_uefa_champs_league'                => 'UEFA Champions League',
            'soccer_uefa_europa_league'                => 'UEFA Europa League',
            'soccer_uefa_europa_conference_league'     => 'UEFA Conference League',
            // Other European
            'soccer_netherlands_eredivisie'            => 'Eredivisie',
            'soccer_portugal_primeira_liga'            => 'Liga Portugal',
            'soccer_england_league1'                   => 'EFL League One',
            'soccer_turkey_super_league'               => 'Süper Lig',
            'soccer_greece_super_league'               => 'Greek Super League',
            'soccer_sweden_allsvenskan'                => 'Allsvenskan',
            'soccer_norway_eliteserien'                => 'Eliteserien',
            // Americas
            'soccer_usa_mls'                           => 'MLS',
            'soccer_brazil_campeonato'                 => 'Brasileirão',
            'soccer_argentina_primera_division'        => 'Primera División',
            'soccer_conmebol_copa_libertadores'        => 'Copa Libertadores',
            // Middle East
            'soccer_saudi_arabio_premiere_league'      => 'Saudi Pro League',
            // Africa
            'soccer_nigeria_npfl'                      => 'NPFL',
            'soccer_africa_caf_champions_league'       => 'CAF Champions League',
            'soccer_south_africa_premier_division'     => 'South Africa PSL',
            'soccer_ghana_premier_league'              => 'Ghana Premier League',
        ];
        return $map[ $key ] ?? ucwords( str_replace( [ 'soccer_', '_' ], [ '', ' ' ], $key ) );
    }

    /**
     * Check if a league name is European
     */
    private static function is_european_league( $league_name ) {
        $lower = strtolower( $league_name );
        foreach ( self::$european_leagues as $keyword ) {
            if ( strpos( $lower, $keyword ) !== false ) return true;
        }
        return false;
    }

    /**
     * Public wrapper for is_off_season() — used by the admin panel in crane-bets-core.php.
     */
    public static function is_off_season_public() {
        return self::is_off_season();
    }

    /**
     * Detect whether European CLUB leagues are in off-season.
     *
     * IMPORTANT: This does NOT mean no football is happening. International
     * tournaments (World Cup, Euros, Copa América, Nations League) run precisely
     * during the club off-season. Always check is_major_tournament_active()
     * alongside this method before suppressing predictions.
     *
     * Off-season window: May 16 – August 14 (club leagues only).
     */
    private static function is_off_season() {
        $tz    = new DateTimeZone( 'Africa/Lagos' );
        $now   = new DateTime( 'now', $tz );
        $month = (int) $now->format( 'n' );
        $day   = (int) $now->format( 'j' );

        // Second half of May (after Champions League final)
        if ( $month === 5 && $day >= 16 ) return true;
        // June and July — club off-season, BUT major tournaments run here
        if ( $month === 6 || $month === 7 ) return true;
        // First half of August (before new club seasons begin)
        if ( $month === 8 && $day <= 14 ) return true;

        return false;
    }

    /**
     * Detect whether a major international football tournament is currently active.
     *
     * Tournaments covered:
     *  - FIFA World Cup (quadrennial, June–July: 2026, 2030, 2034 …)
     *  - UEFA European Championship (quadrennial, June–July: 2024, 2028, 2032 …)
     *  - Copa América (held irregularly in June–July)
     *  - CONCACAF Gold Cup (odd years, June–July)
     *  - UEFA Nations League Finals (June, even years)
     *
     * Returns true if a major tournament is expected to be running right now.
     */
    public static function is_major_tournament_active() {
        $tz    = new DateTimeZone( 'Africa/Lagos' );
        $now   = new DateTime( 'now', $tz );
        $month = (int) $now->format( 'n' );
        $day   = (int) $now->format( 'j' );
        $year  = (int) $now->format( 'Y' );

        // FIFA World Cup: runs ~June 11 – July 19 every 4 years starting 2026
        $wc_years = [ 2026, 2030, 2034, 2038 ];
        if ( in_array( $year, $wc_years, true ) ) {
            if ( ( $month === 6 && $day >= 11 ) || ( $month === 7 && $day <= 19 ) ) {
                return "FIFA World Cup $year";
            }
        }

        // UEFA European Championship: runs ~June 14 – July 14 every 4 years
        $euro_years = [ 2024, 2028, 2032, 2036 ];
        if ( in_array( $year, $euro_years, true ) ) {
            if ( ( $month === 6 && $day >= 14 ) || ( $month === 7 && $day <= 14 ) ) {
                return "UEFA Euro $year";
            }
        }

        // Copa América: approximately June–July
        $copa_years = [ 2021, 2024, 2027, 2031, 2035 ];
        if ( in_array( $year, $copa_years, true ) ) {
            if ( ( $month === 6 && $day >= 15 ) || ( $month === 7 && $day <= 15 ) ) {
                return "Copa América $year";
            }
        }

        // CONCACAF Gold Cup: odd years, roughly June 15 – July 16
        if ( $year % 2 !== 0 ) {
            if ( ( $month === 6 && $day >= 15 ) || ( $month === 7 && $day <= 16 ) ) {
                return "CONCACAF Gold Cup $year";
            }
        }

        // UEFA Nations League Finals: June, even years
        if ( $year % 2 === 0 && $month === 6 ) {
            return "UEFA Nations League Finals $year";
        }

        return false;
    }

    /**
     * Fetch a URL with browser headers and gzip decoding
     */
    private static function fetch_url( $url ) {
        // Try shell_exec with native curl.exe first to bypass Cloudflare WAF / fingerprint blocks
        if ( function_exists( 'shell_exec' ) && ! in_array( 'shell_exec', array_map( 'trim', explode( ',', ini_get( 'disable_functions' ) ) ), true ) ) {
            $userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';
            $cmd = 'curl.exe -s -L -A ' . escapeshellarg( $userAgent ) . ' ' . escapeshellarg( $url );
            $output = shell_exec( $cmd );

            // Check if we got a valid response (not empty, not containing 403/Cloudflare challenge)
            if ( ! empty( $output ) && strpos( $output, '403 Forbidden' ) === false && strpos( $output, '<title>Please Wait... | Cloudflare</title>' ) === false ) {
                return $output;
            }
            error_log( "Crane Scraper: shell_exec curl.exe failed or returned block page for {$url}. Falling back to PHP curl." );
        }

        if ( ! function_exists( 'curl_init' ) ) {
            $response = wp_remote_get( $url, [
                'timeout'   => 20,
                'headers'   => self::browser_headers(),
                'sslverify' => false,
            ] );

            if ( is_wp_error( $response ) ) {
                error_log( 'Crane Scraper fetch error: ' . $response->get_error_message() );
                return null;
            }

            $code = wp_remote_retrieve_response_code( $response );
            if ( $code !== 200 ) {
                error_log( "Crane Scraper: HTTP {$code} for {$url}" );
                return null;
            }

            return wp_remote_retrieve_body( $response );
        }

        $ch      = curl_init();
        $headers = [];
        foreach ( self::browser_headers() as $key => $val ) {
            $headers[] = "{$key}: {$val}";
        }

        curl_setopt( $ch, CURLOPT_URL, $url );
        curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
        curl_setopt( $ch, CURLOPT_TIMEOUT, 20 );
        curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, false );
        curl_setopt( $ch, CURLOPT_SSL_VERIFYHOST, false );
        curl_setopt( $ch, CURLOPT_HTTPHEADER, $headers );
        curl_setopt( $ch, CURLOPT_ENCODING, '' ); // Automatically decode gzip/deflate
        curl_setopt( $ch, CURLOPT_FOLLOWLOCATION, true );
        curl_setopt( $ch, CURLOPT_MAXREDIRS, 5 );
        curl_setopt( $ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36' );

        $body = curl_exec( $ch );
        $code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
        $err  = curl_error( $ch );
        curl_close( $ch );

        if ( $err ) {
            error_log( "Crane Scraper curl error for {$url}: {$err}" );
            return null;
        }

        if ( $code !== 200 ) {
            error_log( "Crane Scraper: HTTP {$code} for {$url}" );
            return null;
        }

        return $body;
    }

    /**
     * Normalize team name for similarity comparison
     */
    private static function normalize_team_name( $name ) {
        $name = strtolower( html_entity_decode( $name, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
        
        // Strip common designations, abbreviations, and punctuation
        $strip = [
            ' f.c.', ' fc', ' utd', ' united', ' city', ' town', ' athletic', 
            ' albion', ' rovers', ' wanderers', ' hotspur', ' spurs', ' real', 
            ' ac ', ' a.c. ', ' cf', ' c.f.', ' de ', ' la ', ' le ', '1. ', 
            'sv ', 'rc ', 'sc ', 'spg ', ' club', ' de fútbol', ' de futbol',
            ' association', ' claud', ' c.d.', ' cd'
        ];
        $name = str_replace( $strip, '', ' ' . $name . ' ' );
        
        // Remove all non-alphanumeric chars
        $name = preg_replace( '/[^a-z0-9]/', '', $name );
        return trim( $name );
    }

    /**
     * Check if two team names are fuzzy matches
     */
    private static function teams_are_similar( $team_a, $team_b ) {
        $norm_a = self::normalize_team_name( $team_a );
        $norm_b = self::normalize_team_name( $team_b );

        if ( empty( $norm_a ) || empty( $norm_b ) ) return false;
        if ( $norm_a === $norm_b ) return true;

        // Direct Levenshtein check
        $dist = levenshtein( $norm_a, $norm_b );
        if ( $dist <= 2 ) {
            return true;
        }

        // Direct abbreviation/synonym overrides
        $synonyms = [
            'manutd' => 'manchesterunited',
            'manunited' => 'manchesterunited',
            'psg' => 'parissaintgermain',
            'tottenham' => 'tottenhamhotspur',
            'nottmforest' => 'nottinghamforest',
            'bvb' => 'borussiadortmund',
            'dortmund' => 'borussiadortmund',
            'la' => 'losangeles',
            'lacy' => 'losangeles',
            'ny' => 'newyork',
            'nycf' => 'newyork',
            'inter' => 'internazionale',
        ];

        $s_a = $synonyms[ $norm_a ] ?? $norm_a;
        $s_b = $synonyms[ $norm_b ] ?? $norm_b;
        if ( $s_a === $s_b || levenshtein( $s_a, $s_b ) <= 2 ) {
            return true;
        }

        // Token intersection check (e.g. "Real Madrid" vs "Madrid")
        if ( strlen( $norm_a ) >= 4 && strlen( $norm_b ) >= 4 ) {
            if ( strpos( $norm_a, $norm_b ) !== false || strpos( $norm_b, $norm_a ) !== false ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Manual trigger handler from admin button
     */
    public static function handle_manual_fetch() {
        if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( $_POST['_wpnonce'], 'crane_free_scraper_fetch' ) ) {
            wp_die( 'Security check failed.' );
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Unauthorized.' );
        }

        // Clear cached 'none' team logos so they can be re-fetched with the new smart matching logic
        global $wpdb;
        $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'crane_logo_%' AND option_value = 'none'" );

        // Force fresh run by clearing transients
        delete_transient( 'crane_forebet_last_run' );
        delete_transient( 'crane_oddsapi_last_run' );

        $imported = self::run();

        wp_redirect( admin_url( 'admin.php?page=crane-api-settings&free_imported=' . $imported ) );
        exit;
    }
}
