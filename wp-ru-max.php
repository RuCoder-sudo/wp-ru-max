<?php
/**
 * Plugin Name:       WP Ru-max
 * Plugin URI:        https://fixcoder.ru/wp-ru-max/
 * Description:       Интеграция WordPress с мессенджером MAX (max.ru) — автопубликация записей, пересылка уведомлений WooCommerce / CF7 / Jetpack / Elementor и настраиваемый чат-виджет с анимацией и звуком. Поддерживает WordPress Multisite (мультисайт) и поддомены.
 * Version:           1.0.51
 * Author:            Сергей Солошенко (RuCoder)
 * Author URI:        https://fixcoder.ru/
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wp-ru-max
 * Domain Path:       /languages
 * Requires at least: 5.8
 * Tested up to:      6.7
 * Requires PHP:      7.4
 * Network:           true
 *
 * -----------------------------------------------------------------------
 * Разработчик:        Сергей Солошенко | РуКодер
 * Специализация:      Веб-разработка с 2018 года | WordPress / Full Stack
 * Принцип работы:     «Сайт — как для себя»
 * -----------------------------------------------------------------------
 * Телефон / WhatsApp: +7 (985) 985-53-97
 * Email:              support@fixcoder.ru
 * Telegram:           @RussCoder
 * Портфолио:          https://fixcoder.ru
 * GitHub:             https://github.com/RuCoder-sudo
 * -----------------------------------------------------------------------
 *
 * Installation:
 * 1. Загрузите папку `wp-ru-max` в директорию `/wp-content/plugins/`
 * 2. Активируйте плагин через меню «Плагины» в WordPress
 *    (или «Сеть → Плагины» для сетевой активации на всех сайтах)
 * 3. Перейдите в «Ru-max → Активация»
 * 4. Введите лицензионный ключ или запросите его на вкладке «Активация»
 * 5. После активации настройте токен бота MAX на вкладке «Главная»
 * 6. Проверьте подключение кнопкой «Проверить подключение»
 *
 * Multisite / Поддомены:
 * - Плагин поддерживает WordPress Multisite (сеть сайтов)
 * - Может быть активирован сетевым администратором для всей сети
 * - Каждый подсайт имеет свои независимые настройки
 * - Сетевая лицензия автоматически распространяется на все подсайты сети
 * - Для поддоменов (sub.domain.ru) достаточно лицензии на корневой домен
 *
 * FAQ:
 * Q: Где взять токен бота MAX?
 * A: На платформе MAX для партнёров: https://business.max.ru → «Чат-боты» → «Интеграция» → «Получить токен».
 *
 * Q: Как узнать ID канала или группы?
 * A: Для публичного канала — никнейм с @ (например, @my_channel).
 *    Для группы — числовой ID (получить через бота @get_id_bot в мессенджере MAX).
 *
 * Q: Работает ли плагин с WooCommerce?
 * A: Да. Плагин перехватывает все email-уведомления WooCommerce (новый заказ,
 *    смена статуса и т.д.) и пересылает их в личный чат с ботом MAX.
 *
 * Q: Поддерживаются ли Contact Form 7, Elementor Forms, Gravity Forms?
 * A: Да. Плагин работает с любыми формами, отправляющими уведомления через wp_mail().
 *
 * Q: Что делает чат-виджет?
 * A: Добавляет плавающую кнопку MAX на сайт с приветственным сообщением,
 *    анимацией, звуковым уведомлением и настраиваемой задержкой появления.
 *
 * Q: Как работает лицензия в Multisite?
 * A: Сетевой администратор может ввести одну лицензию в сетевых настройках
 *    (Сеть → Плагины → WP Ru-max), и она автоматически распространится
 *    на все подсайты сети. Либо каждый подсайт может иметь свою лицензию.
 * -----------------------------------------------------------------------
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'WP_RU_MAX_VERSION', '1.0.51' );
define( 'WP_RU_MAX_PLUGIN_FILE', __FILE__ );
define( 'WP_RU_MAX_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WP_RU_MAX_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'WP_RU_MAX_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
define( 'WP_RU_MAX_API_BASE', 'https://platform-api2.max.ru' );

// PRO is bundled into the main plugin. The option names stay compatible with
// the standalone add-on so existing settings and conversations are preserved.
define( 'WP_RU_MAX_PRO_BUNDLED', true );
// Version 1.0.51: the bundled module uses the same cache-busting version as
// the main plugin, so all updated CSS/JS files are refreshed together.
if ( ! defined( 'WP_RU_MAX_PRO_VERSION' ) ) define( 'WP_RU_MAX_PRO_VERSION', '1.0.51' );
if ( ! defined( 'WP_RU_MAX_PRO_FILE' ) ) define( 'WP_RU_MAX_PRO_FILE', __FILE__ );
if ( ! defined( 'WP_RU_MAX_PRO_DIR' ) ) define( 'WP_RU_MAX_PRO_DIR', WP_RU_MAX_PLUGIN_DIR );
if ( ! defined( 'WP_RU_MAX_PRO_URL' ) ) define( 'WP_RU_MAX_PRO_URL', WP_RU_MAX_PLUGIN_URL . 'assets/pro/' );
if ( ! defined( 'WP_RU_MAX_PRO_OPTION' ) ) define( 'WP_RU_MAX_PRO_OPTION', 'wp_ru_max_pro_settings' );
if ( ! defined( 'WP_RU_MAX_PRO_LICENSE_OPTION' ) ) define( 'WP_RU_MAX_PRO_LICENSE_OPTION', 'wp_ru_max_pro_license' );

require_once WP_RU_MAX_PLUGIN_DIR . 'includes/class-wp-ru-max.php';
require_once WP_RU_MAX_PLUGIN_DIR . 'includes/class-wp-ru-max-api.php';
require_once WP_RU_MAX_PLUGIN_DIR . 'includes/class-wp-ru-max-post-sender.php';
require_once WP_RU_MAX_PLUGIN_DIR . 'includes/class-wp-ru-max-notifications.php';
require_once WP_RU_MAX_PLUGIN_DIR . 'includes/class-wp-ru-max-chat-widget.php';
require_once WP_RU_MAX_PLUGIN_DIR . 'includes/class-wp-ru-max-logger.php';
require_once WP_RU_MAX_PLUGIN_DIR . 'includes/class-wp-ru-max-license.php';
require_once WP_RU_MAX_PLUGIN_DIR . 'includes/class-wp-ru-max-admin.php';
require_once WP_RU_MAX_PLUGIN_DIR . 'includes/class-wp-ru-max-updater.php';
require_once WP_RU_MAX_PLUGIN_DIR . 'includes/class-wp-ru-max-share.php';
require_once WP_RU_MAX_PLUGIN_DIR . 'includes/class-wp-ru-max-oauth.php';
require_once WP_RU_MAX_PLUGIN_DIR . 'includes/class-wp-ru-max-telegram-api.php';
require_once WP_RU_MAX_PLUGIN_DIR . 'includes/class-wp-ru-max-social-poster.php';
// If the old add-on was loaded first, let it own the compatible PRO classes
// for this request. If the main plugin was loaded first, the add-on exits via
// its guard and these bundled classes are used.
if ( ! function_exists( 'wp_ru_max_pro_settings' ) ) {
    require_once WP_RU_MAX_PLUGIN_DIR . 'includes/class-wp-ru-max-pro.php';
    require_once WP_RU_MAX_PLUGIN_DIR . 'includes/class-wp-ru-max-pro-license.php';
    require_once WP_RU_MAX_PLUGIN_DIR . 'includes/class-wp-ru-max-pro-admin.php';
    require_once WP_RU_MAX_PLUGIN_DIR . 'includes/class-wp-ru-max-pro-widget.php';
}

function wp_ru_max() {
    return WP_Ru_Max::instance();
}

/**
 * VK ID requires a trusted redirect URL without an action query parameter.
 * Keep the callback on a normal WordPress path and let VK append only its
 * response parameters (code, state and device_id).
 */
