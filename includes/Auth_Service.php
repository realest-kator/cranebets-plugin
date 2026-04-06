<?php
/**
 * Auth Service for Crane Bets
 * Handling Registration, Login, and Verification
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Crane_Auth_Service {
    
    /**
     * Boot Authentication Service
     * Decoupling from Core God Object (Issue #2a)
     */
    public static function boot() {
        add_action( 'wp_ajax_crane_login', array( __CLASS__, 'handle_login' ) );
        add_action( 'wp_ajax_nopriv_crane_login', array( __CLASS__, 'handle_login' ) );
        add_action( 'wp_ajax_crane_register', array( __CLASS__, 'handle_registration' ) );
        add_action( 'wp_ajax_nopriv_crane_register', array( __CLASS__, 'handle_registration' ) );
        add_action( 'wp_ajax_crane_resend_verification', array( __CLASS__, 'handle_resend_verification' ) );
        add_action( 'wp_ajax_nopriv_crane_resend_verification', array( __CLASS__, 'handle_resend_verification' ) );
        add_action( 'wp_ajax_crane_update_profile', array( __CLASS__, 'handle_crane_update_profile' ) );
        add_action( 'wp_ajax_crane_forgot_password', array( __CLASS__, 'handle_forgot_password' ) );
        add_action( 'wp_ajax_nopriv_crane_forgot_password', array( __CLASS__, 'handle_forgot_password' ) );
        add_action( 'wp_ajax_crane_reset_password', array( __CLASS__, 'handle_reset_password' ) );
        add_action( 'wp_ajax_nopriv_crane_reset_password', array( __CLASS__, 'handle_reset_password' ) );
        add_action( 'init', array( __CLASS__, 'handle_verification' ) );
    }

    private static function check_rate_limit() {
        // Prevent IP Spoofing: Validate Proxy Headers (Medium Risk Fix)
        $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '127.0.0.1';
        if ( isset( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
            $ip = $_SERVER['HTTP_CF_CONNECTING_IP'];
        } elseif ( isset( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
            // Take the right-most IP (closest to server) to prevent client-side spoofing
            $ips = array_map( 'trim', explode( ',', $_SERVER['HTTP_X_FORWARDED_FOR'] ) );
            $ip = end( $ips );
            // Fallback if the extracted IP is private or invalid
            if ( ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
                $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '127.0.0.1';
            }
        }
        
        $transient_key = 'crane_rl_' . md5($ip);
        $attempts = (int) get_transient( $transient_key );
        if ( $attempts > 15 ) {
            wp_send_json_error( array( 'message' => 'Too many requests. Please try again in 5 minutes.' ) );
        }
        set_transient( $transient_key, $attempts + 1, 5 * MINUTE_IN_SECONDS );
    }

    public static function handle_registration() {
        check_ajax_referer( 'crane_security_nonce', 'security' );
        self::check_rate_limit();
        $username = isset( $_POST['username'] ) ? sanitize_user( $_POST['username'] ) : '';
        $email    = isset( $_POST['email'] ) ? sanitize_email( $_POST['email'] ) : '';
        $password = isset( $_POST['password'] ) ? $_POST['password'] : '';

        if ( ! is_email( $email ) ) wp_send_json_error( array( 'message' => 'Invalid email format.' ) );
        if ( strlen( $username ) < 3 ) wp_send_json_error( array( 'message' => 'Username too short.' ) );
        if ( email_exists( $email ) ) wp_send_json_error( array( 'message' => 'Email exists.' ) );
        if ( empty( $_POST['tos_agree'] ) ) wp_send_json_error( array( 'message' => 'You must confirm you are 18+ and agree to the TOS.' ) );

        $user_id = wp_create_user( $username, $password, $email );
        if ( is_wp_error( $user_id ) ) wp_send_json_error( array( 'message' => $user_id->get_error_message() ) );
        if ( ! $user_id ) wp_send_json_error( array( 'message' => 'User creation failed.' ) );

        // Handle Referral logic from cookie or POST backup
        $ref_id = 0;
        if ( isset( $_COOKIE['crane_ref'] ) ) {
            $ref_id = absint( $_COOKIE['crane_ref'] );
        } elseif ( isset( $_POST['crane_ref_backup'] ) ) {
            $ref_id = absint( $_POST['crane_ref_backup'] );
        }
        
        // Prevent circular/self-referral (Logic Escalation Fix)
        if ( $ref_id > 0 && $ref_id !== $user_id && get_userdata( $ref_id ) ) {
            update_user_meta( $user_id, 'crane_referred_by', $ref_id );
        }

        // Email Verification Token
        $token = wp_generate_password( 32, false );
        update_user_meta( $user_id, 'crane_verification_token', $token );
        update_user_meta( $user_id, 'crane_is_verified', 0 );

        $verify_url = add_query_arg( array( 'crane_verify' => $token, 'uid' => $user_id ), home_url('/') );
        $subject = 'Verify your Crane Bets Account';
        $message = "Welcome to Crane Bets! Please verify your email to unlock all features:\n\n" . $verify_url;
        wp_mail( $email, $subject, $message );

        // Return user_id to frontend so the resend verification flow works immediately (QA Fix)
        wp_send_json_success( array( 
            'message' => 'Registration successful! Please check your email to verify your account.',
            'user_id' => $user_id 
        ) );
    }

    public static function handle_login() {
        check_ajax_referer( 'crane_security_nonce', 'security' );
        self::check_rate_limit();
        $creds = array(
            'user_login'    => isset( $_POST['username'] ) ? sanitize_user( $_POST['username'] ) : '',
            'user_password' => isset( $_POST['password'] ) ? $_POST['password'] : '',
            'remember'      => true
        );
        $is_secure = is_ssl() || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
        $user = wp_signon( $creds, $is_secure );
        if ( is_wp_error( $user ) ) {
            wp_send_json_error( array( 'message' => 'Invalid elite credentials. Please try again.' ) );
        }

        // Check verification
        if ( get_user_meta( $user->ID, 'crane_is_verified', true ) === '0' ) {
            $user_id = $user->ID;
            wp_logout();
            wp_send_json_error( array( 
                'message' => 'Please verify your email before logging in.',
                'require_verify' => true,
                'user_id' => $user_id
            ) );
        }

        wp_send_json_success( array( 'message' => 'Login successful!' ) );
    }

    public static function handle_verification() {
        if ( isset( $_GET['crane_verify'] ) && isset( $_GET['uid'] ) ) {
            $user_id = absint( $_GET['uid'] );
            $token   = sanitize_text_field( $_GET['crane_verify'] );
            $saved_token = get_user_meta( $user_id, 'crane_verification_token', true );

            if ( $token === $saved_token ) {
                update_user_meta( $user_id, 'crane_is_verified', 1 );
                delete_user_meta( $user_id, 'crane_verification_token' );
                wp_redirect( add_query_arg( 'verified', '1', home_url('/') ) );
                exit;
            }
        }
    }

    public static function handle_resend_verification() {
        check_ajax_referer( 'crane_security_nonce', 'security' );
        $user_id = isset( $_POST['user_id'] ) ? absint( $_POST['user_id'] ) : 0;
        
        // Anti-Spam Throttle: 60 Seconds
        $last_sent = (int) get_user_meta( $user_id, 'crane_last_verification_sent', true );
        if ( $last_sent && ( current_time( 'timestamp' ) - $last_sent ) < 60 ) {
            wp_send_json_error( array( 'message' => 'Please wait 60 seconds before resending another email.' ) );
        }
        
        if ( ! $user_id || ! get_userdata( $user_id ) ) wp_send_json_error( array( 'message' => 'Invalid user.' ) );
        
        $is_verified = get_user_meta( $user_id, 'crane_is_verified', true );
        if ( $is_verified === '1' ) wp_send_json_error( array( 'message' => 'Account already verified.' ) );

        $token = get_user_meta( $user_id, 'crane_verification_token', true );
        if ( ! $token ) {
            $token = wp_generate_password( 32, false );
            update_user_meta( $user_id, 'crane_verification_token', $token );
        }

        $email = get_userdata( $user_id )->user_email;
        $verify_url = add_query_arg( array( 'crane_verify' => $token, 'uid' => $user_id ), home_url('/') );
        $subject = 'Verify your Crane Bets Account';
        $message = "Welcome back to Crane Bets! Please verify your email to unlock all features:\n\n" . $verify_url;
        wp_mail( $email, $subject, $message );

        update_user_meta( $user_id, 'crane_last_verification_sent', current_time( 'timestamp' ) );
        wp_send_json_success( array( 'message' => 'Verification email resent. Please check your inbox.' ) );
    }

    public static function handle_crane_update_profile() {
        check_ajax_referer( 'crane_security_nonce', 'security' );
        if ( ! is_user_logged_in() ) wp_send_json_error( array( 'message' => 'Login required.' ) );

        $user_id    = get_current_user_id();
        $user       = wp_get_current_user();
        $user_data  = array(
            'ID'           => $user_id,
        );

        $requires_reauth = false;

        // 1. Security: Strict field whitelist and role protection
        $allowed_fields = array( 'ID', 'display_name', 'user_email', 'user_pass' );
        foreach ( $_POST as $key => $val ) {
            // Block role, capabilities, or other administrative meta
            if ( in_array( $key, array( 'role', 'wp_capabilities', 'wp_user_level', 'crane_is_vip' ) ) ) {
                wp_send_json_error( array( 'message' => 'Unauthorized parameter modification.' ) );
            }
        }
        
        if ( isset( $_POST['user_email'] ) && ! empty( $_POST['user_email'] ) && strtolower($_POST['user_email']) !== strtolower($user->user_email) ) {
            $user_data['user_email'] = sanitize_email( $_POST['user_email'] );
            $requires_reauth = true;
        }

        if ( isset( $_POST['password'] ) && ! empty( $_POST['password'] ) ) {
            $user_data['user_pass'] = $_POST['password'];
            $requires_reauth = true;
        }

        if ( $requires_reauth ) {
            $current_pass = isset( $_POST['current_password'] ) ? $_POST['current_password'] : '';
            if ( empty($current_pass) || ! wp_check_password( $current_pass, $user->data->user_pass, $user->ID ) ) {
                wp_send_json_error( array( 'message' => 'Current password is required to change sensitive information.' ) );
            }
        }

        $result = wp_update_user( $user_data );
        if ( is_wp_error( $result ) ) wp_send_json_error( array( 'message' => strip_tags( $result->get_error_message() ) ) );

        wp_send_json_success( array( 'message' => 'Profile updated successfully.' ) );
    }

    public static function render_lost_password_form() {
        ob_start();
        ?>
        <form id="crane-forgot-password-form" class="space-y-6">
            <div>
                <label class="block text-xs font-black text-white/60 uppercase tracking-widest mb-3">Email Address</label>
                <input type="email" name="user_login" id="forgot-email" required class="w-full bg-white/5 border border-white/10 rounded-xl px-4 py-4 text-sm text-white focus:border-crane-green/50 transition-colors" placeholder="Enter your registered email">
            </div>
            <div id="forgot-message" class="text-xs font-bold uppercase tracking-[0.1em] min-h-[1.5em]"></div>
            <button type="submit" id="forgot-submit-btn" class="w-full bg-crane-green text-black py-4 rounded-xl text-[11px] font-black uppercase tracking-widest hover:scale-[1.02] active:scale-95 transition-all mt-4">Reset My Password</button>
        </form>
        <?php
        return ob_get_clean();
    }

    public static function handle_forgot_password() {
        check_ajax_referer( 'crane_security_nonce', 'security' );
        self::check_rate_limit();
        
        $user_login = isset( $_POST['user_login'] ) ? sanitize_email( $_POST['user_login'] ) : '';
        if ( empty( $user_login ) || ! is_email( $user_login ) ) {
            wp_send_json_error( array( 'message' => 'Valid email address is required.' ) );
        }

        $user_data = get_user_by( 'email', $user_login );
        if ( ! $user_data ) {
            // Security: Don't reveal if user exists. Say success anyway, matching exact WP text on success.
            wp_send_json_success( array( 'message' => 'Check your email for the recovery link!' ) );
        }

        $user_login = $user_data->user_login;
        $user_email = $user_data->user_email;
        $key = get_password_reset_key( $user_data );

        if ( is_wp_error( $key ) ) {
            wp_send_json_error( array( 'message' => 'Could not generate reset link.' ) );
        }

        $reset_url = add_query_arg( array( 'key' => $key, 'login' => rawurlencode( $user_login ) ), home_url( '/reset-password' ) );
        
        $message = "Someone has requested a password reset for Crane Bets:\r\n\r\n";
        $message .= $reset_url . "\r\n";

        if ( wp_mail( $user_email, 'Crane Bets Access Reset', $message ) ) {
            wp_send_json_success( array( 'message' => 'Check your email for the recovery link!' ) );
        } else {
            wp_send_json_error( array( 'message' => 'Email server failure.' ) );
        }
    }

    public static function handle_reset_password() {
        check_ajax_referer( 'crane_security_nonce', 'security' );
        self::check_rate_limit();

        $key    = isset($_POST['rp_key']) ? sanitize_text_field($_POST['rp_key']) : '';
        $login  = isset($_POST['rp_login']) ? sanitize_text_field($_POST['rp_login']) : '';
        $pass   = isset($_POST['password']) ? $_POST['password'] : '';

        if ( empty($key) || empty($login) || empty($pass) ) {
            wp_send_json_error( array( 'message' => 'Invalid request data.' ) );
        }

        $user = check_password_reset_key($key, $login);
        if ( is_wp_error($user) ) {
            wp_send_json_error( array( 'message' => 'Your password reset link is invalid or expired. Please request a new one.' ) );
        }

        // Standard WP password policy
        if ( strlen($pass) < 8 ) {
            wp_send_json_error( array( 'message' => 'Password must be at least 8 characters long.' ) );
        }

        reset_password($user, $pass);
        wp_send_json_success( array( 'message' => 'Password updated! Redirecting to login...' ) );
    }
}
