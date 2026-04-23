/* WP Ru-max Chat Widget v1.0.22 */
(function () {
    'use strict';

    var cfg        = (typeof window.wpRuMaxSettings !== 'undefined') ? window.wpRuMaxSettings : {};
    var message    = cfg.message    || 'Здравствуйте! Мы всегда на связи. Кликните, чтобы написать!';
    var showDelay  = typeof cfg.showDelay  === 'number' ? cfg.showDelay  : 0;
    var sound      = cfg.sound      || 'none';
    var soundDelay = typeof cfg.soundDelay === 'number' ? cfg.soundDelay : 3000;
    var soundPages          = cfg.soundPages          || 'all';
    var soundSpecificPages  = Array.isArray(cfg.soundSpecificPages) ? cfg.soundSpecificPages : [];
    var soundOncePerSession = !!cfg.soundOncePerSession;
    var hideDelay   = typeof cfg.hideDelay   === 'number' ? cfg.hideDelay   : 0;
    var repeatDelay = typeof cfg.repeatDelay === 'number' ? cfg.repeatDelay : 0;
    var animation  = cfg.animation  || 'none';
    var retentionEnabled = !!cfg.retentionEnabled;
    var homeUrl    = cfg.homeUrl    || '/';

    var widget   = document.getElementById('wp-ru-max-widget');
    var balloon  = document.getElementById('wp-ru-max-balloon');
    var typingEl = document.getElementById('wp-ru-max-typing');
    var iconEl   = document.getElementById('wp-ru-max-icon');
    var closeBtn = document.getElementById('wp-ru-max-close');
    var retentionModal = document.getElementById('wp-ru-max-retention-modal');

    if (!widget || !balloon || !typingEl) return;

    /* ================================================================== */
    /* PAGE-MATCH HELPER for sound location filter                          */
    /* ================================================================== */
    function normalizePath(p) {
        if (!p) return '/';
        try {
            // If full URL, extract pathname
            if (p.indexOf('http://') === 0 || p.indexOf('https://') === 0 || p.indexOf('//') === 0) {
                var a = document.createElement('a');
                a.href = p;
                p = a.pathname || '/';
            }
        } catch (e) {}
        if (p.charAt(0) !== '/') p = '/' + p;
        // Strip trailing slash (keep root '/')
        if (p.length > 1) p = p.replace(/\/+$/, '');
        return p || '/';
    }

    function isHomePage() {
        var here = normalizePath(window.location.pathname);
        var home = normalizePath(homeUrl ? (function(){ var a=document.createElement('a'); a.href=homeUrl; return a.pathname; })() : '/');
        return here === home || here === '/' || here === '';
    }

    function soundAllowedHere() {
        if (soundPages === 'all') return true;
        if (soundPages === 'home') return isHomePage();
        if (soundPages === 'specific') {
            var here = normalizePath(window.location.pathname);
            for (var i = 0; i < soundSpecificPages.length; i++) {
                if (normalizePath(soundSpecificPages[i]) === here) return true;
            }
            return false;
        }
        return true;
    }

    /* Once-per-session storage flag */
    var SESSION_FLAG = 'wpRuMaxSoundPlayed';
    function sessionAlreadyPlayed() {
        if (!soundOncePerSession) return false;
        try { return window.sessionStorage && sessionStorage.getItem(SESSION_FLAG) === '1'; }
        catch (e) { return false; }
    }
    function markSessionPlayed() {
        if (!soundOncePerSession) return;
        try { if (window.sessionStorage) sessionStorage.setItem(SESSION_FLAG, '1'); } catch (e) {}
    }

    /* ================================================================== */
    /* CLOSE / RETENTION                                                    */
    /* ================================================================== */
    var retentionShown = false;

    function hideBalloonNow() {
        balloon.style.opacity    = '0';
        balloon.style.transition = 'opacity 0.3s';
        setTimeout(function () {
            balloon.style.display = 'none';
            balloon.dataset.closed = '1';
        }, 300);
    }

    function showRetentionModal() {
        if (!retentionModal) return;
        retentionModal.classList.add('show');
    }

    function hideRetentionModal() {
        if (!retentionModal) return;
        retentionModal.classList.remove('show');
    }

    if (closeBtn) {
        var triggerClose = function (e) {
            if (e) { e.preventDefault(); e.stopPropagation(); }
            if (retentionEnabled && retentionModal && !retentionShown) {
                retentionShown = true;
                showRetentionModal();
            } else {
                hideBalloonNow();
            }
            return false;
        };
        closeBtn.addEventListener('mouseenter', function () {
            if (retentionEnabled && retentionModal && !retentionShown) {
                retentionShown = true;
                showRetentionModal();
            }
        });
        closeBtn.addEventListener('click', triggerClose);
    }

    if (retentionModal) {
        var stayBtn  = retentionModal.querySelector('.wp-ru-max-retention-stay');
        var leaveBtn = retentionModal.querySelector('.wp-ru-max-retention-leave');
        if (stayBtn) {
            stayBtn.addEventListener('click', function () {
                hideRetentionModal();
                retentionShown = false;
            });
        }
        if (leaveBtn) {
            leaveBtn.addEventListener('click', function () {
                hideRetentionModal();
                hideBalloonNow();
            });
        }
        retentionModal.addEventListener('click', function (e) {
            if (e.target === retentionModal) {
                hideRetentionModal();
                retentionShown = false;
            }
        });
    }

    /* ================================================================== */
    /* SOUND ENGINE                                                         */
    /* ================================================================== */

    var audioCtx          = null;
    var userInteracted    = false;
    var pendingSoundAt    = null;
    var soundPlayed       = false;

    var INTERACTION_EVENTS = ['click', 'touchstart', 'keydown', 'scroll', 'mousemove'];

    function unlockAudio() {
        if (userInteracted) return;
        userInteracted = true;

        INTERACTION_EVENTS.forEach(function (ev) {
            document.removeEventListener(ev, unlockAudio, true);
        });

        if (!audioCtx) {
            var AC = window.AudioContext || window.webkitAudioContext;
            if (AC) {
                try { audioCtx = new AC(); } catch (e) {}
            }
        }
        if (audioCtx && audioCtx.state === 'suspended') {
            audioCtx.resume();
        }

        if (pendingSoundAt !== null && !soundPlayed) {
            var wait = pendingSoundAt - Date.now();
            if (wait <= 0) {
                fireSound();
            } else {
                setTimeout(fireSound, wait);
            }
        }
    }

    INTERACTION_EVENTS.forEach(function (ev) {
        document.addEventListener(ev, unlockAudio, true);
    });

    function fireSound() {
        if (soundPlayed) return;
        if (!soundAllowedHere()) return;
        if (sessionAlreadyPlayed()) return;
        soundPlayed = true;
        markSessionPlayed();
        doPlaySound(sound);
    }

    function playTone(ctx, freq, startOff, vol, dur) {
        var osc  = ctx.createOscillator();
        var gain = ctx.createGain();
        osc.type = 'sine';
        osc.connect(gain);
        gain.connect(ctx.destination);
        var t = ctx.currentTime + startOff;
        osc.frequency.setValueAtTime(freq, t);
        gain.gain.setValueAtTime(0, t);
        gain.gain.linearRampToValueAtTime(vol, t + 0.02);
        gain.gain.exponentialRampToValueAtTime(0.001, t + dur);
        osc.start(t);
        osc.stop(t + dur + 0.05);
    }

    function doPlaySound(type) {
        if (!audioCtx) return;
        if (audioCtx.state === 'suspended') { audioCtx.resume(); }

        if (type === 'sound1') {
            playTone(audioCtx, 880,  0,    0.18, 0.35);
            playTone(audioCtx, 1100, 0.22, 0.12, 0.28);

        } else if (type === 'sound2') {
            var osc  = audioCtx.createOscillator();
            var gain = audioCtx.createGain();
            osc.type = 'sine';
            osc.connect(gain);
            gain.connect(audioCtx.destination);
            var t = audioCtx.currentTime;
            osc.frequency.setValueAtTime(200, t);
            osc.frequency.exponentialRampToValueAtTime(900, t + 0.12);
            osc.frequency.exponentialRampToValueAtTime(600, t + 0.28);
            gain.gain.setValueAtTime(0, t);
            gain.gain.linearRampToValueAtTime(0.38, t + 0.05);
            gain.gain.exponentialRampToValueAtTime(0.001, t + 0.45);
            osc.start(t);
            osc.stop(t + 0.5);

        } else if (type === 'sound3') {
            playTone(audioCtx, 523.25, 0, 0.12, 0.55);
        }
    }

    window.wpRuMaxPlaySound = doPlaySound;

    /* ================================================================== */
    /* TYPING ANIMATION                                                     */
    /* ================================================================== */
    var typed     = '';
    var charIndex = 0;
    var typingActive = false;

    function typeChar() {
        if (!typingActive) return;
        if (charIndex < message.length) {
            typed += message.charAt(charIndex);
            typingEl.innerHTML = typed + '<span class="wp-ru-max-cursor"></span>';
            charIndex++;
            setTimeout(typeChar, 45);
        } else {
            typingEl.innerHTML = typed;
        }
    }

    function startTyping() {
        typed = '';
        charIndex = 0;
        typingActive = true;
        typeChar();
    }

    /* ================================================================== */
    /* BALLOON HELPERS                                                      */
    /* ================================================================== */
    function showBalloon() {
        if (balloon.dataset.closed === '1') return;
        balloon.style.transition = '';
        balloon.style.opacity    = '1';
        balloon.style.display    = 'block';
    }

    function scheduleAutoHide() {
        setTimeout(function () {
            if (balloon.dataset.closed === '1') return;
            balloon.style.transition = 'opacity 0.5s ease';
            balloon.style.opacity    = '0';
            setTimeout(function () {
                if (balloon.dataset.closed === '1') return;
                balloon.style.display    = 'none';
                balloon.style.transition = '';
                balloon.style.opacity    = '1';
            }, 500);
        }, 12000);
    }

    /* ================================================================== */
    /* WIDGET HIDE / REPEAT CYCLE                                           */
    /* ================================================================== */
    function hideWidget() {
        widget.style.transition = 'opacity 0.4s ease';
        widget.style.opacity = '0';
        setTimeout(function () {
            widget.style.display = 'none';
            widget.style.opacity = '1';
            widget.style.transition = '';
            // Mark balloon as not closed so it can re-appear next cycle
            balloon.dataset.closed = '';
        }, 400);
    }

    function showWidgetCycle() {
        widget.style.display = 'block';
        showBalloon();
        startTyping();
        scheduleAutoHide();

        if (animation && animation !== 'none' && iconEl) {
            setTimeout(startAnimation, 13500);
        }

        if (sound && sound !== 'none') {
            pendingSoundAt = Date.now() + soundDelay;
            soundPlayed = false; // allow next cycle to play (still gated by once-per-session)

            if (userInteracted) {
                setTimeout(fireSound, soundDelay);
            }
        }

        // Schedule auto-hide of the entire widget
        if (hideDelay > 0) {
            setTimeout(function () {
                hideWidget();
                if (repeatDelay > 0) {
                    setTimeout(showWidgetCycle, repeatDelay);
                }
            }, hideDelay);
        }
    }

    /* ================================================================== */
    /* SHOW WIDGET (after configured delay)                                 */
    /* ================================================================== */
    setTimeout(showWidgetCycle, showDelay);

    /* ================================================================== */
    /* HOVER — restore balloon unless manually closed                       */
    /* ================================================================== */
    widget.addEventListener('mouseenter', function () {
        showBalloon();
    });

    /* ================================================================== */
    /* ATTENTION ANIMATIONS                                                 */
    /* ================================================================== */
    function startAnimation() {
        if (!iconEl) return;
        iconEl.classList.remove(
            'wp-ru-max-anim-pulse',
            'wp-ru-max-anim-ripple',
            'wp-ru-max-anim-bounce',
            'wp-ru-max-anim-shake',
            'wp-ru-max-anim-glow',
            'wp-ru-max-anim-rotate'
        );
        if (animation && animation !== 'none') {
            iconEl.classList.add('wp-ru-max-anim-' + animation);
        }
    }

})();
