<?php
/**
 * Locker Service for Crane Bets
 * Handling Story submissions and Likes
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Crane_Locker_Service {
    
    /**
     * Boot Locker Service
     * Decoupling from Core God Object (Issue #2a)
     */
    public static function boot() {
        // AJAX Handlers
        add_action( 'wp_ajax_crane_submit_locker', array( __CLASS__, 'handle_submission' ) );
        add_action( 'wp_ajax_nopriv_crane_submit_locker', array( __CLASS__, 'handle_submission' ) );
        add_action( 'wp_ajax_crane_like_story', array( __CLASS__, 'handle_toggle_like' ) );
        add_action( 'wp_ajax_nopriv_crane_like_story', array( __CLASS__, 'handle_toggle_like' ) );
        add_action( 'wp_ajax_crane_search_stories', array( __CLASS__, 'handle_search_stories' ) );
        add_action( 'wp_ajax_nopriv_crane_search_stories', array( __CLASS__, 'handle_search_stories' ) );
        
        // Shortcodes
        add_shortcode( 'crane_locker_room', array( __CLASS__, 'render_locker_room_placeholder' ) );
        
        // Global Modal in Footer
        add_action( 'wp_footer', array( __CLASS__, 'render_global_locker_form' ) );
    }

    public static function handle_submission() {
        check_ajax_referer( 'crane_security_nonce', 'security' );
        if ( ! is_user_logged_in() ) wp_send_json_error( array( 'message' => 'Login required.' ) );
        
        $user_id = get_current_user_id();
        if ( get_user_meta( $user_id, 'crane_is_verified', true ) === '0' ) {
            wp_send_json_error( array( 'message' => 'Please verify your email to post updates.' ) );
        }

        $title   = isset( $_POST['post_title'] ) ? sanitize_text_field( $_POST['post_title'] ) : '';
        $content = isset( $_POST['post_content'] ) ? sanitize_textarea_field( $_POST['post_content'] ) : '';

        if ( empty( $title ) || empty( $content ) ) {
            wp_send_json_error( array( 'message' => 'Content cannot be empty.' ) );
        }

        // Anti-spam Mutex Lock
        $lock_key = 'crane_locker_lock_' . $user_id;
        if ( get_transient( $lock_key ) ) {
            wp_send_json_error( array( 'message' => 'Processing... please wait.' ) );
        }
        set_transient( $lock_key, true, 30 ); // 30 second global lock to prevent concurrency

        // Anti-spam: Limit posts to 1 per 2 minutes
        $last_post_time = (int) get_user_meta( $user_id, 'crane_last_locker_post', true );
        if ( time() - $last_post_time < 120 ) {
            delete_transient( $lock_key );
            wp_send_json_error( array( 'message' => 'Please wait a couple minutes before sharing another story.' ) );
        }

        $post_id = wp_insert_post( array(
            'post_title'   => $title,
            'post_content' => $content,
            'post_type'    => 'crane_locker_post',
            'post_status'  => 'publish',
            'post_author'  => $user_id
        ) );

        if ( is_wp_error( $post_id ) ) {
            delete_transient( $lock_key );
            wp_send_json_error( array( 'message' => 'Error saving story.' ) );
        }

        update_user_meta( $user_id, 'crane_last_locker_post', time() );
        delete_transient( $lock_key );

        wp_send_json_success( array( 'message' => 'Story shared successfully!' ) );
    }

    public static function handle_toggle_like() {
        global $wpdb;
        check_ajax_referer( 'crane_security_nonce', 'security' );
        if ( ! is_user_logged_in() ) {
            wp_send_json_error( array( 'message' => 'Login required.' ) );
        }

        $user_id = get_current_user_id();
        if ( ! current_user_can( 'manage_options' ) && get_user_meta( $user_id, 'crane_is_verified', true ) === '0' ) {
            wp_send_json_error( array( 'message' => 'Verify email to interact.' ) );
        }
        
        $post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
        if ( ! $post_id ) wp_send_json_error();

        // 1. Security: Verify Post Type (Harden against cross-post manipulation)
        $post_type = get_post_type( $post_id );
        if ( $post_type !== 'crane_locker_post' ) {
            wp_send_json_error( array( 'message' => 'Invalid interaction target.' ) );
        }
        
        $table = $wpdb->prefix . 'crane_likes';
        
        // 2. Check if already liked via dedicated table (Performance Fix)
        $existing = $wpdb->get_var( $wpdb->prepare( 
            "SELECT id FROM $table WHERE post_id = %d AND user_id = %d", 
            $post_id, $user_id 
        ) );
        
        if ( $existing ) {
            // Unlike: DELETE from table
            $wpdb->delete( $table, array( 'post_id' => $post_id, 'user_id' => $user_id ) );
            $status = 'unliked';
        } else {
            // Like: INSERT into table
            $wpdb->insert( $table, array(
                'post_id'    => $post_id,
                'user_id'    => $user_id,
                'created_at' => current_time( 'mysql' )
            ) );
            $status = 'liked';
        }
        
        // 3. Update cached count on post for fast feed rendering
        $new_count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table WHERE post_id = %d", $post_id ) );
        update_post_meta( $post_id, 'crane_like_count', $new_count );
        
        wp_send_json_success( array( 'count' => $new_count, 'status' => $status ) );
    }

    public static function is_user_liked( $post_id, $user_id ) {
        global $wpdb;
        $table = $wpdb->prefix . 'crane_likes';
        $existing = $wpdb->get_var( $wpdb->prepare( 
            "SELECT id FROM $table WHERE post_id = %d AND user_id = %d", 
            $post_id, $user_id 
        ) );
        return (bool) $existing;
    }

    public static function render_locker_room_placeholder( $atts ) { 
        $atts = shortcode_atts( array(
            'limit' => 10,
        ), $atts, 'crane_locker_room' );

        if ( is_user_logged_in() && ! current_user_can( 'manage_options' ) && get_user_meta( get_current_user_id(), 'crane_is_verified', true ) === '0' ) {
            return Crane_Template_Service::load_template_part( 'unverified-notice' );
        }

        $locker_posts = new WP_Query( array(
            'post_type'      => 'crane_locker_post',
            'posts_per_page' => (int) $atts['limit'],
            'post_status'    => 'publish'
        ) );

        return Crane_Template_Service::load_template_part( 'locker-feed', array( 'query' => $locker_posts ) );
    }

    public static function handle_search_stories() {
        $term = isset( $_POST['term'] ) ? sanitize_text_field( $_POST['term'] ) : '';
        
        $args = array(
            'post_type'      => 'crane_locker_post',
            'posts_per_page' => 15,
            'post_status'    => 'publish',
            's'              => $term
        );

        $query = new WP_Query( $args );
        
        ob_start();
        if ( $query->have_posts() ) {
            while ( $query->have_posts() ) {
                $query->the_post();
                echo Crane_Template_Service::load_template_part( 'locker-story-card' );
            }
            wp_reset_postdata();
        } else {
            echo '<p class="text-white/40 text-center py-12 px-6 border border-white/5 rounded-3xl bg-white/5 uppercase text-xs font-black tracking-widest">No matching stories found. Try a different keyword.</p>';
        }
        $html = ob_get_clean();

        wp_send_json_success( array( 'html' => $html ) );
    }

    public static function render_global_locker_form() {
        if ( ! is_user_logged_in() ) return;
        echo Crane_Template_Service::load_template_part( 'locker-modal' );
    }
}
