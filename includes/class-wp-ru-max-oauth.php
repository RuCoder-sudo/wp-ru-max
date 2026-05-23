<?php
/**
 * MAX-авторизация через мессенджер MAX.
 *
 * ── Как работает MAX авторизация ─────────────────────────────────────────────
 * MAX не предоставляет публичный OAuth 2.0 endpoint (Client ID / Client Secret
 * для внешних сайтов не существует). Авторизация работает через систему
 * Mini App (мини-приложений) — аналогично Telegram Web App:
 *
 *   1. Настройте Mini App на платформе MAX: Чат-боты → ваш бот → «Мини-приложение»
 *      Укажите URL вашего сайта как URL мини-приложения.
 *   2. Когда пользователь открывает сайт через бота в MAX, мессенджер
 *      передаёт подписанные данные профиля (initData).
 *   3. Плагин проверяет подпись HMAC-SHA256 (ключ = Bot Token),
 *      извлекает ID / имя / аватар пользователя и выполняет вход.
 *
 * ── Алгоритм проверки подписи ────────────────────────────────────────────────
 *   secret_key = HMAC-SHA256("WebAppData", bot_token)
 *   check_str  = отсортированные пары «key=value» через «\n» (без hash)
 *   expected   = HMAC-SHA256(check_str, secret_key)
 *   compare(expected, initData.hash)
 *
 * ── Что нужно настроить ──────────────────────────────────────────────────────
 *   - Bot Token — уже задан в главных настройках плагина.
 *   - Username бота (@your_bot) — укажите в Дополнительных настройках.
 *   - Mini App URL в MAX Partner Platform = URL вашего сайта.
 *
 * ── Кнопка «Войти через MAX» ─────────────────────────────────────────────────
 *   Ссылка ведёт на страницу бота: https://max.ru/your_bot_username
 *   Когда пользователь открывает сайт из MAX — вход происходит автоматически.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WP_Ru_Max_OAuth {

    const NONCE_ACTION = 'max_miniapp_auth_nonce';

    private static $instance = null;
    private $settings;

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->settings = get_option( 'wp_ru_max_settings', array() );

        if ( empty( $this->settings['max_oauth_enabled'] ) ) {
            return;
        }

        /*
         * Обработка initData из URL-параметров.
         * priority 20: класс создаётся на 'init'/10 — добавляем с priority > 10.
         */
        add_action( 'init', array( $this, 'handle_url_initdata' ), 20 );

        // AJAX — валидация initData, полученного через JavaScript MAX Mini App API
        add_action( 'wp_ajax_nopriv_max_auth_initdata', array( $this, 'ajax_validate_initdata' ) );
        add_action( 'wp_ajax_max_auth_initdata',        array( $this, 'ajax_validate_initdata' ) );

        // Страница входа / регистрации WordPress
        add_action( 'login_form',            array( $this, 'render_login_button' ) );
        add_action( 'register_form',         array( $this, 'render_login_button' ) );
        add_action( 'login_enqueue_scripts', array( $this, 'output_oauth_css' ) );
        add_action( 'login_message',         array( $this, 'maybe_show_error' ) );

        // WooCommerce (plugins_loaded уже завершён → class_exists напрямую)
        if ( class_exists( 'WooCommerce' ) ) {
            add_action( 'woocommerce_login_form_start',     array( $this, 'render_woo_button' ) );
            add_action( 'woocommerce_register_form_start',  array( $this, 'render_woo_button' ) );
            add_action( 'woocommerce_before_checkout_form', array( $this, 'render_checkout_banner' ) );
        }

        // Frontend: CSS на страницах WC + JS для детекции MAX Mini App API
        add_action( 'wp_head',   array( $this, 'maybe_output_frontend_css' ) );
        add_action( 'wp_footer', array( $this, 'inject_miniapp_js' ) );
    }

    // ─── HMAC-SHA256 валидация initData ──────────────────────────────────────

    /**
     * Проверяет подпись initData от MAX Mini App.
     * Алгоритм идентичен Telegram Web App (MAX использует тот же стандарт).
     *
     * @param string $init_data_raw URL-encoded строка из MAX (уже декодированная).
     * @return bool
     */
    private function validate_init_data( $init_data_raw ) {
        $bot_token = trim( $this->settings['bot_token'] ?? '' );
        if ( empty( $bot_token ) || empty( $init_data_raw ) ) {
            return false;
        }

        $params = array();
        parse_str( $init_data_raw, $params );

        $provided_hash = $params['hash'] ?? '';
        if ( empty( $provided_hash ) ) {
            return false;
        }
        unset( $params['hash'] );

        // Проверяем свежесть данных (не старше 1 часа)
        if ( ! empty( $params['auth_date'] ) ) {
            if ( ( time() - (int) $params['auth_date'] ) > 3600 ) {
                WP_Ru_Max_Logger::log( 'api', 'warning', 'MAX Auth: initData устарел (auth_date > 1 часа).' );
                return false;
            }
        }

        // Строка для проверки: отсортированные пары key=value через \n
        ksort( $params );
        $data_check_string = implode( "\n", array_map(
            function ( $k, $v ) { return "$k=$v"; },
            array_keys( $params ),
            array_values( $params )
        ) );

        // secret_key = HMAC-SHA256("WebAppData", bot_token)
        $secret_key = hash_hmac( 'sha256', $bot_token, 'WebAppData', true );
        // expected_hash = HMAC-SHA256(check_string, secret_key)
        $expected_hash = hash_hmac( 'sha256', $data_check_string, $secret_key );

        return hash_equals( $expected_hash, $provided_hash );
    }

    /**
     * Извлекает данные пользователя из initData.
     * Поле 'user' содержит JSON: { id, first_name, last_name, username, photo_url }.
     */
    private function extract_user_from_initdata( $init_data_raw ) {
        $params = array();
        parse_str( $init_data_raw, $params );

        $user_json = $params['user'] ?? '';
        if ( empty( $user_json ) ) {
            return null;
        }

        $user = json_decode( $user_json, true );
        return is_array( $user ) ? $user : null;
    }

    // ─── Обработка initData из URL ────────────────────────────────────────────

    /**
     * Срабатывает на init/20.
     * Когда пользователь открывает сайт из MAX, мессенджер добавляет в URL
     * параметр initData (или init_data / max_initdata).
     */
    public function handle_url_initdata() {
        if ( is_user_logged_in() ) {
            return;
        }

        $raw = '';
        foreach ( array( 'initData', 'init_data', 'max_initdata' ) as $key ) {
            if ( ! empty( $_GET[ $key ] ) ) {
                $raw = wp_unslash( $_GET[ $key ] );
                break;
            }
        }

        if ( empty( $raw ) ) {
            return;
        }

        // URL-decode (браузер кодирует query string дважды при передаче через MAX)
        $raw = urldecode( $raw );

        if ( ! $this->validate_init_data( $raw ) ) {
            WP_Ru_Max_Logger::log( 'api', 'warning', 'MAX Auth: initData (URL) не прошёл HMAC проверку.' );
            return;
        }

        $user_info = $this->extract_user_from_initdata( $raw );
        if ( empty( $user_info ) ) {
            return;
        }

        $user_id = $this->login_or_create_user( $user_info );
        if ( is_wp_error( $user_id ) ) {
            WP_Ru_Max_Logger::log( 'api', 'error', 'MAX Auth: ' . $user_id->get_error_message() );
            return;
        }

        wp_set_current_user( $user_id );
        wp_set_auth_cookie( $user_id, true );

        $user_obj = get_userdata( $user_id );
        if ( $user_obj ) {
            do_action( 'wp_login', $user_obj->user_login, $user_obj );
        }

        // Перенаправляем на чистый URL без initData
        wp_safe_redirect( remove_query_arg( array( 'initData', 'init_data', 'max_initdata' ) ) );
        exit;
    }

    // ─── AJAX — для JS инициализации MAX Mini App ─────────────────────────────

    /**
     * Принимает initData от клиентского JavaScript,
     * валидирует HMAC и логинит пользователя.
     * Вызывается из inject_miniapp_js().
     */
    public function ajax_validate_initdata() {
        if ( ! check_ajax_referer( self::NONCE_ACTION, 'nonce', false ) ) {
            wp_send_json_error( array( 'message' => 'Security check failed' ), 403 );
        }

        if ( is_user_logged_in() ) {
            wp_send_json_success( array( 'redirect' => home_url() ) );
        }

        $raw = sanitize_text_field( wp_unslash( $_POST['init_data'] ?? '' ) );
        if ( empty( $raw ) ) {
            wp_send_json_error( array( 'message' => 'init_data is empty' ), 400 );
        }

        if ( ! $this->validate_init_data( $raw ) ) {
            WP_Ru_Max_Logger::log( 'api', 'warning', 'MAX Auth: initData (JS) не прошёл HMAC проверку.' );
            wp_send_json_error( array( 'message' => 'Invalid MAX signature' ), 403 );
        }

        $user_info = $this->extract_user_from_initdata( $raw );
        if ( empty( $user_info ) ) {
            wp_send_json_error( array( 'message' => 'No user data in initData' ), 400 );
        }

        $user_id = $this->login_or_create_user( $user_info );
        if ( is_wp_error( $user_id ) ) {
            wp_send_json_error( array( 'message' => $user_id->get_error_message() ), 500 );
        }

        wp_set_current_user( $user_id );
        wp_set_auth_cookie( $user_id, true );

        $user_obj = get_userdata( $user_id );
        if ( $user_obj ) {
            do_action( 'wp_login', $user_obj->user_login, $user_obj );
        }

        // Редирект — только на свой сайт
        $redirect = home_url();
        if ( ! empty( $_POST['redirect'] ) ) {
            $candidate = esc_url_raw( wp_unslash( $_POST['redirect'] ) );
            if ( strpos( $candidate, home_url() ) === 0 ) {
                $redirect = $candidate;
            }
        }

        wp_send_json_success( array( 'redirect' => $redirect ) );
    }

    // ─── Кнопки на фронтенде ─────────────────────────────────────────────────

    private function get_bot_url() {
        $username = trim( $this->settings['max_oauth_bot_username'] ?? '' );
        if ( empty( $username ) ) {
            return '';
        }
        return 'https://max.ru/' . ltrim( $username, '@' );
    }

    public function render_login_button() {
        $bot_url = $this->get_bot_url();
        $icon    = WP_RU_MAX_PLUGIN_URL . 'assets/max-32x32.png';
        ?>
        <div class="wp-ru-max-oauth-wrap">
            <?php if ( $bot_url ) : ?>
            <a href="<?php echo esc_url( $bot_url ); ?>" class="wp-ru-max-oauth-btn" target="_blank" rel="noopener noreferrer">
                <img src="<?php echo esc_url( $icon ); ?>" width="22" height="22" alt="MAX">
                Войти через MAX
            </a>
            <?php else : ?>
            <span class="wp-ru-max-oauth-btn wp-ru-max-oauth-btn-disabled">
                <img src="<?php echo esc_url( $icon ); ?>" width="22" height="22" alt="MAX">
                Войти через MAX
            </span>
            <?php endif; ?>
            <p class="wp-ru-max-oauth-hint">Откройте сайт через бота в MAX — вход произойдёт автоматически</p>
            <div class="wp-ru-max-oauth-divider"><span>или</span></div>
        </div>
        <?php
    }

    public function render_woo_button() {
        if ( is_user_logged_in() ) {
            return;
        }
        $bot_url = $this->get_bot_url();
        $icon    = WP_RU_MAX_PLUGIN_URL . 'assets/max-32x32.png';
        ?>
        <div class="wp-ru-max-oauth-wrap wp-ru-max-oauth-woo">
            <?php if ( $bot_url ) : ?>
            <a href="<?php echo esc_url( $bot_url ); ?>" class="wp-ru-max-oauth-btn" target="_blank" rel="noopener noreferrer">
                <img src="<?php echo esc_url( $icon ); ?>" width="22" height="22" alt="MAX">
                Войти через MAX
            </a>
            <?php else : ?>
            <span class="wp-ru-max-oauth-btn wp-ru-max-oauth-btn-disabled">
                <img src="<?php echo esc_url( $icon ); ?>" width="22" height="22" alt="MAX">
                Войти через MAX
            </span>
            <?php endif; ?>
            <p class="wp-ru-max-oauth-hint">Откройте сайт через бота в MAX — вход произойдёт автоматически</p>
            <div class="wp-ru-max-oauth-divider"><span>или</span></div>
        </div>
        <?php
    }

    public function render_checkout_banner() {
        if ( is_user_logged_in() ) {
            return;
        }
        $bot_url = $this->get_bot_url();
        if ( empty( $bot_url ) ) {
            return;
        }
        $icon = WP_RU_MAX_PLUGIN_URL . 'assets/max-32x32.png';
        ?>
        <div class="wp-ru-max-oauth-checkout">
            <p style="margin:0 0 10px;font-weight:600;font-size:15px;">Быстрый вход перед оформлением:</p>
            <a href="<?php echo esc_url( $bot_url ); ?>" class="wp-ru-max-oauth-btn wp-ru-max-oauth-btn-checkout" target="_blank" rel="noopener noreferrer">
                <img src="<?php echo esc_url( $icon ); ?>" width="22" height="22" alt="MAX">
                Войти через MAX
            </a>
            <p class="wp-ru-max-oauth-hint" style="margin-top:8px;text-align:left;">Откройте сайт через бота MAX — вход произойдёт автоматически</p>
        </div>
        <?php
    }

    public function maybe_show_error( $message ) {
        if ( ! empty( $_GET['max_oauth_error'] ) ) {
            $err      = sanitize_text_field( wp_unslash( $_GET['max_oauth_error'] ) );
            $message .= '<p class="message" style="color:#d63638;background:#fff3f3;padding:8px 12px;border-radius:4px;">'
                . '<strong>Ошибка MAX Auth:</strong> ' . esc_html( $err ) . '</p>';
        }
        return $message;
    }

    // ─── CSS ─────────────────────────────────────────────────────────────────

    public function output_oauth_css() {
        $this->print_oauth_styles();
    }

    public function maybe_output_frontend_css() {
        if (
            ( function_exists( 'is_account_page' ) && is_account_page() ) ||
            ( function_exists( 'is_checkout' )     && is_checkout() )
        ) {
            $this->print_oauth_styles();
        }
    }

    private function print_oauth_styles() {
        ?>
<style id="wp-ru-max-oauth-styles">
.wp-ru-max-oauth-wrap { margin-bottom: 20px; }
.wp-ru-max-oauth-woo  { margin-bottom: 24px; }
.wp-ru-max-oauth-checkout {
    margin-bottom: 24px; padding: 20px;
    border: 1px solid #e0eaff; border-radius: 12px; background: #f5f9ff;
}
.wp-ru-max-oauth-btn {
    display: flex; align-items: center; justify-content: center; gap: 10px;
    width: 100%; padding: 13px 20px; background: #0077ff; color: #fff !important;
    border-radius: 10px; text-decoration: none !important; font-size: 15px;
    font-weight: 600; line-height: 1; box-sizing: border-box; cursor: pointer; border: none;
    transition: background .18s, box-shadow .18s, transform .1s;
    box-shadow: 0 2px 10px rgba(0,119,255,.28);
}
.wp-ru-max-oauth-btn-disabled { opacity: .55; cursor: default; pointer-events: none; }
.wp-ru-max-oauth-btn img { display: block; flex-shrink: 0; }
.wp-ru-max-oauth-btn:not(.wp-ru-max-oauth-btn-disabled):hover {
    background: #005ee0; color: #fff !important;
    box-shadow: 0 4px 16px rgba(0,119,255,.40); transform: translateY(-1px);
}
.wp-ru-max-oauth-btn:active { transform: translateY(0); }
.wp-ru-max-oauth-btn-checkout { font-size: 16px; padding: 14px 24px; }
.wp-ru-max-oauth-hint { font-size: 12px; color: #6b7280; margin: 7px 0 0; text-align: center; }
.wp-ru-max-oauth-divider {
    position: relative; text-align: center; margin: 14px 0 0; color: #aaa; font-size: 13px;
}
.wp-ru-max-oauth-divider::before {
    content: ""; position: absolute; top: 50%; left: 0; right: 0; border-top: 1px solid #ddd;
}
.wp-ru-max-oauth-divider span { position: relative; background: #fff; padding: 0 12px; }
</style>
        <?php
    }

    // ─── JavaScript: детекция MAX Mini App ───────────────────────────────────

    /**
     * Вставляет JS на фронтенде для автоматической авторизации
     * когда пользователь открывает сайт через MAX Mini App.
     * Проверяет window.MaxApp.initData и window.Telegram.WebApp.initData.
     */
    public function inject_miniapp_js() {
        if ( is_user_logged_in() ) {
            return;
        }
        ?>
<script id="wp-ru-max-miniapp-js">
(function () {
    'use strict';

    var AJAX_URL = <?php echo wp_json_encode( esc_url( admin_url( 'admin-ajax.php' ) ) ); ?>;
    var NONCE    = <?php echo wp_json_encode( wp_create_nonce( self::NONCE_ACTION ) ); ?>;
    var CURRENT  = window.location.href;

    // Ищем initData в возможных MAX Mini App JS API
    var initData = null;

    // Вариант 1: официальный MAX Mini App API (если MAX реализует его как window.MaxApp)
    if (window.MaxApp && typeof window.MaxApp.initData === 'string' && window.MaxApp.initData) {
        initData = window.MaxApp.initData;
    }
    // Вариант 2: MAX может предоставлять API аналогично Telegram (window.Telegram.WebApp)
    if (!initData && window.Telegram && window.Telegram.WebApp && window.Telegram.WebApp.initData) {
        initData = window.Telegram.WebApp.initData;
    }

    if (!initData) { return; }

    // Отправляем initData на сервер для HMAC-валидации
    var fd = new FormData();
    fd.append('action',    'max_auth_initdata');
    fd.append('nonce',     NONCE);
    fd.append('init_data', initData);
    fd.append('redirect',  CURRENT);

    fetch(AJAX_URL, { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (resp) {
            if (resp && resp.success && resp.data && resp.data.redirect) {
                window.location.href = resp.data.redirect;
            }
        })
        .catch(function () { /* silent */ });
})();
</script>
        <?php
    }

    // ─── Создание / вход пользователя ────────────────────────────────────────

    /**
     * Находит или создаёт пользователя WordPress по данным из MAX initData.
     * initData.user формат: { id, first_name, last_name, username, photo_url }
     */
    private function login_or_create_user( $user_info ) {
        $max_id  = (string) ( $user_info['id'] ?? '' );
        $fname   = sanitize_text_field( $user_info['first_name'] ?? '' );
        $lname   = sanitize_text_field( $user_info['last_name']  ?? '' );
        $uname   = sanitize_text_field( $user_info['username']   ?? '' );
        $avatar  = esc_url_raw( $user_info['photo_url'] ?? '' );

        $display = trim( $fname . ' ' . $lname );
        if ( ! $display ) {
            $display = $uname ?: 'MAX User';
        }

        // 1. Найти существующего пользователя по MAX ID
        if ( $max_id ) {
            $found = get_users( array(
                'meta_key'   => 'wp_ru_max_oauth_id',
                'meta_value' => $max_id,
                'number'     => 1,
                'fields'     => 'ids',
            ) );
            if ( ! empty( $found ) ) {
                $uid = (int) $found[0];
                if ( $avatar ) update_user_meta( $uid, 'wp_ru_max_avatar_url', $avatar );
                // Обновляем имя, если оно изменилось
                wp_update_user( array(
                    'ID'           => $uid,
                    'display_name' => $display,
                    'first_name'   => $fname,
                    'last_name'    => $lname,
                ) );
                return $uid;
            }
        }

        // 2. Создать нового пользователя
        $username  = $this->make_unique_username( $uname ?: $display );
        $user_data = array(
            'user_login'   => $username,
            'user_pass'    => wp_generate_password( 24, true, true ),
            'display_name' => $display,
            'first_name'   => $fname,
            'last_name'    => $lname,
            'role'         => get_option( 'default_role', 'subscriber' ),
        );

        $user_id = wp_insert_user( $user_data );
        if ( is_wp_error( $user_id ) ) {
            return $user_id;
        }

        if ( $max_id )  update_user_meta( $user_id, 'wp_ru_max_oauth_id', $max_id );
        if ( $avatar )  update_user_meta( $user_id, 'wp_ru_max_avatar_url', $avatar );

        WP_Ru_Max_Logger::log( 'api', 'info',
            sprintf( 'MAX Auth: создан пользователь #%d (%s, MAX ID: %s)', $user_id, $username, $max_id )
        );

        return $user_id;
    }

    private function make_unique_username( $base_name ) {
        $base = sanitize_user( strtolower( str_replace( array( ' ', '-', '.' ), '_', $base_name ) ), true );
        if ( empty( $base ) ) {
            $base = 'max_user';
        }
        $username = $base;
        $i = 1;
        while ( username_exists( $username ) ) {
            $username = $base . '_' . $i;
            $i++;
        }
        return $username;
    }
}
