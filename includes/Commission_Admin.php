<?php
/**
 * Commission Admin Service for Crane Bets
 * Admin panel for managing referral commissions + Paystack Transfer payouts
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Crane_Commission_Admin {

    /**
     * Register admin menu page
     */
    public static function register_menu() {
        // Consolidated into Crane_Bets_Core to prevent duplicate slug errors.
    }

    /**
     * Register Paystack settings
     */
    public static function register_settings() {
        register_setting( 'crane_api_options', 'crane_paystack_secret_key' );
    }

    /**
     * Handle commission payout via Paystack Transfer
     */
    public static function handle_payout() {
        check_admin_referer( 'crane_payout_action' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );

        $user_id = absint( isset($_POST['user_id']) ? $_POST['user_id'] : 0 );
        $amount  = floatval( get_user_meta( $user_id, 'crane_affiliate_balance', true ) );

        if ( ! $user_id || $amount <= 0 ) {
            wp_redirect( add_query_arg( array( 'crane_msg' => 'Invalid user or insufficient balance', 'crane_type' => 'error' ), menu_page_url( 'crane-tools', false ) ) );
            exit;
        }

        // 1. Race Condition Fix: Mutual Exclusion Lock (Issue #1b)
        $lock_key = "crane_payout_lock_{$user_id}";
        if ( get_transient( $lock_key ) ) {
            wp_redirect( add_query_arg( array( 'crane_msg' => 'Payout already in progress. Please wait.', 'crane_type' => 'error' ), menu_page_url( 'crane-tools', false ) ) );
            exit;
        }
        set_transient( $lock_key, '1', 60 ); // 1 minute lock

        $paystack_key = get_option( 'crane_paystack_secret_key', '' );
        if ( empty( $paystack_key ) ) {
            wp_redirect( add_query_arg( array( 'crane_msg' => 'Paystack key not configured', 'crane_type' => 'error' ), menu_page_url( 'crane-tools', false ) ) );
            exit;
        }

        // Get user's bank details
        $bank_code    = get_user_meta( $user_id, 'crane_bank_code', true );
        $account_num  = get_user_meta( $user_id, 'crane_account_number', true );
        $account_name = get_user_meta( $user_id, 'crane_account_name', true );

        if ( empty( $bank_code ) || empty( $account_num ) ) {
            wp_redirect( add_query_arg( array( 'crane_msg' => 'User has not set bank details', 'crane_type' => 'error' ), menu_page_url( 'crane-tools', false ) ) );
            exit;
        }

        // Step 1: Create Transfer Recipient
        $recipient_response = wp_remote_post( 'https://api.paystack.co/transferrecipient', array(
            'timeout' => 30,
            'headers' => array(
                'Authorization' => 'Bearer ' . $paystack_key,
                'Content-Type'  => 'application/json',
            ),
            'body' => json_encode( array(
                'type'           => 'nuban',
                'name'           => $account_name ?: 'Crane User',
                'account_number' => $account_num,
                'bank_code'      => $bank_code,
                'currency'       => 'NGN',
            ) ),
        ) );

        if ( is_wp_error( $recipient_response ) ) {
            error_log( 'Crane Paystack Recipient Error: ' . $recipient_response->get_error_message() );
            wp_redirect( add_query_arg( array( 'crane_msg' => 'Paystack connection failed', 'crane_type' => 'error' ), menu_page_url( 'crane-tools', false ) ) );
            exit;
        }

        $recipient_body = json_decode( wp_remote_retrieve_body( $recipient_response ), true );
        if ( empty( $recipient_body['data']['recipient_code'] ) ) {
            $msg = isset($recipient_body['message']) ? $recipient_body['message'] : 'Unknown error creating recipient';
            error_log( 'Crane Paystack Recipient Fail: ' . $msg );
            wp_redirect( add_query_arg( array( 'crane_msg' => $msg, 'crane_type' => 'error' ), menu_page_url( 'crane-tools', false ) ) );
            exit;
        }

        $recipient_code = $recipient_body['data']['recipient_code'];

        // Step 2: Initiate Transfer
        $transfer_response = wp_remote_post( 'https://api.paystack.co/transfer', array(
            'timeout' => 30,
            'headers' => array(
                'Authorization' => 'Bearer ' . $paystack_key,
                'Content-Type'  => 'application/json',
            ),
            'body' => json_encode( array(
                'source'    => 'balance',
                'amount'    => $amount * 100, // Convert to kobo
                'recipient' => $recipient_code,
                'reason'    => 'Crane Bets Referral Commission',
            ) ),
        ) );

        if ( is_wp_error( $transfer_response ) ) {
            error_log( 'Crane Paystack Transfer Error: ' . $transfer_response->get_error_message() );
            wp_redirect( add_query_arg( array( 'crane_msg' => 'Transfer failed', 'crane_type' => 'error' ), menu_page_url( 'crane-tools', false ) ) );
            exit;
        }

        $transfer_body = json_decode( wp_remote_retrieve_body( $transfer_response ), true );
        if ( ! empty( $transfer_body['status'] ) && $transfer_body['status'] === true ) {
            // Success — reset balance and log payment
            $reference = isset($transfer_body['data']['transfer_code']) ? $transfer_body['data']['transfer_code'] : 'manual-' . time();

            // Reset affiliate balance
            update_user_meta( $user_id, 'crane_affiliate_balance', 0 );

            // Log the payout
            $log = get_user_meta( $user_id, 'crane_commission_log', true );
            if ( ! is_array( $log ) ) $log = array();
            $log[] = array(
                'amount'    => $amount,
                'reference' => $reference,
                'date'      => current_time( 'mysql' ),
                'status'    => 'success',
            );
            update_user_meta( $user_id, 'crane_commission_log', $log );

            wp_redirect( add_query_arg( array( 'crane_msg' => '₦' . number_format( $amount ) . ' paid successfully!', 'crane_type' => 'success' ), menu_page_url( 'crane-tools', false ) ) );
        } else {
            $msg = isset($transfer_body['message']) ? $transfer_body['message'] : 'Transfer failed';
            error_log( 'Crane Paystack Transfer Fail: ' . $msg );
            wp_redirect( add_query_arg( array( 'crane_msg' => $msg, 'crane_type' => 'error' ), menu_page_url( 'crane-tools', false ) ) );
        }
        exit;
    }

    /**
     * Handle manual (non-Paystack) payout marking
     */
    public static function handle_manual_payout() {
        check_admin_referer( 'crane_manual_payout_action' );
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Unauthorized' );

        $user_id = absint( isset($_POST['user_id']) ? $_POST['user_id'] : 0 );
        $amount  = floatval( get_user_meta( $user_id, 'crane_affiliate_balance', true ) );

        if ( ! $user_id || $amount <= 0 ) {
            wp_redirect( add_query_arg( array( 'crane_msg' => 'Invalid user', 'crane_type' => 'error' ), menu_page_url( 'crane-tools', false ) ) );
            exit;
        }

        update_user_meta( $user_id, 'crane_affiliate_balance', 0 );

        $log = get_user_meta( $user_id, 'crane_commission_log', true );
        if ( ! is_array( $log ) ) $log = array();
        $log[] = array(
            'amount'    => $amount,
            'reference' => 'manual-' . time(),
            'date'      => current_time( 'mysql' ),
            'status'    => 'manual',
        );
        update_user_meta( $user_id, 'crane_commission_log', $log );

        wp_redirect( add_query_arg( array( 'crane_msg' => 'Marked as paid (manual)', 'crane_type' => 'success' ), menu_page_url( 'crane-tools', false ) ) );
        exit;
    }

    /**
     * Render the commissions admin page
     */
    public static function render_commissions_page() {
        // 1. Architectural Hardening: Responsive Admin UI
        ?>
        <style>
            .crane-engagement-table th.col-vip, .crane-engagement-table td.col-vip { width: 100px; }
            @media screen and (max-width: 960px) {
                .crane-engagement-table .col-vip, .crane-engagement-table .col-refs { display: none !important; }
            }
            .crane-engagement-table .dashicons { vertical-align: middle; margin-right: 4px; }
        </style>
        <?php

        // 2. Data Logic Hardening: Direct SQL lookup with Transient Caching (1 Hour)
        global $wpdb;
        $target_ids = get_transient( 'crane_active_engagement_ids' );
        $referrers  = array(); // Initialize outside cache block to prevent fatal on cache-hit

        if ( ! $target_ids || ! is_array( $target_ids ) ) {
            // Find everyone who is a referrer
            $referrers = $wpdb->get_col( "SELECT DISTINCT meta_value FROM $wpdb->usermeta WHERE meta_key = 'crane_referred_by'" );
            
            // Find everyone with a balance or significant time spent
            $engaged = $wpdb->get_col( "SELECT user_id FROM $wpdb->usermeta WHERE (meta_key = 'crane_affiliate_balance' AND meta_value > 0) OR (meta_key = 'crane_vip_seconds' AND meta_value >= 300)" );
            
            // Merge and clean IDs
            $target_ids = array_unique( array_merge( 
                array_filter( array_map( 'absint', $referrers ) ), 
                array_filter( array_map( 'absint', $engaged ) ) 
            ) );

            set_transient( 'crane_active_engagement_ids', $target_ids, HOUR_IN_SECONDS );
        } else {
            // Cache hit: still need referrers for the merge below
            $referrers = $wpdb->get_col( "SELECT DISTINCT meta_value FROM $wpdb->usermeta WHERE meta_key = 'crane_referred_by'" );
        }

        if ( empty( $target_ids ) ) {
            $all_users = array();
        } else {
            // Cap at 250 for admin performance, sorted by reverse ID
            $all_users = get_users( array( 
                'include' => array_slice( $target_ids, 0, 250 ),
                'orderby' => 'ID',
                'order'   => 'DESC'
            ) );
        }
        
        $pending_users = array();
        $active_users = array();
        $paid_users = array();
        
        $user_ids_to_check = array_unique( array_merge( 
            wp_list_pluck( $all_users, 'ID' ), 
            array_map( 'absint', $referrers ) 
        ) );

        foreach ( $user_ids_to_check as $user_id ) {
            $user = get_userdata( $user_id );
            if ( ! $user ) continue;

            $balance = floatval( get_user_meta( $user->ID, 'crane_affiliate_balance', true ) );
            $referral_count = self::count_referrals( $user->ID );
            $vip_seconds = absint( get_user_meta( $user->ID, 'crane_vip_seconds', true ) );
            $vip_hours = floor( $vip_seconds / 3600 );
            
            $bank_code = get_user_meta( $user->ID, 'crane_bank_code', true );
            $account_num = get_user_meta( $user->ID, 'crane_account_number', true );
            $account_name = get_user_meta( $user->ID, 'crane_account_name', true );
            $log = get_user_meta( $user->ID, 'crane_commission_log', true );
            $total_paid = 0;
            if ( is_array( $log ) ) {
                foreach ( $log as $entry ) {
                    $total_paid += floatval( isset($entry['amount']) ? $entry['amount'] : 0 );
                }
            }

            $user_data = array(
                'user'          => $user,
                'balance'       => $balance,
                'referrals'     => $referral_count,
                'vip_hours'     => $vip_hours,
                'bank_code'     => $bank_code,
                'account_num'   => $account_num,
                'account_name'  => $account_name,
                'total_paid'    => $total_paid,
                'log'           => is_array( $log ) ? $log : array(),
            );

            if ( $balance > 0 ) {
                $pending_users[] = $user_data;
            } elseif ( $referral_count > 0 || $vip_hours > 0 ) {
                $active_users[] = $user_data;
            } elseif ( $total_paid > 0 ) {
                $paid_users[] = $user_data;
            }
        }
        ?>
        <div class="wrap">
            <h1>User Earnings & Engagement</h1>
            <p class="description">Monitor referrals, commissions, and website activity (VIP Hours) for all users.</p>

            <?php if ( isset( $_GET['crane_msg'] ) ) : ?>
                <div class="notice notice-<?php echo ( isset($_GET['crane_type']) ? $_GET['crane_type'] : 'success' ) === 'error' ? 'error' : 'success'; ?> is-dismissible">
                    <p><?php echo esc_html( $_GET['crane_msg'] ); ?></p>
                </div>
            <?php endif; ?>

            <!-- Paystack Key Status -->
            <div class="card" style="padding:15px; max-width:700px; margin-bottom:20px; background: #fff; border-left: 4px solid #00ff6a;">
                <strong>Paystack Integration:</strong> <?php echo get_option( 'crane_paystack_secret_key' ) ? '✅ Configured' : '❌ Not configured — <a href="' . admin_url( 'admin.php?page=crane-api-settings' ) . '">Set it in API Settings</a>'; ?>
            </div>

            <!-- Pending Commissions -->
            <h2 style="display:flex; align-items:center; gap:10px;">
                <span style="background:#d63638; color:#fff; padding:2px 8px; border-radius:10px; font-size:14px;"><?php echo count( $pending_users ); ?></span>
                Pending Payouts
            </h2>
            <?php if ( ! empty( $pending_users ) ) : ?>
            <table class="wp-list-table widefat fixed striped" style="max-width:1000px;">
                <thead>
                    <tr>
                        <th>User</th>
                        <th>Balance</th>
                        <th>Referrals</th>
                        <th>Time Spent</th>
                        <th>Bank Details</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ( $pending_users as $pu ) : ?>
                    <tr>
                        <td>
                            <strong><?php echo esc_html( $pu['user']->display_name ); ?></strong>
                            <br><small><?php echo esc_html( $pu['user']->user_email ); ?></small>
                        </td>
                        <td><strong style="color:#0a7c42; font-size:1.1em;">₦<?php echo number_format( $pu['balance'] ); ?></strong></td>
                        <td><span class="dashicons dashicons-groups" style="color:#666;"></span> <strong><?php echo $pu['referrals']; ?></strong></td>
                        <td><span class="dashicons dashicons-clock" style="color:#666;"></span> <?php echo $pu['vip_hours']; ?>h</td>
                        <td>
                            <?php if ( $pu['bank_code'] ) : ?>
                                <strong><?php echo esc_html( $pu['account_name'] ); ?></strong><br>
                                <small>****<?php echo substr( $pu['account_num'], -4 ); ?></small>
                            <?php else : ?>
                                <span style="color:#d63638; font-style:italic;">Not set</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php 
                            $secret_key = get_option( 'crane_paystack_secret_key' );
                            if ( $pu['bank_code'] && $secret_key ) : 
                            ?>
                            <form method="post" action="<?php echo admin_url( 'admin-post.php' ); ?>" style="display:inline;" onsubmit="return confirm('Pay ₦<?php echo number_format( $pu['balance'] ); ?> to <?php echo esc_attr( $pu['user']->display_name ); ?>?');">
                                <input type="hidden" name="action" value="crane_payout_commission">
                                <input type="hidden" name="user_id" value="<?php echo $pu['user']->ID; ?>">
                                <input type="hidden" name="amount" value="<?php echo $pu['balance']; ?>">
                                <?php wp_nonce_field( 'crane_payout_action' ); ?>
                                <button type="submit" class="button button-primary" style="background:#007cba; border-color:#007cba;">💳 Pay via Paystack</button>
                            </form>
                            <?php elseif ( $pu['bank_code'] ) : ?>
                                <span class="description" style="color:#d63638; font-size:10px;">⚠️ Secret Key Missing in API Settings</span>
                            <?php else : ?>
                                <span class="description" style="font-size:10px;">No Bank Details Saved</span>
                            <?php endif; ?>
                            <form method="post" action="<?php echo admin_url( 'admin-post.php' ); ?>" style="display:inline; margin-left:5px;" onsubmit="return confirm('Mark as manually paid?');">
                                <input type="hidden" name="action" value="crane_manual_payout">
                                <input type="hidden" name="user_id" value="<?php echo $pu['user']->ID; ?>">
                                <?php wp_nonce_field( 'crane_manual_payout_action' ); ?>
                                <button type="submit" class="button">✓ Mark Paid</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php else : ?>
                <p>No pending commissions.</p>
            <?php endif; ?>

            <!-- Active Users (Referrals or Hours) -->
            <h2 style="margin-top:40px;">Active Affiliates & Users</h2>
            <?php if ( ! empty( $active_users ) ) : ?>
            <table class="wp-list-table widefat fixed striped" style="max-width:900px;">
                <thead>
                    <tr>
                        <th>User</th>
                        <th>Referrals</th>
                        <th>Time Spent</th>
                        <th>Total Earned</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ( $active_users as $au ) : ?>
                    <tr>
                        <td>
                            <strong><?php echo esc_html( $au['user']->display_name ); ?></strong>
                            <br><small><?php echo esc_html( $au['user']->user_email ); ?></small>
                        </td>
                        <td><span class="dashicons dashicons-groups" style="color:#666;"></span> <strong><?php echo $au['referrals']; ?></strong></td>
                        <td><span class="dashicons dashicons-clock" style="color:#666;"></span> <?php echo $au['vip_hours']; ?>h</td>
                        <td>₦<?php echo number_format( $au['total_paid'] ); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php else : ?>
                <p>No other active users found.</p>
            <?php endif; ?>

            <!-- Payment History -->
            <h2 style="margin-top:40px;">Payment History</h2>
            <?php if ( ! empty( $paid_users ) ) : ?>
            <table class="wp-list-table widefat fixed striped" style="max-width:800px;">
                <thead>
                    <tr>
                        <th>User</th>
                        <th>Total Paid</th>
                        <th>Last Payment</th>
                        <th>Reference</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ( $paid_users as $pu ) :
                    $last = end( $pu['log'] );
                ?>
                    <tr>
                        <td>
                            <strong><?php echo esc_html( $pu['user']->display_name ); ?></strong>
                        </td>
                        <td><strong style="color:#0a7c42;">₦<?php echo number_format( $pu['total_paid'] ); ?></strong></td>
                        <td><?php echo esc_html( isset($last['date']) ? $last['date'] : 'N/A' ); ?></td>
                        <td><code style="font-size:10px;"><?php echo esc_html( isset($last['reference']) ? $last['reference'] : 'N/A' ); ?></code> (<?php echo esc_html( isset($last['status']) ? $last['status'] : '' ); ?>)</td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php else : ?>
                <p>No payments made yet.</p>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Count how many users were referred by a given user
     */
    private static function count_referrals( $user_id ) {
        $referred = new WP_User_Query( array(
            'meta_key'   => 'crane_referred_by',
            'meta_value' => $user_id,
        ) );
        return $referred->get_total();
    }
}
