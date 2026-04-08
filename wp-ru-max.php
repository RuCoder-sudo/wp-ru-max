<?php
/**
 * Plugin Name: WP Ru-max
 * Plugin URI: https://github.com/RuCoder-sudo/wp-ru-max
 * Description: Интегрируйте свой сайт WordPress с Max - российский мессенджер с полным контролем.
 * Version: 1.0.10
 * Author: RuCoder
 * Author URI: https://рукодер.рф/
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: рукодер.рф
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'WP_RU_MAX_VERSION', '1.0.10' );
define( 'WP_RU_MAX_PLUGIN_FILE', __FILE__ );
define( 'WP_RU_MAX_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WP_RU_MAX_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'WP_RU_MAX_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
define( 'WP_RU_MAX_API_BASE', 'https://platform-api.max.ru' );

require_once WP_RU_MAX_PLUGIN_DIR . 'includes/class-wp-ru-max.php';
require_once WP_RU_MAX_PLUGIN_DIR . 'includes/class-wp-ru-max-api.php';
require_once WP_RU_MAX_PLUGIN_DIR . 'includes/class-wp-ru-max-post-sender.php';
require_once WP_RU_MAX_PLUGIN_DIR . 'includes/class-wp-ru-max-notifications.php';
require_once WP_RU_MAX_PLUGIN_DIR . 'includes/class-wp-ru-max-chat-widget.php';
require_once WP_RU_MAX_PLUGIN_DIR . 'includes/class-wp-ru-max-logger.php';
require_once WP_RU_MAX_PLUGIN_DIR . 'includes/class-wp-ru-max-license.php';
require_once WP_RU_MAX_PLUGIN_DIR . 'includes/class-wp-ru-max-admin.php';

function wp_ru_max() {
    return WP_Ru_Max::instance();
}

wp_ru_max();

register_activation_hook( __FILE__, array( 'WP_Ru_Max', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'WP_Ru_Max', 'deactivate' ) );
