<?php
/**
 * Crane Bets Uninstall
 *
 * This file runs when the plugin is deleted from the WordPress dashboard.
 * It ensures the database is left clean by removing all auto-created pages and options.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

// 1. Delete Options
$crane_options = array(
    'crane_page_locker_room',
    'crane_page_leaderboard',
    'crane_page_vip',
    'crane_page_dashboard',
    'crane_page_testimonies',
    'crane_page_lost_password',
    'crane_api_football_key',
    'crane_api_newsdata',
    'crane_paystack_secret_key',
    'crane_vip_product_id',
    'crane_purge_on_uninstall',
    'crane_demo_imported'
);

foreach ( $crane_options as $option ) {
    delete_option( $option );
}

// 2. Delete Auto-Created Pages
$pages_to_cleanup = array(
    'locker-room',
    'leaderboard',
    'vip',
    'dashboard'
);

foreach ( $pages_to_cleanup as $slug ) {
    $page = get_page_by_path( $slug );
    if ( $page ) {
        wp_delete_post( $page->ID, true ); // Force delete (bypass trash)
    }
}

// 3. Clean up Demo Data (Unconditional 'Full-System' Purge)
// Per policy: if yes delete, if no delete too - ensure no orphaned demo content remains.
$demo_posts = get_posts( array(
    'post_type'      => array( 'crane_prediction', 'crane_locker_post' ),
    'meta_key'       => 'crane_demo',
    'meta_value'     => 1,
    'posts_per_page' => -1,
    'fields'         => 'ids'
) );

foreach ( $demo_posts as $pid ) {
    wp_delete_post( $pid, true );
}

// 4. Clean up Sample Product if WooCommerce was used
if ( class_exists( 'WooCommerce' ) ) {
    $product = get_page_by_path( 'vip-prediction-access', OBJECT, 'product' );
    if ( $product ) {
        wp_delete_post( $product->ID, true );
    }
}

flush_rewrite_rules();
