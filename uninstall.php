<?php

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

// Загружаем основной класс для использования метода uninstall()
require_once plugin_dir_path( __FILE__ ) . 'includes/class-wp-ru-max.php';

WP_Ru_Max::uninstall();

// The former extension stored these separately from the main settings.
// Remove them only when the user explicitly enabled deletion on uninstall.
$settings = get_option( 'wp_ru_max_settings', array() );
if ( ! empty( $settings['delete_on_uninstall'] ) ) {
    delete_option( 'wp_ru_max_pro_settings' );
    delete_option( 'wp_ru_max_pro_pending_messages' );
    delete_option( 'wp_ru_max_pro_license' );
}
