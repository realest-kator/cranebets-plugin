<?php
/**
 * Template part for the Global Locker Submission Modal
 */
?>
<div id="locker-modal" class="hidden fixed inset-0 z-[10001] flex items-center justify-center p-4">
    <div class="absolute inset-0 bg-black/60 backdrop-blur-sm" onclick="this.parentElement.classList.add('hidden')"></div>
    <div class="relative w-full max-w-xl bg-crane-dark border border-white/10 rounded-3xl p-8 shadow-2xl animate-[fadeIn_0.3s_ease-out]">
        <h2 class="text-2xl font-black uppercase italic mb-6">Share <span class="text-crane-green">Update</span></h2>
        <form id="crane-locker-form" class="space-y-6">
            <div>
                <label class="block text-xs font-black text-white/60 uppercase tracking-widest mb-3">Topic / Summary</label>
                <input type="text" name="post_title" required class="w-full bg-white/5 border border-white/10 rounded-xl px-4 py-4 text-sm text-white focus:border-crane-green/50 transition-colors" placeholder="e.g. My Weekend Win">
            </div>
            <div>
                <label class="block text-xs font-black text-white/60 uppercase tracking-widest mb-3">The Experience</label>
                <textarea name="post_content" required rows="5" class="w-full bg-white/5 border border-white/10 rounded-xl px-4 py-4 text-sm text-white focus:border-crane-green/50 transition-colors resize-none" placeholder="Share your insights..."></textarea>
            </div>
            <div id="locker-message" class="text-xs font-bold uppercase tracking-[0.1em] min-h-[1.5em]"></div>
            <button type="submit" id="locker-submit-btn" class="w-full bg-crane-green text-black py-4 rounded-xl text-[11px] font-black uppercase tracking-widest hover:scale-[1.02] active:scale-95 transition-all">Publish Story</button>
        </form>
    </div>
</div>
