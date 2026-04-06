<?php
/**
 * Template: Prediction Submit Modal
 * AJAX-powered modal for users to drop their match predictions
 */
if ( ! is_user_logged_in() ) return;
?>
<div id="crane-prediction-modal" class="fixed inset-0 z-[200] hidden opacity-0 transition-all duration-300">
    <!-- Backdrop -->
    <div class="absolute inset-0 bg-black/80 backdrop-blur-sm" onclick="closePredictionModal()"></div>

    <!-- Modal -->
    <div class="relative z-10 flex items-end md:items-center justify-center min-h-full p-4">
        <div class="bg-crane-dark border border-white/10 rounded-3xl w-full max-w-lg p-8 transform translate-y-4 transition-transform duration-300">
            <!-- Header -->
            <div class="flex items-center justify-between mb-8">
                <div>
                    <h3 class="text-xl font-black uppercase tracking-tight text-white">Make a <span class="text-crane-green italic">Prediction</span></h3>
                    <p class="text-white/50 text-xs font-bold uppercase tracking-widest mt-1">Predict & build your accuracy rank</p>
                </div>
                <button onclick="closePredictionModal()" class="w-10 h-10 rounded-xl bg-white/5 flex items-center justify-center text-white/60 hover:text-white hover:bg-white/10 transition-all">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                </button>
            </div>

            <!-- Form -->
            <form id="crane-prediction-form" class="space-y-5">
                <?php wp_nonce_field( 'crane_security_nonce', 'security', false ); ?>

                <!-- Match Name -->
                <div>
                    <label class="block text-xs font-black text-white/60 uppercase tracking-widest mb-2">Match *</label>
                    <input type="text" name="match" placeholder="e.g. Chelsea vs Arsenal" required
                           class="w-full bg-white/5 border border-white/10 rounded-xl px-4 py-3 text-sm text-white placeholder-white/20 focus:border-crane-green/50 focus:outline-none transition-all">
                </div>

                <!-- League (optional) -->
                <div>
                    <label class="block text-xs font-black text-white/60 uppercase tracking-widest mb-2">League <span class="text-white/60">(optional)</span></label>
                    <input type="text" name="league" placeholder="e.g. Premier League"
                           class="w-full bg-white/5 border border-white/10 rounded-xl px-4 py-3 text-sm text-white placeholder-white/20 focus:border-crane-green/50 focus:outline-none transition-all">
                </div>

                <!-- Your Pick -->
                <div>
                    <label class="block text-xs font-black text-white/60 uppercase tracking-widest mb-3">Your Prediction *</label>
                    <div class="grid grid-cols-3 gap-3">
                        <label class="crane-pick-option cursor-pointer">
                            <input type="radio" name="selection" value="home" required class="hidden peer">
                            <div class="peer-checked:bg-crane-green/20 peer-checked:border-crane-green peer-checked:text-crane-green bg-white/5 border border-white/10 rounded-xl py-3 text-center text-xs font-black uppercase tracking-widest text-white/60 hover:bg-white/10 transition-all">
                                Home
                            </div>
                        </label>
                        <label class="crane-pick-option cursor-pointer">
                            <input type="radio" name="selection" value="draw" class="hidden peer">
                            <div class="peer-checked:bg-crane-green/20 peer-checked:border-crane-green peer-checked:text-crane-green bg-white/5 border border-white/10 rounded-xl py-3 text-center text-xs font-black uppercase tracking-widest text-white/60 hover:bg-white/10 transition-all">
                                Draw
                            </div>
                        </label>
                        <label class="crane-pick-option cursor-pointer">
                            <input type="radio" name="selection" value="away" class="hidden peer">
                            <div class="peer-checked:bg-crane-green/20 peer-checked:border-crane-green peer-checked:text-crane-green bg-white/5 border border-white/10 rounded-xl py-3 text-center text-xs font-black uppercase tracking-widest text-white/60 hover:bg-white/10 transition-all">
                                Away
                            </div>
                        </label>
                    </div>
                </div>

                <!-- Odds (optional) -->
                <div>
                    <label class="block text-xs font-black text-white/60 uppercase tracking-widest mb-2">Odds <span class="text-white/60">(optional)</span></label>
                    <input type="text" name="odds" placeholder="e.g. 2.10"
                           class="w-full bg-white/5 border border-white/10 rounded-xl px-4 py-3 text-sm text-white placeholder-white/20 focus:border-crane-green/50 focus:outline-none transition-all">
                </div>

                <!-- Submit -->
                <div id="crane-prediction-feedback" class="hidden text-sm font-bold py-2 px-4 rounded-xl text-center"></div>

                <button type="submit" id="crane-prediction-submit"
                        class="w-full bg-crane-green text-black py-4 rounded-2xl text-[11px] font-black uppercase tracking-[0.2em] hover:scale-[1.02] active:scale-95 transition-all">
                    Submit Prediction 🎯
                </button>
            </form>
        </div>
    </div>
</div>

<script>
function openPredictionModal() {
    const modal = document.getElementById('crane-prediction-modal');
    if (!modal) return;
    modal.classList.remove('hidden');
    void modal.offsetWidth;
    modal.classList.remove('opacity-0');
    modal.querySelector('.translate-y-4')?.classList.remove('translate-y-4');
    document.body.style.overflow = 'hidden';
}
function closePredictionModal() {
    const modal = document.getElementById('crane-prediction-modal');
    if (!modal) return;
    modal.classList.add('opacity-0');
    setTimeout(() => {
        modal.classList.add('hidden');
        document.body.style.overflow = '';
    }, 300);
}

jQuery(document).ready(function($) {
    $('#crane-prediction-form').on('submit', function(e) {
        e.preventDefault();
        var $btn = $('#crane-prediction-submit');
        var $feedback = $('#crane-prediction-feedback');

        if (!navigator.onLine) {
            $feedback.removeClass('hidden').addClass('bg-red-500/20 text-red-500').text('No internet connection.');
            return;
        }

        $btn.prop('disabled', true).html('<span class="inline-block animate-spin mr-2">↻</span> Submitting...');

        $.post(craneData.ajax_url, {
            action: 'crane_submit_prediction',
            security: $(this).find('[name="security"]').val(),
            match: $(this).find('[name="match"]').val(),
            selection: $(this).find('[name="selection"]:checked').val(),
            league: $(this).find('[name="league"]').val(),
            odds: $(this).find('[name="odds"]').val()
        }, function(response) {
            $feedback.removeClass('hidden bg-red-500/20 text-red-400 bg-green-500/20 text-green-400');
            if (response.success) {
                $feedback.addClass('bg-green-500/20 text-green-400').text(response.data.message);
                setTimeout(() => { closePredictionModal(); location.reload(); }, 1200);
            } else {
                $feedback.addClass('bg-red-500/20 text-red-400').text(response.data.message || 'Something went wrong.');
                $btn.prop('disabled', false).text('Submit Prediction 🎯');
            }
        });
    });
});
</script>
