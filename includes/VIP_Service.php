<?php
/**
 * VIP Service for Crane Bets
 * Handling Timer logic, Motivation Curve, and VIP Premium Access Visibility
 * 
 * Timer tracks SECONDS of actual browsing time.
 * 400 hours = 1,440,000 seconds to unlock VIP.
 * Progress uses sqrt curve: fast at first, slows down.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Crane_VIP_Service {
    
    /**
     * Boot VIP Service
     * Decoupling from Core God Object (Issue #2a)
     */
    public static function boot() {
        // AJAX Handlers
        add_action( 'wp_ajax_crane_reduce_timer', array( __CLASS__, 'handle_timer_reduction' ) );
        add_action( 'wp_ajax_nopriv_crane_reduce_timer', array( __CLASS__, 'handle_timer_reduction' ) );
        add_action( 'wp_ajax_crane_increment_timer', array( __CLASS__, 'handle_increment_timer' ) );
        add_action( 'wp_ajax_nopriv_crane_increment_timer', array( __CLASS__, 'handle_increment_timer' ) );
        
        // Shortcodes
        add_shortcode( 'crane_leaderboard', array( __CLASS__, 'render_leaderboard' ) );
        add_shortcode( 'crane_vip_timer', array( __CLASS__, 'render_vip_timer_placeholder' ) );
        add_shortcode( 'crane_user_dashboard', array( __CLASS__, 'render_dashboard_placeholder' ) );
    }

    const VIP_THRESHOLD_HOURS = 400;
    const VIP_THRESHOLD_SECONDS = 1440000; // 400 * 3600
    const HEARTBEAT_INTERVAL = 300; // seconds between pings
    const MIN_PING_GAP    = 290; // Allow 10s flex for latency
    const MAX_CREDIT_TIME = 360; // Max normal wait + wiggle

    /**
     * Handle heartbeat ping — tracks real browsing time
     * JS pings every 300 seconds while user is on the site
     */
    public static function handle_increment_timer() {
        check_ajax_referer( 'crane_security_nonce', 'security' );
        if ( ! is_user_logged_in() ) wp_send_json_error();
        $user_id = get_current_user_id();

        // Already VIP? Skip tracking
        if ( get_user_meta( $user_id, 'crane_is_vip', true ) === '1' ) {
            wp_send_json_success( array( 'is_vip' => true, 'progress' => 100 ) );
        }

        $last_update = (int) get_user_meta( $user_id, 'crane_vip_last_ping', true );
        $now = current_time( 'timestamp' );
        $gap = $now - $last_update;

        if ( $last_update && $gap < self::MIN_PING_GAP ) {
            // Rate limit triggered (cheating or rapid clicks)
            wp_send_json_error( array( 'message' => 'Anti-cheat triggered. Tab closed or syncing too fast.' ) );
        }

        // Immediately secure the ping to prevent DB trace collision (Data Race Throttle Bypass Fix)
        update_user_meta( $user_id, 'crane_vip_last_ping', $now ); 

        // Calculate valid time
        $credit = $gap;
        if ( ! $last_update || $credit > self::MAX_CREDIT_TIME ) {
            $credit = 300; // Base 5 minutes per ping
        }

        $total_seconds = absint( get_user_meta( $user_id, 'crane_vip_seconds', true ) );
        $new_total = $total_seconds + $credit;

        update_user_meta( $user_id, 'crane_vip_seconds', $new_total );
        update_user_meta( $user_id, 'crane_last_timer_update', $now );

        // Also update legacy timer (hours) for backward compat
        $hours = floor( $new_total / 3600 );
        update_user_meta( $user_id, 'crane_vip_timer', $hours );

        // Check if VIP unlocked
        if ( $new_total >= self::VIP_THRESHOLD_SECONDS ) {
            $current_source = get_user_meta( $user_id, 'crane_vip_source', true );
            
            // Only set source to 'timer' if not already set by admin/purchase
            if ( ! $current_source || $current_source === 'timer' ) {
                // Check if they are just unlocking it now (no expiry set)
                $current_expiry = get_user_meta( $user_id, 'crane_vip_expiry', true );
                if ( ! $current_expiry ) {
                    update_user_meta( $user_id, 'crane_is_vip', '1' );
                    update_user_meta( $user_id, 'crane_vip_source', 'timer' );
                    update_user_meta( $user_id, 'crane_vip_expiry', current_time('timestamp') + ( 30 * DAY_IN_SECONDS ) );
                }
            }
        }

        // Calculate VIP Premium Access progress: sqrt(hours / 400) * 100
        $progress = min( 100, round( sqrt( $hours / self::VIP_THRESHOLD_HOURS ) * 100, 1 ) );

        wp_send_json_success( array(
            'total_seconds' => $new_total,
            'hours'         => $hours,
            'progress'      => $progress,
            'is_vip'        => $new_total >= self::VIP_THRESHOLD_SECONDS,
        ) );
    }

    /**
     * Handle timer reduction (for admin use or penalty)
     */
    public static function handle_timer_reduction() {
        check_ajax_referer( 'crane_security_nonce', 'security' );
        if ( ! is_user_logged_in() ) wp_send_json_error();
        $user_id = get_current_user_id();
        
        $total_seconds = absint( get_user_meta( $user_id, 'crane_vip_seconds', true ) );
        if ( $total_seconds <= 0 ) wp_send_json_error( array( 'message' => 'Timer already depleted.' ) );
        
        $new_total = max( 0, $total_seconds - 3600 ); // Remove 1 hour
        update_user_meta( $user_id, 'crane_vip_seconds', $new_total );
        update_user_meta( $user_id, 'crane_vip_timer', floor( $new_total / 3600 ) );
        wp_send_json_success( array( 'total_seconds' => $new_total ) );
    }

    /**
     * Get VIP progress data for a user
     * Supports both 'Hours Spent' (Timer) and 'Month Duration' (Purchase)
     */
    public static function get_vip_progress( $user_id ) {
        $total_seconds = absint( get_user_meta( $user_id, 'crane_vip_seconds', true ) );
        $hours = floor( $total_seconds / 3600 );
        $is_vip = get_user_meta( $user_id, 'crane_is_vip', true ) === '1';
        $vip_source = get_user_meta( $user_id, 'crane_vip_source', true ) ?: '';

        // Expiry Check for Monthly Subscriptions AND Timer
        if ( $is_vip ) {
            $expiry = (int) get_user_meta( $user_id, 'crane_vip_expiry', true );
            $now = current_time( 'timestamp' );
            
            if ( $expiry && $now > $expiry ) {
                $is_vip = false;
                delete_user_meta( $user_id, 'crane_is_vip' );
                delete_user_meta( $user_id, 'crane_vip_source' );
                delete_user_meta( $user_id, 'crane_vip_expiry' );

                // If they attained this via timer, reset their hard work so they can earn it again
                if ( $vip_source === 'timer' ) {
                    update_user_meta( $user_id, 'crane_vip_seconds', 0 );
                    update_user_meta( $user_id, 'crane_vip_timer', 0 );
                    $total_seconds = 0;
                    $hours = 0;
                }
            }
        }

        // Motivation curve: sqrt(hours/400) * 100
        $progress = min( 100, round( sqrt( $hours / self::VIP_THRESHOLD_HOURS ) * 100, 1 ) );

        return array(
            'total_seconds' => $total_seconds,
            'hours'         => $hours,
            'progress'      => $progress,
            'is_vip'        => $is_vip,
            'vip_source'    => $vip_source, // 'timer' or 'purchase'
            'expiry'        => isset($expiry) ? $expiry : 0,
            'threshold'     => self::VIP_THRESHOLD_HOURS,
        );
    }

    /**
     * Handle VIP purchase via WooCommerce order completion
     * Supports VARIABLE QUANTITY (e.g. 3 Units = 90 Days) and STACKING (Extension)
     */
    public static function handle_vip_purchase( $order_id ) {
        if ( ! function_exists( 'wc_get_order' ) ) return;
        
        $order = wc_get_order( $order_id );
        if ( ! $order || ! is_a( $order, 'WC_Order' ) ) return;

        $user_id = $order->get_user_id();
        if ( ! $user_id ) return;

        $vip_product_id = (int) get_option( 'crane_vip_product_id', 0 );

        foreach ( $order->get_items() as $item ) {
            $product = $item->get_product();
            if ( ! $product ) continue;

            $current_product_id = (int) $product->get_id();
            $parent_id         = (int) $product->get_parent_id();

            $is_vip_item = ( $vip_product_id && ( $current_product_id === $vip_product_id || $parent_id === $vip_product_id ) );
            if ( ! $is_vip_item ) {
                $name = strtolower( $product->get_name() );
                if ( strpos( $name, 'vip' ) !== false || has_term( array( 'vip', 'inner-circle' ), 'product_cat', $current_product_id ) ) {
                    $is_vip_item = true;
                }
            }

            if ( $is_vip_item ) {
                $quantity = (int) $item->get_quantity();
                $days_to_add = $quantity * 30;
                $tier = ( $vip_product_id ? get_post_meta( $vip_product_id, '_crane_vip_tier', true ) : '' ) ?: 'pro';

                // Handle Stacking Logic (Architectural Fix #3)
                $now = current_time( 'timestamp' );
                $current_expiry = (int) get_user_meta( $user_id, 'crane_vip_expiry', true );

                if ( $current_expiry && $current_expiry > $now ) {
                    $new_expiry = $current_expiry + ( $days_to_add * DAY_IN_SECONDS );
                } else {
                    $new_expiry = $now + ( $days_to_add * DAY_IN_SECONDS );
                }

                // Apply Staked Membership
                update_user_meta( $user_id, 'crane_is_vip', '1' );
                update_user_meta( $user_id, 'crane_vip_source', 'purchase' );
                update_user_meta( $user_id, 'crane_vip_tier', $tier );
                update_user_meta( $user_id, 'crane_vip_expiry', $new_expiry );
                update_user_meta( $user_id, 'crane_vip_activated', current_time( 'mysql' ) );

                self::send_vip_welcome_email( $user_id, $tier );
                error_log( "Crane VIP Stack: User #{$user_id} added {$days_to_add} days access. New Expiry: " . date('Y-m-d H:i', $new_expiry) );
                break;
            }
        }
    }

    /**
     * AWARD VIP Status — Direct assignment for simulations/admins
     */
    public static function award_vip_status( $user_id, $source = 'manual', $tier = 'pro' ) {
        if ( ! $user_id ) return;
        
        update_user_meta( $user_id, 'crane_is_vip', '1' );
        update_user_meta( $user_id, 'crane_vip_source', $source );
        update_user_meta( $user_id, 'crane_vip_tier', $tier );
        update_user_meta( $user_id, 'crane_vip_activated', current_time( 'mysql' ) );
        
        self::send_vip_welcome_email( $user_id, $tier );
        error_log( "Crane VIP: User #{$user_id} manually awarded VIP ({$tier}) via source: {$source}" );
    }

    /**
     * Send VIP welcome email
     */
    public static function send_vip_welcome_email( $user_id, $tier = 'pro' ) {
        $user = get_userdata( $user_id );
        if ( ! $user ) return;

        $site_name = get_bloginfo( 'name' );
        // Human-centric label fix: change 'admin_grant' to 'Premium' 
        $tier_label = ( $tier === 'admin_grant' || $tier === 'pro' ) ? 'Premium' : ucfirst( $tier );

        // Calculate days remaining (Architectural Enrichment)
        $expiry = (int) get_user_meta( $user_id, 'crane_vip_expiry', true );
        $now = current_time( 'timestamp' );
        $days_remaining = ( $expiry > $now ) ? ceil( ( $expiry - $now ) / DAY_IN_SECONDS ) : 30;

        $subject = "🎉 Welcome to {$site_name} VIP — You're In!";
        $message = "
<div style='font-family: Inter, Arial, sans-serif; max-width: 600px; margin: 0 auto; background: #0a0a0a; color: #ffffff; padding: 40px; border-radius: 16px;'>
    <h1 style='color: #00ff6a; font-size: 28px; margin-bottom: 10px;'>Welcome to VIP 🏆</h1>
    <p style='color: #999; font-size: 14px;'>Hey {$user->display_name},</p>
    <p style='color: #ccc; font-size: 14px; line-height: 1.8;'>
        You now have <strong style='color: #00ff6a;'>VIP Premium Access</strong> on {$site_name}. 
        Starting from your next update, you'll receive daily premium predictions delivered straight to your email.
    </p>
    <div style='background: #111; border: 1px solid #222; border-radius: 12px; padding: 20px; margin: 20px 0;'>
        <h3 style='color: #00ff6a; font-size: 14px; text-transform: uppercase; letter-spacing: 2px;'>What You Get</h3>
        <ul style='color: #ccc; font-size: 13px; line-height: 2; padding-left: 20px;'>
            <li>Daily premium match predictions via email</li>
            <li>Early access to odds before they shift</li>
            <li>Exclusive VIP-only community updates</li>
            <li>Priority support</li>
            <li style='margin-top: 10px; color: #00ff6a;'><strong>VIP Access Duration:</strong> {$days_remaining} Days</li>
        </ul>
    </div>
    <p style='color: #666; font-size: 11px; text-transform: uppercase; letter-spacing: 1px;'>
        Your first VIP predictions email will arrive shortly.
    </p>
    <hr style='border: 1px solid #222; margin: 20px 0;'>
    <p style='color: #444; font-size: 10px;'>— The {$site_name} Team</p>
</div>";

        $headers = array( 'Content-Type: text/html; charset=UTF-8' );
        wp_mail( $user->user_email, $subject, $message, $headers );
    }

    public static function update_accuracy_status( $user_id ) {
        if ( class_exists('Crane_User_Prediction_Service') ) {
            Crane_User_Prediction_Service::recalculate_user_accuracy( $user_id );
        }
    }

    public static function render_leaderboard() {
        $top_users = new WP_User_Query( array(
            'meta_key' => 'crane_accuracy_ratio',
            'orderby'  => 'meta_value_num',
            'order'    => 'DESC',
            'number'   => 10
        ) );

        return Crane_Template_Service::load_template_part( 'leaderboard', array( 'users' => $top_users->get_results() ) );
    }

    public static function render_vip_timer_placeholder() {
        if ( ! is_user_logged_in() ) return '<p class="text-white/60">Log in to view VIP status.</p>';
        $user_id = get_current_user_id();
        $vip_data = self::get_vip_progress( $user_id );
        return Crane_Template_Service::load_template_part( 'vip-timer', array(
            'timer'    => $vip_data['hours'],
            'progress' => $vip_data['progress'],
            'is_vip'   => $vip_data['is_vip'],
            'source'   => $vip_data['vip_source'],
        ) );
    }

    public static function render_dashboard_placeholder() {
        if ( ! is_user_logged_in() ) return '<p class="text-white/60">Log in to access dashboard.</p>';
        $user  = wp_get_current_user();
        $vip_data = self::get_vip_progress( $user->ID );
        $accuracy = class_exists('Crane_User_Prediction_Service')
            ? Crane_User_Prediction_Service::get_user_accuracy( $user->ID )
            : array( 'wins' => 0, 'losses' => 0, 'total' => 0, 'ratio' => 0, 'percentage' => 0, 'badge' => 'Novice', 'color' => '#888888' );

        return Crane_Template_Service::load_template_part( 'dashboard', array(
            'user'       => $user,
            'wins'       => $accuracy['wins'],
            'badge'      => $accuracy['badge'],
            'timer'      => $vip_data['hours'],
            'progress'   => $vip_data['progress'],
            'is_vip'     => $vip_data['is_vip'],
            'vip_source' => $vip_data['vip_source'],
            'expiry'     => $vip_data['expiry'],
            'accuracy'   => $accuracy,
        ) );
    }
}