function wp_ru_max_register_vk_callback_route() {
    add_rewrite_rule(
        '^wp-ru-max-vk-callback/?$',
        'index.php?wp_ru_max_vk_callback=1',
        'top'
    );
}
add_action( 'init', 'wp_ru_max_register_vk_callback_route', 1 );

function wp_ru_max_register_vk_callback_query_var( $vars ) {
    $vars[] = 'wp_ru_max_vk_callback';
    return $vars;
}
add_filter( 'query_vars', 'wp_ru_max_register_vk_callback_query_var' );

function wp_ru_max_handle_vk_callback_route() {
    $is_callback = '1' === (string) get_query_var( 'wp_ru_max_vk_callback' );
    if ( ! $is_callback && ! empty( $_SERVER['REQUEST_URI'] ) ) {
        $request_path = (string) wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH );
        $callback_path = (string) wp_parse_url( home_url( '/wp-ru-max-vk-callback/' ), PHP_URL_PATH );
        $is_callback = untrailingslashit( $request_path ) === untrailingslashit( $callback_path );
    }
    if ( ! $is_callback ) {
        return;
    }
    WP_Ru_Max_Admin::instance()->vk_oauth_callback();
}
add_action( 'template_redirect', 'wp_ru_max_handle_vk_callback_route', 1 );

wp_ru_max();

// Инициализируем апдейтер только в контексте wp-admin, иначе он добавляет
// фильтры и обращается к transient-кешу на каждом фронтенд-запросе.
if ( is_admin() ) {
    new WP_Ru_Max_Updater( WP_RU_MAX_PLUGIN_FILE, WP_RU_MAX_VERSION );
}

// Хуки активации/деактивации
register_activation_hook( __FILE__, array( 'WP_Ru_Max', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'WP_Ru_Max', 'deactivate' ) );

// Загружаем сетевой класс для Multisite
if ( function_exists( 'is_multisite' ) && is_multisite() ) {
    require_once WP_RU_MAX_PLUGIN_DIR . 'includes/class-wp-ru-max-network-admin.php';
    WP_Ru_Max_Network_Admin::instance();
}

// Existing installations need one rewrite flush after upgrading to the
// clean VK callback route. The flag keeps this from running on every request.
add_action( 'admin_init', function() {
    if ( '1' === (string) get_option( 'wp_ru_max_vk_callback_rewrite_v1' ) ) {
        return;
    }
    flush_rewrite_rules( false );
    update_option( 'wp_ru_max_vk_callback_rewrite_v1', '1' );
} );
