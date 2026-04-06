<?php
/**
 * Affiliate Service for Crane Bets
 * Handling Referral tracking and Commission processing
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Crane_Affiliate_Service {

    public static function capture_referral_id() {
        if ( isset( $_GET['ref'] ) ) {
            $ref_id = absint( $_GET['ref'] );
            if ( $ref_id > 0 && get_userdata( $ref_id ) ) {
                // Set cookie for 30 days (PHP fallback)
                if ( ! headers_sent() ) {
                    setcookie( 'crane_referrer', $ref_id, time() + ( DAY_IN_SECONDS * 30 ), COOKIEPATH, COOKIE_DOMAIN );
                }
            }
        }
        
        // Force cookie logic via JS to pierce through WP-Rocket / Cloudflare full-page caching
        add_action( 'wp_footer', function() {
            ?>
            <script>
                (function() {
                    const urlParams = new URLSearchParams(window.location.search);
                    const ref = urlParams.get('ref');
                    if (ref) {
                        document.cookie = "crane_referrer=" + encodeURIComponent(ref) + "; max-age=" + (30*24*60*60) + "; path=/";
                    }
                })();
            </script>
            <?php
        });
    }

    public static function process_commission( $order_id ) {
        if ( ! function_exists( 'wc_get_order' ) ) return;
        
        $order = wc_get_order( $order_id );
        if ( ! $order || ! is_a( $order, 'WC_Order' ) ) return;
        
        // Idempotent lock: Prevent duplicating the 20% payout if Woocommerce fires 'completed' twice
        if ( $order->get_meta( 'crane_commission_paid' ) === 'yes' ) return;
        
        $user_id = $order->get_user_id();
        if ( ! $user_id ) return;
        
        $referrer_id = get_user_meta( $user_id, 'crane_referred_by', true );
        if ( ! $referrer_id ) return;

        $commission = (float) $order->get_total() * 0.20;
        $balance = (float) get_user_meta( $referrer_id, 'crane_affiliate_balance', true ) ?: 0;
        update_user_meta( $referrer_id, 'crane_affiliate_balance', $balance + $commission );
        
        // Seal the lock
        $order->update_meta_data( 'crane_commission_paid', 'yes' );
        $order->save_meta_data();
    }

    public static function check_referral_unlock( $is_purchasable, $product ) {
        if ( ! class_exists( 'WooCommerce' ) || ! $product ) return $is_purchasable;
        
        $required = (int) get_post_meta( $product->get_id(), 'crane_referral_required', true );
        if ( ! $required ) return $is_purchasable;
        if ( ! is_user_logged_in() ) return false;
        
        $referrals = new WP_User_Query( array( 
            'meta_key'    => 'crane_referred_by', 
            'meta_value'  => get_current_user_id(), 
            'count_total' => true 
        ) );
        return $referrals->get_total() >= $required;
    }

    public static function render_affiliate_dashboard() {
        if ( ! is_user_logged_in() ) return '<p class="text-white/60">Log in to view dashboard.</p>';
        $user_id = get_current_user_id();
        $balance = (float) get_user_meta( $user_id, 'crane_affiliate_balance', true ) ?: 0;
        $ref_link = home_url( '/?ref=' . $user_id );
        
        $referrals = new WP_User_Query( array( 'meta_key' => 'crane_referred_by', 'meta_value' => $user_id, 'count_total' => true ) );
        $total_refs = $referrals->get_total();

        return Crane_Template_Service::load_template_part( 'affiliate-dashboard', array(
            'balance'    => $balance,
            'ref_link'   => $ref_link,
            'total_refs' => $total_refs
        ) );
    }

    /**
     * Get Total Referrals for a User (Architectural Fix)
     * Queries for users who have 'crane_referred_by' set to this user ID.
     */
    public static function get_referral_count( $user_id ) {
        if ( ! $user_id ) return 0;
        
        $referrals = new WP_User_Query( array( 
            'meta_key'    => 'crane_referred_by', 
            'meta_value'  => $user_id, 
            'count_total' => true,
            'fields'      => 'ID' 
        ) );
        
        return (int) $referrals->get_total();
    }
}
