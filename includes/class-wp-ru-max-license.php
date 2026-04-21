<?php
/**
 * Система лицензирования WP Ru-max
 *
 * Принцип работы:
 * 1. На сайте рукодер.рф установлен плагин WP Ru-max Key Manager
 * 2. Владелец создаёт ключ в разделе «Ru-max Ключи» в своей админке
 * 3. Покупатель вводит ключ во вкладке «Активация»
 * 4. Плагин отправляет ключ на рукодер.рф для проверки
 * 5. При успехе — активация сохраняется навсегда в БД WordPress
 * 6. Повторная онлайн-проверка происходит раз в 7 дней
 * 7. Брутфорс защищён: 5 попыток в час, потом блокировка
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WP_Ru_Max_License {

    private static $instance = null;

    const OPTION_KEY      = 'wp_ru_max_license';
    const RATE_LIMIT_KEY  = 'wp_ru_max_license_attempts';
    const MAX_ATTEMPTS    = 5;
    const BLOCK_MINUTES   = 60;
    const RECHECK_DAYS    = 1;
    const RECHECK_SECONDS = 3600; // фоновая перепроверка раз в час

    // URL API проверки ключей (рукодер.рф в Punycode для надёжности)
    const VERIFY_URL      = 'https://xn--d1acnqieq.xn--p1ai/wp-json/wp-ru-max-km/v1/verify';

    // Секрет API — должен совпадать с WPRM_KM_DEFAULT_SECRET в Key Manager
    const API_SECRET      = 'd0563fa8f8fce6879cdf697eed0460a82fa7977897fd364ec911c93ed8bb25b3';

    // Email владельца для получения запросов на ключ
    const OWNER_EMAIL     = 'rucoder.rf@yandex.ru';

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
        add_action( 'wp_ajax_wp_ru_max_recheck_license',    array( $this, 'ajax_recheck_license' ) );
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
            return;
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

        // Брутфорс-защита
        $rate_check = $this->check_rate_limit();
        if ( is_wp_error( $rate_check ) ) {
            wp_send_json_error( $rate_check->get_error_message() );
        }

        // Проверка ключа через рукодер.рф
        $result = $this->verify_key( $key );

        if ( is_wp_error( $result ) ) {
            $this->increment_attempts();
            wp_send_json_error( $result->get_error_message() );
        }

        // Ключ верный — сохраняем
        $domain = parse_url( get_site_url(), PHP_URL_HOST );
        update_option( self::OPTION_KEY, array(
            'status'        => 'active',
            'key'           => $key,
            'domain'        => $domain,
            'activated_at'  => current_time( 'mysql' ),
            'last_verified' => current_time( 'mysql' ),
        ) );

        delete_transient( self::RATE_LIMIT_KEY . '_' . $this->get_site_id() );

        WP_Ru_Max_Logger::log( 'license', 'success', 'Плагин успешно активирован на домене ' . $domain );

        wp_send_json_success( array(
            'message' => 'Плагин успешно активирован! Все функции теперь доступны.',
        ) );
    }

    /**
     * AJAX: запрос лицензионного ключа (форма)
     */
    public function ajax_request_license() {
        check_ajax_referer( 'wp_ru_max_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Нет прав доступа.' );
        }

        $name    = isset( $_POST['req_name'] )   ? sanitize_text_field( wp_unslash( $_POST['req_name'] ) )   : '';
        $email   = isset( $_POST['req_email'] )  ? sanitize_email( wp_unslash( $_POST['req_email'] ) )       : '';
        $social  = isset( $_POST['req_social'] ) ? sanitize_text_field( wp_unslash( $_POST['req_social'] ) ) : '';
        $consent = isset( $_POST['consent'] )    ? filter_var( $_POST['consent'], FILTER_VALIDATE_BOOLEAN )  : false;
        $mailing = isset( $_POST['mailing'] )    ? filter_var( $_POST['mailing'], FILTER_VALIDATE_BOOLEAN )  : false;

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

        $subject = 'Запрос лицензии WP Ru-max — ' . $name;
        $body  = "=== НОВЫЙ ЗАПРОС ЛИЦЕНЗИИ WP Ru-max ===\n\n";
        $body .= "Имя:    " . $name . "\n";
        $body .= "Email:  " . $email . "\n";
        $body .= "Соцсеть/мессенджер: " . ( $social !== '' ? $social : '— не указано —' ) . "\n";
        $body .= "Сайт:   " . $site_url . "\n";
        $body .= "Домен:  " . $domain . "\n\n";
        $body .= "Согласие на обработку данных: Да\n";
        $body .= "Согласие на рассылку: " . ( $mailing ? 'Да' : 'Нет' ) . "\n";
        $body .= "Дата запроса: " . current_time( 'd.m.Y H:i:s' ) . "\n\n";
        $body .= "=== Выдайте ключ на https://рукодер.рф/wp-admin/admin.php?page=wp-ru-max-keys ===\n";

        $headers = array(
            'Content-Type: text/plain; charset=UTF-8',
            'Reply-To: ' . $name . ' <' . $email . '>',
        );

        $sent = wp_mail( self::OWNER_EMAIL, $subject, $body, $headers );

        if ( $sent ) {
            wp_send_json_success( 'Запрос отправлен! Владелец пришлёт ключ на ' . $email . ' в ближайшее время.' );
        } else {
            wp_send_json_error( 'Не удалось отправить запрос. Напишите напрямую: ' . self::OWNER_EMAIL );
        }
    }

    /**
     * AJAX: сброс лицензии (только admin)
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
     * Проверяет ключ через REST API рукодер.рф
     */
    private function verify_key( $key ) {
        $response = wp_remote_post( self::VERIFY_URL, array(
            'timeout'   => 15,
            'sslverify' => true,
            'headers'   => array(
                'Content-Type' => 'application/json',
                'X-WPRM-Secret' => self::API_SECRET,
            ),
            'body' => json_encode( array( 'key' => $key ) ),
        ) );

        if ( is_wp_error( $response ) ) {
            return new WP_Error(
                'network_error',
                'Не удалось связаться с сервером активации. Проверьте интернет-соединение и попробуйте ещё раз.'
            );
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code === 429 ) {
            return new WP_Error( 'rate_limited', 'Сервер временно заблокировал запросы. Попробуйте через 1 час.' );
        }

        if ( $code === 403 ) {
            return new WP_Error( 'auth_error', 'Ошибка авторизации. Обратитесь к разработчику.' );
        }

        if ( ! empty( $body['valid'] ) ) {
            return true;
        }

        return new WP_Error( 'invalid_key', 'Неверный лицензионный ключ. Проверьте правильность ввода.' );
    }

    /**
     * Периодическая перепроверка активного ключа (раз в час).
     * Вызывается из init на каждой загрузке админки.
     */
    public static function recheck_if_needed() {
        if ( ! self::is_active() ) {
            return;
        }
        $data = self::get_data();
        $last = strtotime( $data['last_verified'] ?? '2000-01-01' );
        if ( ( time() - $last ) < self::RECHECK_SECONDS ) {
            return;
        }
        self::do_recheck( $data );
    }

    /**
     * Принудительная перепроверка ключа без учёта интервала.
     * Возвращает обновлённые данные лицензии.
     */
    public static function force_recheck() {
        $data = self::get_data();
        if ( empty( $data['key'] ) ) {
            return $data;
        }
        return self::do_recheck( $data );
    }

    /**
     * Внутренняя реализация перепроверки.
     */
    private static function do_recheck( $data ) {
        $instance = self::instance();
        $result   = $instance->verify_key( $data['key'] ?? '' );

        if ( is_wp_error( $result ) ) {
            $error_code = $result->get_error_code();
            if ( $error_code === 'invalid_key' ) {
                // Ключ отозван или недействителен — немедленная блокировка
                $data['status']         = 'suspended';
                $data['recheck_failed'] = 0;
                WP_Ru_Max_Logger::log( 'license', 'error', 'Лицензия отозвана сервером — плагин деактивирован.' );
            } else {
                // Сетевая ошибка — мягкая блокировка после 2 неудачных попыток
                $data['recheck_failed'] = ( $data['recheck_failed'] ?? 0 ) + 1;
                if ( $data['recheck_failed'] >= 2 ) {
                    $data['status'] = 'suspended';
                }
            }
        } else {
            $data['status']         = 'active';
            $data['recheck_failed'] = 0;
            $data['last_verified']  = current_time( 'mysql' );
        }
        update_option( self::OPTION_KEY, $data );
        return $data;
    }

    /**
     * AJAX: ручная перепроверка лицензии (кнопка в админке)
     */
    public function ajax_recheck_license() {
        check_ajax_referer( 'wp_ru_max_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Нет прав доступа.' );
        }
        $data = self::force_recheck();
        if ( ! empty( $data['status'] ) && $data['status'] === 'active' ) {
            wp_send_json_success( array( 'status' => 'active', 'message' => 'Лицензия действительна.' ) );
        }
        wp_send_json_error( 'Лицензия отозвана или недействительна. Плагин деактивирован.' );
    }

    /**
     * Проверяет, не превышен ли лимит попыток
     */
    private function check_rate_limit() {
        $transient_key = self::RATE_LIMIT_KEY . '_' . $this->get_site_id();
        $attempts      = get_transient( $transient_key );

        if ( $attempts !== false && (int) $attempts >= self::MAX_ATTEMPTS ) {
            return new WP_Error(
                'rate_limit',
                'Слишком много неверных попыток. Повторите через ' . self::BLOCK_MINUTES . ' минут.'
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
