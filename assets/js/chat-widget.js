/* WP Ru-max Chat Widget v1.0.51 */
(function () {
    'use strict';

    var cfg        = (typeof window.wpRuMaxSettings !== 'undefined') ? window.wpRuMaxSettings : {};
    var message    = typeof cfg.message === 'string' ? cfg.message.trim() : '';
    var welcomeEnabled = cfg.welcomeEnabled !== false && message.length > 0;
    var showDelay  = typeof cfg.showDelay  === 'number' ? cfg.showDelay  : 0;
    var sound      = cfg.sound      || 'none';
    var soundDelay = typeof cfg.soundDelay === 'number' ? cfg.soundDelay : 3000;
    var soundsUrl  = cfg.soundsUrl  || '';
    var soundPages          = cfg.soundPages          || 'all';
    var soundSpecificPages  = Array.isArray(cfg.soundSpecificPages) ? cfg.soundSpecificPages : [];
    var soundOncePerSession = !!cfg.soundOncePerSession;
    var hideDelay   = typeof cfg.hideDelay   === 'number' ? cfg.hideDelay   : 0;
    var repeatDelay = typeof cfg.repeatDelay === 'number' ? cfg.repeatDelay : 0;
    var animation  = cfg.animation  || 'none';
    var retentionEnabled = !!cfg.retentionEnabled;
    var homeUrl    = cfg.homeUrl    || '/';

    /* Яндекс.Метрика */
    var yaMetrikaEnabled = !!cfg.yaMetrikaEnabled;
    var yaMetrikaCounter = cfg.yaMetrikaCounter || 0;
    var yaMetrikaGoal    = cfg.yaMetrikaGoal    || 'chat_widget_click';

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
            if (p.indexOf('http://') === 0 || p.indexOf('https://') === 0 || p.indexOf('//') === 0) {
                var a = document.createElement('a');
                a.href = p;
                p = a.pathname || '/';
            }
        } catch (e) {}
        if (p.charAt(0) !== '/') p = '/' + p;
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
        // Убран обработчик mouseenter: показывать retention-modal при наведении
        // на кнопку закрытия — слишком агрессивное поведение (UX-баг).
        // Retention-modal теперь показывается только при клике на кнопку закрытия.
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

    /* MP3 sounds: play via HTML5 Audio */
    function playMp3Sound(filename) {
        if (!soundsUrl) return;
        try {
            var audio = new Audio(soundsUrl + filename);
            audio.volume = 0.7;
            var playPromise = audio.play();
            if (playPromise !== undefined) {
                playPromise.catch(function() {});
            }
        } catch (e) {}
    }

    function doPlaySound(type) {
        /* MP3-based sounds (sound4, sound5, sound6) */
        /* sound7 убран: файл assets/sounds/sound7.mp3 не существует */
        if (type === 'sound4') { playMp3Sound('sound4.mp3'); return; }
        if (type === 'sound5') { playMp3Sound('sound5.mp3'); return; }
        if (type === 'sound6') { playMp3Sound('sound6.mp3'); return; }

        /* Web Audio API synthesised sounds */
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
            // textContent вместо innerHTML: предотвращает интерпретацию HTML-тегов
            // из текста сообщения (XSS / непреднамеренная разметка).
            typingEl.textContent = typed;
            var cursorEl = document.createElement('span');
            cursorEl.className = 'wp-ru-max-cursor';
            typingEl.appendChild(cursorEl);
            charIndex++;
            setTimeout(typeChar, 45);
        } else {
            typingEl.textContent = typed;
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
        if (!welcomeEnabled) return;
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
            balloon.dataset.closed = '';
        }, 400);
    }

    function showWidgetCycle() {
        widget.style.display = 'block';
        if (welcomeEnabled) {
            showBalloon();
            startTyping();
            scheduleAutoHide();
        }

        if (animation && animation !== 'none' && iconEl) {
            setTimeout(startAnimation, 13500);
        }

        if (sound && sound !== 'none') {
            pendingSoundAt = Date.now() + soundDelay;
            soundPlayed = false;

            if (userInteracted) {
                setTimeout(fireSound, soundDelay);
            }
        }

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
        if (welcomeEnabled) showBalloon();
    });

    /* ================================================================== */
    /* YANDEX.METRIKA — reachGoal при клике на иконку виджета              */
    /* ================================================================== */
    if (iconEl && yaMetrikaEnabled && yaMetrikaCounter) {
        iconEl.addEventListener('click', function () {
            try {
                if (typeof ym === 'function') {
                    ym(yaMetrikaCounter, 'reachGoal', yaMetrikaGoal);
                }
            } catch (e) {}
        });
    }

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
            'wp-ru-max-anim-rotate',
            'wp-ru-max-anim-float',
            'wp-ru-max-anim-pendulum',
            'wp-ru-max-anim-burst',
            'wp-ru-max-anim-heartbeat'
        );
        if (animation && animation !== 'none') {
            iconEl.classList.add('wp-ru-max-anim-' + animation);
        }
    }

})();

