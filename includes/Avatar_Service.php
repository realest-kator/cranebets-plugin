<?php
/**
 * Avatar Service for Crane Bets
 * Handles secure user display picture upload, storage, and global rendering.
 * 
 * Security Measures:
 * - File type validation via MIME check (not just extension)
 * - File size hard limit (2MB)
 * - Image reprocessing to strip EXIF/malicious payloads
 * - Nonce verification on all AJAX endpoints
 * - Login-required gate
 * - Filename sanitization (no user-controlled filenames stored)
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Crane_Avatar_Service {

    const MAX_FILE_SIZE = 2 * 1024 * 1024; // 2MB
    const AVATAR_SIZE   = 250; // px (square)
    const META_KEY      = 'crane_dp_url';

    public static function boot() {
        add_action( 'wp_ajax_crane_upload_avatar', array( __CLASS__, 'handle_avatar_upload' ) );
        add_action( 'wp_ajax_crane_remove_avatar', array( __CLASS__, 'handle_avatar_remove' ) );

        // Global WordPress avatar override
        add_filter( 'get_avatar_url', array( __CLASS__, 'filter_avatar_url' ), 99, 3 );
    }

    /**
     * Handle secure avatar upload via AJAX
     */
    public static function handle_avatar_upload() {
        check_ajax_referer( 'crane_security_nonce', 'security' );

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( array( 'message' => 'Login required.' ) );
        }

        if ( empty( $_FILES['crane_avatar'] ) ) {
            wp_send_json_error( array( 'message' => 'No file was uploaded.' ) );
        }

        $file = $_FILES['crane_avatar'];

        // Security Gate 1: Check for upload errors
        if ( $file['error'] !== UPLOAD_ERR_OK ) {
            wp_send_json_error( array( 'message' => 'Upload failed. Please try again.' ) );
        }

        // Security Gate 2: File size check (server-side, not just client-side)
        if ( $file['size'] > self::MAX_FILE_SIZE ) {
            wp_send_json_error( array( 'message' => 'File too large. Maximum 2MB allowed.' ) );
        }

        // Security Gate 3: MIME type validation via getimagesize (not relying on user-reported type)
        $image_info = @getimagesize( $file['tmp_name'] );
        if ( ! $image_info ) {
            wp_send_json_error( array( 'message' => 'Invalid image file. Only JPG, PNG, and WebP are allowed.' ) );
        }

        $allowed_mimes = array( IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP );
        if ( ! in_array( $image_info[2], $allowed_mimes, true ) ) {
            wp_send_json_error( array( 'message' => 'Invalid image format. Only JPG, PNG, and WebP are allowed.' ) );
        }

        // Security Gate 4: Reprocess image to strip EXIF data and any embedded payloads
        $editor = wp_get_image_editor( $file['tmp_name'] );
        if ( is_wp_error( $editor ) ) {
            wp_send_json_error( array( 'message' => 'Could not process image. Please try a different file.' ) );
        }

        // Resize to square (crop from center)
        $editor->resize( self::AVATAR_SIZE, self::AVATAR_SIZE, true );
        $editor->set_quality( 85 );

        // Generate a safe, non-guessable filename
        $user_id  = get_current_user_id();
        $ext      = ( $image_info[2] === IMAGETYPE_PNG ) ? 'png' : ( ( $image_info[2] === IMAGETYPE_WEBP ) ? 'webp' : 'jpg' );
        $filename = 'crane-dp-' . $user_id . '-' . wp_generate_password( 8, false ) . '.' . $ext;

        // Save to wp-content/uploads/crane-avatars/
        $upload_dir = wp_upload_dir();
        $avatar_dir = $upload_dir['basedir'] . '/crane-avatars';
        $avatar_url_base = $upload_dir['baseurl'] . '/crane-avatars';

        if ( ! file_exists( $avatar_dir ) ) {
            wp_mkdir_p( $avatar_dir );
            // Security: Block directory listing
            file_put_contents( $avatar_dir . '/index.php', '<?php // Silence is golden.' );
        }

        $save_path = $avatar_dir . '/' . $filename;
        $saved = $editor->save( $save_path );

        if ( is_wp_error( $saved ) ) {
            wp_send_json_error( array( 'message' => 'Failed to save avatar. Please try again.' ) );
        }

        // Delete old avatar file if it exists
        $old_url = get_user_meta( $user_id, self::META_KEY, true );
        if ( $old_url ) {
            $old_filename = basename( $old_url );
            $old_path = $avatar_dir . '/' . $old_filename;
            if ( file_exists( $old_path ) ) {
                @unlink( $old_path );
            }
        }

        // Save the URL to user meta
        $final_url = $avatar_url_base . '/' . $saved['file'];
        update_user_meta( $user_id, self::META_KEY, $final_url );

        wp_send_json_success( array(
            'message'    => 'Display picture updated!',
            'avatar_url' => $final_url,
        ) );
    }

    /**
     * Handle avatar removal via AJAX
     */
    public static function handle_avatar_remove() {
        check_ajax_referer( 'crane_security_nonce', 'security' );

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( array( 'message' => 'Login required.' ) );
        }

        $user_id = get_current_user_id();
        $old_url = get_user_meta( $user_id, self::META_KEY, true );

        if ( $old_url ) {
            // Delete the physical file
            $upload_dir = wp_upload_dir();
            $avatar_dir = $upload_dir['basedir'] . '/crane-avatars';
            $old_filename = basename( $old_url );
            $old_path = $avatar_dir . '/' . $old_filename;
            if ( file_exists( $old_path ) ) {
                @unlink( $old_path );
            }
            delete_user_meta( $user_id, self::META_KEY );
        }

        wp_send_json_success( array( 'message' => 'Display picture removed.' ) );
    }

    /**
     * Filter WordPress' get_avatar_url to serve custom DP globally
     */
    public static function filter_avatar_url( $url, $id_or_email, $args ) {
        $user_id = 0;

        if ( is_numeric( $id_or_email ) ) {
            $user_id = (int) $id_or_email;
        } elseif ( is_string( $id_or_email ) ) {
            $user = get_user_by( 'email', $id_or_email );
            if ( $user ) $user_id = $user->ID;
        } elseif ( $id_or_email instanceof WP_User ) {
            $user_id = $id_or_email->ID;
        } elseif ( $id_or_email instanceof WP_Comment ) {
            $user_id = (int) $id_or_email->user_id;
        }

        if ( $user_id ) {
            $custom_url = get_user_meta( $user_id, self::META_KEY, true );
            if ( $custom_url ) {
                return $custom_url;
            }
        }

        return $url;
    }

    /**
     * Global helper: Render a user's avatar HTML
     * Falls back to the styled initial circle if no DP is set.
     *
     * @param int    $user_id The user ID
     * @param int    $size    Pixel size (default 40)
     * @param string $color   Badge/tier color for fallback initial circle
     * @return string HTML
     */
    public static function get_avatar_html( $user_id, $size = 40, $color = '#888888' ) {
        $custom_url = get_user_meta( $user_id, self::META_KEY, true );

        if ( $custom_url ) {
            return sprintf(
                '<img src="%s" alt="DP" class="rounded-full object-cover" style="width: %dpx; height: %dpx; border: 1px solid %s40;" loading="lazy">',
                esc_url( $custom_url ),
                $size,
                $size,
                esc_attr( $color )
            );
        }

        // Fallback: Styled initial circle
        $user = get_userdata( $user_id );
        $name = $user ? $user->display_name : 'User';
        $initial = strtoupper( substr( $name, 0, 1 ) );

        return sprintf(
            '<div class="rounded-full flex items-center justify-center font-black uppercase" style="width: %dpx; height: %dpx; background: %s20; color: %s; border: 1px solid %s40; font-size: %dpx;">%s</div>',
            $size,
            $size,
            esc_attr( $color ),
            esc_attr( $color ),
            esc_attr( $color ),
            max( 10, (int)($size * 0.4) ),
            esc_html( $initial )
        );
    }
}
