<?php
/**
 * Template part for the User Dashboard
 * 
 * @var $args array {
 *    @var $user WP_User
 *    @var $wins int
 *    @var $badge string
 *    @var $timer int
 *    @var $accuracy array (from User_Prediction_Service)
 * }
 */
$user     = isset($args['user']) ? $args['user'] : null;
$wins     = isset($args['wins']) ? $args['wins'] : 0;
$badge    = isset($args['badge']) ? $args['badge'] : 'Novice';
$timer    = isset($args['timer']) ? $args['timer'] : 400;
$accuracy = isset($args['accuracy']) ? $args['accuracy'] : array(
    'wins' => 0, 'losses' => 0, 'total' => 0,
    'ratio' => 0, 'percentage' => 0, 'badge' => 'Novice', 'color' => '#888888'
);

if ( ! $user ) return;
?>
<div class="grid grid-cols-1 md:grid-cols-2 gap-8 mb-12">
    <!-- Profile Card -->
    <div class="bg-white/5 border border-white/10 rounded-3xl p-8 backdrop-blur-xl">
        <h3 class="text-xs font-black text-white/60 uppercase tracking-[0.2em] mb-8">Account Profile</h3>
        <div class="flex items-center gap-6 mb-8">
            <div class="w-20 h-20 rounded-full flex items-center justify-center text-3xl font-black border" style="background: <?php echo esc_attr($accuracy['color']); ?>15; color: <?php echo esc_attr($accuracy['color']); ?>; border-color: <?php echo esc_attr($accuracy['color']); ?>40;">
                <?php echo esc_html( substr( $user->display_name, 0, 1 ) ); ?>
            </div>
            <div>
                <h4 class="text-2xl font-black text-white"><?php echo esc_html( $user->display_name ); ?></h4>
                <p class="text-white/60 text-sm"><?php echo esc_html( $user->user_email ); ?></p>
            </div>
        </div>
        <div class="flex flex-wrap gap-4">
            <span class="px-4 py-2 rounded-xl text-xs font-black uppercase border" style="color: <?php echo esc_attr($accuracy['color']); ?>; background: <?php echo esc_attr($accuracy['color']); ?>15; border-color: <?php echo esc_attr($accuracy['color']); ?>30;">
                <?php echo esc_html($accuracy['badge']); ?>
            </span>
            <span class="px-4 py-2 bg-white/5 rounded-xl text-xs font-black text-white/60 uppercase border border-white/10">Member since <?php echo date('Y', strtotime($user->user_registered)); ?></span>
        </div>
    </div>

    <!-- Stats Card -->
    <div class="bg-crane-glass border border-white/5 rounded-3xl p-8 backdrop-blur-xl">
        <h3 class="text-xs font-black text-white/60 uppercase tracking-[0.2em] mb-6">Prediction Stats</h3>
        <div class="grid grid-cols-2 gap-4">
            <div class="p-5 bg-white/5 rounded-2xl border border-white/5">
                <span class="block text-3xl font-black italic mb-1" style="color: <?php echo esc_attr($accuracy['color']); ?>;"><?php echo $accuracy['percentage']; ?>%</span>
                <span class="text-xs text-white/60 uppercase font-black tracking-widest">Win Rate</span>
            </div>
            <div class="p-5 bg-white/5 rounded-2xl border border-white/5">
                <span class="block text-3xl font-black italic text-crane-green mb-1"><?php echo $accuracy['wins']; ?></span>
                <span class="text-xs text-white/60 uppercase font-black tracking-widest">Wins</span>
            </div>
            <div class="p-5 bg-white/5 rounded-2xl border border-white/5">
                <span class="block text-3xl font-black italic text-white mb-1"><?php echo $accuracy['total']; ?></span>
                <span class="text-xs text-white/60 uppercase font-black tracking-widest">Total Picks</span>
            </div>
            <div class="p-5 bg-white/5 rounded-2xl border border-white/5">
                <span class="block text-3xl font-black italic text-white mb-1"><?php echo $timer; ?></span>
                <span class="text-xs text-white/60 uppercase font-black tracking-widest">VIP Hours</span>
            </div>
        </div>
    </div>
