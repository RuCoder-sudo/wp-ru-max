/* WP Ru-max Chat Widget v1.0.17 */
(function () {
    'use strict';

    var cfg        = (typeof window.wpRuMaxSettings !== 'undefined') ? window.wpRuMaxSettings : {};
    var message    = cfg.message    || 'Здравствуйте! Мы всегда на связи. Кликните, чтобы написать!';
    var showDelay  = typeof cfg.showDelay  === 'number' ? cfg.showDelay  : 0;
    var sound      = cfg.sound      || 'none';
    var soundDelay = typeof cfg.soundDelay === 'number' ? cfg.soundDelay : 3000;
    var animation  = cfg.animation  || 'none';

    var widget   = document.getElementById('wp-ru-max-widget');
    var balloon  = document.getElementById('wp-ru-max-balloon');
    var typingEl = document.getElementById('wp-ru-max-typing');
    var iconEl   = document.getElementById('wp-ru-max-icon');

    if (!widget || !balloon || !typingEl) return;

    /* ================================================================== */
    /* SOUND ENGINE                                                         */
    /* Must be set up IMMEDIATELY — before any delay timers —              */
    /* so we catch interactions that happen before the widget appears.     */
    /* ================================================================== */

    var audioCtx          = null;
    var userInteracted    = false;   /* has the user touched/clicked/scrolled? */
    var pendingSoundAt    = null;    /* absolute ms when sound should fire     */
    var soundPlayed       = false;   /* fire only once                         */

    var INTERACTION_EVENTS = ['click', 'touchstart', 'keydown', 'scroll', 'mousemove'];

    function unlockAudio() {
        if (userInteracted) return;
        userInteracted = true;

        /* Remove listeners — only need one interaction */
        INTERACTION_EVENTS.forEach(function (ev) {
            document.removeEventListener(ev, unlockAudio, true);
        });

        /* Create / resume AudioContext now that the browser allows it */
        if (!audioCtx) {
            var AC = window.AudioContext || window.webkitAudioContext;
            if (AC) {
                try { audioCtx = new AC(); } catch (e) {}
            }
        }
        if (audioCtx && audioCtx.state === 'suspended') {
            audioCtx.resume();
        }

        /* If the widget has already appeared and the sound timer has elapsed, play now */
        if (pendingSoundAt !== null && !soundPlayed) {
            var wait = pendingSoundAt - Date.now();
            if (wait <= 0) {
                fireSound();
            } else {
                setTimeout(fireSound, wait);
            }
        }
    }

    /* Register listeners immediately — before any delay timers */
    INTERACTION_EVENTS.forEach(function (ev) {
        document.addEventListener(ev, unlockAudio, true);
    });

    function fireSound() {
        if (soundPlayed) return;
        soundPlayed = true;
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
            /* Новое сообщение — двойной мелодичный звон */
            playTone(audioCtx, 880,  0,    0.18, 0.35);
            playTone(audioCtx, 1100, 0.22, 0.12, 0.28);

        } else if (type === 'sound2') {
            /* Всплывающее окно — нарастающий pop */
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
            /* Тихий сигнал — мягкий тон C5 */
            playTone(audioCtx, 523.25, 0, 0.12, 0.55);
        }
    }

    /* Expose for admin preview (admin.js uses its own AudioContext per click) */
    window.wpRuMaxPlaySound = doPlaySound;

    /* ================================================================== */
    /* TYPING ANIMATION                                                     */
    /* ================================================================== */
    var typed     = '';
    var charIndex = 0;

    function typeChar() {
        if (charIndex < message.length) {
            typed += message.charAt(charIndex);
            typingEl.innerHTML = typed + '<span class="wp-ru-max-cursor"></span>';
            charIndex++;
            setTimeout(typeChar, 45);
        } else {
            typingEl.innerHTML = typed;
        }
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
    /* SHOW WIDGET (after configured delay)                                 */
    /* ================================================================== */
    setTimeout(function () {

        widget.style.display = 'block';
        showBalloon();
        typeChar();
        scheduleAutoHide();

        /* Attention animation starts after balloon auto-hides */
        if (animation && animation !== 'none' && iconEl) {
            setTimeout(startAnimation, 13500);
        }

        /* Schedule sound                                                   */
        /* If user has already interacted — set a plain setTimeout.         */
        /* If not — store the target time; unlockAudio() will pick it up.   */
        if (sound && sound !== 'none') {
            pendingSoundAt = Date.now() + soundDelay;

            if (userInteracted) {
                /* AudioContext already unlocked — just wait the delay */
                setTimeout(fireSound, soundDelay);
            }
            /* else: unlockAudio() will call fireSound() when user interacts */
        }

    }, showDelay);

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
            'wp-ru-max-anim-shake'
        );
        if (animation && animation !== 'none') {
            iconEl.classList.add('wp-ru-max-anim-' + animation);
        }
    }

})();
