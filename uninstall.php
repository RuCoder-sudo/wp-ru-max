<?php

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

// Загружаем классы из папки текущей установки. Это также позволяет удалить
// старую неполную установку без fatal error из-за отсутствующего автопостинга.
$wp_ru_max_includes_dir = __DIR__ . '/includes/';
if ( is_readable( $wp_ru_max_includes_dir . 'class-wp-ru-max-auto-posting.php' ) ) {
    require_once $wp_ru_max_includes_dir . 'class-wp-ru-max-auto-posting.php';
}
require_once $wp_ru_max_includes_dir . 'class-wp-ru-max.php';

WP_Ru_Max::uninstall();

// The former extension stored these separately from the main settings.
// Remove them only when the user explicitly enabled deletion on uninstall.
$settings = get_option( 'wp_ru_max_settings', array() );
if ( ! empty( $settings['delete_on_uninstall'] ) ) {
    delete_option( 'wp_ru_max_pro_settings' );
    delete_option( 'wp_ru_max_pro_pending_messages' );
    delete_option( 'wp_ru_max_pro_license' );
}
