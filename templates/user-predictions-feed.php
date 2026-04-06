<?php
/**
 * Template part for the User Predictions Community Feed
 *
 * @var $args array {
 *    @var $query WP_Query
 * }
 */
$picks_query = isset($args['query']) ? $args['query'] : null;
?>
<div class="space-y-6">
    <?php
    if ( $picks_query && $picks_query->have_posts() ) :
        while ( $picks_query->have_posts() ) : $picks_query->the_post();
            $author_id = get_the_author_meta( 'ID' );
            $accuracy  = Crane_User_Prediction_Service::get_user_accuracy( $author_id );
            $match     = get_post_meta( get_the_ID(), '_crane_pick_match', true );
            $selection = get_post_meta( get_the_ID(), '_crane_pick_selection', true );
            $league    = get_post_meta( get_the_ID(), '_crane_pick_league', true );
            $odds      = get_post_meta( get_the_ID(), '_crane_pick_odds', true );
            $result    = get_post_meta( get_the_ID(), '_crane_pick_result', true );

            $sel_labels = array( 'home' => 'Home Win', 'draw' => 'Draw', 'away' => 'Away Win' );
            $sel_label  = isset($sel_labels[ $selection ]) ? $sel_labels[ $selection ] : $selection;
    ?>
        <article class="bg-crane-glass border border-white/5 rounded-3xl p-6 md:p-8 backdrop-blur-lg hover:border-white/10 transition-all">
            <!-- Header: User + Badge + Time -->
            <header class="flex items-center justify-between mb-5">
                <div class="flex items-center gap-3">
                    <?php 
                    if ( class_exists('Crane_Avatar_Service') ) {
                        echo Crane_Avatar_Service::get_avatar_html( $author_id, 40, $accuracy['color'] );
                    } else {
                    ?>
                    <div class="w-10 h-10 rounded-full flex items-center justify-center text-sm font-black uppercase" style="background: <?php echo esc_attr( $accuracy['color'] ); ?>20; color: <?php echo esc_attr( $accuracy['color'] ); ?>; border: 1px solid <?php echo esc_attr( $accuracy['color'] ); ?>40;">
                        <?php echo esc_html( substr( get_the_author(), 0, 1 ) ); ?>
                    </div>
                    <?php } ?>
                    <div>
                        <h4 class="font-bold text-sm text-white"><?php the_author(); ?></h4>
                        <span class="text-[11px] font-black uppercase tracking-widest px-2 py-0.5 rounded-full ring-1" style="color: <?php echo esc_attr( $accuracy['color'] ); ?>; background: <?php echo esc_attr( $accuracy['color'] ); ?>15; ring-color: <?php echo esc_attr( $accuracy['color'] ); ?>30;">
                            <?php echo esc_html( $accuracy['badge'] ); ?> · <?php echo $accuracy['percentage']; ?>%
                        </span>
                    </div>
                </div>
                <time class="text-xs text-white/60 font-bold uppercase"><?php echo human_time_diff( get_the_time('U'), current_time('timestamp') ); ?> ago</time>
            </header>

            <!-- Match + Pick -->
            <div class="mb-5">
                <?php if ( $league ) : ?>
                    <span class="text-[11px] font-black text-white/50 uppercase tracking-widest block mb-2"><?php echo esc_html( $league ); ?></span>
                <?php endif; ?>
                <h3 class="text-lg font-black text-white mb-2"><?php echo esc_html( $match ); ?></h3>
                <div class="flex items-center gap-3 flex-wrap">
                    <span class="bg-white/10 text-white/60 px-4 py-1.5 rounded-xl text-xs font-black uppercase tracking-widest">
                        Pick: <?php echo esc_html( $sel_label ); ?>
                    </span>
                    <?php if ( $odds ) : ?>
                        <span class="text-xs text-white/50 font-bold">@ <?php echo esc_html( $odds ); ?></span>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Result Status -->
            <footer class="border-t border-white/5 pt-4">
                <?php if ( $result === 'won' ) : ?>
                    <span class="inline-flex items-center gap-1.5 text-xs font-black uppercase tracking-widest text-green-400">
                        <span class="w-2 h-2 rounded-full bg-green-400"></span> Won
                    </span>
                <?php elseif ( $result === 'lost' ) : ?>
                    <span class="inline-flex items-center gap-1.5 text-xs font-black uppercase tracking-widest text-red-400">
                        <span class="w-2 h-2 rounded-full bg-red-400"></span> Lost
                    </span>
                <?php else : ?>
                    <span class="inline-flex items-center gap-1.5 text-xs font-black uppercase tracking-widest text-yellow-400">
                        <span class="w-2 h-2 rounded-full bg-yellow-400 animate-pulse"></span> Pending
                    </span>
                <?php endif; ?>
            </footer>
        </article>
    <?php endwhile; wp_reset_postdata(); else: ?>
        <p class="text-white/60 text-center py-12 text-sm">You haven't made any predictions yet.</p>
    <?php endif; ?>
</div>
