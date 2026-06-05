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
        add_action( 'admin_menu',                        array( $this, 'register_submenu_items' ), 999 );
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
        add_action( 'wp_ajax_wp_ru_max_send_push',       array( $this, 'ajax_send_push' ) );
        add_action( 'save_post',                         array( $this, 'persist_skip_meta_on_save' ), 10, 2 );
        add_action( 'post_submitbox_misc_actions',       array( $this, 'render_classic_editor_panel' ) );
        add_filter( 'plugin_action_links_' . WP_RU_MAX_PLUGIN_BASENAME, array( $this, 'add_plugin_links' ) );
    }

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

        if ( $skip_str === '' ) {
            // Явное значение не задано — применяем глобальную настройку По умолчанию
            $settings     = get_option( 'wp_ru_max_settings', array() );
            $default_on   = ! empty( $settings['auto_send_default'] );
            $is_on        = $default_on;
        } else {
            $is_on = ( $skip_str === '0' );
        }

        return rest_ensure_response( array(
            'on'     => $is_on,
            'stored' => $skip_str,
        ) );
    }

    public function rest_set_skip( $request ) {
        $post_id = (int) $request['post_id'];
        $on_raw  = $request->get_param( 'on' );

        $is_on = ( $on_raw === true || $on_raw === 1 || $on_raw === '1' || $on_raw === 'true' || $on_raw === 'on' );
        $value = $is_on ? '0' : '1';

        update_post_meta( $post_id, self::SKIP_META_KEY, $value );
        delete_post_meta( $post_id, self::SKIP_META_KEY_LEGACY );

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

        if ( $skip_str === '' ) {
            $settings   = get_option( 'wp_ru_max_settings', array() );
            $is_on      = ! empty( $settings['auto_send_default'] );
        } else {
            $is_on = ( $skip_str === '0' );
        }

        wp_send_json_success( array(
            'on'     => $is_on,
            'stored' => $skip_str,
        ) );
    }

    const SKIP_META_KEY        = 'wp_ru_max_skip';
    const SKIP_META_KEY_LEGACY = '_wp_ru_max_skip';

    public function maybe_migrate_skip_meta() {
        if ( get_option( 'wp_ru_max_skip_meta_migrated_v1' ) ) {
            return;
        }
        global $wpdb;
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
        $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$wpdb->postmeta} WHERE meta_key = %s",
            self::SKIP_META_KEY_LEGACY
        ) );
        update_option( 'wp_ru_max_skip_meta_migrated_v1', 1 );
    }

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

            add_action(
                'rest_after_insert_' . $post_type,
                array( $this, 'persist_skip_meta_from_rest' ),
                10, 3
            );
        }
    }

    public function sanitize_skip_meta( $value ) {
        if ( $value === '0' || $value === 0 || $value === false ) {
            return '0';
        }
        if ( is_string( $value ) && trim( $value ) === '0' ) {
            return '0';
        }
        return '1';
    }

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

        update_post_meta( $post->ID, self::SKIP_META_KEY, $normalized );

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
/* Connection status indicator */
.wp-ru-max-status-dot {
    font-size: 18px;
    line-height: 1;
    vertical-align: middle;
    margin-right: 4px;
}
.wp-ru-max-status-dot.status-green { color: #00a32a; }
.wp-ru-max-status-dot.status-red   { color: #d63638; }
.wp-ru-max-status-dot.status-unknown { color: #999; }
/* Legacy support */
.wp-ru-max-status-indicator.status-success { color: #00a32a; }
.wp-ru-max-status-indicator.status-error   { color: #d63638; }
.wp-ru-max-status-indicator.status-unknown { color: #999; }
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

        $gutenberg_js_path = WP_RU_MAX_PLUGIN_DIR . 'assets/js/gutenberg-panel.js';
        $gutenberg_js_ver  = file_exists( $gutenberg_js_path )
            ? (string) filemtime( $gutenberg_js_path )
            : WP_RU_MAX_VERSION;

        wp_enqueue_script(
            'wp-ru-max-gutenberg',
            WP_RU_MAX_PLUGIN_URL . 'assets/js/gutenberg-panel.js',
            array( 'jquery', 'wp-plugins', 'wp-edit-post', 'wp-element', 'wp-components', 'wp-data' ),
            $gutenberg_js_ver,
            true
        );
        $auto_send_default = ! empty( $settings['auto_send_default'] );
        wp_localize_script( 'wp-ru-max-gutenberg', 'wpRuMaxGutenberg', array(
            'ajaxUrl'         => admin_url( 'admin-ajax.php' ),
            'nonce'           => wp_create_nonce( 'wp_ru_max_nonce' ),
            'iconUrl'         => WP_RU_MAX_PLUGIN_URL . 'assets/max-32x32.png',
            'restUrl'         => esc_url_raw( rest_url( 'wp-ru-max/v1/skip/' ) ),
            'restNonce'       => wp_create_nonce( 'wp_rest' ),
            'autoSendDefault' => $auto_send_default,
        ) );
    }

    public function render_classic_editor_panel( $post ) {
        if ( ! $post || ! $post->ID ) {
            return;
        }
        if ( function_exists( 'use_block_editor_for_post' ) && use_block_editor_for_post( $post ) ) {
            return;
        }

        $settings = get_option( 'wp_ru_max_settings', array() );
        $token    = isset( $settings['bot_token'] ) ? $settings['bot_token'] : '';
        $channels = isset( $settings['channels'] ) ? (array) $settings['channels'] : array();

        if ( empty( $token ) || empty( $channels ) ) {
            return;
        }

        $skip    = get_post_meta( $post->ID, self::SKIP_META_KEY, true );
        $skip_is_set = ( $skip !== '' && $skip !== null && $skip !== false );
        if ( $skip_is_set ) {
            $enabled = ( $skip !== '1' );
        } else {
            $enabled = ! empty( $settings['auto_send_default'] );
        }
        $nonce   = wp_create_nonce( 'wp_ru_max_nonce' );
        $icon    = WP_RU_MAX_PLUGIN_URL . 'assets/max-32x32.png';
        ?>
        <div class="misc-pub-section wp-ru-max-classic-section" style="border-top:1px solid #ddd;padding-top:8px;margin-top:4px;">
            <img src="<?php echo esc_url( $icon ); ?>" width="16" height="16" alt="MAX" style="vertical-align:middle;margin-right:4px;">
            <strong>Отправить в MAX</strong>
            <div style="margin-top:6px;">
                <input type="hidden" name="<?php echo esc_attr( self::SKIP_META_KEY ); ?>" value="1">
                <label style="cursor:pointer;">
                    <input type="checkbox"
                           id="wp_ru_max_auto_send_classic"
                           name="<?php echo esc_attr( self::SKIP_META_KEY ); ?>"
                           value="0"
                           <?php checked( $enabled ); ?>>
                    Автоотправка в MAX: <span class="wp-ru-max-auto-label"><?php echo $enabled ? 'ВКЛ' : 'ВЫКЛ'; ?></span>
                </label>
            </div>
            <div style="margin-top:6px;">
                <button type="button"
                        class="button button-secondary wp-ru-max-send-now-classic"
                        style="width:100%;justify-content:center;"
                        data-post-id="<?php echo intval( $post->ID ); ?>"
                        data-nonce="<?php echo esc_attr( $nonce ); ?>">
                    Отправить в MAX вручную
                </button>
                <span class="wp-ru-max-classic-result" style="display:none;margin-top:4px;font-size:12px;display:block;"></span>
            </div>
        </div>
        <?php
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

    public function register_submenu_items() {
        global $submenu;
        $is_licensed = WP_Ru_Max_License::is_active();
        $submenu['wp-ru-max'] = array(
            array( 'Главная',                   'manage_options', 'admin.php?page=wp-ru-max&tab=main' ),
            array( 'Отправка публикаций',       'manage_options', 'admin.php?page=wp-ru-max&tab=post_sender' ),
            array( 'Личные уведомления',        'manage_options', 'admin.php?page=wp-ru-max&tab=notifications' ),
            array( 'Дополнительные настройки',  'manage_options', 'admin.php?page=wp-ru-max&tab=advanced' ),
            array( 'Инструкция',                'manage_options', 'admin.php?page=wp-ru-max&tab=instructions' ),
            array( 'Чат',                       'manage_options', 'admin.php?page=wp-ru-max&tab=chat' ),
            array( 'История',                   'manage_options', 'admin.php?page=wp-ru-max&tab=history' ),
            array( $is_licensed ? 'Активирован ✓' : '⚠ Активация', 'manage_options', 'admin.php?page=wp-ru-max&tab=activation' ),
            array( 'Обновления',                'manage_options', 'admin.php?page=wp-ru-max&tab=updates' ),
        );
    }

    public function enqueue_admin_scripts( $hook ) {
        $is_plugin_page = strpos( $hook, 'wp-ru-max' ) !== false;
        $is_post_edit   = in_array( $hook, array( 'post.php', 'post-new.php' ), true );

        if ( ! $is_plugin_page && ! $is_post_edit ) {
            return;
        }

        if ( $is_plugin_page ) {
            wp_enqueue_style( 'wp-ru-max-admin', WP_RU_MAX_PLUGIN_URL . 'assets/css/admin.css', array(), WP_RU_MAX_VERSION );
            wp_enqueue_script( 'wp-ru-max-admin', WP_RU_MAX_PLUGIN_URL . 'assets/js/admin.js', array( 'jquery' ), WP_RU_MAX_VERSION, true );
            wp_localize_script( 'wp-ru-max-admin', 'wpRuMax', array(
                'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                'nonce'   => wp_create_nonce( 'wp_ru_max_nonce' ),
            ) );
        }

        if ( $is_post_edit ) {
            wp_add_inline_script( 'jquery', $this->get_classic_editor_inline_js() );
        }
    }

    private function get_classic_editor_inline_js() {
        $ajax_url = esc_js( admin_url( 'admin-ajax.php' ) );
        return "
jQuery(function($){
    $(document).on('click', '.wp-ru-max-send-now-classic', function(){
        var btn    = $(this);
        var result = btn.siblings('.wp-ru-max-classic-result');
        var postId = btn.data('post-id');
        var nonce  = btn.data('nonce');
        btn.prop('disabled', true).text('Отправляю...');
        result.hide();
        $.post('" . $ajax_url . "', {
            action:  'wp_ru_max_send_post_now',
            post_id: postId,
            nonce:   nonce
        }, function(resp){
            btn.prop('disabled', false).text('Отправить в MAX вручную');
            if(resp.success){
                result.css('color','#00a32a').text('✓ ' + resp.data).show();
            } else {
                result.css('color','#d63638').text('✗ ' + (resp.data || 'Ошибка')).show();
            }
        }).fail(function(){
            btn.prop('disabled', false).text('Отправить в MAX вручную');
            result.css('color','#d63638').text('✗ Ошибка соединения').show();
        });
    });
    $(document).on('change', '#wp_ru_max_auto_send_classic', function(){
        var lbl = $(this).closest('label');
        lbl.find('.wp-ru-max-auto-label').text(this.checked ? 'ВКЛ' : 'ВЫКЛ');
    });
});
";
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
                case 'send_delay_seconds':
                case 'retry_count':
                case 'retry_delay_seconds':
                    $settings[ $field ] = max( 0, intval( $value ) );
                    break;
                case 'image_size_limit_mb':
                    $settings[ $field ] = max( 0, floatval( $value ) );
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
                case 'auto_send_default':
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
                case 'notify_plugin_updates':
                case 'notify_site_errors':
                case 'share_button_enabled':
                case 'max_oauth_enabled':
                case 'multisite_enabled':
                case 'woo_filter_enabled':
                    $settings[ $field ] = filter_var( $value, FILTER_VALIDATE_BOOLEAN );
                    break;
                case 'max_oauth_client_id':
                case 'max_oauth_client_secret':
                    $settings[ $field ] = sanitize_text_field( $value );
                    break;
                case 'post_types':
                case 'channels':
                case 'notify_chat_ids':
                case 'filter_categories':
                case 'filter_tags':
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
            $allowed_text     = array( 'bot_token', 'bot_name', 'notify_from_email', 'notify_format', 'chat_widget_size', 'chat_widget_url', 'chat_widget_message', 'chat_widget_position', 'chat_widget_sound', 'chat_widget_animation', 'chat_widget_retention_title', 'chat_widget_retention_stay_text', 'chat_widget_retention_leave_text', 'chat_widget_retention_text_align', 'chat_widget_retention_buttons_align', 'chat_widget_sound_pages', 'max_oauth_bot_username' );
            $allowed_textarea = array( 'notify_template', 'post_message_template', 'chat_widget_retention_message', 'chat_widget_sound_specific_pages' );
            $allowed_bool     = array( 'post_sender_enabled', 'send_new_post', 'send_updated_post', 'auto_send_default', 'show_read_more', 'show_action_label', 'show_author_date', 'send_post_image', 'notifications_enabled', 'send_files_by_url', 'enable_bot_api_log', 'enable_post_sender_log', 'delete_on_uninstall', 'chat_widget_enabled', 'chat_widget_retention_enabled', 'chat_widget_sound_once_per_session', 'notify_plugin_updates', 'notify_site_errors', 'share_button_enabled', 'max_oauth_enabled', 'multisite_enabled', 'woo_filter_enabled' );
            $allowed_int      = array( 'excerpt_max_chars', 'chat_widget_bottom_offset', 'chat_widget_show_delay', 'chat_widget_sound_delay', 'chat_widget_retention_btn_radius', 'chat_widget_hide_delay', 'chat_widget_repeat_delay', 'send_delay_seconds', 'retry_count', 'retry_delay_seconds' );
            $allowed_float    = array( 'image_size_limit_mb' );
            $allowed_color    = array( 'chat_widget_retention_stay_bg', 'chat_widget_retention_stay_color', 'chat_widget_retention_leave_bg', 'chat_widget_retention_leave_color' );
            $allowed_array    = array( 'post_types', 'channels', 'notify_chat_ids', 'filter_categories', 'filter_tags', 'woo_notify_statuses' );

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
            foreach ( $allowed_float as $key ) {
                if ( isset( $_POST[ $key ] ) ) {
                    $settings[ $key ] = max( 0, floatval( $_POST[ $key ] ) );
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
            } else {
                $settings['post_buttons'] = array();
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

    public function ajax_send_push() {
        check_ajax_referer( 'wp_ru_max_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Нет прав доступа.' );
        }

        // Rate limiting: не более 10 push-отправок в минуту
        $rate_key = 'wp_ru_max_push_rate_' . get_current_user_id();
        $rate     = (int) get_transient( $rate_key );
        if ( $rate >= 10 ) {
            wp_send_json_error( 'Слишком много отправок. Подождите немного.' );
        }
        set_transient( $rate_key, $rate + 1, 60 );

        $chat_id = isset( $_POST['chat_id'] ) ? sanitize_text_field( wp_unslash( $_POST['chat_id'] ) )     : '';
        $message = isset( $_POST['message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['message'] ) ) : '';

        if ( empty( $chat_id ) ) {
            wp_send_json_error( 'Укажите канал или Chat ID.' );
        }
        if ( empty( $message ) ) {
            wp_send_json_error( 'Введите текст сообщения.' );
        }

        $api    = new WP_Ru_Max_API();
        $result = $api->send_message( $chat_id, $message, 'html' );

        if ( is_wp_error( $result ) ) {
            WP_Ru_Max_Logger::log( 'push', 'error',
                'Push НЕУДАЧНО → ' . $chat_id . ': ' . $result->get_error_message() );
            wp_send_json_error( $result->get_error_message() );
        } else {
            WP_Ru_Max_Logger::log( 'push', 'success',
                'Push отправлен → ' . $chat_id,
                array( 'msg_length' => mb_strlen( $message ) ) );
            wp_send_json_success( 'Push-уведомление успешно отправлено в ' . esc_html( $chat_id ) . '!' );
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
                    'updates'      => 'Обновления',
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
                    case 'updates':
                        $this->render_tab_updates();
                        break;
                }
                ?>
            </div>
        </div>
        <?php
    }

    private function render_tab_updates() {
        ?>
        <div class="wp-ru-max-card">
            <h2>Обновления</h2>
            <p>История изменений и планы развития плагина WP Ru-max.</p>
        </div>

        <div class="wp-ru-max-card wp-ru-max-promo-block" style="background:linear-gradient(135deg,#f0f4ff 0%,#e8f5e9 100%);border-left:4px solid #6366f1;">
            <div style="display:flex;align-items:flex-start;gap:20px;flex-wrap:wrap;">
                <div style="font-size:42px;line-height:1;">🍪</div>
                <div style="flex:1;min-width:200px;">
                    <h3 style="margin:0 0 6px;color:#1e293b;font-size:16px;border:none;padding:0;">CookieRus — Баннер по законом России о cookie</h3>
                    <p style="margin:0 0 10px;color:#374151;font-size:13px;">Полнофункциональный менеджер согласия с cookie для WordPress с приоритетом на требования <strong>152-ФЗ</strong>. Плагин выводит информационный баннер с кнопками «Принять всё», «Отклонить» и «Настроить», поддерживает 5 категорий cookies (от «Необходимых» до «Рекламы»), ведёт детальный лог согласий пользователей с IP и страной, а также позволяет выгрузить историю в CSV для юриста или администратора. Встроен генератор политики cookie под российское законодательство.</p>
                    <div style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:14px;">
                        <span style="background:#e0e7ff;color:#3730a3;padding:3px 10px;border-radius:20px;font-size:12px;">Панель управления</span>
                        <span style="background:#e0e7ff;color:#3730a3;padding:3px 10px;border-radius:20px;font-size:12px;">Настройки баннера</span>
                        <span style="background:#e0e7ff;color:#3730a3;padding:3px 10px;border-radius:20px;font-size:12px;">Менеджер логов</span>
                        <span style="background:#e0e7ff;color:#3730a3;padding:3px 10px;border-radius:20px;font-size:12px;">Генератор политики</span>
                        <span style="background:#dcfce7;color:#166534;padding:3px 10px;border-radius:20px;font-size:12px;">Полный гайд по регистрации в РКН</span>
                    </div>
                    <a href="https://github.com/RuCoder-sudo/cookierus" target="_blank" rel="noopener"
                       class="button button-primary"
                       style="background:#6366f1;border-color:#4f46e5;text-decoration:none;">
                        ⬇ Скачать бесплатно на GitHub
                    </a>
                </div>
            </div>
        </div>

        <div class="wp-ru-max-card">
            <h3>В ближайших обновлениях</h3>
            <p style="color:#666;font-style:italic;margin-bottom:16px;">Запланировано к реализации — список для себя, чтобы не забыть что добавим в следующих версиях.</p>

            <ul style="margin-left:20px;list-style:disc;line-height:2;">
                <li>
                    <strong>Передача событий в Яндекс.Метрику</strong><br>
                    <span style="color:#555;">Передача событий в Яндекс.Метрику позволит анализировать поведение пользователей на сайте и улучшать его функциональность.</span>
                </li>
                <li style="margin-top:10px;">
                    <strong>CRM + автоматизация</strong><br>
                    <span style="color:#555;">Интеграция с CRM-системами (amoCRM, Bitrix24) и автоматизация отдела продаж позволит упростить работу с клиентами и повысить эффективность продаж.</span>
                </li>
                <li style="margin-top:10px;">
                    <strong>Хэштеги и упоминания</strong><br>
                    <span style="color:#555;">Хэштеги и упоминания в посте позволят уведомлять менеджеров о важных событиях или комментариях.</span>
                </li>
            </ul>
        </div>

        <div class="wp-ru-max-card">
            <h3>История версий</h3>

            <h4 style="margin-bottom:4px;">v1.0.36</h4>
            <ul style="margin-left:20px;list-style:disc;margin-bottom:16px;">
                <li>Добавлено: поддержка bbPress форумов — плагин корректно обрабатывает топики (тип записи «topic») без панели миниатюры.</li>
                <li>Добавлено: автоматический поиск изображений по трём источникам: миниатюра → первый &lt;img&gt; из контента → прикреплённый файл.</li>
                <li>Добавлено: источник найденного изображения фиксируется в журнале для удобной диагностики.</li>
            </ul>

            <h4 style="margin-bottom:4px;">v1.0.35</h4>
            <ul style="margin-left:20px;list-style:disc;margin-bottom:16px;">
                <li>Исправлено (критично): загрузка изображений — метод запроса к MAX Upload API исправлен с GET на POST /uploads?type=image. Ошибка «Path /uploads is not recognized» устранена.</li>
                <li>Добавлено: прямой multipart-POST файла на /uploads?type=image (Метод A) — основной способ загрузки изображений.</li>
                <li>Добавлено: 4 уровня отказоустойчивости: Метод A → Метод B → URL → только текст.</li>
                <li>Добавлено: детальное логирование всех этапов загрузки изображений с HTTP-кодами и телом ответа MAX API.</li>
            </ul>

            <h4 style="margin-bottom:4px;">v1.0.34</h4>
            <ul style="margin-left:20px;list-style:disc;margin-bottom:16px;">
                <li>Добавлено: WooCommerce — фильтр уведомлений по статусам заказа (ожидает оплаты, в обработке, выполнен и др.).</li>
                <li>Добавлено: защита от дублей WooCommerce — одно и то же уведомление о заказе больше не приходит 2–3 раза подряд.</li>
                <li>Добавлено: настройки WooCommerce-фильтра в разделе «Личные уведомления» → «WooCommerce — фильтр заказов».</li>
            </ul>

            <h4 style="margin-bottom:4px;">v1.0.33</h4>
            <ul style="margin-left:20px;list-style:disc;margin-bottom:16px;">
                <li>Исправлено: публикации снова приходят с фото — MAX API перестал принимать изображения по URL; реализована бинарная загрузка через Upload API (multipart/form-data) с получением token.</li>
                <li>Добавлено: мерцание вкладки «Активация» (анимация pulse) — пока плагин не активирован, вкладка привлекает внимание.</li>
                <li>Добавлено: блок «Запросить лицензионный ключ» перемещён ниже формы ввода ключа.</li>
                <li>Добавлено: поле «Ссылка на ваш сайт» в форме запроса лицензии (обязательное).</li>
                <li>Добавлено: чекбокс-согласие (pre-checked) — «Ознакомлен, что токен бота в MAX доступен только ИП и ООО».</li>
                <li>Добавлено: подменю в боковой панели WordPress при наведении на «Ru-max».</li>
                <li>Добавлено: рекламный блок CookieRus на вкладке «Обновления».</li>
                <li>Добавлено: блок «Согласие пользователя» со ссылками на документацию под формой активации лицензии.</li>
            </ul>

            <h4 style="margin-bottom:4px;">v1.0.32</h4>
            <ul style="margin-left:20px;list-style:disc;margin-bottom:16px;">
                <li>Добавлено: поддержка WordPress Multisite (сеть сайтов) — плагин корректно работает на всех подсайтах сети.</li>
                <li>Добавлено: тумблер «Включить поддержку сети и поддоменов» во вкладке «Дополнительные настройки» — позволяет полностью управлять этой функцией.</li>
                <li>Добавлено: сетевая лицензия — страница «Сеть → Ru-max (Сеть)» для суперадминистратора. Одна лицензия покрывает все подсайты.</li>
                <li>Добавлено: поддержка поддоменов — лицензия на корневой домен (example.com) автоматически распространяется на sub.example.com и другие поддомены.</li>
                <li>Добавлено: при добавлении нового подсайта в сеть таблица истории создаётся автоматически.</li>
                <li>Добавлено: совместимость с PHP 7.4+ — полифил str_ends_with() для PHP &lt; 8.0.</li>
                <li>Заголовок плагина: Network: true — поддержка активации для всей сети через «Сеть → Плагины».</li>
            </ul>

            <h4 style="margin-bottom:4px;">v1.0.31</h4>
            <ul style="margin-left:20px;list-style:disc;margin-bottom:16px;">
                <li>Добавлено: кнопка «Поделиться в MAX» — при включении добавляет синюю кнопку в конце каждой статьи/страницы. На мобильных — нативный диалог «Поделиться» ОС, на ПК — попап с копированием ссылки.</li>
                <li>Добавлено: MAX-авторизация через Mini App — переработана с нуля. MAX не поддерживает стандартный OAuth 2.0; реализована авторизация через HMAC-SHA256 + initData (Bot Token, без Client ID/Secret). Кнопка «Войти через MAX» ведёт на страницу бота; при открытии сайта из MAX вход происходит автоматически.</li>
                <li>Исправлено: инструкция Шаг 6 переписана под Mini App-подход — убран Redirect URI, добавлено поле Username бота.</li>
                <li>Исправлено: порядок карточек в «Дополнительных настройках» — Изображения → Файлы → Поделиться → Авторизация → Журналы → Отладка → Очистка.</li>
                <li>Исправлено: обратный вызов авторизации перенесён на хук init/priority 20 (был priority 1 — слишком рано).</li>
                <li>Исправлено: WooCommerce-хуки подключаются через class_exists() вместо plugins_loaded (уже отработал к моменту создания класса).</li>
            </ul>

            <h4 style="margin-bottom:4px;">v1.0.30</h4>
            <ul style="margin-left:20px;list-style:disc;margin-bottom:16px;">
                <li>Блок «Отправка push-уведомлений в MAX» перенесён под «Канал(ы)».</li>
                <li>В «Личных уведомлениях» новый раздел «Правила отправки» — уведомления обновлений плагинов/ядра WP и уведомления критических ошибок сайта.</li>
                <li>При обновлении плагинов WordPress — отправка сообщения в MAX со списком обновлённых плагинов.</li>
                <li>При фатальной PHP-ошибке — уведомление в MAX (не чаще 1 раза в 5 минут).</li>
            </ul>

            <h4 style="margin-bottom:4px;">v1.0.29</h4>
            <ul style="margin-left:20px;list-style:disc;margin-bottom:16px;">
                <li>Раздел «Отправка push-уведомлений в MAX» во вкладке «Отправка публикаций».</li>
                <li>Поддержка WP Mail SMTP, FluentSMTP, Postman SMTP — перехват писем на приоритете 5.</li>
                <li>Безопасность: токен бота маскируется в логах.</li>
                <li>Rate-limiting для push-отправок — не более 10 раз в минуту.</li>
                <li>Кэш ответа API /me через transient на 5 минут.</li>
                <li>Совместимость с WordPress 7.0.</li>
            </ul>

            <h4 style="margin-bottom:4px;">v1.0.28</h4>
            <ul style="margin-left:20px;list-style:disc;margin-bottom:16px;">
                <li>Настройка «Автоотправка по умолчанию» теперь отображается как тумблер (в стиле Gutenberg).</li>
                <li>Новая анимация «Левитация» для кнопки чат-виджета — плавное парение с динамической тенью.</li>
            </ul>

            <h4 style="margin-bottom:4px;">v1.0.27</h4>
            <ul style="margin-left:20px;list-style:disc;margin-bottom:16px;">
                <li>Поддержка Jetpack Contact Form — экранирование email-адресов.</li>
                <li>Глобальная настройка «Автоотправка по умолчанию».</li>
                <li>Тумблер в редакторе отражает глобальный «По умолчанию».</li>
                <li>Сворачиваемые списки категорий и тегов (>8 элементов).</li>
            </ul>

            <p style="margin-top:8px;"><a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-ru-max&tab=history' ) ); ?>">→ Открыть журнал событий</a></p>
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
            <div id="bot_status" style="display:flex;align-items:center;gap:8px;font-size:15px;">
                <span class="wp-ru-max-status-dot status-unknown">●</span>
                <span>Нажмите «Проверить подключение» для проверки статуса.</span>
            </div>
        </div>
        <?php endif; ?>
        <?php
    }

    private function render_tab_post_sender( $settings ) {
        $enabled            = ! empty( $settings['post_sender_enabled'] );
        $send_delay         = isset( $settings['send_delay_seconds'] )    ? (int) $settings['send_delay_seconds']    : 0;
        $retry_count        = isset( $settings['retry_count'] )           ? (int) $settings['retry_count']           : 2;
        $retry_delay        = isset( $settings['retry_delay_seconds'] )   ? (int) $settings['retry_delay_seconds']   : 5;
        $image_limit        = isset( $settings['image_size_limit_mb'] )   ? (float) $settings['image_size_limit_mb'] : 5;
        $filter_categories  = isset( $settings['filter_categories'] )     ? (array) $settings['filter_categories']   : array();
        $filter_tags        = isset( $settings['filter_tags'] )           ? (array) $settings['filter_tags']         : array();
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
                <h3>Отправка push-уведомлений в MAX</h3>
                <p>Отправьте произвольное сообщение напрямую от бота в выбранный канал/группу MAX.</p>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="push_chat_id">Канал / Chat ID <span style="color:#d63638;">*</span></label></th>
                        <td>
                            <input type="text" id="push_chat_id" class="regular-text" placeholder="@channel_name или -100123456789" />
                            <p class="description">Имя канала или числовой ID группы из списка «Канал(ы)» выше.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="push_message">Сообщение</label></th>
                        <td>
                            <textarea id="push_message" rows="5" class="large-text" placeholder="Текст push-уведомления..."></textarea>
                            <p class="description">Поддерживается HTML: <code>&lt;b&gt;</code>, <code>&lt;i&gt;</code>, <code>&lt;a href="..."&gt;</code>.</p>
                        </td>
                    </tr>
                </table>
                <div class="wp-ru-max-actions" style="margin-top:12px;">
                    <button type="button" class="button button-primary" id="send_push_btn">Отправить push</button>
                </div>
                <div id="push_result" class="wp-ru-max-notice" style="display:none;margin-top:10px;"></div>
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
                        <th scope="row">Автоотправка по умолчанию</th>
                        <td>
                            <div style="display:flex;align-items:center;gap:10px;margin-bottom:6px;">
                                <label class="wp-ru-max-toggle">
                                    <input type="checkbox" name="auto_send_default" value="1" <?php checked( ! empty( $settings['auto_send_default'] ) ); ?> />
                                    <span class="wp-ru-max-toggle-slider"></span>
                                </label>
                                <strong>ВКЛ для всех новых записей</strong>
                            </div>
                            <p class="description">Если включено — тумблер «Автоотправка в MAX» будет по умолчанию <strong>ВКЛ</strong> для каждой новой записи, которую ещё не редактировали вручную. Если выключено — по умолчанию <strong>ВЫКЛ</strong>.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Ссылка "Читать полностью"</th>
                        <td>
                            <label>
                                <input type="checkbox" name="show_read_more" value="1" <?php checked( isset( $settings['show_read_more'] ) ? $settings['show_read_more'] : true ); ?> />
                                Добавлять ссылку на статью в конце сообщения
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="excerpt_max_chars">Длина анонса (символов)</label></th>
                        <td>
                            <input type="number" id="excerpt_max_chars" name="excerpt_max_chars"
                                value="<?php echo esc_attr( isset( $settings['excerpt_max_chars'] ) ? $settings['excerpt_max_chars'] : 300 ); ?>"
                                min="0" max="4096" step="10" class="small-text" />
                            <span> символов</span>
                            <p class="description"><strong>0</strong> — без ограничений. Рекомендуется: <strong>300</strong>.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Метка типа публикации</th>
                        <td>
                            <label>
                                <input type="checkbox" name="show_action_label" value="1" <?php checked( isset( $settings['show_action_label'] ) ? $settings['show_action_label'] : true ); ?> />
                                Показывать метку «Новая публикация» / «Обновлённая публикация»
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Автор и дата</th>
                        <td>
                            <label>
                                <input type="checkbox" name="show_author_date" value="1" <?php checked( isset( $settings['show_author_date'] ) ? $settings['show_author_date'] : true ); ?> />
                                Показывать автора и дату в сообщении
                            </label>
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
                <h3>Фильтр по категориям и тегам</h3>
                <p class="description">Оставьте все позиции не отмеченными, чтобы отправлять записи из всех категорий/тегов. Если выбрать конкретные — отправка будет только для записей, у которых есть хотя бы одна совпадающая категория или тег.</p>
                <table class="form-table">
                    <tr>
                        <th scope="row">Категории</th>
                        <td>
                            <?php
                            $all_cats        = get_categories( array( 'hide_empty' => false, 'number' => 200 ) );
                            $cats_threshold  = 8;
                            if ( ! empty( $all_cats ) ) {
                                $cats_total     = count( $all_cats );
                                $cats_collapsed = $cats_total > $cats_threshold;
                                $checked_cats   = array_filter( $all_cats, function( $c ) use ( $filter_categories ) {
                                    return in_array( (string) $c->term_id, array_map( 'strval', $filter_categories ), true );
                                } );
                                foreach ( $all_cats as $i => $cat ) :
                                    $is_checked  = in_array( (string) $cat->term_id, array_map( 'strval', $filter_categories ), true );
                                    $hidden      = $cats_collapsed && $i >= $cats_threshold && ! $is_checked;
                                    $item_style  = $hidden
                                        ? 'display:none;margin-right:16px;margin-bottom:6px;'
                                        : 'display:inline-block;margin-right:16px;margin-bottom:6px;';
                                ?>
                                <label class="wp-ru-max-term-label" style="<?php echo $item_style; ?>">
                                    <input type="checkbox" name="filter_categories[]" value="<?php echo esc_attr( $cat->term_id ); ?>"
                                        <?php checked( $is_checked ); ?> />
                                    <?php echo esc_html( $cat->name ); ?>
                                </label>
                                <?php endforeach;
                                if ( $cats_collapsed ) : ?>
                                <div style="margin-top:6px;">
                                    <button type="button" class="button button-small"
                                            onclick="wpRuMaxToggleTerms(this, 8)"
                                            data-more="<?php echo esc_attr( sprintf( '▼ Показать все %d категории', $cats_total ) ); ?>"
                                            data-less="▲ Свернуть">
                                        <?php echo esc_html( sprintf( '▼ Показать все %d категории', $cats_total ) ); ?>
                                    </button>
                                </div>
                                <?php endif;
                            } else {
                                echo '<p class="description">Категории не найдены.</p>';
                            }
                            ?>
                            <p class="description" style="margin-top:8px;">Пусто (ничего не отмечено) = отправлять из всех категорий.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Теги</th>
                        <td>
                            <?php
                            $all_tags       = get_tags( array( 'hide_empty' => false, 'number' => 200 ) );
                            $tags_threshold = 8;
                            if ( ! empty( $all_tags ) ) {
                                $tags_total     = count( $all_tags );
                                $tags_collapsed = $tags_total > $tags_threshold;
                                foreach ( $all_tags as $i => $tag ) :
                                    $is_checked  = in_array( (string) $tag->term_id, array_map( 'strval', $filter_tags ), true );
                                    $hidden      = $tags_collapsed && $i >= $tags_threshold && ! $is_checked;
                                    $item_style  = $hidden
                                        ? 'display:none;margin-right:16px;margin-bottom:6px;'
                                        : 'display:inline-block;margin-right:16px;margin-bottom:6px;';
                                ?>
                                <label class="wp-ru-max-term-label" style="<?php echo $item_style; ?>">
                                    <input type="checkbox" name="filter_tags[]" value="<?php echo esc_attr( $tag->term_id ); ?>"
                                        <?php checked( $is_checked ); ?> />
                                    <?php echo esc_html( $tag->name ); ?>
                                </label>
                                <?php endforeach;
                                if ( $tags_collapsed ) : ?>
                                <div style="margin-top:6px;">
                                    <button type="button" class="button button-small"
                                            onclick="wpRuMaxToggleTerms(this, 8)"
                                            data-more="<?php echo esc_attr( sprintf( '▼ Показать все %d тега', $tags_total ) ); ?>"
                                            data-less="▲ Свернуть">
                                        <?php echo esc_html( sprintf( '▼ Показать все %d тега', $tags_total ) ); ?>
                                    </button>
                                </div>
                                <?php endif;
                            } else {
                                echo '<p class="description">Теги не найдены.</p>';
                            }
                            ?>
                            <p class="description" style="margin-top:8px;">Пусто (ничего не отмечено) = отправлять с любыми тегами.</p>
                        </td>
                    </tr>
                </table>
            </div>

            <div class="wp-ru-max-card">
                <h3>Задержка и повторные попытки</h3>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label>Задержка отправки</label></th>
                        <td>
                            <?php
                            $delay_opts = array( 0 => 'Без задержки', 30 => '30 секунд', 60 => '1 минута', 120 => '2 минуты', 300 => '5 минут' );
                            foreach ( $delay_opts as $val => $label ) :
                            ?>
                            <label style="display:inline-flex;align-items:center;margin-right:16px;margin-bottom:6px;">
                                <input type="radio" name="send_delay_seconds" value="<?php echo esc_attr( $val ); ?>" <?php checked( $send_delay, $val ); ?> style="margin-right:5px;" />
                                <?php echo esc_html( $label ); ?>
                            </label>
                            <?php endforeach; ?>
                            <p class="description">Задержка отправки через WP-Cron — устраняет гонку условий с обработкой изображения.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="retry_count">Число повторных попыток</label></th>
                        <td>
                            <input type="number" id="retry_count" name="retry_count" value="<?php echo esc_attr( $retry_count ); ?>" min="0" max="5" class="small-text" />
                            <p class="description">Сколько раз повторить отправку при ошибке API. <strong>0</strong> — без повторов.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="retry_delay_seconds">Интервал между попытками (сек)</label></th>
                        <td>
                            <input type="number" id="retry_delay_seconds" name="retry_delay_seconds" value="<?php echo esc_attr( $retry_delay ); ?>" min="1" max="30" class="small-text" />
                            <span> сек</span>
                            <p class="description">Пауза между повторными попытками (1–30 секунд).</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="image_size_limit_mb">Макс. размер изображения (МБ)</label></th>
                        <td>
                            <input type="number" id="image_size_limit_mb" name="image_size_limit_mb" value="<?php echo esc_attr( $image_limit ); ?>" min="0" max="50" step="0.5" class="small-text" />
                            <span> МБ</span>
                            <p class="description">Изображения тяжелее этого лимита автоматически пропускаются — отправляется только текст сообщения. <strong>0</strong> — без ограничения.</p>
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
                                Поля записи: <code>{meta_FIELDNAME}</code> — стандартные мета-поля, <code>{acf_FIELDNAME}</code> — поля ACF.
                            </p>
                        </td>
                    </tr>
                </table>
            </div>

            <div class="wp-ru-max-card">
                <h3>Встроенные кнопки клавиатуры</h3>
                <p>Здесь вы можете добавить свои собственные кнопки, которые будут отображаться под каждой публикацией в MAX.</p>

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
                <strong>Внимание:</strong> Уведомления включены, но не указан ни один ID чата/группы. Добавьте ID чата и нажмите <strong>«Сохранить»</strong>.
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
                            <p class="description">Если вы хотите получать уведомления с каждой электронной почты, напишите <code>any</code>.</p>
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
                <h3>Шаблон сообщения</h3>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="notify_template">Шаблон</label></th>
                        <td>
                            <textarea id="notify_template" name="notify_template" rows="6" class="large-text code"><?php echo esc_textarea( $settings['notify_template'] ?? "<b>{email_subject}</b>\n{email_message}" ); ?></textarea>
                            <p class="description">Переменные: <code>{email_subject}</code> <code>{email_message}</code></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label>Форматирование</label></th>
                        <td>
                            <label><input type="radio" name="notify_format" value="none" <?php checked( ( $settings['notify_format'] ?? 'html' ), 'none' ); ?> /> Нет</label>&nbsp;&nbsp;
                            <label><input type="radio" name="notify_format" value="html" <?php checked( ( $settings['notify_format'] ?? 'html' ), 'html' ); ?> /> HTML стиль</label>&nbsp;&nbsp;
                            <label><input type="radio" name="notify_format" value="markdown" <?php checked( ( $settings['notify_format'] ?? 'html' ), 'markdown' ); ?> /> Markdown</label>
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
                            <label style="display:block;margin-bottom:10px;">
                                <input type="checkbox" name="notify_plugin_updates" value="1" <?php checked( ! empty( $settings['notify_plugin_updates'] ) ); ?> />
                                <strong>Уведомления обновления плагинов</strong>
                                <span class="description"> — уведомление в MAX при обновлении плагинов и ядра WordPress</span>
                            </label>
                            <label style="display:block;">
                                <input type="checkbox" name="notify_site_errors" value="1" <?php checked( ! empty( $settings['notify_site_errors'] ) ); ?> />
                                <strong>Уведомление ошибок сайта</strong>
                                <span class="description"> — уведомление в MAX при критических PHP-ошибках (fatal error)</span>
                            </label>
                            <p class="description" style="margin-top:10px;">Если ничего не включено — модуль работает как прежде: перехватывает все письма и доставляет их в MAX.</p>
                        </td>
                    </tr>
                </table>
            </div>

            <div class="wp-ru-max-card">
                <h3>WooCommerce — фильтр заказов</h3>
                <p>Настройте, о каких заказах WooCommerce получать уведомления. Включённая защита от дублей гарантирует, что одно и то же уведомление о заказе придёт только один раз.</p>
                <table class="form-table">
                    <tr>
                        <th scope="row">Включить фильтр WooCommerce</th>
                        <td>
                            <label>
                                <input type="checkbox" name="woo_filter_enabled" id="woo_filter_enabled" value="1" <?php checked( ! empty( $settings['woo_filter_enabled'] ) ); ?> />
                                <strong>Фильтр по статусам + защита от дублей</strong>
                            </label>
                            <p class="description">Без этой опции приходят <em>все</em> письма WooCommerce, включая дубли (новый заказ + смена статуса = 2+ одинаковых уведомления).</p>
                        </td>
                    </tr>
                    <tr id="woo_statuses_row" style="<?php echo empty( $settings['woo_filter_enabled'] ) ? 'display:none;' : ''; ?>">
                        <th scope="row">Статусы для уведомлений</th>
                        <td>
                            <?php
                            $woo_statuses = array(
                                'pending'    => 'Ожидает оплаты',
                                'processing' => 'В обработке (оплачен)',
                                'on-hold'    => 'На удержании',
                                'completed'  => 'Выполнен',
                                'cancelled'  => 'Отменён',
                                'refunded'   => 'Возврат средств',
                                'failed'     => 'Ошибка оплаты',
                            );
                            $selected_statuses = isset( $settings['woo_notify_statuses'] )
                                ? (array) $settings['woo_notify_statuses']
                                : array( 'processing', 'completed' );
                            foreach ( $woo_statuses as $slug => $label ) :
                            ?>
                            <label style="display:block;margin-bottom:6px;">
                                <input type="checkbox" name="woo_notify_statuses[]" value="<?php echo esc_attr( $slug ); ?>" <?php checked( in_array( $slug, $selected_statuses, true ) ); ?> />
                                <?php echo esc_html( $label ); ?> <code style="font-size:11px;color:#666;"><?php echo esc_html( $slug ); ?></code>
                            </label>
                            <?php endforeach; ?>
                            <p class="description" style="margin-top:8px;">Если ни один статус не выбран — уведомления WooCommerce не отправляются совсем.</p>
                        </td>
                    </tr>
                </table>
            </div>

            <div class="wp-ru-max-card">
                <h3>Встроенные кнопки клавиатуры</h3>
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
                        <p class="description"><strong>Включено</strong> — изображение (превью) поста отправляется вместе с сообщением в MAX.</p>
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
            <h3>Multisite и поддомены</h3>
            <p>Поддержка WordPress Multisite (сеть сайтов) и автоматическое распространение лицензии на поддомены и субдомены.</p>
            <table class="form-table">
                <tr>
                    <th scope="row">Включить поддержку сети и поддоменов</th>
                    <td>
                        <div style="display:flex;align-items:center;gap:12px;">
                            <label class="wp-ru-max-toggle">
                                <input type="checkbox" id="multisite_enabled" <?php checked( ! empty( $settings['multisite_enabled'] ) ); ?> />
                                <span class="wp-ru-max-toggle-slider"></span>
                            </label>
                            <span><?php echo ! empty( $settings['multisite_enabled'] ) ? '<strong>Включено</strong>' : 'Выключено'; ?></span>
                        </div>
                        <p class="description" style="margin-top:8px;">
                            При включении: лицензия на корневой домен (<code><?php echo esc_html( WP_Ru_Max_License::get_network_domain() ); ?></code>) автоматически распространяется на все поддомены и субдомены, а также на все подсайты WordPress Multisite-сети.<br>
                            При выключении: каждый сайт проверяет только свою лицензию независимо.
                        </p>
                    </td>
                </tr>
            </table>
        </div>

        <div class="wp-ru-max-card">
            <h3>Кнопка «Поделиться в MAX»</h3>
            <p>Добавляет кнопку «Поделиться в MAX» в конец каждой статьи на сайте — посетители смогут поделиться материалом в мессенджере MAX одним кликом.</p>
            <table class="form-table">
                <tr>
                    <th scope="row">Показывать кнопку</th>
                    <td>
                        <div style="display:flex;align-items:center;gap:12px;">
                            <label class="wp-ru-max-toggle">
                                <input type="checkbox" id="share_button_enabled" <?php checked( ! empty( $settings['share_button_enabled'] ) ); ?> />
                                <span class="wp-ru-max-toggle-slider"></span>
                            </label>
                            <span><?php echo ! empty( $settings['share_button_enabled'] ) ? '<strong>Включено</strong>' : 'Выключено'; ?></span>
                        </div>
                        <p class="description" style="margin-top:8px;">Кнопка появится в конце каждой записи и страницы (одиночный просмотр). На мобильных открывается нативный диалог «Поделиться» — пользователь выбирает MAX из списка приложений. На ПК — ссылка на статью копируется в буфер обмена.</p>
                    </td>
                </tr>
            </table>
        </div>

        <div class="wp-ru-max-card">
            <h3>MAX-авторизация (Mini App)</h3>
            <p>Позволяет посетителям входить на сайт через мессенджер MAX. Кнопка «Войти через MAX» появится на странице входа, регистрации и при оформлении заказа WooCommerce.</p>
            <p style="background:#f0f6ff;border-left:4px solid #0077ff;padding:10px 14px;border-radius:0 6px 6px 0;font-size:13px;margin:0 0 12px;">
                <strong>Как работает:</strong> MAX использует систему Mini App — <strong>не</strong> стандартный OAuth. Пользователь нажимает «Войти через MAX», переходит к боту в мессенджере, открывает мини-приложение (ваш сайт) — и вход происходит автоматически. Подпись данных проверяется через <strong>Bot Token</strong> (уже задан в главных настройках).
            </p>
            <table class="form-table">
                <tr>
                    <th scope="row">Включить авторизацию через MAX</th>
                    <td>
                        <div style="display:flex;align-items:center;gap:12px;">
                            <label class="wp-ru-max-toggle">
                                <input type="checkbox" id="max_oauth_enabled" <?php checked( ! empty( $settings['max_oauth_enabled'] ) ); ?> />
                                <span class="wp-ru-max-toggle-slider"></span>
                            </label>
                            <span><?php echo ! empty( $settings['max_oauth_enabled'] ) ? '<strong>Включено</strong>' : 'Выключено'; ?></span>
                        </div>
                    </td>
                </tr>
                <tr id="max_oauth_bot_row" style="<?php echo empty( $settings['max_oauth_enabled'] ) ? 'display:none;' : ''; ?>">
                    <th scope="row"><label for="max_oauth_bot_username">Username бота</label></th>
                    <td>
                        <input type="text" id="max_oauth_bot_username" class="regular-text" autocomplete="off"
                            value="<?php echo esc_attr( $settings['max_oauth_bot_username'] ?? '' ); ?>"
                            placeholder="@your_bot или your_bot" />
                        <p class="description">Username вашего MAX-бота (с @ или без). Кнопка «Войти через MAX» ведёт на <code>https://max.ru/USERNAME</code>.</p>
                        <p class="description" style="margin-top:6px;color:#6b7280;">
                            Также необходимо настроить <strong>Mini App</strong> в MAX Partner Platform: Чат-боты → ваш бот → «Мини-приложение» → укажите URL вашего сайта (<code><?php echo esc_html( home_url() ); ?></code>).
                        </p>
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
                <tr><th>Plugin:</th><td>WP Ru-max v<?php echo esc_html( WP_RU_MAX_VERSION ); ?></td></tr>
                <tr><th>WordPress:</th><td><?php echo esc_html( get_bloginfo( 'version' ) ); ?></td></tr>
                <tr><th>PHP:</th><td><?php echo esc_html( phpversion() ); ?></td></tr>
                <tr><th>cURL:</th><td><?php echo function_exists( 'curl_version' ) ? '✓' : '✗'; ?></td></tr>
                <tr><th>API Base:</th><td><?php echo esc_html( WP_RU_MAX_API_BASE ); ?></td></tr>
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

        <script>
        (function($){
            $('#max_oauth_enabled').on('change', function(){
                $('#max_oauth_bot_row').toggle(this.checked);
            });
        })(jQuery);
        </script>
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
                <li>Перейдите на <a href="https://max.ru/partner" target="_blank" rel="noopener"><strong>платформу MAX для партнёров</strong></a> и войдите в аккаунт или зарегистрируйтесь.</li>
                <li>Создайте профиль вашей организации и пройдите верификацию.</li>
            </ol>
        </div>
        <div class="wp-ru-max-card">
            <h3>Шаг 2: Создание бота</h3>
            <ol>
                <li>В личном кабинете перейдите в раздел <strong>«Чат-боты»</strong>.</li>
                <li>Нажмите <strong>«Создать нового чат-бота»</strong> и заполните поля.</li>
                <li>Отправьте бота на модерацию и дождитесь одобрения.</li>
            </ol>
        </div>
        <div class="wp-ru-max-card">
            <h3>Шаг 3: Получение токена бота</h3>
            <ol>
                <li>Перейдите в раздел <strong>«Чат-боты»</strong> → выберите нужного бота.</li>
                <li>Откройте раздел <strong>«Интеграция»</strong>.</li>
                <li>Нажмите <strong>«Получить токен»</strong> и скопируйте значение токена.</li>
            </ol>
        </div>
        <div class="wp-ru-max-card">
            <h3>Шаг 4: Настройка плагина WP Ru-max</h3>
            <ol>
                <li>Перейдите на вкладку <a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-ru-max&tab=main' ) ); ?>"><strong>«Главная»</strong></a>.</li>
                <li>Вставьте токен бота в поле <strong>«Токен бота»</strong>.</li>
                <li>Нажмите <strong>«Проверить подключение»</strong> — должен появиться зелёный статус.</li>
                <li>Сохраните настройки.</li>
            </ol>
        </div>
        <div class="wp-ru-max-card">
            <h3>Шаг 5: Получение ID канала или чата</h3>
            <ul>
                <li>Для <strong>публичного канала</strong>: используйте никнейм в формате <code>@channel_name</code>.</li>
                <li>Для <strong>группы или приватного чата</strong>: числовой ID (например, <code>-100123456789</code>).</li>
            </ul>
        </div>
        <div class="wp-ru-max-card">
            <h3>Шаг 6 (необязательно): Настройка MAX-авторизации (Mini App)</h3>
            <p>Если вы хотите, чтобы посетители сайта могли входить через аккаунт мессенджера MAX — выполните следующие шаги. MAX использует систему Mini App, а не стандартный OAuth — Client ID и Secret <strong>не нужны</strong>.</p>
            <ol>
                <li>
                    Перейдите на <a href="https://max.ru/partner" target="_blank" rel="noopener"><strong>платформу MAX для партнёров</strong></a> и войдите в аккаунт.
                </li>
                <li>
                    Откройте раздел <strong>«Чат-боты»</strong>, выберите вашего бота, перейдите на вкладку <strong>«Мини-приложение»</strong>.
                </li>
                <li>
                    В поле <strong>URL мини-приложения</strong> введите адрес вашего сайта:
                    <br><br>
                    <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                        <code style="display:inline-block;background:#f3f4f6;padding:7px 12px;border-radius:6px;font-size:13px;word-break:break-all;border:1px solid #dde3ed;">https://ваш-сайт.ru</code>
                    </div>
                    <br>
                    <em>Именно здесь MAX будет открывать ваш сайт как мини-приложение. Авторизация не требует никаких redirect URI — всё работает через подпись Bot Token.</em>
                </li>
                <li>
                    Скопируйте <strong>Username бота</strong> (например, <code>@your_bot</code>) — он виден в настройках бота в MAX Partner Platform.
                </li>
                <li>
                    Перейдите на вкладку <a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-ru-max&tab=advanced' ) ); ?>"><strong>«Дополнительные настройки»</strong></a> → раздел <strong>«MAX-авторизация (Mini App)»</strong>.
                </li>
                <li>
                    Включите тумблер <strong>«Включить авторизацию через MAX»</strong>, введите <strong>Username бота</strong>, нажмите <strong>«Сохранить»</strong>.<br>
                    <em>Bot Token уже задан в главных настройках — дополнительно вводить его не нужно.</em>
                </li>
                <li>
                    Готово! На странице входа, регистрации и оформления заказа WooCommerce появится кнопка <strong>«Войти через MAX»</strong>. Пользователь нажимает кнопку → открывает ваш сайт в MAX → вход происходит автоматически.
                </li>
            </ol>
            <p style="margin-top:12px;padding:10px 14px;background:#f0fff4;border-left:4px solid #00a854;border-radius:0 6px 6px 0;">
                <strong>Как работает авторизация:</strong> MAX передаёт подписанные данные пользователя (имя, ID, аватар) прямо в мини-приложение. Плагин проверяет подпись через HMAC-SHA256 с Bot Token — подделать данные невозможно. Пользователь может войти только открыв сайт из MAX.
            </p>
        </div>
        <div class="wp-ru-max-card">
            <h3>Полезные ссылки</h3>
            <ul>
                <li><a href="https://max.ru/partner" target="_blank" rel="noopener">Платформа MAX для партнёров</a></li>
                <li><a href="https://dev.max.ru" target="_blank" rel="noopener">Документация MAX API</a></li>
                <li><a href="https://рукодер.рф/" target="_blank" rel="noopener">Разработка сайтов под ключ</a></li>
            </ul>
        </div>
        <div class="wp-ru-max-card">
            <h3>Согласие пользователя</h3>
            <p>Используя плагин WP Ru-max и активируя лицензию, пользователь подтверждает, что ознакомлен с указанными ниже страницами и даёт согласие на обработку его <strong>email, имени, фамилии и домена</strong>:</p>
            <ul>
                <li><a href="https://github.com/RuCoder-sudo/wp-ru-max/wiki/Политика-плагина" target="_blank" rel="noopener">Политика плагина</a></li>
                <li><a href="https://github.com/RuCoder-sudo/wp-ru-max/wiki/Возврат-и-отзыв-лицензии" target="_blank" rel="noopener">Возврат и отзыв лицензии</a></li>
                <li><a href="https://github.com/RuCoder-sudo/wp-ru-max/wiki/Пользовательское-соглашение" target="_blank" rel="noopener">Пользовательское соглашение</a></li>
                <li><a href="https://github.com/RuCoder-sudo/wp-ru-max/wiki/Политика-конфиденциальности" target="_blank" rel="noopener">Политика конфиденциальности</a></li>
            </ul>
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
        $retention_message = $settings['chat_widget_retention_message'] ?? 'Уже уходите? Получите скидку 10% на первый заказ!';
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
            <p>Добавьте на сайт плавающую кнопку MAX с анимацией приветственного сообщения.</p>
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
                            <input type="url" id="chat_widget_url" name="chat_widget_url" value="<?php echo esc_attr( $url ); ?>" class="large-text" placeholder="https://max.ru/YourBotName" />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="chat_widget_message">Приветственное сообщение</label></th>
                        <td>
                            <textarea id="chat_widget_message" name="chat_widget_message" rows="3" class="large-text"><?php echo esc_textarea( $message ); ?></textarea>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label>Расположение</label></th>
                        <td>
                            <label><input type="radio" name="chat_widget_position" value="right" <?php checked( $position, 'right' ); ?> /> Справа внизу</label>&nbsp;&nbsp;&nbsp;
                            <label><input type="radio" name="chat_widget_position" value="left" <?php checked( $position, 'left' ); ?> /> Слева внизу</label>
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
                            <script>(function(){var r=document.getElementById('chat_widget_bottom_offset_range');var n=document.getElementById('chat_widget_bottom_offset');if(r&&n){r.addEventListener('input',function(){n.value=r.value;});n.addEventListener('input',function(){r.value=n.value;});}})()</script>
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
                            <?php foreach ( array( 0=>'Сразу', 5=>'Через 5 сек', 8=>'Через 8 сек', 10=>'Через 10 сек', 15=>'Через 15 сек' ) as $val => $label ) : ?>
                            <label style="display:inline-flex;align-items:center;margin-right:16px;margin-bottom:6px;">
                                <input type="radio" name="chat_widget_show_delay" value="<?php echo esc_attr( $val ); ?>" <?php checked( $show_delay, $val ); ?> style="margin-right:5px;" />
                                <?php echo esc_html( $label ); ?>
                            </label>
                            <?php endforeach; ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label>Скрыть виджет через</label></th>
                        <td>
                            <?php foreach ( array( 0=>'Не скрывать', 10=>'10 сек', 20=>'20 сек', 30=>'30 сек', 60=>'1 мин', 120=>'2 мин' ) as $val => $label ) : ?>
                            <label style="display:inline-flex;align-items:center;margin-right:16px;margin-bottom:6px;">
                                <input type="radio" name="chat_widget_hide_delay" value="<?php echo esc_attr( $val ); ?>" <?php checked( $hide_delay, $val ); ?> style="margin-right:5px;" />
                                <?php echo esc_html( $label ); ?>
                            </label>
                            <?php endforeach; ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label>Повторно показать через</label></th>
                        <td>
                            <?php foreach ( array( 0=>'Не повторять', 30=>'30 сек', 60=>'1 мин', 120=>'2 мин', 300=>'5 мин', 600=>'10 мин' ) as $val => $label ) : ?>
                            <label style="display:inline-flex;align-items:center;margin-right:16px;margin-bottom:6px;">
                                <input type="radio" name="chat_widget_repeat_delay" value="<?php echo esc_attr( $val ); ?>" <?php checked( $repeat_delay, $val ); ?> style="margin-right:5px;" />
                                <?php echo esc_html( $label ); ?>
                            </label>
                            <?php endforeach; ?>
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
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label>Время проигрывания звука</label></th>
                        <td>
                            <?php foreach ( array( 3=>'Через 3 секунды', 6=>'Через 6 секунд', 9=>'Через 9 секунд' ) as $val => $label ) : ?>
                            <label style="display:block;margin-bottom:8px;cursor:pointer;">
                                <input type="radio" name="chat_widget_sound_delay" value="<?php echo esc_attr( $val ); ?>" <?php checked( $sound_delay, $val ); ?> style="margin-right:6px;" />
                                <?php echo esc_html( $label ); ?> после появления
                            </label>
                            <?php endforeach; ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label>Где проигрывать звук</label></th>
                        <td>
                            <div style="display:flex;flex-direction:column;gap:8px;">
                                <label style="cursor:pointer;"><input type="radio" name="chat_widget_sound_pages" value="all" <?php checked( $sound_pages, 'all' ); ?> /> На всех страницах сайта</label>
                                <label style="cursor:pointer;"><input type="radio" name="chat_widget_sound_pages" value="home" <?php checked( $sound_pages, 'home' ); ?> /> Только на главной странице</label>
                                <label style="cursor:pointer;"><input type="radio" name="chat_widget_sound_pages" value="specific" <?php checked( $sound_pages, 'specific' ); ?> /> Только на выбранных страницах</label>
                            </div>
                            <div id="chat_widget_sound_specific_wrap" style="margin-top:10px;<?php echo $sound_pages === 'specific' ? '' : 'display:none;'; ?>">
                                <textarea id="chat_widget_sound_specific_pages" name="chat_widget_sound_specific_pages" rows="4" class="large-text" placeholder="/contacts&#10;/about"><?php echo esc_textarea( $sound_specific_pages ); ?></textarea>
                            </div>
                            <script>(function(){var radios=document.getElementsByName('chat_widget_sound_pages');var wrap=document.getElementById('chat_widget_sound_specific_wrap');for(var i=0;i<radios.length;i++){radios[i].addEventListener('change',function(){wrap.style.display=(this.value==='specific'&&this.checked)?'':'none';});}})();</script>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label>Один раз за сессию</label></th>
                        <td>
                            <label class="wp-ru-max-switch" style="vertical-align:middle;">
                                <input type="checkbox" id="chat_widget_sound_once_per_session" <?php checked( $sound_once_per_session ); ?> />
                                <span class="wp-ru-max-switch-slider"></span>
                            </label>
                            <span style="margin-left:10px;">Проигрывать звук только один раз за визит</span>
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
                                $anim_options = array( 'none'=>'Без анимации', 'pulse'=>'Пульсация', 'ripple'=>'Рябь', 'bounce'=>'Подпрыгивание', 'shake'=>'Покачивание', 'glow'=>'Свечение', 'rotate'=>'Вращение', 'float'=>'Левитация' );
                                foreach ( $anim_options as $val => $label ) :
                                ?>
                                <label style="display:flex;align-items:center;gap:6px;cursor:pointer;background:<?php echo $animation === $val ? '#e8f0fe' : '#f8f9fa'; ?>;border:2px solid <?php echo $animation === $val ? '#4a90d9' : '#ddd'; ?>;border-radius:8px;padding:8px 14px;">
                                    <input type="radio" name="chat_widget_animation" value="<?php echo esc_attr( $val ); ?>" <?php checked( $animation, $val ); ?> />
                                    <?php echo esc_html( $label ); ?>
                                </label>
                                <?php endforeach; ?>
                            </div>
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
                            <span style="margin-left:10px;">Включить попап удержания при закрытии приветственного сообщения</span>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="chat_widget_retention_title">Заголовок окна</label></th>
                        <td><textarea id="chat_widget_retention_title" name="chat_widget_retention_title" rows="2" class="large-text"><?php echo esc_textarea( $retention_title ); ?></textarea></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="chat_widget_retention_message">Сообщение удержания</label></th>
                        <td><textarea id="chat_widget_retention_message" name="chat_widget_retention_message" rows="4" class="large-text"><?php echo esc_textarea( $retention_message ); ?></textarea></td>
                    </tr>
                    <tr>
                        <th scope="row"><label>Выравнивание текста</label></th>
                        <td>
                            <?php foreach ( array('left'=>'По левому краю','center'=>'По центру','right'=>'По правому краю') as $val=>$lbl ) : ?>
                            <label style="margin-right:14px;cursor:pointer;"><input type="radio" name="chat_widget_retention_text_align" value="<?php echo esc_attr($val); ?>" <?php checked($retention_text_align,$val); ?> /> <?php echo esc_html($lbl); ?></label>
                            <?php endforeach; ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="chat_widget_retention_stay_text">Текст кнопки «Остаться»</label></th>
                        <td><input type="text" id="chat_widget_retention_stay_text" name="chat_widget_retention_stay_text" value="<?php echo esc_attr($retention_stay_text); ?>" class="regular-text" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="chat_widget_retention_leave_text">Текст кнопки «Уйти»</label></th>
                        <td><input type="text" id="chat_widget_retention_leave_text" name="chat_widget_retention_leave_text" value="<?php echo esc_attr($retention_leave_text); ?>" class="regular-text" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label>Выравнивание кнопок</label></th>
                        <td>
                            <?php foreach ( array('left'=>'По левому краю','center'=>'По центру','right'=>'По правому краю') as $val=>$lbl ) : ?>
                            <label style="margin-right:14px;cursor:pointer;"><input type="radio" name="chat_widget_retention_buttons_align" value="<?php echo esc_attr($val); ?>" <?php checked($retention_buttons_align,$val); ?> /> <?php echo esc_html($lbl); ?></label>
                            <?php endforeach; ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="chat_widget_retention_btn_radius">Закругление кнопок (px)</label></th>
                        <td>
                            <input type="range" id="chat_widget_retention_btn_radius_range" min="0" max="50" value="<?php echo esc_attr($retention_btn_radius); ?>" style="width:200px;vertical-align:middle;" />
                            <input type="number" id="chat_widget_retention_btn_radius" name="chat_widget_retention_btn_radius" value="<?php echo esc_attr($retention_btn_radius); ?>" min="0" max="50" style="width:70px;" />
                            <script>(function(){var r=document.getElementById('chat_widget_retention_btn_radius_range');var n=document.getElementById('chat_widget_retention_btn_radius');if(r&&n){r.addEventListener('input',function(){n.value=r.value;});n.addEventListener('input',function(){r.value=n.value;});}})()</script>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label>Цвета кнопки «Остаться»</label></th>
                        <td>
                            <label style="margin-right:14px;">Фон: <input type="color" name="chat_widget_retention_stay_bg" value="<?php echo esc_attr($retention_stay_bg); ?>" /></label>
                            <label>Текст: <input type="color" name="chat_widget_retention_stay_color" value="<?php echo esc_attr($retention_stay_color); ?>" /></label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label>Цвета кнопки «Уйти»</label></th>
                        <td>
                            <label style="margin-right:14px;">Фон: <input type="color" name="chat_widget_retention_leave_bg" value="<?php echo esc_attr($retention_leave_bg); ?>" /></label>
                            <label>Текст: <input type="color" name="chat_widget_retention_leave_color" value="<?php echo esc_attr($retention_leave_color); ?>" /></label>
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

        <script>var wpRuMaxHistoryType = '<?php echo esc_js( $filter_type ); ?>';</script>
        <?php
    }

    private function render_tab_activation() {
        $existing = WP_Ru_Max_License::get_data();
        if ( ! empty( $existing['key'] ) ) {
            WP_Ru_Max_License::force_recheck();
        }

        $is_licensed  = WP_Ru_Max_License::is_active();
        $license      = WP_Ru_Max_License::get_data();
        $license_obj  = WP_Ru_Max_License::instance();
        $attempts     = $license_obj->get_remaining_attempts();
        $is_suspended = ! $is_licensed && ! empty( $license['status'] ) && $license['status'] === 'suspended';
        ?>
        <div class="wp-ru-max-card">
            <?php if ( $is_licensed ) : ?>
                <div class="wp-ru-max-activation-success">
                    <span class="wp-ru-max-success-icon">&#10003;</span>
                    <h2>Плагин активирован</h2>
                    <p>Все функции WP Ru-max доступны без ограничений.</p>
                    <table class="form-table" style="max-width:500px;">
                        <tr><th>Домен:</th><td><code><?php echo esc_html( $license['domain'] ?? '—' ); ?></code></td></tr>
                        <tr><th>Дата активации:</th><td><?php echo esc_html( $license['activated_at'] ?? '—' ); ?></td></tr>
                        <tr><th>Тип лицензии:</th><td>Пожизненная</td></tr>
                        <tr><th>Последняя проверка:</th><td><?php echo esc_html( $license['last_verified'] ?? '—' ); ?></td></tr>
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
                    Ключ <code><?php echo esc_html( $license['key'] ?? '' ); ?></code> был аннулирован. Введите новый лицензионный ключ.
                </div>
                <?php endif; ?>
                <p>Для использования всех функций WP Ru-max введите лицензионный ключ.</p>

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
                                <input type="text" id="license_key" name="license_key" class="regular-text"
                                    placeholder="WPRM-XXXX-XXXX-XXXX-XXXX" autocomplete="off"
                                    style="text-transform:uppercase;letter-spacing:1px;font-family:monospace;" />
                                <p class="description">Осталось попыток: <strong><?php echo (int) $attempts; ?></strong> из <?php echo WP_Ru_Max_License::MAX_ATTEMPTS; ?>.</p>
                            </td>
                        </tr>
                    </table>
                    <div class="wp-ru-max-actions">
                        <button type="button" class="button button-primary" id="activate_license_btn">Активировать</button>
                    </div>
                    <div id="license_activate_result" class="wp-ru-max-notice" style="display:none;margin-top:12px;"></div>
                    <?php endif; ?>
                    <div style="margin-top:20px;padding:14px 18px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;">
                        <h4 style="margin:0 0 8px;font-size:13px;color:#374151;">Согласие пользователя</h4>
                        <p style="margin:0 0 8px;font-size:13px;color:#374151;">Используя плагин WP Ru-max и активируя лицензию, пользователь подтверждает, что ознакомлен с указанными ниже страницами и даёт согласие на обработку его <strong>email, имени, фамилии и домена</strong>:</p>
                        <ul style="margin:0 0 0 18px;font-size:13px;list-style:disc;">
                            <li><a href="https://github.com/RuCoder-sudo/wp-ru-max/wiki/Политика-плагина" target="_blank" rel="noopener">Политика плагина</a></li>
                            <li><a href="https://github.com/RuCoder-sudo/wp-ru-max/wiki/Возврат-и-отзыв-лицензии" target="_blank" rel="noopener">Возврат и отзыв лицензии</a></li>
                            <li><a href="https://github.com/RuCoder-sudo/wp-ru-max/wiki/Пользовательское-соглашение" target="_blank" rel="noopener">Пользовательское соглашение</a></li>
                            <li><a href="https://github.com/RuCoder-sudo/wp-ru-max/wiki/Политика-конфиденциальности" target="_blank" rel="noopener">Политика конфиденциальности</a></li>
                        </ul>
                    </div>
                </div>

                <hr style="margin:32px 0;" />

                <div class="wp-ru-max-activation-block">
                    <h3>Запросить лицензионный ключ</h3>
                    <p>Нет ключа? Заполните форму — владелец плагина рассмотрит запрос и пришлёт ключ на ваш email.</p>
                    <table class="form-table">
                        <tr>
                            <th scope="row"><label for="req_name">Ваше имя *</label></th>
                            <td><input type="text" id="req_name" name="req_name" class="regular-text" placeholder="Иван Иванов" /></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="req_email">Ваш email *</label></th>
                            <td>
                                <input type="email" id="req_email" name="req_email" class="regular-text" placeholder="your@email.ru" />
                                <p class="description">На этот адрес придёт лицензионный ключ.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="req_site">Ссылка на ваш сайт *</label></th>
                            <td>
                                <input type="url" id="req_site" name="req_site" class="regular-text" placeholder="https://example.ru" />
                                <p class="description">Вставьте ссылку на ваш сайт для получения лицензионного ключа.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="req_social">Контакт для быстрой связи</label></th>
                            <td><input type="text" id="req_social" name="req_social" class="regular-text" placeholder="Telegram @username, MAX, WhatsApp +7..." /></td>
                        </tr>
                        <tr>
                            <th scope="row">Согласия *</th>
                            <td>
                                <label class="wp-ru-max-checkbox-label" style="display:flex;align-items:flex-start;gap:8px;background:#fff8e1;border:1px solid #f0c040;border-radius:6px;padding:10px 14px;margin-bottom:10px;">
                                    <input type="checkbox" id="bot_info_confirmed" name="bot_info_confirmed" value="1" checked style="margin-top:3px;flex-shrink:0;" />
                                    <span>Ознакомлен и проинформирован, что создать бота в мессенджере MAX и получить токен бота могут только <strong>ИП или ООО</strong>. Физические лица не могут получить токен бота.</span>
                                </label>
                                <label class="wp-ru-max-checkbox-label">
                                    <input type="checkbox" id="consent_personal" name="consent_personal" value="1" />
                                    Даю своё согласие на <a href="https://рукодер.рф/privacy-policy/" target="_blank" rel="noopener">обработку персональных данных</a>.
                                </label>
                                <br /><br />
                                <label class="wp-ru-max-checkbox-label">
                                    <input type="checkbox" id="consent_mailing" name="consent_mailing" value="1" />
                                    Согласен(а) получать информационные рассылки.
                                </label>
                            </td>
                        </tr>
                    </table>
                    <div class="wp-ru-max-actions">
                        <button type="button" class="button button-primary" id="request_license_btn" disabled>Отправить запрос</button>
                    </div>
                    <div id="license_request_result" class="wp-ru-max-notice" style="display:none;margin-top:12px;"></div>
                </div>

                <hr style="margin:32px 0;" />

                <div class="wp-ru-max-activation-block">
                    <h3>Важная информация о лицензии</h3>
                    <p>Стоимость лицензии WP Ru-max — <strong>2&nbsp;200&nbsp;₽</strong> за один домен. Лицензия <strong>бессрочная</strong>.</p>
                    <ul style="margin-left:18px;list-style:disc;">
                        <li>Привязка к одному домену сайта</li>
                        <li>Бессрочное использование без продления</li>
                        <li>Все обновления плагина включены</li>
                    </ul>
                </div>

                <hr style="margin:32px 0;" />

                <div class="wp-ru-max-activation-block">
                    <h3>Система скидок на лицензии</h3>
                    <table class="widefat striped" style="max-width:520px;">
                        <thead><tr><th>Количество доменов</th><th>Цена</th><th>Цена за домен</th><th>Экономия</th></tr></thead>
                        <tbody>
                            <tr><td>1 домен</td><td><strong>2&nbsp;200&nbsp;₽</strong></td><td>2&nbsp;200&nbsp;₽/шт</td><td>—</td></tr>
                            <tr><td>2 домена</td><td><strong>4&nbsp;000&nbsp;₽</strong></td><td>2&nbsp;000&nbsp;₽/шт</td><td>~9%</td></tr>
                            <tr><td>5 доменов</td><td><strong>7&nbsp;000&nbsp;₽</strong></td><td>1&nbsp;400&nbsp;₽/шт</td><td><strong>36%</strong></td></tr>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <script>
        (function($){
            $('#recheck_license_btn').on('click', function(){
                var $btn = $(this).prop('disabled', true).text('Проверяем...');
                $.post(wpRuMax.ajaxUrl, { action: 'wp_ru_max_recheck_license', nonce: wpRuMax.nonce }, function(resp){
                    if (resp.success) {
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

            $('#activate_license_btn').on('click', function(){
                var key = $('#license_key').val().trim().toUpperCase();
                if (!key) { showResult('#license_activate_result', false, 'Введите лицензионный ключ.'); return; }
                var $btn = $(this).prop('disabled', true).text('Проверяем...');
                $.post(wpRuMax.ajaxUrl, { action: 'wp_ru_max_activate_license', nonce: wpRuMax.nonce, license_key: key }, function(resp){
                    if (resp.success) {
                        showResult('#license_activate_result', true, resp.data.message);
                        setTimeout(function(){ location.reload(); }, 1500);
                    } else {
                        showResult('#license_activate_result', false, resp.data || 'Ошибка активации.');
                        $btn.prop('disabled', false).text('Активировать');
                    }
                }).fail(function(){
                    showResult('#license_activate_result', false, 'Ошибка сети.');
                    $btn.prop('disabled', false).text('Активировать');
                });
            });

            function updateRequestBtn(){
                var ok = $('#consent_personal').is(':checked') &&
                         $('#consent_mailing').is(':checked') &&
                         $('#bot_info_confirmed').is(':checked');
                $('#request_license_btn').prop('disabled', !ok);
            }
            $('#consent_personal, #consent_mailing, #bot_info_confirmed').on('change', updateRequestBtn);
            updateRequestBtn();

            $('#request_license_btn').on('click', function(){
                var name  = $('#req_name').val().trim();
                var email = $('#req_email').val().trim();
                var site  = $('#req_site').val().trim();
                if (!name)  { showResult('#license_request_result', false, 'Укажите ваше имя.'); return; }
                if (!email || !/\S+@\S+\.\S+/.test(email)) { showResult('#license_request_result', false, 'Укажите корректный email.'); return; }
                if (!site)  { showResult('#license_request_result', false, 'Укажите ссылку на ваш сайт.'); return; }
                if (!$('#bot_info_confirmed').is(':checked')) { showResult('#license_request_result', false, 'Подтвердите информацию о боте MAX.'); return; }
                var $btn = $(this).prop('disabled', true).text('Отправляем...');
                $.post(wpRuMax.ajaxUrl, {
                    action: 'wp_ru_max_request_license', nonce: wpRuMax.nonce,
                    req_name: name, req_email: email, req_site: site,
                    req_social: $('#req_social').val().trim(),
                    consent: $('#consent_personal').is(':checked') ? 1 : 0,
                    mailing: $('#consent_mailing').is(':checked') ? 1 : 0,
                    bot_info_confirmed: $('#bot_info_confirmed').is(':checked') ? 1 : 0
                }, function(resp){
                    if (resp.success) {
                        showResult('#license_request_result', true, resp.data);
                        $('#req_name, #req_email, #req_site, #req_social').val('');
                        $('#consent_personal, #consent_mailing').prop('checked', false);
                        $btn.prop('disabled', true).text('Отправить запрос');
                    } else {
                        showResult('#license_request_result', false, resp.data || 'Ошибка отправки.');
                        $btn.prop('disabled', false).text('Отправить запрос');
                    }
                }).fail(function(){
                    showResult('#license_request_result', false, 'Ошибка сети.');
                    $btn.prop('disabled', false).text('Отправить запрос');
                });
            });

            function showResult(selector, success, msg){
                $(selector).removeClass('notice-success notice-error').addClass(success ? 'notice-success' : 'notice-error')
                    .css({ display:'block', padding:'12px 16px', background:'#fff', borderLeft: success ? '4px solid #00a32a' : '4px solid #d63638', marginTop:'12px' }).html(msg);
            }
        })(jQuery);
        </script>
        <?php
    }
}
