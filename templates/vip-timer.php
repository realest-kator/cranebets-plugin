<?php
/**
 * Template part for the VIP Timer with Motivation Curve
 * 
 * @var $args array {
 *    @var $timer int Hours tracked
 *    @var $progress float Motivation curve percentage (0-100)
 *    @var $is_vip bool Whether user has VIP access
 *    @var $source string VIP source ('timer' or 'purchase')
 * }
 */
$timer    = isset($args['timer']) ? $args['timer'] : 0;
$progress = isset($args['progress']) ? $args['progress'] : 0;
$is_vip   = isset($args['is_vip']) ? $args['is_vip'] : false;
$source   = isset($args['source']) ? $args['source'] : '';

$circle_circumference = 552.92;
$circle_offset = $circle_circumference * (1 - ($progress / 100));
?>
<div class="bg-crane-glass border border-white/5 rounded-3xl p-8 md:p-12 text-center backdrop-blur-xl">
    
    <?php if ( $is_vip ) : ?>
    <!-- VIP UNLOCKED STATE -->
    <div class="mb-6">
        <span class="inline-block bg-crane-green/20 text-crane-green px-4 py-2 rounded-xl text-xs font-black uppercase tracking-widest border border-crane-green/30">
            ⚡ VIP ACTIVE <?php echo $source === 'purchase' ? '(Premium)' : '(Earned)'; ?>
        </span>
    </div>
    <h3 class="text-2xl font-black uppercase italic text-white mb-4">Elite <span class="text-crane-green">Access</span> Unlocked</h3>
    <p class="text-white/60 text-xs uppercase tracking-widest">You're receiving VIP predictions via email daily.</p>
    
    <?php else : ?>
    <!-- PROGRESS STATE -->
    <h3 class="text-xs font-black text-white/60 uppercase tracking-[0.2em] mb-8">VIP Progress</h3>
    
    <!-- Circular Progress -->
    <div class="relative inline-block mb-8">
        <svg class="w-48 h-48 -rotate-90">
            <circle cx="96" cy="96" r="88" stroke="currentColor" stroke-width="8" fill="transparent" class="text-white/5" />
            <circle cx="96" cy="96" r="88" stroke="currentColor" stroke-width="8" fill="transparent" class="text-crane-green transition-all duration-1000" stroke-linecap="round" stroke-dasharray="<?php echo $circle_circumference; ?>" stroke-dashoffset="<?php echo $circle_offset; ?>" id="vip-circle" />
        </svg>
        <div class="absolute inset-0 flex flex-col items-center justify-center">
            <span class="text-4xl font-black italic text-white" id="vip-progress-display"><?php echo $progress; ?></span>
            <span class="text-lg font-black text-white/50">%</span>
            <span class="text-[11px] font-black text-white/60 uppercase tracking-widest mt-1">to VIP</span>
        </div>
    </div>

    <!-- Hours Counter -->
    <div class="mb-8">
        <span class="text-2xl font-black text-white" id="vip-hours-display"><?php echo $timer; ?></span>
        <span class="text-white/50 text-sm font-bold"> / 400 hours</span>
    </div>

    <!-- Progress Bar (linear) -->
    <div class="max-w-sm mx-auto mb-8">
        <div class="w-full bg-white/5 rounded-full h-2 overflow-hidden">
            <div class="bg-gradient-to-r from-crane-green/50 to-crane-green h-full rounded-full transition-all duration-1000" style="width: <?php echo $progress; ?>%" id="vip-bar"></div>
        </div>
        <div class="flex justify-between mt-2">
            <span class="text-[11px] text-white/60 uppercase font-bold">0h</span>
            <span class="text-[11px] text-white/60 uppercase font-bold">400h</span>
        </div>
    </div>

    <!-- Stats -->
    <div class="grid grid-cols-2 gap-4 max-w-sm mx-auto">
        <div class="bg-white/5 p-4 rounded-2xl border border-white/5">
            <span class="block text-xs font-black text-crane-green uppercase mb-1" id="vip-tier-label">
                <?php
                if ( $progress >= 75 ) echo 'Almost There';
                elseif ( $progress >= 50 ) echo 'Halfway';
                elseif ( $progress >= 25 ) echo 'Rising';
                else echo 'Getting Started';
                ?>
            </span>
            <span class="text-[11px] text-white/60 uppercase font-bold">Status</span>
        </div>
        <div class="col-span-2 mt-2">
            <a href="<?php echo home_url('/vip'); ?>" class="block w-full bg-gradient-to-r from-crane-green/20 to-crane-green/10 hover:from-crane-green hover:to-crane-green hover:text-black hover:shadow-[0_0_30px_rgba(34,197,94,0.3)] transition-all p-4 rounded-2xl border border-crane-green/30 text-center group cursor-pointer">
                <span class="block text-xs font-black uppercase tracking-widest text-crane-green group-hover:text-black/70 mb-1">Buy Premium Access</span>
                <span class="block text-xl font-black italic text-white group-hover:text-black tracking-tighter">₦75,000</span>
            </a>
        </div>
    </div>
    <?php endif; ?>
</div>
