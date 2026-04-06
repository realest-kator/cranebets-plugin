<?php
/**
 * Template part for the Unverified User Notice
 * 
 * @var $args array {
 *    @var $message string
 * }
 */
$message = isset($args['message']) ? $args['message'] : 'Please check your inbox and verify your email to join the community.';
?>
<div class="bg-white/5 border border-white/10 rounded-3xl p-12 text-center backdrop-blur-xl animate-[fadeIn_0.5s_ease-out]">
    <div class="w-16 h-16 bg-crane-green/10 rounded-full mx-auto mb-6 flex items-center justify-center border border-crane-green/20">
        <svg class="w-8 h-8 text-crane-green" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"></path></svg>
    </div>
    <h3 class="text-xl font-black uppercase italic mb-3">Verification <span class="text-crane-green">Required</span></h3>
    <p class="text-white/60 text-sm max-w-sm mx-auto leading-relaxed mb-8"><?php echo esc_html($message); ?></p>
    
    <div class="flex flex-col sm:flex-row gap-4 justify-center items-center">
        <button onclick="location.reload()" class="bg-white/10 text-white px-8 py-3 rounded-xl text-xs font-black uppercase tracking-widest hover:bg-white/20 transition-all border border-white/5">Reload Page</button>
        <button id="resend-crane-verify" class="bg-crane-green text-black px-8 py-3 rounded-xl text-xs font-black uppercase tracking-widest hover:scale-105 active:scale-95 transition-all shadow-lg shadow-crane-green/10">Resend Link</button>
    </div>

    <script>
    document.getElementById('resend-crane-verify')?.addEventListener('click', function() {
        const btn = this;
        btn.disabled = true;
        btn.innerText = 'Sending...';

        const formData = new FormData();
        formData.append('action', 'crane_resend_verification');
        formData.append('security', craneData.nonce);
        formData.append('user_id', '<?php echo get_current_user_id(); ?>');

        fetch(craneData.ajax_url, {
            method: 'POST',
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                if (typeof showCraneToast === 'function') showCraneToast(data.data.message, 'success');
                btn.innerText = 'Sent!';
            } else {
                if (typeof showCraneToast === 'function') showCraneToast(data.data.message, 'error');
                btn.innerText = 'Resend Link';
                btn.disabled = false;
            }
        })
        .catch(() => {
            if (typeof showCraneToast === 'function') showCraneToast('Network Error', 'error');
            btn.disabled = false;
            btn.innerText = 'Resend Link';
        });
    });
    </script>
</div>
