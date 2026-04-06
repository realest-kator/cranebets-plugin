/**
 * Crane Bets Core Logic
 * Unified Interface Controller (Hardened Platinum Edition)
 */

/**
 * 1. Global UI Commands (Lazy-Initialization for immediate onclick availability)
 */

window.showCraneToast = function(message, type = 'success') {
    const container = document.getElementById('crane-toast');
    if (!container) {
        setTimeout(() => window.showCraneToast(message, type), 100);
        return;
    }

    const toast = document.createElement('div');
    const bg = type === 'success' ? 'bg-crane-green/20 border-crane-green/30 text-crane-green' : 'bg-red-500/20 border-red-500/30 text-red-500';
    toast.className = `flex items-center gap-3 px-6 py-4 rounded-xl border backdrop-blur-xl ${bg} shadow-lg pointer-events-auto transform translate-x-full transition-transform duration-300`;
    toast.innerHTML = `<span class="text-xs font-black uppercase tracking-widest">${message}</span>`;
    
    container.appendChild(toast);
    
    setTimeout(() => toast.classList.remove('translate-x-full'), 100);
    setTimeout(() => {
        toast.classList.add('translate-x-full');
        setTimeout(() => toast.remove(), 300);
    }, 3000);
};

window.openLockerModal = function() {
    const modal = document.getElementById('locker-modal');
    if (!modal) return;
    modal.classList.remove('hidden');
    document.body.style.overflow = 'hidden';
};

window.closeLockerModal = function() {
    const modal = document.getElementById('locker-modal');
    if (modal) {
        modal.classList.add('hidden');
        document.body.style.overflow = '';
    }
};

window.showAuthModal = function(mode = 'login') {
    const authModal = document.getElementById('crane-auth-modal');
    if (!authModal) {
        setTimeout(() => window.showAuthModal(mode), 100);
        return;
    }

    const authModalInner = authModal.querySelector('div');
    const authAction = document.getElementById('auth_action');
    const emailGroup = document.getElementById('auth-email-group');
    const authEmail = document.getElementById('auth-email');
    const authTosGroup = document.getElementById('auth-tos-group');
    const authTos = document.getElementById('auth-tos');
    const modalTitle = document.getElementById('auth-modal-title');
    const modalDesc = document.getElementById('auth-modal-desc');
    const submitBtn = document.getElementById('auth-submit-btn');
    const toggleBtn = document.getElementById('auth-toggle-btn');
    const forgotPass = document.getElementById('forgot-password-container');
    const msgDiv = document.getElementById('auth-message');

    if (msgDiv) msgDiv.innerText = '';

    authModal.classList.remove('hidden');
    document.body.classList.add('auth-modal-open');
    document.body.style.overflow = 'hidden'; // Lock scroll for auth as well
    
    setTimeout(() => {
        authModal.classList.remove('opacity-0');
        if (authModalInner) authModalInner.classList.remove('scale-95');
    }, 10);

    if (mode === 'login' || mode === 'signin') {
        if (authAction) authAction.value = 'crane_login';
        emailGroup?.classList.add('hidden');
        authTosGroup?.classList.add('hidden');
        if (authEmail) authEmail.required = false;
        if (authTos) authTos.required = false;
        if (modalTitle) modalTitle.innerHTML = 'Welcome <span class="text-crane-green italic">Back</span>';
        if (modalDesc) modalDesc.innerText = 'Log in to your Crane account.';
        if (submitBtn) submitBtn.innerText = 'Log In';
        if (toggleBtn) toggleBtn.innerText = "Don't have an account? Sign Up";
        forgotPass?.classList.remove('hidden');
    } else {
        if (authAction) authAction.value = 'crane_register';
        emailGroup?.classList.remove('hidden');
        authTosGroup?.classList.remove('hidden');
        if (authEmail) authEmail.required = true;
        if (authTos) authTos.required = true;
        if (modalTitle) modalTitle.innerHTML = 'Join <span class="text-crane-green italic">Crane</span>';
        if (modalDesc) {
            const ref = localStorage.getItem('crane_ref');
            if (ref) {
                modalDesc.innerHTML = 'Create your personal account.<br><span class="text-crane-green font-bold uppercase text-[11px] tracking-widest block mt-2 inline-flex items-center gap-1 rounded bg-crane-green/10 px-2 py-1 border border-crane-green/20"><svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"></path></svg> VIP Invite Active</span>';
            } else {
                modalDesc.innerText = 'Create your Crane account.';
            }
        }
        if (submitBtn) submitBtn.innerText = 'Create Account';
        if (toggleBtn) toggleBtn.innerText = "Already have an account? Log In";
        forgotPass?.classList.add('hidden');
    }
};

