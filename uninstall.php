<?php

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

// Загружаем основной класс для использования метода uninstall()
require_once plugin_dir_path( __FILE__ ) . 'includes/class-wp-ru-max.php';

WP_Ru_Max::uninstall();
