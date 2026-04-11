<?php
/**
 * Plugin Name: WP RU-MAX
 * Plugin URI: https://рукодер.рф/
 * Description: Интегрируйте свой сайт WordPress с Max - Российский мессенджер с полным контролем.
 * Version: 1.0.14
 * Author: Сергей Солошенко (RuCoder)
 * Author URI: https://рукодер.рф/
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wp-ru-max
 * Domain Path: /languages
 * Requires at least: 5.8
 * Tested up to: 6.6
 * Requires PHP: 7.4
 * Stable tag: 1.0.14
 * Tags: max, messenger, chat, online-chat, russian-messenger, widget, woocommerce
 *
 * Compatible with: Wordpress, WooCommerce, Mail, Elementor, Gutenberg, Contact Form 7, Ninja Forms, Formidable Forms, Gravity Forms
 * Max Account Required: Yes
 * Translation Ready: Yes
 * Locale: ru_RU
 *
 * Support URI: https://рукодер.рф/
 * Разработчик: Сергей Солошенко | РуКодер
 * Специализация: Веб-разработка с 2018 года | WordPress / Full Stack
 * Принцип работы: "Сайт как для себя"
 * Контакты:
 * - Телефон/WhatsApp: +7 (985) 985-53-97
 * - Email: support@рукодер.рф
 * - Telegram: @RussCoder
 * - Портфолио: https://рукодер.рф
 * - GitHub: https://github.com/RuCoder-sudo
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'WP_RU_MAX_VERSION', '1.0.14' );
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
