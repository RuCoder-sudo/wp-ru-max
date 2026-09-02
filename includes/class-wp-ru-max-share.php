<?php
/**
 * Кнопка «Поделиться в MAX» — добавляет кнопку в конец контента статьи.
 *
 * Принцип работы:
 *  - На мобильных (Web Share API доступен): открывает нативный диалог ОС —
 *    пользователь выбирает приложение MAX из списка.
 *  - На всех остальных устройствах: показывает мини-попап под кнопкой с двумя
 *    действиями:
 *      1) «Открыть MAX» — пытается открыть десктопное/мобильное приложение
 *         через deep link maxim://, и параллельно копирует ссылку в буфер.
 *      2) «Скопировать ссылку» — копирует URL в буфер обмена.
 *
 * Кнопка всегда выравнивается по левому краю.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WP_Ru_Max_Share {

    private static $instance = null;

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $settings = get_option( 'wp_ru_max_settings', array() );
        if ( ! empty( $settings['share_button_enabled'] ) ) {
            add_filter( 'the_content', array( $this, 'append_share_button' ) );
            add_action( 'wp_head',   array( $this, 'maybe_share_styles' ) );
            add_action( 'wp_footer', array( $this, 'maybe_share_script' ) );
        }
    }

    // ─── HTML кнопки ─────────────────────────────────────────────────────────

    public function append_share_button( $content ) {
        if ( ! is_singular() || ! in_the_loop() || ! is_main_query() ) {
            return $content;
        }

        $url   = esc_url( get_permalink() );
        $title = esc_attr( get_the_title() );
        $icon  = esc_url( WP_RU_MAX_PLUGIN_URL . 'assets/max-32x32.png' );

        $html  = '<div class="wp-ru-max-share-wrap">';
        $html .= '<button type="button" class="wp-ru-max-share-btn"'
               . ' data-url="'   . $url   . '"'
               . ' data-title="' . $title . '"'
               . ' aria-label="Поделиться в MAX"'
               . ' aria-haspopup="true" aria-expanded="false">';
        $html .= '<img src="' . $icon . '" width="20" height="20" alt="">';
        $html .= 'Поделиться в MAX';
        $html .= '</button>';

        // Попап — показывается только когда Web Share API недоступен
        $html .= '<div class="wp-ru-max-share-popup" role="dialog" aria-label="Поделиться в MAX" hidden>';
        $html .= '<button type="button" class="wp-ru-max-share-open-max">';
        $html .=   '<img src="' . $icon . '" width="18" height="18" alt=""> Открыть MAX';
        $html .= '</button>';
        $html .= '<button type="button" class="wp-ru-max-share-copy">';
        $html .=   '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>';
        $html .=   ' Скопировать ссылку';
        $html .= '</button>';
        $html .= '<div class="wp-ru-max-share-notice" role="status" aria-live="polite"></div>';
        $html .= '</div>';

        $html .= '</div>';

        return $content . $html;
    }

    // ─── CSS ─────────────────────────────────────────────────────────────────

    public function maybe_share_styles() {
        if ( ! is_singular() ) {
            return;
        }
        ?>
<style id="wp-ru-max-share-styles">
/* Обёртка — всегда слева */
.wp-ru-max-share-wrap {
    position: relative;
    display: block;
    text-align: left;
    margin: 28px 0 16px;
    padding-top: 20px;
    border-top: 1px solid #e5e7eb;
    float: none;
}

/* Главная кнопка */
.wp-ru-max-share-btn {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 11px 22px;
    background: #0077ff;
    color: #fff;
    border: none;
    border-radius: 10px;
    font-size: 15px;
    font-weight: 600;
    line-height: 1;
    cursor: pointer;
    font-family: inherit;
    transition: background .18s, box-shadow .18s, transform .1s;
    box-shadow: 0 2px 10px rgba(0,119,255,.30);
    -webkit-appearance: none;
    appearance: none;
    vertical-align: middle;
}
.wp-ru-max-share-btn img { display: block; flex-shrink: 0; }
.wp-ru-max-share-btn:hover {
    background: #005ee0;
    box-shadow: 0 4px 16px rgba(0,119,255,.40);
    transform: translateY(-1px);
    color: #fff;
}
.wp-ru-max-share-btn:active { transform: translateY(0); }

/* Попап-карточка */
.wp-ru-max-share-popup {
    position: absolute;
    top: calc(100% - 6px);
    left: 0;
    z-index: 9999;
    background: #fff;
    border: 1px solid #e0e7ef;
    border-radius: 12px;
    box-shadow: 0 8px 32px rgba(0,0,0,.15);
    padding: 8px;
    min-width: 230px;
    display: flex;
    flex-direction: column;
    gap: 4px;
}
.wp-ru-max-share-popup[hidden] { display: none; }

