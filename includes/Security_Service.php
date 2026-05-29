<?php
/**
 * Crane_Security_Service
 * 
 * Handles obfuscation and hardening to hide WordPress fingerprints.
 * Built with safety-first logic to prevent any side effects or fatal errors.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Crane_Security_Service {

    /**
     * Core boot loader. 
     * Uses defensive checks to ensure standard WordPress functionality is present.
     */
    public static function boot() {
        if ( ! function_exists( 'add_action' ) || ! function_exists( 'add_filter' ) ) {
            return;
        }

        // 1. Remove standard WordPress fingerprints from the head
        add_action( 'init', array( __CLASS__, 'remove_wp_fingerprints' ) );

        // 2. Remove WordPress version from scripts and styles
        add_filter( 'script_loader_src', array( __CLASS__, 'remove_wp_ver_string' ), 9999 );
        add_filter( 'style_loader_src', array( __CLASS__, 'remove_wp_ver_string' ), 9999 );

        // 3. Hide login errors (prevent username discovery)
        add_filter( 'login_errors', '__return_null' );

        // 4. Block access to sensitive files via WP hooks (Frontend Only)
        if ( ! is_admin() ) {
            add_action( 'init', array( __CLASS__, 'block_sensitive_files' ) );
        }

        // 5. Remove 'wp-block' styles (Project uses custom Tailwind)
        add_action( 'wp_enqueue_scripts', array( __CLASS__, 'remove_gutenberg_css' ), 100 );
    }

    /**
     * Removes meta tags and links that identify the site as WordPress.
     */
    public static function remove_wp_fingerprints() {
        // Remove generator name and version
        remove_action( 'wp_head', 'wp_generator' );
        add_filter( 'the_generator', '__return_empty_string' );

        // Remove RSD (Really Simple Discovery) link
        remove_action( 'wp_head', 'rsd_link' );

        // Remove Windows Live Writer link
        remove_action( 'wp_head', 'wlwmanifest_link' );

        // Remove REST API link from head and headers
        remove_action( 'wp_head', 'rest_output_link_wp_head', 10 );
        remove_action( 'template_redirect', 'rest_output_link_header', 11 );

        // Remove oEmbed links
        remove_action( 'wp_head', 'wp_oembed_add_discovery_links' );
        remove_action( 'wp_head', 'wp_oembed_add_host_js' );

        // Remove Emoji detection (Standard WP feature, often used by detectors)
        remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
        remove_action( 'admin_print_scripts', 'print_emoji_detection_script' );
        remove_action( 'wp_print_styles', 'print_emoji_styles' );
        remove_action( 'admin_print_styles', 'print_emoji_styles' );
        remove_filter( 'the_content_feed', 'wp_staticize_emoji' );
        remove_filter( 'comment_text_rss', 'wp_staticize_emoji' );
        remove_filter( 'wp_mail', 'wp_staticize_emoji_for_email' );
    }

    /**
     * Strips '?ver=x.x.x' from scripts and styles.
     * Only strips if the version matches the current WP version.
     */
    public static function remove_wp_ver_string( $src ) {
        if ( is_string( $src ) && strpos( $src, 'ver=' . get_bloginfo( 'version' ) ) !== false ) {
            $src = remove_query_arg( 'ver', $src );
        }
        return $src;
    }

    /**
     * Block direct access to common identifying files.
     * Uses a soft redirect to home.
     */
    public static function block_sensitive_files() {
        if ( is_admin() || ! isset( $_SERVER['REQUEST_URI'] ) ) {
            return;
        }

        $request_uri = $_SERVER['REQUEST_URI'];

        // Targeted check for specific WP-detecting files
        if ( preg_match( '/(readme\.html|license\.txt|wp-config\.php)/i', $request_uri ) ) {
            wp_safe_redirect( home_url() );
            exit;
        }
    }

    /**
     * Dequeue standard library CSS since theme uses custom Tailwind.
     */
    public static function remove_gutenberg_css() {
        wp_dequeue_style( 'wp-block-library' );
        wp_dequeue_style( 'wp-block-library-theme' );
        wp_dequeue_style( 'wc-block-style' );
    }
}
