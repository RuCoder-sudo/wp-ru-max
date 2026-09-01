<?php
/**
 * Unified contacts, live chat and contact-form module.
 *
 * The option names intentionally remain compatible with the former
 * WP Ru-max PRO extension so an upgrade does not discard settings or
 * conversations. There is no second license or second plugin dependency.
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! function_exists( 'wp_ru_max_contacts_bool' ) ) {
    function wp_ru_max_contacts_bool( $value, $default = false ) {
        if ( is_bool( $value ) ) {
            return $value;
        }
        if ( is_numeric( $value ) ) {
            return (bool) $value;
        }
        if ( is_string( $value ) ) {
            $value = strtolower( trim( $value ) );
            if ( in_array( $value, array( '1', 'true', 'yes', 'on' ), true ) ) {
                return true;
            }
            if ( in_array( $value, array( '', '0', 'false', 'no', 'off', 'null' ), true ) ) {
                return false;
            }
        }
        return (bool) $default;
    }
}

function wp_ru_max_contacts_settings() {
    $defaults = array(
        'enabled' => true,
        'channels' => array(
            'phone' => array( 'enabled' => false, 'value' => '', 'icon' => 'phone', 'desktop' => true, 'mobile' => true ),
            'telegram' => array( 'enabled' => false, 'value' => '', 'icon' => 'telegram', 'desktop' => true, 'mobile' => true ),
            'vkontakte' => array( 'enabled' => false, 'value' => '', 'icon' => 'vkontakte', 'desktop' => true, 'mobile' => true ),
            'contact' => array( 'enabled' => false, 'value' => '', 'icon' => 'contact', 'desktop' => true, 'mobile' => true ),
            'email' => array( 'enabled' => false, 'value' => '', 'icon' => 'email', 'desktop' => true, 'mobile' => true ),
        ),
        'custom_channels' => array(),
        'channel_order' => array( 'phone', 'telegram', 'vkontakte', 'contact', 'email' ),
        'style' => array(
            'mode' => 'chat', 'layout' => 'circle', 'icon' => 'chat',
            'icon_background' => '#4f46e5', 'icon_color' => '#ffffff',
            'position' => 'right', 'size' => 60, 'cta' => 'Написать нам',
            'cta_behavior' => 'hover', 'cta_text_color' => '#ffffff',
            'cta_background' => '#4f46e5', 'page_title' => '',
            'backdrop_blur' => false, 'attention' => 'pulse',
        ),
        'chat' => array(
            'target' => '', 'title' => 'Живой чат',
            'welcome' => 'Здравствуйте! Чем можем помочь?',
            'manager_online' => false, 'bot_enabled' => true,
            'schedule_enabled' => false, 'schedule_days' => array( 1, 2, 3, 4, 5 ),
            'schedule_start' => '09:00', 'schedule_end' => '18:00',
            'bot_name' => 'Помощник',
            'bot_offline_message' => 'Менеджер сейчас не в сети. Я уже передал ваше сообщение команде и постараюсь помочь прямо сейчас.',
            'faq_enabled' => true,
            'faq' => array(
                array( 'question' => 'Как быстро вы отвечаете?', 'answer' => 'Обычно менеджер отвечает в течение 15 минут в рабочее время.' ),
                array( 'question' => 'Можно ли обсудить заказ?', 'answer' => 'Да, напишите нам детали — мы подключим нужного специалиста.' ),
            ),
            'contact_form_enabled' => true,
            'quick_buttons' => array(
                array( 'label' => 'Позвать менеджера', 'message' => 'Хочу поговорить с менеджером' ),
                array( 'label' => 'Узнать стоимость', 'message' => 'Подскажите, пожалуйста, стоимость' ),
            ),
        ),
    );
    $saved = get_option( 'wp_ru_max_pro_settings', array() );
    $saved = is_array( $saved ) ? $saved : array();
    $settings = wp_parse_args( $saved, $defaults );
    foreach ( array( 'channels', 'style', 'chat' ) as $group ) {
        $settings[ $group ] = wp_parse_args( is_array( $saved[ $group ] ?? null ) ? $saved[ $group ] : array(), $defaults[ $group ] );
    }
    $settings['enabled'] = wp_ru_max_contacts_bool( $settings['enabled'], true );
    foreach ( $defaults['channels'] as $key => $fallback ) {
        $settings['channels'][ $key ] = wp_parse_args( is_array( $settings['channels'][ $key ] ?? null ) ? $settings['channels'][ $key ] : array(), $fallback );
        foreach ( array( 'enabled', 'desktop', 'mobile' ) as $bool_key ) {
            $settings['channels'][ $key ][ $bool_key ] = wp_ru_max_contacts_bool( $settings['channels'][ $key ][ $bool_key ], $fallback[ $bool_key ] );
        }
    }
    foreach ( array( 'backdrop_blur' ) as $bool_key ) {
        $settings['style'][ $bool_key ] = wp_ru_max_contacts_bool( $settings['style'][ $bool_key ], false );
    }
    foreach ( array( 'manager_online', 'bot_enabled', 'schedule_enabled', 'faq_enabled', 'contact_form_enabled' ) as $bool_key ) {
        $settings['chat'][ $bool_key ] = wp_ru_max_contacts_bool( $settings['chat'][ $bool_key ], $defaults['chat'][ $bool_key ] );
    }
    $settings['chat']['faq'] = is_array( $settings['chat']['faq'] ?? null ) ? $settings['chat']['faq'] : array();
    $settings['chat']['quick_buttons'] = is_array( $settings['chat']['quick_buttons'] ?? null ) ? $settings['chat']['quick_buttons'] : array();
    $custom = array();
    foreach ( (array) ( $saved['custom_channels'] ?? array() ) as $item ) {
        if ( ! is_array( $item ) ) {
            continue;
        }
        $id = sanitize_key( $item['id'] ?? '' );
        $label = sanitize_text_field( $item['label'] ?? '' );
        $url = esc_url_raw( $item['url'] ?? '' );
        $icon_url = esc_url_raw( $item['icon_url'] ?? '' );
        if ( '' === $id || in_array( $id, array_keys( $defaults['channels'] ), true ) || '' === $label || '' === $url || '' === $icon_url ) {
            continue;
        }
        $custom[ $id ] = array(
            'id' => $id, 'label' => $label, 'url' => $url, 'icon_url' => $icon_url,
            'enabled' => wp_ru_max_contacts_bool( $item['enabled'] ?? false ),
            'desktop' => wp_ru_max_contacts_bool( $item['desktop'] ?? true, true ),
            'mobile' => wp_ru_max_contacts_bool( $item['mobile'] ?? true, true ),
        );
    }
    $settings['custom_channels'] = $custom;
    $order = is_array( $settings['channel_order'] ?? null ) ? array_map( 'sanitize_key', $settings['channel_order'] ) : $defaults['channel_order'];
    $allowed = array_merge( array_keys( $defaults['channels'] ), array_keys( $custom ) );
    $settings['channel_order'] = array_values( array_unique( array_merge(
        array_intersect( $order, $allowed ),
        array_diff( $defaults['channel_order'], $order ),
        array_diff( array_keys( $custom ), $order )
    ) ) );
    return $settings;
}

function wp_ru_max_contacts_is_configured() {
    return false !== get_option( 'wp_ru_max_pro_settings', false ) && ! empty( wp_ru_max_contacts_settings()['enabled'] );
}

function wp_ru_max_contacts_is_live_chat_available( $chat ) {
    if ( empty( $chat['schedule_enabled'] ) ) {
        return true;
    }
    $day = (int) current_time( 'w' );
    $days = array_map( 'absint', (array) ( $chat['schedule_days'] ?? array() ) );
    if ( ! in_array( $day, $days, true ) ) {
        return false;
    }
    $now = current_time( 'H:i' );
    $start = preg_match( '/^\d{2}:\d{2}$/', $chat['schedule_start'] ?? '' ) ? $chat['schedule_start'] : '09:00';
    $end = preg_match( '/^\d{2}:\d{2}$/', $chat['schedule_end'] ?? '' ) ? $chat['schedule_end'] : '18:00';
    return $start <= $end ? ( $now >= $start && $now <= $end ) : ( $now >= $start || $now <= $end );
}

function wp_ru_max_contacts_main_widget_settings() {
    $main = get_option( 'wp_ru_max_settings', array() );
    $sizes = array( 'small' => 32, 'medium' => 48, 'large' => 64 );
    return array(
        'enabled' => ! empty( $main['chat_widget_enabled'] ),
        'size' => $sizes[ $main['chat_widget_size'] ?? 'medium' ] ?? 48,
        'position' => 'left' === ( $main['chat_widget_position'] ?? 'right' ) ? 'left' : 'right',
        'bottom_offset' => max( 0, min( 300, absint( $main['chat_widget_bottom_offset'] ?? 20 ) ) ),
    );
}

class WP_Ru_Max_Contacts {
    private static $instance = null;
    public static function instance() {
        return self::$instance ?: ( self::$instance = new self() );
    }
    private function __construct() {
        add_filter( 'wp_ru_max_admin_tabs', array( $this, 'add_tab' ) );
        add_filter( 'wp_ru_max_admin_tab_keys', array( $this, 'add_key' ) );
        add_filter( 'wp_ru_max_admin_submenu_items', array( $this, 'add_submenu' ) );
        add_action( 'wp_ru_max_render_admin_tab_contacts', array( $this, 'render' ) );
        add_action( 'wp_ru_max_before_widget_close', array( $this, 'render_widget' ), 10, 1 );
        add_action( 'wp_ajax_wp_ru_max_contacts_save', array( $this, 'save' ) );
        add_action( 'wp_ajax_wp_ru_max_contacts_message', array( $this, 'message' ) );
        add_action( 'wp_ajax_nopriv_wp_ru_max_contacts_message', array( $this, 'message' ) );
        add_action( 'wp_ajax_wp_ru_max_contacts_history', array( $this, 'history' ) );
        add_action( 'wp_ajax_nopriv_wp_ru_max_contacts_history', array( $this, 'history' ) );
        add_action( 'wp_ajax_wp_ru_max_contacts_reply', array( $this, 'reply' ) );
        add_action( 'wp_ajax_wp_ru_max_contacts_close', array( $this, 'close' ) );
        add_action( 'wp_ajax_wp_ru_max_contacts_messages', array( $this, 'messages' ) );
    }
    public function add_tab( $tabs ) { $tabs['contacts'] = 'Связь с клиентами'; return $tabs; }
    public function add_key( $keys ) { $keys[] = 'contacts'; return $keys; }
    public function add_submenu( $items ) { $items[] = array( 'Связь с клиентами', 'manage_options', 'admin.php?page=wp-ru-max&tab=contacts' ); return $items; }
    private function text( $value ) { return sanitize_text_field( wp_unslash( $value ?? '' ) ); }
    private function allow_public_request( $action, $limit = 20 ) {
        $ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
        $key = 'wp_ru_max_contacts_public_' . md5( $action . '|' . $ip . '|' . wp_salt( 'auth' ) );
        $count = (int) get_transient( $key );
        if ( $count >= $limit ) return false;
        set_transient( $key, $count + 1, MINUTE_IN_SECONDS );
        return true;
    }
    private function icon( $key ) {
        $map = array( 'phone' => '☎', 'telegram' => '✈', 'vkontakte' => 'VK', 'contact' => '✉', 'email' => '@' );
        return $map[ $key ] ?? '↗';
    }
    private function channel_link( $item, $type ) {
        $value = trim( $item['value'] ?? '' );
        if ( '' === $value ) return '#';
        if ( 'email' === $type ) {
            $email = sanitize_email( $value );
            return '' !== $email ? 'mailto:' . $email : '#';
        }
        if ( 'phone' === $type ) {
            $phone = preg_replace( '/[^0-9+]/', '', $value );
            return '' !== $phone ? 'tel:' . $phone : '#';
        }
        if ( 'telegram' === $type && preg_match( '/^@?[A-Za-z0-9_]{3,}$/', $value ) ) {
            $value = 'https://t.me/' . ltrim( $value, '@' );
        } elseif ( 'vkontakte' === $type && preg_match( '/^@?[A-Za-z0-9_.-]{2,}$/', $value ) ) {
            $value = 'https://vk.com/' . ltrim( $value, '@' );
        } elseif ( ! preg_match( '#^[a-z][a-z0-9+.-]*://#i', $value ) && 0 !== strpos( $value, '//' ) ) {
            $value = 'https://' . ltrim( $value, '/' );
        }
        return esc_url( $value );
    }
    public function save() {
        check_ajax_referer( 'wp_ru_max_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Недостаточно прав.' );
        $raw = wp_unslash( $_POST['settings'] ?? array() );
        if ( ! is_array( $raw ) ) wp_send_json_error( 'Неверные настройки.' );
        $current = wp_ru_max_contacts_settings();
        $channels = array();
        foreach ( array_keys( $current['channels'] ) as $key ) {
            $item = is_array( $raw['channels'][ $key ] ?? null ) ? $raw['channels'][ $key ] : array();
            $channels[ $key ] = array(
                'enabled' => wp_ru_max_contacts_bool( $item['enabled'] ?? false ),
                'value' => $this->text( $item['value'] ?? '' ),
                'icon' => sanitize_key( $item['icon'] ?? $key ),
                'desktop' => wp_ru_max_contacts_bool( $item['desktop'] ?? true, true ),
                'mobile' => wp_ru_max_contacts_bool( $item['mobile'] ?? true, true ),
            );
        }
        $custom = array();
        foreach ( (array) ( $raw['custom_channels'] ?? array() ) as $item ) {
            if ( ! is_array( $item ) ) continue;
            $id = sanitize_key( $item['id'] ?? '' );
            $label = $this->text( $item['label'] ?? '' );
            $url = esc_url_raw( $item['url'] ?? '' );
            $icon_url = esc_url_raw( $item['icon_url'] ?? '' );
            if ( '' === $id || '' === $label || '' === $url || '' === $icon_url || isset( $channels[ $id ] ) ) continue;
            $custom[ $id ] = array(
                'id' => $id, 'label' => $label, 'url' => $url, 'icon_url' => $icon_url,
                'enabled' => wp_ru_max_contacts_bool( $item['enabled'] ?? false ),
                'desktop' => wp_ru_max_contacts_bool( $item['desktop'] ?? true, true ),
                'mobile' => wp_ru_max_contacts_bool( $item['mobile'] ?? true, true ),
            );
        }
        $style_raw = is_array( $raw['style'] ?? null ) ? $raw['style'] : array();
        $chat_raw = is_array( $raw['chat'] ?? null ) ? $raw['chat'] : array();
        $settings = array(
            'enabled' => true, 'channels' => $channels, 'custom_channels' => $custom,
            'channel_order' => array_values( array_unique( array_map( 'sanitize_key', (array) ( $raw['channel_order'] ?? array() ) ) ) ),
            'style' => array(
                'mode' => in_array( $style_raw['mode'] ?? '', array( 'simple', 'chat' ), true ) ? $style_raw['mode'] : 'simple',
                'layout' => in_array( $style_raw['layout'] ?? '', array( 'circle', 'list', 'grid', 'corner', 'menu' ), true ) ? $style_raw['layout'] : 'circle',
                'icon' => $this->text( $style_raw['icon'] ?? 'chat' ),
                'icon_background' => sanitize_hex_color( $style_raw['icon_background'] ?? '#4f46e5' ) ?: '#4f46e5',
                'icon_color' => sanitize_hex_color( $style_raw['icon_color'] ?? '#ffffff' ) ?: '#ffffff',
                'position' => 'left' === ( $style_raw['position'] ?? '' ) ? 'left' : 'right',
                'size' => max( 42, min( 96, absint( $style_raw['size'] ?? 60 ) ) ),
                'cta' => $this->text( $style_raw['cta'] ?? 'Написать нам' ),
                'cta_behavior' => 'always' === ( $style_raw['cta_behavior'] ?? '' ) ? 'always' : 'hover',
                'cta_text_color' => sanitize_hex_color( $style_raw['cta_text_color'] ?? '#ffffff' ) ?: '#ffffff',
                'cta_background' => sanitize_hex_color( $style_raw['cta_background'] ?? '#4f46e5' ) ?: '#4f46e5',
                'page_title' => $this->text( $style_raw['page_title'] ?? '' ),
                'backdrop_blur' => wp_ru_max_contacts_bool( $style_raw['backdrop_blur'] ?? false ),
                'attention' => in_array( $style_raw['attention'] ?? '', array( 'none', 'pulse', 'bounce' ), true ) ? $style_raw['attention'] : 'pulse',
            ),
            'chat' => array(
                'target' => $this->text( $chat_raw['target'] ?? '' ),
                'title' => $this->text( $chat_raw['title'] ?? 'Живой чат' ),
                'welcome' => sanitize_textarea_field( $chat_raw['welcome'] ?? '' ),
                'manager_online' => wp_ru_max_contacts_bool( $chat_raw['manager_online'] ?? false ),
                'bot_enabled' => wp_ru_max_contacts_bool( $chat_raw['bot_enabled'] ?? false ),
                'schedule_enabled' => wp_ru_max_contacts_bool( $chat_raw['schedule_enabled'] ?? false ),
                'schedule_days' => array_values( array_intersect( array_map( 'absint', (array) ( $chat_raw['schedule_days'] ?? array() ) ), range( 0, 6 ) ) ),
                'schedule_start' => preg_match( '/^\d{2}:\d{2}$/', $chat_raw['schedule_start'] ?? '' ) ? $chat_raw['schedule_start'] : '09:00',
                'schedule_end' => preg_match( '/^\d{2}:\d{2}$/', $chat_raw['schedule_end'] ?? '' ) ? $chat_raw['schedule_end'] : '18:00',
                'bot_name' => $this->text( $chat_raw['bot_name'] ?? 'Помощник' ),
                'bot_offline_message' => sanitize_textarea_field( $chat_raw['bot_offline_message'] ?? '' ),
                'faq_enabled' => wp_ru_max_contacts_bool( $chat_raw['faq_enabled'] ?? false ),
                'contact_form_enabled' => wp_ru_max_contacts_bool( $chat_raw['contact_form_enabled'] ?? false ),
                'faq' => array(), 'quick_buttons' => array(),
            ),
        );
        foreach ( (array) ( $chat_raw['faq'] ?? array() ) as $faq ) {
            if ( is_array( $faq ) && '' !== trim( $faq['question'] ?? '' ) && '' !== trim( $faq['answer'] ?? '' ) ) {
                $settings['chat']['faq'][] = array( 'question' => $this->text( $faq['question'] ), 'answer' => sanitize_textarea_field( $faq['answer'] ) );
            }
        }
        foreach ( (array) ( $chat_raw['quick_buttons'] ?? array() ) as $button ) {
            if ( is_array( $button ) && '' !== trim( $button['label'] ?? '' ) && '' !== trim( $button['message'] ?? '' ) ) {
                $settings['chat']['quick_buttons'][] = array( 'label' => $this->text( $button['label'] ), 'message' => $this->text( $button['message'] ) );
            }
        }
        update_option( 'wp_ru_max_pro_settings', $settings );
        wp_send_json_success( 'Настройки связи сохранены.' );
    }
    public function render() {
        $s = wp_ru_max_contacts_settings();
        $main = wp_ru_max_contacts_main_widget_settings();
        $c = $s['channels']; $st = $s['style']; $chat = $s['chat'];
        $labels = array( 'phone' => 'Телефон', 'telegram' => 'Telegram', 'vkontakte' => 'ВКонтакте', 'contact' => 'Форма обратной связи', 'email' => 'Email' );
        $open = array_filter( (array) get_option( 'wp_ru_max_pro_pending_messages', array() ), function( $item ) { return 'closed' !== ( $item['status'] ?? 'open' ); } );
        ?>
        <div class="wprmp-admin" dir="ltr">
            <div class="wprmp-heading"><div><span class="wprmp-kicker">WP RU-MAX</span><h1>Связь с клиентами</h1><p>Каналы, форма обратной связи и живой чат — теперь в едином плагине.</p></div><div class="wprmp-heading-actions"><span class="wprmp-live-status"><i></i> Модуль включён</span><button type="button" class="button button-primary" id="wprmp-save">Сохранить настройки</button></div></div>
            <nav class="wprmp-subtabs"><button type="button" class="wprmp-subtab is-active" data-wprmp-tab="settings">Настройки</button><button type="button" class="wprmp-subtab" data-wprmp-tab="messages">Заявки <b><?php echo count( $open ); ?></b></button></nav>
            <section class="wprmp-pane is-active" data-wprmp-pane="settings">
                <div class="wprmp-hero"><div><span class="wprmp-eyebrow">КОНТАКТЫ В ОДНОМ МЕСТЕ</span><h2>Сделайте общение частью сайта</h2><p>Основной значок MAX берёт размер, положение и отступ из вкладки «Чат-виджет MAX».</p></div></div>
                <h2>Каналы связи</h2>
                <div class="wprmp-channel-order">
                <?php foreach ( $s['channel_order'] as $key ) : $item = $c[ $key ] ?? ( $s['custom_channels'][ $key ] ?? null ); if ( ! is_array( $item ) ) continue; $custom = isset( $s['custom_channels'][ $key ] ); ?>
                    <div class="wprmp-channel wprmp-channel-card" data-channel-item="<?php echo esc_attr( $key ); ?>" data-custom-channel="<?php echo $custom ? '1' : '0'; ?>">
                        <div class="wprmp-channel-top"><label><span class="wprmp-drag-handle" draggable="true">⋮⋮</span><span class="wprmp-channel-symbol"><?php echo esc_html( $custom ? '↗' : $this->icon( $key ) ); ?></span><input type="checkbox" class="wprmp-enabled" <?php checked( ! empty( $item['enabled'] ) ); ?>><b class="wprmp-channel-name"><?php echo esc_html( $custom ? $item['label'] : $labels[ $key ] ); ?></b></label><span class="wprmp-channel-status"><?php echo $custom ? 'Ссылка' : 'Канал'; ?></span></div>
                        <?php if ( $custom ) : ?><div class="wprmp-custom-fields"><label>Название <input type="text" data-custom-field="label" value="<?php echo esc_attr( $item['label'] ); ?>"></label><label>Ссылка <input type="url" data-custom-field="url" value="<?php echo esc_attr( $item['url'] ); ?>"></label><label>URL иконки <input type="url" data-custom-field="icon_url" value="<?php echo esc_attr( $item['icon_url'] ); ?>"></label></div><button type="button" class="button wprmp-remove-custom">Удалить канал</button><?php else : ?><input type="text" data-value="<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( $item['value'] ?? '' ); ?>" placeholder="<?php echo esc_attr( 'phone' === $key ? '+7 900 000-00-00' : ( 'email' === $key ? 'email@example.com' : '@username или ссылка' ) ); ?>"><?php endif; ?>
                        <div class="wprmp-channel-devices"><label><input type="checkbox" data-device="desktop" <?php checked( ! empty( $item['desktop'] ) ); ?>> ПК</label><label><input type="checkbox" data-device="mobile" <?php checked( ! empty( $item['mobile'] ) ); ?>> Мобильные</label></div>
                    </div>
                <?php endforeach; ?>
                </div>
                <button type="button" class="button wprmp-add-custom-channel">+ Добавить произвольную ссылку</button>
                <h2>Вид меню и живой чат</h2>
                <div class="wprmp-fields"><label>Расположение значков <select data-style="layout"><option value="circle" <?php selected( $st['layout'], 'circle' ); ?>>Кружком</option><option value="list" <?php selected( $st['layout'], 'list' ); ?>>Списком</option><option value="grid" <?php selected( $st['layout'], 'grid' ); ?>>Сеткой</option><option value="menu" <?php selected( $st['layout'], 'menu' ); ?>>Меню</option></select></label><label>Фон иконок <input type="color" data-style="icon_background" value="<?php echo esc_attr( $st['icon_background'] ); ?>"></label><label>Цвет иконок <input type="color" data-style="icon_color" value="<?php echo esc_attr( $st['icon_color'] ); ?>"></label><label>CTA-текст <input type="text" data-style="cta" value="<?php echo esc_attr( $st['cta'] ); ?>"></label><label>Эффект внимания <select data-style="attention"><option value="pulse" <?php selected( $st['attention'], 'pulse' ); ?>>Пульсация</option><option value="bounce" <?php selected( $st['attention'], 'bounce' ); ?>>Прыжок</option><option value="none" <?php selected( $st['attention'], 'none' ); ?>>Нет</option></select></label><label><input type="checkbox" data-style="backdrop_blur" <?php checked( ! empty( $st['backdrop_blur'] ) ); ?>> Размытие фона</label></div>
                <div class="wprmp-chat-settings"><div class="wprmp-chat-settings-head"><div><h2>Живой чат</h2><p>Сообщения сохраняются в очереди и дублируются менеджеру в MAX.</p></div><label class="wprmp-switch"><input type="checkbox" data-chat="manager_online" <?php checked( ! empty( $chat['manager_online'] ) ); ?>><span></span><b>Менеджер онлайн</b></label></div><div class="wprmp-chat-fields"><label>MAX ID получателя <input type="text" data-chat="target" value="<?php echo esc_attr( $chat['target'] ); ?>"></label><label>Заголовок <input type="text" data-chat="title" value="<?php echo esc_attr( $chat['title'] ); ?>"></label><label>Приветствие <textarea data-chat="welcome"><?php echo esc_textarea( $chat['welcome'] ); ?></textarea></label><label><input type="checkbox" data-chat="bot_enabled" <?php checked( ! empty( $chat['bot_enabled'] ) ); ?>> Автоответ бота вне рабочего времени</label><label>Имя бота <input type="text" data-chat="bot_name" value="<?php echo esc_attr( $chat['bot_name'] ); ?>"></label><label>Ответ бота <textarea data-chat="bot_offline_message"><?php echo esc_textarea( $chat['bot_offline_message'] ); ?></textarea></label><label><input type="checkbox" data-chat="faq_enabled" <?php checked( ! empty( $chat['faq_enabled'] ) ); ?>> Показывать частые вопросы</label><label><input type="checkbox" data-chat="contact_form_enabled" <?php checked( ! empty( $chat['contact_form_enabled'] ) ); ?>> Запрашивать email и телефон</label></div></div>
                <div class="wprmp-schedule-card"><h2>Расписание живого чата</h2><label><input type="checkbox" data-chat="schedule_enabled" <?php checked( ! empty( $chat['schedule_enabled'] ) ); ?>> Показывать «Менеджер онлайн» только в рабочие часы</label><div class="wprmp-days"><?php foreach ( array( 1 => 'Пн', 2 => 'Вт', 3 => 'Ср', 4 => 'Чт', 5 => 'Пт', 6 => 'Сб', 0 => 'Вс' ) as $day => $name ) : ?><label><input type="checkbox" data-schedule-day="<?php echo (int) $day; ?>" <?php checked( in_array( $day, (array) $chat['schedule_days'], true ) ); ?>><?php echo esc_html( $name ); ?></label><?php endforeach; ?></div><div class="wprmp-time-range"><label>С <input type="time" data-chat="schedule_start" value="<?php echo esc_attr( $chat['schedule_start'] ); ?>"></label><label>До <input type="time" data-chat="schedule_end" value="<?php echo esc_attr( $chat['schedule_end'] ); ?>"></label></div></div>
            </section>
            <section class="wprmp-pane" data-wprmp-pane="messages"><h2>Входящие обращения</h2><p>Здесь отображаются форма обратной связи и сообщения живого чата.</p><div class="wprmp-pending"><?php if ( empty( $open ) ) : ?><div class="wprmp-empty"><strong>Новых обращений нет</strong></div><?php else : foreach ( array_reverse( $open ) as $item ) : ?><article data-conversation-status="open"><div class="wprmp-message-avatar"><?php echo esc_html( strtoupper( substr( $item['name'] ?? 'П', 0, 1 ) ) ); ?></div><div class="wprmp-message-body"><div class="wprmp-message-meta"><strong><?php echo esc_html( $item['name'] ?? 'Посетитель' ); ?></strong><small><?php echo esc_html( $item['created_at'] ?? '' ); ?> · <?php echo esc_html( 'contact_form' === ( $item['channel'] ?? '' ) ? 'Форма' : 'Живой чат' ); ?></small></div><div class="wprmp-thread"><?php foreach ( (array) ( $item['messages'] ?? array() ) as $m ) : ?><div class="wprmp-thread-message <?php echo 'visitor' === ( $m['role'] ?? '' ) ? 'from-visitor' : 'from-manager'; ?>"><b><?php echo esc_html( 'manager' === ( $m['role'] ?? '' ) ? 'Менеджер' : ( $item['name'] ?? 'Посетитель' ) ); ?></b><span><?php echo esc_html( $m['text'] ?? '' ); ?></span></div><?php endforeach; ?></div><div class="wprmp-message-contact"><?php echo esc_html( $item['email'] ?? '' ); ?><?php echo ! empty( $item['phone'] ) ? ' · ' . esc_html( $item['phone'] ) : ''; ?></div><div class="wprmp-reply-box"><textarea class="wprmp-reply-text" rows="2" placeholder="Ответ посетителю…"></textarea><button type="button" class="button wprmp-reply" data-message-id="<?php echo esc_attr( $item['id'] ?? '' ); ?>">Ответить</button><button type="button" class="button wprmp-close-chat" data-message-id="<?php echo esc_attr( $item['id'] ?? '' ); ?>">Завершить</button><small class="wprmp-reply-status"></small></div></div></article><?php endforeach; endif; ?></div></section>
        </div>
        <?php
    }
    public function render_widget() {
        if ( ! wp_ru_max_contacts_is_configured() ) return;
        $s = wp_ru_max_contacts_settings(); $main = wp_ru_max_contacts_main_widget_settings();
        if ( empty( $s['enabled'] ) || empty( $main['enabled'] ) ) return;
        $st = $s['style']; $channels = $s['channels']; $custom = $s['custom_channels'];
        $hex = ltrim( sanitize_hex_color( $st['icon_background'] ) ?: '#4f46e5', '#' );
        if ( 3 === strlen( $hex ) ) $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        $rgb = array_map( 'hexdec', str_split( $hex, 2 ) );
        $enabled = array( 'max' );
        if ( ! empty( $s['chat']['manager_online'] ) && wp_ru_max_contacts_is_live_chat_available( $s['chat'] ) ) $enabled[] = 'live_chat';
        foreach ( $s['channel_order'] as $key ) {
            $item = $channels[ $key ] ?? ( $custom[ $key ] ?? null );
            if ( is_array( $item ) && ! empty( $item['enabled'] ) && ( ! empty( $item['desktop'] ) || ! empty( $item['mobile'] ) ) ) $enabled[] = $key;
        }
        $max_url = trim( get_option( 'wp_ru_max_settings', array() )['chat_widget_url'] ?? '' ) ?: '#';
        $icons = array( 'phone' => '☎', 'telegram' => '✈', 'vkontakte' => 'VK', 'contact' => '✉', 'email' => '@' );
        ?>
        <div id="wp-ru-max-contacts-menu" class="wprmp-menu wprmp-layout-<?php echo esc_attr( $st['layout'] ); ?> wprmp-position-<?php echo esc_attr( $main['position'] ); ?><?php echo ! empty( $st['backdrop_blur'] ) ? ' wprmp-has-blur' : ''; ?>" style="--wprmp-bg:<?php echo esc_attr( $st['icon_background'] ); ?>;--wprmp-bg-glass:rgba(<?php echo (int) $rgb[0]; ?>,<?php echo (int) $rgb[1]; ?>,<?php echo (int) $rgb[2]; ?>,.22);--wprmp-color:<?php echo esc_attr( $st['icon_color'] ); ?>;--wprmp-size:<?php echo (int) $main['size']; ?>px;--wprmp-bottom-offset:<?php echo (int) $main['bottom_offset']; ?>px">
            <div class="wprmp-panel" data-mode="live_chat" aria-hidden="true"><button class="wprmp-close" type="button" aria-label="Закрыть">×</button><div class="wprmp-panel-brand"><span class="wprmp-brand-symbol">MAX</span><div><h3><?php echo esc_html( $s['chat']['title'] ); ?></h3><small><?php echo ! empty( $s['chat']['manager_online'] ) ? 'Менеджер онлайн' : 'Сообщение попадёт менеджеру'; ?></small></div></div><p class="wprmp-mode-note">Сообщение увидит менеджер, а диалог можно продолжить здесь.</p><p class="wprmp-welcome"><?php echo esc_html( $s['chat']['welcome'] ); ?></p><div class="wprmp-thread-live" aria-live="polite"></div><form class="wprmp-form"><input type="hidden" name="channel" value="live_chat"><div class="wprmp-identity"><?php if ( ! empty( $s['chat']['contact_form_enabled'] ) ) : ?><div class="wprmp-form-row"><input name="name" placeholder="Имя" required><input name="email" type="email" placeholder="Email"></div><input name="phone" placeholder="Телефон"><?php else : ?><input name="name" placeholder="Имя" required><?php endif; ?></div><div class="wprmp-consents"><label><input type="checkbox" name="consent" value="1" required><span>Согласен(а) на обработку персональных данных.</span></label></div><textarea name="message" placeholder="Сообщение" required></textarea><button type="submit">Отправить сообщение <span>→</span></button><span class="wprmp-form-status" role="status"></span></form><?php if ( ! empty( $s['chat']['faq_enabled'] ) && ! empty( $s['chat']['faq'] ) ) : ?><div class="wprmp-faq"><strong>Частые вопросы</strong><?php foreach ( $s['chat']['faq'] as $faq ) : ?><button type="button" class="wprmp-faq-item"><span><?php echo esc_html( $faq['question'] ); ?></span><i><?php echo esc_html( $faq['answer'] ); ?></i></button><?php endforeach; ?></div><?php endif; ?><?php if ( ! empty( $s['chat']['quick_buttons'] ) ) : ?><div class="wprmp-quick-buttons"><?php foreach ( $s['chat']['quick_buttons'] as $button ) : ?><button type="button" data-message="<?php echo esc_attr( $button['message'] ); ?>"><?php echo esc_html( $button['label'] ); ?></button><?php endforeach; ?></div><?php endif; ?></div>
            <div class="wprmp-channels" aria-hidden="true"><?php foreach ( $enabled as $key ) : if ( 'live_chat' === $key ) : ?><button type="button" class="wprmp-channel wprmp-open-live-chat" data-mode="live_chat" title="Менеджер онлайн" aria-label="Менеджер онлайн"><span>●</span><b class="wprmp-channel-label">Менеджер онлайн</b></button><?php elseif ( 'max' === $key ) : ?><a class="wprmp-channel" href="<?php echo esc_url( $max_url ); ?>" target="_blank" rel="noopener" title="MAX" aria-label="MAX"><span>MAX</span><b class="wprmp-channel-label">MAX</b></a><?php else : $item = $channels[ $key ] ?? $custom[ $key ]; $is_custom = isset( $custom[ $key ] ); $label = $is_custom ? $item['label'] : ( array( 'phone' => 'Телефон', 'telegram' => 'Telegram', 'vkontakte' => 'ВКонтакте', 'contact' => 'Форма обратной связи', 'email' => 'Email' )[ $key ] ?? $key ); $device = ( empty( $item['desktop'] ) ? ' wprmp-hide-desktop' : '' ) . ( empty( $item['mobile'] ) ? ' wprmp-hide-mobile' : '' ); if ( ! $is_custom && 'contact' === $key ) : ?><button type="button" class="wprmp-channel wprmp-open-chat<?php echo esc_attr( $device ); ?>" data-mode="contact_form" title="<?php echo esc_attr( $label ); ?>"><span><?php echo esc_html( $icons[ $key ] ?? '↗' ); ?></span><b class="wprmp-channel-label"><?php echo esc_html( $label ); ?></b></button><?php else : ?><a class="wprmp-channel<?php echo esc_attr( $device ); ?>" href="<?php echo esc_url( $is_custom ? $item['url'] : $this->channel_link( array_merge( $item, array( 'type' => $key ) ), $key ) ); ?>"<?php echo ( $is_custom || ! in_array( $key, array( 'phone', 'email' ), true ) ) ? ' target="_blank" rel="noopener"' : ''; ?> title="<?php echo esc_attr( $label ); ?>"><span><?php echo esc_html( $is_custom ? '↗' : ( $icons[ $key ] ?? '↗' ) ); ?></span><b class="wprmp-channel-label"><?php echo esc_html( $label ); ?></b></a><?php endif; endif; endforeach; ?></div>
            <script>window.wpRuMaxContacts = <?php echo wp_json_encode( array( 'ajaxUrl' => admin_url( 'admin-ajax.php' ), 'nonce' => wp_create_nonce( 'wp_ru_max_contacts_front' ), 'managerOnline' => ! empty( $s['chat']['manager_online'] ) ) ); ?>;</script>
        </div>
        <?php
    }
    public function message() {
        check_ajax_referer( 'wp_ru_max_contacts_front', 'nonce' );
        if ( ! $this->allow_public_request( 'message' ) ) wp_send_json_error( 'Слишком много запросов. Попробуйте ещё раз через минуту.', 429 );
        $s = wp_ru_max_contacts_settings();
        if ( empty( $s['enabled'] ) ) wp_send_json_error( 'Виджет отключен.' );
        $name = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );
        $email = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
        $phone = sanitize_text_field( wp_unslash( $_POST['phone'] ?? '' ) );
        $message = sanitize_textarea_field( wp_unslash( $_POST['message'] ?? '' ) );
        $consent = ! empty( $_POST['consent'] );
        $id = sanitize_key( wp_unslash( $_POST['conversation_id'] ?? '' ) );
        $channel = sanitize_key( wp_unslash( $_POST['channel'] ?? 'live_chat' ) );
        if ( ! in_array( $channel, array( 'live_chat', 'contact_form' ), true ) ) $channel = 'live_chat';
        $new = '' === $id; if ( $new ) $id = wp_generate_uuid4();
        if ( '' === $name || '' === $message ) wp_send_json_error( 'Введите имя и сообщение.' );
        if ( $new && ! $consent ) wp_send_json_error( 'Необходимо принять условия обработки персональных данных.' );
        $pending = get_option( 'wp_ru_max_pro_pending_messages', array() ); $found = false;
        foreach ( $pending as &$conversation ) {
            if ( ( $conversation['conversation_id'] ?? '' ) === $id && 'closed' !== ( $conversation['status'] ?? 'open' ) ) {
                $conversation['messages'][] = array( 'role' => 'visitor', 'text' => $message, 'created_at' => current_time( 'mysql' ) );
                $conversation['message'] = $message; $conversation['channel'] = $channel; $found = true; break;
            }
        }
        unset( $conversation );
        if ( ! $found ) $pending[] = array( 'id' => $id, 'conversation_id' => $id, 'channel' => $channel, 'status' => 'open', 'created_at' => current_time( 'mysql' ), 'name' => $name, 'email' => $email, 'phone' => $phone, 'consent' => $consent, 'message' => $message, 'url' => esc_url_raw( wp_get_referer() ), 'messages' => array( array( 'role' => 'visitor', 'text' => $message, 'created_at' => current_time( 'mysql' ) ) ) );
        update_option( 'wp_ru_max_pro_pending_messages', array_slice( $pending, -100 ) );
        if ( 'contact_form' !== $channel && ! empty( $s['chat']['target'] ) && class_exists( 'WP_Ru_Max_API' ) ) {
            $base = get_option( 'wp_ru_max_settings', array() ); $api = new WP_Ru_Max_API( $base['bot_token'] ?? '' );
            $api->send_message( $s['chat']['target'], '<b>Новое сообщение с сайта</b>\nИмя: ' . esc_html( $name ) . '\nEmail: ' . esc_html( $email ) . '\nТелефон: ' . esc_html( $phone ) . '\n\n' . esc_html( $message ), 'html' );
        }
        $reply = ! empty( $s['chat']['manager_online'] ) ? 'Менеджер уже видит ваше сообщение и скоро ответит.' : ( ! empty( $s['chat']['bot_enabled'] ) ? ( $s['chat']['bot_offline_message'] ?: 'Сообщение принято. Мы скоро ответим.' ) : 'Сообщение отправлено. Мы скоро ответим.' );
        if ( ! empty( $s['chat']['bot_enabled'] ) ) {
            $pending = get_option( 'wp_ru_max_pro_pending_messages', array() );
            foreach ( $pending as &$conversation ) if ( ( $conversation['conversation_id'] ?? '' ) === $id ) { $conversation['messages'][] = array( 'role' => 'bot', 'text' => $reply, 'name' => $s['chat']['bot_name'], 'created_at' => current_time( 'mysql' ) ); break; }
            unset( $conversation ); update_option( 'wp_ru_max_pro_pending_messages', $pending );
        }
        $response_messages = array( array( 'role' => 'visitor', 'text' => $message ) );
        if ( ! empty( $s['chat']['bot_enabled'] ) ) {
            $response_messages[] = array( 'role' => 'bot', 'text' => $reply, 'name' => $s['chat']['bot_name'] );
        }
        wp_send_json_success( array( 'message' => 'Сообщение отправлено.', 'conversation_id' => $id, 'messages' => $response_messages ) );
    }
    public function history() {
        check_ajax_referer( 'wp_ru_max_contacts_front', 'nonce' ); $id = sanitize_key( wp_unslash( $_POST['conversation_id'] ?? '' ) );
        if ( ! $this->allow_public_request( 'history', 60 ) ) wp_send_json_error( 'Слишком много запросов. Попробуйте ещё раз через минуту.', 429 );
        foreach ( (array) get_option( 'wp_ru_max_pro_pending_messages', array() ) as $item ) if ( ( $item['conversation_id'] ?? '' ) === $id ) wp_send_json_success( array( 'messages' => array_values( (array) ( $item['messages'] ?? array() ) ) ) );
        wp_send_json_success( array( 'messages' => array() ) );
    }
    private function find_pending( $id, &$pending ) {
        foreach ( $pending as &$item ) if ( ( $item['id'] ?? '' ) === $id || ( $item['conversation_id'] ?? '' ) === $id ) return $item;
        return null;
    }
    public function reply() {
        check_ajax_referer( 'wp_ru_max_nonce', 'nonce' ); if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Недостаточно прав.' );
        $id = sanitize_key( wp_unslash( $_POST['message_id'] ?? '' ) ); $message = sanitize_textarea_field( wp_unslash( $_POST['message'] ?? '' ) ); $pending = get_option( 'wp_ru_max_pro_pending_messages', array() ); $ok = false;
        foreach ( $pending as &$item ) if ( ( $item['id'] ?? '' ) === $id || ( $item['conversation_id'] ?? '' ) === $id ) { $item['messages'][] = array( 'role' => 'manager', 'text' => $message, 'created_at' => current_time( 'mysql' ) ); $ok = true; break; }
        unset( $item ); if ( ! $ok || '' === $message ) wp_send_json_error( 'Диалог или сообщение не найдено.' ); update_option( 'wp_ru_max_pro_pending_messages', $pending );
        $target = wp_ru_max_contacts_settings()['chat']['target']; $base = get_option( 'wp_ru_max_settings', array() ); if ( '' !== $target && class_exists( 'WP_Ru_Max_API' ) ) ( new WP_Ru_Max_API( $base['bot_token'] ?? '' ) )->send_message( $target, wp_strip_all_tags( $message ), 'html' );
        wp_send_json_success( 'Ответ отправлен.' );
    }
    public function close() {
        check_ajax_referer( 'wp_ru_max_nonce', 'nonce' ); if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Недостаточно прав.' ); $id = sanitize_key( wp_unslash( $_POST['message_id'] ?? '' ) ); $pending = get_option( 'wp_ru_max_pro_pending_messages', array() ); $ok = false;
        foreach ( $pending as &$item ) if ( ( $item['id'] ?? '' ) === $id || ( $item['conversation_id'] ?? '' ) === $id ) { $item['status'] = 'closed'; $item['closed_at'] = current_time( 'mysql' ); $ok = true; break; }
        unset( $item ); if ( ! $ok ) wp_send_json_error( 'Диалог не найден.' ); update_option( 'wp_ru_max_pro_pending_messages', $pending ); wp_send_json_success( 'Чат завершён.' );
    }
    public function messages() {
        check_ajax_referer( 'wp_ru_max_nonce', 'nonce' ); if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Недостаточно прав.' ); $pending = get_option( 'wp_ru_max_pro_pending_messages', array() ); $count = 0;
        foreach ( (array) $pending as $item ) if ( 'closed' !== ( $item['status'] ?? 'open' ) ) $count++;
        wp_send_json_success( array( 'count' => $count ) );
    }
}

add_action( 'init', array( 'WP_Ru_Max_Contacts', 'instance' ), 20 );
