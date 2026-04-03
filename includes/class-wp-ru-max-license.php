<?php
/**
 * Система лицензирования WP Ru-max
 *
 * Принцип работы:
 * 1. Владелец генерирует ключ (generate-key.php) → SHA256-хэш добавляется в license-keys.json на GitHub
 * 2. Пользователь вводит ключ в плагине
 * 3. Плагин вычисляет SHA256(ключ) и сравнивает с файлом на GitHub
 * 4. При совпадении — плагин активируется навсегда (сохраняется локально)
 * 5. Брутфорс защищён: 5 попыток в час, после — блокировка на 60 минут
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WP_Ru_Max_License {

    private static $instance = null;

    const OPTION_KEY         = 'wp_ru_max_license';
    const RATE_LIMIT_KEY     = 'wp_ru_max_license_attempts';
    const MAX_ATTEMPTS       = 5;
    const BLOCK_MINUTES      = 60;

    // URL файла с SHA256-хэшами ключей на GitHub (raw)
    const GITHUB_KEYS_URL    = 'https://raw.githubusercontent.com/RuCoder-sudo/wp-ru-max/main/license-keys.json';

    // Email владельца для получения запросов на ключ
    const OWNER_EMAIL        = 'rucoder.rf@yandex.ru';

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'wp_ajax_wp_ru_max_activate_license',   array( $this, 'ajax_activate_license' ) );
        add_action( 'wp_ajax_wp_ru_max_request_license',    array( $this, 'ajax_request_license' ) );
        add_action( 'wp_ajax_wp_ru_max_deactivate_license', array( $this, 'ajax_deactivate_license' ) );
        add_action( 'admin_notices',                         array( $this, 'show_activation_notice' ) );
    }

    /**
     * Проверяет, активирован ли плагин
     */
    public static function is_active() {
        $data = get_option( self::OPTION_KEY, array() );
        return ! empty( $data['status'] ) && $data['status'] === 'active';
    }

    /**
     * Возвращает данные лицензии
     */
    public static function get_data() {
        return get_option( self::OPTION_KEY, array() );
    }

    /**
     * Показывает уведомление в админке если плагин не активирован
     */
    public function show_activation_notice() {
        if ( self::is_active() ) {
            return;
        }
        $screen = get_current_screen();
        if ( $screen && strpos( $screen->id, 'wp-ru-max' ) !== false ) {
            return; // Уже на странице плагина — не показываем
        }
        $url = admin_url( 'admin.php?page=wp-ru-max&tab=activation' );
        ?>
        <div class="notice notice-warning is-dismissible">
            <p>
                <img src="<?php echo esc_url( WP_RU_MAX_PLUGIN_URL . 'assets/max-32x32.png' ); ?>" style="vertical-align:middle;width:20px;height:20px;margin-right:6px;" />
                <strong>WP Ru-max</strong> — плагин не активирован. Для доступа ко всем функциям
                <a href="<?php echo esc_url( $url ); ?>"><strong>введите лицензионный ключ</strong></a>.
            </p>
        </div>
        <?php
    }

    /**
     * AJAX: активация плагина по ключу
     */
    public function ajax_activate_license() {
        check_ajax_referer( 'wp_ru_max_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Нет прав доступа.' );
        }

        $key = isset( $_POST['license_key'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['license_key'] ) ) ) : '';

        if ( empty( $key ) ) {
            wp_send_json_error( 'Введите лицензионный ключ.' );
        }

        // Проверка брутфорс-защиты
        $rate_check = $this->check_rate_limit();
        if ( is_wp_error( $rate_check ) ) {
            wp_send_json_error( $rate_check->get_error_message() );
        }

        // Проверка ключа через GitHub
        $result = $this->verify_key( $key );

        if ( is_wp_error( $result ) ) {
            $this->increment_attempts();
            wp_send_json_error( $result->get_error_message() );
        }

        // Ключ верный — сохраняем активацию
        $domain = parse_url( get_site_url(), PHP_URL_HOST );
        update_option( self::OPTION_KEY, array(
            'status'       => 'active',
            'key_hash'     => hash( 'sha256', strtolower( $key ) ),
            'domain'       => $domain,
            'activated_at' => current_time( 'mysql' ),
        ) );

        // Сбрасываем счётчик попыток
        delete_transient( self::RATE_LIMIT_KEY . '_' . $this->get_site_id() );

        WP_Ru_Max_Logger::log( 'license', 'success', 'Плагин успешно активирован на домене ' . $domain );

        wp_send_json_success( array(
            'message' => 'Плагин успешно активирован! Все функции теперь доступны.',
        ) );
    }

    /**
     * AJAX: запрос лицензионного ключа (форма с именем и почтой)
     */
    public function ajax_request_license() {
        check_ajax_referer( 'wp_ru_max_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Нет прав доступа.' );
        }

        $name    = isset( $_POST['req_name'] )  ? sanitize_text_field( wp_unslash( $_POST['req_name'] ) )  : '';
        $email   = isset( $_POST['req_email'] ) ? sanitize_email( wp_unslash( $_POST['req_email'] ) )       : '';
        $consent = isset( $_POST['consent'] )   ? filter_var( $_POST['consent'], FILTER_VALIDATE_BOOLEAN )  : false;
        $mailing = isset( $_POST['mailing'] )   ? filter_var( $_POST['mailing'], FILTER_VALIDATE_BOOLEAN )  : false;

        if ( empty( $name ) ) {
            wp_send_json_error( 'Укажите ваше имя.' );
        }
        if ( ! is_email( $email ) ) {
            wp_send_json_error( 'Укажите корректный email.' );
        }
        if ( ! $consent ) {
            wp_send_json_error( 'Необходимо дать согласие на обработку персональных данных.' );
        }
        if ( ! $mailing ) {
            wp_send_json_error( 'Необходимо дать согласие на получение уведомлений.' );
        }

        $domain   = parse_url( get_site_url(), PHP_URL_HOST );
        $site_url = get_site_url();
        $mailing_text = $mailing ? 'Да' : 'Нет';

        $subject = 'Запрос лицензии WP Ru-max — ' . $name;

        $body  = "=== НОВЫЙ ЗАПРОС ЛИЦЕНЗИИ WP Ru-max ===\n\n";
        $body .= "Имя:   " . $name . "\n";
        $body .= "Email: " . $email . "\n";
        $body .= "Сайт:  " . $site_url . "\n";
        $body .= "Домен: " . $domain . "\n\n";
        $body .= "Согласие на обработку данных: Да\n";
        $body .= "Согласие на рассылку: " . $mailing_text . "\n";
        $body .= "Дата запроса: " . current_time( 'd.m.Y H:i:s' ) . "\n\n";
        $body .= "=== Для выдачи ключа перейдите в панель управления License Manager ===\n";

        $headers = array(
            'Content-Type: text/plain; charset=UTF-8',
            'Reply-To: ' . $name . ' <' . $email . '>',
        );

        $sent = wp_mail( self::OWNER_EMAIL, $subject, $body, $headers );

        if ( $sent ) {
            wp_send_json_success( 'Запрос отправлен! Ожидайте — владелец пришлёт ключ на ваш email ' . $email . ' в ближайшее время.' );
        } else {
            wp_send_json_error( 'Не удалось отправить запрос. Попробуйте написать напрямую: ' . self::OWNER_EMAIL );
        }
    }

    /**
     * AJAX: сброс лицензии (только для отладки, доступен только администратору)
     */
    public function ajax_deactivate_license() {
        check_ajax_referer( 'wp_ru_max_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Нет прав доступа.' );
        }
        delete_option( self::OPTION_KEY );
        wp_send_json_success( 'Лицензия сброшена.' );
    }

    /**
     * Проверяет ключ по SHA256-хэшам на GitHub
     */
    private function verify_key( $key ) {
        $hash = hash( 'sha256', strtolower( $key ) );

        // Загружаем список хэшей с GitHub (с кэшем на 10 минут)
        $cache_key  = 'wp_ru_max_gh_keys';
        $keys_json  = get_transient( $cache_key );

        if ( false === $keys_json ) {
            $response = wp_remote_get( self::GITHUB_KEYS_URL, array(
                'timeout'   => 10,
                'sslverify' => true,
                'headers'   => array( 'Cache-Control' => 'no-cache' ),
            ) );

            if ( is_wp_error( $response ) ) {
                return new WP_Error( 'network_error', 'Не удалось связаться с сервером проверки. Проверьте интернет-соединение.' );
            }

            $code = wp_remote_retrieve_response_code( $response );
            if ( $code !== 200 ) {
                return new WP_Error( 'server_error', 'Сервер проверки недоступен (код ' . $code . '). Попробуйте позже.' );
            }

            $keys_json = wp_remote_retrieve_body( $response );
            set_transient( $cache_key, $keys_json, 10 * MINUTE_IN_SECONDS );
        }

        $data = json_decode( $keys_json, true );

        if ( ! is_array( $data ) || ! isset( $data['keys'] ) || ! is_array( $data['keys'] ) ) {
            return new WP_Error( 'invalid_data', 'Ошибка чтения данных лицензий. Обратитесь к разработчику.' );
        }

        if ( in_array( $hash, $data['keys'], true ) ) {
            return true;
        }

        return new WP_Error( 'invalid_key', 'Неверный лицензионный ключ. Проверьте правильность ввода или запросите новый ключ.' );
    }

    /**
     * Проверяет, не превышен ли лимит попыток
     */
    private function check_rate_limit() {
        $transient_key = self::RATE_LIMIT_KEY . '_' . $this->get_site_id();
        $attempts      = get_transient( $transient_key );

        if ( $attempts === false ) {
            return true; // Нет данных — попытки ещё не было
        }

        if ( (int) $attempts >= self::MAX_ATTEMPTS ) {
            return new WP_Error(
                'rate_limit',
                'Слишком много неверных попыток. Повторите через ' . self::BLOCK_MINUTES . ' минут или запросите ключ у разработчика.'
            );
        }

        return true;
    }

    /**
     * Увеличивает счётчик неверных попыток
     */
    private function increment_attempts() {
        $transient_key = self::RATE_LIMIT_KEY . '_' . $this->get_site_id();
        $attempts      = get_transient( $transient_key );

        if ( $attempts === false ) {
            set_transient( $transient_key, 1, self::BLOCK_MINUTES * MINUTE_IN_SECONDS );
        } else {
            set_transient( $transient_key, (int) $attempts + 1, self::BLOCK_MINUTES * MINUTE_IN_SECONDS );
        }
    }

    /**
     * Уникальный ID сайта для rate limiting
     */
    private function get_site_id() {
        return md5( get_site_url() );
    }

    /**
     * Возвращает оставшееся количество попыток
     */
    public function get_remaining_attempts() {
        $transient_key = self::RATE_LIMIT_KEY . '_' . $this->get_site_id();
        $attempts      = get_transient( $transient_key );
        if ( $attempts === false ) {
            return self::MAX_ATTEMPTS;
        }
        return max( 0, self::MAX_ATTEMPTS - (int) $attempts );
    }
}
