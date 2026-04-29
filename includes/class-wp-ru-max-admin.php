<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WP_Ru_Max_Admin {

    private static $instance = null;

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'admin_menu',                        array( $this, 'add_menu' ) );
        add_action( 'admin_head',                        array( $this, 'admin_icon_css' ) );
        add_action( 'admin_enqueue_scripts',             array( $this, 'enqueue_admin_scripts' ) );
        add_action( 'enqueue_block_editor_assets',       array( $this, 'enqueue_gutenberg_panel' ) );
        add_action( 'init',                              array( $this, 'maybe_migrate_skip_meta' ), 5 );
        add_action( 'init',                              array( $this, 'register_post_meta' ) );
        add_action( 'rest_api_init',                     array( $this, 'register_rest_routes' ) );
        add_action( 'wp_ajax_wp_ru_max_save_settings',   array( $this, 'ajax_save_settings' ) );
        add_action( 'wp_ajax_wp_ru_max_test_connection', array( $this, 'ajax_test_connection' ) );
        add_action( 'wp_ajax_wp_ru_max_send_test_message', array( $this, 'ajax_send_test_message' ) );
        add_action( 'wp_ajax_wp_ru_max_get_logs',        array( $this, 'ajax_get_logs' ) );
        add_action( 'wp_ajax_wp_ru_max_clear_logs',      array( $this, 'ajax_clear_logs' ) );
        add_action( 'wp_ajax_wp_ru_max_send_post_now',   array( $this, 'ajax_send_post_now' ) );
        add_action( 'wp_ajax_wp_ru_max_get_skip',        array( $this, 'ajax_get_skip' ) );
        add_action( 'wp_ajax_wp_ru_max_set_skip',        array( $this, 'ajax_set_skip' ) );
        add_action( 'save_post',                         array( $this, 'persist_skip_meta_on_save' ), 10, 2 );
        add_filter( 'plugin_action_links_' . WP_RU_MAX_PLUGIN_BASENAME, array( $this, 'add_plugin_links' ) );
    }

    /**
     * Регистрация собственного REST-маршрута для тумблера «Автоотправка».
     *
     * Используем СВОЁ простое API вместо стандартного meta-через-REST,
     * потому что у некоторых пользователей Гутенберг по неизвестной причине
     * не отправляет meta-поле в теле запроса (хук rest_after_insert не
     * срабатывает или приходит без нашего ключа). Свой маршрут даёт нам
     * 100% контроль: тумблер сохраняется СРАЗУ при клике, без ожидания
     * «Сохранить черновик».
     */
    public function register_rest_routes() {
        register_rest_route( 'wp-ru-max/v1', '/skip/(?P<post_id>\d+)', array(
            array(
                'methods'             => 'GET',
                'callback'            => array( $this, 'rest_get_skip' ),
                'permission_callback' => array( $this, 'rest_skip_permission' ),
                'args'                => array(
                    'post_id' => array( 'validate_callback' => 'is_numeric' ),
                ),
            ),
            array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'rest_set_skip' ),
                'permission_callback' => array( $this, 'rest_skip_permission' ),
                'args'                => array(
                    'post_id' => array( 'validate_callback' => 'is_numeric' ),
                    'on'      => array( 'required' => true ),
                ),
            ),
        ) );
    }

    public function rest_skip_permission( $request ) {
        $post_id = (int) $request['post_id'];
        return $post_id > 0 && current_user_can( 'edit_post', $post_id );
    }

    /**
     * GET /wp-json/wp-ru-max/v1/skip/{post_id}
     * Возвращает текущее состояние тумблера ИЗ БД (с очисткой кэша).
     */
    public function rest_get_skip( $request ) {
        $post_id = (int) $request['post_id'];
        wp_cache_delete( $post_id, 'post_meta' );

        $skip = get_post_meta( $post_id, self::SKIP_META_KEY, true );
        if ( $skip === '' || $skip === null || $skip === false ) {
            $legacy = get_post_meta( $post_id, self::SKIP_META_KEY_LEGACY, true );
            if ( $legacy !== '' && $legacy !== null && $legacy !== false ) {
                $skip = $legacy;
            }
        }
        $skip_str = is_scalar( $skip ) ? trim( (string) $skip ) : '';
        $is_on    = ( $skip_str === '0' );

        return rest_ensure_response( array(
            'on'     => $is_on,
            'stored' => $skip_str,
        ) );
    }

    /**
     * POST /wp-json/wp-ru-max/v1/skip/{post_id}
     * Body: { on: true|false|1|0 }
     * Сохраняет состояние тумблера и сразу читает обратно из БД.
     */
    public function rest_set_skip( $request ) {
        $post_id = (int) $request['post_id'];
        $on_raw  = $request->get_param( 'on' );

        // Любое истинное значение → ВКЛ ('0' в нашей семантике).
        $is_on = ( $on_raw === true || $on_raw === 1 || $on_raw === '1' || $on_raw === 'true' || $on_raw === 'on' );
        $value = $is_on ? '0' : '1';

        update_post_meta( $post_id, self::SKIP_META_KEY, $value );
        // Старый ключ удаляем, чтобы не было путаницы.
        delete_post_meta( $post_id, self::SKIP_META_KEY_LEGACY );

        // Контрольное чтение БЕЗ кэша.
        wp_cache_delete( $post_id, 'post_meta' );
        $stored = get_post_meta( $post_id, self::SKIP_META_KEY, true );

        WP_Ru_Max_Logger::log(
            'post_sender',
            'info',
            sprintf(
                'Тумблер #%d через свой REST: запрошено %s → сохранено "%s" → в БД "%s" (%s)',
                $post_id,
                $is_on ? 'ВКЛ' : 'ВЫКЛ',
                $value,
                (string) $stored,
                $stored === '0' ? 'ВКЛ' : 'ВЫКЛ'
            ),
            array(
                'post_id'      => $post_id,
                'requested_on' => $is_on,
                'sent_value'   => $value,
                'stored'       => $stored,
            )
        );

        return rest_ensure_response( array(
            'on'     => ( $stored === '0' ),
            'stored' => $stored,
            'sent'   => $value,
        ) );
    }

    /**
     * AJAX-fallback (admin-ajax.php) на случай, если REST-маршрут заблокирован
     * на сайте (например, Wordfence). По функциональности идентичен rest_set_skip.
     */
    public function ajax_set_skip() {
        check_ajax_referer( 'wp_ru_max_nonce', 'nonce' );
        $post_id = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;
        if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
            wp_send_json_error( 'Нет прав доступа.' );
        }
        $on_raw = isset( $_POST['on'] ) ? wp_unslash( $_POST['on'] ) : '0';
        $is_on  = ( $on_raw === true || $on_raw === 1 || $on_raw === '1' || $on_raw === 'true' || $on_raw === 'on' );
        $value  = $is_on ? '0' : '1';

        update_post_meta( $post_id, self::SKIP_META_KEY, $value );
        delete_post_meta( $post_id, self::SKIP_META_KEY_LEGACY );

        wp_cache_delete( $post_id, 'post_meta' );
        $stored = get_post_meta( $post_id, self::SKIP_META_KEY, true );

        WP_Ru_Max_Logger::log(
            'post_sender',
            'info',
            sprintf(
                'Тумблер #%d через AJAX-fallback: запрошено %s → сохранено "%s" → в БД "%s" (%s)',
                $post_id,
                $is_on ? 'ВКЛ' : 'ВЫКЛ',
                $value,
                (string) $stored,
                $stored === '0' ? 'ВКЛ' : 'ВЫКЛ'
            ),
            array(
                'post_id'    => $post_id,
                'sent_value' => $value,
                'stored'     => $stored,
            )
        );

        wp_send_json_success( array(
            'on'     => ( $stored === '0' ),
            'stored' => $stored,
        ) );
    }

    public function ajax_get_skip() {
        check_ajax_referer( 'wp_ru_max_nonce', 'nonce' );
        $post_id = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;
        if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
            wp_send_json_error( 'Нет прав доступа.' );
        }
        wp_cache_delete( $post_id, 'post_meta' );
        $skip = get_post_meta( $post_id, self::SKIP_META_KEY, true );
        if ( $skip === '' || $skip === null || $skip === false ) {
            $legacy = get_post_meta( $post_id, self::SKIP_META_KEY_LEGACY, true );
            if ( $legacy !== '' && $legacy !== null && $legacy !== false ) {
                $skip = $legacy;
            }
        }
        $skip_str = is_scalar( $skip ) ? trim( (string) $skip ) : '';
        wp_send_json_success( array(
            'on'     => ( $skip_str === '0' ),
            'stored' => $skip_str,
        ) );
    }

    /**
     * Имя метаключа для тумблера «Автоотправка в MAX».
     * Намеренно БЕЗ ведущего подчёркивания, чтобы исключить конфликт с
     * «protected meta» в WordPress: protected-ключи REST API (Гутенберг)
     * молча отказывается обновлять, даже при register_post_meta + auth_callback.
     */
    const SKIP_META_KEY        = 'wp_ru_max_skip';
    const SKIP_META_KEY_LEGACY = '_wp_ru_max_skip';

    /**
     * Одноразовая миграция значений из старого protected-ключа
     * `_wp_ru_max_skip` в новый публичный ключ `wp_ru_max_skip`.
     */
    public function maybe_migrate_skip_meta() {
        if ( get_option( 'wp_ru_max_skip_meta_migrated_v1' ) ) {
            return;
        }
        global $wpdb;
        // Переименовываем только те строки, где новой записи ещё нет.
        $wpdb->query( $wpdb->prepare(
            "UPDATE {$wpdb->postmeta} pm1
             LEFT JOIN {$wpdb->postmeta} pm2
                 ON pm2.post_id = pm1.post_id AND pm2.meta_key = %s
             SET pm1.meta_key = %s
             WHERE pm1.meta_key = %s AND pm2.meta_id IS NULL",
            self::SKIP_META_KEY,
            self::SKIP_META_KEY,
            self::SKIP_META_KEY_LEGACY
        ) );
        // Удаляем оставшиеся дубликаты старого ключа.
        $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$wpdb->postmeta} WHERE meta_key = %s",
            self::SKIP_META_KEY_LEGACY
        ) );
        update_option( 'wp_ru_max_skip_meta_migrated_v1', 1 );
    }

    /**
     * Семантика значений мета-ключа «Автоотправка в MAX»:
     *
     *   '1' (или отсутствует) = автоотправка ВЫКЛ (по умолчанию).
     *   '0'                   = автоотправка ВКЛ (автор явно включил).
     *
     * Намеренно НЕ задаём `default` в register_post_meta:
     * WordPress в этом случае при сохранении значения, равного default,
     * выполняет специальную обработку (может удалить запись из postmeta
     * или иначе вмешаться в стандартное update_metadata). Без default
     * запись всегда сохраняется явно: '0' или '1'. Дефолт «ВЫКЛ»
     * реализован на уровне чтения (см. JS и WP_Ru_Max_Post_Sender).
     */

    public function register_post_meta() {
        $post_types = get_post_types( array( 'public' => true ) );
        foreach ( $post_types as $post_type ) {
            register_post_meta( $post_type, self::SKIP_META_KEY, array(
                'show_in_rest'      => array(
                    'schema' => array(
                        'type'    => 'string',
                        'enum'    => array( '0', '1' ),
                        'context' => array( 'view', 'edit' ),
                    ),
                ),
                'single'            => true,
                'type'              => 'string',
                'sanitize_callback' => array( $this, 'sanitize_skip_meta' ),
                'auth_callback'     => function() { return current_user_can( 'edit_posts' ); },
            ) );

            // Серверная страховка: после стандартной REST-обработки мета
            // принудительно перезаписываем значение из тела запроса —
            // исключает любые гонки с другими хуками или плагинами.
            add_action(
                'rest_after_insert_' . $post_type,
                array( $this, 'persist_skip_meta_from_rest' ),
                10, 3
            );
        }
    }

    /**
     * Нормализация значения тумблера «Автоотправка в MAX».
     * Только '0' (включая 0/false/'') трактуется как «ВКЛ».
     * Всё остальное — '1' (ВЫКЛ, безопасный дефолт).
     */
    public function sanitize_skip_meta( $value ) {
        if ( $value === '0' || $value === 0 || $value === false ) {
            return '0';
        }
        if ( is_string( $value ) && trim( $value ) === '0' ) {
            return '0';
        }
        return '1';
    }

    /**
     * Серверная страховочная запись значения «Автоотправка в MAX»
     * для REST-контекста (Гутенберг).
     *
     * Используем update_post_meta (а не delete + add) — он атомарен
     * и не зависит от порядка хуков. Затем сразу читаем значение
     * обратно из БД и пишем в лог — это даёт однозначное подтверждение,
     * что именно лежит в postmeta после сохранения.
     */
    public function persist_skip_meta_from_rest( $post, $request, $creating ) {
        if ( ! ( $request instanceof WP_REST_Request ) ) {
            return;
        }
        if ( ! is_object( $post ) || empty( $post->ID ) ) {
            return;
        }
        if ( ! current_user_can( 'edit_post', $post->ID ) ) {
            return;
        }

        $meta = $request->get_param( 'meta' );
        if ( ! is_array( $meta ) ) {
            return;
        }

        if ( ! array_key_exists( self::SKIP_META_KEY, $meta ) ) {
            return;
        }

        $raw        = $meta[ self::SKIP_META_KEY ];
        $normalized = $this->sanitize_skip_meta( $raw );

        // Атомарное обновление: WP сам выберет UPDATE или INSERT.
        update_post_meta( $post->ID, self::SKIP_META_KEY, $normalized );

        // Контрольное чтение из БД — без кэша.
        wp_cache_delete( $post->ID, 'post_meta' );
        $stored = get_post_meta( $post->ID, self::SKIP_META_KEY, true );

        WP_Ru_Max_Logger::log(
            'post_sender',
            'info',
            sprintf(
                'Тумблер «Автоотправка в MAX» для записи #%d: пришло "%s" → нормализовано "%s" → в БД "%s" (%s)',
                $post->ID,
                is_scalar( $raw ) ? (string) $raw : gettype( $raw ),
                $normalized,
                (string) $stored,
                $stored === '0' ? 'ВКЛ' : 'ВЫКЛ'
            ),
            array(
                'post_id'    => $post->ID,
                'raw'        => $raw,
                'normalized' => $normalized,
                'stored'     => $stored,
            )
        );
    }

    /**
     * Серверная страховочная запись для классического редактора
     * и любых не-REST контекстов сохранения записи.
     */
    public function persist_skip_meta_on_save( $post_id, $post ) {
        if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
            return;
        }
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }
        // REST обрабатывается отдельно через rest_after_insert_*.
        if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
            return;
        }

        $value = null;
        if ( isset( $_POST[ self::SKIP_META_KEY ] ) ) {
            $value = wp_unslash( $_POST[ self::SKIP_META_KEY ] );
        } elseif ( isset( $_POST[ self::SKIP_META_KEY_LEGACY ] ) ) {
            $value = wp_unslash( $_POST[ self::SKIP_META_KEY_LEGACY ] );
        }

        if ( $value === null ) {
            return;
        }

        update_post_meta( $post_id, self::SKIP_META_KEY, $this->sanitize_skip_meta( $value ) );
    }

    public function admin_icon_css() {
        ?>
<style>
#adminmenu .wp-menu-image img {
    padding: 0px 0 0;
    opacity: 1.6;
}
#toplevel_page_wp-ru-max .wp-menu-image img {
    width: 18px !important;
    height: 18px !important;
    padding: 7px 0 0 !important;
    opacity: 1 !important;
}
</style>
        <?php
    }

    public function enqueue_gutenberg_panel() {
        $settings = get_option( 'wp_ru_max_settings', array() );
        $token    = isset( $settings['bot_token'] ) ? $settings['bot_token'] : '';
        $channels = isset( $settings['channels'] ) ? (array) $settings['channels'] : array();

        if ( empty( $token ) || empty( $channels ) ) {
            return;
        }

        // Версия скрипта = время изменения файла. Это гарантирует
        // сброс кэша браузера на старый gutenberg-panel.js даже без
        // изменения общей версии плагина.
        $gutenberg_js_path = WP_RU_MAX_PLUGIN_DIR . 'assets/gutenberg-panel.js';
        $gutenberg_js_ver  = file_exists( $gutenberg_js_path )
            ? (string) filemtime( $gutenberg_js_path )
            : WP_RU_MAX_VERSION;

        wp_enqueue_script(
            'wp-ru-max-gutenberg',
            WP_RU_MAX_PLUGIN_URL . 'assets/gutenberg-panel.js',
            array( 'jquery', 'wp-plugins', 'wp-edit-post', 'wp-element', 'wp-components', 'wp-data' ),
            $gutenberg_js_ver,
            true
        );
        wp_localize_script( 'wp-ru-max-gutenberg', 'wpRuMaxGutenberg', array(
            'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'wp_ru_max_nonce' ),
            'iconUrl'  => WP_RU_MAX_PLUGIN_URL . 'assets/max-32x32.png',
            'restUrl'  => esc_url_raw( rest_url( 'wp-ru-max/v1/skip/' ) ),
            'restNonce'=> wp_create_nonce( 'wp_rest' ),
        ) );
    }

    public function ajax_send_post_now() {
        check_ajax_referer( 'wp_ru_max_nonce', 'nonce' );
        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( 'Нет прав доступа.' );
        }

        $post_id = isset( $_POST['post_id'] ) ? intval( $_POST['post_id'] ) : 0;
        if ( ! $post_id ) {
            wp_send_json_error( 'Не указан ID записи.' );
        }

        $post = get_post( $post_id );
        if ( ! $post ) {
            wp_send_json_error( 'Запись не найдена.' );
        }

        $sender = WP_Ru_Max_Post_Sender::instance();
        $result = $sender->send_post_manually( $post );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }

        wp_send_json_success( 'Запись успешно отправлена в MAX!' );
    }

    public function add_menu() {
        add_menu_page(
            'WP Ru-max',
            'Ru-max',
            'manage_options',
            'wp-ru-max',
            array( $this, 'render_page' ),
            WP_RU_MAX_PLUGIN_URL . 'assets/max-32x32.png',
            58
        );
    }

    public function enqueue_admin_scripts( $hook ) {
        if ( strpos( $hook, 'wp-ru-max' ) === false ) {
            return;
        }
        wp_enqueue_style( 'wp-ru-max-admin', WP_RU_MAX_PLUGIN_URL . 'assets/admin.css', array(), WP_RU_MAX_VERSION );
        wp_enqueue_script( 'wp-ru-max-admin', WP_RU_MAX_PLUGIN_URL . 'assets/admin.js', array( 'jquery' ), WP_RU_MAX_VERSION, true );
        wp_localize_script( 'wp-ru-max-admin', 'wpRuMax', array(
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'wp_ru_max_nonce' ),
        ) );
    }

    public function add_plugin_links( $links ) {
        $settings_link = '<a href="' . admin_url( 'admin.php?page=wp-ru-max' ) . '">Настройки</a>';
        array_unshift( $links, $settings_link );
        return $links;
    }

    public function ajax_save_settings() {
        check_ajax_referer( 'wp_ru_max_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Нет прав доступа.' );
        }

        $settings = get_option( 'wp_ru_max_settings', array() );

        if ( isset( $_POST['field'] ) ) {
            $field = sanitize_text_field( $_POST['field'] );
            $value = isset( $_POST['value'] ) ? wp_unslash( $_POST['value'] ) : '';

            switch ( $field ) {
                case 'bot_token':
                case 'bot_name':
                case 'notify_from_email':
                case 'notify_format':
                case 'chat_widget_size':
                case 'chat_widget_url':
                case 'chat_widget_message':
                case 'chat_widget_position':
                case 'chat_widget_sound':
                case 'chat_widget_animation':
                case 'chat_widget_retention_title':
                case 'chat_widget_retention_stay_text':
                case 'chat_widget_retention_leave_text':
                    $settings[ $field ] = sanitize_text_field( $value );
                    break;
                case 'chat_widget_retention_message':
                    $settings[ $field ] = sanitize_textarea_field( $value );
                    break;
                case 'chat_widget_retention_text_align':
                case 'chat_widget_retention_buttons_align':
                    $allowed_align = array( 'left', 'center', 'right' );
                    $settings[ $field ] = in_array( $value, $allowed_align, true ) ? $value : 'left';
                    break;
                case 'chat_widget_retention_btn_radius':
                    $settings[ $field ] = max( 0, min( 50, intval( $value ) ) );
                    break;
                case 'chat_widget_retention_stay_bg':
                case 'chat_widget_retention_stay_color':
                case 'chat_widget_retention_leave_bg':
                case 'chat_widget_retention_leave_color':
                    $hex = sanitize_hex_color( $value );
                    if ( $hex ) { $settings[ $field ] = $hex; }
                    break;
                case 'excerpt_max_chars':
                case 'chat_widget_bottom_offset':
                case 'chat_widget_show_delay':
                case 'chat_widget_sound_delay':
                case 'chat_widget_hide_delay':
                case 'chat_widget_repeat_delay':
                    $settings[ $field ] = max( 0, intval( $value ) );
                    break;
                case 'chat_widget_sound_pages':
                    $allowed_pages = array( 'all', 'home', 'specific' );
                    $settings[ $field ] = in_array( $value, $allowed_pages, true ) ? $value : 'all';
                    break;
                case 'chat_widget_sound_specific_pages':
                    $settings[ $field ] = sanitize_textarea_field( $value );
                    break;
                case 'chat_widget_sound_once_per_session':
                    $settings[ $field ] = filter_var( $value, FILTER_VALIDATE_BOOLEAN );
                    break;
                case 'notify_template':
                    $settings[ $field ] = sanitize_textarea_field( $value );
                    break;
                case 'post_sender_enabled':
                case 'send_new_post':
                case 'send_updated_post':
                case 'show_read_more':
                case 'show_action_label':
                case 'show_author_date':
                case 'send_post_image':
                case 'notifications_enabled':
                case 'send_files_by_url':
                case 'enable_bot_api_log':
                case 'enable_post_sender_log':
                case 'delete_on_uninstall':
                case 'chat_widget_enabled':
                    $settings[ $field ] = filter_var( $value, FILTER_VALIDATE_BOOLEAN );
                    break;
                case 'post_types':
                case 'channels':
                case 'notify_chat_ids':
                    if ( is_array( $value ) ) {
                        $settings[ $field ] = array_map( 'sanitize_text_field', $value );
                    } elseif ( is_string( $value ) && ! empty( $value ) ) {
                        $settings[ $field ] = array_map( 'trim', array_filter( explode( "\n", sanitize_textarea_field( $value ) ) ) );
                    } else {
                        $settings[ $field ] = array();
                    }
                    break;
            }
        } else {
            $allowed_text = array( 'bot_token', 'bot_name', 'notify_from_email', 'notify_format', 'chat_widget_size', 'chat_widget_url', 'chat_widget_message', 'chat_widget_position', 'chat_widget_sound', 'chat_widget_animation', 'chat_widget_retention_title', 'chat_widget_retention_stay_text', 'chat_widget_retention_leave_text', 'chat_widget_retention_text_align', 'chat_widget_retention_buttons_align', 'chat_widget_sound_pages' );
            $allowed_textarea = array( 'notify_template', 'chat_widget_retention_message', 'chat_widget_sound_specific_pages' );
            $allowed_bool = array( 'post_sender_enabled', 'send_new_post', 'send_updated_post', 'show_read_more', 'show_action_label', 'show_author_date', 'send_post_image', 'notifications_enabled', 'send_files_by_url', 'enable_bot_api_log', 'enable_post_sender_log', 'delete_on_uninstall', 'chat_widget_enabled', 'chat_widget_retention_enabled', 'chat_widget_sound_once_per_session' );
            $allowed_int  = array( 'excerpt_max_chars', 'chat_widget_bottom_offset', 'chat_widget_show_delay', 'chat_widget_sound_delay', 'chat_widget_retention_btn_radius', 'chat_widget_hide_delay', 'chat_widget_repeat_delay' );
            $allowed_color = array( 'chat_widget_retention_stay_bg', 'chat_widget_retention_stay_color', 'chat_widget_retention_leave_bg', 'chat_widget_retention_leave_color' );
            $allowed_array = array( 'post_types', 'channels', 'notify_chat_ids' );

            foreach ( $allowed_text as $key ) {
                if ( isset( $_POST[ $key ] ) ) {
                    $settings[ $key ] = sanitize_text_field( wp_unslash( $_POST[ $key ] ) );
                }
            }
            foreach ( $allowed_int as $key ) {
                if ( isset( $_POST[ $key ] ) ) {
                    $settings[ $key ] = max( 0, intval( $_POST[ $key ] ) );
                }
            }
            foreach ( $allowed_textarea as $key ) {
                if ( isset( $_POST[ $key ] ) ) {
                    $settings[ $key ] = sanitize_textarea_field( wp_unslash( $_POST[ $key ] ) );
                }
            }
            foreach ( $allowed_bool as $key ) {
                if ( isset( $_POST[ $key ] ) ) {
                    $settings[ $key ] = filter_var( $_POST[ $key ], FILTER_VALIDATE_BOOLEAN );
                }
                // Do NOT reset to false if key is absent — partial saves must not destroy other modules.
            }
            foreach ( $allowed_color as $key ) {
                if ( isset( $_POST[ $key ] ) ) {
                    $hex = sanitize_hex_color( wp_unslash( $_POST[ $key ] ) );
                    if ( $hex ) { $settings[ $key ] = $hex; }
                }
            }
            foreach ( $allowed_array as $key ) {
                if ( isset( $_POST[ $key ] ) && is_array( $_POST[ $key ] ) ) {
                    $settings[ $key ] = array_map( 'sanitize_text_field', wp_unslash( $_POST[ $key ] ) );
                } elseif ( isset( $_POST[ $key ] ) ) {
                    $val = sanitize_textarea_field( wp_unslash( $_POST[ $key ] ) );
                    $settings[ $key ] = array_map( 'trim', array_filter( explode( "\n", $val ) ) );
                }
            }
        }

        // Кнопки уведомлений (JSON)
        if ( isset( $_POST['notify_buttons_json'] ) ) {
            $raw = json_decode( wp_unslash( $_POST['notify_buttons_json'] ), true );
            if ( is_array( $raw ) ) {
                $buttons = array();
                foreach ( $raw as $btn ) {
                    $text = isset( $btn['text'] ) ? sanitize_text_field( $btn['text'] ) : '';
                    $url  = isset( $btn['url'] )  ? sanitize_text_field( $btn['url'] )  : '';
                    if ( $text && $url ) {
                        $buttons[] = array( 'text' => $text, 'url' => $url );
                    }
                }
                $settings['notify_buttons'] = $buttons;
            } else {
                $settings['notify_buttons'] = array();
            }
        }

        // Кнопки публикаций (JSON)
        if ( ! isset( $_POST['post_buttons_json'] ) && ! isset( $_POST['field'] ) && isset( $_POST['post_sender_enabled'] ) ) {
            WP_Ru_Max_Logger::log( 'settings', 'warning',
                'Сохранение «Отправки публикаций» — поле post_buttons_json НЕ получено. Проверьте JS.',
                array( 'post_keys' => array_keys( $_POST ) )
            );
        }
        if ( isset( $_POST['post_buttons_json'] ) ) {
            $json_raw = wp_unslash( $_POST['post_buttons_json'] );
            $raw      = json_decode( $json_raw, true );
            if ( is_array( $raw ) ) {
                $buttons = array();
                foreach ( $raw as $btn ) {
                    $text = isset( $btn['text'] ) ? sanitize_text_field( $btn['text'] ) : '';
                    $url  = isset( $btn['url'] )  ? sanitize_text_field( $btn['url'] )  : '';
                    if ( $text && $url ) {
                        $buttons[] = array( 'text' => $text, 'url' => $url );
                    }
                }
                $settings['post_buttons'] = $buttons;
                WP_Ru_Max_Logger::log( 'settings', 'info',
                    'Кнопки публикаций сохранены: ' . count( $buttons ) . ' шт.',
                    array( 'buttons' => $buttons )
                );
            } else {
                $settings['post_buttons'] = array();
                WP_Ru_Max_Logger::log( 'settings', 'warning',
                    'post_buttons_json получен, но не удалось распарсить JSON.',
                    array( 'raw' => substr( $json_raw, 0, 300 ), 'json_error' => json_last_error_msg() )
                );
            }
        }

        // Шаблон сообщения публикации
        if ( isset( $_POST['post_message_template'] ) ) {
            $settings['post_message_template'] = sanitize_textarea_field( wp_unslash( $_POST['post_message_template'] ) );
        }

        update_option( 'wp_ru_max_settings', $settings );
        WP_Ru_Max_Logger::log( 'settings', 'info', 'Настройки обновлены.' );
        wp_send_json_success( 'Настройки сохранены.' );
    }

    public function ajax_test_connection() {
        check_ajax_referer( 'wp_ru_max_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Нет прав доступа.' );
        }

        $token = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '';
        $api   = new WP_Ru_Max_API( $token ?: null );
        $result = $api->test_connection( $token ?: null );

        if ( $result['success'] ) {
            wp_send_json_success( $result );
        } else {
            wp_send_json_error( $result );
        }
    }

    public function ajax_send_test_message() {
        check_ajax_referer( 'wp_ru_max_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Нет прав доступа.' );
        }

        $chat_id = isset( $_POST['chat_id'] ) ? sanitize_text_field( wp_unslash( $_POST['chat_id'] ) ) : '';
        $type    = isset( $_POST['type'] ) ? sanitize_text_field( $_POST['type'] ) : 'general';

        if ( empty( $chat_id ) ) {
            wp_send_json_error( 'Укажите ID чата или канала.' );
        }

        $api = new WP_Ru_Max_API();

        if ( $type === 'notification' ) {
            $obj = WP_Ru_Max_Notifications::instance();
            $result = $obj->send_test( $chat_id );
        } else {
            $msg = "<b>Тестовое сообщение WP Ru-max</b>\n\nПодключение работает корректно!\n\nСайт: " . get_bloginfo( 'url' ) . "\nВремя: " . current_time( 'd.m.Y H:i:s' );
            $result = $api->send_message( $chat_id, $msg, 'html' );
        }

        if ( is_wp_error( $result ) ) {
            WP_Ru_Max_Logger::log( 'test', 'error', 'Тест сообщения НЕУДАЧЕН в ' . $chat_id . ': ' . $result->get_error_message() );
            wp_send_json_error( $result->get_error_message() );
        } else {
            WP_Ru_Max_Logger::log( 'test', 'success', 'Тестовое сообщение успешно отправлено в ' . $chat_id );
            wp_send_json_success( 'Тестовое сообщение отправлено!' );
        }
    }

    public function ajax_get_logs() {
        check_ajax_referer( 'wp_ru_max_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Нет прав доступа.' );
        }

        $limit  = isset( $_POST['limit'] ) ? intval( $_POST['limit'] ) : 50;
        $offset = isset( $_POST['offset'] ) ? intval( $_POST['offset'] ) : 0;
        $type   = isset( $_POST['type'] ) ? sanitize_text_field( $_POST['type'] ) : '';

        $logs  = WP_Ru_Max_Logger::get_logs( $limit, $offset, $type );
        $total = WP_Ru_Max_Logger::get_count( $type );

        wp_send_json_success( array( 'logs' => $logs, 'total' => $total ) );
    }

    public function ajax_clear_logs() {
        check_ajax_referer( 'wp_ru_max_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Нет прав доступа.' );
        }

        $type = isset( $_POST['type'] ) ? sanitize_text_field( $_POST['type'] ) : '';
        WP_Ru_Max_Logger::clear_logs( $type );
        wp_send_json_success( 'Логи очищены.' );
    }

    public function render_page() {
        $settings = get_option( 'wp_ru_max_settings', array() );
        $active_tab = isset( $_GET['tab'] ) ? sanitize_text_field( $_GET['tab'] ) : 'main';
        ?>
        <div class="wrap wp-ru-max-wrap">
            <div class="wp-ru-max-header">
                <img src="<?php echo esc_url( WP_RU_MAX_PLUGIN_URL . 'assets/max-64x64.png' ); ?>" alt="MAX" width="40" height="40" />
                <h1>WP Ru-max</h1>
                <span class="wp-ru-max-version">v<?php echo esc_html( WP_RU_MAX_VERSION ); ?></span>
            </div>

            <nav class="wp-ru-max-tabs nav-tab-wrapper">
                <?php
                $tabs = array(
                    'main'         => 'Главная',
                    'post_sender'  => 'Отправка публикаций',
                    'notifications'=> 'Личные уведомления',
                    'advanced'     => 'Дополнительные настройки',
                    'instructions' => 'Инструкция',
                    'chat'         => 'Чат',
                    'history'      => 'История',
                    'activation'   => WP_Ru_Max_License::is_active() ? 'Активирован' : 'Активация',
                );
                foreach ( $tabs as $tab_key => $tab_label ) {
                    $class = ( $active_tab === $tab_key ) ? 'nav-tab nav-tab-active' : 'nav-tab';
                    if ( $tab_key === 'activation' && ! WP_Ru_Max_License::is_active() ) {
                        $class .= ' wp-ru-max-tab-activation-alert';
                    }
                    echo '<a href="' . esc_url( admin_url( 'admin.php?page=wp-ru-max&tab=' . $tab_key ) ) . '" class="' . esc_attr( $class ) . '">' . esc_html( $tab_label ) . '</a>';
                }
                ?>
            </nav>

            <div class="wp-ru-max-content">
                <?php
                switch ( $active_tab ) {
                    case 'main':
                        $this->render_tab_main( $settings );
                        break;
                    case 'post_sender':
                        $this->render_tab_post_sender( $settings );
                        break;
                    case 'notifications':
                        $this->render_tab_notifications( $settings );
                        break;
                    case 'advanced':
                        $this->render_tab_advanced( $settings );
                        break;
                    case 'instructions':
                        $this->render_tab_instructions();
                        break;
                    case 'chat':
                        $this->render_tab_chat( $settings );
                        break;
                    case 'history':
                        $this->render_tab_history();
                        break;
                    case 'activation':
                        $this->render_tab_activation();
                        break;
                }
                ?>
            </div>
        </div>
        <?php
    }

    private function render_tab_main( $settings ) {
        $is_licensed = WP_Ru_Max_License::is_active();
        ?>
        <div class="wp-ru-max-card">
            <h2>Настройки бота MAX</h2>
            <p>Введите токен вашего бота из мессенджера MAX. Для получения токена перейдите на <a href="https://max.ru/partner" target="_blank" rel="noopener">платформу MAX для партнёров</a>.</p>

            <?php if ( ! $is_licensed ) : ?>
            <div class="wp-ru-max-license-banner">
                <span class="dashicons dashicons-lock"></span>
                <strong>Плагин не активирован.</strong>
                Поле «Токен бота» заблокировано. Перейдите на вкладку
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-ru-max&tab=activation' ) ); ?>">Активация</a>
                и введите лицензионный ключ для разблокировки.
            </div>
            <?php endif; ?>

            <table class="form-table">
                <tr>
                    <th scope="row"><label for="bot_token">Токен бота *</label></th>
                    <td>
                        <?php if ( $is_licensed ) : ?>
                        <div class="wp-ru-max-token-row">
                            <input type="password" id="bot_token" name="bot_token" value="<?php echo esc_attr( $settings['bot_token'] ?? '' ); ?>" class="regular-text" placeholder="Вставьте токен бота MAX..." autocomplete="off" />
                            <button type="button" class="button" id="toggle_token_visibility">Показать</button>
                        </div>
                        <p class="description">Токен находится в разделе «Интеграция» в личном кабинете MAX для партнёров.</p>
                        <?php else : ?>
                        <div class="wp-ru-max-token-row">
                            <input type="password" id="bot_token" name="bot_token" value="" class="regular-text wp-ru-max-locked-field" placeholder="Заблокировано — активируйте плагин" autocomplete="off" disabled readonly />
                            <button type="button" class="button" disabled>Заблокировано</button>
                        </div>
                        <p class="description" style="color:#d63638;">
                            Для ввода токена необходимо <a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-ru-max&tab=activation' ) ); ?>">активировать плагин</a>.
                        </p>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="bot_name">Имя бота</label></th>
                    <td>
                        <input type="text" id="bot_name" name="bot_name" value="<?php echo esc_attr( $settings['bot_name'] ?? '' ); ?>" class="regular-text" placeholder="Например: MyShopBot" />
                        <p class="description">Никнейм бота в MAX (без символа @).</p>
                    </td>
                </tr>
            </table>

            <div class="wp-ru-max-actions">
                <button type="button" class="button button-primary" id="save_main_settings">Сохранить настройки</button>
                <button type="button" class="button button-secondary" id="test_connection">Проверить подключение</button>
            </div>

            <div id="connection_result" class="wp-ru-max-notice" style="display:none;"></div>
        </div>

        <?php if ( ! empty( $settings['bot_token'] ) ) : ?>
        <div class="wp-ru-max-card wp-ru-max-status">
            <h3>Статус подключения</h3>
            <div id="bot_status">
                <span class="wp-ru-max-status-indicator status-unknown">●</span>
                <span>Нажмите «Проверить подключение» для проверки статуса.</span>
            </div>
        </div>
        <?php endif; ?>
        <?php
    }

    private function render_tab_post_sender( $settings ) {
        $enabled = ! empty( $settings['post_sender_enabled'] );
        ?>
        <div class="wp-ru-max-card">
            <h2>Отправка публикаций</h2>
            <p>С помощью этого модуля вы можете настроить способ отправки записей в WP Ru-max.</p>

            <div class="wp-ru-max-toggle-row">
                <label class="wp-ru-max-toggle">
                    <input type="checkbox" id="post_sender_enabled" <?php checked( $enabled ); ?> />
                    <span class="wp-ru-max-toggle-slider"></span>
                </label>
                <span><?php echo $enabled ? '<strong>Включено</strong>' : 'Выключено'; ?></span>
            </div>
        </div>

        <div id="post_sender_settings" style="<?php echo $enabled ? '' : 'display:none;'; ?>">
            <div class="wp-ru-max-card">
                <h3>Назначение</h3>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label>Канал(ы) *</label></th>
                        <td>
                            <div id="channels_list">
                                <?php
                                $channels = isset( $settings['channels'] ) ? (array) $settings['channels'] : array( '' );
                                foreach ( $channels as $ch ) :
                                ?>
                                <div class="wp-ru-max-channel-row">
                                    <input type="text" name="channels[]" value="<?php echo esc_attr( $ch ); ?>" class="regular-text" placeholder="@channel_name или -100123456789" />
                                    <button type="button" class="button wp-ru-max-remove-channel">X</button>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <button type="button" class="button" id="add_channel">+ Добавить канал</button>
                            <p class="description">Имя пользователя канала (например, @mychannel) или числовой ID (например, -100123456789).</p>
                        </td>
                    </tr>
                </table>
            </div>

            <div class="wp-ru-max-card">
                <h3>Правила отправки</h3>
                <table class="form-table">
                    <tr>
                        <th scope="row">Отправлять, когда</th>
                        <td>
                            <label>
                                <input type="checkbox" name="send_new_post" value="1" <?php checked( ! empty( $settings['send_new_post'] ) ); ?> />
                                Публикуется новая запись
                            </label><br>
                            <label>
                                <input type="checkbox" name="send_updated_post" value="1" <?php checked( ! empty( $settings['send_updated_post'] ) ); ?> />
                                Обновляется существующая запись
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Ссылка "Читать полностью"</th>
                        <td>
                            <label>
                                <input type="checkbox" name="show_read_more" value="1" <?php checked( isset( $settings['show_read_more'] ) ? $settings['show_read_more'] : true ); ?> />
                                Добавлять ссылку на статью в конце сообщения
                            </label>
                            <p class="description">Если включено — в конец каждого сообщения добавляется ссылка для перехода к полной статье. Если выключено — сообщение отправляется без ссылки.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="excerpt_max_chars">Длина анонса (символов)</label></th>
                        <td>
                            <input
                                type="number"
                                id="excerpt_max_chars"
                                name="excerpt_max_chars"
                                value="<?php echo esc_attr( isset( $settings['excerpt_max_chars'] ) ? $settings['excerpt_max_chars'] : 300 ); ?>"
                                min="0"
                                max="4096"
                                step="10"
                                class="small-text"
                            />
                            <span> символов</span>
                            <p class="description">
                                Максимальное количество символов в анонсе статьи, отправляемом в MAX.<br>
                                <strong>0</strong> — без ограничений (отправляется весь анонс).<br>
                                Рекомендуется: <strong>300</strong> для коротких сообщений, <strong>800–1000</strong> для объёмных статей.
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Метка типа публикации</th>
                        <td>
                            <label>
                                <input type="checkbox" name="show_action_label" value="1" <?php checked( isset( $settings['show_action_label'] ) ? $settings['show_action_label'] : true ); ?> />
                                Показывать метку «Новая публикация» / «Обновлённая публикация»
                            </label>
                            <p class="description">Если выключено — строка с типом публикации не добавляется в начало сообщения.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Автор и дата</th>
                        <td>
                            <label>
                                <input type="checkbox" name="show_author_date" value="1" <?php checked( isset( $settings['show_author_date'] ) ? $settings['show_author_date'] : true ); ?> />
                                Показывать автора и дату в сообщении
                            </label>
                            <p class="description">Если включено — в сообщение добавляются строки «Автор:» и «Дата:». Если выключено — они не отображаются.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Тип записи</th>
                        <td>
                            <label>
                                <input type="checkbox" name="post_types[]" value="post" <?php checked( in_array( 'post', (array) ( $settings['post_types'] ?? array( 'post' ) ), true ) ); ?> />
                                Запись (post)
                            </label><br>
                            <label>
                                <input type="checkbox" name="post_types[]" value="page" <?php checked( in_array( 'page', (array) ( $settings['post_types'] ?? array() ), true ) ); ?> />
                                Страница (page)
                            </label><br>
                            <?php
                            $custom_types = get_post_types( array( 'public' => true, '_builtin' => false ), 'objects' );
                            foreach ( $custom_types as $pt ) :
                            ?>
                            <label>
                                <input type="checkbox" name="post_types[]" value="<?php echo esc_attr( $pt->name ); ?>" <?php checked( in_array( $pt->name, (array) ( $settings['post_types'] ?? array() ), true ) ); ?> />
                                <?php echo esc_html( $pt->label ); ?> (<?php echo esc_html( $pt->name ); ?>)
                            </label><br>
                            <?php endforeach; ?>
                        </td>
                    </tr>
                </table>
            </div>

            <div class="wp-ru-max-card">
                <h3>Шаблон сообщения</h3>
                <p>Настройте шаблон для публикаций, отправляемых в MAX. Если поле оставить пустым — используется стандартный формат.</p>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="post_message_template">Шаблон</label></th>
                        <td>
                            <textarea id="post_message_template" name="post_message_template" rows="8" class="large-text code"><?php echo esc_textarea( $settings['post_message_template'] ?? '' ); ?></textarea>
                            <p class="description">
                                Доступные переменные: <code>{title}</code> <code>{excerpt}</code> <code>{url}</code> <code>{author}</code> <code>{date}</code> <code>{status}</code> <code>{site_name}</code> <code>{post_type}</code><br>
                                Поля записи: <code>{meta_FIELDNAME}</code> — стандартные мета-поля, <code>{acf_FIELDNAME}</code> — поля ACF.<br>
                                Например: <code>&lt;b&gt;{title}&lt;/b&gt;\n{excerpt}\n\n&lt;a href="{url}"&gt;Читать&lt;/a&gt;</code>
                            </p>
                        </td>
                    </tr>
                </table>
            </div>

            <div class="wp-ru-max-card">
                <h3>Встроенные кнопки клавиатуры</h3>
                <p>Здесь вы можете добавить свои собственные кнопки, которые будут отображаться под каждой публикацией в MAX.</p>
                <p class="description"><strong>Примечание:</strong> Название кнопки должно быть уникальным и содержать не более 8 английских букв или символов подчеркивания.</p>

                <div id="post_buttons_list" style="margin:12px 0;">
                    <?php
                    $post_buttons = isset( $settings['post_buttons'] ) ? (array) $settings['post_buttons'] : array();
                    foreach ( $post_buttons as $btn ) :
                        if ( empty( $btn['text'] ) && empty( $btn['url'] ) ) continue;
                    ?>
                    <div class="wp-ru-max-button-row" style="display:flex;gap:8px;margin-bottom:8px;align-items:center;">
                        <input type="text" name="post_btn_text[]" value="<?php echo esc_attr( $btn['text'] ?? '' ); ?>" class="regular-text" placeholder="Название кнопки" style="max-width:160px;" />
                        <input type="text" name="post_btn_url[]" value="<?php echo esc_attr( $btn['url'] ?? '' ); ?>" class="regular-text" placeholder="https://..." style="flex:1;" />
                        <button type="button" class="button wp-ru-max-remove-btn">Удалить</button>
                    </div>
                    <?php endforeach; ?>
                </div>

                <div style="display:flex;gap:8px;align-items:center;margin-bottom:8px;">
                    <input type="text" id="new_post_btn_text" class="regular-text" placeholder="Название кнопки" style="max-width:160px;" />
                    <input type="text" id="new_post_btn_url" class="regular-text" placeholder="https://..." style="flex:1;" />
                    <button type="button" class="button" id="add_post_button">+ Добавить кнопку</button>
                </div>

                <p class="description">
                    В поле URL вы можете указать абсолютный или динамический URL, используя теги настраиваемых полей, как в шаблоне сообщения.<br>
                    Например: <code>https://domain.com</code>, <code>{url}</code>, <code>{home_url}</code><br>
                    <strong>Примечание:</strong> если вы добавляете теги в качестве параметров URL-запроса, обязательно заключите их в <code>{encode:&lt;tag&gt;}</code>.<br>
                    Например: <code>{home_url}?utm_source={encode:{title}}</code>
                </p>
            </div>

            <div class="wp-ru-max-card">
                <div class="wp-ru-max-actions">
                    <button type="button" class="button button-primary" id="save_post_sender">Сохранить</button>
                    <button type="button" class="button button-secondary" id="test_post_sender">Тестовое сообщение</button>
                </div>
                <div id="test_chat_id_row" style="margin-top:10px;display:none;">
                    <input type="text" id="test_chat_id" class="regular-text" placeholder="ID чата для теста..." />
                    <button type="button" class="button" id="send_test_post">Отправить тест</button>
                </div>
                <div id="post_sender_result" class="wp-ru-max-notice" style="display:none;"></div>
            </div>
        </div>
        <?php
    }

    private function render_tab_notifications( $settings ) {
        $enabled  = ! empty( $settings['notifications_enabled'] );
        $chat_ids = isset( $settings['notify_chat_ids'] ) ? array_filter( array_map( 'trim', (array) $settings['notify_chat_ids'] ) ) : array();
        $chat_ids_display = ! empty( $chat_ids ) ? array_values( $chat_ids ) : array( '' );
        ?>
        <div class="wp-ru-max-card">
            <h2>Личные уведомления</h2>
            <p>Модуль будет следить за уведомлениями по электронной почте, отправленными с этого сайта, и доставлять их в чат/группу WP Ru-max.</p>

            <?php if ( $enabled && empty( $chat_ids ) ) : ?>
            <div style="background:#fff3cd;border-left:4px solid #ffc107;padding:12px 16px;margin:12px 0;border-radius:2px;">
                <strong>Внимание:</strong> Уведомления включены, но не указан ни один ID чата/группы в поле «Отправлять в».
                Добавьте ID чата и нажмите <strong>«Сохранить»</strong> — иначе письма будут перехвачены, но никуда не отправлены.
            </div>
            <?php endif; ?>

            <div class="wp-ru-max-toggle-row">
                <label class="wp-ru-max-toggle">
                    <input type="checkbox" id="notifications_enabled" <?php checked( $enabled ); ?> />
                    <span class="wp-ru-max-toggle-slider"></span>
                </label>
                <span><?php echo $enabled ? '<strong>Активна</strong>' : 'Не активна'; ?></span>
            </div>
        </div>

        <div id="notifications_settings" style="<?php echo $enabled ? '' : 'display:none;'; ?>">
            <div class="wp-ru-max-card">
                <h3>Настройки уведомлений</h3>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="notify_from_email">Получать уведомления с этой почты</label></th>
                        <td>
                            <input type="text" id="notify_from_email" name="notify_from_email" value="<?php echo esc_attr( $settings['notify_from_email'] ?? 'any' ); ?>" class="regular-text" placeholder="any" />
                            <p class="description">Если вы хотите получать уведомления с каждой электронной почты, напишите <code>any</code>. Можно указать несколько через запятую.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Отправлять в *</th>
                        <td>
                            <div id="notify_chat_ids_list">
                                <?php foreach ( $chat_ids_display as $cid ) : ?>
                                <div class="wp-ru-max-channel-row">
                                    <input type="text" name="notify_chat_ids[]" value="<?php echo esc_attr( $cid ); ?>" class="regular-text" placeholder="987654321 | My Personal ID" />
                                    <button type="button" class="button wp-ru-max-remove-channel">X</button>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <button type="button" class="button" id="add_notify_channel">+ Добавить канал</button>
                            <p class="description">Идентификатор чата или группы.</p>
                        </td>
                    </tr>
                </table>

                <div class="wp-ru-max-actions">
                    <button type="button" class="button button-secondary" id="test_notification">Тестировать</button>
                    <input type="text" id="notify_test_chat" class="regular-text" placeholder="ID чата для теста..." />
                </div>
                <div id="notify_test_result" class="wp-ru-max-notice" style="display:none;"></div>
            </div>

            <div class="wp-ru-max-card">
                <h3>Уведомления пользователям</h3>
                <p>Разрешить пользователям получать уведомления по электронной почте в WP Ru-max.</p>
                <p class="description">Пользователи могут ввести свой ID чата вручную на странице профиля WordPress.</p>
            </div>

            <div class="wp-ru-max-card">
                <h3>Шаблон сообщения</h3>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="notify_template">Шаблон</label></th>
                        <td>
                            <textarea id="notify_template" name="notify_template" rows="6" class="large-text code"><?php echo esc_textarea( $settings['notify_template'] ?? "<b>{email_subject}</b>\n{email_message}" ); ?></textarea>
                            <p class="description">Структура отправляемого сообщения. Доступные переменные: <code>{email_subject}</code> <code>{email_message}</code></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label>Форматирование</label></th>
                        <td>
                            <label>
                                <input type="radio" name="notify_format" value="none" <?php checked( ( $settings['notify_format'] ?? 'html' ), 'none' ); ?> /> Нет
                            </label>&nbsp;&nbsp;
                            <label>
                                <input type="radio" name="notify_format" value="html" <?php checked( ( $settings['notify_format'] ?? 'html' ), 'html' ); ?> /> HTML стиль
                            </label>&nbsp;&nbsp;
                            <label>
                                <input type="radio" name="notify_format" value="markdown" <?php checked( ( $settings['notify_format'] ?? 'html' ), 'markdown' ); ?> /> Markdown
                            </label>
                        </td>
                    </tr>
                </table>
            </div>

            <div class="wp-ru-max-card">
                <h3>Встроенные кнопки клавиатуры</h3>
                <p>Здесь вы можете добавить свои собственные кнопки, которые будут отображаться под каждым уведомлением в MAX.</p>
                <p class="description"><strong>Примечание:</strong> Название кнопки должно быть уникальным и содержать не более 8 английских букв или символов подчеркивания.</p>

                <div id="notify_buttons_list" style="margin:12px 0;">
                    <?php
                    $notify_buttons = isset( $settings['notify_buttons'] ) ? (array) $settings['notify_buttons'] : array();
                    foreach ( $notify_buttons as $btn ) :
                        if ( empty( $btn['text'] ) && empty( $btn['url'] ) ) continue;
                    ?>
                    <div class="wp-ru-max-button-row" style="display:flex;gap:8px;margin-bottom:8px;align-items:center;">
                        <input type="text" name="notify_btn_text[]" value="<?php echo esc_attr( $btn['text'] ?? '' ); ?>" class="regular-text" placeholder="Название кнопки" style="max-width:160px;" />
                        <input type="text" name="notify_btn_url[]" value="<?php echo esc_attr( $btn['url'] ?? '' ); ?>" class="regular-text" placeholder="https://..." style="flex:1;" />
                        <button type="button" class="button wp-ru-max-remove-btn">Удалить</button>
                    </div>
                    <?php endforeach; ?>
                </div>

                <div style="display:flex;gap:8px;align-items:center;margin-bottom:8px;">
                    <input type="text" id="new_notify_btn_text" class="regular-text" placeholder="Название кнопки" style="max-width:160px;" />
                    <input type="text" id="new_notify_btn_url" class="regular-text" placeholder="https://..." style="flex:1;" />
                    <button type="button" class="button" id="add_notify_button">+ Добавить кнопку</button>
                </div>

                <p class="description">
                    В поле URL вы можете указать абсолютный URL.<br>
                    Например: <code>https://domain.com</code><br>
                    <strong>Примечание:</strong> если кнопка удалена из настроек, она исчезнет из новых уведомлений в MAX.
                </p>
            </div>

            <div class="wp-ru-max-card">
                <div class="wp-ru-max-actions">
                    <button type="button" class="button button-primary" id="save_notifications">Сохранить</button>
                </div>
                <div id="notifications_result" class="wp-ru-max-notice" style="display:none;"></div>
            </div>
        </div>
        <?php
    }

    private function render_tab_advanced( $settings ) {
        ?>
        <div class="wp-ru-max-card">
            <h2>Дополнительные настройки</h2>
            <p class="wp-ru-max-warning">Настройки в этом разделе не следует изменять, если это не рекомендовано поддержкой WP Ru-max.</p>
        </div>

        <div class="wp-ru-max-card">
            <h3>Отправка изображений</h3>
            <table class="form-table">
                <tr>
                    <th scope="row">Отправка изображения поста</th>
                    <td>
                        <label class="wp-ru-max-toggle">
                            <input type="checkbox" id="send_post_image" <?php checked( isset( $settings['send_post_image'] ) ? $settings['send_post_image'] : true ); ?> />
                            <span class="wp-ru-max-toggle-slider"></span>
                        </label>
                        <p class="description">
                            <strong>Включено</strong> — изображение (превью) поста отправляется вместе с сообщением в MAX.<br>
                            <strong>Выключено</strong> — фото поста не отправляется, только текст.
                        </p>
                    </td>
                </tr>
            </table>
        </div>

        <div class="wp-ru-max-card">
            <h3>Отправка файлов</h3>
            <table class="form-table">
                <tr>
                    <th scope="row">Отправка файлов по URL</th>
                    <td>
                        <label class="wp-ru-max-toggle">
                            <input type="checkbox" id="send_files_by_url" <?php checked( ! empty( $settings['send_files_by_url'] ) ); ?> />
                            <span class="wp-ru-max-toggle-slider"></span>
                        </label>
                        <p class="description">Выключите, чтобы загрузить файлы/изображения вместо передачи URL.</p>
                    </td>
                </tr>
            </table>
        </div>

        <div class="wp-ru-max-card">
            <h3>Журналы</h3>
            <table class="form-table">
                <tr>
                    <th scope="row">Включить журналы для API</th>
                    <td>
                        <label class="wp-ru-max-toggle">
                            <input type="checkbox" id="enable_bot_api_log" <?php checked( ! empty( $settings['enable_bot_api_log'] ) ); ?> />
                            <span class="wp-ru-max-toggle-slider"></span>
                        </label>
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-ru-max&tab=history&type=api' ) ); ?>" class="button button-small">[Посмотреть журнал]</a>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Включить журналы для отправки публикаций</th>
                    <td>
                        <label class="wp-ru-max-toggle">
                            <input type="checkbox" id="enable_post_sender_log" <?php checked( ! empty( $settings['enable_post_sender_log'] ) ); ?> />
                            <span class="wp-ru-max-toggle-slider"></span>
                        </label>
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-ru-max&tab=history&type=post_sender' ) ); ?>" class="button button-small">[Посмотреть журнал]</a>
                    </td>
                </tr>
            </table>
        </div>

        <div class="wp-ru-max-card">
            <h3>Отладочная информация</h3>
            <table class="form-table wp-ru-max-debug-table">
                <tr>
                    <th>Plugin:</th>
                    <td>WP Ru-max v<?php echo esc_html( WP_RU_MAX_VERSION ); ?></td>
                </tr>
                <tr>
                    <th>WordPress:</th>
                    <td><?php echo esc_html( get_bloginfo( 'version' ) ); ?></td>
                </tr>
                <tr>
                    <th>PHP:</th>
                    <td><?php echo esc_html( phpversion() ); ?></td>
                </tr>
                <tr>
                    <th>Action Scheduler:</th>
                    <td><?php echo class_exists( 'ActionScheduler' ) ? '✓' : '✗ (не установлен)'; ?></td>
                </tr>
                <tr>
                    <th>DOMDocument:</th>
                    <td><?php echo class_exists( 'DOMDocument' ) ? '✓' : '✗'; ?></td>
                </tr>
                <tr>
                    <th>DOMXPath:</th>
                    <td><?php echo class_exists( 'DOMXPath' ) ? '✓' : '✗'; ?></td>
                </tr>
                <tr>
                    <th>cURL:</th>
                    <td><?php echo function_exists( 'curl_version' ) ? '✓' : '✗'; ?></td>
                </tr>
                <tr>
                    <th>API Base:</th>
                    <td><?php echo esc_html( WP_RU_MAX_API_BASE ); ?></td>
                </tr>
            </table>
        </div>

        <div class="wp-ru-max-card">
            <h3>Очистка при удалении</h3>
            <table class="form-table">
                <tr>
                    <th scope="row">Удаление настроек при удалении плагина</th>
                    <td>
                        <label class="wp-ru-max-toggle">
                            <input type="checkbox" id="delete_on_uninstall" <?php checked( ! empty( $settings['delete_on_uninstall'] ) ); ?> />
                            <span class="wp-ru-max-toggle-slider"></span>
                        </label>
                        <p class="description">При активации — все настройки и история будут удалены вместе с плагином.</p>
                    </td>
                </tr>
            </table>
        </div>

        <div class="wp-ru-max-card">
            <div class="wp-ru-max-actions">
                <button type="button" class="button button-primary" id="save_advanced">Сохранить</button>
            </div>
            <div id="advanced_result" class="wp-ru-max-notice" style="display:none;"></div>
        </div>
        <?php
    }

    private function render_tab_instructions() {
        ?>
        <div class="wp-ru-max-card">
            <h2>Инструкция по подключению WP Ru-max</h2>
            <p>Следуйте этим шагам, чтобы быстро подключить ваш сайт к мессенджеру MAX.</p>
        </div>

        <div class="wp-ru-max-card">
            <h3>Шаг 1: Регистрация на платформе MAX для партнёров</h3>
            <ol>
                <li>Перейдите на <a href="https://max.ru/partner" target="_blank" rel="noopener"><strong>платформу MAX для партнёров</strong></a> и войдите в аккаунт или зарегистрируйтесь по номеру телефона.</li>
                <li>Создайте и заполните профиль вашей организации, пройдите необходимую верификацию.</li>
                <li>Подключение к платформе MAX для партнёров доступно для <strong>юридических лиц и ИП, являющихся резидентами РФ</strong>.</li>
            </ol>
        </div>

        <div class="wp-ru-max-card">
            <h3>Шаг 2: Создание бота</h3>
            <ol>
                <li>В личном кабинете перейдите в раздел <strong>«Чат-боты»</strong>.</li>
                <li>Нажмите <strong>«Создать нового чат-бота»</strong> и заполните все необходимые поля.</li>
                <li>Отправьте бота на модерацию и дождитесь одобрения.</li>
                <li>После одобрения ваш бот будет готов к работе.</li>
            </ol>
            <div class="wp-ru-max-tip"><strong>Важно:</strong> Бот должен быть добавлен как администратор в канал или группу, куда он будет отправлять сообщения.</div>
        </div>

        <div class="wp-ru-max-card">
            <h3>Шаг 3: Получение токена бота</h3>
            <ol>
                <li>Авторизуйтесь на платформе MAX для партнёров.</li>
                <li>Перейдите в раздел <strong>«Чат-боты»</strong> → выберите нужного бота.</li>
                <li>Откройте раздел <strong>«Интеграция»</strong>.</li>
                <li>Нажмите <strong>«Получить токен»</strong> и скопируйте значение токена.</li>
            </ol>
            <div class="wp-ru-max-tip"><strong>Важно:</strong> Никому не передавайте токен бота! Он даёт полный контроль над ботом.</div>
        </div>

        <div class="wp-ru-max-card">
            <h3>Шаг 4: Настройка плагина WP Ru-max</h3>
            <ol>
                <li>Перейдите на вкладку <a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-ru-max&tab=main' ) ); ?>"><strong>«Главная»</strong></a>.</li>
                <li>Вставьте токен бота в поле <strong>«Токен бота»</strong>.</li>
                <li>Нажмите <strong>«Проверить подключение»</strong> — должен появиться зелёный статус подтверждения.</li>
                <li>Сохраните настройки.</li>
            </ol>
        </div>

        <div class="wp-ru-max-card">
            <h3>Шаг 5: Получение ID канала или чата</h3>
            <p>Для отправки сообщений нужен числовой ID чата или никнейм канала:</p>
            <ul>
                <li>Для <strong>публичного канала</strong>: используйте никнейм в формате <code>@channel_name</code>.</li>
                <li>Для <strong>группы или приватного чата</strong>: добавьте бота в группу и используйте числовой ID (например, <code>-100123456789</code>).</li>
                <li>Ваш личный ID чата можно узнать, написав боту любое сообщение и проверив ответ через <a href="https://dev.max.ru" target="_blank" rel="noopener">API MAX</a>.</li>
            </ul>
        </div>

        <div class="wp-ru-max-card">
            <h3>Шаг 6: Настройка получения уведомлений с форм</h3>
            <p>WP Ru-max автоматически перехватывает все email-уведомления WordPress, включая:</p>
            <ul>
                <li><strong>WooCommerce</strong> — новые заказы, изменения статуса, вопросы о товарах</li>
                <li><strong>Contact Form 7</strong> — любые отправки форм</li>
                <li><strong>Elementor Forms</strong> — все данные из форм на страницах</li>
                <li><strong>Любые другие формы</strong>, отправляющие email через WordPress</li>
                <li><strong>Уведомления WordPress</strong> — регистрации, сбросы паролей и т.д.</li>
            </ul>
            <p>Для активации:</p>
            <ol>
                <li>Перейдите на вкладку <a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-ru-max&tab=notifications' ) ); ?>"><strong>«Личные уведомления»</strong></a>.</li>
                <li>Включите модуль тумблером.</li>
                <li>Добавьте ID вашего чата в поле <strong>«Отправлять в»</strong>.</li>
                <li>Сохраните настройки и нажмите <strong>«Тестировать»</strong>.</li>
            </ol>
        </div>

        <div class="wp-ru-max-card">
            <h3>Формат уведомлений о заявках</h3>
            <p>Когда кто-то заполняет форму на вашем сайте, в MAX придёт сообщение вида:</p>
            <div class="wp-ru-max-message-preview">
                <p><strong>У нас новая заявка на сайте!</strong></p>
                <p><em>[данные из формы: имя, фамилия, телефон, email и т.д.]</em></p>
                <p>Это отличная возможность для нас проявить наш высокий уровень обслуживания. Пожалуйста, перезвоните клиенту и предоставьте всю необходимую информацию и поддержку. Спасибо за вашу оперативность!</p>
                <p>Не забудьте отметить заявку как обработанную после связи с клиентом.</p>
            </div>
        </div>

        <div class="wp-ru-max-card">
            <h3>Полезные ссылки</h3>
            <ul>
                <li><a href="https://max.ru/partner" target="_blank" rel="noopener">Платформа MAX для партнёров</a></li>
                <li><a href="https://dev.max.ru" target="_blank" rel="noopener">Документация MAX API</a></li>
                <li><a href="https://dev.max.ru/docs-api/methods/GET/me" target="_blank" rel="noopener">API метод GET /me</a></li>
                <li><a href="https://docs.fstrk.io/knowledge_base/channels/max" target="_blank" rel="noopener">Инструкция Fasttrack по MAX</a></li>
                <li><a href="https://рукодер.рф/" target="_blank" rel="noopener">Разработка сайтов под ключ</a></li>
            </ul>
        </div>

        <div class="wp-ru-max-card">
            <h3>Согласие пользователя</h3>
            <p>
                Используя плагин WP Ru-max и активируя лицензию, пользователь подтверждает, что
                <strong>ознакомлен с указанными ниже страницами</strong> и даёт согласие на обработку
                его <strong>email, имени, фамилии и домена</strong> для обработки заявки и отправки
                лицензионного ключа активации:
            </p>
            <ul>
                <li><a href="https://github.com/RuCoder-sudo/wp-ru-max/wiki/Политика-плагина" target="_blank" rel="noopener">Политика плагина</a></li>
                <li><a href="https://github.com/RuCoder-sudo/wp-ru-max/wiki/Возврат-и-отзыв-лицензии" target="_blank" rel="noopener">Возврат и отзыв лицензии</a></li>
                <li><a href="https://github.com/RuCoder-sudo/wp-ru-max/wiki/Пользовательское-соглашение" target="_blank" rel="noopener">Пользовательское соглашение</a></li>
                <li><a href="https://github.com/RuCoder-sudo/wp-ru-max/wiki/Политика-конфиденциальности" target="_blank" rel="noopener">Политика конфиденциальности</a></li>
            </ul>
            <p class="description">
                Отправляя запрос на получение лицензионного ключа на вкладке «Активация», вы подтверждаете
                согласие с указанными документами и даёте разрешение на обработку ваших персональных данных
                в целях рассмотрения заявки, генерации и отправки ключа активации.
            </p>
        </div>

        <?php
    }

    private function render_tab_chat( $settings ) {
        $enabled       = ! empty( $settings['chat_widget_enabled'] );
        $size          = $settings['chat_widget_size']        ?? 'medium';
        $url           = $settings['chat_widget_url']         ?? '';
        $message       = $settings['chat_widget_message']     ?? 'Здравствуйте! У вас есть вопросы!? Мы всегда на связи. Кликните, чтобы нам написать!';
        $position      = $settings['chat_widget_position']    ?? 'right';
        $bottom_offset = isset( $settings['chat_widget_bottom_offset'] ) ? (int) $settings['chat_widget_bottom_offset'] : 20;
        $show_delay    = isset( $settings['chat_widget_show_delay'] )    ? (int) $settings['chat_widget_show_delay']    : 0;
        $sound         = $settings['chat_widget_sound']       ?? 'none';
        $sound_delay   = isset( $settings['chat_widget_sound_delay'] )   ? (int) $settings['chat_widget_sound_delay']   : 3;
        $sound_pages          = $settings['chat_widget_sound_pages']           ?? 'all';
        $sound_specific_pages = $settings['chat_widget_sound_specific_pages']  ?? '';
        $sound_once_per_session = ! empty( $settings['chat_widget_sound_once_per_session'] );
        $hide_delay    = isset( $settings['chat_widget_hide_delay'] )    ? (int) $settings['chat_widget_hide_delay']    : 0;
        $repeat_delay  = isset( $settings['chat_widget_repeat_delay'] )  ? (int) $settings['chat_widget_repeat_delay']  : 0;
        $animation     = $settings['chat_widget_animation']   ?? 'none';
        $retention_enabled = ! empty( $settings['chat_widget_retention_enabled'] );
        $retention_title   = $settings['chat_widget_retention_title']   ?? 'Специальное предложение!';
        $retention_message = $settings['chat_widget_retention_message'] ?? 'Уже уходите? Получите скидку 10% на первый заказ, если ответим на ваш вопрос в течение 5 минут!';
        $retention_text_align    = $settings['chat_widget_retention_text_align']    ?? 'left';
        $retention_buttons_align = $settings['chat_widget_retention_buttons_align'] ?? 'right';
        $retention_btn_radius    = isset( $settings['chat_widget_retention_btn_radius'] ) ? (int) $settings['chat_widget_retention_btn_radius'] : 8;
        $retention_stay_text     = $settings['chat_widget_retention_stay_text']     ?? 'Остаться';
        $retention_leave_text    = $settings['chat_widget_retention_leave_text']    ?? 'Все равно уйти';
        $retention_stay_bg       = $settings['chat_widget_retention_stay_bg']       ?? '#4a90d9';
        $retention_stay_color    = $settings['chat_widget_retention_stay_color']    ?? '#ffffff';
        $retention_leave_bg      = $settings['chat_widget_retention_leave_bg']      ?? '#f0f0f0';
        $retention_leave_color   = $settings['chat_widget_retention_leave_color']   ?? '#555555';
        ?>
        <div class="wp-ru-max-card">
            <h2>Чат-виджет MAX</h2>
            <p>Добавьте на сайт плавающую кнопку MAX с анимацией приветственного сообщения. Посетители смогут нажать на неё и перейти в ваш чат или канал в MAX.</p>

            <div class="wp-ru-max-toggle-row">
                <label class="wp-ru-max-toggle">
                    <input type="checkbox" id="chat_widget_enabled" <?php checked( $enabled ); ?> />
                    <span class="wp-ru-max-toggle-slider"></span>
                </label>
                <span><?php echo $enabled ? '<strong>Активировать</strong>' : 'Выключить'; ?></span>
            </div>
        </div>

        <div id="chat_widget_settings" style="<?php echo $enabled ? '' : 'display:none;'; ?>">
            <div class="wp-ru-max-card">
                <h3>Настройки виджета</h3>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label>Размер значка</label></th>
                        <td>
                            <div class="wp-ru-max-size-options">
                                <label class="wp-ru-max-size-option <?php echo $size === 'small' ? 'selected' : ''; ?>">
                                    <input type="radio" name="chat_widget_size" value="small" <?php checked( $size, 'small' ); ?> />
                                    <img src="<?php echo esc_url( WP_RU_MAX_PLUGIN_URL . 'assets/max-32x32.png' ); ?>" width="32" height="32" alt="Маленькое" />
                                    <span>Маленькое (32px)</span>
                                </label>
                                <label class="wp-ru-max-size-option <?php echo $size === 'medium' ? 'selected' : ''; ?>">
                                    <input type="radio" name="chat_widget_size" value="medium" <?php checked( $size, 'medium' ); ?> />
                                    <img src="<?php echo esc_url( WP_RU_MAX_PLUGIN_URL . 'assets/max-64x64.png' ); ?>" width="48" height="48" alt="Среднее" />
                                    <span>Среднее (64px)</span>
                                </label>
                                <label class="wp-ru-max-size-option <?php echo $size === 'large' ? 'selected' : ''; ?>">
                                    <input type="radio" name="chat_widget_size" value="large" <?php checked( $size, 'large' ); ?> />
                                    <img src="<?php echo esc_url( WP_RU_MAX_PLUGIN_URL . 'assets/max-256x256.png' ); ?>" width="64" height="64" alt="Большое" />
                                    <span>Большое (80px)</span>
                                </label>
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="chat_widget_url">Ссылка на чат *</label></th>
                        <td>
                            <input type="url" id="chat_widget_url" name="chat_widget_url" value="<?php echo esc_attr( $url ); ?>" class="large-text" placeholder="https://max.ru/YourBotName или ссылка на группу" />
                            <p class="description">Ссылка, куда переходит пользователь по клику на значок.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="chat_widget_message">Приветственное сообщение</label></th>
                        <td>
                            <textarea id="chat_widget_message" name="chat_widget_message" rows="3" class="large-text"><?php echo esc_textarea( $message ); ?></textarea>
                            <p class="description">Текст, который появляется в анимированном пузыре над значком. Имитирует печатание.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label>Расположение</label></th>
                        <td>
                            <label>
                                <input type="radio" name="chat_widget_position" value="right" <?php checked( $position, 'right' ); ?> />
                                Справа внизу
                            </label>&nbsp;&nbsp;&nbsp;
                            <label>
                                <input type="radio" name="chat_widget_position" value="left" <?php checked( $position, 'left' ); ?> />
                                Слева внизу
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="chat_widget_bottom_offset">Отступ снизу (px)</label></th>
                        <td>
                            <div style="display:flex;align-items:center;gap:12px;">
                                <input type="range" id="chat_widget_bottom_offset_range" min="0" max="200" value="<?php echo esc_attr( $bottom_offset ); ?>" style="width:200px;" />
                                <input type="number" id="chat_widget_bottom_offset" name="chat_widget_bottom_offset" value="<?php echo esc_attr( $bottom_offset ); ?>" min="0" max="200" style="width:70px;" />
                                <span>px</span>
                            </div>
                            <p class="description">Задайте отступ значка от нижнего края экрана. Двигайте ползунок вверх/вниз.</p>
                            <script>
                            (function(){
                                var r = document.getElementById('chat_widget_bottom_offset_range');
                                var n = document.getElementById('chat_widget_bottom_offset');
                                if(r && n){
                                    r.addEventListener('input', function(){ n.value = r.value; });
                                    n.addEventListener('input', function(){ r.value = n.value; });
                                }
                            })();
                            </script>
                        </td>
                    </tr>
                </table>
            </div>

            <div class="wp-ru-max-card">
                <h3>Задержка появления виджета</h3>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label>Показать виджет</label></th>
                        <td>
                            <?php
                            $delay_options = array(
                                0  => 'Сразу',
                                5  => 'Через 5 секунд',
                                8  => 'Через 8 секунд',
                                10 => 'Через 10 секунд',
                                15 => 'Через 15 секунд',
                            );
                            foreach ( $delay_options as $val => $label ) :
                            ?>
                            <label style="display:inline-flex;align-items:center;margin-right:20px;margin-bottom:8px;cursor:pointer;">
                                <input type="radio" name="chat_widget_show_delay" value="<?php echo esc_attr( $val ); ?>" <?php checked( $show_delay, $val ); ?> style="margin-right:6px;" />
                                <?php echo esc_html( $label ); ?>
                            </label>
                            <?php endforeach; ?>
                            <p class="description">Через сколько секунд после загрузки страницы показать виджет посетителю.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label>Скрыть виджет через</label></th>
                        <td>
                            <?php
                            $hide_options = array(
                                0   => 'Не скрывать',
                                10  => 'Через 10 секунд',
                                20  => 'Через 20 секунд',
                                30  => 'Через 30 секунд',
                                60  => 'Через 1 минуту',
                                120 => 'Через 2 минуты',
                            );
                            foreach ( $hide_options as $val => $label ) :
                            ?>
                            <label style="display:inline-flex;align-items:center;margin-right:20px;margin-bottom:8px;cursor:pointer;">
                                <input type="radio" name="chat_widget_hide_delay" value="<?php echo esc_attr( $val ); ?>" <?php checked( $hide_delay, $val ); ?> style="margin-right:6px;" />
                                <?php echo esc_html( $label ); ?>
                            </label>
                            <?php endforeach; ?>
                            <p class="description">Через сколько секунд после появления значок чата автоматически скроется, чтобы не мешать посетителю. «Не скрывать» — значок остаётся постоянно.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label>Повторно показать через</label></th>
                        <td>
                            <?php
                            $repeat_options = array(
                                0   => 'Не показывать повторно',
                                30  => 'Через 30 секунд',
                                60  => 'Через 1 минуту',
                                120 => 'Через 2 минуты',
                                300 => 'Через 5 минут',
                                600 => 'Через 10 минут',
                            );
                            foreach ( $repeat_options as $val => $label ) :
                            ?>
                            <label style="display:inline-flex;align-items:center;margin-right:20px;margin-bottom:8px;cursor:pointer;">
                                <input type="radio" name="chat_widget_repeat_delay" value="<?php echo esc_attr( $val ); ?>" <?php checked( $repeat_delay, $val ); ?> style="margin-right:6px;" />
                                <?php echo esc_html( $label ); ?>
                            </label>
                            <?php endforeach; ?>
                            <p class="description">Через сколько секунд после автоскрытия снова показать значок. Работает только если включено «Скрыть виджет через».</p>
                        </td>
                    </tr>
                </table>
            </div>

            <div class="wp-ru-max-card">
                <h3>Звук уведомления</h3>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label>Выбор звука</label></th>
                        <td>
                            <div style="display:flex;flex-direction:column;gap:10px;">
                                <label style="display:flex;align-items:center;gap:10px;cursor:pointer;">
                                    <input type="radio" name="chat_widget_sound" value="none" <?php checked( $sound, 'none' ); ?> />
                                    <span>Без звука</span>
                                </label>
                                <label style="display:flex;align-items:center;gap:10px;cursor:pointer;">
                                    <input type="radio" name="chat_widget_sound" value="sound1" <?php checked( $sound, 'sound1' ); ?> />
                                    <span>Вариант 1 — Новое сообщение</span>
                                    <button type="button" class="button wp-ru-max-preview-sound" data-sound="sound1">&#9654; Прослушать</button>
                                </label>
                                <label style="display:flex;align-items:center;gap:10px;cursor:pointer;">
                                    <input type="radio" name="chat_widget_sound" value="sound2" <?php checked( $sound, 'sound2' ); ?> />
                                    <span>Вариант 2 — Всплывающее окно</span>
                                    <button type="button" class="button wp-ru-max-preview-sound" data-sound="sound2">&#9654; Прослушать</button>
                                </label>
                                <label style="display:flex;align-items:center;gap:10px;cursor:pointer;">
                                    <input type="radio" name="chat_widget_sound" value="sound3" <?php checked( $sound, 'sound3' ); ?> />
                                    <span>Вариант 3 — Тихий сигнал</span>
                                    <button type="button" class="button wp-ru-max-preview-sound" data-sound="sound3">&#9654; Прослушать</button>
                                </label>
                            </div>
                            <p class="description" style="margin-top:8px;">Выберите звук уведомления, который будет проигрываться при появлении виджета.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label>Время проигрывания звука</label></th>
                        <td>
                            <?php
                            $sound_delay_options = array(
                                3 => 'Через 3 секунды после появления',
                                6 => 'Через 6 секунд после появления',
                                9 => 'Через 9 секунд после появления',
                            );
                            foreach ( $sound_delay_options as $val => $label ) :
                            ?>
                            <label style="display:block;margin-bottom:8px;cursor:pointer;">
                                <input type="radio" name="chat_widget_sound_delay" value="<?php echo esc_attr( $val ); ?>" <?php checked( $sound_delay, $val ); ?> style="margin-right:6px;" />
                                <?php echo esc_html( $label ); ?>
                            </label>
                            <?php endforeach; ?>
                            <p class="description">Через сколько секунд после появления виджета проиграть звук уведомления.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label>Где проигрывать звук</label></th>
                        <td>
                            <div style="display:flex;flex-direction:column;gap:8px;">
                                <label style="cursor:pointer;">
                                    <input type="radio" name="chat_widget_sound_pages" value="all" <?php checked( $sound_pages, 'all' ); ?> />
                                    На всех страницах сайта
                                </label>
                                <label style="cursor:pointer;">
                                    <input type="radio" name="chat_widget_sound_pages" value="home" <?php checked( $sound_pages, 'home' ); ?> />
                                    Только на главной странице
                                </label>
                                <label style="cursor:pointer;">
                                    <input type="radio" name="chat_widget_sound_pages" value="specific" <?php checked( $sound_pages, 'specific' ); ?> />
                                    Только на выбранных страницах
                                </label>
                            </div>
                            <div id="chat_widget_sound_specific_wrap" style="margin-top:10px;<?php echo $sound_pages === 'specific' ? '' : 'display:none;'; ?>">
                                <textarea id="chat_widget_sound_specific_pages" name="chat_widget_sound_specific_pages" rows="4" class="large-text" placeholder="/contacts&#10;/about&#10;/services/seo"><?php echo esc_textarea( $sound_specific_pages ); ?></textarea>
                                <p class="description">Укажите пути страниц (по одному на строку), на которых нужно проигрывать звук. Например: <code>/contacts</code>, <code>/about</code>. Можно указать как полный URL, так и относительный путь — сравнение идёт по совпадению с текущим путём.</p>
                            </div>
                            <p class="description" style="margin-top:8px;">Решает проблему «звук срабатывает на каждой странице» — выберите главную или конкретные страницы (например, «Контакты»), и звук будет играть только там.</p>
                            <script>
                            (function(){
                                var radios = document.getElementsByName('chat_widget_sound_pages');
                                var wrap = document.getElementById('chat_widget_sound_specific_wrap');
                                for (var i = 0; i < radios.length; i++) {
                                    radios[i].addEventListener('change', function(){
                                        wrap.style.display = (this.value === 'specific' && this.checked) ? '' : 'none';
                                    });
                                }
                            })();
                            </script>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label>Один раз за сессию</label></th>
                        <td>
                            <label class="wp-ru-max-switch" style="vertical-align:middle;">
                                <input type="checkbox" id="chat_widget_sound_once_per_session" <?php checked( $sound_once_per_session ); ?> />
                                <span class="wp-ru-max-switch-slider"></span>
                            </label>
                            <span style="margin-left:10px;">Проигрывать звук только один раз за визит (не на каждой странице)</span>
                            <p class="description">Когда включено — звук уведомления прозвучит только один раз во время посещения сайта посетителем, даже если он переходит между страницами. Это убирает раздражающее повторение звука.</p>
                        </td>
                    </tr>
                </table>
            </div>

            <div class="wp-ru-max-card">
                <h3>Анимация привлечения внимания</h3>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label>Анимация</label></th>
                        <td>
                            <div style="display:flex;flex-wrap:wrap;gap:12px;">
                                <?php
                                $anim_options = array(
                                    'none'    => 'Без анимации',
                                    'pulse'   => 'Пульсация',
                                    'ripple'  => 'Рябь',
                                    'bounce'  => 'Подпрыгивание',
                                    'shake'   => 'Покачивание',
                                    'glow'    => 'Свечение',
                                    'rotate'  => 'Вращение',
                                );
                                foreach ( $anim_options as $val => $label ) :
                                ?>
                                <label style="display:flex;align-items:center;gap:6px;cursor:pointer;background:<?php echo $animation === $val ? '#e8f0fe' : '#f8f9fa'; ?>;border:2px solid <?php echo $animation === $val ? '#4a90d9' : '#ddd'; ?>;border-radius:8px;padding:8px 14px;">
                                    <input type="radio" name="chat_widget_animation" value="<?php echo esc_attr( $val ); ?>" <?php checked( $animation, $val ); ?> />
                                    <?php echo esc_html( $label ); ?>
                                </label>
                                <?php endforeach; ?>
                            </div>
                            <p class="description" style="margin-top:8px;">Анимация для привлечения внимания к кнопке чата.</p>
                        </td>
                    </tr>
                </table>
            </div>

            <div class="wp-ru-max-card">
                <h3>Сообщения на удержание</h3>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="chat_widget_retention_enabled">Удержание</label></th>
                        <td>
                            <label class="wp-ru-max-switch">
                                <input type="checkbox" id="chat_widget_retention_enabled" <?php checked( $retention_enabled ); ?> />
                                <span class="wp-ru-max-switch-slider"></span>
                            </label>
                            <span style="margin-left:10px;">Включить попап удержания при попытке закрыть приветственное сообщение</span>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="chat_widget_retention_title">Заголовок окна</label></th>
                        <td>
                            <textarea id="chat_widget_retention_title" name="chat_widget_retention_title" rows="2" class="large-text" placeholder="Специальное предложение!"><?php echo esc_textarea( $retention_title ); ?></textarea>
                            <p class="description">Заголовок попапа удержания (поддерживаются переносы строк).</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="chat_widget_retention_message">Сообщение удержания</label></th>
                        <td>
                            <textarea id="chat_widget_retention_message" name="chat_widget_retention_message" rows="4" class="large-text" placeholder="Уже уходите? Получите скидку 10% на первый заказ..."><?php echo esc_textarea( $retention_message ); ?></textarea>
                            <p class="description">Текст сообщения, которое появится при попытке закрыть чат (поддерживаются переносы строк).</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label>Выравнивание текста</label></th>
                        <td>
                            <?php foreach ( array( 'left' => 'По левому краю', 'center' => 'По центру', 'right' => 'По правому краю' ) as $val => $lbl ) : ?>
                                <label style="margin-right:14px;cursor:pointer;">
                                    <input type="radio" name="chat_widget_retention_text_align" value="<?php echo esc_attr( $val ); ?>" <?php checked( $retention_text_align, $val ); ?> />
                                    <?php echo esc_html( $lbl ); ?>
                                </label>
                            <?php endforeach; ?>
                            <p class="description">Выравнивание заголовка и сообщения внутри попапа.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="chat_widget_retention_stay_text">Текст кнопки «Остаться»</label></th>
                        <td>
                            <input type="text" id="chat_widget_retention_stay_text" name="chat_widget_retention_stay_text" value="<?php echo esc_attr( $retention_stay_text ); ?>" class="regular-text" placeholder="Остаться" />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="chat_widget_retention_leave_text">Текст кнопки «Уйти»</label></th>
                        <td>
                            <input type="text" id="chat_widget_retention_leave_text" name="chat_widget_retention_leave_text" value="<?php echo esc_attr( $retention_leave_text ); ?>" class="regular-text" placeholder="Все равно уйти" />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label>Выравнивание кнопок</label></th>
                        <td>
                            <?php foreach ( array( 'left' => 'По левому краю', 'center' => 'По центру', 'right' => 'По правому краю' ) as $val => $lbl ) : ?>
                                <label style="margin-right:14px;cursor:pointer;">
                                    <input type="radio" name="chat_widget_retention_buttons_align" value="<?php echo esc_attr( $val ); ?>" <?php checked( $retention_buttons_align, $val ); ?> />
                                    <?php echo esc_html( $lbl ); ?>
                                </label>
                            <?php endforeach; ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="chat_widget_retention_btn_radius">Закругление кнопок (px)</label></th>
                        <td>
                            <input type="range" id="chat_widget_retention_btn_radius_range" min="0" max="50" value="<?php echo esc_attr( $retention_btn_radius ); ?>" style="width:200px;vertical-align:middle;" />
                            <input type="number" id="chat_widget_retention_btn_radius" name="chat_widget_retention_btn_radius" value="<?php echo esc_attr( $retention_btn_radius ); ?>" min="0" max="50" style="width:70px;" />
                            <p class="description">От 0 (квадратные) до 50 (овальные) пикселей.</p>
                            <script>
                            (function(){
                                var r = document.getElementById('chat_widget_retention_btn_radius_range');
                                var n = document.getElementById('chat_widget_retention_btn_radius');
                                if (r && n) {
                                    r.addEventListener('input', function(){ n.value = r.value; });
                                    n.addEventListener('input', function(){ r.value = n.value; });
                                }
                            })();
                            </script>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label>Цвета кнопки «Остаться»</label></th>
                        <td>
                            <label style="margin-right:14px;">Фон: <input type="color" name="chat_widget_retention_stay_bg" value="<?php echo esc_attr( $retention_stay_bg ); ?>" /></label>
                            <label>Текст: <input type="color" name="chat_widget_retention_stay_color" value="<?php echo esc_attr( $retention_stay_color ); ?>" /></label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label>Цвета кнопки «Уйти»</label></th>
                        <td>
                            <label style="margin-right:14px;">Фон: <input type="color" name="chat_widget_retention_leave_bg" value="<?php echo esc_attr( $retention_leave_bg ); ?>" /></label>
                            <label>Текст: <input type="color" name="chat_widget_retention_leave_color" value="<?php echo esc_attr( $retention_leave_color ); ?>" /></label>
                        </td>
                    </tr>
                </table>
            </div>

            <div class="wp-ru-max-card">
                <h3>Предварительный просмотр</h3>
                <div class="wp-ru-max-preview-box">
                    <div class="wp-ru-max-preview-site">
                        <div style="background:#f0f0f0;height:100%;border-radius:8px;display:flex;align-items:center;justify-content:center;color:#999;font-size:14px;">Ваш сайт</div>
                    </div>
                    <div class="wp-ru-max-preview-widget" id="widget_preview">
                        <div class="preview-balloon">
                            <div id="preview_message"><?php echo esc_html( $message ); ?></div>
                        </div>
                        <div class="preview-icon">
                            <img src="<?php echo esc_url( WP_RU_MAX_PLUGIN_URL . 'assets/max-64x64.png' ); ?>" alt="MAX" id="preview_icon_img" width="64" height="64" />
                        </div>
                    </div>
                </div>
            </div>

            <div class="wp-ru-max-card">
                <div class="wp-ru-max-actions">
                    <button type="button" class="button button-primary" id="save_chat_widget">Сохранить</button>
                </div>
                <div id="chat_widget_result" class="wp-ru-max-notice" style="display:none;"></div>
            </div>
        </div>
        <?php
    }

    private function render_tab_history() {
        $filter_type = isset( $_GET['type'] ) ? sanitize_text_field( $_GET['type'] ) : '';
        ?>
        <div class="wp-ru-max-card">
            <h2>История и логи</h2>
            <p>Здесь отображаются все события: отправка сообщений, ошибки подключения, тесты и изменения настроек.</p>

            <div class="wp-ru-max-history-toolbar">
                <div class="wp-ru-max-filter-tabs">
                    <?php
                    $filter_options = array(
                        ''             => 'Все события',
                        'api'          => 'API',
                        'post_sender'  => 'Публикации',
                        'notifications'=> 'Уведомления',
                        'test'         => 'Тесты',
                        'settings'     => 'Настройки',
                    );
                    foreach ( $filter_options as $val => $label ) {
                        $active = $filter_type === $val ? 'class="active"' : '';
                        echo '<a href="' . esc_url( admin_url( 'admin.php?page=wp-ru-max&tab=history&type=' . $val ) ) . '" ' . $active . '>' . esc_html( $label ) . '</a>';
                    }
                    ?>
                </div>

                <div class="wp-ru-max-history-actions">
                    <button type="button" class="button button-primary" id="send_global_test">Тест подключения</button>
                    <input type="text" id="global_test_chat" class="regular-text" placeholder="ID чата для теста..." />
                    <button type="button" class="button" id="clear_logs" data-type="<?php echo esc_attr( $filter_type ); ?>">Очистить логи</button>
                    <button type="button" class="button" id="refresh_logs">Обновить</button>
                </div>
            </div>

            <div id="history_test_result" class="wp-ru-max-notice" style="display:none;margin:10px 0;"></div>

            <div id="history_table_wrap">
                <div class="wp-ru-max-loading">Загрузка...</div>
            </div>

            <div class="wp-ru-max-pagination">
                <button type="button" class="button" id="prev_page" disabled>← Предыдущая</button>
                <span id="page_info">Страница 1</span>
                <button type="button" class="button" id="next_page">Следующая →</button>
            </div>
        </div>

        <script>
        var wpRuMaxHistoryType = '<?php echo esc_js( $filter_type ); ?>';
        </script>
        <?php
    }

    private function render_tab_activation() {
        // Каждый раз при открытии вкладки активации делаем свежую проверку
        // ключа на сервере, чтобы отозванные ключи определялись сразу.
        $existing = WP_Ru_Max_License::get_data();
        if ( ! empty( $existing['key'] ) ) {
            WP_Ru_Max_License::force_recheck();
        }

        $is_licensed = WP_Ru_Max_License::is_active();
        $license     = WP_Ru_Max_License::get_data();
        $license_obj = WP_Ru_Max_License::instance();
        $attempts    = $license_obj->get_remaining_attempts();
        $is_suspended = ! $is_licensed && ! empty( $license['status'] ) && $license['status'] === 'suspended';
        ?>
        <div class="wp-ru-max-card">
            <?php if ( $is_licensed ) : ?>
                <div class="wp-ru-max-activation-success">
                    <span class="wp-ru-max-success-icon">&#10003;</span>
                    <h2>Плагин активирован</h2>
                    <p>Все функции WP Ru-max доступны без ограничений.</p>
                    <table class="form-table" style="max-width:500px;">
                        <tr>
                            <th>Домен:</th>
                            <td><code><?php echo esc_html( $license['domain'] ?? '—' ); ?></code></td>
                        </tr>
                        <tr>
                            <th>Дата активации:</th>
                            <td><?php echo esc_html( $license['activated_at'] ?? '—' ); ?></td>
                        </tr>
                        <tr>
                            <th>Тип лицензии:</th>
                            <td>Пожизненная</td>
                        </tr>
                        <tr>
                            <th>Последняя проверка:</th>
                            <td><?php echo esc_html( $license['last_verified'] ?? '—' ); ?></td>
                        </tr>
                    </table>
                    <p style="margin-top:16px;">
                        <button type="button" class="button" id="recheck_license_btn">Проверить лицензию сейчас</button>
                    </p>
                    <div id="license_recheck_result" class="wp-ru-max-notice" style="display:none;margin-top:12px;"></div>
                </div>

            <?php else : ?>

                <h2>Активация плагина</h2>
                <?php if ( $is_suspended ) : ?>
                    <div class="wp-ru-max-notice notice-error" style="display:block;padding:12px 16px;border-left:4px solid #d63638;background:#fff;margin:12px 0;">
                        <strong>Лицензия отозвана или больше недействительна.</strong><br />
                        Ключ <code><?php echo esc_html( $license['key'] ?? '' ); ?></code> был аннулирован на сервере рукодер.рф.
                        Все функции плагина деактивированы. Введите новый лицензионный ключ или запросите его ниже.
                    </div>
                <?php endif; ?>
                <p>Для использования всех функций WP Ru-max введите лицензионный ключ. Если у вас нет ключа — запросите его ниже.</p>

                <div class="wp-ru-max-activation-block">
                    <h3>Ввести лицензионный ключ</h3>

                    <?php if ( $attempts <= 0 ) : ?>
                        <div class="wp-ru-max-notice notice-error" style="display:block;padding:12px 16px;border-left:4px solid #d63638;background:#fff;margin:12px 0;">
                            Слишком много неверных попыток. Повторить можно через <?php echo WP_Ru_Max_License::BLOCK_MINUTES; ?> минут.
                        </div>
                    <?php else : ?>

                    <table class="form-table">
                        <tr>
                            <th scope="row"><label for="license_key">Лицензионный ключ</label></th>
                            <td>
                                <input type="text" id="license_key" name="license_key"
                                    class="regular-text"
                                    placeholder="WPRM-XXXX-XXXX-XXXX-XXXX"
                                    autocomplete="off"
                                    style="text-transform:uppercase;letter-spacing:1px;font-family:monospace;" />
                                <p class="description">
                                    Формат ключа: <code>WPRM-XXXX-XXXX-XXXX-XXXX</code>.
                                    Осталось попыток: <strong><?php echo (int) $attempts; ?></strong> из <?php echo WP_Ru_Max_License::MAX_ATTEMPTS; ?>.
                                </p>
                            </td>
                        </tr>
                    </table>

                    <div class="wp-ru-max-actions">
                        <button type="button" class="button button-primary" id="activate_license_btn">
                            Активировать
                        </button>
                    </div>
                    <div id="license_activate_result" class="wp-ru-max-notice" style="display:none;margin-top:12px;"></div>

                    <?php endif; ?>
                </div>

                <hr style="margin:32px 0;" />

                <div class="wp-ru-max-activation-block">
                    <h3>Важная информация о лицензии</h3>
                    <p>
                        Стоимость лицензии WP Ru-max — <strong>2&nbsp;200&nbsp;₽</strong> за один домен.
                        Лицензия <strong>бессрочная</strong>: оплачивается один раз и действует постоянно,
                        без абонентской платы и продлений. Включает все функции плагина и обновления.
                    </p>
                    <ul style="margin-left:18px;list-style:disc;">
                        <li>Привязка к одному домену сайта</li>
                        <li>Бессрочное использование без продления</li>
                        <li>Все обновления плагина включены</li>
                        <li>Поддержка от разработчика</li>
                    </ul>
                </div>

                <hr style="margin:32px 0;" />

                <div class="wp-ru-max-activation-block">
                    <h3>Система скидок на лицензии</h3>
                    <p>При покупке нескольких доменов действует прогрессивная скидка:</p>
                    <table class="widefat striped" style="max-width:520px;">
                        <thead>
                            <tr>
                                <th>Количество доменов</th>
                                <th>Цена</th>
                                <th>Цена за домен</th>
                                <th>Экономия</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td>1 домен</td>
                                <td><strong>2&nbsp;200&nbsp;₽</strong></td>
                                <td>2&nbsp;200&nbsp;₽/шт</td>
                                <td>—</td>
                            </tr>
                            <tr>
                                <td>2 домена</td>
                                <td><strong>4&nbsp;000&nbsp;₽</strong></td>
                                <td>2&nbsp;000&nbsp;₽/шт</td>
                                <td>~9%</td>
                            </tr>
                            <tr>
                                <td>5 доменов</td>
                                <td><strong>7&nbsp;000&nbsp;₽</strong></td>
                                <td>1&nbsp;400&nbsp;₽/шт</td>
                                <td><strong>36%</strong></td>
                            </tr>
                        </tbody>
                    </table>
                    <p class="description" style="margin-top:8px;">Чем больше доменов — тем выгоднее. Для покупки нескольких лицензий свяжитесь с нами.</p>
                </div>

                <hr style="margin:32px 0;" />

                <div class="wp-ru-max-activation-block">
                    <h3>Запросить лицензионный ключ</h3>
                    <p>Нет ключа? Заполните форму — владелец плагина рассмотрит запрос и пришлёт ключ на ваш email.</p>

                    <table class="form-table">
                        <tr>
                            <th scope="row"><label for="req_name">Ваше имя *</label></th>
                            <td>
                                <input type="text" id="req_name" name="req_name" class="regular-text" placeholder="Иван Иванов" />
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="req_email">Ваш email *</label></th>
                            <td>
                                <input type="email" id="req_email" name="req_email" class="regular-text" placeholder="your@email.ru" />
                                <p class="description">На этот адрес придёт лицензионный ключ.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="req_social">Контакт для быстрой связи</label></th>
                            <td>
                                <input type="text" id="req_social" name="req_social" class="regular-text" placeholder="Telegram @username, MAX, WhatsApp +7..., VK ссылка" />
                                <p class="description">Удобный мессенджер или соцсеть, чтобы быстрее обсудить покупку и выслать ключ.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Согласия *</th>
                            <td>
                                <label class="wp-ru-max-checkbox-label">
                                    <input type="checkbox" id="consent_personal" name="consent_personal" value="1" />
                                    Даю своё согласие на <a href="https://рукодер.рф/privacy-policy/" target="_blank" rel="noopener">обработку персональных данных</a>.
                                </label>
                                <br /><br />
                                <label class="wp-ru-max-checkbox-label">
                                    <input type="checkbox" id="consent_mailing" name="consent_mailing" value="1" />
                                    Согласен(а) получать информационные рассылки и уведомления об акциях на указанный email. Подтверждаю, что могу отменить подписку в любое время.
                                </label>
                            </td>
                        </tr>
                    </table>

                    <div class="wp-ru-max-actions">
                        <button type="button" class="button button-primary" id="request_license_btn" disabled>
                            Отправить запрос
                        </button>
                    </div>
                    <div id="license_request_result" class="wp-ru-max-notice" style="display:none;margin-top:12px;"></div>
                </div>

            <?php endif; ?>
        </div>

        <script>
        (function($){
            // Ручная перепроверка действующей лицензии
            $('#recheck_license_btn').on('click', function(){
                var $btn = $(this).prop('disabled', true).text('Проверяем...');
                $.post(wpRuMax.ajaxUrl, {
                    action: 'wp_ru_max_recheck_license',
                    nonce:  wpRuMax.nonce
                }, function(resp){
                    if ( resp.success ) {
                        showResult('#license_recheck_result', true, resp.data.message || 'Лицензия действительна.');
                        $btn.prop('disabled', false).text('Проверить лицензию сейчас');
                        setTimeout(function(){ location.reload(); }, 1200);
                    } else {
                        showResult('#license_recheck_result', false, resp.data || 'Лицензия больше не действительна.');
                        setTimeout(function(){ location.reload(); }, 1500);
                    }
                }).fail(function(){
                    showResult('#license_recheck_result', false, 'Ошибка сети. Попробуйте ещё раз.');
                    $btn.prop('disabled', false).text('Проверить лицензию сейчас');
                });
            });

            // Активация по ключу
            $('#activate_license_btn').on('click', function(){
                var key = $('#license_key').val().trim().toUpperCase();
                if ( ! key ) {
                    showResult('#license_activate_result', false, 'Введите лицензионный ключ.');
                    return;
                }
                var $btn = $(this).prop('disabled', true).text('Проверяем...');
                $.post(wpRuMax.ajaxUrl, {
                    action: 'wp_ru_max_activate_license',
                    nonce:  wpRuMax.nonce,
                    license_key: key
                }, function(resp){
                    if ( resp.success ) {
                        showResult('#license_activate_result', true, resp.data.message);
                        setTimeout(function(){ location.reload(); }, 1500);
                    } else {
                        showResult('#license_activate_result', false, resp.data || 'Ошибка активации.');
                        $btn.prop('disabled', false).text('Активировать');
                    }
                }).fail(function(){
                    showResult('#license_activate_result', false, 'Ошибка сети. Попробуйте ещё раз.');
                    $btn.prop('disabled', false).text('Активировать');
                });
            });

            // Разблокировка кнопки запроса только когда оба чекбокса отмечены
            $('#consent_personal, #consent_mailing').on('change', function(){
                var both = $('#consent_personal').is(':checked') && $('#consent_mailing').is(':checked');
                $('#request_license_btn').prop('disabled', !both);
            });

            // Запрос ключа
            $('#request_license_btn').on('click', function(){
                var name  = $('#req_name').val().trim();
                var email = $('#req_email').val().trim();
                if ( ! name ) {
                    showResult('#license_request_result', false, 'Укажите ваше имя.');
                    return;
                }
                if ( ! email || ! /\S+@\S+\.\S+/.test(email) ) {
                    showResult('#license_request_result', false, 'Укажите корректный email.');
                    return;
                }
                var $btn = $(this).prop('disabled', true).text('Отправляем...');
                $.post(wpRuMax.ajaxUrl, {
                    action:    'wp_ru_max_request_license',
                    nonce:     wpRuMax.nonce,
                    req_name:  name,
                    req_email: email,
                    req_social: $('#req_social').val().trim(),
                    consent:   $('#consent_personal').is(':checked') ? 1 : 0,
                    mailing:   $('#consent_mailing').is(':checked')  ? 1 : 0
                }, function(resp){
                    if ( resp.success ) {
                        showResult('#license_request_result', true, resp.data);
                        $('#req_name, #req_email, #req_social').val('');
                        $('#consent_personal, #consent_mailing').prop('checked', false);
                        $btn.prop('disabled', true).text('Отправить запрос');
                    } else {
                        showResult('#license_request_result', false, resp.data || 'Ошибка отправки.');
                        $btn.prop('disabled', false).text('Отправить запрос');
                    }
                }).fail(function(){
                    showResult('#license_request_result', false, 'Ошибка сети. Попробуйте ещё раз.');
                    $btn.prop('disabled', false).text('Отправить запрос');
                });
            });

            function showResult(selector, success, msg){
                $(selector)
                    .removeClass('notice-success notice-error')
                    .addClass( success ? 'notice-success' : 'notice-error' )
                    .css({ display:'block', padding:'12px 16px', background:'#fff',
                           borderLeft: success ? '4px solid #00a32a' : '4px solid #d63638',
                           marginTop:'12px' })
                    .html( msg );
            }
        })(jQuery);
        </script>
        <?php
    }
}
