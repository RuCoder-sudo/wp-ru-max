<?php
/**
 * Сетевой административный интерфейс для WordPress Multisite.
 *
 * Добавляет страницу настроек в сетевую панель администратора:
 * Сеть → Ru-max (Сеть)
 *
 * Функции:
 * - Активация/деактивация сетевой лицензии (покрывает все подсайты)
 * - Настройка сетевых параметров по умолчанию
 * - Просмотр статуса подсайтов
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WP_Ru_Max_Network_Admin {

    private static $instance = null;

    const NETWORK_SETTINGS_KEY = 'wp_ru_max_network_settings';

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'network_admin_menu', array( $this, 'add_network_menu' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
    }

    public function add_network_menu() {
        add_menu_page(
            'WP Ru-max (Сеть)',
            'Ru-max (Сеть)',
            'manage_network_options',
            'wp-ru-max-network',
            array( $this, 'render_network_page' ),
            WP_RU_MAX_PLUGIN_URL . 'assets/max-32x32.png',
            30
        );
    }

    public function enqueue_scripts( $hook ) {
        if ( strpos( $hook, 'wp-ru-max-network' ) === false ) {
            return;
        }
        wp_enqueue_style(
            'wp-ru-max-admin',
            WP_RU_MAX_PLUGIN_URL . 'assets/css/admin.css',
            array(),
            WP_RU_MAX_VERSION
        );
        wp_enqueue_script( 'jquery' );
        wp_add_inline_script( 'jquery', $this->get_network_admin_js(), 'after' );
    }

    private function get_network_admin_js() {
        $nonce = wp_create_nonce( 'wp_ru_max_network_nonce' );
        $ajax  = esc_url( admin_url( 'admin-ajax.php' ) );
        return <<<JS
(function($){
    var ajaxUrl = '{$ajax}';
    var nonce   = '{$nonce}';

    function showResult(selector, success, msg){
        var el = $(selector);
        el.removeClass('notice-success notice-error')
          .addClass(success ? 'notice-success' : 'notice-error')
          .css({ display:'block', padding:'12px 16px', background:'#fff',
                 borderLeft: success ? '4px solid #00a32a' : '4px solid #d63638', marginTop:'12px' })
          .html(msg);
    }

    // Активация сетевой лицензии
    $('#activate_network_license_btn').on('click', function(){
        var key = $('#network_license_key').val().trim().toUpperCase();
        if (!key) { showResult('#network_license_result', false, 'Введите лицензионный ключ.'); return; }
        var \$btn = $(this).prop('disabled', true).text('Проверяем...');
        $.post(ajaxUrl, {
            action: 'wp_ru_max_activate_network_license',
            nonce: nonce,
            license_key: key
        }, function(resp){
            if (resp.success) {
                showResult('#network_license_result', true, resp.data.message);
                setTimeout(function(){ location.reload(); }, 1500);
            } else {
                showResult('#network_license_result', false, resp.data || 'Ошибка активации.');
                \$btn.prop('disabled', false).text('Активировать для всей сети');
            }
        }).fail(function(){
            showResult('#network_license_result', false, 'Ошибка сети.');
            \$btn.prop('disabled', false).text('Активировать для всей сети');
        });
    });

    // Деактивация сетевой лицензии
    $('#deactivate_network_license_btn').on('click', function(){
        if (!confirm('Сбросить сетевую лицензию? Каждый подсайт должен будет активировать плагин отдельно.')) return;
        var \$btn = $(this).prop('disabled', true).text('Сброс...');
        $.post(ajaxUrl, {
            action: 'wp_ru_max_deactivate_network_license',
            nonce: nonce
        }, function(resp){
            if (resp.success) {
                showResult('#network_license_result', true, 'Лицензия сброшена.');
                setTimeout(function(){ location.reload(); }, 1500);
            } else {
                showResult('#network_license_result', false, resp.data || 'Ошибка.');
                \$btn.prop('disabled', false).text('Сбросить сетевую лицензию');
            }
        });
    });

    // Перепроверка сетевой лицензии
    $('#recheck_network_license_btn').on('click', function(){
        var \$btn = $(this).prop('disabled', true).text('Проверяем...');
        $.post(ajaxUrl, {
            action: 'wp_ru_max_recheck_network_license',
            nonce: nonce
        }, function(resp){
            if (resp.success) {
                showResult('#network_license_result', true, resp.data.message || 'Лицензия действительна.');
            } else {
                showResult('#network_license_result', false, resp.data || 'Лицензия недействительна.');
            }
            \$btn.prop('disabled', false).text('Проверить сейчас');
            setTimeout(function(){ location.reload(); }, 1200);
        });
    });
})(jQuery);
JS;
    }

    public function render_network_page() {
        $is_network_licensed = WP_Ru_Max_License::is_network_active();
        $network_license     = WP_Ru_Max_License::get_network_data();
        $network_domain      = WP_Ru_Max_License::get_network_domain();
        $sites               = get_sites( array( 'number' => 50 ) );
        ?>
        <div class="wrap">
            <h1>
                <img src="<?php echo esc_url( WP_RU_MAX_PLUGIN_URL . 'assets/max-32x32.png' ); ?>" width="24" height="24" style="vertical-align:middle;margin-right:8px;" />
                WP Ru-max — Сетевые настройки
            </h1>
            <p style="color:#666;">Управление плагином для всей сети WordPress Multisite.</p>

            <?php /* ── Сетевая лицензия ── */ ?>
            <div class="wp-ru-max-card" style="background:#fff;border:1px solid #e0e0e0;border-radius:8px;padding:20px 24px;margin-top:20px;max-width:800px;">
                <h2 style="margin-top:0;">
                    <?php if ( $is_network_licensed ) : ?>
                        <span style="color:#00a32a;">&#10003;</span> Сетевая лицензия активирована
                    <?php else : ?>
                        <span style="color:#d63638;">&#9888;</span> Сетевая лицензия не активирована
                    <?php endif; ?>
                </h2>

                <?php if ( $is_network_licensed ) : ?>
                    <table class="form-table" style="max-width:500px;">
                        <tr><th>Домен сети:</th><td><code><?php echo esc_html( $network_license['domain'] ?? '—' ); ?></code></td></tr>
                        <tr><th>Дата активации:</th><td><?php echo esc_html( $network_license['activated_at'] ?? '—' ); ?></td></tr>
                        <tr><th>Последняя проверка:</th><td><?php echo esc_html( $network_license['last_verified'] ?? '—' ); ?></td></tr>
                        <tr><th>Покрывает:</th><td>Все подсайты этой сети</td></tr>
                    </table>
                    <p>
                        <button type="button" class="button" id="recheck_network_license_btn">Проверить сейчас</button>
                        &nbsp;
                        <button type="button" class="button button-secondary" id="deactivate_network_license_btn" style="color:#d63638;">Сбросить сетевую лицензию</button>
                    </p>
                <?php else : ?>
                    <p>
                        Сетевая лицензия позволяет активировать WP Ru-max <strong>для всех подсайтов сети</strong> с помощью одного ключа.
                        Это удобно, если у вас много подсайтов или субдоменов.
                    </p>
                    <p style="background:#fff8e1;border-left:4px solid #ffb300;padding:10px 14px;border-radius:0 6px 6px 0;">
                        <strong>Альтернатива:</strong> каждый подсайт может иметь собственную лицензию.
                        Перейдите в нужный подсайт → Ru-max → Активация.
                    </p>
                    <table class="form-table" style="max-width:500px;">
                        <tr>
                            <th scope="row"><label for="network_license_key">Ключ для сети</label></th>
                            <td>
                                <input type="text" id="network_license_key" class="regular-text"
                                    placeholder="WPRM-XXXX-XXXX-XXXX-XXXX"
                                    style="text-transform:uppercase;letter-spacing:1px;font-family:monospace;" />
                                <p class="description">Домен сети: <code><?php echo esc_html( $network_domain ); ?></code></p>
                            </td>
                        </tr>
                    </table>
                    <p>
                        <button type="button" class="button button-primary" id="activate_network_license_btn">
                            Активировать для всей сети
                        </button>
                    </p>
                <?php endif; ?>
                <div id="network_license_result" style="display:none;"></div>
            </div>

            <?php /* ── Состояние подсайтов ── */ ?>
            <div class="wp-ru-max-card" style="background:#fff;border:1px solid #e0e0e0;border-radius:8px;padding:20px 24px;margin-top:20px;max-width:800px;">
                <h2 style="margin-top:0;">Подсайты сети</h2>
                <table class="widefat striped" style="margin-top:12px;">
                    <thead>
                        <tr>
                            <th>Подсайт</th>
                            <th>Домен</th>
                            <th>Лицензия</th>
                            <th>Настройки</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $sites as $site ) :
                            switch_to_blog( $site->blog_id );
                            $site_licensed = WP_Ru_Max_License::is_active();
                            $per_site_data = WP_Ru_Max_License::get_data();
                            $site_settings = get_option( 'wp_ru_max_settings', array() );
                            $bot_token     = ! empty( $site_settings['bot_token'] ) ? '✓' : '—';
                            $admin_url     = get_admin_url( null, 'admin.php?page=wp-ru-max' );
                            restore_current_blog();
                            $site_name = get_blog_option( $site->blog_id, 'blogname' );
                            $site_url  = get_blog_option( $site->blog_id, 'siteurl' );
                            $site_host = parse_url( $site_url, PHP_URL_HOST );
                        ?>
                        <tr>
                            <td><a href="<?php echo esc_url( $admin_url ); ?>"><?php echo esc_html( $site_name ?: 'Подсайт #' . $site->blog_id ); ?></a></td>
                            <td><code><?php echo esc_html( $site_host ); ?></code></td>
                            <td>
                                <?php if ( $is_network_licensed ) : ?>
                                    <span style="color:#00a32a;">&#10003; Через сеть</span>
                                <?php elseif ( $site_licensed ) : ?>
                                    <span style="color:#00a32a;">&#10003; Своя лицензия</span>
                                <?php else : ?>
                                    <span style="color:#d63638;">&#10007; Не активирован</span>
                                <?php endif; ?>
                            </td>
                            <td>Токен: <?php echo esc_html( $bot_token ); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php if ( count( $sites ) >= 50 ) : ?>
                    <p class="description">Показаны первые 50 подсайтов.</p>
                <?php endif; ?>
            </div>

            <?php /* ── Информация о мультисайте ── */ ?>
            <div class="wp-ru-max-card" style="background:#f0f6fc;border:1px solid #c8d8ea;border-radius:8px;padding:20px 24px;margin-top:20px;max-width:800px;">
                <h3 style="margin-top:0;">Как работает лицензирование в Multisite</h3>
                <ul style="line-height:1.8;margin-left:18px;list-style:disc;">
                    <li><strong>Сетевая лицензия</strong> — активируется здесь, автоматически покрывает все подсайты сети.</li>
                    <li><strong>Лицензия подсайта</strong> — каждый подсайт активирует плагин самостоятельно через Ru-max → Активация.</li>
                    <li><strong>Поддомены</strong> — лицензия на <code>example.com</code> автоматически покрывает <code>shop.example.com</code>, <code>blog.example.com</code> и другие поддомены.</li>
                    <li>Настройки (токен бота, каналы, уведомления) <strong>независимы</strong> для каждого подсайта.</li>
                </ul>
            </div>
        </div>
        <?php
    }
}
