<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WP_Ru_Max_License {

    private static $instance = null;

    const OPTION_KEY         = 'wp_ru_max_license';
    const NETWORK_OPTION_KEY = 'wp_ru_max_network_license';
    const RATE_LIMIT_KEY     = 'wp_ru_max_license_attempts';
    const MAX_ATTEMPTS       = 5;
    const BLOCK_MINUTES      = 60;
    const RECHECK_DAYS       = 160;
    const RECHECK_SECONDS    = 13824000;
    const RECHECK_RETRY_SECONDS = 3600;
    const RECHECK_START_GUARD_SECONDS = 60;
    const INVALID_CONFIRMATIONS_REQUIRED = 3;

    const VERIFY_URL  = 'https://fixcoder.ru/wp-json/wp-ru-max-km/v1/verify';
    const VERIFY_CA   = 'assets/fixcoder-root-yr.pem';
    const API_SECRET  = 'd0563fa8f8fce6879cdf697eed0460a82fa7977897fd364ec911c93ed8bb25b3';
    const OWNER_EMAIL = 'rucoder.rf@yandex.ru';

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'wp_ajax_wp_ru_max_activate_license',         array( $this, 'ajax_activate_license' ) );
        add_action( 'wp_ajax_wp_ru_max_request_license',          array( $this, 'ajax_request_license' ) );
        add_action( 'wp_ajax_wp_ru_max_deactivate_license',       array( $this, 'ajax_deactivate_license' ) );
        add_action( 'wp_ajax_wp_ru_max_recheck_license',          array( $this, 'ajax_recheck_license' ) );
        add_action( 'admin_notices',                               array( $this, 'show_activation_notice' ) );

        if ( is_multisite() ) {
            add_action( 'wp_ajax_wp_ru_max_activate_network_license',   array( $this, 'ajax_activate_network_license' ) );
            add_action( 'wp_ajax_wp_ru_max_deactivate_network_license', array( $this, 'ajax_deactivate_network_license' ) );
            add_action( 'wp_ajax_wp_ru_max_recheck_network_license',    array( $this, 'ajax_recheck_network_license' ) );
            add_action( 'network_admin_notices',                        array( $this, 'show_network_activation_notice' ) );
        }
    }

    public static function is_active() {
        $data = self::get_data();
        if ( ! empty( $data['status'] ) && $data['status'] === 'active' ) {
            return true;
        }

        if ( is_multisite() ) {
            $net_data = self::get_network_data();
            if ( ! empty( $net_data['status'] ) && $net_data['status'] === 'active' ) {
                $scope = $net_data['scope'] ?? 'network';
                if ( $scope === 'network' ) {
                    return true;
                }
                if ( ! empty( $net_data['domain'] ) && self::domain_matches( $net_data['domain'] ) ) {
                    return true;
                }
            }
        }

        return false;
    }

    public static function is_multisite_feature_enabled() {
        $settings = get_option( 'wp_ru_max_settings', array() );
        return ! empty( $settings['multisite_enabled'] );
    }

    public static function is_network_active() {
        if ( ! is_multisite() ) {
            return false;
        }
        $data = self::get_network_data();
        return ! empty( $data['status'] ) && $data['status'] === 'active';
    }

    public static function get_data() {
        $data = get_option( self::OPTION_KEY, array() );
        return self::normalize_legacy_suspension( $data, false );
    }

    public static function get_network_data() {
        $data = get_site_option( self::NETWORK_OPTION_KEY, array() );
        return self::normalize_legacy_suspension( $data, true );
    }

    /**
     * До версии 1.0.48 три быстрых открытия вкладки лицензии могли записать
     * статус suspended. Это было неотличимо от подтверждённого отзыва и
     * блокировало уже настроенный токен MAX. Переводим такой старый статус в
     * состояние «требует проверки», сохраняя сам факт ответа сервера.
     */
    private static function normalize_legacy_suspension( $data, $network ) {
        if ( ! is_array( $data ) || ( $data['status'] ?? '' ) !== 'suspended' ) {
            return is_array( $data ) ? $data : array();
        }

        if ( ! empty( $data['verification_status'] ) ) {
            return $data;
        }

        $data['status']              = 'active';
        $data['verification_status'] = 'revoked';
        $data['legacy_suspension']   = true;

        if ( $network ) {
            update_site_option( self::NETWORK_OPTION_KEY, $data );
        } else {
            update_option( self::OPTION_KEY, $data );
        }

        return $data;
    }

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

    public function show_network_activation_notice() {
        if ( self::is_network_active() ) {
            return;
        }
        $screen = get_current_screen();
        if ( $screen && strpos( $screen->id, 'wp-ru-max' ) !== false ) {
            return;
        }
        $url = network_admin_url( 'admin.php?page=wp-ru-max-network' );
        ?>
        <div class="notice notice-info is-dismissible">
            <p>
                <img src="<?php echo esc_url( WP_RU_MAX_PLUGIN_URL . 'assets/max-32x32.png' ); ?>" style="vertical-align:middle;width:20px;height:20px;margin-right:6px;" />
                <strong>WP Ru-max</strong> — сетевая лицензия не активирована.
                Вы можете <a href="<?php echo esc_url( $url ); ?>"><strong>активировать сетевую лицензию</strong></a>
                (одна лицензия для всей сети) или активировать плагин на каждом подсайте отдельно.
            </p>
        </div>
        <?php
    }

    public function ajax_activate_license() {
        check_ajax_referer( 'wp_ru_max_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Нет прав доступа.' );
        }

        $key = isset( $_POST['license_key'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['license_key'] ) ) ) : '';
        if ( empty( $key ) ) {
            wp_send_json_error( 'Введите лицензионный ключ.' );
        }

        $rate_check = $this->check_rate_limit();
        if ( is_wp_error( $rate_check ) ) {
            wp_send_json_error( $rate_check->get_error_message() );
        }

        $result = $this->verify_key( $key );
        if ( is_wp_error( $result ) ) {
            $this->increment_attempts();
            wp_send_json_error( $result->get_error_message() );
        }

        $domain    = self::get_current_domain();
        $lic_data  = array(
            'status'        => 'active',
            'key'           => $key,
            'domain'        => $domain,
            'activated_at'  => current_time( 'mysql' ),
            'last_verified' => current_time( 'mysql' ),
        );
        update_option( self::OPTION_KEY, $lic_data );

        if ( is_multisite() && self::is_multisite_feature_enabled() ) {
            update_site_option( self::NETWORK_OPTION_KEY, array_merge( $lic_data, array( 'scope' => 'subdomain' ) ) );
        }

        delete_transient( self::RATE_LIMIT_KEY . '_' . $this->get_site_id() );

        WP_Ru_Max_Logger::log( 'license', 'success', 'Плагин успешно активирован на домене ' . $domain );

        $extra = ( is_multisite() && self::is_multisite_feature_enabled() )
            ? ' Все поддомены и подсайты сети также активированы автоматически.'
            : '';

        wp_send_json_success( array(
            'message' => 'Плагин успешно активирован! Все функции теперь доступны.' . $extra,
        ) );
    }

    public function ajax_request_license() {
        check_ajax_referer( 'wp_ru_max_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Нет прав доступа.' );
        }

        $name           = isset( $_POST['req_name'] )            ? sanitize_text_field( wp_unslash( $_POST['req_name'] ) )            : '';
        $email          = isset( $_POST['req_email'] )           ? sanitize_email( wp_unslash( $_POST['req_email'] ) )                : '';
        $site           = isset( $_POST['req_site'] )            ? esc_url_raw( wp_unslash( $_POST['req_site'] ) )                    : '';
        $inn            = isset( $_POST['req_inn'] )             ? sanitize_text_field( wp_unslash( $_POST['req_inn'] ) )             : '';
        $phone          = isset( $_POST['req_phone'] )           ? sanitize_text_field( wp_unslash( $_POST['req_phone'] ) )           : '';
        $social         = isset( $_POST['req_social'] )          ? sanitize_text_field( wp_unslash( $_POST['req_social'] ) )          : '';
        $consent        = isset( $_POST['consent'] )             ? filter_var( wp_unslash( $_POST['consent'] ), FILTER_VALIDATE_BOOLEAN )         : false;
        $mailing        = isset( $_POST['mailing'] )             ? filter_var( wp_unslash( $_POST['mailing'] ), FILTER_VALIDATE_BOOLEAN )         : false;
        $bot_confirmed  = isset( $_POST['bot_info_confirmed'] )  ? filter_var( wp_unslash( $_POST['bot_info_confirmed'] ), FILTER_VALIDATE_BOOLEAN ) : false;

        if ( empty( $name ) )        wp_send_json_error( 'Укажите ваше имя.' );
        if ( ! is_email( $email ) )  wp_send_json_error( 'Укажите корректный email.' );
        if ( empty( $site ) )        wp_send_json_error( 'Укажите ссылку на ваш сайт.' );
        if ( ! $consent )            wp_send_json_error( 'Необходимо дать согласие на обработку персональных данных.' );

        $domain   = self::get_current_domain();
        $site_url = get_site_url();
        $is_ms    = is_multisite() ? ' [Multisite]' : '';

        $subject = 'Запрос лицензии WP Ru-max — ' . $name;
        $body  = "=== НОВЫЙ ЗАПРОС ЛИЦЕНЗИИ WP Ru-max ===\n\n";
        $body .= "Имя:    " . $name . "\n";
        $body .= "Email:  " . $email . "\n";
        $body .= "Сайт заявителя: " . ( $site !== '' ? $site : '— не указано —' ) . "\n";
        $body .= "ИНН:    " . ( $inn !== '' ? $inn : '— не указано —' ) . "\n";
        $body .= "Телефон: " . ( $phone !== '' ? $phone : '— не указано —' ) . "\n";
        $body .= "Соцсеть/мессенджер: " . ( $social !== '' ? $social : '— не указано —' ) . "\n";
        $body .= "Сайт WP (auto): " . $site_url . $is_ms . "\n";
        $body .= "Домен:  " . $domain . "\n\n";
        $body .= "Согласие на обработку данных: Да\n";
        $body .= "Согласие на рассылку: " . ( $mailing ? 'Да' : 'Нет' ) . "\n";
        $body .= "Подтверждение о боте (ИП/ООО): " . ( $bot_confirmed ? 'Да' : 'Нет' ) . "\n";
        $body .= "Дата запроса: " . current_time( 'd.m.Y H:i:s' ) . "\n\n";
        $body .= "=== Выдайте ключ на https://fixcoder.ru/wp-admin/admin.php?page=rclm-keys ===\n";

        $headers = array(
            'Content-Type: text/plain; charset=UTF-8',
            'Reply-To: ' . $name . ' <' . $email . '>',
        );

        $mail_error = '';
        $mail_failed = function ( $error ) use ( &$mail_error ) {
            if ( is_wp_error( $error ) ) {
                $mail_error = $error->get_error_message();
            }
        };
        add_action( 'wp_mail_failed', $mail_failed, 10, 1 );
        $sent = wp_mail( self::OWNER_EMAIL, $subject, $body, $headers );
        remove_action( 'wp_mail_failed', $mail_failed, 10 );

        if ( $sent ) {
            wp_send_json_success( 'Запрос отправлен! Владелец пришлёт ключ на ' . $email . ' в ближайшее время.' );
        } else {
            if ( class_exists( 'WP_Ru_Max_Logger', false ) ) {
                WP_Ru_Max_Logger::log( 'license', 'error', 'Не удалось отправить запрос лицензии через wp_mail().', array(
                    'error' => $mail_error,
                ) );
            }
            wp_send_json_error( 'Не удалось отправить запрос с сайта. Проверьте настройки SMTP или напишите напрямую: ' . self::OWNER_EMAIL );
        }
    }

    public function ajax_deactivate_license() {
        check_ajax_referer( 'wp_ru_max_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Нет прав доступа.' );
        }
        delete_option( self::OPTION_KEY );
        wp_send_json_success( 'Лицензия сброшена.' );
    }

    public function ajax_recheck_license() {
        check_ajax_referer( 'wp_ru_max_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Нет прав доступа.' );
        }
        $data = self::force_recheck();
        if ( ! empty( $data['verification_status'] ) && 'revoked' === $data['verification_status'] ) {
            wp_send_json_error( 'Сервер сообщил о возможном отзыве ключа после повторной проверки. Плагин и уже настроенная автоматическая отправка не остановлены; проверьте ключ у владельца лицензии.' );
        }
        if ( ! empty( $data['status'] ) && $data['status'] === 'active' ) {
            wp_send_json_success( array( 'status' => 'active', 'message' => 'Лицензия действительна.' ) );
        }
        wp_send_json_error( 'Лицензия не подтверждена. Уже настроенная автоматическая отправка не остановлена.' );
    }

    public function ajax_activate_network_license() {
        check_ajax_referer( 'wp_ru_max_network_nonce', 'nonce' );
        if ( ! is_super_admin() ) {
            wp_send_json_error( 'Требуются права суперадминистратора сети.' );
        }

        $key = isset( $_POST['license_key'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['license_key'] ) ) ) : '';
        if ( empty( $key ) ) {
            wp_send_json_error( 'Введите лицензионный ключ.' );
        }

        $result = $this->verify_key( $key );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }

        $domain = self::get_network_domain();
        update_site_option( self::NETWORK_OPTION_KEY, array(
            'status'        => 'active',
            'scope'         => 'network',
            'key'           => $key,
            'domain'        => $domain,
            'activated_at'  => current_time( 'mysql' ),
            'last_verified' => current_time( 'mysql' ),
        ) );

        wp_send_json_success( array(
            'message' => 'Сетевая лицензия активирована! Все подсайты сети теперь имеют доступ ко всем функциям.',
        ) );
    }

    public function ajax_deactivate_network_license() {
        check_ajax_referer( 'wp_ru_max_network_nonce', 'nonce' );
        if ( ! is_super_admin() ) {
            wp_send_json_error( 'Требуются права суперадминистратора сети.' );
        }
        delete_site_option( self::NETWORK_OPTION_KEY );
        wp_send_json_success( 'Сетевая лицензия сброшена.' );
    }

    public function ajax_recheck_network_license() {
        check_ajax_referer( 'wp_ru_max_network_nonce', 'nonce' );
        if ( ! is_super_admin() ) {
            wp_send_json_error( 'Требуются права суперадминистратора сети.' );
        }
        $data = self::force_recheck_network();
        if ( ! empty( $data['verification_status'] ) && 'revoked' === $data['verification_status'] ) {
            wp_send_json_error( 'Сервер сообщил о возможном отзыве сетевого ключа после повторной проверки. Плагин и уже настроенная автоматическая отправка не остановлены; проверьте ключ у владельца лицензии.' );
        }
        if ( ! empty( $data['status'] ) && $data['status'] === 'active' ) {
            wp_send_json_success( array( 'status' => 'active', 'message' => 'Сетевая лицензия действительна.' ) );
        }
        wp_send_json_error( 'Сетевая лицензия отозвана или недействительна.' );
    }

    private function verify_key( $key ) {
        $request_args = array(
            'timeout'   => 15,
            'sslverify' => true,
            'headers'   => array(
                'Content-Type'  => 'application/json',
                'X-WPRM-Secret' => self::API_SECRET,
            ),
            'body' => wp_json_encode( array( 'key' => $key ) ),
        );
        $ca_file = defined( 'WP_RU_MAX_PLUGIN_DIR' ) ? WP_RU_MAX_PLUGIN_DIR . self::VERIFY_CA : '';
        if ( $ca_file && is_readable( $ca_file ) ) {
            $request_args['sslcertificates'] = $ca_file;
        }
        $response = wp_remote_post( self::VERIFY_URL, $request_args );

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
        if ( $code < 200 || $code >= 300 ) {
            return new WP_Error( 'server_error', 'Сервер активации временно недоступен. Лицензия оставлена активной.' );
        }
        if ( ! is_array( $body ) ) {
            return new WP_Error( 'server_error', 'Сервер активации вернул некорректный ответ. Лицензия оставлена активной.' );
        }
        // Сервер лицензий поддерживает несколько форматов ответа. Старый
        // формат использует valid=true, новые ответы могут возвращать
        // status=active или оборачивать данные в data. Не считать любой
        // неизвестный/неполный ответ отзывом лицензии.
        $license_body = isset( $body['data'] ) && is_array( $body['data'] ) ? $body['data'] : $body;
        $valid_value  = $license_body['valid'] ?? null;
        $status_value = strtolower( trim( (string) ( $license_body['status'] ?? $license_body['license_status'] ?? '' ) ) );

        if ( true === $valid_value || 1 === $valid_value || '1' === $valid_value || 'true' === strtolower( (string) $valid_value ) ) {
            return true;
        }

        if ( in_array( $status_value, array( 'active', 'valid', 'ok' ), true ) ) {
            return true;
        }

        if (
            false === $valid_value
            || 0 === $valid_value
            || '0' === $valid_value
            || 'false' === strtolower( (string) $valid_value )
            || in_array( $status_value, array( 'revoked', 'suspended', 'invalid', 'expired', 'inactive', 'blocked' ), true )
        ) {
            return new WP_Error( 'invalid_key', 'Неверный или отозванный лицензионный ключ.' );
        }

        return new WP_Error( 'server_error', 'Сервер активации вернул неполный ответ. Лицензия оставлена активной.' );
    }

    public static function recheck_if_needed() {
        if ( self::is_multisite_feature_enabled() && is_multisite() && self::is_network_active() ) {
            $data = self::get_network_data();
            $last_verified = strtotime( $data['last_verified'] ?? '2000-01-01' );
            $last_attempt  = strtotime( $data['last_recheck_attempt'] ?? $data['last_verified'] ?? '2000-01-01' );
            if ( ( time() - $last_attempt ) < self::RECHECK_RETRY_SECONDS ) {
                return;
            }
            if ( self::recheck_started_recently( $data ) ) {
                return;
            }
            if ( ( time() - $last_verified ) >= self::RECHECK_SECONDS ) {
                self::do_recheck_network( $data );
            }
            return;
        }

        if ( ! self::is_active() ) {
            return;
        }
        $data = self::get_data();
        $last_verified = strtotime( $data['last_verified'] ?? '2000-01-01' );
        $last_attempt  = strtotime( $data['last_recheck_attempt'] ?? $data['last_verified'] ?? '2000-01-01' );
        if ( ( time() - $last_attempt ) < self::RECHECK_RETRY_SECONDS ) {
            return;
        }
        if ( self::recheck_started_recently( $data ) ) {
            return;
        }
        if ( ( time() - $last_verified ) < self::RECHECK_SECONDS ) {
            return;
        }
        self::do_recheck( $data );
    }

    public static function force_recheck() {
        $data = self::get_data();
        if ( empty( $data['key'] ) ) {
            return $data;
        }
        // Не допускаем несколько параллельных AJAX-проверок. В частности,
        // три быстрых обновления вкладки не должны считаться тремя
        // независимыми ответами сервера лицензий.
        if ( self::recheck_started_recently( $data ) ) {
            return $data;
        }
        return self::do_recheck( $data );
    }

    public static function force_recheck_network() {
        $data = self::get_network_data();
        if ( empty( $data['key'] ) ) {
            return $data;
        }
        if ( self::recheck_started_recently( $data ) ) {
            return $data;
        }
        return self::do_recheck_network( $data );
    }

    private static function recheck_started_recently( $data ) {
        $started_at = isset( $data['last_recheck_started_at'] ) ? (int) $data['last_recheck_started_at'] : 0;
        return $started_at > 0 && ( time() - $started_at ) < self::RECHECK_START_GUARD_SECONDS;
    }

    private static function do_recheck( $data ) {
        $instance = self::instance();
        // Записываем маркер до сетевого запроса: другой одновременно
        // открытый запрос увидит его и не отправит второй verify-запрос.
        $data['last_recheck_started_at'] = time();
        update_option( self::OPTION_KEY, $data, false );
        $result   = $instance->verify_key( $data['key'] ?? '' );
        $data['last_recheck_attempt'] = current_time( 'mysql' );

        if ( is_wp_error( $result ) ) {
            $error_code = $result->get_error_code();
            if ( $error_code === 'invalid_key' ) {
                $last_invalid_at = (int) ( $data['last_invalid_recheck_at'] ?? 0 );
                // Не считаем несколько ручных проверок в течение одного
                // часа независимыми подтверждениями отзыва.
                if ( $last_invalid_at <= 0 || ( time() - $last_invalid_at ) >= self::RECHECK_RETRY_SECONDS ) {
                    $data['invalid_recheck_count'] = (int) ( $data['invalid_recheck_count'] ?? 0 ) + 1;
                    $data['last_invalid_recheck_at'] = time();
                }
                $data['verification_status'] = 'invalid';
                if ( $data['invalid_recheck_count'] >= self::INVALID_CONFIRMATIONS_REQUIRED ) {
                    // Не переводим рабочий плагин в suspended автоматически:
                    // ответ сервера может быть устаревшим или ошибочным.
                    // Факт возможного отзыва хранится и показывается админу,
                    // а очередь и существующий токен продолжают работать.
                    $data['verification_status'] = 'revoked';
                    $data['recheck_failed'] = 0;
                    WP_Ru_Max_Logger::log( 'license', 'error', 'Сервер несколько раз сообщил о возможном отзыве лицензии; статус сохранён для проверки администратором, автоматическая отправка не остановлена.' );
                } else {
                    WP_Ru_Max_Logger::log( 'license', 'warning', 'Сервер вернул недействительный статус лицензии; активный статус и автоматическая отправка сохранены до независимого подтверждения.' );
                }
            } elseif ( in_array( $error_code, array( 'network_error', 'server_error' ), true ) ) {
                $data['recheck_failed'] = ( $data['recheck_failed'] ?? 0 ) + 1;
                $data['verification_status'] = 'unavailable';
                WP_Ru_Max_Logger::log( 'license', 'warning', 'Временная ошибка проверки лицензии; активный статус сохранён.' );
            } else {
                $data['recheck_failed'] = ( $data['recheck_failed'] ?? 0 ) + 1;
                $data['verification_status'] = 'unavailable';
            }
        } else {
            $data['status']         = 'active';
            $data['recheck_failed'] = 0;
            $data['invalid_recheck_count'] = 0;
            $data['last_invalid_recheck_at'] = 0;
            $data['verification_status'] = 'verified';
            $data['last_verified']  = current_time( 'mysql' );
        }
        update_option( self::OPTION_KEY, $data );
        return $data;
    }

    private static function do_recheck_network( $data ) {
        $instance = self::instance();
        $data['last_recheck_started_at'] = time();
        update_site_option( self::NETWORK_OPTION_KEY, $data );
        $result   = $instance->verify_key( $data['key'] ?? '' );
        $data['last_recheck_attempt'] = current_time( 'mysql' );

        if ( is_wp_error( $result ) ) {
            $error_code = $result->get_error_code();
            if ( $error_code === 'invalid_key' ) {
                $last_invalid_at = (int) ( $data['last_invalid_recheck_at'] ?? 0 );
                if ( $last_invalid_at <= 0 || ( time() - $last_invalid_at ) >= self::RECHECK_RETRY_SECONDS ) {
                    $data['invalid_recheck_count'] = (int) ( $data['invalid_recheck_count'] ?? 0 ) + 1;
                    $data['last_invalid_recheck_at'] = time();
                }
                $data['verification_status'] = 'invalid';
                if ( $data['invalid_recheck_count'] >= self::INVALID_CONFIRMATIONS_REQUIRED ) {
                    $data['verification_status'] = 'revoked';
                    $data['recheck_failed'] = 0;
                }
            } elseif ( in_array( $error_code, array( 'network_error', 'server_error' ), true ) ) {
                $data['recheck_failed'] = ( $data['recheck_failed'] ?? 0 ) + 1;
                $data['verification_status'] = 'unavailable';
            } else {
                $data['recheck_failed'] = ( $data['recheck_failed'] ?? 0 ) + 1;
                $data['verification_status'] = 'unavailable';
            }
        } else {
            $data['status']         = 'active';
            $data['recheck_failed'] = 0;
            $data['invalid_recheck_count'] = 0;
            $data['last_invalid_recheck_at'] = 0;
            $data['verification_status'] = 'verified';
            $data['last_verified']  = current_time( 'mysql' );
        }
        update_site_option( self::NETWORK_OPTION_KEY, $data );
        return $data;
    }

    public static function get_current_domain() {
        $host = parse_url( get_site_url(), PHP_URL_HOST );
        return $host ? strtolower( $host ) : '';
    }

    public static function get_network_domain() {
        if ( is_multisite() ) {
            $network = get_network();
            return $network ? strtolower( $network->domain ) : self::get_current_domain();
        }
        return self::get_current_domain();
    }

    public static function domain_matches( $licensed_domain, $current_domain = '' ) {
        if ( empty( $licensed_domain ) ) {
            return false;
        }
        if ( empty( $current_domain ) ) {
            $current_domain = self::get_current_domain();
        }
        $licensed_domain = strtolower( trim( $licensed_domain ) );
        $current_domain  = strtolower( trim( $current_domain ) );

        if ( $licensed_domain === $current_domain ) {
            return true;
        }

        if ( str_ends_with( $current_domain, '.' . $licensed_domain ) ) {
            return true;
        }

        $without_www = preg_replace( '/^www\./', '', $current_domain );
        if ( $without_www === $licensed_domain ) {
            return true;
        }

        return false;
    }

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

    private function increment_attempts() {
        $transient_key = self::RATE_LIMIT_KEY . '_' . $this->get_site_id();
        $attempts      = get_transient( $transient_key );
        if ( $attempts === false ) {
            set_transient( $transient_key, 1, self::BLOCK_MINUTES * MINUTE_IN_SECONDS );
        } else {
            set_transient( $transient_key, (int) $attempts + 1, self::BLOCK_MINUTES * MINUTE_IN_SECONDS );
        }
    }

    private function get_site_id() {
        return md5( get_site_url() );
    }

    public function get_remaining_attempts() {
        $transient_key = self::RATE_LIMIT_KEY . '_' . $this->get_site_id();
        $attempts      = get_transient( $transient_key );
        if ( $attempts === false ) {
            return self::MAX_ATTEMPTS;
        }
        return max( 0, self::MAX_ATTEMPTS - (int) $attempts );
    }

    public static function get_days_until_recheck() {
        $data = self::get_data();
        if ( empty( $data['last_verified'] ) ) {
            return 0;
        }
        $last    = strtotime( $data['last_verified'] );
        $next    = $last + self::RECHECK_SECONDS;
        $seconds = $next - time();
        return max( 0, (int) ceil( $seconds / DAY_IN_SECONDS ) );
    }
}

if ( ! function_exists( 'str_ends_with' ) ) {
    function str_ends_with( string $haystack, string $needle ): bool {
        if ( $needle === '' ) return true;
        $len = strlen( $needle );
        return substr( $haystack, -$len ) === $needle;
    }
}
