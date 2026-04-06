<?php
/**
 * User Prediction Service for Crane Bets
 * Handles user-submitted picks, settlement, and accuracy badge calculation.
 *
 * Badge tiers (wins ÷ total predictions):
 *   0.00–0.09  Novice
 *   0.10–0.19  Senior
 *   0.20–0.29  Enthusiast
 *   0.30–0.39  Expert
 *   0.40–1.00  Master
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Crane_User_Prediction_Service {
    
    /**
     * Boot User Prediction Service
     * Decoupling from Core God Object (Issue #2a)
     */
    public static function boot() {
        // AJAX Handlers
        add_action( 'wp_ajax_crane_submit_prediction', array( __CLASS__, 'handle_submit_prediction' ) );
        add_action( 'wp_ajax_nopriv_crane_submit_prediction', array( __CLASS__, 'handle_submit_prediction' ) );
        add_action( 'wp_ajax_crane_settle_prediction', array( __CLASS__, 'handle_settle_prediction' ) );
        add_action( 'wp_ajax_crane_search_picks', array( __CLASS__, 'handle_search_picks' ) );
        add_action( 'wp_ajax_nopriv_crane_search_picks', array( __CLASS__, 'handle_search_picks' ) );
        
        // Shortcodes
        add_shortcode( 'crane_user_predictions', array( __CLASS__, 'render_predictions_feed' ) );
        add_action( 'wp_ajax_crane_search_picks', array( __CLASS__, 'handle_search_picks' ) );
        add_action( 'wp_ajax_nopriv_crane_search_picks', array( __CLASS__, 'handle_search_picks' ) );
        
        // Global Modal in Footer
        add_action( 'wp_footer', array( __CLASS__, 'render_prediction_modal_global' ) );
    }

    /**
     * Badge tier map — ratio ranges to badge name
     */
    private static $tiers = array(
        array( 'min' => 0.00, 'max' => 0.099, 'name' => 'Novice',     'color' => '#888888' ),
        array( 'min' => 0.10, 'max' => 0.199, 'name' => 'Senior',     'color' => '#3b82f6' ),
        array( 'min' => 0.20, 'max' => 0.299, 'name' => 'Enthusiast', 'color' => '#a855f7' ),
        array( 'min' => 0.30, 'max' => 0.399, 'name' => 'Expert',     'color' => '#f59e0b' ),
        array( 'min' => 0.40, 'max' => 1.000, 'name' => 'Master',     'color' => '#00ff6a' ),
    );

    // ─── AJAX: Submit Prediction ────────────────────────────────────

    public static function handle_submit_prediction() {
        global $wpdb;
        check_ajax_referer( 'crane_security_nonce', 'security' );
        if ( ! is_user_logged_in() ) {
            wp_send_json_error( array( 'message' => 'You must be logged in to post a prediction.' ) );
        }

        $user_id = get_current_user_id();

        // Rate limiting
        $last_pick = get_user_meta( $user_id, 'crane_last_pick_time', true );
        if ( $last_pick && ( current_time( 'timestamp' ) - (int) $last_pick < 30 ) ) {
            wp_send_json_error( array( 'message' => 'Please wait 30 seconds before submitting another prediction.' ) );
        }
        update_user_meta( $user_id, 'crane_last_pick_time', current_time( 'timestamp' ) );

        // High-Concurrency Hardening: Removed verification wall for 1.0.0 Production Release.

        $match     = isset( $_POST['match'] )     ? sanitize_text_field( $_POST['match'] )     : '';
        $selection = isset( $_POST['selection'] )  ? sanitize_text_field( $_POST['selection'] ) : '';
        $league    = isset( $_POST['league'] )     ? sanitize_text_field( $_POST['league'] )    : '';
        $odds      = isset( $_POST['odds'] )       ? floatval( $_POST['odds'] )                 : 1.00;

        if ( empty( $match ) || empty( $selection ) ) {
            wp_send_json_error( array( 'message' => 'Match name and your pick are required.' ) );
        }

        // INSERT into Custom Table (Scalability Fix)
        $table = $wpdb->prefix . 'crane_predictions';
        $result = $wpdb->insert( $table, array(
            'user_id'    => $user_id,
            'match_name' => $match,
            'league'     => $league,
            'selection'  => $selection,
            'odds'       => $odds,
            'status'     => 'pending',
            'created_at' => current_time( 'mysql' )
        ) );

        if ( ! $result ) {
            wp_send_json_error( array( 'message' => 'Failed to save prediction to database.' ) );
        }

        wp_send_json_success( array( 'message' => 'Prediction submitted! 🔥' ) );
    }

    // ─── AJAX: Admin Settle Prediction ──────────────────────────────

    public static function handle_settle_prediction() {
        global $wpdb;
        check_ajax_referer( 'crane_security_nonce', 'security' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Admin only.' ) );
        }

        $pick_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0; // Using name post_id to maintain JS compatibility
        $result  = isset( $_POST['result'] )  ? sanitize_text_field( $_POST['result'] ) : '';

        if ( ! $pick_id || ! in_array( $result, array( 'won', 'lost' ), true ) ) {
            wp_send_json_error( array( 'message' => 'Invalid settlement data.' ) );
        }

        $table = $wpdb->prefix . 'crane_predictions';
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $pick_id ) );

        if ( ! $row ) {
            wp_send_json_error( array( 'message' => 'Prediction record not found.' ) );
        }

        if ( $row->status !== 'pending' ) {
            wp_send_json_error( array( 'message' => 'This prediction is already settled.' ) );
        }

        // Update result
        $wpdb->update( $table, 
            array( 'status' => $result, 'settled_at' => current_time( 'mysql' ) ),
            array( 'id' => $pick_id )
        );

        // Recalculate user accuracy
        self::recalculate_user_accuracy( (int) $row->user_id );

        wp_send_json_success( array( 'message' => "Prediction marked as {$result}." ) );
    }

    // ─── Accuracy Calculation ───────────────────────────────────────

    /**
     * Recalculate a user's accuracy ratio and badge based on all their settled picks
     */
    public static function recalculate_user_accuracy( $user_id ) {
        global $wpdb;
        $table = $wpdb->prefix . 'crane_predictions';

        // Count total settled predictions (won + lost, NOT pending) using optimized custom table SQL
        $query = $wpdb->prepare( "
            SELECT 
                SUM(CASE WHEN status = 'won' THEN 1 ELSE 0 END) as wins,
                SUM(CASE WHEN status = 'lost' THEN 1 ELSE 0 END) as losses
            FROM $table
            WHERE user_id = %d
              AND status IN ('won', 'lost')
        ", $user_id );

        $results = $wpdb->get_row( $query );
        
        $wins   = (int) ( isset($results->wins) ? $results->wins : 0 );
        $losses = (int) ( isset($results->losses) ? $results->losses : 0 );
        $total  = $wins + $losses;
        $ratio  = ( $total > 0 ) ? round( $wins / $total, 4 ) : 0;

        // Update user meta
        update_user_meta( $user_id, 'crane_wins', $wins );
        update_user_meta( $user_id, 'crane_total_predictions', $total );
        update_user_meta( $user_id, 'crane_accuracy_ratio', $ratio );
        update_user_meta( $user_id, 'crane_accuracy_level', self::get_badge_name( $ratio ) );
    }

    /**
     * Get badge name from accuracy ratio
     */
    public static function get_badge_name( $ratio ) {
        foreach ( self::$tiers as $tier ) {
            if ( $ratio >= $tier['min'] && $ratio <= $tier['max'] ) {
                return $tier['name'];
            }
        }
        return 'Novice';
    }

    /**
     * Get badge color from accuracy ratio
     */
    public static function get_badge_color( $ratio ) {
        foreach ( self::$tiers as $tier ) {
            if ( $ratio >= $tier['min'] && $ratio <= $tier['max'] ) {
                return $tier['color'];
            }
        }
        return '#888888';
    }

    /**
     * Get full accuracy data for a user
     */
    public static function get_user_accuracy( $user_id ) {
        if ( user_can( $user_id, 'manage_options' ) ) {
            return array(
                'wins'       => 999,
                'losses'     => 0,
                'total'      => 999,
                'ratio'      => 1.0,
                'percentage' => 100,
                'badge'      => 'Master',
                'color'      => '#00ff6a',
            );
        }

        $wins    = (int) get_user_meta( $user_id, 'crane_wins', true );
        $total   = (int) get_user_meta( $user_id, 'crane_total_predictions', true );
        $ratio   = (float) get_user_meta( $user_id, 'crane_accuracy_ratio', true );
        $badge   = get_user_meta( $user_id, 'crane_accuracy_level', true ) ?: 'Novice';

        return array(
            'wins'       => $wins,
            'losses'     => $total - $wins,
            'total'      => $total,
            'ratio'      => $ratio,
            'percentage' => $total > 0 ? round( $ratio * 100, 1 ) : 0,
            'badge'      => $badge,
            'color'      => self::get_badge_color( $ratio ),
        );
    }

    // ─── Shortcode: Community Predictions Feed ──────────────────────

    public static function render_predictions_feed( $atts ) {
        global $wpdb;
        $atts = shortcode_atts( array( 'limit' => 15, 'filter' => 'mine' ), $atts, 'crane_user_predictions' );
        $table = $wpdb->prefix . 'crane_predictions';
        $user_id = get_current_user_id();

        $limit = (int) $atts['limit'];
        $filter = $atts['filter'];
        
        if ( $filter === 'all' ) {
            $picks = $wpdb->get_results( $wpdb->prepare( "
                SELECT * FROM $table 
                ORDER BY created_at DESC 
                LIMIT %d
            ", $limit ) );
        } else {
            if ( ! $user_id ) return '';
            $picks = $wpdb->get_results( $wpdb->prepare( "
                SELECT * FROM $table 
                WHERE user_id = %d
                ORDER BY created_at DESC 
                LIMIT %d
            ", $user_id, $limit ) );
        }

        return Crane_Template_Service::load_template_part( 'user-predictions-feed', array( 'picks' => $picks, 'filter' => $filter ) );
    }
    
    /**
     * Render the admin management page for User Predictions
     */
    public static function render_admin_management_page() {
        global $wpdb;
        $table = $wpdb->prefix . 'crane_predictions';
        
        // Paging
        $per_page = 20;
        $page = isset( $_GET['paged'] ) ? max( 1, intval( $_GET['paged'] ) ) : 1;
        $offset = ( $page - 1 ) * $per_page;

        $total = $wpdb->get_var( "SELECT COUNT(*) FROM $table" );
        $predictions = $wpdb->get_results( $wpdb->prepare( "
            SELECT * FROM $table 
            ORDER BY created_at DESC 
            LIMIT %d OFFSET %d
        ", $per_page, $offset ) );
        ?>
        <div class="wrap">
            <h1>User Predictions Management</h1>
            <p>Settle user-submitted picks to update their accuracy levels and badges.</p>

            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th width="150">User</th>
                        <th width="200">Match</th>
                        <th>Selection</th>
                        <th>Odds</th>
                        <th>Status</th>
                        <th>Submitted</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( $predictions ) : foreach ( $predictions as $p ) : 
                        $user = get_userdata( $p->user_id );
                        $labels = array( 'home' => '🏠 Home', 'draw' => '🤝 Draw', 'away' => '✈️ Away' );
                    ?>
                    <tr>
                        <td>
                            <strong><?php echo $user ? esc_html( $user->display_name ) : 'Unknown'; ?></strong>
                            <br><small><?php echo $user ? esc_html( $user->user_email ) : ''; ?></small>
                        </td>
                        <td>
                            <strong><?php echo esc_html( $p->match_name ); ?></strong>
                            <?php if ( $p->league ) : ?><br><small><?php echo esc_html( $p->league ); ?></small><?php endif; ?>
                        </td>
                        <td><?php echo esc_html( isset($labels[ $p->selection ]) ? $labels[ $p->selection ] : $p->selection ); ?></td>
                        <td><?php echo esc_html( $p->odds ); ?></td>
                        <td>
                            <?php if ( $p->status === 'won' ) : ?>
                                <span style="color:#22c55e; font-weight:bold;">✅ Won</span>
                            <?php elseif ( $p->status === 'lost' ) : ?>
                                <span style="color:#ef4444; font-weight:bold;">❌ Lost</span>
                            <?php else : ?>
                                <span style="color:#f59e0b;">⏳ Pending</span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo human_time_diff( strtotime( $p->created_at ), current_time('timestamp') ); ?> ago</td>
                        <td>
                            <?php if ( $p->status === 'pending' ) : ?>
                                <button type="button" class="button button-small crane-settle-db-btn" data-result="won" data-id="<?php echo $p->id; ?>" style="background:#22c55e; color:#fff; border:none;">Won</button>
                                <button type="button" class="button button-small crane-settle-db-btn" data-result="lost" data-id="<?php echo $p->id; ?>" style="background:#ef4444; color:#fff; border:none;">Lost</button>
                            <?php else : ?>
                                <span class="description">Settled</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; else : ?>
                        <tr><td colspan="7">No records found.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>

            <script>
                jQuery(document).ready(function($) {
                    $('.crane-settle-db-btn').on('click', function() {
                        var btn = $(this);
                        var result = btn.data('result');
                        var id = btn.data('id');
                        if ( ! confirm('Mark as ' + result.toUpperCase() + '?') ) return;
                        
                        btn.prop('disabled', true).text('...');
                        $.post(ajaxurl, {
                            action: 'crane_settle_prediction',
                            post_id: id, // Keep key post_id for backend compatibility
                            result: result,
                            security: '<?php echo wp_create_nonce( 'crane_security_nonce' ); ?>'
                        }, function(response) {
                            if (response.success) {
                                location.reload();
                            } else {
                                alert(response.data.message || 'Error');
                                btn.prop('disabled', false).text(result);
                            }
                        });
                    });
                });
            </script>

            <div class="tablenav bottom">
                <div class="tablenav-pages">
                    <?php
                    echo paginate_links( array(
                        'base' => add_query_arg( 'paged', '%#%' ),
                        'format' => '',
                        'prev_text' => __('&laquo;'),
                        'next_text' => __('&raquo;'),
                        'total' => ceil( $total / $per_page ),
                        'current' => $page
                    ) );
                    ?>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Render the prediction modal globally via wp_footer
     */
    public static function render_prediction_modal_global() {
        if ( ! is_user_logged_in() ) return;
        echo Crane_Template_Service::load_template_part( 'prediction-submit-modal' );
    }

    public static function handle_search_picks() {
        global $wpdb;
        $term = isset( $_POST['term'] ) ? sanitize_text_field( $_POST['term'] ) : '';
        $table = $wpdb->prefix . 'crane_predictions';
        
        $results = $wpdb->get_results( $wpdb->prepare( "
            SELECT * FROM $table 
            WHERE (match_name LIKE %s OR league LIKE %s OR selection LIKE %s)
            ORDER BY created_at DESC 
            LIMIT 25
        ", '%' . $wpdb->esc_like($term) . '%', '%' . $wpdb->esc_like($term) . '%', '%' . $wpdb->esc_like($term) . '%' ) );

        ob_start();
        if ( $results ) {
            foreach ( $results as $pick ) {
                echo self::render_prediction_row( (array) $pick );
            }
        } else {
            echo '<div class="text-white/40 text-center py-12 px-6 border border-white/5 rounded-3xl bg-white/5 uppercase text-xs font-black tracking-widest">No matching predictions found. Try a different keyword.</div>';
        }
        $html = ob_get_clean();

        wp_send_json_success( array( 'html' => $html ) );
    }

    private static function render_prediction_row( $pick ) {
        // Shared logic to render a single prediction row
        $status_class = 'text-white/40';
        if($pick['status'] === 'won') $status_class = 'text-crane-green';
        if($pick['status'] === 'lost') $status_class = 'text-red-500';
        
        ob_start();
        ?>
        <div class="bg-white/5 border border-white/5 rounded-2xl p-6 flex flex-col sm:flex-row items-center justify-between gap-6 hover:border-white/10 transition-all group">
            <div class="flex items-center gap-4">
                <div class="w-10 h-10 rounded-full bg-white/5 flex items-center justify-center text-white/20 font-black uppercase border border-white/5">
                    <?php echo esc_html(substr($pick['league'], 0, 1)); ?>
                </div>
                <div>
                    <h4 class="font-bold text-sm text-white"><?php echo esc_html($pick['match_name']); ?></h4>
                    <span class="text-[10px] text-white/40 uppercase font-black tracking-widest"><?php echo esc_html($pick['league']); ?></span>
                </div>
            </div>
            <div class="text-center sm:text-right">
                <div class="text-xs font-black uppercase tracking-widest text-crane-green mb-1"><?php echo esc_html($pick['selection']); ?></div>
                <div class="text-[10px] font-bold uppercase tracking-widest <?php echo $status_class; ?>"><?php echo strtoupper($pick['status']); ?></div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
}
