<?php

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

$settings = get_option( 'wp_ru_max_settings', array() );

if ( ! empty( $settings['delete_on_uninstall'] ) ) {
    global $wpdb;
    $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}ru_max_history" );
    delete_option( 'wp_ru_max_settings' );
}
