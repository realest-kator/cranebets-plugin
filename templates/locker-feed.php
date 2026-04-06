<?php
/**
 * Template part for the Locker Room Feed (with Stories + Picks tabs)
 * 
 * @var $args array {
 *    @var $query WP_Query
 * }
 */
$locker_posts = isset($args['query']) ? $args['query'] : null;
?>
    <!-- Header Actions -->
    <div class="flex items-center justify-between mb-8">
        <div>
            <h2 class="text-3xl font-black uppercase italic tracking-tighter mb-2">Locker <span class="text-crane-green font-bold">Room</span></h2>
            <p class="text-white/50 text-xs font-black uppercase tracking-widest leading-relaxed">The high-end platform for serious bettors in Nigeria.</p>
        </div>
        <?php if ( is_user_logged_in() ) : ?>
        <div class="flex items-center gap-3">
            <button onclick="openPredictionModal()" class="bg-crane-green/20 text-crane-green w-12 h-12 rounded-2xl flex items-center justify-center hover:scale-110 active:scale-95 transition-all border border-crane-green/30" title="Make a Prediction">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
            </button>
            <button onclick="openLockerModal()" class="bg-crane-green text-black w-12 h-12 rounded-2xl flex items-center justify-center hover:scale-110 active:scale-95 transition-all shadow-xl shadow-crane-green/20" title="Post a Story">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M12 4v16m8-8H4"></path></svg>
            </button>
        </div>
        <?php endif; ?>
    </div>

    <!-- Tabs -->
    <div class="flex gap-2 mb-8" id="crane-locker-tabs">
        <button class="crane-tab active bg-crane-green/20 text-crane-green px-6 py-2.5 rounded-xl text-xs font-black uppercase tracking-widest border border-crane-green/30 transition-all" data-tab="stories">
            Stories
        </button>
        <button class="crane-tab bg-white/5 text-white/60 px-6 py-2.5 rounded-xl text-xs font-black uppercase tracking-widest border border-white/10 hover:bg-white/10 transition-all" data-tab="picks">
            Predictions
        </button>
    </div>

    <!-- Stories Tab Content -->
    <div id="crane-tab-stories" class="crane-tab-content">
        <div class="space-y-8">
            <?php
            if ( $locker_posts && $locker_posts->have_posts() ) :
                while ( $locker_posts->have_posts() ) : $locker_posts->the_post();
                    $author_id = get_the_author_meta( 'ID' );
                    $accuracy = class_exists('Crane_User_Prediction_Service') ? Crane_User_Prediction_Service::get_user_accuracy( $author_id ) : null;
                    $badge = $accuracy ? $accuracy['badge'] : (get_user_meta( $author_id, 'crane_accuracy_level', true ) ?: 'Novice');
                    $badge_color = $accuracy ? $accuracy['color'] : '#888888';
                    $user_id = get_current_user_id();
                    $user_liked = ($user_id && class_exists('Crane_Locker_Service')) ? Crane_Locker_Service::is_user_liked(get_the_ID(), $user_id) : false;
            ?>
                <article class="bg-crane-glass border border-white/5 rounded-3xl p-8 backdrop-blur-lg hover:border-white/10 transition-all">
                    <header class="flex items-center justify-between mb-6">
                        <div class="flex items-center gap-4">
                            <?php 
                            if ( class_exists('Crane_Avatar_Service') ) {
                                echo Crane_Avatar_Service::get_avatar_html( $author_id, 40, $badge_color );
                            } else {
                            ?>
                            <div class="w-10 h-10 rounded-full flex items-center justify-center font-black uppercase" style="background: <?php echo esc_attr($badge_color); ?>20; color: <?php echo esc_attr($badge_color); ?>; border: 1px solid <?php echo esc_attr($badge_color); ?>40;">
                            <?php 
                            $like_count = (int) get_post_meta( get_the_ID(), 'crane_like_count', true );
                            echo esc_html( substr( get_the_author(), 0, 1 ) ); 
                            ?>
                            </div>
                            <?php } ?>
                            <div>
                                <h4 class="font-bold text-sm text-white"><?php the_author(); ?></h4>
                                <span class="text-[11px] font-black uppercase tracking-widest px-2 py-0.5 rounded-full ring-1" style="color: <?php echo esc_attr($badge_color); ?>; background: <?php echo esc_attr($badge_color); ?>15; ring-color: <?php echo esc_attr($badge_color); ?>30;"><?php echo esc_html($badge); ?></span>
                            </div>
                        </div>
                        <time class="text-xs text-white/60 font-bold uppercase"><?php echo human_time_diff( get_the_time('U'), current_time('timestamp') ); ?> ago</time>
                    </header>
                    <div class="prose prose-invert max-w-none text-white/70 mb-8">
                        <a href="<?php the_permalink(); ?>"><h3 class="text-xl font-bold text-white mb-2 hover:text-crane-green"><?php the_title(); ?></h3></a>
                        <?php the_excerpt(); ?>
                    </div>
                    <footer class="flex items-center gap-6 border-t border-white/5 pt-6">
                        <button class="crane-like-btn flex items-center gap-2 <?php echo $user_liked ? 'text-crane-green' : 'text-white/60'; ?> hover:text-crane-green transition-colors" data-post-id="<?php the_ID(); ?>">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z"></path></svg>
                            <span class="text-xs font-black uppercase tracking-widest"><span class="like-count"><?php echo $like_count; ?></span> Likes</span>
                        </button>
                        <a href="<?php the_permalink(); ?>#comments" class="text-xs font-black text-white/60 uppercase hover:text-white"><?php echo get_comments_number(); ?> Replies</a>
                    </footer>
                </article>
            <?php endwhile; wp_reset_postdata(); else: ?>
                <p class="text-white/60 text-center py-12">No stories yet.</p>
            <?php endif; ?>
        </div>
    </div>

    <!-- Picks Tab Content -->
    <div id="crane-tab-picks" class="crane-tab-content hidden">
        <?php echo do_shortcode('[crane_user_predictions filter="all"]'); ?>
    </div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const tabs = document.querySelectorAll('.crane-tab');
    tabs.forEach(function(tab) {
        tab.addEventListener('click', function() {
            // Deactivate all tabs
            tabs.forEach(function(t) {
                t.classList.remove('active', 'bg-crane-green/20', 'text-crane-green', 'border-crane-green/30');
                t.classList.add('bg-white/5', 'text-white/60', 'border-white/10');
            });
            // Activate clicked tab
            this.classList.add('active', 'bg-crane-green/20', 'text-crane-green', 'border-crane-green/30');
            this.classList.remove('bg-white/5', 'text-white/60', 'border-white/10');
            // Show/hide panels
            var target = this.dataset.tab;
            document.querySelectorAll('.crane-tab-content').forEach(function(p) { p.classList.add('hidden'); });
            document.getElementById('crane-tab-' + target).classList.remove('hidden');
        });
    });
});
</script>
