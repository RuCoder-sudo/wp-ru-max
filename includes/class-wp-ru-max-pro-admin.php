<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WP_Ru_Max_Pro_Admin {
    private static $instance;

    public static function instance() {
        return self::$instance ?: ( self::$instance = new self() );
    }

    private function __construct() {
        add_filter( 'wp_ru_max_admin_tabs', array( $this, 'add_tab' ) );
        add_filter( 'wp_ru_max_admin_tab_keys', array( $this, 'add_key' ) );
        add_filter( 'wp_ru_max_admin_submenu_items', array( $this, 'add_submenu' ) );
        add_action( 'wp_ru_max_render_admin_tab_contacts', array( $this, 'render' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
        add_action( 'wp_ajax_wp_ru_max_pro_save', array( $this, 'save' ) );
        add_action( 'wp_ajax_wp_ru_max_pro_reply', array( $this, 'reply' ) );
        add_action( 'wp_ajax_wp_ru_max_pro_close', array( $this, 'close' ) );
        add_action( 'wp_ajax_wp_ru_max_pro_messages', array( $this, 'messages' ) );
    }

    public function add_tab( $tabs ) { $tabs['contacts'] = 'Связь с клиентами'; return $tabs; }
    public function add_key( $keys ) { $keys[] = 'contacts'; return $keys; }
    public function add_submenu( $items ) { $items[] = array( 'Связь с клиентами', 'manage_options', 'admin.php?page=wp-ru-max&tab=contacts' ); return $items; }

    public function assets( $hook ) {
        if ( false === strpos( $hook, 'wp-ru-max' ) ) {
            return;
        }
        // Keep the plugin release at v1.0.58, but bust browser caches when
        // the bundled admin assets change between maintenance updates.
        $asset_versions = array();
        foreach ( array( 'admin.css', 'preview.css', 'admin.js' ) as $asset ) {
            $path = WP_RU_MAX_PRO_DIR . 'assets/pro/' . $asset;
            $asset_versions[ $asset ] = file_exists( $path ) ? (string) filemtime( $path ) : WP_RU_MAX_PRO_VERSION;
        }
        wp_enqueue_style( 'wp-ru-max-pro-admin', WP_RU_MAX_PRO_URL . 'admin.css', array(), $asset_versions['admin.css'] );
        wp_enqueue_style( 'wp-ru-max-pro-preview', WP_RU_MAX_PRO_URL . 'preview.css', array( 'wp-ru-max-pro-admin' ), $asset_versions['preview.css'] );
        wp_enqueue_script( 'wp-ru-max-pro-admin', WP_RU_MAX_PRO_URL . 'admin.js', array( 'jquery' ), $asset_versions['admin.js'], true );
        wp_localize_script( 'wp-ru-max-pro-admin', 'wpRuMaxPro', array(
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce' => wp_create_nonce( 'wp_ru_max_pro_nonce' ),
            'assetsUrl' => WP_RU_MAX_PRO_URL,
        ) );
    }

    private function text( $value ) {
        return sanitize_text_field( wp_unslash( $value ?? '' ) );
    }

    public function save() {
        check_ajax_referer( 'wp_ru_max_pro_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Недостаточно прав.' );
        }
        $current = wp_ru_max_pro_settings();
        $raw = wp_unslash( $_POST['settings'] ?? array() );
        if ( ! is_array( $raw ) ) {
            wp_send_json_error( 'Неверные настройки.' );
        }
        $channels = array();
        foreach ( array( 'phone', 'telegram', 'vkontakte', 'contact', 'email' ) as $channel ) {
            $item = is_array( $raw['channels'][ $channel ] ?? null ) ? $raw['channels'][ $channel ] : array();
            $channels[ $channel ] = array(
                'enabled' => wp_ru_max_pro_bool( $item['enabled'] ?? false ),
                'value' => $this->text( $item['value'] ?? '' ),
                'icon' => sanitize_file_name( $item['icon'] ?? $current['channels'][ $channel ]['icon'] ),
                'desktop' => wp_ru_max_pro_bool( $item['desktop'] ?? ( $current['channels'][ $channel ]['desktop'] ?? true ), $current['channels'][ $channel ]['desktop'] ?? true ),
                'mobile' => wp_ru_max_pro_bool( $item['mobile'] ?? ( $current['channels'][ $channel ]['mobile'] ?? true ), $current['channels'][ $channel ]['mobile'] ?? true ),
            );
        }
        $custom_channels = array();
        foreach ( (array) ( $raw['custom_channels'] ?? array() ) as $item ) {
            if ( ! is_array( $item ) ) {
                continue;
            }
            $id = sanitize_key( $item['id'] ?? '' );
            if ( '' === $id || in_array( $id, array_keys( $channels ), true ) ) {
                continue;
            }
            $label = $this->text( $item['label'] ?? '' );
            $url = esc_url_raw( $item['url'] ?? '' );
            $icon_url = esc_url_raw( $item['icon_url'] ?? '' );
            if ( '' === $label || '' === $url || '' === $icon_url ) {
                continue;
            }
            $custom_channels[ $id ] = array(
                'id' => $id,
                'label' => $label,
                'url' => $url,
                'icon_url' => $icon_url,
                'enabled' => wp_ru_max_pro_bool( $item['enabled'] ?? false ),
                'desktop' => wp_ru_max_pro_bool( $item['desktop'] ?? true, true ),
                'mobile' => wp_ru_max_pro_bool( $item['mobile'] ?? true, true ),
            );
        }
        $raw_order = is_array( $raw['channel_order'] ?? null ) ? array_map( 'sanitize_key', $raw['channel_order'] ) : array();
        $base_order = array( 'phone', 'telegram', 'vkontakte', 'contact', 'email' );
        $allowed_order = array_merge( $base_order, array_keys( $custom_channels ) );
        $channel_order = array_values( array_unique( array_merge(
            array_intersect( $raw_order, $allowed_order ),
            array_diff( $base_order, $raw_order ),
            array_diff( array_keys( $custom_channels ), $raw_order )
        ) ) );
        $style_raw = is_array( $raw['style'] ?? null ) ? $raw['style'] : array();
        $chat_raw = is_array( $raw['chat'] ?? null ) ? $raw['chat'] : array();
        // Версия 1.0.51: сохраняем выключенный живой чат даже если браузер
        // прислал неполный объект настроек без unchecked-поля.
        $live_chat_enabled = array_key_exists( 'live_chat_enabled', $chat_raw )
            ? wp_ru_max_pro_bool( $chat_raw['live_chat_enabled'] )
            : wp_ru_max_pro_bool( $current['chat']['live_chat_enabled'] ?? true, true );
        $settings = array(
            'enabled' => wp_ru_max_pro_bool( $raw['enabled'] ?? false, true ),
            'channels' => $channels,
            'custom_channels' => $custom_channels,
            'channel_order' => $channel_order,
            'style' => array(
                'mode' => in_array( $style_raw['mode'] ?? '', array( 'simple', 'chat' ), true ) ? $style_raw['mode'] : 'simple',
                'layout' => in_array( $style_raw['layout'] ?? '', array( 'circle', 'grid', 'corner', 'menu', 'stack', 'rows' ), true ) ? $style_raw['layout'] : 'circle',
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
                'backdrop_blur' => wp_ru_max_pro_bool( $style_raw['backdrop_blur'] ?? false ),
                'attention' => in_array( $style_raw['attention'] ?? '', array( 'none', 'pulse', 'bounce' ), true ) ? $style_raw['attention'] : 'pulse',
            ),
            'chat' => array(
                // Версия 1.0.51: отдельное управление отображением живого чата.
                'live_chat_enabled' => $live_chat_enabled,
                'target' => $this->text( $chat_raw['target'] ?? '' ),
                'title' => $this->text( $chat_raw['title'] ?? 'Чат с нами' ),
                'welcome' => sanitize_textarea_field( $chat_raw['welcome'] ?? '' ),
                'manager_online' => wp_ru_max_pro_bool( $chat_raw['manager_online'] ?? false ),
                'bot_enabled' => wp_ru_max_pro_bool( $chat_raw['bot_enabled'] ?? false ),
                'schedule_enabled' => wp_ru_max_pro_bool( $chat_raw['schedule_enabled'] ?? false ),
                'schedule_days' => array_values( array_intersect( array_map( 'absint', (array) ( $chat_raw['schedule_days'] ?? array() ) ), range( 0, 6 ) ) ),
                'schedule_start' => preg_match( '/^\d{2}:\d{2}$/', $chat_raw['schedule_start'] ?? '' ) ? $chat_raw['schedule_start'] : '09:00',
                'schedule_end' => preg_match( '/^\d{2}:\d{2}$/', $chat_raw['schedule_end'] ?? '' ) ? $chat_raw['schedule_end'] : '18:00',
                'bot_name' => $this->text( $chat_raw['bot_name'] ?? 'Помощник' ),
                'bot_offline_message' => sanitize_textarea_field( $chat_raw['bot_offline_message'] ?? '' ),
                'faq_enabled' => wp_ru_max_pro_bool( $chat_raw['faq_enabled'] ?? false ),
                'contact_form_enabled' => wp_ru_max_pro_bool( $chat_raw['contact_form_enabled'] ?? false ),
                'faq' => array(),
                'quick_buttons' => array(),
            ),
        );
        foreach ( (array) ( $chat_raw['faq'] ?? array() ) as $faq ) {
            if ( ! empty( $faq['question'] ) && ! empty( $faq['answer'] ) ) {
                $settings['chat']['faq'][] = array( 'question' => $this->text( $faq['question'] ), 'answer' => sanitize_textarea_field( $faq['answer'] ) );
            }
        }
        foreach ( (array) ( $chat_raw['quick_buttons'] ?? array() ) as $button ) {
            if ( ! empty( $button['label'] ) && ! empty( $button['message'] ) ) {
                $settings['chat']['quick_buttons'][] = array( 'label' => $this->text( $button['label'] ), 'message' => $this->text( $button['message'] ) );
            }
        }
        update_option( WP_RU_MAX_PRO_OPTION, $settings );
        wp_send_json_success( 'Настройки сохранены.' );
    }

    public function render() {
        $s = wp_ru_max_pro_settings();
        $c = $s['channels'];
        $custom = $s['custom_channels'] ?? array();
        $st = $s['style'];
        $chat = $s['chat'];
        $labels = array( 'phone' => 'Телефон', 'telegram' => 'Telegram', 'vkontakte' => 'ВКонтакте', 'contact' => 'Форма обратной связи', 'email' => 'Email' );
        $icons = array( 'phone' => 'phone-svg.svg', 'telegram' => 'telegram.svg', 'vkontakte' => 'vkontakte.svg', 'contact' => 'contact.svg', 'email' => 'email.svg' );
        $pending = get_option( 'wp_ru_max_pro_pending_messages', array() );
        $live_count = 0;
        foreach ( (array) $pending as $conversation ) {
            if ( 'closed' === ( $conversation['status'] ?? 'open' ) || 'live_chat' !== ( $conversation['channel'] ?? '' ) ) continue;
            foreach ( (array) ( $conversation['messages'] ?? array() ) as $message ) if ( 'visitor' === ( $message['role'] ?? '' ) ) $live_count++;
        }
        $open_forms = array_filter( (array) $pending, function( $item ) { return 'closed' !== ( $item['status'] ?? 'open' ) && 'contact_form' === ( $item['channel'] ?? 'contact_form' ); } );
        $main = wp_ru_max_pro_main_widget_settings();
        ?>
        <div class="wprmp-admin" dir="ltr">
            <div class="wprmp-heading"><div><span class="wprmp-kicker">WP RU-MAX</span><h1>Связь с клиентами</h1><p>Настройте красивый чат, принимайте заявки и отвечайте из MAX.</p></div><div class="wprmp-heading-actions"><span class="wprmp-live-status">● Модуль включён</span><button type="button" class="button button-primary" id="wprmp-save">Сохранить настройки</button></div></div>
            <nav class="wprmp-subtabs" aria-label="Разделы связи"><button type="button" class="wprmp-subtab is-active" data-wprmp-tab="settings">Настройки виджета</button><button type="button" class="wprmp-subtab" data-wprmp-tab="messages">Заявки с формы <b><?php echo count( $open_forms ); ?></b></button><button type="button" class="wprmp-subtab" data-wprmp-tab="livechat">Живой чат <span class="wprmp-online-pill"><b><?php echo (int) $live_count; ?></b> сообщений</span></button></nav>
            <section class="wprmp-pane is-active" data-wprmp-pane="settings">
                <div class="wprmp-grid"><section class="wprmp-settings">
                    <div class="wprmp-hero"><div><span class="wprmp-eyebrow">КОНТАКТЫ В ОДНОМ МЕСТЕ</span><h2>Сделайте общение частью сайта</h2><p>Посетитель нажимает на значок MAX и получает аккуратное меню дополнительных каналов.</p></div></div>
                    <h2>Шаг 1: Выберите свои каналы</h2><div class="wprmp-channel-order" aria-label="Порядок каналов">
                    <?php foreach ( $s['channel_order'] as $key ) : $is_custom = isset( $custom[ $key ] ); if ( ! $is_custom && ! isset( $labels[ $key ] ) ) continue; $item = $is_custom ? $custom[ $key ] : $c[ $key ]; $label = $is_custom ? $item['label'] : $labels[ $key ]; $icon = $is_custom ? $item['icon_url'] : WP_RU_MAX_PRO_URL . $icons[ $key ]; ?>
                        <div class="wprmp-channel-card<?php echo $is_custom ? ' wprmp-custom-channel-card' : ''; ?>" data-channel-item="<?php echo esc_attr( $key ); ?>" data-custom-channel="<?php echo $is_custom ? '1' : '0'; ?>"><div class="wprmp-channel-top"><label><span class="wprmp-drag-handle" draggable="true">⋮⋮</span><img class="wprmp-channel-icon" src="<?php echo esc_url( $icon ); ?>" alt=""><input type="checkbox" class="wprmp-enabled" <?php checked( ! empty( $item['enabled'] ) ); ?>> <b class="wprmp-channel-name"><?php echo esc_html( $label ); ?></b></label><span class="wprmp-channel-status"><?php echo $is_custom ? 'Произвольный' : 'Канал'; ?></span></div>
                        <?php if ( $is_custom ) : ?><div class="wprmp-custom-fields"><label>Название <input type="text" data-custom-field="label" value="<?php echo esc_attr( $item['label'] ); ?>"></label><label>Ссылка <input type="url" data-custom-field="url" value="<?php echo esc_attr( $item['url'] ); ?>"></label><label>URL иконки <input type="url" data-custom-field="icon_url" value="<?php echo esc_attr( $item['icon_url'] ); ?>"></label></div><button type="button" class="button wprmp-remove-custom">Удалить канал</button><?php else : ?><input type="text" data-value="<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( $item['value'] ?? '' ); ?>" placeholder="<?php echo 'phone' === $key ? '+7 900 000-00-00' : ( 'email' === $key ? 'email@example.com' : '@username или ссылка' ); ?>"><?php endif; ?>
                        <div class="wprmp-channel-devices"><label><input type="checkbox" data-device="desktop" <?php checked( ! empty( $item['desktop'] ) ); ?>> ПК</label><label><input type="checkbox" data-device="mobile" <?php checked( ! empty( $item['mobile'] ) ); ?>> Мобильные</label></div></div>
                    <?php endforeach; ?></div>
                    <button type="button" class="button wprmp-add-custom-channel">+ Добавить произвольную ссылку</button><p class="wprmp-custom-note">Создайте свой канал: укажите название, ссылку и URL изображения иконки.</p>
                    <h2>Шаг 2: Настройте свой виджет</h2><p class="wprmp-main-settings-note">Размер, положение и высота основного значка MAX берутся из раздела «Чат-виджет MAX» основного плагина.</p><div class="wprmp-main-settings-sync"><strong>Настройки основного MAX применяются и к PRO</strong><span>Размер: <?php echo esc_html( $main['size'] ); ?> px · Положение: <?php echo 'left' === $main['position'] ? 'слева' : 'справа'; ?> · Отступ снизу: <?php echo (int) $main['bottom_offset']; ?> px</span></div>
                    <div class="wprmp-fields"><label>Вид <select data-style="mode"><option value="simple" <?php selected( $st['mode'], 'simple' ); ?>>Простой вид</option><option value="chat" <?php selected( $st['mode'], 'chat' ); ?>>Вид чата</option></select></label><label>Расположение значков <select data-style="layout"><option value="circle" <?php selected( $st['layout'], 'circle' ); ?>>Кружком</option><option value="grid" <?php selected( $st['layout'], 'grid' ); ?>>Сеткой</option><option value="corner" <?php selected( $st['layout'], 'corner' ); ?>>Угловой круг</option><option value="menu" <?php selected( $st['layout'], 'menu' ); ?>>Меню</option><option value="stack" <?php selected( $st['layout'], 'stack' ); ?>>Вертикально</option><option value="rows" <?php selected( $st['layout'], 'rows' ); ?>>В два ряда</option></select></label><label>Фон иконки <input type="color" data-style="icon_background" value="<?php echo esc_attr( $st['icon_background'] ); ?>"></label><label>Цвет иконки <input type="color" data-style="icon_color" value="<?php echo esc_attr( $st['icon_color'] ); ?>"></label><label>Положение <select data-style="position"><option value="right" <?php selected( $st['position'], 'right' ); ?>>Справа</option><option value="left" <?php selected( $st['position'], 'left' ); ?>>Слева</option></select></label><label>Размер виджета <input type="range" min="42" max="96" data-style="size" value="<?php echo esc_attr( $st['size'] ); ?>"></label><label>Призыв к действию <input type="text" data-style="cta" value="<?php echo esc_attr( $st['cta'] ); ?>"></label><label>Поведение <select data-style="cta_behavior"><option value="hover" <?php selected( $st['cta_behavior'], 'hover' ); ?>>При наведении</option><option value="always" <?php selected( $st['cta_behavior'], 'always' ); ?>>Всегда</option></select></label><label>Цвет текста CTA <input type="color" data-style="cta_text_color" value="<?php echo esc_attr( $st['cta_text_color'] ); ?>"></label><label>Фон CTA <input type="color" data-style="cta_background" value="<?php echo esc_attr( $st['cta_background'] ); ?>"></label><label>Заголовок страницы <input type="text" data-style="page_title" value="<?php echo esc_attr( $st['page_title'] ); ?>"></label><label>Эффект внимания <select data-style="attention"><option value="pulse" <?php selected( $st['attention'], 'pulse' ); ?>>Пульсация</option><option value="bounce" <?php selected( $st['attention'], 'bounce' ); ?>>Прыжок</option><option value="none" <?php selected( $st['attention'], 'none' ); ?>>Нет</option></select></label><label><input type="checkbox" data-style="backdrop_blur" <?php checked( ! empty( $st['backdrop_blur'] ) ); ?>> Эффект размытия фона</label></div>
                    <div class="wprmp-chat-settings"><div class="wprmp-chat-settings-head"><div><h2>Живой чат</h2><p>Заявки приходят сюда и дублируются менеджеру в MAX.</p></div><label class="wprmp-switch"><input type="checkbox" data-chat="live_chat_enabled" <?php checked( ! empty( $chat['live_chat_enabled'] ) ); ?>><span></span><b>Показывать значок «Живой чат»</b></label><label class="wprmp-switch"><input type="checkbox" data-chat="manager_online" <?php checked( ! empty( $chat['manager_online'] ) ); ?>><span></span><b>Менеджер онлайн</b></label></div><div class="wprmp-chat-fields"><label>Чат MAX / идентификатор получателя <input type="text" data-chat="target" value="<?php echo esc_attr( $chat['target'] ); ?>"></label><label>Заголовок <input type="text" data-chat="title" value="<?php echo esc_attr( $chat['title'] ); ?>"></label><label>Приветствие <textarea data-chat="welcome"><?php echo esc_textarea( $chat['welcome'] ); ?></textarea></label><label><input type="checkbox" data-chat="bot_enabled" <?php checked( ! empty( $chat['bot_enabled'] ) ); ?>> Автоответ бота офлайн</label><label>Имя бота <input type="text" data-chat="bot_name" value="<?php echo esc_attr( $chat['bot_name'] ); ?>"></label><label>Ответ бота <textarea data-chat="bot_offline_message"><?php echo esc_textarea( $chat['bot_offline_message'] ); ?></textarea></label></div></div>
                    <div class="wprmp-enhancements"><span class="wprmp-eyebrow">УСИЛЕНИЯ</span><h2>Полезные блоки в чате</h2><div class="wprmp-enhancement-grid"><label><input type="checkbox" data-chat="faq_enabled" <?php checked( ! empty( $chat['faq_enabled'] ) ); ?>><strong>Частые вопросы</strong></label><label><input type="checkbox" data-chat="contact_form_enabled" <?php checked( ! empty( $chat['contact_form_enabled'] ) ); ?>><strong>Контактная форма</strong></label></div><div class="wprmp-faq-editor"><?php foreach ( $chat['faq'] as $faq ) : ?><div class="wprmp-faq-row"><input type="text" data-faq="question" value="<?php echo esc_attr( $faq['question'] ); ?>" placeholder="Вопрос"><input type="text" data-faq="answer" value="<?php echo esc_attr( $faq['answer'] ); ?>" placeholder="Ответ"></div><?php endforeach; ?></div></div>
                </section><aside class="wprmp-preview"><div class="wprmp-preview-label"><span class="wprmp-live-dot"></span> ЖИВОЙ ПРОСМОТР</div><h2>Так увидят ваш виджет</h2><div class="wprmp-browser"><div class="wprmp-browser-bar"><i></i><i></i><i></i></div><div class="wprmp-demo-page"><span>Ваш сайт</span><div></div><div></div></div><div id="wprmp-preview-widget" data-preview-open="0"><span class="wprmp-preview-cta"><?php echo esc_html( $st['cta'] ); ?></span><button type="button" class="wprmp-preview-trigger" aria-label="Открыть меню связи" aria-expanded="false"><img src="<?php echo esc_url( WP_RU_MAX_PRO_URL . 'roboform.svg' ); ?>" alt=""></button><div class="wprmp-preview-chat" aria-live="polite"><strong><?php echo esc_html( $chat['title'] ); ?></strong><small><?php echo esc_html( $chat['welcome'] ); ?></small><span>Напишите сообщение…</span></div></div></div><p>Нажмите на иконку в правом нижнем углу сайта — откроется меню каналов.</p></aside></div>
                <div class="wprmp-schedule-card"><h2>Расписание живого чата</h2><p>Значок «Живой чат» показывается только в выбранные дни и часы.</p><label><input type="checkbox" data-chat="schedule_enabled" <?php checked( ! empty( $chat['schedule_enabled'] ) ); ?>> Показывать живой чат только в рабочие часы</label><div class="wprmp-days"><?php foreach ( array( 1 => 'Пн', 2 => 'Вт', 3 => 'Ср', 4 => 'Чт', 5 => 'Пт', 6 => 'Сб', 0 => 'Вс' ) as $day => $day_label ) : ?><label><input type="checkbox" data-schedule-day="<?php echo (int) $day; ?>" <?php checked( in_array( $day, (array) $chat['schedule_days'], true ) ); ?>><?php echo esc_html( $day_label ); ?></label><?php endforeach; ?></div><div class="wprmp-time-range"><label>С <input type="time" data-chat="schedule_start" value="<?php echo esc_attr( $chat['schedule_start'] ); ?>"></label><label>До <input type="time" data-chat="schedule_end" value="<?php echo esc_attr( $chat['schedule_end'] ); ?>"></label></div></div>
            </section>
            <section class="wprmp-pane" data-wprmp-pane="messages"><div class="wprmp-messages-head"><div><span class="wprmp-eyebrow">ВХОДЯЩИЕ</span><h2>Заявки с формы</h2><p>Новые обращения появляются здесь автоматически.</p></div><span class="wprmp-message-count"><?php echo count( $open_forms ); ?> новых</span></div><div class="wprmp-message-filters"><button type="button" class="button is-active" data-message-filter="all">Все</button><button type="button" class="button" data-message-filter="open">Открытые</button><button type="button" class="button" data-message-filter="closed">Завершённые</button></div><div class="wprmp-pending"><?php $this->render_pending( $pending, $chat ); ?></div></section>
            <section class="wprmp-pane" data-wprmp-pane="livechat"><div class="wprmp-livechat-summary"><div><span class="wprmp-eyebrow">ВХОДЯЩИЕ СООБЩЕНИЯ</span><h2>Живой чат</h2></div><span class="wprmp-livechat-count" id="wprmp-livechat-count"><?php echo (int) $live_count; ?> сообщений</span></div><div class="wprmp-livechat-conversations"></div></section>
        </div>
        <?php
    }

    private function render_pending( $pending, $chat ) {
        if ( empty( $pending ) ) {
            echo '<div class="wprmp-empty"><strong>Пока нет новых сообщений</strong><span>Когда посетитель заполнит форму, обращение появится здесь.</span></div>';
            return;
        }
        foreach ( array_reverse( $pending ) as $item ) {
            $status = 'closed' === ( $item['status'] ?? 'open' ) ? 'closed' : 'open';
            $thread = (array) ( $item['messages'] ?? array( array( 'role' => 'visitor', 'text' => $item['message'] ?? '' ) ) );
            ?>
            <article data-conversation-status="<?php echo esc_attr( $status ); ?>"><div class="wprmp-message-avatar"><?php echo esc_html( strtoupper( substr( $item['name'] ?? 'П', 0, 1 ) ) ); ?></div><div class="wprmp-message-body"><div class="wprmp-message-meta"><strong><?php echo esc_html( $item['name'] ?? 'Посетитель' ); ?></strong><small><?php echo esc_html( $item['created_at'] ?? '' ); ?> · <?php echo 'contact_form' === ( $item['channel'] ?? 'live_chat' ) ? 'Форма обратной связи' : 'Живой чат'; ?></small></div><div class="wprmp-thread"><?php foreach ( $thread as $message ) : ?><div class="wprmp-thread-message <?php echo 'visitor' === ( $message['role'] ?? '' ) ? 'from-visitor' : 'from-manager'; ?>"><b><?php echo 'manager' === ( $message['role'] ?? '' ) ? 'Менеджер' : ( 'bot' === ( $message['role'] ?? '' ) ? esc_html( $chat['bot_name'] ) : esc_html( $item['name'] ?? 'Посетитель' ) ); ?></b><span><?php echo esc_html( $message['text'] ?? '' ); ?></span></div><?php endforeach; ?></div><div class="wprmp-message-contact"><?php echo esc_html( $item['email'] ?? '' ); ?><?php echo ! empty( $item['phone'] ) ? ' · ' . esc_html( $item['phone'] ) : ''; ?></div><div class="wprmp-reply-box"><textarea class="wprmp-reply-text" rows="2" placeholder="Напишите ответ посетителю…"></textarea><button type="button" class="button wprmp-reply" data-message-id="<?php echo esc_attr( $item['id'] ?? $item['conversation_id'] ?? '' ); ?>">Ответить посетителю</button><small class="wprmp-reply-status"></small></div></div></article>
            <?php
        }
    }

    public function reply() {
        check_ajax_referer( 'wp_ru_max_pro_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Недостаточно прав.' );
        $message = sanitize_textarea_field( wp_unslash( $_POST['message'] ?? '' ) );
        $message_id = sanitize_key( wp_unslash( $_POST['message_id'] ?? '' ) );
        if ( '' === $message || '' === $message_id ) wp_send_json_error( 'Введите ответ и выберите диалог.' );
        $pending = get_option( 'wp_ru_max_pro_pending_messages', array() );
        $stored = false;
        foreach ( $pending as &$conversation ) {
            if ( ( $conversation['id'] ?? '' ) === $message_id || ( $conversation['conversation_id'] ?? '' ) === $message_id ) {
                $conversation['messages'][] = array( 'role' => 'manager', 'text' => $message, 'created_at' => current_time( 'mysql' ) );
                $stored = true;
                break;
            }
        }
        unset( $conversation );
        if ( ! $stored ) wp_send_json_error( 'Диалог не найден.' );
        update_option( 'wp_ru_max_pro_pending_messages', $pending );
        $target = wp_ru_max_pro_settings()['chat']['target'];
        if ( '' !== $target && class_exists( 'WP_Ru_Max_API' ) ) {
            $settings = get_option( 'wp_ru_max_settings', array() );
            ( new WP_Ru_Max_API( $settings['bot_token'] ?? '' ) )->send_message( $target, wp_strip_all_tags( $message ), 'html' );
        }
        wp_send_json_success( 'Ответ отправлен.' );
    }

    public function close() {
        check_ajax_referer( 'wp_ru_max_pro_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Недостаточно прав.' );
        $message_id = sanitize_key( wp_unslash( $_POST['message_id'] ?? '' ) );
        if ( '' === $message_id ) wp_send_json_error( 'Выберите диалог.' );
        $pending = get_option( 'wp_ru_max_pro_pending_messages', array() );
        $closed = false;
        foreach ( $pending as &$conversation ) {
            if ( ( $conversation['id'] ?? '' ) === $message_id || ( $conversation['conversation_id'] ?? '' ) === $message_id ) {
                $conversation['status'] = 'closed';
                $conversation['closed_at'] = current_time( 'mysql' );
                $closed = true;
                break;
            }
        }
        unset( $conversation );
        if ( ! $closed ) wp_send_json_error( 'Диалог не найден.' );
        update_option( 'wp_ru_max_pro_pending_messages', $pending );
        wp_send_json_success( 'Чат завершён.' );
    }

    public function messages() {
        check_ajax_referer( 'wp_ru_max_pro_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Недостаточно прав.' );
        $pending = get_option( 'wp_ru_max_pro_pending_messages', array() );
        $open = array_filter( (array) $pending, function( $item ) { return 'closed' !== ( $item['status'] ?? 'open' ) && 'contact_form' === ( $item['channel'] ?? 'contact_form' ); } );
        $live_count = 0;
        foreach ( (array) $pending as $conversation ) {
            if ( 'closed' === ( $conversation['status'] ?? 'open' ) || 'live_chat' !== ( $conversation['channel'] ?? '' ) ) continue;
            foreach ( (array) ( $conversation['messages'] ?? array() ) as $message ) if ( 'visitor' === ( $message['role'] ?? '' ) ) $live_count++;
        }
        wp_send_json_success( array( 'count' => count( $open ), 'live_count' => $live_count ) );
    }
}
