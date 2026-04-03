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
        add_action( 'init',                              array( $this, 'register_post_meta' ) );
        add_action( 'wp_ajax_wp_ru_max_save_settings',   array( $this, 'ajax_save_settings' ) );
        add_action( 'wp_ajax_wp_ru_max_test_connection', array( $this, 'ajax_test_connection' ) );
        add_action( 'wp_ajax_wp_ru_max_send_test_message', array( $this, 'ajax_send_test_message' ) );
        add_action( 'wp_ajax_wp_ru_max_get_logs',        array( $this, 'ajax_get_logs' ) );
        add_action( 'wp_ajax_wp_ru_max_clear_logs',      array( $this, 'ajax_clear_logs' ) );
        add_action( 'wp_ajax_wp_ru_max_send_post_now',   array( $this, 'ajax_send_post_now' ) );
        add_filter( 'plugin_action_links_' . WP_RU_MAX_PLUGIN_BASENAME, array( $this, 'add_plugin_links' ) );
    }

    public function register_post_meta() {
        $post_types = get_post_types( array( 'public' => true ) );
        foreach ( $post_types as $post_type ) {
            register_post_meta( $post_type, '_wp_ru_max_skip', array(
                'show_in_rest'  => true,
                'single'        => true,
                'type'          => 'string',
                'auth_callback' => function() { return current_user_can( 'edit_posts' ); },
            ) );
        }
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

        wp_enqueue_script(
            'wp-ru-max-gutenberg',
            WP_RU_MAX_PLUGIN_URL . 'assets/gutenberg-panel.js',
            array( 'jquery', 'wp-plugins', 'wp-edit-post', 'wp-element', 'wp-components', 'wp-data' ),
            WP_RU_MAX_VERSION,
            true
        );
        wp_localize_script( 'wp-ru-max-gutenberg', 'wpRuMaxGutenberg', array(
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'wp_ru_max_nonce' ),
            'iconUrl' => WP_RU_MAX_PLUGIN_URL . 'assets/max-32x32.png',
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
                    $settings[ $field ] = sanitize_text_field( $value );
                    break;
                case 'excerpt_max_chars':
                    $settings[ $field ] = max( 0, intval( $value ) );
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
            $allowed_text = array( 'bot_token', 'bot_name', 'notify_from_email', 'notify_format', 'chat_widget_size', 'chat_widget_url', 'chat_widget_message', 'chat_widget_position' );
            $allowed_textarea = array( 'notify_template' );
            $allowed_bool = array( 'post_sender_enabled', 'send_new_post', 'send_updated_post', 'show_read_more', 'show_action_label', 'show_author_date', 'send_post_image', 'notifications_enabled', 'send_files_by_url', 'enable_bot_api_log', 'enable_post_sender_log', 'delete_on_uninstall', 'chat_widget_enabled' );
            $allowed_int  = array( 'excerpt_max_chars' );
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
            foreach ( $allowed_array as $key ) {
                if ( isset( $_POST[ $key ] ) && is_array( $_POST[ $key ] ) ) {
                    $settings[ $key ] = array_map( 'sanitize_text_field', wp_unslash( $_POST[ $key ] ) );
                } elseif ( isset( $_POST[ $key ] ) ) {
                    $val = sanitize_textarea_field( wp_unslash( $_POST[ $key ] ) );
                    $settings[ $key ] = array_map( 'trim', array_filter( explode( "\n", $val ) ) );
                }
            }
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
        $enabled = ! empty( $settings['notifications_enabled'] );
        $chat_ids = isset( $settings['notify_chat_ids'] ) ? (array) $settings['notify_chat_ids'] : array( '' );
        ?>
        <div class="wp-ru-max-card">
            <h2>Личные уведомления</h2>
            <p>Модуль будет следить за уведомлениями по электронной почте, отправленными с этого сайта, и доставлять их в чат/группу WP Ru-max.</p>

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
                                <?php foreach ( $chat_ids as $cid ) : ?>
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
            </ul>
        </div>
        <?php
    }

    private function render_tab_chat( $settings ) {
        $enabled  = ! empty( $settings['chat_widget_enabled'] );
        $size     = $settings['chat_widget_size'] ?? 'medium';
        $url      = $settings['chat_widget_url'] ?? '';
        $message  = $settings['chat_widget_message'] ?? 'Здравствуйте! Мы всегда на связи. Кликните, чтобы нам написать!';
        $position = $settings['chat_widget_position'] ?? 'right';
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
        $is_licensed = WP_Ru_Max_License::is_active();
        $license     = WP_Ru_Max_License::get_data();
        $license_obj = WP_Ru_Max_License::instance();
        $attempts    = $license_obj->get_remaining_attempts();
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
                    </table>
                </div>

            <?php else : ?>

                <h2>Активация плагина</h2>
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
                    consent:   $('#consent_personal').is(':checked') ? 1 : 0,
                    mailing:   $('#consent_mailing').is(':checked')  ? 1 : 0
                }, function(resp){
                    if ( resp.success ) {
                        showResult('#license_request_result', true, resp.data);
                        $('#req_name, #req_email').val('');
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