/* Unified contacts menu and live chat. Loaded by the main widget only. */
(function () {
    'use strict';
    var menu = document.getElementById('wp-ru-max-contacts-menu');
    var icon = document.getElementById('wp-ru-max-icon');
    var cfg = window.wpRuMaxContacts || {};
    if (!menu || !icon || !cfg.ajaxUrl || !cfg.nonce) return;
    var panel = menu.querySelector('.wprmp-panel');
    var channels = menu.querySelector('.wprmp-channels');
    var close = menu.querySelector('.wprmp-close');
    function closeMenu() {
        menu.classList.remove('is-open');
        if (panel) { panel.classList.remove('is-open'); panel.setAttribute('aria-hidden', 'true'); }
        if (channels) { channels.classList.remove('is-open'); channels.setAttribute('aria-hidden', 'true'); }
        icon.setAttribute('aria-expanded', 'false');
    }
    function openMenu() {
        menu.classList.add('is-open');
        var balloon = document.getElementById('wp-ru-max-balloon');
        if (balloon) { balloon.style.display = 'none'; balloon.dataset.closed = '1'; }
        if (panel) { panel.classList.remove('is-open'); panel.setAttribute('aria-hidden', 'true'); }
        if (channels) { channels.classList.add('is-open'); channels.setAttribute('aria-hidden', 'false'); }
        icon.setAttribute('aria-expanded', 'true');
    }
    function showPanel(mode) {
        if (!panel) return;
        mode = mode || 'live_chat';
        panel.dataset.mode = mode;
        var title = panel.querySelector('.wprmp-panel-brand h3');
        var status = panel.querySelector('.wprmp-panel-brand small');
        if (title) title.textContent = mode === 'contact_form' ? 'Форма обратной связи' : 'Живой чат';
        if (status) status.textContent = mode === 'contact_form' ? 'Обычная заявка без переписки' : (cfg.managerOnline ? 'Менеджер онлайн' : 'Сообщение попадёт менеджеру');
        var channel = panel.querySelector('input[name="channel"]');
        if (channel) channel.value = mode;
        var consent = panel.querySelector('.wprmp-consents');
        if (consent) {
            var existing = '';
            try { existing = sessionStorage.getItem('wpRuMaxContactsConversation') || ''; } catch (e) {}
            var required = mode === 'contact_form' || !existing;
            consent.style.display = required ? '' : 'none';
            consent.querySelectorAll('input').forEach(function (field) { field.required = required; });
        }
        panel.classList.add('is-open');
        panel.setAttribute('aria-hidden', 'false');
        if (channels) { channels.classList.remove('is-open'); channels.setAttribute('aria-hidden', 'true'); }
    }
    icon.addEventListener('click', function (event) {
        event.preventDefault();
        event.stopPropagation();
        menu.classList.contains('is-open') ? closeMenu() : openMenu();
    }, true);
    icon.setAttribute('aria-haspopup', 'dialog');
    icon.setAttribute('aria-expanded', 'false');
    if (close) close.addEventListener('click', closeMenu);
    /*
     * Preserve the browser's default navigation for all external, tel: and
     * mailto: channel links while preventing theme scripts from treating the
     * click as a click on the launcher or an outside click.
     */
    menu.addEventListener('click', function (event) {
        var link = event.target.closest ? event.target.closest('a.wprmp-channel') : null;
        if (link && link.getAttribute('href') && link.getAttribute('href') !== '#') {
            event.stopPropagation();
        }
    });
    menu.querySelectorAll('.wprmp-open-chat,.wprmp-open-live-chat').forEach(function (button) {
        button.addEventListener('click', function () { showPanel(button.dataset.mode || (button.classList.contains('wprmp-open-chat') ? 'contact_form' : 'live_chat')); });
    });
    menu.querySelectorAll('.wprmp-faq-item').forEach(function (button) {
        button.addEventListener('click', function () { button.classList.toggle('is-expanded'); });
    });
    menu.querySelectorAll('.wprmp-quick-buttons button').forEach(function (button) {
        button.addEventListener('click', function () {
            var textarea = menu.querySelector('textarea[name="message"]');
            if (textarea) { textarea.value = button.getAttribute('data-message') || ''; textarea.focus(); }
        });
    });
    document.addEventListener('click', function (event) {
        if (menu.classList.contains('is-open') && !menu.contains(event.target) && event.target !== icon) closeMenu();
    });
    function renderThread(items) {
        var thread = menu.querySelector('.wprmp-thread-live');
        if (!thread) return;
        thread.textContent = '';
        (items || []).forEach(function (item) {
            var bubble = document.createElement('div');
            bubble.className = 'wprmp-live-bubble ' + (item.role || 'bot');
            var name = document.createElement('b');
            name.textContent = item.role === 'manager' ? 'Менеджер' : (item.name || (item.role === 'bot' ? 'Помощник' : 'Вы'));
            var text = document.createElement('span');
            text.textContent = item.text || '';
            bubble.appendChild(name); bubble.appendChild(text); thread.appendChild(bubble);
        });
        thread.scrollTop = thread.scrollHeight;
    }
    var form = menu.querySelector('.wprmp-form');
    if (form) form.addEventListener('submit', function (event) {
        event.preventDefault();
        var data = new FormData(form);
        var status = form.querySelector('.wprmp-form-status');
        var contact = data.get('channel') === 'contact_form';
        var id = '';
        if (!contact) { try { id = sessionStorage.getItem('wpRuMaxContactsConversation') || ''; } catch (e) {} }
        if (id) data.append('conversation_id', id);
        data.append('action', 'wp_ru_max_contacts_message');
        data.append('nonce', cfg.nonce);
        if (status) status.textContent = 'Отправляем…';
        fetch(cfg.ajaxUrl, { method: 'POST', body: data, credentials: 'same-origin' })
            .then(function (response) { return response.json(); })
            .then(function (result) {
                if (!result.success) throw new Error(result.data || 'Не удалось отправить сообщение.');
                if (status) status.textContent = result.data.message || 'Сообщение отправлено.';
                if (result.data.conversation_id && !contact) {
                    try { sessionStorage.setItem('wpRuMaxContactsConversation', result.data.conversation_id); } catch (e) {}
                }
                var identity = form.querySelector('.wprmp-identity');
                var consent = form.querySelector('.wprmp-consents');
                if (identity) identity.style.display = 'none';
                if (consent) consent.style.display = 'none';
                form.querySelectorAll('.wprmp-identity input').forEach(function (field) { field.disabled = true; field.required = false; });
                form.reset();
                renderThread(result.data.messages || []);
            })
            .catch(function (error) { if (status) status.textContent = error.message || 'Не удалось отправить сообщение.'; });
    });
    function poll() {
        var id = '';
        try { id = sessionStorage.getItem('wpRuMaxContactsConversation') || ''; } catch (e) {}
        if (!id) return;
        var data = new FormData();
        data.append('action', 'wp_ru_max_contacts_history'); data.append('nonce', cfg.nonce); data.append('conversation_id', id);
        fetch(cfg.ajaxUrl, { method: 'POST', body: data, credentials: 'same-origin' })
            .then(function (response) { return response.json(); })
            .then(function (result) { if (result.success && result.data) renderThread(result.data.messages || []); })
            .catch(function () {});
    }
    window.setInterval(poll, 4000);
})();
