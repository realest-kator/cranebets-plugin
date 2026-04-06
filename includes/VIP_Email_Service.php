<?php
/**
 * VIP Email Service
 * Handles daily VIP prediction email blast to all VIP members.
 * Predictions are manually curated by admin (flagged with _crane_vip_prediction meta).
 * Tier-ready: currently sends same email to all VIPs, but queries by tier for future expansion.
 */

if ( ! class_exists( 'Crane_VIP_Email_Service' ) ) {
class Crane_VIP_Email_Service {

    /**
     * Daily CRON callback — sends VIP predictions email
     */
    public static function send_daily_vip_email() {
        // Get today's VIP-flagged predictions
        $vip_predictions = new WP_Query( array(
            'post_type'      => 'crane_prediction',
            'posts_per_page' => 20,
            'post_status'    => 'publish',
            'meta_query'     => array(
                array(
                    'key'   => '_crane_vip_prediction',
                    'value' => '1',
                ),
            ),
            'date_query'     => array(
                array(
                    // Use explicit UTC date to prevent Africa/Lagos +1 timezone drift missing midnight predictions
                    'after'     => gmdate( 'Y-m-d', strtotime( '-1 day' ) ),
                    'inclusive' => true,
                    'column'    => 'post_date_gmt',
                ),
            ),
            'orderby'        => 'date',
            'order'          => 'DESC',
        ) );

        if ( ! $vip_predictions->have_posts() ) {
            error_log( 'Crane VIP Email: No VIP predictions found for today. Skipping email.' );
            return;
        }

        // Collect prediction data
        $picks = array();
        while ( $vip_predictions->have_posts() ) {
            $vip_predictions->the_post();
            $post_id = get_the_ID();
            $picks[] = array(
                'league'  => get_post_meta( $post_id, 'match_league', true ),
                'time'    => get_post_meta( $post_id, 'match_time', true ),
                'team1'   => get_post_meta( $post_id, 'team1_name', true ),
                'team2'   => get_post_meta( $post_id, 'team2_name', true ),
                'odd1'    => get_post_meta( $post_id, 'match_odd1', true ),
                'oddX'    => get_post_meta( $post_id, 'match_oddX', true ),
                'odd2'    => get_post_meta( $post_id, 'match_odd2', true ),
                'tip'     => get_post_meta( $post_id, '_crane_vip_tip', true ),
            );
        }
        wp_reset_postdata();

        // Build HTML email
        $email_body = self::build_email_html( $picks );

        // Get all VIP users (tier-ready query)
        $vip_users = self::get_vip_users();

        if ( empty( $vip_users ) ) {
            error_log( 'Crane VIP Email: No VIP users found. Skipping.' );
            return;
        }

        $site_name = get_bloginfo( 'name' );
        $subject = "{$site_name} VIP Predictions — " . date( 'D, M j' );
        $headers = array( 'Content-Type: text/html; charset=UTF-8' );

        $sent = 0;
        // TODO (Future Tiers): When Gold/Diamond tiers are added, loop per tier,
        // pass $tier to get_vip_users() and build_email_html() with tier-specific content.
        foreach ( $vip_users as $user ) {
            $result = wp_mail( $user->user_email, $subject, $email_body, $headers );
            if ( $result ) $sent++;
        }

        error_log( "Crane VIP Email: Sent to {$sent}/" . count( $vip_users ) . " VIP users with " . count( $picks ) . " predictions." );
    }

    /**
     * Get all VIP users, optionally filtered by tier
     * @param string $tier Optional tier filter (default: all VIPs)
     */
    public static function get_vip_users( $tier = '' ) {
        $meta_query = array(
            array(
                'key'   => 'crane_is_vip',
                'value' => '1',
            ),
        );

        // Future tiering: filter by specific tier
        if ( ! empty( $tier ) ) {
            $meta_query[] = array(
                'key'   => 'crane_vip_tier',
                'value' => $tier,
            );
        }

        $query = new WP_User_Query( array(
            'meta_query' => $meta_query,
            'fields'     => array( 'ID', 'user_email', 'display_name' ),
        ) );

        return $query->get_results();
    }

    /**
     * Build the HTML email body
     */
    private static function build_email_html( $picks ) {
        $site_name = get_bloginfo( 'name' );
        $date = date( 'l, F j, Y' );
        $count = count( $picks );

        $picks_html = '';
        foreach ( $picks as $pick ) {
            $tip_html = ! empty( $pick['tip'] ) 
                ? "<p style='color: #00ff6a; font-size: 12px; margin: 8px 0 0; font-style: italic;'>💡 {$pick['tip']}</p>" 
                : '';

            $picks_html .= "
            <div style='background: #111; border: 1px solid #222; border-radius: 12px; padding: 16px; margin-bottom: 12px;'>
                <div style='display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;'>
                    <span style='color: #00ff6a; font-size: 10px; text-transform: uppercase; letter-spacing: 2px; font-weight: 900;'>{$pick['league']}</span>
                    <span style='color: #666; font-size: 10px;'>{$pick['time']}</span>
                </div>
                <div style='font-size: 15px; font-weight: 900; color: #fff; margin-bottom: 6px;'>
                    {$pick['team1']} <span style='color: #444;'>vs</span> {$pick['team2']}
                </div>
                <div style='display: flex; gap: 12px;'>
                    <span style='background: #1a1a1a; border: 1px solid #333; padding: 4px 10px; border-radius: 6px; color: #fff; font-size: 11px; font-weight: 700;'>1: {$pick['odd1']}</span>
                    <span style='background: #1a1a1a; border: 1px solid #333; padding: 4px 10px; border-radius: 6px; color: #fff; font-size: 11px; font-weight: 700;'>X: {$pick['oddX']}</span>
                    <span style='background: #1a1a1a; border: 1px solid #333; padding: 4px 10px; border-radius: 6px; color: #fff; font-size: 11px; font-weight: 700;'>2: {$pick['odd2']}</span>
                </div>
                {$tip_html}
            </div>";
        }

        return "
<div style='font-family: Inter, Arial, sans-serif; max-width: 600px; margin: 0 auto; background: #0a0a0a; color: #ffffff; padding: 0;'>
    <!-- Header -->
    <div style='background: linear-gradient(135deg, #0a0a0a, #111); padding: 40px 30px 20px; border-bottom: 1px solid #222;'>
        <div style='text-align: center;'>
            <span style='background: rgba(0,255,106,0.1); border: 1px solid rgba(0,255,106,0.3); padding: 6px 16px; border-radius: 20px; color: #00ff6a; font-size: 9px; font-weight: 900; text-transform: uppercase; letter-spacing: 3px;'>VIP PREDICTIONS</span>
        </div>
        <h1 style='text-align: center; font-size: 26px; font-weight: 900; color: #fff; margin: 16px 0 4px; text-transform: uppercase;'>{$site_name}</h1>
        <p style='text-align: center; color: #666; font-size: 11px; text-transform: uppercase; letter-spacing: 2px;'>{$date} · {$count} Picks</p>
    </div>

    <!-- Predictions -->
    <div style='padding: 24px 30px;'>
        {$picks_html}
    </div>

    <!-- Footer -->
    <div style='padding: 20px 30px; border-top: 1px solid #222; text-align: center;'>
        <p style='color: #444; font-size: 10px; text-transform: uppercase; letter-spacing: 1px;'>
            You're receiving this because you have VIP access on {$site_name}.<br>
            This is not financial advice. Bet responsibly.
        </p>
    </div>
</div>";
    }

    /**
     * Admin: Manual trigger to send VIP email now
     */
    public static function handle_manual_vip_email() {
        check_admin_referer( 'crane_manual_vip_email' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );

        self::send_daily_vip_email();

        wp_redirect( admin_url( 'admin.php?page=crane-api-settings&vip_email_sent=1' ) );
        exit;
    }
}
}
