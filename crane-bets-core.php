<?php
/*
Plugin Name: Crane Bets Core
Description: Backbone functionality for Crane bets Theme (VIP Timer, Accuracy, API Sync, Demo Tools).
Version: 1.1.1
Author: Ashiekaa Elijah
Author URI: https://kator.vercel.app/

- Auto-Created Pages: Locker Room ([crane_locker_room]), Leaderboard ([crane_leaderboard]), VIP ([crane_vip_timer]), User Dashboard ([crane_user_dashboard])
- Demo Data: Uses 'crane_demo = 1' meta key to identify and safely delete simulated content.
- Meta Keys used: 
  - User: crane_wins, crane_accuracy_level (Novice/Vibe Master/Crane God), crane_vip_timer, crane_demo
  - Posts: crane_demo, crane_article_hash, fixture_id
*/

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'CRANE_BETS_CORE_PATH', plugin_dir_path( __FILE__ ) );

if ( ! class_exists( 'Crane_Bets_Core' ) ) {
    class Crane_Bets_Core {

    // Core Services Bootstrapper (Structural Integrity Fix)
    private function boot_services() {
        $services = array(
            'Template_Service.php'   => 'Crane_Template_Service',
            'Auth_Service.php'       => 'Crane_Auth_Service',
            'VIP_Service.php'        => 'Crane_VIP_Service',
            'Locker_Service.php'     => 'Crane_Locker_Service',
            'Affiliate_Service.php'  => 'Crane_Affiliate_Service',
            'Prediction_API_Service.php' => 'Crane_Prediction_API_Service',
            'Commission_Admin.php'   => 'Crane_Commission_Admin',
            'RSS_News_Fetcher.php'   => 'Crane_RSS_News_Fetcher',
            'User_Prediction_Service.php' => 'Crane_User_Prediction_Service',
            'VIP_Email_Service.php'  => 'Crane_VIP_Email_Service',
            'Security_Service.php'   => 'Crane_Security_Service',
            'Avatar_Service.php'     => 'Crane_Avatar_Service',
            'Free_Prediction_Scraper.php' => 'Crane_Free_Prediction_Scraper'
        );

        foreach ( $services as $file => $class ) {
            $path = CRANE_BETS_CORE_PATH . 'includes/' . $file;
            if ( file_exists( $path ) ) {
                require_once $path;
                if ( class_exists( $class ) && method_exists( $class, 'boot' ) ) {
                    $class::boot();
                }
            }
        }
    }

    private static $instance = null;

    public static function get_instance() {
        if ( is_null( self::$instance ) ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __clone() {}
    public function __wakeup() {}

    private function __construct() {
        // Essential Boot Sequence (Structural Alignment)
        $this->boot_services();

        // Core Hooks
        add_action( 'init', array( $this, 'register_post_types' ) );
        add_action( 'show_user_profile', array( $this, 'add_vip_fields' ) );
        add_action( 'edit_user_profile', array( $this, 'add_vip_fields' ) );
        add_action( 'personal_options_update', array( $this, 'save_vip_fields' ) );
        add_action( 'edit_user_profile_update', array( $this, 'save_vip_fields' ) );
        add_action( 'user_register', array( $this, 'initialize_user_meta' ) );

        add_filter( 'manage_users_columns', array( $this, 'add_user_columns' ) );
        add_filter( 'manage_users_custom_column', array( $this, 'render_user_columns' ), 10, 3 );

        add_action( 'add_meta_boxes', array( $this, 'add_match_meta_boxes' ) );
        add_action( 'save_post', array( $this, 'save_match_meta' ) );
        add_action( 'admin_enqueue_scripts', function() { wp_enqueue_media(); } );

        // Defer deeper integrations until all plugins are ready (Issue #10)
        add_action( 'plugins_loaded', array( $this, 'initialize_integrations' ) );

        // Shortcodes (Delegated to Service Classes)
        add_shortcode( 'crane_affiliate_dashboard', array( 'Crane_Affiliate_Service', 'render_affiliate_dashboard' ) );

        // Other AJAX
        add_action( 'wp_ajax_crane_save_bank_details', array( $this, 'handle_save_bank_details' ) );

        add_shortcode( 'crane_lost_password_form', array( 'Crane_Auth_Service', 'render_lost_password_form' ) );

        add_action( 'after_setup_theme', array( $this, 'sync_theme_menu_locations' ) );

        // Assets
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_crane_core_assets' ) );

        // Navigation Sync
        add_filter( 'wp_nav_menu_objects', array( $this, 'filter_nav_menu_visibility' ), 10, 2 );

        // Affiliate & Commissions (Delegated to Service)
        add_action( 'init', array( 'Crane_Affiliate_Service', 'capture_referral_id' ) );

        // Dynamic Template Routing
        add_filter( 'template_include', array( $this, 'force_crane_page_templates' ) );
        
        // Security: Block direct CPT template access
        add_action( 'template_redirect', array( $this, 'block_cpt_direct_access' ) );

        // Cron Syncs — Prediction API
        add_action( 'crane_sync_predictions_cron_v2', array( 'Crane_Prediction_API_Service', 'sync_predictions' ) );
        add_action( 'crane_sync_odds_cron', array( 'Crane_Prediction_API_Service', 'sync_odds' ) );
        // Cleanup hooked to BOTH services so it always runs regardless of source setting
        add_action( 'crane_cleanup_predictions_cron', array( 'Crane_Prediction_API_Service', 'cleanup_old_predictions' ) );
        add_action( 'crane_cleanup_predictions_cron', array( 'Crane_Free_Prediction_Scraper', 'cleanup_old_predictions' ) );

        // Manual purge old predictions
        add_action( 'admin_post_crane_purge_old_predictions', array( $this, 'handle_purge_old_predictions' ) );

        // Manual sync trigger
        add_action( 'admin_post_crane_manual_sync', array( 'Crane_Prediction_API_Service', 'handle_manual_sync' ) );

        // Sub-Service Integrations
        add_action( 'admin_menu', array( 'Crane_Commission_Admin', 'register_menu' ) );
        add_action( 'admin_post_crane_payout_commission', array( 'Crane_Commission_Admin', 'handle_payout' ) );
        add_action( 'admin_post_crane_manual_payout', array( 'Crane_Commission_Admin', 'handle_manual_payout' ) );

        add_action( 'crane_vip_daily_email_cron', array( 'Crane_VIP_Email_Service', 'send_daily_vip_email' ) );
        add_action( 'admin_post_crane_manual_vip_email', array( 'Crane_VIP_Email_Service', 'handle_manual_vip_email' ) );
        add_action( 'admin_post_crane_manual_vip_assignment', array( $this, 'handle_manual_vip_assignment' ) );

        add_action( 'crane_fetch_news_cron', array( 'Crane_RSS_News_Fetcher', 'fetch_and_post_news' ) );
        add_action( 'admin_post_crane_manual_fetch_news', array( 'Crane_RSS_News_Fetcher', 'handle_manual_fetch' ) );

        // Custom cron interval
        add_filter( 'cron_schedules', array( $this, 'add_crane_cron_intervals' ) );

        // SMTP & Testing Tools
        add_action( 'admin_post_crane_test_vip', array( $this, 'simulate_vip_purchase' ) );
    }

    /**
     * Defer initialization to ensure WooCommerce & other dependencies are available
     */
    public function initialize_integrations() {
        // Admin Hooks
        add_action( 'admin_init', array( $this, 'register_crane_settings' ) );
        add_action( 'admin_menu', array( $this, 'crane_register_tools_menu' ) );
        add_action( 'admin_post_crane_import_demo', array( $this, 'crane_import_demo_data' ) );
        add_action( 'admin_post_crane_delete_demo', array( $this, 'crane_delete_demo_data' ) );
        add_action( 'admin_post_crane_clear_logo_cache', array( $this, 'handle_clear_logo_cache' ) );
        add_action( 'admin_notices', array( $this, 'registration_admin_notice' ) );

        // Version-stamped upgrade check
        add_action( 'admin_init', array( $this, 'maybe_run_upgrade' ) );

        // WooCommerce Hooks
        if ( class_exists( 'WooCommerce' ) ) {
            add_action( 'woocommerce_order_status_completed', array( 'Crane_Affiliate_Service', 'process_commission' ) );
            add_action( 'woocommerce_order_status_completed', array( 'Crane_VIP_Service', 'handle_vip_purchase' ) );
            add_filter( 'woocommerce_is_purchasable', array( 'Crane_Affiliate_Service', 'check_referral_unlock' ), 10, 2 );
            
            // UX: Replace generic "No Products" with VIP sales pitch
            remove_action( 'woocommerce_no_products_found', 'wc_no_products_found' );
            add_action( 'woocommerce_no_products_found', array( $this, 'crane_empty_shop_ux' ) );

            // UX: Direct Checkout
            add_filter( 'woocommerce_add_to_cart_redirect', array( $this, 'crane_skip_cart_redirect' ) );
        }
    }


    /**
     * Security: Block direct CPT access
     */
    public function block_cpt_direct_access() {
        if ( is_singular( array('crane_user_pick', 'crane_match') ) || is_post_type_archive( array('crane_user_pick', 'crane_prediction') ) ) {
            wp_redirect( home_url( '/' ) );
            exit;
        }
    }

    /**
     * UX: Skip Cart Page for Frictionless Path (Conditional Guard)
     * Only redirect to checkout for Predictions and VIP.
     * Merchandise (Jerseys) should stay in the shop for multi-item sales.
     */
    public function crane_skip_cart_redirect( $url ) {
        if ( ! isset( $_REQUEST['add-to-cart'] ) || ! is_numeric( $_REQUEST['add-to-cart'] ) ) return $url;
        
        $product_id = (int) $_REQUEST['add-to-cart'];
        $product = wc_get_product( $product_id );
        if ( ! $product ) return $url;

        // One-Click Categories
        $is_vip = ( $product_id === (int) get_option( 'crane_vip_product_id' ) );
        $is_prediction = has_term( 'predictions', 'product_cat', $product_id );

        if ( $is_vip || $is_prediction ) {
            return wc_get_checkout_url();
        }

        return $url;
    }

    /**
     * UX: Custom Empty Shop Template
     */
    public function crane_empty_shop_ux() {
        ?>
        <div class="bg-crane-green/10 border border-crane-green/30 rounded-3xl p-16 text-center backdrop-blur-xl max-w-4xl mx-auto my-12 relative overflow-hidden">
            <div class="absolute -top-40 -right-40 w-80 h-80 bg-crane-green/10 rounded-full blur-[100px]"></div>
            <div class="inline-flex items-center justify-center w-20 h-20 rounded-full bg-crane-green/10 text-crane-green text-3xl mb-8 border border-crane-green/20">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" class="w-10 h-10"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path></svg>
            </div>
            <h2 class="text-4xl font-black text-white uppercase italic tracking-tighter mb-4">Private Sector <span class="text-crane-green">Locked</span></h2>
            <p class="text-white/60 mb-10 max-w-xl mx-auto text-sm leading-relaxed">The active VIP subscriptions are currently being cycled. The next batch of premium access keys will be available shortly. Only verified members will be granted passage.</p>
            <div class="flex gap-4 justify-center">
                 <a href="<?php echo esc_url( self::get_instance()->get_crane_url('vip') ); ?>" class="bg-crane-green hover:bg-crane-green/80 text-black px-10 py-5 rounded-2xl text-[11px] font-black uppercase tracking-widest transition-all shadow-lg shadow-crane-green/20">Check VIP Status</a>
            </div>
        </div>
        <?php
    }

    public function simulate_vip_purchase() {
        if ( ! current_user_can('manage_options') ) wp_die('-1');
        check_admin_referer('crane_test_vip'); // Prevent CSRF
        $user_id = get_current_user_id();
        if ( class_exists('Crane_VIP_Service') ) {
            Crane_VIP_Service::award_vip_status( $user_id, 'purchase' );
            wp_die( "<b>Success!</b> Simulated Paystack hook cleared. Premium Status awarded to Admin User #{$user_id}. <br><br><a href='" . self::get_instance()->get_crane_url('vip') . "'>Return to VIP</a>", "Action Successful" );
        }
        wp_die( "Failure. VIP Service architecture inaccessible." );
    }

    /**
     * Version-stamped upgrade routine.
     * Runs once per plugin version change on admin_init.
     * Covers zip re-uploads where register_activation_hook doesn't fire.
     */
    public function maybe_run_upgrade() {
        $current_version = '1.1';
        $stored_version  = get_option( 'crane_plugin_version', '0' );
        if ( $stored_version === $current_version ) return;

        // 1. Custom Table Migration (Architecture Fix)
        self::install_custom_tables();

        // 2. Data Migration: Port existing CPT picks to Custom Table
        if ( version_compare( $stored_version, '1.3.0', '<' ) ) {
            self::migrate_legacy_picks();
        }

        // 3. Setup ActionScheduler hooks (Production Cron Replacement)
        self::setup_action_scheduler_events();

        // Run idempotent setup that may have been missed
        self::crane_create_required_pages();

        // 4. Auto-detect VIP Product if not set
        if ( ! get_option( 'crane_vip_product_id' ) ) {
            $vip_product = get_page_by_path( 'vip-inner-circle-access', OBJECT, 'product' );
            if ( $vip_product ) {
                update_option( 'crane_vip_product_id', $vip_product->ID );
            } else {
                // Try searching by name
                $vip_search = get_posts( array( 'post_type' => 'product', 'title' => 'VIP Inner Circle Access', 'posts_per_page' => 1 ) );
                if ( ! empty( $vip_search ) ) {
                    update_option( 'crane_vip_product_id', $vip_search[0]->ID );
                }
            }
        }

        // Ensure CRONs exist (safe — wp_next_scheduled guards against dupes)
        $predictions_schedule = wp_get_schedule( 'crane_sync_predictions_cron_v2' );
        if ( ! $predictions_schedule || $predictions_schedule !== 'crane_2hours' ) {
            wp_clear_scheduled_hook( 'crane_sync_predictions_cron_v2' );
            wp_schedule_event( time(), 'crane_2hours', 'crane_sync_predictions_cron_v2' );
        }
        if ( ! wp_next_scheduled( 'crane_sync_odds_cron' ) ) {
            wp_schedule_event( time(), 'twicedaily', 'crane_sync_odds_cron' );
        }
        if ( ! wp_next_scheduled( 'crane_cleanup_predictions_cron' ) ) {
            wp_schedule_event( time(), 'daily', 'crane_cleanup_predictions_cron' );
        }
        if ( ! wp_next_scheduled( 'crane_vip_daily_email_cron' ) ) {
            $next_8am = strtotime( 'today 08:00' );
            if ( $next_8am < time() ) $next_8am = strtotime( 'tomorrow 08:00' );
            wp_schedule_event( $next_8am, 'daily', 'crane_vip_daily_email_cron' );
        }
        if ( ! wp_next_scheduled( 'crane_fetch_news_cron' ) ) {
            wp_schedule_event( time(), 'hourly', 'crane_fetch_news_cron' );
        }

        if ( version_compare( $stored_version, '1.0.1', '<' ) ) {
            wp_clear_scheduled_hook( 'crane_sync_predictions_cron' );
            if ( function_exists( 'as_unschedule_all_actions' ) ) {
                as_unschedule_all_actions( 'crane_sync_predictions_as' );
            }
        }

        update_option( 'crane_plugin_version', $current_version );
        error_log( "Crane Bets: Upgrade routine completed (v{$stored_version} → v{$current_version})" );
    }

    /**
     * Database Infrastructure: Migrate Predictions to Custom Table
     */
    public static function install_custom_tables() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        // 1. Predictions Table
        $table_predictions = $wpdb->prefix . 'crane_predictions';
        $sql_predictions = "CREATE TABLE IF NOT EXISTS $table_predictions (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL,
            match_name varchar(200) NOT NULL,
            league varchar(100) DEFAULT '' NOT NULL,
            selection varchar(50) NOT NULL,
            odds decimal(5,2) NOT NULL DEFAULT 1.00,
            status varchar(20) DEFAULT 'pending' NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            settled_at datetime DEFAULT NULL,
            PRIMARY KEY  (id),
            KEY user_id (user_id),
            KEY status (status),
            KEY league (league),
            KEY created_at (created_at)
        ) $charset_collate;";

        // 2. Likes Table (Performance Fix for Serialized Meta)
        $table_likes = $wpdb->prefix . 'crane_likes';
        $sql_likes = "CREATE TABLE IF NOT EXISTS $table_likes (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            post_id bigint(20) unsigned NOT NULL,
            user_id bigint(20) unsigned NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY post_user (post_id, user_id),
            KEY post_id (post_id)
        ) $charset_collate;";

        require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
        dbDelta( $sql_predictions );
        dbDelta( $sql_likes );
    }

    /**
     * Migration: One-time port from wp_posts/wp_postmeta to Custom Table
     */
    public static function migrate_legacy_picks() {
        global $wpdb;
        $table = $wpdb->prefix . 'crane_predictions';

        $legacy_picks = new WP_Query( array(
            'post_type'      => 'crane_user_pick',
            'post_status'    => 'any',
            'posts_per_page' => -1,
            'fields'         => 'ids'
        ) );

        if ( ! $legacy_picks->have_posts() ) return;

        foreach ( $legacy_picks->posts as $pid ) {
            $user_id   = get_post_field( 'post_author', $pid );
            $match     = get_post_meta( $pid, '_crane_pick_match', true );
            $league    = get_post_meta( $pid, '_crane_pick_league', true );
            $selection = get_post_meta( $pid, '_crane_pick_selection', true );
            $odds      = floatval( get_post_meta( $pid, '_crane_pick_odds', true ) ?: 1.00 );
            $status    = get_post_meta( $pid, '_crane_pick_result', true ) ?: 'pending';
            $date      = get_post_field( 'post_date', $pid );

            $wpdb->insert( $table, array(
                'user_id'    => $user_id,
                'match_name' => $match,
                'league'     => $league,
                'selection'  => $selection,
                'odds'       => $odds,
                'status'     => $status,
                'created_at' => $date
            ) );
        }
        wp_reset_postdata();
    }

    /**
     * Production Cron: Initialize ActionScheduler recurring tasks
     */
    public static function setup_action_scheduler_events() {
        if ( ! function_exists( 'as_has_scheduled_action' ) ) return;

        // Replace 30min Sync with AS (High reliability)
        if ( ! as_has_scheduled_action( 'crane_sync_predictions_as_v2' ) ) {
            as_schedule_recurring_action( time(), 7200, 'crane_sync_predictions_as_v2' );
        }
        
        // Replacement for Daily odds and cleanup if needed
        if ( ! as_has_scheduled_action( 'crane_cleanup_old_predictions_as' ) ) {
            as_schedule_recurring_action( time(), 86400, 'crane_cleanup_old_predictions_as' );
        }
    }

    public function sync_theme_menu_locations() {
        $menu_exists = wp_get_nav_menu_object( 'Crane Bets Main Menu' );
        if ( $menu_exists ) {
            $locations = get_theme_mod( 'nav_menu_locations' ) ? get_theme_mod( 'nav_menu_locations' ) : array();
            if ( empty( $locations['primary'] ) || empty( $locations['mobile'] ) ) {
                $locations['primary'] = $menu_exists->term_id;
                $locations['mobile']  = $menu_exists->term_id;
                set_theme_mod( 'nav_menu_locations', $locations );
            }
        }
    }

    /**
     * Legacy proxy for external calls directly to core.
     */
    public function update_accuracy_status( $user_id ) {
        if ( class_exists( 'Crane_VIP_Service' ) ) {
            Crane_VIP_Service::update_accuracy_status( $user_id );
        }
    }

    /**
     * Save user bank details for commission payouts
     */
    public function handle_save_bank_details() {
        check_ajax_referer( 'crane_security_nonce', 'security' );
        if ( ! is_user_logged_in() ) wp_send_json_error();
        $user_id = get_current_user_id();

        $bank_code    = isset($_POST['bank_code']) ? sanitize_text_field( $_POST['bank_code'] ) : '';
        $account_num  = isset($_POST['account_number']) ? sanitize_text_field( $_POST['account_number'] ) : '';
        $account_name = isset($_POST['account_name']) ? sanitize_text_field( $_POST['account_name'] ) : '';

        if ( empty( $bank_code ) || empty( $account_num ) ) {
            wp_send_json_error( array( 'message' => 'Bank and account number are required.' ) );
        }

        update_user_meta( $user_id, 'crane_bank_code', $bank_code );
        update_user_meta( $user_id, 'crane_account_number', $account_num );
        update_user_meta( $user_id, 'crane_account_name', $account_name );

        wp_send_json_success( array( 'message' => 'Bank details saved.' ) );
    }

    public function filter_nav_menu_visibility( $items, $args ) {
        if ( is_user_logged_in() ) return $items;
        
        $private_pages = array( 'Dashboard', 'Leaderboard', 'My account' );
        foreach ( $items as $key => $item ) {
            if ( in_array( $item->title, $private_pages ) ) {
                unset( $items[$key] );
            }
        }
        return $items;
    }

    /**
     * Register Custom Post Types (Refactored per Day 16 Request)
     */
    public function register_post_types() {
        register_post_type( 'crane_prediction', array(
            'labels'      => array( 'name' => 'Predictions', 'singular_name' => 'Prediction' ),
            'public'      => true,
            'has_archive' => true,
            'show_in_rest'=> false, // Disable Gutenberg
            'supports'    => array( 'title', 'author', 'thumbnail', 'custom-fields' ), // Remove 'editor'
            'menu_icon'   => 'dashicons-chart-line',
            'rewrite'     => array( 'slug' => 'predictions' ),
        ) );

         register_post_type( 'crane_locker_post', array(
            'labels'      => array( 'name' => 'Locker Room Posts', 'singular_name' => 'Locker Post' ),
            'public'      => true,
            'has_archive' => false,
            'show_in_rest'=> false, // Disable Gutenberg
            'supports'    => array( 'title', 'editor', 'author', 'thumbnail', 'comments', 'custom-fields' ),
            'menu_icon'   => 'dashicons-groups',
            'rewrite'     => array( 'slug' => 'locker-posts' ),
        ) );

        register_post_type( 'crane_testimony', array(
            'labels'      => array(
                'name'          => 'Testimonies',
                'singular_name' => 'Testimony',
                'add_new_item'  => 'Add New Win Screenshot',
                'edit_item'     => 'Edit Testimony',
            ),
            'public'      => true,
            'has_archive' => false,
            'show_in_rest'=> false,
            'supports'    => array( 'title', 'thumbnail', 'custom-fields' ),
            'menu_icon'   => 'dashicons-awards',
            'rewrite'     => array( 'slug' => 'testimonies' ),
        ) );
    }

    /**
     * Get Total Referrals (Proxy for Theme Architecture)
     */
    public function get_referral_count( $user_id ) {
        if ( class_exists( 'Crane_Affiliate_Service' ) ) {
            return Crane_Affiliate_Service::get_referral_count( $user_id );
        }
        return 0;
    }

    /**
     * Resolve Core URLs Dynamically
     */
    public function get_crane_url( $key ) {
        switch ( $key ) {
            case 'locker-room':
                $id = get_option( 'crane_page_locker_room' );
                break;
            case 'dashboard':
                $id = get_option( 'crane_page_dashboard' );
                break;
            case 'leaderboard':
                $id = get_option( 'crane_page_leaderboard' );
                break;
            case 'news':
                // Check if there is a 'news' page, otherwise fallback to blog index
                $id = get_option( 'page_for_posts' );
                if ( ! $id ) {
                    $news_page = get_page_by_path( 'news' );
                    $id = $news_page ? $news_page->ID : 0;
                }
                break;
            case 'vip':
                $id = get_option( 'crane_page_vip' );
                break;
            case 'lost-password':
                $id = get_option( 'crane_page_lost_password' );
                break;
            case 'testimonies':
                $id = get_option( 'crane_page_testimonies' );
                break;
            case 'commission':
                $id = get_option( 'crane_page_commission' );
                break;
            case 'shop':
                $id = function_exists( 'wc_get_page_id' ) ? wc_get_page_id( 'shop' ) : 0;
                break;
            default:
                return home_url( '/' );
        }

        return ( $id ) ? get_permalink( $id ) : home_url( '/' );
    }

    public function add_vip_fields( $user ) {
        $expiry = get_user_meta( $user->ID, 'crane_vip_expiry', true );
        $expiry_date = $expiry ? date('Y-m-d', (int)$expiry) : '';
        $is_vip = get_user_meta( $user->ID, 'crane_is_vip', true ) === '1';
        $source = get_user_meta( $user->ID, 'crane_vip_source', true ) ?: 'timer';
        ?>
        <h3>Crane Bets: VIP Premium Access Status</h3>
        <table class="form-table">
            <tr>
                <th><label for="crane_is_vip">VIP Status</label></th>
                <td>
                    <input type="checkbox" name="crane_is_vip" id="crane_is_vip" value="1" <?php checked( $is_vip ); ?> />
                    <span class="description">Manually grant VIP Premium Access.</span>
                </td>
            </tr>
            <tr>
                <th><label for="crane_vip_expiry">VIP Expiry Date</label></th>
                <td>
                    <input type="date" name="crane_vip_expiry" id="crane_vip_expiry" value="<?php echo esc_attr( $expiry_date ); ?>" />
                    <p class="description">Manual date override. Leave empty for activity-based (Timer) access.</p>
                </td>
            </tr>
            <tr>
                <th><label for="crane_vip_timer_display">Total Activity (Hours)</label></th>
                <td>
                    <input type="number" step="0.1" name="crane_vip_timer_manual" id="crane_vip_timer_display" value="<?php echo esc_attr( get_user_meta( $user->ID, 'crane_vip_timer', true ) ); ?>" />
                    <p class="description">Accumulated browsing hours. 400H = Auto VIP.</p>
                </td>
            </tr>
            <tr>
                <th><label for="crane_is_verified">Verification Status</label></th>
                <td>
                    <select name="crane_is_verified" id="crane_is_verified">
                        <option value="0" <?php selected( get_user_meta( $user->ID, 'crane_is_verified', true ), 0 ); ?>>Unverified</option>
                        <option value="1" <?php selected( get_user_meta( $user->ID, 'crane_is_verified', true ), 1 ); ?>>Verified</option>
                    </select>
                </td>
            </tr>
        </table>
        <?php
    }

    public function save_vip_fields( $user_id ) {
        if ( ! current_user_can( 'edit_user', $user_id ) ) return false;

        $is_vip = isset( $_POST['crane_is_vip'] ) ? '1' : '0';
        update_user_meta( $user_id, 'crane_is_vip', $is_vip );

        if ( $is_vip === '1' ) {
            // Only update source if it wasn't already set to something else (don't break 'purchase')
            $source = get_user_meta( $user_id, 'crane_vip_source', true );
            if ( ! $source || $source === 'timer' ) {
                update_user_meta( $user_id, 'crane_vip_source', 'manual' );
            }
        }

        if ( ! empty( $_POST['crane_vip_expiry'] ) ) {
            update_user_meta( $user_id, 'crane_vip_expiry', strtotime( $_POST['crane_vip_expiry'] ) );
        } else {
            delete_user_meta( $user_id, 'crane_vip_expiry' );
        }

        if ( isset( $_POST['crane_vip_timer_manual'] ) ) {
            $raw_val = sanitize_text_field( $_POST['crane_vip_timer_manual'] );
            $hours = (float) str_replace(',', '.', $raw_val);
            update_user_meta( $user_id, 'crane_vip_timer', $hours );
            update_user_meta( $user_id, 'crane_vip_seconds', floor($hours * 3600) );
        }

        if ( isset( $_POST['crane_is_verified'] ) ) {
            update_user_meta( $user_id, 'crane_is_verified', $_POST['crane_is_verified'] );
        }
    }

    /**
     * Admin Columns: User List Table
     */
    public function add_user_columns( $column ) {
        $column['crane_vip']   = 'VIP Status';
        $column['crane_timer'] = 'Timer (H)';
        $column['crane_exp']   = 'Expiry';
        return $column;
    }

    public function render_user_columns( $val, $column_name, $user_id ) {
        switch ( $column_name ) {
            case 'crane_vip':
                $is_vip = get_user_meta( $user_id, 'crane_is_vip', true ) === '1';
                $source = get_user_meta( $user_id, 'crane_vip_source', true );
                if ( $is_vip ) {
                    $color = ($source === 'purchase') ? '#00ff6a' : '#ffcc00';
                    return '<span style="color: '.$color.'; font-weight:800; text-transform:uppercase; font-size:10px;">★ ' . esc_html($source) . '</span>';
                }
                return '<span style="color: #666; font-size:10px;">Standard</span>';
                
            case 'crane_timer':
                $timer = get_user_meta( $user_id, 'crane_vip_timer', true ) ?: 0;
                return '<strong>' . esc_html($timer) . 'h</strong>';

            case 'crane_exp':
                $expiry = get_user_meta( $user_id, 'crane_vip_expiry', true );
                if ( ! $expiry ) return '-';
                $now = current_time('timestamp');
                $color = ($expiry < $now) ? '#ff4444' : '#00ff6a';
                return '<span style="color: '.$color.'">' . date('M j, Y', $expiry) . '</span>';

            default:
        }
        return $val;
    }

    public function initialize_user_meta( $user_id ) {
        // Initialize basic stats
        update_user_meta( $user_id, 'crane_wins', 0 );
        update_user_meta( $user_id, 'crane_accuracy_level', 'Novice' );
        update_user_meta( $user_id, 'crane_vip_timer', 0 );
        update_user_meta( $user_id, 'crane_last_timer_update', current_time( 'timestamp' ) );
        update_user_meta( $user_id, 'crane_vip_status', 'standard' );
        update_user_meta( $user_id, 'crane_is_verified', 0 ); // Combined verification key

        // Optimized Referral Attribution (New standard only)
        if ( isset( $_COOKIE['crane_referrer'] ) ) {
            $referrer_id = absint( $_COOKIE['crane_referrer'] );
            if ( $referrer_id > 0 && $referrer_id != $user_id ) {
                update_user_meta( $user_id, 'crane_referred_by', $referrer_id );
            }
        }
    }

    /**
     * Match Data Meta Boxes
     */
    public function add_match_meta_boxes() {
        add_meta_box( 'crane_match_details', 'Crane Match Engine Data', array($this, 'render_match_meta_box'), 'crane_prediction', 'normal', 'high' );
    }

    public function render_match_meta_box( $post ) {
        wp_nonce_field( 'crane_save_match_data', 'crane_match_nonce' );
        $league = get_post_meta( $post->ID, 'match_league', true );
        $time   = get_post_meta( $post->ID, 'match_time', true );
        $t1     = get_post_meta( $post->ID, 'team1_name', true );
        $t2     = get_post_meta( $post->ID, 'team2_name', true );
        $odd1   = get_post_meta( $post->ID, 'match_odd1', true );
        $oddX   = get_post_meta( $post->ID, 'match_oddX', true );
        $odd2   = get_post_meta( $post->ID, 'match_odd2', true );
        $t1_logo = get_post_meta( $post->ID, 'team1_logo', true );
        $t2_logo = get_post_meta( $post->ID, 'team2_logo', true );
        $is_vip_pick = get_post_meta( $post->ID, '_crane_vip_prediction', true );
        $vip_tip = get_post_meta( $post->ID, '_crane_vip_tip', true );
        ?>
        <div class="crane-admin-box" style="display: grid; grid-template-cols: 1fr 1fr; gap: 20px; padding: 10px;">
            <div style="grid-column: span 2; border-bottom: 1px solid #eee; padding-bottom: 10px; margin-bottom: 10px;">
                <label><strong>League Name:</strong></label><br>
                <input type="text" name="match_league" value="<?php echo esc_attr($league); ?>" style="width:100%" placeholder="e.g. Champions League">
            </div>
            
            <div>
                <label><strong>Team 1 (Home):</strong></label><br>
                <input type="text" name="team1_name" value="<?php echo esc_attr($t1); ?>" style="width:100%" placeholder="Team A Name"><br><br>
                <label><strong>Team 1 Logo URL:</strong></label><br>
                <input type="text" id="team1_logo" name="team1_logo" value="<?php echo esc_attr($t1_logo); ?>" style="width:80%">
                <button type="button" class="button crane-media-upload" data-target="team1_logo">Upload</button>
            </div>

            <div>
                <label><strong>Team 2 (Away):</strong></label><br>
                <input type="text" name="team2_name" value="<?php echo esc_attr($t2); ?>" style="width:100%" placeholder="Team B Name"><br><br>
                <label><strong>Team 2 Logo URL:</strong></label><br>
                <input type="text" id="team2_logo" name="team2_logo" value="<?php echo esc_attr($t2_logo); ?>" style="width:80%">
                <button type="button" class="button crane-media-upload" data-target="team2_logo">Upload</button>
            </div>

            <div style="grid-column: span 2; background: #f9f9f9; padding: 15px; border-radius: 5px;">
                <label><strong>Match Status / Time:</strong></label><br>
                <input type="text" name="match_time" value="<?php echo esc_attr($time); ?>" placeholder="e.g. LIVE or 20:45">
                <small> (Type "LIVE" for live indicator)</small>
            </div>

            <div style="grid-column: span 2;">
                <label><strong>Match Odds:</strong></label><br>
                <div style="display: flex; gap: 10px;">
                    <input type="text" name="match_odd1" value="<?php echo esc_attr($odd1); ?>" placeholder="Odd 1" style="width:33%">
                    <input type="text" name="match_oddX" value="<?php echo esc_attr($oddX); ?>" placeholder="Odd X" style="width:33%">
                    <input type="text" name="match_odd2" value="<?php echo esc_attr($odd2); ?>" placeholder="Odd 2" style="width:33%">
                </div>
            <div style="grid-column: span 2; background: #fffde7; padding: 15px; border-radius: 5px; border: 1px solid #fff59d; margin-top: 10px;">
                <label style="color: #827717; font-weight: 800; text-transform: uppercase; font-size: 11px;">⚡ Public Free Tip (Eagle Mode)</label><br>
                <input type="text" name="_crane_free_tip" value="<?php echo esc_attr( get_post_meta($post->ID, '_crane_free_tip', true) ); ?>" style="width:100%; border: 1px solid #d4e157; height: 45px; font-weight: 800; font-size: 16px; color: #33691e;" placeholder="e.g. Real Sociedad Win">
                <p class="description">This will be the main prediction shown on the homepage card.</p>
            </div>
        </div>
        <script>
            jQuery(document).ready(function($){
                $('.crane-media-upload').click(function(e) {
                    e.preventDefault();
                    var target = $(this).data('target');
                    var custom_uploader = wp.media({
                        title: 'Select Team Logo',
                        button: { text: 'Use this logo' },
                        multiple: false
                    }).on('select', function() {
                        var attachment = custom_uploader.state().get('selection').first().toJSON();
                        $('#' + target).val(attachment.url);
                    }).open();
                });
            });
        </script>

        <!-- VIP Prediction Section -->
        <div style="margin-top: 20px; padding: 15px; background: #111; border: 2px solid #333; border-radius: 8px;">
            <h4 style="color: #00ff6a; margin: 0 0 10px;">⚡ VIP Prediction Settings</h4>
            <label style="color: #fff;">
                <input type="checkbox" name="_crane_vip_prediction" value="1" <?php checked( $is_vip_pick, '1' ); ?> />
                <strong>Mark as VIP Prediction</strong> (will be emailed to VIP members)
            </label>
            <br><br>
            <label style="color: #ccc;"><strong>VIP Tip / Analysis:</strong></label><br>
            <textarea name="_crane_vip_tip" rows="3" style="width:100%; background: #222; color: #fff; border: 1px solid #444; padding: 8px; border-radius: 4px;" placeholder="e.g. Back home win — strong H2H record, key player returning from injury"><?php echo esc_textarea( $vip_tip ); ?></textarea>
            <small style="color: #666;">This tip will be included in the VIP email to subscribers.</small>
        </div>
        <?php
    }

    public function save_match_meta( $post_id ) {
        if ( ! isset( $_POST['crane_match_nonce'] ) || ! wp_verify_nonce( $_POST['crane_match_nonce'], 'crane_save_match_data' ) ) return;
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
        if ( ! current_user_can( 'edit_post', $post_id ) ) return;

        $fields = array( 
            'match_league' => 'sanitize_text_field', 
            'match_time'   => 'sanitize_text_field', 
            'team1_name'   => 'sanitize_text_field', 
            'team2_name'   => 'sanitize_text_field', 
            'match_odd1'   => 'sanitize_text_field', 
            'match_oddX'   => 'sanitize_text_field', 
            'match_odd2'   => 'sanitize_text_field', 
            'team1_logo'   => 'esc_url_raw', 
            'team2_logo'   => 'esc_url_raw',
            '_crane_vip_tip' => 'sanitize_textarea_field',
            '_crane_free_tip' => 'sanitize_text_field',
        );
        // VIP checkbox (checkbox not sent when unchecked)
        update_post_meta( $post_id, '_crane_vip_prediction', isset( $_POST['_crane_vip_prediction'] ) ? '1' : '0' );
        foreach ( $fields as $field => $sanitize_callback ) {
            if ( isset( $_POST[$field] ) ) {
                update_post_meta( $post_id, $field, $sanitize_callback( $_POST[$field] ) );
            }
        }
    }

    /**
     * Setup Pages on Activation
     */
    public static function crane_create_required_pages() {
        $pages = array(
            'locker-room' => array( 'title' => 'Locker Room', 'content' => '[crane_locker_room]', 'option' => 'crane_page_locker_room', 'template' => 'locker-room.php' ),
            'leaderboard' => array( 'title' => 'Leaderboard', 'content' => '[crane_leaderboard]', 'option' => 'crane_page_leaderboard', 'template' => 'page-leaderboard.php' ),
            'vip'         => array( 'title' => 'VIP Access', 'content' => '', 'option' => 'crane_page_vip', 'template' => 'page-vip.php' ),
            'dashboard'   => array( 'title' => 'User Dashboard', 'content' => '[crane_user_dashboard]', 'option' => 'crane_page_dashboard', 'template' => 'page-user-dashboard.php' ),
            'lost-password' => array( 'title' => 'Lost Password', 'content' => '[crane_lost_password_form]', 'option' => 'crane_page_lost_password', 'template' => 'page-lost-password.php' ),
            'testimonies' => array( 'title' => 'Testimonies', 'content' => '', 'option' => 'crane_page_testimonies', 'template' => 'page-testimonies.php' ),
            'commission'  => array( 'title' => 'Commission Dashboard', 'content' => '[crane_affiliate_dashboard]', 'option' => 'crane_page_commission', 'template' => 'page-commissions.php' ),
        );

        foreach ( $pages as $default_slug => $data ) {
            $page_id = (int) get_option( $data['option'] );
            $existing_by_id = ( $page_id ) ? get_post( $page_id ) : null;
            
            if ( $existing_by_id && $existing_by_id->post_type === 'page' && $existing_by_id->post_status !== 'trash' ) {
                // Page already set and valid
                $final_id = $page_id;
            } else {
                // Check for slug collision but prefer our own logic
                $existing_by_path = get_page_by_path( $default_slug );
                
                if ( ! $existing_by_path ) {
                    $final_id = wp_insert_post( array(
                        'post_title'   => $data['title'],
                        'post_content' => $data['content'],
                        'post_status'  => 'publish',
                        'post_type'    => 'page',
                        'post_name'    => $default_slug
                    ) );
                } else {
                    $final_id = $existing_by_path->ID;
                }
                update_option( $data['option'], $final_id );
            }

            // Automate Template Assignment
            if ( ! empty( $data['template'] ) ) {
                update_post_meta( $final_id, '_wp_page_template', $data['template'] );
            }
        }
        
        flush_rewrite_rules();
    }

    /**
     * Admin Notice for Registration Settings
     */
    public function registration_admin_notice() {
        if ( ! get_option( 'users_can_register' ) ) {
            ?>
            <div class="notice notice-warning is-dismissible">
                <p><?php _e( '<strong>Crane Bets Warning:</strong> New user registration is disabled in your WordPress settings. Please enable "Anyone can register" in Settings > General for the platform to function correctly.', 'crane-bets' ); ?></p>
            </div>
            <?php
        }
    }

    /**
     * Settings & Tools Menu Registration
     */
    public function register_crane_settings() {
        register_setting('crane_api_options', 'crane_api_football_key');
        register_setting('crane_api_options', 'crane_prediction_source');
        register_setting('crane_api_options', 'crane_odds_api_key');
        register_setting('crane_api_options', 'crane_api_newsdata');
        register_setting('crane_api_options', 'crane_paystack_secret_key');
        register_setting('crane_api_options', 'crane_vip_product_id');
        register_setting('crane_api_options', 'crane_purge_on_uninstall');
        register_setting('crane_api_options', 'crane_custom_rss_feeds');
        register_setting('crane_api_options', 'crane_news_import_category');
        Crane_Commission_Admin::register_settings();
    }

    public function add_crane_cron_intervals( $schedules ) {
        $schedules['crane_30min'] = array(
            'interval' => 1800,
            'display'  => 'Every 30 Minutes (Crane)'
        );
        $schedules['crane_2hours'] = array(
            'interval' => 7200,
            'display'  => 'Every 2 Hours (Crane)'
        );
        return $schedules;
    }

    public function crane_register_tools_menu() {
        add_menu_page(
            'Crane Bets',
            'Crane Bets',
            'manage_options',
            'crane-tools',
            array( 'Crane_Commission_Admin', 'render_commissions_page' ),
            'dashicons-chart-area',
            30
        );
        add_submenu_page(
            'crane-tools',
            'Commissions & Referrals',
            'Commissions & Referrals',
            'manage_options',
            'crane-tools', // Reusing slug makes it the default view
            array( 'Crane_Commission_Admin', 'render_commissions_page' )
        );
        add_submenu_page(
            'crane-tools',
            'General Tools',
            'General Tools',
            'manage_options',
            'crane-general-tools',
            array( $this, 'render_tools_page' )
        );
        add_submenu_page(
            'crane-tools',
            'API Settings',
            'API Settings',
            'manage_options',
            'crane-api-settings',
            array( $this, 'render_api_settings_page' )
        );
        add_submenu_page(
            'crane-tools',
            'User Predictions',
            'User Predictions',
            'manage_options',
            'crane-manage-predictions',
            array( 'Crane_User_Prediction_Service', 'render_admin_management_page' )
        );
    }

    public function render_api_settings_page() {
        ?>
        <div class="wrap">
            <h1>Crane Bets API Settings</h1>
            <p>Configure API integrations for live match data and automated predictions.</p>

            <?php if ( isset( $_GET['crane_msg'] ) ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php echo esc_html( $_GET['crane_msg'] ); ?></p></div>
            <?php endif; ?>

            <form method="post" action="options.php" style="background:#fff; padding:20px; border:1px solid #ccd0d4; max-width:700px; margin-top:20px;">
                <?php settings_fields( 'crane_api_options' ); ?>
                <?php do_settings_sections( 'crane_api_options' ); ?>
                <table class="form-table">
                    <tr valign="top">
                        <th scope="row">API-Football Key</th>
                        <td>
                            <input type="password" name="crane_api_football_key" value="<?php echo esc_attr( get_option('crane_api_football_key') ); ?>" style="width:100%" placeholder="Enter your API-Sports key" />
                            <p class="description">Get your free key at <a href="https://dashboard.api-football.com/register" target="_blank">dashboard.api-football.com</a> (100 requests/day free)</p>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Prediction Source Selection</th>
                        <td>
                            <?php 
                            $pred_source = get_option('crane_prediction_source', 'forebet_odds'); 
                            ?>
                            <select name="crane_prediction_source" style="width:100%">
                                <option value="api_football" <?php selected( $pred_source, 'api_football' ); ?>>API-Football Only (Free: 100 req/day — fixtures, live scores, logos)</option>
                                <option value="forebet" <?php selected( $pred_source, 'forebet' ); ?>>Forebet Only (Free — mathematical predictions, no key needed)</option>
                                <option value="odds_api" <?php selected( $pred_source, 'odds_api' ); ?>>The Odds API Only (Free key required — real bookmaker odds)</option>
                                <option value="forebet_odds" <?php selected( $pred_source, 'forebet_odds' ); ?>>Forebet + The Odds API (Both Free — bulk predictions + odds)</option>
                                <option value="all" <?php selected( $pred_source, 'all' ); ?>>⭐ All Sources — Recommended (API-Football + Forebet + Odds API)</option>
                            </select>
                            <p class="description">Select where predictions are imported from. <strong>"All Sources" is recommended</strong> — API-Football (free: fixtures, live scores, team logos, 5 prediction tips/cycle) + Forebet (bulk mathematical predictions) + Odds API (bookmaker odds). Combined quota stays well within all free limits.</p>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">The Odds API Key</th>
                        <td>
                            <input type="password" name="crane_odds_api_key" value="<?php echo esc_attr( get_option('crane_odds_api_key') ); ?>" style="width:100%" placeholder="Enter your The Odds API key" />
                            <p class="description">Required if using The Odds API. Get a free key at <a href="https://the-odds-api.com" target="_blank">the-odds-api.com</a>.</p>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">NewsData.io API Key (News)</th>
                        <td>
                            <input type="password" name="crane_api_newsdata" value="<?php echo esc_attr( get_option('crane_api_newsdata') ); ?>" style="width:100%" placeholder="Enter NewsData.io Token" />
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Custom RSS Feeds</th>
                        <td>
                            <textarea name="crane_custom_rss_feeds" rows="5" style="width:100%" placeholder="Enter one RSS URL per line, e.g.&#10;http://feeds.bbci.co.uk/sport/football/rss.xml&#10;https://news.google.com/rss/search?q=sports"><?php echo esc_textarea( get_option('crane_custom_rss_feeds') ); ?></textarea>
                            <p class="description">Enter one feed URL per line. If left empty, the system automatically falls back to BBC Sport Football RSS.</p>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Target News Category</th>
                        <td>
                            <?php 
                            $selected_cat = get_option('crane_news_import_category', '' );
                            $categories = get_categories( array( 'hide_empty' => 0 ) );
                            ?>
                            <select name="crane_news_import_category" style="width:100%">
                                <option value="">-- Default Category (Uncategorized) --</option>
                                <?php foreach ( $categories as $cat ) : ?>
                                    <option value="<?php echo esc_attr( $cat->slug ); ?>" <?php selected( $selected_cat, $cat->slug ); ?>><?php echo esc_html( $cat->name ); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description">Select the category all newly scraped articles will be automatically assigned to.</p>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Paystack Secret Key</th>
                        <td>
                            <input type="password" name="crane_paystack_secret_key" value="<?php echo esc_attr( get_option('crane_paystack_secret_key') ); ?>" style="width:100%" placeholder="sk_live_xxxxxxx or sk_test_xxxxxxx" />
                            <p class="description">Required for commission payouts. Get from <a href="https://dashboard.paystack.com/#/settings/developers" target="_blank">Paystack Dashboard</a></p>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">VIP Inner Circle Mapping</th>
                        <td>
                            <?php 
                            $selected_product = (int) get_option('crane_vip_product_id', 0 );
                            $products = get_posts( array( 'post_type' => 'product', 'posts_per_page' => -1 ) );
                            ?>
                            <select name="crane_vip_product_id" style="width:100%">
                                <option value="0">-- Select VIP Product --</option>
                                <?php foreach ( $products as $p ) : ?>
                                    <option value="<?php echo $p->ID; ?>" <?php selected( $selected_product, $p->ID ); ?>><?php echo esc_html( $p->post_title ); ?> (ID: <?php echo $p->ID; ?>)</option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description">Select the WooCommerce product that users purchase to gain VIP status.</p>
                        </td>
                    </tr>
                </table>
                <?php submit_button('Save API Keys'); ?>
            </form>

            <div class="card" style="padding:20px; max-width:700px; margin-top:20px;">
                <h2>Prediction Sync</h2>
                <p>Sync status: Next automatic sync in <strong><?php
                    $next = wp_next_scheduled( 'crane_sync_predictions_cron_v2' );
                    echo $next ? human_time_diff( time(), $next ) : 'Not scheduled';
                ?></strong> &nbsp;<em style="color:#888;font-weight:normal;"><?php
                    if ( $next ) {
                        $tz = new DateTimeZone( 'Africa/Lagos' );
                        $dt = new DateTime( '@' . $next );
                        $dt->setTimezone( $tz );
                        echo '(' . $dt->format( 'M j, g:i A' ) . ' WAT)';
                    }
                ?></em></p>
                <p>API Key status: <strong><?php echo get_option('crane_api_football_key') ? 'Set' : 'Not set'; ?></strong></p>
                <form action="<?php echo admin_url('admin-post.php'); ?>" method="post" style="margin-top:10px;">
                    <input type="hidden" name="action" value="crane_manual_sync">
                    <?php wp_nonce_field( 'crane_manual_sync' ); ?>
                    <button type="submit" class="button button-primary">Sync Predictions Now</button>
                </form>
            </div>

            <div class="card" style="padding:20px; max-width:700px; margin-top:20px;">
                <h2>Free Predictions Scraper Sync</h2>
                <p>Source selected: <strong><?php 
                    $curr_src = get_option('crane_prediction_source', 'forebet_odds');
                    $src_labels = [
                        'api_football' => 'API-Football Only (Premium)',
                        'forebet' => 'Forebet Only (Free)',
                        'odds_api' => 'The Odds API Only (Free)',
                        'forebet_odds' => 'Forebet + The Odds API (Free)',
                        'all' => 'All Sources (API-Football + Free Sources)',
                    ];
                    echo esc_html( $src_labels[$curr_src] ?? $curr_src );
                ?></strong></p>
                <p>The Odds API Key status: <strong><?php echo get_option('crane_odds_api_key') ? 'Set' : 'Not set'; ?></strong></p>
                <?php if ( isset( $_GET['free_imported'] ) ) : 
                    $total_imp    = intval( $_GET['free_imported'] );
                    $forebet_imp  = intval( $_GET['forebet_count'] ?? 0 );
                    $odds_imp     = intval( $_GET['odds_count'] ?? 0 );
                    $apif_imp     = intval( $_GET['apif_count'] ?? 0 );
                ?>
                    <div class="notice notice-success inline" style="padding: 12px 16px;">
                        <p style="margin:0 0 6px;"><strong>✅ <?php echo $total_imp; ?> predictions imported successfully.</strong></p>
                        <ul style="margin:4px 0 0 16px; list-style:disc; font-size:12px; color:#1d2327;">
                            <?php if ( $forebet_imp > 0 ) : ?>
                                <li>📊 <strong>Forebet</strong> (mathematical predictions): <?php echo $forebet_imp; ?></li>
                            <?php endif; ?>
                            <?php if ( $odds_imp > 0 ) : ?>
                                <li>📈 <strong>The Odds API</strong> (bookmaker odds): <?php echo $odds_imp; ?></li>
                            <?php endif; ?>
                            <?php if ( $apif_imp > 0 ) : ?>
                                <li>⚡ <strong>API-Football</strong> (live fixtures): <?php echo $apif_imp; ?></li>
                            <?php endif; ?>
                            <?php if ( $forebet_imp === 0 && $odds_imp === 0 && $apif_imp === 0 ) : ?>
                                <li>Source details unavailable (all cached or skipped).</li>
                            <?php endif; ?>
                        </ul>
                    </div>
                <?php endif; ?>
                <form action="<?php echo admin_url('admin-post.php'); ?>" method="post" style="margin-top:10px;">
                    <input type="hidden" name="action" value="crane_manual_scraper_fetch">
                    <?php wp_nonce_field( 'crane_free_scraper_fetch' ); ?>
                    <button type="submit" class="button button-primary">Scrape & Sync Free Predictions Now</button>
                </form>
                <form action="<?php echo admin_url('admin-post.php'); ?>" method="post" style="margin-top:10px;">
                    <input type="hidden" name="action" value="crane_clear_logo_cache">
                    <?php wp_nonce_field( 'crane_clear_logo_cache' ); ?>
                    <button type="submit" class="button" onclick="return confirm('This will delete all cached team logos. They will be re-fetched correctly on next sync. Continue?')">&#x1F5D1; Clear Logo Cache</button>
                    <?php if ( isset( $_GET['logo_cache_cleared'] ) ) : ?>
                        <span style="color:green;margin-left:10px;">&#10003; Logo cache cleared (<?php echo intval( $_GET['cleared_count'] ?? 0 ); ?> entries removed) &mdash; logos will re-fetch correctly on next import.</span>
                    <?php endif; ?>
                </form>
                <form action="<?php echo admin_url('admin-post.php'); ?>" method="post" style="margin-top:10px;">
                    <input type="hidden" name="action" value="crane_purge_old_predictions">
                    <?php wp_nonce_field( 'crane_purge_old_predictions' ); ?>
                    <button type="submit" class="button button-link-delete" style="color:#d63638;" onclick="return confirm('This will permanently delete all past predictions (match_date before today). Continue?')">&#x1F5D1; Purge Past Predictions</button>
                    <?php if ( isset( $_GET['old_preds_purged'] ) ) : ?>
                        <span style="color:green;margin-left:10px;">&#10003; Deleted <?php echo intval( $_GET['purged_count'] ?? 0 ); ?> past predictions. Front-page cache also cleared.</span>
                    <?php endif; ?>
                </form>
                <?php
                // Use the scraper's own methods so admin panel always matches runtime behaviour
                $is_os         = class_exists( 'Crane_Free_Prediction_Scraper' )
                    ? Crane_Free_Prediction_Scraper::is_off_season_public()
                    : false;
                $is_tournament = class_exists( 'Crane_Free_Prediction_Scraper' )
                    ? Crane_Free_Prediction_Scraper::is_major_tournament_active()
                    : false;

                // Fallback inline calc if class not yet loaded
                if ( ! class_exists( 'Crane_Free_Prediction_Scraper' ) ) {
                    $tz_os = new DateTimeZone( 'Africa/Lagos' );
                    $now_os = new DateTime( 'now', $tz_os );
                    $m_os = (int) $now_os->format( 'n' );
                    $d_os = (int) $now_os->format( 'j' );
                    $y_os = (int) $now_os->format( 'Y' );
                    $is_os = ( $m_os === 6 || $m_os === 7 ) || ( $m_os === 5 && $d_os >= 16 ) || ( $m_os === 8 && $d_os <= 14 );
                    $is_tournament = false;
                    $wc_years = [2026, 2030, 2034, 2038];
                    $euro_years = [2024, 2028, 2032, 2036];
                    if ( in_array( $y_os, $wc_years, true ) && ( ( $m_os === 6 && $d_os >= 11 ) || ( $m_os === 7 && $d_os <= 19 ) ) ) {
                        $is_tournament = "FIFA World Cup $y_os";
                    } elseif ( in_array( $y_os, $euro_years, true ) && ( ( $m_os === 6 && $d_os >= 14 ) || ( $m_os === 7 && $d_os <= 14 ) ) ) {
                        $is_tournament = "UEFA Euro $y_os";
                    } elseif ( $y_os % 2 === 0 && $m_os === 6 ) {
                        $is_tournament = "UEFA Nations League Finals $y_os";
                    }
                }
                ?>
                <p style="margin-top:12px; font-size:12px; color:<?php echo $is_os && ! $is_tournament ? '#d63638' : '#00a32a'; ?>;">
                    <strong>European Club League Status:</strong>
                    <?php echo $is_os ? '&#x1F534; Club Off-Season' : '&#x1F7E2; Club Leagues Active'; ?>
                    &nbsp;&mdash;&nbsp;
                    <?php
                    $tz_disp = new DateTimeZone( 'Africa/Lagos' );
                    $now_disp = new DateTime( 'now', $tz_disp );
                    echo esc_html( $now_disp->format( 'M j, Y' ) ) . ' WAT';
                    ?>
                </p>
                <p style="margin-top:6px; font-size:12px; color:<?php echo $is_tournament ? '#00a32a' : '#888'; ?>;">
                    <strong>Major Tournament Status:</strong>
                    <?php if ( $is_tournament ) : ?>
                        &#x1F3C6; <strong>ACTIVE</strong> &mdash; <?php echo esc_html( $is_tournament ); ?> running. Predictions expanded to 40/cycle, international pages fetched.
                    <?php else : ?>
                        &#x26AA; No major international tournament currently active.
                    <?php endif; ?>
                </p>
            </div>


            <div class="card" style="padding:20px; max-width:700px; margin-top:20px;">
                <h2>VIP Email Blast</h2>
                <?php
                    $next_email = wp_next_scheduled( 'crane_vip_daily_email_cron' );
                    $vip_count_query = new WP_User_Query( array( 'meta_key' => 'crane_is_vip', 'meta_value' => '1', 'count_total' => true ) );
                ?>
                <p>Next scheduled email: <strong><?php
                    if ( $next_email ) {
                        $tz_e = new DateTimeZone( 'Africa/Lagos' );
                        $dt_e = new DateTime( '@' . $next_email );
                        $dt_e->setTimezone( $tz_e );
                        echo esc_html( $dt_e->format( 'M j, g:i A' ) . ' WAT' );
                    } else { echo 'Not scheduled'; }
                ?></strong></p>
                <p>VIP members: <strong><?php echo $vip_count_query->get_total(); ?></strong></p>
                <?php if ( absint( isset($_GET['vip_email_sent']) ? $_GET['vip_email_sent'] : 0 ) === 1 ) : ?>
                    <div class="notice notice-success inline"><p> VIP email sent successfully!</p></div>
                <?php endif; ?>
                <form action="<?php echo admin_url('admin-post.php'); ?>" method="post" style="margin-top:10px; display:inline-block;">
                    <input type="hidden" name="action" value="crane_manual_vip_email">
                    <?php wp_nonce_field( 'crane_manual_vip_email' ); ?>
                    <button type="submit" class="button button-primary" onclick="return confirm('Send VIP predictions email to all VIP members now?')">Send VIP Email Now</button>
                </form>
                <form action="<?php echo admin_url('admin-post.php'); ?>" method="post" style="margin-top:10px; display:inline-block; margin-left:10px;">
                    <input type="hidden" name="action" value="crane_test_vip">
                    <?php wp_nonce_field( 'crane_test_vip' ); ?>
                    <button type="submit" class="button" onclick="return confirm('Award yourself Premium VIP status to test?')">Test VIP Purchase Hook</button>
                </form>
                <p class="description" style="margin-top:10px;">Sends all predictions marked as "VIP Prediction" (from last 24hrs) to every VIP user.</p>
            </div>

            <div class="card" style="padding:20px; max-width:700px; margin-top:20px;">
                <h2>Auto-Fetch Sports News</h2>
                <?php
                    $next_news = wp_next_scheduled( 'crane_fetch_news_cron' );
                ?>
                <p>Next automated fetch: <strong><?php
                    if ( $next_news ) {
                        $tz_n = new DateTimeZone( 'Africa/Lagos' );
                        $dt_n = new DateTime( '@' . $next_news );
                        $dt_n->setTimezone( $tz_n );
                        echo esc_html( $dt_n->format( 'M j, g:i A' ) . ' WAT' );
                    } else { echo 'Not scheduled'; }
                ?></strong></p>
                
                <?php if ( isset( $_GET['news_imported'] ) ) : ?>
                    <div class="notice notice-success inline"><p> Imported <?php echo intval($_GET['news_imported']); ?> new sports articles!</p></div>
                <?php endif; ?>
                
                <form action="<?php echo admin_url('admin-post.php'); ?>" method="post" style="margin-top:10px;">
                    <input type="hidden" name="action" value="crane_manual_fetch_news">
                    <?php wp_nonce_field( 'crane_manual_fetch_news' ); ?>
                    <button type="submit" class="button button-primary" onclick="return confirm('Fetch latest football news from BBC Sport now?')">Fetch News Now</button>
                </form>
                <p class="description" style="margin-top:10px;">Fetches the latest sports news using NewsData.io (BBC RSS fallback). Publishes them to the News page immediately. Runs automatically every hour.</p>
            </div>
        </div>
        <?php
    }

    public function render_tools_page() {
        ?>
        <div class="wrap">
            <h1>Crane Bets Tools</h1>
            <div class="card" style="padding: 20px; max-width: 500px; margin-top: 20px;">
                <h2>Demo Data Management</h2>
                <p>Import or delete simulated users and content to test the platform vibe.</p>
                <div style="display: flex; gap: 10px;">
                    <form action="<?php echo admin_url('admin-post.php'); ?>" method="post">
                        <input type="hidden" name="action" value="crane_import_demo">
                        <?php wp_nonce_field( 'crane_demo_action' ); ?>
                        <button type="submit" class="button button-primary">Import Demo Data</button>
                    </form>
                    <form action="<?php echo admin_url('admin-post.php'); ?>" method="post">
                        <input type="hidden" name="action" value="crane_delete_demo">
                        <?php wp_nonce_field( 'crane_demo_action' ); ?>
                        <button type="submit" class="button button-link-delete" style="color: #d63638;">Delete Demo Data</button>
                    </form>
                </div>
            </div>

            <div class="card" style="padding: 20px; max-width: 500px; margin-top: 20px;">
                <h2>Uninstallation Policy</h2>
                <p>Configure the "Full-System" uninstallation strictness.</p>
                <form method="post" action="options.php">
                    <?php settings_fields( 'crane_api_options' ); ?>
                    <table class="form-table">
                        <tr valign="top">
                            <th scope="row">Purge Demo Content on Deletion?</th>
                            <td>
                                <label><input type="radio" name="crane_purge_on_uninstall" value="yes" <?php checked(get_option('crane_purge_on_uninstall', 'yes'), 'yes'); ?>> Yes, Purge Everything</label><br>
                                <label><input type="radio" name="crane_purge_on_uninstall" value="no" <?php checked(get_option('crane_purge_on_uninstall'), 'no'); ?>> No, (Wait, <em>Still</em> Purge Everything)</label>
                            </td>
                        </tr>
                    </table>
                    <?php submit_button('Save Policy'); ?>
                </form>
            </div>
            <div class="card" style="padding: 20px; max-width: 500px; margin-top: 20px;">
                <h2>Manual VIP Management</h2>
                <p>Assign VIP standing or manually update a user's tracking hours.</p>
                <form action="<?php echo admin_url('admin-post.php'); ?>" method="post">
                    <input type="hidden" name="action" value="crane_manual_vip_assignment">
                    <?php wp_nonce_field( 'crane_manual_vip_action' ); ?>
                    
                    <table class="form-table">
                        <tr valign="top">
                            <th scope="row">User Email or Username</th>
                            <td><input type="text" name="vip_user_ident" style="width:100%" required placeholder="e.g. user@example.com" /></td>
                        </tr>
                        <tr valign="top">
                            <th scope="row">Action</th>
                            <td>
                                <select name="vip_action" style="width:100%" required>
                                    <option value="award_vip">Award VIP Elite (Purchase Equivalent)</option>
                                    <option value="add_hours">Add Tracking Hours (Timer)</option>
                                </select>
                            </td>
                        </tr>
                        <tr valign="top">
                            <th scope="row">Value (if adding hours or days)</th>
                            <td>
                                <input type="number" name="vip_value" style="width:100%" min="1" placeholder="e.g. 50 (Hours or Days)" required />
                                <p class="description">If Awarding VIP: Number of days valid. If Adding Hours: Amount of tracking hours to add.</p>
                            </td>
                        </tr>
                    </table>
                    <?php submit_button('Process VIP Action'); ?>
                </form>
            </div>

            <?php if ( isset( $_GET['crane_msg'] ) ) : ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php echo esc_html( $_GET['crane_msg'] ); ?></p>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Import Demo Data Logic
     */
    public function crane_import_demo_data() {
        check_admin_referer( 'crane_demo_action' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );

        // 1. Create Demo Users
        $users = array(
            'tipster_pro'  => array( 'wins' => 18, 'rank' => 'Crane God' ),
            'vibe_master'  => array( 'wins' => 10, 'rank' => 'Vibe Master' ),
            'rookie_user'  => array( 'wins' => 2,  'rank' => 'Novice' ),
        );

        foreach ( $users as $name => $stats ) {
            if ( ! username_exists( $name ) ) {
                $user_id = wp_create_user( $name, 'demo123!', $name . '@demo.com' );
                update_user_meta( $user_id, 'crane_wins', $stats['wins'] );
                update_user_meta( $user_id, 'crane_accuracy_level', $stats['rank'] );
                update_user_meta( $user_id, 'crane_demo', 1 );
            }
        }

        // 2. Create Demo Predictions
        for ( $i = 1; $i <= 5; $i++ ) {
            $post_id = wp_insert_post( array(
                'post_title'   => 'Demo Match #' . $i,
                'post_type'    => 'crane_prediction',
                'post_status'  => 'publish',
                'post_content' => 'High-confidence prediction for test purposes.',
            ) );
            update_post_meta( $post_id, 'crane_demo', 1 );
            update_post_meta( $post_id, 'crane_odds', '2.' . $i . '0' );
            update_post_meta( $post_id, 'crane_result', $i % 2 == 0 ? 'won' : 'lost' );
        }

        // 3. Create Demo Locker Posts
        for ( $j = 1; $j <= 3; $j++ ) {
            $post_id = wp_insert_post( array(
                'post_title'   => 'Epic Win #' . $j,
                'post_type'    => 'crane_locker_post',
                'post_status'  => 'publish',
                'post_content' => 'Just hit a big one! Crane Bets never misses.',
            ) );
            update_post_meta( $post_id, 'crane_demo', 1 );
        }

        wp_redirect( admin_url( 'tools.php?page=crane-tools&crane_msg=Demo data imported successfully' ) );
        exit;
    }

    /**
     * Delete Demo Data Logic
     */
    public function crane_delete_demo_data() {
        check_admin_referer( 'crane_demo_action' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );

        // Delete Posts
        $posts = new WP_Query( array(
            'post_type'      => array( 'crane_prediction', 'crane_locker_post' ),
            'meta_key'       => 'crane_demo',
            'meta_value'     => 1,
            'posts_per_page' => -1,
            'fields'         => 'ids'
        ) );
        foreach ( $posts->posts as $pid ) {
            wp_delete_post( $pid, true );
        }

        // Delete Users
        $users = new WP_User_Query( array(
            'meta_key'   => 'crane_demo',
            'meta_value' => 1,
            'fields'     => 'ID'
        ) );
        foreach ( $users->get_results() as $uid ) {
            require_once( ABSPATH . 'wp-admin/includes/user.php' );
            wp_delete_user( $uid );
        }

        wp_redirect( admin_url( 'tools.php?page=crane-tools&crane_msg=Demo data removed successfully' ) );
        exit;
    }

    /**
     * Clear all cached team logos (crane_logo_* options)
     * Useful after bad logo matches (e.g. PSG resolved to Arsenal badge)
     */
    public function handle_clear_logo_cache() {
        check_admin_referer( 'crane_clear_logo_cache' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );

        global $wpdb;
        $deleted = $wpdb->query(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'crane\_logo\_%'"
        );

        // Fetch and update all existing prediction posts to use the corrected logo URLs
        $posts = new WP_Query( array(
            'post_type'      => 'crane_prediction',
            'post_status'    => 'any',
            'posts_per_page' => -1,
            'fields'         => 'ids'
        ) );

        if ( ! empty( $posts->posts ) && class_exists( 'Crane_Prediction_API_Service' ) ) {
            foreach ( $posts->posts as $pid ) {
                $t1_name = get_post_meta( $pid, 'team1_name', true );
                $t2_name = get_post_meta( $pid, 'team2_name', true );

                if ( ! empty( $t1_name ) ) {
                    $t1_logo = Crane_Prediction_API_Service::get_team_logo( $t1_name );
                    update_post_meta( $pid, 'team1_logo', $t1_logo );
                }
                if ( ! empty( $t2_name ) ) {
                    $t2_logo = Crane_Prediction_API_Service::get_team_logo( $t2_name );
                    update_post_meta( $pid, 'team2_logo', $t2_logo );
                }
            }
        }

        $redirect = admin_url( 'admin.php?page=crane-api-settings&logo_cache_cleared=1&cleared_count=' . intval( $deleted ) );
        wp_redirect( $redirect );
        exit;
    }

    /**
     * Manually purge all past crane_prediction posts (match_date < today WAT)
     */
    public function handle_purge_old_predictions() {
        check_admin_referer( 'crane_purge_old_predictions' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );

        $deleted = 0;
        if ( class_exists( 'Crane_Free_Prediction_Scraper' ) ) {
            $deleted += Crane_Free_Prediction_Scraper::cleanup_old_predictions();
        }
        if ( class_exists( 'Crane_Prediction_API_Service' ) ) {
            // Also trigger the API service cleanup for any API-football sourced posts
            Crane_Prediction_API_Service::cleanup_old_predictions();
        }

        // Clear front-page transients so stale HTML is gone immediately
        delete_transient( 'crane_front_matches_html' );
        delete_transient( 'crane_front_locker_preview' );
        delete_transient( 'crane_front_matches_pool' );

        if ( class_exists( 'Crane_Free_Prediction_Scraper' ) ) {
            Crane_Free_Prediction_Scraper::purge_page_caches();
        }

        wp_redirect( admin_url( 'admin.php?page=crane-api-settings&old_preds_purged=1&purged_count=' . intval( $deleted ) ) );
        exit;
    }

    /**
     * Handle Manual VIP Assignments from Settings Dashboard
     */
    public function handle_manual_vip_assignment() {
        check_admin_referer( 'crane_manual_vip_action' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );

        $ident = sanitize_text_field( wp_unslash( $_POST['vip_user_ident'] ?? '' ) );
        $action = sanitize_text_field( wp_unslash( $_POST['vip_action'] ?? '' ) );
        $value = absint( $_POST['vip_value'] ?? 0 );

        if ( empty($ident) || empty($action) || $value <= 0 ) {
            wp_redirect( admin_url( 'admin.php?page=crane-general-tools&crane_msg=' . urlencode('Invalid inputs.') ) );
            exit;
        }

        // Hardened user lookup
        $user = is_email( $ident ) 
            ? get_user_by( 'email', sanitize_email( $ident ) ) 
            : get_user_by( 'login', $ident );

        if ( ! $user ) {
            wp_redirect( admin_url( 'admin.php?page=crane-general-tools&crane_msg=' . urlencode('User not found.') ) );
            exit;
        }

        if ( $action === 'award_vip' ) {
            $now = current_time( 'timestamp' );
            $current_expiry = (int) get_user_meta( $user->ID, 'crane_vip_expiry', true );
            $new_expiry = ( $current_expiry && $current_expiry > $now ) 
                ? $current_expiry + ( $value * DAY_IN_SECONDS ) 
                : $now + ( $value * DAY_IN_SECONDS );
                
            update_user_meta( $user->ID, 'crane_is_vip', '1' );
            update_user_meta( $user->ID, 'crane_vip_source', 'purchase' );
            update_user_meta( $user->ID, 'crane_vip_expiry', $new_expiry );
            
            if ( class_exists('Crane_VIP_Service') ) {
                Crane_VIP_Service::send_vip_welcome_email( $user->ID, 'pro' );
            }
            $msg = "Awarded VIP to {$user->user_email} extended by {$value} days.";
        } else if ( $action === 'add_hours' ) {
            $current_seconds = absint( get_user_meta( $user->ID, 'crane_vip_seconds', true ) );
            $new_seconds = $current_seconds + ( $value * 3600 );
            
            update_user_meta( $user->ID, 'crane_vip_seconds', $new_seconds );
            // Synchronize legacy timer meta
            update_user_meta( $user->ID, 'crane_vip_timer', floor( $new_seconds / 3600 ) );
            
            // Re-trigger check
            if ( class_exists('Crane_VIP_Service') ) {
                if ( $new_seconds >= Crane_VIP_Service::VIP_THRESHOLD_SECONDS ) {
                    update_user_meta( $user->ID, 'crane_is_vip', '1' );
                    update_user_meta( $user->ID, 'crane_vip_source', 'timer' );
                    
                    $current_expiry = get_user_meta( $user->ID, 'crane_vip_expiry', true );
                    if ( ! $current_expiry ) {
                        update_user_meta( $user->ID, 'crane_vip_expiry', current_time('timestamp') + ( 30 * DAY_IN_SECONDS ) );
                    }
                }
            }
            $msg = "Added {$value} hours to {$user->user_email}.";
        }

        wp_redirect( admin_url( 'admin.php?page=crane-general-tools&crane_msg=' . urlencode($msg) ) );
        exit;
    }

    /**
     * Assets & Utilities
     */
    public function sync_matches() { /* Match sync placeholder */ }
    public function sync_news() { /* News sync placeholder */ }

    public function enqueue_crane_core_assets() {
        wp_enqueue_script( 'crane-core-logic', plugins_url( 'assets/js/crane-core.js', __FILE__ ), array(), '1.0.0', true );
        $public_urls = array( home_url('/') );
        $public_urls[] = $this->get_crane_url( 'news' );
        $public_urls[] = home_url( '/news' ); // Explicit fallback
        $public_urls[] = $this->get_crane_url( 'leaderboard' );
        $public_urls[] = home_url( '/leaderboard' ); // Explicit fallback
        $public_urls[] = $this->get_crane_url( 'testimonies' );
        $public_urls[] = home_url( '/testimonies' ); // Explicit fallback
        $public_urls[] = $this->get_crane_url( 'locker-room' );
        $public_urls[] = home_url( '/locker-room' ); // Explicit fallback
        $public_urls[] = home_url( '/locker' ); // In case user uses /locker
        $public_urls[] = $this->get_crane_url( 'shop' );
        $public_urls[] = get_permalink( get_option( 'crane_page_lost_password' ) );
        $public_urls[] = home_url( '/lost-password' ); // Explicit fallback
        
        // Whitelist WooCommerce basic checkout flow for unregistered users
        if ( class_exists( 'WooCommerce' ) ) {
            $public_urls[] = get_permalink( wc_get_page_id( 'cart' ) );
            $public_urls[] = get_permalink( wc_get_page_id( 'checkout' ) );
        }

        wp_localize_script( 'crane-core-logic', 'craneData', array(
            'ajax_url'    => admin_url( 'admin-ajax.php' ),
            'nonce'       => wp_create_nonce( 'crane_security_nonce' ),
            'public_urls' => array_values( array_map( 'esc_url', array_filter( $public_urls ) ) ),
            'is_logged_in' => is_user_logged_in(),
            'is_verified' => ( is_user_logged_in() && ( current_user_can( 'manage_options' ) || get_user_meta( get_current_user_id(), 'crane_is_verified', true ) === '1' ) )
        ) );
    }

    public function force_crane_page_templates( $template ) {
        if ( ! is_page() ) return $template;

        $page_id = get_the_ID();
        $pages = array(
            'locker-room.php'         => get_option( 'crane_page_locker_room' ),
            'page-leaderboard.php'    => get_option( 'crane_page_leaderboard' ),
            'page-user-dashboard.php' => get_option( 'crane_page_dashboard' ),
            'page-lost-password.php'  => get_option( 'crane_page_lost_password' ),
            'page-vip.php'            => get_option( 'crane_page_vip' ),
            'page-testimonies.php'    => get_option( 'crane_page_testimonies' ),
        );

        foreach ( $pages as $tpl => $pid ) {
            if ( (int)$pid === $page_id ) {
                $new_template = locate_template( "template-parts/{$tpl}" );
                if ( ! $new_template ) $new_template = locate_template( $tpl );
                if ( ! $new_template ) {
                    $fallback = plugin_dir_path( __FILE__ ) . 'templates/' . $tpl;
                    if ( file_exists( $fallback ) ) $new_template = $fallback;
                }
                if ( $new_template ) return $new_template;
            }
        }
        return $template;
    }
}
} // End existence check

// Initialize Singleton Global
$crane_bets_core = Crane_Bets_Core::get_instance();

register_activation_hook( __FILE__, 'crane_bets_core_activation' );
function crane_bets_core_activation() {
    // 0. Emergency Catch for Restrictive DB Buffers (Issue #1a Fix)
    if ( ! function_exists( 'wp_insert_post' ) ) {
        require_once( ABSPATH . 'wp-admin/includes/post.php' );
    }

    try {
        // 1. Silent Force Table Installation
        Crane_Bets_Core::install_custom_tables();
    } catch ( Exception $e ) {
        // Idempotent Failure: Log it, but don't stop activation
        error_log( 'Crane Bets Activation Table Hook: ' . $e->getMessage() );
    }

    // 1. Create News Page and Assign
    $news_page = get_page_by_path( 'news' );
    if ( ! $news_page ) {
        $news_page_id = wp_insert_post( array(
            'post_title'   => 'News',
            'post_type'    => 'page',
            'post_status'  => 'publish'
        ) );
    } else {
        $news_page_id = $news_page->ID;
    }
    update_option( 'page_for_posts', $news_page_id );

    // 2. Setup Default Menu
    $menu_name = 'Crane Bets Main Menu';
    $menu_exists = wp_get_nav_menu_object( $menu_name );
    $core = Crane_Bets_Core::get_instance();

    if ( ! $menu_exists ) {
        $menu_id = wp_create_nav_menu( $menu_name );
        $menu_items = array(
            array( 'title' => 'Home', 'url' => home_url('/') ),
            array( 'title' => 'News', 'url' => $core->get_crane_url('news') ),
            array( 'title' => 'VIP ⚡', 'url' => $core->get_crane_url('vip') ),
            array( 'title' => 'Locker Room', 'url' => $core->get_crane_url('locker-room') ),
            array( 'title' => 'Shop', 'url' => $core->get_crane_url('shop') ),
            array( 'title' => 'Testimonies', 'url' => $core->get_crane_url('testimonies') ),
            array( 'title' => 'Dashboard', 'url' => $core->get_crane_url('dashboard') ),
            array( 'title' => 'Leaderboard', 'url' => $core->get_crane_url('leaderboard') ),
        );
        foreach ( $menu_items as $item ) {
            wp_update_nav_menu_item( $menu_id, 0, array(
                'menu-item-title'   => $item['title'],
                'menu-item-url'     => $item['url'],
                'menu-item-status'  => 'publish',
                'menu-item-type'    => 'custom',
            ) );
        }

        // Re-assign menu to theme locations immediately for active theme
        $theme_slug = get_template();
        $mods = get_option( "theme_mods_{$theme_slug}", array() );
        if ( ! is_array( $mods ) ) { $mods = array(); }
        
        $locations = isset( $mods['nav_menu_locations'] ) ? $mods['nav_menu_locations'] : array();
        $locations['primary'] = $menu_id;
        $locations['mobile']  = $menu_id;
        $mods['nav_menu_locations'] = $locations;
        
        update_option( 'theme_mods_crane-bets-theme', $mods );
    }

    $core->register_post_types();
    // Activation sequence cleanup: register_post_types is already hooked to init in the constructor.
    Crane_Bets_Core::crane_create_required_pages();

    // 3. Create VIP Product if WooCommerce is active
    if ( class_exists( 'WooCommerce' ) ) {
        $vip_product_id = get_option( 'crane_vip_product_id', 0 );
        $existing_product = $vip_product_id ? get_post( $vip_product_id ) : null;

        if ( ! $existing_product || $existing_product->post_status === 'trash' ) {
            $product_id = wp_insert_post( array(
                'post_title'   => 'VIP Inner Circle Access',
                'post_content' => 'Unlock daily premium predictions delivered straight to your email. VIP members get exclusive pro tips, early access to odds, and private sector support. One-time payment, monthly access.',
                'post_status'  => 'publish',
                'post_type'    => 'product',
                'post_name'    => 'vip-inner-circle-access',
            ) );
            if ( is_wp_error( $product_id ) ) {
                error_log( 'Crane Bets: Failed to create VIP product — ' . $product_id->get_error_message() );
                return; // Bail activation gracefully, don't crash
            }
            update_post_meta( $product_id, '_price', '75000' );
            update_post_meta( $product_id, '_regular_price', '75000' );
            update_post_meta( $product_id, '_sku', 'CRANE-VIP-INNER' );
            update_post_meta( $product_id, '_virtual', 'yes' );
            update_post_meta( $product_id, '_sold_individually', 'yes' );
            update_post_meta( $product_id, '_manage_stock', 'no' );
            update_post_meta( $product_id, '_stock_status', 'instock' );
            wp_set_object_terms( $product_id, 'simple', 'product_type' );
            update_post_meta( $product_id, '_visibility', 'visible' );
            update_post_meta( $product_id, '_crane_vip_tier', 'pro' ); // For future tiering
            update_option( 'crane_vip_product_id', $product_id );
        }
    }

    // Schedule CRON jobs — moved here from constructor to avoid race condition with custom intervals
    if ( ! wp_next_scheduled( 'crane_sync_predictions_cron_v2' ) ) {
        wp_schedule_event( time(), 'crane_2hours', 'crane_sync_predictions_cron_v2' );
    }
    if ( ! wp_next_scheduled( 'crane_sync_odds_cron' ) ) {
        wp_schedule_event( time(), 'twicedaily', 'crane_sync_odds_cron' );
    }
    if ( ! wp_next_scheduled( 'crane_cleanup_predictions_cron' ) ) {
        wp_schedule_event( time(), 'daily', 'crane_cleanup_predictions_cron' );
    }
    if ( ! wp_next_scheduled( 'crane_vip_daily_email_cron' ) ) {
        $next_8am = strtotime( 'today 08:00' );
        if ( $next_8am < time() ) $next_8am = strtotime( 'tomorrow 08:00' );
        wp_schedule_event( $next_8am, 'daily', 'crane_vip_daily_email_cron' );
    }
    if ( ! wp_next_scheduled( 'crane_fetch_news_cron' ) ) {
        wp_schedule_event( time(), 'hourly', 'crane_fetch_news_cron' );
    }

    flush_rewrite_rules();
}

register_deactivation_hook( __FILE__, 'crane_bets_core_deactivation' );
function crane_bets_core_deactivation() {
    wp_clear_scheduled_hook( 'crane_sync_predictions_cron' );
    wp_clear_scheduled_hook( 'crane_sync_predictions_cron_v2' );
    wp_clear_scheduled_hook( 'crane_sync_odds_cron' );
    wp_clear_scheduled_hook( 'crane_cleanup_predictions_cron' );
    wp_clear_scheduled_hook( 'crane_vip_daily_email_cron' );
    wp_clear_scheduled_hook( 'crane_fetch_news_cron' );
}
