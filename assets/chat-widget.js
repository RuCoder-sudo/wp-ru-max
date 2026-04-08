/* WP Ru-max Chat Widget Script */
(function () {
    'use strict';

    var message = typeof window.wpRuMaxMessage !== 'undefined' ? window.wpRuMaxMessage : 'Здравствуйте! У вас есть вопросы!? Мы всегда на связи. Кликните, чтобы нам написать!';
    var typed = '';
    var charIndex = 0;
    var typeDelay = 45;
    var showDelay = 2000;
    var balloon = document.getElementById('wp-ru-max-balloon');
    var typingEl = document.getElementById('wp-ru-max-typing');

    if (!balloon || !typingEl) return;

    function typeChar() {
        if (charIndex < message.length) {
            typed += message.charAt(charIndex);
            typingEl.innerHTML = typed + '<span class="wp-ru-max-cursor"></span>';
            charIndex++;
            setTimeout(typeChar, typeDelay);
        } else {
            typingEl.innerHTML = typed;
        }
    }

    setTimeout(function () {
        balloon.style.display = 'block';
        typeChar();
    }, showDelay);

    /* Auto-hide balloon after 12 seconds */
    setTimeout(function () {
        if (balloon) {
            balloon.style.transition = 'opacity 0.5s ease';
            balloon.style.opacity = '0';
            setTimeout(function () {
                balloon.style.display = 'none';
            }, 500);
        }
    }, showDelay + 12000);

    /* Show balloon on hover over widget icon */
    var widget = document.getElementById('wp-ru-max-widget');
    if (widget) {
        widget.addEventListener('mouseenter', function () {
            balloon.style.display = 'block';
            balloon.style.opacity = '1';
        });
    }

})();
