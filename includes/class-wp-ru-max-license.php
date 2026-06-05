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
    const RECHECK_SECONDS    = 13824000; // 160 * 24 * 3600

    // URL API проверки ключей (рукодер.рф в Punycode для надёжности)
    const VERIFY_URL  = 'https://xn--d1acnqieq.xn--p1ai/wp-json/wp-ru-max-km/v1/verify';

    // Секрет API — должен совпадать с WPRM_KM_DEFAULT_SECRET в Key Manager
    const API_SECRET  = 'd0563fa8f8fce6879cdf697eed0460a82fa7977897fd364ec911c93ed8bb25b3';

    // Email владельца для получения запросов на ключ
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

        // Multisite: сетевые AJAX-обработчики (network admin)
        if ( is_multisite() ) {
            add_action( 'wp_ajax_wp_ru_max_activate_network_license',   array( $this, 'ajax_activate_network_license' ) );
            add_action( 'wp_ajax_wp_ru_max_deactivate_network_license', array( $this, 'ajax_deactivate_network_license' ) );
            add_action( 'wp_ajax_wp_ru_max_recheck_network_license',    array( $this, 'ajax_recheck_network_license' ) );
            add_action( 'network_admin_notices',                        array( $this, 'show_network_activation_notice' ) );
        }
    }

    // ─── Проверка статуса ────────────────────────────────────────────────────

    /**
     * Проверяет, активирован ли плагин.
     *
     * Порядок проверки:
     * 1. Сетевая лицензия (network-wide) — покрывает все подсайты сети
     * 2. Лицензия текущего подсайта (per-site)
     */
    public static function is_active() {
        // 1. Лицензия текущего сайта — всегда проверяется первой
        $data = get_option( self::OPTION_KEY, array() );
        if ( ! empty( $data['status'] ) && $data['status'] === 'active' ) {
            return true;
        }

        // 2. Проверяем сетевую лицензию (только в Multisite)
        if ( is_multisite() ) {
            $net_data = get_site_option( self::NETWORK_OPTION_KEY, array() );
            if ( ! empty( $net_data['status'] ) && $net_data['status'] === 'active' ) {
                $scope = $net_data['scope'] ?? 'network';
                if ( $scope === 'network' ) {
                    // Полная сетевая лицензия (Сеть → Ru-max) — покрывает ВСЕ подсайты
                    return true;
                }
                // scope='subdomain': активирован тумблер на главном сайте.
                // Покрывает ТОЛЬКО поддомены того же домена — example.com крывает sub.example.com.
                // Чужие домены (evil.com при лицензии example.com) НЕ покрываются.
                if ( ! empty( $net_data['domain'] ) && self::domain_matches( $net_data['domain'] ) ) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Проверяет, включён ли тумблер поддержки Multisite/поддоменов.
     * Используется только при СОХРАНЕНИИ лицензии (распространить ли её на сеть).
     * На поддоменах тумблер НЕ требуется — проверка происходит автоматически.
     */
    public static function is_multisite_feature_enabled() {
        $settings = get_option( 'wp_ru_max_settings', array() );
        return ! empty( $settings['multisite_enabled'] );
    }

    /**
     * Проверяет, активна ли сетевая лицензия (для всей Multisite-сети).
     */
    public static function is_network_active() {
        if ( ! is_multisite() ) {
            return false;
        }
        $data = get_site_option( self::NETWORK_OPTION_KEY, array() );
        return ! empty( $data['status'] ) && $data['status'] === 'active';
    }

    /**
     * Возвращает данные лицензии текущего сайта.
     */
    public static function get_data() {
        return get_option( self::OPTION_KEY, array() );
    }

    /**
     * Возвращает данные сетевой лицензии.
     */
    public static function get_network_data() {
        return get_site_option( self::NETWORK_OPTION_KEY, array() );
    }

    /**
     * Показывает уведомление в обычной админке, если плагин не активирован.
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
     * Показывает уведомление в сетевой админке, если сетевая лицензия не активирована.
     */
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

    // ─── AJAX: лицензия подсайта ─────────────────────────────────────────────

    /**
     * AJAX: активация плагина по ключу (для текущего подсайта).
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

        // Если тумблер включён и это Multisite — автоматически сохраняем лицензию как общую для сети.
        // Это позволяет поддоменам и подсайтам пользоваться лицензией БЕЗ отдельной активации.
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

    /**
     * AJAX: запрос лицензионного ключа (форма).
     */
    public function ajax_request_license() {
        check_ajax_referer( 'wp_ru_max_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Нет прав доступа.' );
        }

        $name           = isset( $_POST['req_name'] )           ? sanitize_text_field( wp_unslash( $_POST['req_name'] ) )           : '';
        $email          = isset( $_POST['req_email'] )          ? sanitize_email( wp_unslash( $_POST['req_email'] ) )               : '';
        $site           = isset( $_POST['req_site'] )           ? esc_url_raw( wp_unslash( $_POST['req_site'] ) )                   : '';
        $social         = isset( $_POST['req_social'] )         ? sanitize_text_field( wp_unslash( $_POST['req_social'] ) )         : '';
        $consent        = isset( $_POST['consent'] )            ? filter_var( wp_unslash( $_POST['consent'] ), FILTER_VALIDATE_BOOLEAN )         : false;
        $mailing        = isset( $_POST['mailing'] )            ? filter_var( wp_unslash( $_POST['mailing'] ), FILTER_VALIDATE_BOOLEAN )         : false;
        $bot_confirmed  = isset( $_POST['bot_info_confirmed'] ) ? filter_var( wp_unslash( $_POST['bot_info_confirmed'] ), FILTER_VALIDATE_BOOLEAN ) : false;

        if ( empty( $name ) )        wp_send_json_error( 'Укажите ваше имя.' );
        if ( ! is_email( $email ) )  wp_send_json_error( 'Укажите корректный email.' );
        if ( empty( $site ) )        wp_send_json_error( 'Укажите ссылку на ваш сайт.' );
        if ( ! $consent )            wp_send_json_error( 'Необходимо дать согласие на обработку персональных данных.' );
        if ( ! $mailing )            wp_send_json_error( 'Необходимо дать согласие на получение уведомлений.' );

        $domain   = self::get_current_domain();
        $site_url = get_site_url();
        $is_ms    = is_multisite() ? ' [Multisite]' : '';

        $subject = 'Запрос лицензии WP Ru-max — ' . $name;
        $body  = "=== НОВЫЙ ЗАПРОС ЛИЦЕНЗИИ WP Ru-max ===\n\n";
        $body .= "Имя:    " . $name . "\n";
        $body .= "Email:  " . $email . "\n";
        $body .= "Сайт заявителя: " . ( $site !== '' ? $site : '— не указано —' ) . "\n";
        $body .= "Соцсеть/мессенджер: " . ( $social !== '' ? $social : '— не указано —' ) . "\n";
        $body .= "Сайт WP (auto): " . $site_url . $is_ms . "\n";
        $body .= "Домен:  " . $domain . "\n\n";
        $body .= "Согласие на обработку данных: Да\n";
        $body .= "Согласие на рассылку: " . ( $mailing ? 'Да' : 'Нет' ) . "\n";
        $body .= "Подтверждение о боте (ИП/ООО): " . ( $bot_confirmed ? 'Да' : 'Нет' ) . "\n";
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
     * AJAX: сброс лицензии текущего сайта.
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
     * AJAX: ручная перепроверка лицензии текущего сайта.
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

    // ─── AJAX: сетевая лицензия (Multisite) ──────────────────────────────────

    /**
     * AJAX: активация сетевой лицензии (для всей Multisite-сети).
     * Требует прав super admin.
     */
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

    /**
     * AJAX: сброс сетевой лицензии.
     */
    public function ajax_deactivate_network_license() {
        check_ajax_referer( 'wp_ru_max_network_nonce', 'nonce' );
        if ( ! is_super_admin() ) {
            wp_send_json_error( 'Требуются права суперадминистратора сети.' );
        }
        delete_site_option( self::NETWORK_OPTION_KEY );
        wp_send_json_success( 'Сетевая лицензия сброшена.' );
    }

    /**
     * AJAX: перепроверка сетевой лицензии.
     */
    public function ajax_recheck_network_license() {
        check_ajax_referer( 'wp_ru_max_network_nonce', 'nonce' );
        if ( ! is_super_admin() ) {
            wp_send_json_error( 'Требуются права суперадминистратора сети.' );
        }
        $data = self::force_recheck_network();
        if ( ! empty( $data['status'] ) && $data['status'] === 'active' ) {
            wp_send_json_success( array( 'status' => 'active', 'message' => 'Сетевая лицензия действительна.' ) );
        }
        wp_send_json_error( 'Сетевая лицензия отозвана или недействительна.' );
    }

    // ─── Проверка ключа через API ─────────────────────────────────────────────

    /**
     * Проверяет ключ через REST API рукодер.рф.
     */
    private function verify_key( $key ) {
        $response = wp_remote_post( self::VERIFY_URL, array(
            'timeout'   => 15,
            'sslverify' => true,
            'headers'   => array(
                'Content-Type'  => 'application/json',
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

    // ─── Периодические проверки ───────────────────────────────────────────────

    /**
     * Проверяет, нужно ли обновить лицензию (вызывается на каждой загрузке).
     */
    public static function recheck_if_needed() {
        // Проверяем сетевую лицензию (только если тумблер включён)
        if ( self::is_multisite_feature_enabled() && is_multisite() && self::is_network_active() ) {
            $data = self::get_network_data();
            $last = strtotime( $data['last_verified'] ?? '2000-01-01' );
            if ( ( time() - $last ) >= self::RECHECK_SECONDS ) {
                self::do_recheck_network( $data );
            }
            return; // Сетевая лицензия покрывает текущий сайт
        }

        // Проверяем лицензию текущего сайта
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
     * Принудительная перепроверка лицензии сайта.
     */
    public static function force_recheck() {
        $data = self::get_data();
        if ( empty( $data['key'] ) ) {
            return $data;
        }
        return self::do_recheck( $data );
    }

    /**
     * Принудительная перепроверка сетевой лицензии.
     */
    public static function force_recheck_network() {
        $data = self::get_network_data();
        if ( empty( $data['key'] ) ) {
            return $data;
        }
        return self::do_recheck_network( $data );
    }

    /**
     * Внутренняя реализация перепроверки лицензии сайта.
     */
    private static function do_recheck( $data ) {
        $instance = self::instance();
        $result   = $instance->verify_key( $data['key'] ?? '' );

        if ( is_wp_error( $result ) ) {
            $error_code = $result->get_error_code();
            if ( $error_code === 'invalid_key' ) {
                $data['status']         = 'suspended';
                $data['recheck_failed'] = 0;
                WP_Ru_Max_Logger::log( 'license', 'error', 'Лицензия отозвана сервером — плагин деактивирован.' );
            } else {
                $data['recheck_failed'] = ( $data['recheck_failed'] ?? 0 ) + 1;
                if ( $data['recheck_failed'] >= 3 ) {
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
     * Внутренняя реализация перепроверки сетевой лицензии.
     */
    private static function do_recheck_network( $data ) {
        $instance = self::instance();
        $result   = $instance->verify_key( $data['key'] ?? '' );

        if ( is_wp_error( $result ) ) {
            $error_code = $result->get_error_code();
            if ( $error_code === 'invalid_key' ) {
                $data['status']         = 'suspended';
                $data['recheck_failed'] = 0;
            } else {
                $data['recheck_failed'] = ( $data['recheck_failed'] ?? 0 ) + 1;
                if ( $data['recheck_failed'] >= 3 ) {
                    $data['status'] = 'suspended';
                }
            }
        } else {
            $data['status']         = 'active';
            $data['recheck_failed'] = 0;
            $data['last_verified']  = current_time( 'mysql' );
        }
        update_site_option( self::NETWORK_OPTION_KEY, $data );
        return $data;
    }

    // ─── Вспомогательные методы ───────────────────────────────────────────────

    /**
     * Возвращает домен текущего сайта (без www).
     */
    public static function get_current_domain() {
        $host = parse_url( get_site_url(), PHP_URL_HOST );
        return $host ? strtolower( $host ) : '';
    }

    /**
     * Возвращает главный домен сети (для Multisite).
     */
    public static function get_network_domain() {
        if ( is_multisite() ) {
            $network = get_network();
            return $network ? strtolower( $network->domain ) : self::get_current_domain();
        }
        return self::get_current_domain();
    }

    /**
     * Проверяет, покрывает ли лицензия с доменом $licensed_domain текущий домен.
     * Лицензия на корневой домен (example.com) покрывает поддомены (sub.example.com).
     *
     * @param string $licensed_domain Домен, на который выдана лицензия.
     * @param string $current_domain  Текущий домен сайта (по умолчанию — текущий).
     * @return bool
     */
    public static function domain_matches( $licensed_domain, $current_domain = '' ) {
        if ( empty( $licensed_domain ) ) {
            return false;
        }
        if ( empty( $current_domain ) ) {
            $current_domain = self::get_current_domain();
        }
        $licensed_domain = strtolower( trim( $licensed_domain ) );
        $current_domain  = strtolower( trim( $current_domain ) );

        // Точное совпадение
        if ( $licensed_domain === $current_domain ) {
            return true;
        }

        // Поддомен: current = sub.example.com, licensed = example.com
        if ( str_ends_with( $current_domain, '.' . $licensed_domain ) ) {
            return true;
        }

        // Поддомен: current = www.example.com, licensed = example.com
        $without_www = preg_replace( '/^www\./', '', $current_domain );
        if ( $without_www === $licensed_domain ) {
            return true;
        }

        return false;
    }

    /**
     * Проверяет ограничение по количеству попыток активации.
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
     * Увеличивает счётчик неверных попыток.
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
     * Уникальный ID сайта для rate limiting.
     */
    private function get_site_id() {
        return md5( get_site_url() );
    }

    /**
     * Возвращает оставшееся количество попыток.
     */
    public function get_remaining_attempts() {
        $transient_key = self::RATE_LIMIT_KEY . '_' . $this->get_site_id();
        $attempts      = get_transient( $transient_key );
        if ( $attempts === false ) {
            return self::MAX_ATTEMPTS;
        }
        return max( 0, self::MAX_ATTEMPTS - (int) $attempts );
    }

    /**
     * Возвращает количество дней до следующей проверки.
     */
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

// PHP < 8.0 compatibility (str_ends_with is native in PHP 8.0+, polyfill for 7.4)
if ( ! function_exists( 'str_ends_with' ) ) {
    function str_ends_with( string $haystack, string $needle ): bool {
        if ( $needle === '' ) return true;
        $len = strlen( $needle );
        return substr( $haystack, -$len ) === $needle;
    }
}
