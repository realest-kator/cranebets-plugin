<?php
/**
 * Template Service for Crane Bets
 * Handling Template loading and decoupling from Core
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Crane_Template_Service {
    public static function load_template_part( $slug, $args = [] ) {
        ob_start();
        $template = locate_template( "template-parts/{$slug}.php" );
        
        // 1. Theme Fallback Logic: If theme doesn't have it, look in the plugin (Issue #1 Fix)
        if ( ! $template ) {
            $plugin_path = dirname( dirname( __FILE__ ) ) . "/templates/{$slug}.php";
            if ( file_exists( $plugin_path ) ) {
                $template = $plugin_path;
            }
        }

        if ( ! $template ) {
            // Severe deadlock: No theme part AND no plugin part.
            echo "<div class='crane-theme-warning' style='padding: 15px; margin: 10px 0; background-color: #f8d7da; border: 1px solid #f5c6cb; color: #721c24; border-radius: 4px;'><p style='margin: 0;'><strong>Crane Bets Fatal UI Error:</strong> Missing template <code>{$slug}.php</code> in both Theme and Plugin. Please reinstall the Crane Bets core.</p></div>";
        } else {
            // Set global $crane_args for legacy templates if needed, though load_template handles it better.
            load_template( $template, false, $args );
        }
        return ob_get_clean();
    }
}
