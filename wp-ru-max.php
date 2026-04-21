<?php
/**
 * Plugin Name:       WP Ru-max
 * Plugin URI:        https://рукодер.рф/wp-ru-max/
 * Description:       Интеграция WordPress с мессенджером MAX (max.ru) — автопубликация записей, пересылка уведомлений WooCommerce / CF7 / Elementor и настраиваемый чат-виджет с анимацией и звуком.
 * Version:           1.0.20
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
 *
 * Installation:
 * 1. Загрузите папку `wp-ru-max` в директорию `/wp-content/plugins/`
 * 2. Активируйте плагин через меню «Плагины» в WordPress
 * 3. Перейдите в «Ru-max → Активация»
 * 4. Введите лицензионный ключ или запросите его на вкладке «Активация»
 * 5. После активации настройте токен бота MAX на вкладке «Главная»
 * 6. Проверьте подключение кнопкой «Проверить подключение»
 *
 * FAQ:
 * Q: Где взять токен бота MAX?
 * A: На платформе MAX для партнёров: https://max.ru/partner → «Чат-боты» → «Интеграция» → «Получить токен».
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
 * -----------------------------------------------------------------------
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'WP_RU_MAX_VERSION', '1.0.20' );
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
