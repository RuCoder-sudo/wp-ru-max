<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WP_Ru_Max {

    private static $instance = null;

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->init_hooks();
    }

    private function init_hooks() {
        add_action( 'init', array( $this, 'init' ) );
        add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );

        // Multisite: создаём таблицу при добавлении нового подсайта
        if ( function_exists( 'is_multisite' ) && is_multisite() ) {
            // WordPress 5.1+
            add_action( 'wp_initialize_site', array( $this, 'on_new_site' ), 10, 1 );
            // Совместимость с более старыми версиями
            add_action( 'wpmu_new_blog', array( $this, 'on_new_blog_legacy' ), 10, 6 );
        }
    }

    public function init() {
        WP_Ru_Max_License::instance();
        WP_Ru_Max_License::recheck_if_needed();
        WP_Ru_Max_Admin::instance();
        WP_Ru_Max_Post_Sender::instance();
        WP_Ru_Max_Notifications::instance();
        WP_Ru_Max_Chat_Widget::instance();
        WP_Ru_Max_Logger::instance();
        WP_Ru_Max_Share::instance();
        WP_Ru_Max_OAuth::instance();
    }

    public function load_textdomain() {
        load_plugin_textdomain( 'wp-ru-max', false, dirname( WP_RU_MAX_PLUGIN_BASENAME ) . '/languages' );
    }

    // ─── Multisite: новый подсайт (WP 5.1+) ─────────────────────────────────

    /**
     * Создаёт таблицу истории при добавлении нового подсайта (WP 5.1+).
     */
    public function on_new_site( $site ) {
        if ( is_plugin_active_for_network( WP_RU_MAX_PLUGIN_BASENAME ) ) {
            $blog_id = is_object( $site ) ? (int) $site->blog_id : (int) $site;
            switch_to_blog( $blog_id );
            self::create_table();
            self::maybe_add_default_options();
            restore_current_blog();
        }
    }

    /**
     * Совместимость с WP < 5.1 — хук wpmu_new_blog.
     */
    public function on_new_blog_legacy( $blog_id, $user_id, $domain, $path, $site_id, $meta ) {
        if ( is_plugin_active_for_network( WP_RU_MAX_PLUGIN_BASENAME ) ) {
            switch_to_blog( $blog_id );
            self::create_table();
            self::maybe_add_default_options();
            restore_current_blog();
        }
    }

    // ─── Создание таблицы ────────────────────────────────────────────────────

    /**
     * Создаёт таблицу ru_max_history для текущего блога.
     * Безопасно вызывать повторно (IF NOT EXISTS).
     */
    public static function create_table() {
        global $wpdb;
        $table_name      = $wpdb->prefix . 'ru_max_history';
        $charset_collate = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            event_time datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            event_type varchar(50) NOT NULL,
            event_data longtext NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'info',
            details longtext,
            PRIMARY KEY (id)
        ) $charset_collate;";
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    /**
     * Добавляет настройки по умолчанию для текущего блога, если они ещё не заданы.
     */
    public static function maybe_add_default_options() {
        if ( false === get_option( 'wp_ru_max_settings' ) ) {
            add_option( 'wp_ru_max_settings', array(
                'bot_token'              => '',
                'bot_name'               => '',
                'post_sender_enabled'    => false,
                'channels'               => array(),
                'send_new_post'          => true,
                'send_updated_post'      => false,
                'show_read_more'         => true,
                'post_types'             => array( 'post' ),
                'filter_categories'      => array(),
                'filter_tags'            => array(),
                'send_delay_seconds'     => 0,
                'retry_count'            => 2,
                'retry_delay_seconds'    => 5,
                'image_size_limit_mb'    => 5,
                'notifications_enabled'  => false,
                'notify_from_email'      => 'any',
                'notify_chat_ids'        => array(),
                'notify_template'        => "<b>{email_subject}</b>\n{email_message}",
                'notify_format'          => 'html',
                'send_files_by_url'      => true,
                'multisite_enabled'      => false,
                'enable_bot_api_log'     => false,
                'enable_post_sender_log' => false,
                'delete_on_uninstall'    => false,
                'chat_widget_enabled'    => false,
                'chat_widget_size'       => 'medium',
                'chat_widget_url'        => '',
                'chat_widget_message'    => 'Здравствуйте! У вас есть вопросы!? Мы всегда на связи. Кликните, чтобы нам написать!',
                'chat_widget_position'   => 'right',
                'chat_widget_retention_enabled' => false,
                'chat_widget_retention_title'   => 'Специальное предложение!',
                'chat_widget_retention_message' => 'Уже уходите? Получите скидку 10% на первый заказ, если ответим на ваш вопрос в течение 5 минут!',
                'chat_widget_retention_text_align'    => 'left',
                'chat_widget_retention_buttons_align' => 'right',
                'chat_widget_retention_btn_radius'    => 8,
                'chat_widget_retention_stay_text'     => 'Остаться',
                'chat_widget_retention_leave_text'    => 'Все равно уйти',
                'chat_widget_retention_stay_bg'       => '#4a90d9',
                'chat_widget_retention_stay_color'    => '#ffffff',
                'chat_widget_retention_leave_bg'      => '#f0f0f0',
                'chat_widget_retention_leave_color'   => '#555555',
            ) );
        }
    }

    // ─── Активация / деактивация ─────────────────────────────────────────────

    /**
     * Вызывается при активации плагина.
     * Поддерживает как обычную активацию, так и сетевую (Multisite).
     *
     * @param bool $network_wide true если активирован для всей сети.
     */
    public static function activate( $network_wide = false ) {
        if ( $network_wide && function_exists( 'is_multisite' ) && is_multisite() ) {
            // Активация для всей сети — создаём таблицы на каждом подсайте
            $sites = get_sites( array( 'number' => 0 ) );
            foreach ( $sites as $site ) {
                switch_to_blog( (int) $site->blog_id );
                self::create_table();
                self::maybe_add_default_options();
                restore_current_blog();
            }
        } else {
            // Обычная активация — только текущий сайт
            self::create_table();
            self::maybe_add_default_options();
        }
    }

    public static function deactivate() {
        // Ничего не удаляем при деактивации — данные сохраняются
    }

    /**
     * Полное удаление данных плагина (вызывается из uninstall.php).
     * Поддерживает Multisite.
     */
    public static function uninstall() {
        global $wpdb;

        if ( function_exists( 'is_multisite' ) && is_multisite() ) {
            $sites = get_sites( array( 'number' => 0 ) );
            foreach ( $sites as $site ) {
                switch_to_blog( (int) $site->blog_id );
                $settings = get_option( 'wp_ru_max_settings', array() );
                if ( ! empty( $settings['delete_on_uninstall'] ) ) {
                    $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}ru_max_history" );
                    delete_option( 'wp_ru_max_settings' );
                    delete_option( 'wp_ru_max_license' );
                    delete_option( 'wp_ru_max_license_attempts' );
                    delete_option( 'wp_ru_max_skip_meta_migrated_v1' );
                }
                restore_current_blog();
            }
            // Удаляем сетевые настройки
            $network_settings = get_site_option( 'wp_ru_max_network_settings', array() );
            if ( ! empty( $network_settings['delete_on_uninstall'] ) ) {
                delete_site_option( 'wp_ru_max_network_settings' );
                delete_site_option( 'wp_ru_max_network_license' );
            }
        } else {
            $settings = get_option( 'wp_ru_max_settings', array() );
            if ( ! empty( $settings['delete_on_uninstall'] ) ) {
                $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}ru_max_history" );
                delete_option( 'wp_ru_max_settings' );
                delete_option( 'wp_ru_max_license' );
                delete_option( 'wp_ru_max_license_attempts' );
                delete_option( 'wp_ru_max_skip_meta_migrated_v1' );
            }
        }
    }
}
