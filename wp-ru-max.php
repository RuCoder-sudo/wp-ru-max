<?php
/**
 * Plugin Name:       WP Ru-max
 * Plugin URI:        https://рукодер.рф/wp-ru-max/
 * Description:       Интеграция WordPress с мессенджером MAX (max.ru) — автопубликация записей, пересылка уведомлений WooCommerce / CF7 / Elementor и настраиваемый чат-виджет с анимацией и звуком.
 * Version:           1.0.17
 * Author:            Сергей Солошенко (RuCoder)
 * Author URI:        https://рукодер.рф/
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wp-ru-max
 * Domain Path:       /languages
 * Requires at least: 5.8
 * Tested up to:      6.7
 * Requires PHP:      7.4
 *
 * -----------------------------------------------------------------------
 * Разработчик:        Сергей Солошенко | РуКодер
 * Специализация:      Веб-разработка с 2018 года | WordPress / Full Stack
 * Принцип работы:     «Сайт — как для себя»
 * -----------------------------------------------------------------------
 * Телефон / WhatsApp: +7 (985) 985-53-97
 * Email:              support@рукодер.рф
 * Telegram:           @RussCoder
 * Портфолио:          https://рукодер.рф
 * GitHub:             https://github.com/RuCoder-sudo
 * -----------------------------------------------------------------------
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'WP_RU_MAX_VERSION', '1.0.17' );
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
require_once WP_RU_MAX_PLUGIN_DIR . 'includes/class-wp-ru-max-updater.php';

function wp_ru_max() {
    return WP_Ru_Max::instance();
}

wp_ru_max();

new WP_Ru_Max_Updater( WP_RU_MAX_PLUGIN_FILE, WP_RU_MAX_VERSION );

register_activation_hook( __FILE__, array( 'WP_Ru_Max', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'WP_Ru_Max', 'deactivate' ) );