</div>

<!-- Accuracy Progress Bar -->
<div class="bg-white/5 border border-white/10 rounded-3xl p-8 backdrop-blur-xl mb-12">
    <div class="flex items-center justify-between mb-4">
        <h3 class="text-xs font-black text-white/60 uppercase tracking-[0.2em]">Accuracy Level</h3>
        <span class="text-xs font-black uppercase tracking-widest" style="color: <?php echo esc_attr($accuracy['color']); ?>;"><?php echo esc_html($accuracy['badge']); ?></span>
    </div>
    <div class="flex gap-1 mb-3">
        <?php
        $tiers = array(
            array('name' => 'Novice',     'min' => 0,    'color' => '#888888'),
            array('name' => 'Senior',     'min' => 0.10, 'color' => '#3b82f6'),
            array('name' => 'Enthusiast', 'min' => 0.20, 'color' => '#a855f7'),
            array('name' => 'Expert',     'min' => 0.30, 'color' => '#f59e0b'),
            array('name' => 'Master',     'min' => 0.40, 'color' => '#00ff6a'),
        );
        foreach ($tiers as $i => $tier) :
            $is_active = $accuracy['ratio'] >= $tier['min'];
            $bg = $is_active ? $tier['color'] : 'rgba(255,255,255,0.05)';
        ?>
            <div class="flex-1 h-2 rounded-full transition-all" style="background: <?php echo $bg; ?>;" title="<?php echo $tier['name']; ?>: <?php echo ($tier['min'] * 100); ?>%+"></div>
        <?php endforeach; ?>
    </div>
    <div class="flex justify-between">
        <?php foreach ($tiers as $tier) : ?>
            <span class="text-[11px] font-bold uppercase tracking-widest" style="color: <?php echo $tier['color']; ?>40;"><?php echo $tier['name']; ?></span>
        <?php endforeach; ?>
    </div>
</div>

<!-- Forms Section -->
<div class="bg-white/5 border border-white/10 rounded-3xl p-8 backdrop-blur-xl">
    <h3 class="text-xs font-black text-white/60 uppercase tracking-[0.2em] mb-8">Update Security</h3>
    <form id="crane-profile-form" class="space-y-6">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <div>
                <label class="block text-xs font-black text-white/60 uppercase tracking-widest mb-3">Display Name</label>
                <input type="text" name="display_name" value="<?php echo esc_attr($user->display_name); ?>" class="w-full bg-white/5 border border-white/10 rounded-xl px-4 py-3 text-sm text-white focus:border-crane-green/50">
            </div>
            <div>
                <label class="block text-xs font-black text-white/60 uppercase tracking-widest mb-3">Email Address</label>
                <input type="email" name="user_email" value="<?php echo esc_attr($user->user_email); ?>" class="w-full bg-white/5 border border-white/10 rounded-xl px-4 py-3 text-sm text-white focus:border-crane-green/50">
            </div>
            <div>
                <label class="block text-xs font-black text-white/60 uppercase tracking-widest mb-3">New Password (Optional)</label>
                <input type="password" name="password" placeholder="••••••••" class="w-full bg-white/5 border border-white/10 rounded-xl px-4 py-3 text-sm text-white focus:border-crane-green/50">
            </div>
            <div class="md:col-span-2">
                <label class="block text-xs font-black text-amber-500 uppercase tracking-widest mb-3">Current Password (Required for Email/Password Changes)</label>
                <input type="password" name="current_password" placeholder="••••••••" class="w-full bg-white/5 border border-white/10 rounded-xl px-4 py-3 text-sm text-white focus:border-amber-500/50">
            </div>
        </div>
        <button type="submit" class="bg-crane-green text-black px-12 py-4 rounded-xl text-[11px] font-black uppercase tracking-widest hover:scale-105 active:scale-95 transition-all">Save Profile</button>
    </form>
</div>
