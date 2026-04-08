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
    }

    public function init() {
        WP_Ru_Max_License::instance();
        WP_Ru_Max_License::recheck_if_needed();
        WP_Ru_Max_Admin::instance();
        WP_Ru_Max_Post_Sender::instance();
        WP_Ru_Max_Notifications::instance();
        WP_Ru_Max_Chat_Widget::instance();
        WP_Ru_Max_Logger::instance();
    }

    public function load_textdomain() {
        load_plugin_textdomain( 'wp-ru-max', false, dirname( WP_RU_MAX_PLUGIN_BASENAME ) . '/languages' );
    }

    public static function activate() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ru_max_history';
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

        if ( false === get_option( 'wp_ru_max_settings' ) ) {
            add_option( 'wp_ru_max_settings', array(
                'bot_token'              => '',
                'bot_name'              => '',
                'post_sender_enabled'    => false,
                'channels'               => array(),
                'send_new_post'          => true,
                'send_updated_post'      => false,
                'show_read_more'         => true,
                'post_types'             => array( 'post' ),
                'notifications_enabled'  => false,
                'notify_from_email'      => 'any',
                'notify_chat_ids'        => array(),
                'notify_template'        => "<b>{email_subject}</b>\n{email_message}",
                'notify_format'          => 'html',
                'send_files_by_url'      => true,
                'enable_bot_api_log'     => false,
                'enable_post_sender_log' => false,
                'delete_on_uninstall'    => false,
                'chat_widget_enabled'    => false,
                'chat_widget_size'       => 'medium',
                'chat_widget_url'        => '',
                'chat_widget_message'    => 'Здравствуйте! У вас есть вопросы!? Мы всегда на связи. Кликните, чтобы нам написать!',
                'chat_widget_position'   => 'right',
            ) );
        }
    }

    public static function deactivate() {
    }

    public static function uninstall() {
        $settings = get_option( 'wp_ru_max_settings', array() );
        if ( ! empty( $settings['delete_on_uninstall'] ) ) {
            global $wpdb;
            $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}ru_max_history" );
            delete_option( 'wp_ru_max_settings' );
        }
    }
}