/* Кнопки внутри попапа */
.wp-ru-max-share-open-max,
.wp-ru-max-share-copy {
    display: flex;
    align-items: center;
    gap: 8px;
    width: 100%;
    padding: 10px 14px;
    border: none;
    border-radius: 8px;
    background: transparent;
    font-size: 14px;
    font-weight: 500;
    font-family: inherit;
    color: #1a1a2e;
    cursor: pointer;
    text-align: left;
    transition: background .15s;
    -webkit-appearance: none;
    appearance: none;
}
.wp-ru-max-share-open-max:hover { background: #eff6ff; color: #0077ff; }
.wp-ru-max-share-copy:hover     { background: #f3f4f6; }
.wp-ru-max-share-open-max img   { flex-shrink: 0; }
.wp-ru-max-share-copy svg       { flex-shrink: 0; }

/* Уведомление внутри попапа */
.wp-ru-max-share-notice {
    font-size: 12px;
    color: #16a34a;
    padding: 0 14px 4px;
    min-height: 0;
    transition: opacity .2s;
}
.wp-ru-max-share-notice:empty { display: none; }

@media (max-width: 480px) {
    .wp-ru-max-share-popup {
        position: fixed;
        bottom: 16px;
        left: 16px;
        right: 16px;
        top: auto;
        min-width: unset;
        border-radius: 16px;
        padding: 12px;
        box-shadow: 0 -4px 32px rgba(0,0,0,.18);
    }
}
</style>
        <?php
    }

    // ─── JavaScript ──────────────────────────────────────────────────────────

    public function maybe_share_script() {
        if ( ! is_singular() ) {
            return;
        }
        ?>
<script id="wp-ru-max-share-js">
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {

        // Закрываем все открытые попапы по клику вне
        document.addEventListener('click', function (e) {
            if (!e.target.closest('.wp-ru-max-share-wrap')) {
                closeAllPopups();
            }
        });

        document.querySelectorAll('.wp-ru-max-share-wrap').forEach(function (wrap) {
            var btn    = wrap.querySelector('.wp-ru-max-share-btn');
            var popup  = wrap.querySelector('.wp-ru-max-share-popup');
            var btnMax = wrap.querySelector('.wp-ru-max-share-open-max');
            var btnCpy = wrap.querySelector('.wp-ru-max-share-copy');
            var notice = wrap.querySelector('.wp-ru-max-share-notice');

            if (!btn) return;

            var url   = btn.dataset.url   || window.location.href;
            var title = btn.dataset.title || document.title;

            btn.addEventListener('click', function (e) {
                e.stopPropagation();

                // На мобильных / поддерживающих Web Share API — нативный диалог
                if (navigator.share) {
                    navigator.share({ title: title, url: url })
                        .catch(function (err) {
                            // Пользователь закрыл диалог — ничего не делаем
                        });
                    return;
                }

                // На ПК — показываем попап
                var isOpen = !popup.hidden;
                closeAllPopups();
                if (!isOpen) {
                    popup.hidden = false;
                    btn.setAttribute('aria-expanded', 'true');
                    if (notice) notice.textContent = '';
                }
            });

            // Кнопка «Открыть MAX»
            if (btnMax) {
                btnMax.addEventListener('click', function (e) {
                    e.stopPropagation();
                    openMaxApp(url, title);
                    // Параллельно копируем ссылку в буфер
                    copyToClipboard(url, function (ok) {
                        if (notice) {
                            notice.textContent = ok
                                ? 'MAX открывается… Ссылка также скопирована!'
                                : 'Открываем MAX…';
                        }
                    });
                    setTimeout(function () { closeAllPopups(); }, 3000);
                });
            }

            // Кнопка «Скопировать ссылку»
            if (btnCpy) {
                btnCpy.addEventListener('click', function (e) {
                    e.stopPropagation();
                    copyToClipboard(url, function (ok) {
                        if (notice) {
                            notice.textContent = ok ? '✓ Ссылка скопирована!' : 'Не удалось скопировать';
                        }
                        setTimeout(function () { closeAllPopups(); }, 2000);
                    });
                });
            }
        });

        // ─── Вспомогательные функции ─────────────────────────────────────

        function closeAllPopups() {
            document.querySelectorAll('.wp-ru-max-share-popup').forEach(function (p) {
                p.hidden = true;
            });
            document.querySelectorAll('.wp-ru-max-share-btn').forEach(function (b) {
                b.setAttribute('aria-expanded', 'false');
            });
        }

        /**
         * Открывает приложение MAX через deep link maxim://.
         * Работает на ПК (Windows/macOS) и мобильных, где установлен MAX.
         * Если приложение не установлено — браузер просто ничего не делает.
         */
        function openMaxApp(url, title) {
            var text     = title + '\n' + url;
            var deepLink = 'maxim://forward?text=' + encodeURIComponent(text);

            // Используем скрытый <a> чтобы не навигировать страницу
            var a = document.createElement('a');
            a.href = deepLink;
            a.style.cssText = 'display:none;position:fixed;';
            document.body.appendChild(a);
            try { a.click(); } catch (err) {}
            setTimeout(function () {
                if (a.parentNode) a.parentNode.removeChild(a);
            }, 500);
        }

        function copyToClipboard(text, cb) {
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(text).then(
                    function () { if (cb) cb(true); },
                    function () { legacyCopy(text, cb); }
                );
            } else {
                legacyCopy(text, cb);
            }
        }

        function legacyCopy(text, cb) {
            var ta = document.createElement('textarea');
            ta.value = text;
            ta.style.cssText = 'position:fixed;top:-9999px;left:-9999px;opacity:0;';
            document.body.appendChild(ta);
            ta.focus(); ta.select();
            var ok = false;
            try { ok = document.execCommand('copy'); } catch (e) {}
            document.body.removeChild(ta);
            if (cb) cb(ok);
        }

    });
})();
</script>
        <?php
    }
}