window.closeAuthModal = function() {
    const authModal = document.getElementById('crane-auth-modal');
    if (!authModal) return;
    const authModalInner = authModal.querySelector('div');
    authModal.classList.add('opacity-0');
    if (authModalInner) authModalInner.classList.add('scale-95');
    setTimeout(() => {
        authModal.classList.add('hidden');
        document.body.classList.remove('auth-modal-open');
        document.body.style.overflow = '';
    }, 100); // Instant response
};

// Cached mobile nav selector for focus guards
const mobileNavTrigger = document.querySelector('.lg\\:hidden.fixed.bottom-6');

// UX Guard: Hide floating mobile navigation when user is typing
document.addEventListener('focusin', (e) => {
    if (mobileNavTrigger && (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA')) {
        mobileNavTrigger.classList.add('opacity-0', 'pointer-events-none');
    }
});
document.addEventListener('focusout', (e) => {
    if (mobileNavTrigger && (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA')) {
        mobileNavTrigger.classList.remove('opacity-0', 'pointer-events-none');
    }
});

// 3. Lifecycle Initialization — Real-Time Referral & Verification Freshness
(function() {
    const urlParams = new URLSearchParams(window.location.search);
    const ref = urlParams.get('ref');
    if (ref) {
        localStorage.setItem('crane_ref', ref);
        // Set cookie for PHP fallback (1 year)
        document.cookie = `crane_ref=${ref}; path=/; max-age=${60*60*24*365}; SameSite=Lax`;
    }
    
    // If user just verified, flag it in session to bypass static craneData in other tabs
    if (urlParams.get('verified') === '1') {
        sessionStorage.setItem('crane_just_verified', '1');
    }
})();

document.addEventListener('click', function(e) {
    const link = e.target.closest('a');
    if (!link || typeof craneData === 'undefined') return;

    const hrefAttr = link.getAttribute('href');
    if (!hrefAttr || hrefAttr.startsWith('#') || hrefAttr.startsWith('javascript:') || 
        hrefAttr.includes('wp-admin') || link.hasAttribute('data-toggle') || 
        link.classList.contains('no-guard')) {
        return;
    }

    try {
        const absoluteLink = new URL(hrefAttr, window.location.origin).href;
        const isPublic = craneData.public_urls.some(url => 
            absoluteLink === url || absoluteLink.startsWith(url + '/') || absoluteLink.startsWith(url + '?')
        );

        if (isPublic) return;

        if (!craneData.is_logged_in) {
            e.preventDefault();
            window.showCraneToast('Crane Membership Required', 'error');
            window.showAuthModal('signup');
        } else {
            const isVerifiedSession = sessionStorage.getItem('crane_just_verified') === '1';
            if (!craneData.is_verified && !isVerifiedSession) {
                e.preventDefault();
                window.showCraneToast('Please verify your email to unlock all features.', 'error');
            }
        }
    } catch (error) {}
}, true);

document.addEventListener('DOMContentLoaded', function() {
    // Auth References
    const authModal = document.getElementById('crane-auth-modal');
    const authModalInner = authModal?.querySelector('div');
    const authForm = document.getElementById('crane-auth-form');
    const authMsg = document.getElementById('auth-message');
    const authSubmitBtn = document.getElementById('auth-submit-btn');
    
    // Locker References
    const lockerModal = document.getElementById('locker-modal');
    const lockerForm = document.getElementById('crane-locker-form');
    const lockerMsg = document.getElementById('locker-message');

    // Forgot Password References
    const forgotForm = document.getElementById('crane-forgot-password-form');
    const forgotMsg = document.getElementById('forgot-message');

    // Track state for resend verification
    let pendingVerifyUserId = null;

    // 3.1 Modal Triggers
    document.querySelectorAll('#open-locker-modal, .open-locker-btn').forEach(btn => {
        btn.addEventListener('click', window.openLockerModal);
    });

    document.getElementById('close-auth-modal')?.addEventListener('click', window.closeAuthModal);

    // 3.2 Generic Form AJAX Handler
    const handleFormSubmit = (form, action, msgElement, successCallback) => {
        if (!form) return;
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            const submitBtn = form.querySelector('button[type="submit"]');
            const originalText = submitBtn.innerText;
            
            submitBtn.disabled = true;
            submitBtn.classList.add('opacity-50', 'cursor-not-allowed', 'animate-pulse');
            submitBtn.innerHTML = '<svg class="animate-spin -ml-1 mr-2 h-4 w-4 text-current inline" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg> Processing...';
            if (msgElement) {
                msgElement.innerText = 'Elevating request...';
                msgElement.className = 'text-xs font-bold uppercase tracking-[0.1em] text-white/60 mt-2';
            }

            const formData = new FormData(this);
            formData.append('action', action);
            if (typeof craneData !== 'undefined') formData.append('security', craneData.nonce);

            fetch(craneData.ajax_url, {
                method: 'POST',
                body: formData
            })
            .then(res => {
                if (res.status === 403) { location.reload(); throw new Error('Nonce Expired'); }
                return res.json();
            })
            .then(data => {
                if (data.success) {
                    if (msgElement) {
                        msgElement.innerText = data.data.message;
                        msgElement.className = 'text-xs font-bold uppercase tracking-[0.1em] text-crane-green mt-2';
                    }
                    if (successCallback) successCallback(data);
                    else setTimeout(() => location.reload(), 1500);
                } else {
                    if (msgElement) {
                        msgElement.innerText = data.data.message || 'Verification failure.';
                        msgElement.className = 'text-xs font-bold uppercase tracking-[0.1em] text-red-500 mt-2';
                    }
                    submitBtn.classList.remove('opacity-50', 'cursor-not-allowed', 'animate-pulse');
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = originalText;
                }
            })
            .catch(() => {
                if (msgElement) msgElement.innerText = 'Network error.';
                submitBtn.classList.remove('opacity-50', 'cursor-not-allowed', 'animate-pulse');
                submitBtn.disabled = false;
                submitBtn.innerHTML = originalText;
            });
        });
    };

    // 3.3 Auth Form — Custom handler for login/register verification flow
    if (authForm) {
        authForm.addEventListener('submit', function(e) {
            e.preventDefault();
            const actionField = document.getElementById('auth_action');
            const action = actionField ? actionField.value : 'crane_login';
            const originalText = authSubmitBtn.innerText;

            if (!navigator.onLine) {
                if (authMsg) {
                    authMsg.innerText = 'Offline: Check your internet connection.';
                    authMsg.className = 'text-xs font-bold uppercase tracking-[0.1em] text-red-500 mt-2';
                    authMsg.classList.remove('hidden');
                }
                return;
            }

            authSubmitBtn.disabled = true;
            authSubmitBtn.classList.add('opacity-50', 'cursor-not-allowed', 'animate-pulse');
            authSubmitBtn.innerHTML = '<svg class="animate-spin -ml-1 mr-2 h-4 w-4 text-current inline" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg> Authenticating...';
            if (authMsg) {
                authMsg.innerText = 'Authenticating...';
                authMsg.className = 'text-xs font-bold uppercase tracking-[0.1em] text-white/60 mt-2';
            }

            const formData = new FormData(this);
            formData.append('action', action);
            if (typeof craneData !== 'undefined') formData.append('security', craneData.nonce);
            
            // Re-inject backup ref
            if (action === 'crane_register') {
                const storedRef = localStorage.getItem('crane_ref');
                if (storedRef) formData.append('crane_ref_backup', storedRef);
            }

            fetch(craneData.ajax_url, { method: 'POST', body: formData })
            .then(res => {
                if (res.status === 403) { location.reload(); throw new Error('Nonce Expired'); }
                return res.json();
            })
            .then(data => {
                if (data.success) {
                    // ====== SUCCESS: Registration or Login ======
                    if (action === 'crane_register') {
                        // Post-registration: show "check your inbox" with resend option
                        pendingVerifyUserId = data.data.user_id; // Store user_id from response
                        authMsg.innerHTML = `
                            <span class="text-crane-green">✓ Account created!</span><br>
                            <span class="text-white/60 normal-case mt-1 block">Check your email inbox to verify your account.</span>
                            <button type="button" id="resend-after-register" class="mt-3 text-crane-green underline hover:text-white transition-colors normal-case">Resend verification email</button>
                        `;
                        authMsg.className = 'text-xs font-bold uppercase tracking-[0.1em] mt-2';
                        authSubmitBtn.innerText = 'Account Created!';
                        authSubmitBtn.classList.add('opacity-50');

                        // Execute actual resend logic replacing dummy placeholder (QA Fix)
                        document.getElementById('resend-after-register')?.addEventListener('click', function() {
                            this.innerText = 'Sending...';
                            this.disabled = true;
                            
                            const fd = new FormData();
                            fd.append('action', 'crane_resend_verification');
                            fd.append('user_id', pendingVerifyUserId);
                            fd.append('security', craneData.nonce);
                            
                            fetch(craneData.ajax_url, { method: 'POST', body: fd })
                            .then(r => { if (r.status === 403) { location.reload(); throw new Error('Nonce Expired'); } return r.json(); })
                            .then(res => {
                                if (res.success) {
                                    this.innerText = '✓ Email Sent! Check your inbox.';
                                    this.classList.remove('text-crane-green', 'underline', 'hover:text-white');
                                    this.classList.add('text-white/60');
                                } else {
                                    this.innerText = res.data.message || 'Failed. Try again.';
                                    this.disabled = false;
                                }
                            });
                        });
                    } else {
                        // Login success
                        authMsg.innerText = data.data.message || 'Login successful!';
                        authMsg.className = 'text-xs font-bold uppercase tracking-[0.1em] text-crane-green mt-2';
                        setTimeout(() => location.reload(), 1000);
                    }
                } else {
                    // ====== ERROR ======
                    if (data.data && data.data.require_verify) {
                        // Unverified user tried to login — show resend button
                        pendingVerifyUserId = data.data.user_id;
                        authMsg.innerHTML = `
                            <span class="text-red-500">${data.data.message}</span><br>
                            <button type="button" id="resend-verify-btn" class="mt-3 px-4 py-2 bg-crane-green/10 border border-crane-green/20 rounded-xl text-crane-green hover:bg-crane-green/20 transition-all normal-case">
                                Resend Verification Email
                            </button>
                        `;
                        authMsg.className = 'text-xs font-bold uppercase tracking-[0.1em] mt-2';

                        document.getElementById('resend-verify-btn')?.addEventListener('click', function() {
                            this.innerText = 'Sending...';
                            this.disabled = true;
                            const fd = new FormData();
                            fd.append('action', 'crane_resend_verification');
                            fd.append('user_id', pendingVerifyUserId);
                            fd.append('security', craneData.nonce);
                            fetch(craneData.ajax_url, { method: 'POST', body: fd })
                            .then(r => r.json())
                            .then(res => {
                                if (res.success) {
                                    this.innerText = '✓ Email Sent! Check your inbox.';
                                    this.classList.remove('text-crane-green', 'border-crane-green/20', 'bg-crane-green/10');
                                    this.classList.add('text-white/60');
                                } else {
                                    this.innerText = res.data.message || 'Failed. Try again.';
                                    this.disabled = false;
                                }
                            });
                        });
                    } else {
                        authMsg.innerText = data.data.message || 'Authentication failed.';
                        authMsg.className = 'text-xs font-bold uppercase tracking-[0.1em] text-red-500 mt-2';
                    }
                    authSubmitBtn.classList.remove('opacity-50', 'cursor-not-allowed', 'animate-pulse');
                    authSubmitBtn.disabled = false;
                    authSubmitBtn.innerHTML = originalText;
                }
            })
            .catch(() => {
                authMsg.innerText = 'Network error.';
                authSubmitBtn.classList.remove('opacity-50', 'cursor-not-allowed', 'animate-pulse');
                authSubmitBtn.disabled = false;
                authSubmitBtn.innerHTML = originalText;
            });
        });
    }

    // 3.4 Locker Form — with inline feedback + delayed reload
    handleFormSubmit(lockerForm, 'crane_submit_locker', lockerMsg, (data) => {
        if (lockerMsg) {
            lockerMsg.innerText = '✓ ' + data.data.message;
            lockerMsg.className = 'text-xs font-bold uppercase tracking-[0.1em] text-crane-green mt-2';
        }
        window.showCraneToast(data.data.message, 'success');
        setTimeout(() => {
            lockerModal?.classList.add('hidden');
            document.body.style.overflow = '';
            location.reload();
        }, 1500);
    });

    // 3.5 Forgot Password Form
    handleFormSubmit(forgotForm, 'crane_forgot_password', forgotMsg, () => {
        forgotForm.reset();
    });

    // 3.6 Profile Update Form
    const profileForm = document.getElementById('crane-profile-form');
    const profileMsg = document.getElementById('profile-message');
    handleFormSubmit(profileForm, 'crane_update_profile', profileMsg, (data) => {
        window.showCraneToast(data.data.message, 'success');
        setTimeout(() => location.reload(), 1500);
    });

    // 3.6 Dynamic Like Buttons
    document.addEventListener('click', function(e) {
        const likeBtn = e.target.closest('.crane-like-btn');
        if (!likeBtn || typeof craneData === 'undefined') return;
        if (!likeBtn || typeof craneData === 'undefined' || likeBtn.disabled) return;

        const postId = likeBtn.dataset.postId;
        const countSpan = likeBtn.querySelector('.like-count');
        const wasLiked = likeBtn.classList.contains('text-crane-green');
        
        // Optimistic UI update + Tactile Animation
        likeBtn.disabled = true;
        const currentLikes = parseInt(countSpan.innerText) || 0;
        countSpan.innerText = currentLikes + (wasLiked ? -1 : 1);
        likeBtn.classList.toggle('text-crane-green');
        likeBtn.classList.toggle('text-white/60');
        
        // Heartbeat animation
        likeBtn.style.transform = 'scale(1.3)';
        setTimeout(() => likeBtn.style.transform = 'scale(1)', 200);

        const formData = new FormData();
        formData.append('action', 'crane_like_story');
        formData.append('post_id', postId);
        formData.append('security', craneData.nonce);

        fetch(craneData.ajax_url, { method: 'POST', body: formData })
        .then(res => {
            if (res.status === 403) { location.reload(); throw new Error('Nonce Expired'); }
            return res.json();
        })
        .then(data => {
            if (!data.success) {
                // Rollback Optimistic UI
                likeBtn.classList.toggle('text-crane-green', wasLiked);
                if (countSpan) countSpan.innerText = wasLiked ? parseInt(countSpan.innerText) + 1 : parseInt(countSpan.innerText) - 1;
                
                window.showCraneToast(data.data.message, 'error');
                if (data.data.message.includes('Login')) window.showAuthModal('signup');
            }
        });
    });

    // 3.7 Auth Toggle Button (switch between login/signup)
    document.getElementById('auth-toggle-btn')?.addEventListener('click', function() {
        const actionField = document.getElementById('auth_action');
        if (actionField && actionField.value === 'crane_login') {
            window.showAuthModal('signup');
        } else {
            window.showAuthModal('login');
        }
    });

    // 3.8 VIP Heartbeat — Optimized Anti-Drift Tracker (300-second real-time sync)
    if (typeof craneData !== 'undefined' && craneData.is_logged_in) {
        let vipInterval = null;
        let lastPingTime = Date.now();

        const sendHeartbeat = () => {
            const now = Date.now();
            const elapsed = Math.floor((now - lastPingTime) / 1000);
            
            // Prevent accumulation while computer is asleep or tab is throttled
            if (elapsed > 400) { // If gap is significantly larger than 300s
                lastPingTime = now;
                return; 
            }

            const formData = new FormData();
            formData.append('action', 'crane_increment_timer');
            formData.append('security', craneData.nonce);
            
            fetch(craneData.ajax_url, { method: 'POST', body: formData })
            .then(res => {
                if (res.status === 403) location.reload(); // Nonce expired, refresh session
                return res.json();
            })
            .then(data => {
                lastPingTime = Date.now();
                if (data.success) {
                    const d = data.data;
                    // Update progress display
                    const progressEl = document.getElementById('vip-progress-display');
                    const hoursEl = document.getElementById('vip-hours-display');
                    const barEl = document.getElementById('vip-bar');
                    const circleEl = document.getElementById('vip-circle');

                    if (progressEl) progressEl.innerText = d.progress;
                    if (hoursEl) hoursEl.innerText = d.hours;
                    if (barEl) barEl.style.width = d.progress + '%';
                    if (circleEl) {
                        const circumference = 552.92;
                        circleEl.setAttribute('stroke-dashoffset', circumference * (1 - d.progress / 100));
                    }

                    // VIP unlocked!
                    if (d.is_vip) {
                        window.showCraneToast('🎉 VIP Access Unlocked!', 'success');
                        clearInterval(vipInterval);
                    }
                }
            });
        };

        // Start heartbeat (5 minutes)
        vipInterval = setInterval(sendHeartbeat, 300000);

        // Pause when tab is hidden (prevent fake time accumulation)
        document.addEventListener('visibilitychange', () => {
            if (document.hidden) {
                clearInterval(vipInterval);
            } else {
                lastPingTime = Date.now(); // Reset drift anchor on return
                vipInterval = setInterval(sendHeartbeat, 300000);
                // We don't immediate ping here to respect the 400s anti-cheat threshold
            }
        });
    }

    // Modal Focus Trap
    document.addEventListener('keydown', function(e) {
        const modal = document.getElementById('crane-auth-modal');
        if (!modal || modal.classList.contains('hidden')) return;

        if (e.key === 'Tab') {
            const focusables = modal.querySelectorAll('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])');
            const first = focusables[0];
            const last = focusables[focusables.length - 1];

            if (e.shiftKey) {
                if (document.activeElement === first) {
                    last.focus();
                    e.preventDefault();
                }
            } else {
                if (document.activeElement === last) {
                    first.focus();
                    e.preventDefault();
                }
            }
        }
    });
});

