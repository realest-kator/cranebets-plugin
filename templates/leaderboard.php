<?php
/**
 * Template part for the Accuracy Leaderboard
 * Sorted by accuracy ratio (wins / total predictions), not raw wins
 * 
 * @var $args array {
 *    @var $users WP_User[]
 * }
 */
$users = isset($args['users']) ? $args['users'] : array();
?>
<div class="bg-crane-glass border border-white/5 rounded-3xl p-8 backdrop-blur-xl">
    <h3 class="text-xs font-black text-white/60 uppercase tracking-[0.2em] mb-8">Accuracy Leaderboard</h3>
    <div class="space-y-4">
        <?php
        if ( ! empty( $users ) ) :
            foreach ( $users as $index => $user ) : 
                $accuracy = class_exists('Crane_User_Prediction_Service')
                    ? Crane_User_Prediction_Service::get_user_accuracy( $user->ID )
                    : array( 'wins' => 0, 'total' => 0, 'ratio' => 0, 'percentage' => 0, 'badge' => 'Novice', 'color' => '#888888' );
                ?>
                <div class="flex items-center justify-between p-4 bg-white/5 rounded-2xl border border-white/5 hover:bg-white/[0.08] transition-all">
                    <div class="flex items-center gap-4">
                        <span class="text-lg font-black italic text-white/60">#<?php echo $index + 1; ?></span>
                        <div class="w-10 h-10 rounded-full flex items-center justify-center font-black" style="background: <?php echo esc_attr($accuracy['color']); ?>20; color: <?php echo esc_attr($accuracy['color']); ?>;">
                            <?php echo esc_html( substr( $user->display_name, 0, 1 ) ); ?>
                        </div>
                        <div>
                            <h4 class="text-sm font-bold text-white"><?php echo esc_html( $user->display_name ); ?></h4>
                            <span class="text-[11px] font-black uppercase tracking-widest" style="color: <?php echo esc_attr($accuracy['color']); ?>;"><?php echo esc_html( $accuracy['badge'] ); ?></span>
                        </div>
                    </div>
                    <div class="text-right">
                        <span class="block text-xl font-black italic" style="color: <?php echo esc_attr($accuracy['color']); ?>;"><?php echo $accuracy['percentage']; ?>%</span>
                        <span class="text-[11px] font-black text-white/60 uppercase tracking-widest"><?php echo $accuracy['wins']; ?>W / <?php echo $accuracy['total']; ?>T</span>
                    </div>
                </div>
            <?php endforeach; 
        else : ?>
            <p class="text-white/60 text-center py-4 font-bold uppercase text-xs tracking-widest">No stats recorded yet</p>
        <?php endif; ?>
    </div>
</div>
