<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WP_Ru_Max_Pro_Widget {
    private static $instance;

    public static function instance() {
        return self::$instance ?: ( self::$instance = new self() );
    }

    private function __construct() {
        add_action( 'wp_enqueue_scripts', array( $this, 'assets' ) );
        add_action( 'wp_ru_max_before_widget_close', array( $this, 'render' ), 10, 1 );
        add_action( 'wp_ajax_wp_ru_max_pro_message', array( $this, 'message' ) );
        add_action( 'wp_ajax_nopriv_wp_ru_max_pro_message', array( $this, 'message' ) );
        add_action( 'wp_ajax_wp_ru_max_pro_history', array( $this, 'history' ) );
        add_action( 'wp_ajax_nopriv_wp_ru_max_pro_history', array( $this, 'history' ) );
    }

    public function assets() {
        if ( ! wp_ru_max_pro_is_enabled() ) {
            return;
        }
        $settings = wp_ru_max_pro_settings();
        if ( empty( $settings['enabled'] ) ) {
            return;
        }
        wp_enqueue_style( 'wp-ru-max-pro', WP_RU_MAX_PRO_URL . 'widget.css', array(), WP_RU_MAX_PRO_VERSION );
        wp_enqueue_script( 'wp-ru-max-pro', WP_RU_MAX_PRO_URL . 'widget.js', array(), WP_RU_MAX_PRO_VERSION, true );
        wp_localize_script( 'wp-ru-max-pro', 'wpRuMaxProFront', array(
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce' => wp_create_nonce( 'wp_ru_max_pro_front' ),
            'roboIcon' => WP_RU_MAX_PRO_URL . 'roboform.svg',
            'managerOnline' => ! empty( $settings['chat']['manager_online'] ) && wp_ru_max_pro_is_live_chat_available( $settings['chat'] ),
        ) );
    }

    private function link( $channel ) {
        $value = trim( $channel['value'] ?? '' );
        $type = sanitize_key( $channel['type'] ?? '' );
        if ( '' === $value ) {
            return '#';
        }

        if ( 'email' === $type ) {
            $email = sanitize_email( $value );
            return '' !== $email ? 'mailto:' . $email : '#';
        }

        if ( 'phone' === $type ) {
            $phone = preg_replace( '/[^0-9+]/', '', $value );
            return '' !== $phone ? 'tel:' . $phone : '#';
        }

        /*
         * Accept both a full URL and the short values people normally enter
         * in the settings screen. esc_url() intentionally returns an empty
         * string for a bare username, so normalise it before escaping.
         */
        if ( 'telegram' === $type && preg_match( '/^@?[A-Za-z0-9_]{3,}$/', $value ) ) {
            $value = 'https://t.me/' . ltrim( $value, '@' );
        } elseif ( 'vkontakte' === $type && preg_match( '/^@?[A-Za-z0-9_.-]{2,}$/', $value ) ) {
            $value = 'https://vk.com/' . ltrim( $value, '@' );
        } elseif ( ! preg_match( '#^[a-z][a-z0-9+.-]*://#i', $value ) && 0 !== strpos( $value, '//' ) ) {
            $value = 'https://' . ltrim( $value, '/' );
        }

        return esc_url( $value );
    }

    public function render( $base_settings = array() ) {
        if ( ! wp_ru_max_pro_is_enabled() ) {
            return;
        }
        $settings = wp_ru_max_pro_settings();
        $main = wp_ru_max_pro_main_widget_settings();
        if ( empty( $settings['enabled'] ) || empty( $main['enabled'] ) ) {
            return;
        }
        $style = $settings['style'];
        $channels = $settings['channels'];
        $custom_channels = $settings['custom_channels'] ?? array();
        $style['position'] = $main['position'];
        $style['size'] = $main['size'];
        $icon_background = sanitize_hex_color( $style['icon_background'] ?? '#4f46e5' ) ?: '#4f46e5';
        $icon_hex = ltrim( $icon_background, '#' );
        if ( 3 === strlen( $icon_hex ) ) {
            $icon_hex = $icon_hex[0] . $icon_hex[0] . $icon_hex[1] . $icon_hex[1] . $icon_hex[2] . $icon_hex[2];
        }
        $icon_rgb = array_map( 'hexdec', str_split( $icon_hex, 2 ) );
        $glass_background = sprintf( 'rgba(%d,%d,%d,.22)', $icon_rgb[0], $icon_rgb[1], $icon_rgb[2] );
        $main_settings = get_option( 'wp_ru_max_settings', array() );
        $max_url = trim( $main_settings['chat_widget_url'] ?? '' ) ?: '#';
        $max_target = '#' === $max_url ? '' : ' target="_blank" rel="noopener noreferrer"';
        $live_available = ! empty( $settings['chat']['manager_online'] )
            && wp_ru_max_pro_is_live_chat_available( $settings['chat'] );
        // Version 1.0.51: live chat is an explicit setting. Offline/schedule
        // state still does not hide it; only the administrator's toggle does.
        $live_chat_enabled = wp_ru_max_pro_bool( $settings['chat']['live_chat_enabled'] ?? true, true );
        $enabled = array( 'max' );
        if ( $live_chat_enabled ) {
            $enabled[] = 'live_chat';
        }
        $channel_order = is_array( $settings['channel_order'] ?? null ) ? $settings['channel_order'] : array( 'phone', 'telegram', 'vkontakte', 'contact', 'email' );
        foreach ( $channel_order as $key ) {
            $item = $channels[ $key ] ?? $custom_channels[ $key ] ?? null;
            if ( is_array( $item ) && ! empty( $item['enabled'] ) && ( ! empty( $item['desktop'] ) || ! empty( $item['mobile'] ) ) ) {
                $enabled[] = $key;
            }
        }
        $faq = ! empty( $settings['chat']['faq_enabled'] ) && is_array( $settings['chat']['faq'] ?? null ) ? $settings['chat']['faq'] : array();
        $quick_buttons = is_array( $settings['chat']['quick_buttons'] ?? null ) ? $settings['chat']['quick_buttons'] : array();
        ?>
        <div id="wp-ru-max-pro-menu" class="wprmp-menu wprmp-layout-<?php echo esc_attr( $style['layout'] ?? 'circle' ); ?> wprmp-position-<?php echo esc_attr( $style['position'] ); ?> wprmp-attention-<?php echo esc_attr( $style['attention'] ?? '' ); ?><?php echo ! empty( $style['backdrop_blur'] ) ? ' wprmp-has-blur' : ''; ?>" style="--wprmp-bg:<?php echo esc_attr( $icon_background ); ?>;--wprmp-bg-glass:<?php echo esc_attr( $glass_background ); ?>;--wprmp-color:<?php echo esc_attr( $style['icon_color'] ); ?>;--wprmp-size:<?php echo (int) $style['size']; ?>px;--wprmp-bottom-offset:<?php echo (int) $main['bottom_offset']; ?>px;--wprmp-form-bg:#4f46e5;--wprmp-form-color:#ffffff;--wprmp-cta-bg:<?php echo esc_attr( $style['cta_background'] ?? '' ); ?>;--wprmp-cta-color:<?php echo esc_attr( $style['cta_text_color'] ?? '' ); ?>">
            <div class="wprmp-panel" data-mode="live_chat" aria-hidden="true">
                <button class="wprmp-close" type="button" aria-label="Закрыть">×</button>
                <div class="wprmp-panel-brand"><img src="<?php echo esc_url( WP_RU_MAX_PRO_URL . 'roboform.svg' ); ?>" alt=""><div><h3><?php echo esc_html( $settings['chat']['title'] ); ?></h3><small><?php echo $live_available ? 'Менеджер онлайн' : 'Бот на связи 24/7'; ?></small></div></div>
                <p class="wprmp-mode-note wprmp-live-only">Живой чат: сообщение увидит менеджер, а диалог можно продолжить здесь.</p>
                <p class="wprmp-welcome"><?php echo esc_html( $settings['chat']['welcome'] ); ?></p><div class="wprmp-thread-live" aria-live="polite"></div>
                <form class="wprmp-form"><input type="hidden" name="channel" value="live_chat"><div class="wprmp-identity"><?php if ( ! empty( $settings['chat']['contact_form_enabled'] ) ) : ?><div class="wprmp-form-row"><input name="name" placeholder="Имя" required><input name="email" type="email" placeholder="Email"></div><input name="phone" placeholder="Телефон"><?php else : ?><input name="name" placeholder="Имя" required><?php endif; ?></div>
                    <div class="wprmp-consents"><label><input type="checkbox" name="consent" value="1" required><span>Да, я согласен(а) на обработку персональных данных в соответствии с Политикой конфиденциальности и принимаю условия Публичной оферты.</span></label><label><input type="checkbox" name="mailing" value="1"><span>Я согласен(а) получать информационные рассылки и уведомления об акциях. Подписку можно отменить в любое время.</span></label></div>
                    <textarea name="message" placeholder="Сообщение" required></textarea><button type="submit">Отправить сообщение <span>→</span></button><span class="wprmp-form-status" role="status"></span>
                </form>
                <?php if ( ! empty( $faq ) ) : ?><div class="wprmp-faq"><strong>Частые вопросы</strong><?php foreach ( $faq as $item ) : ?><button type="button" class="wprmp-faq-item"><span><?php echo esc_html( $item['question'] ); ?></span><i><?php echo esc_html( $item['answer'] ); ?></i></button><?php endforeach; ?></div><?php endif; ?>
                <?php if ( ! empty( $quick_buttons ) ) : ?><div class="wprmp-quick-buttons"><?php foreach ( $quick_buttons as $button ) : ?><button type="button" data-message="<?php echo esc_attr( $button['message'] ); ?>"><?php echo esc_html( $button['label'] ); ?></button><?php endforeach; ?></div><?php endif; ?>
            </div>
            <div class="wprmp-channels" aria-hidden="true"><?php foreach ( $enabled as $key ) :
                if ( 'live_chat' === $key ) : ?><button type="button" class="wprmp-channel wprmp-open-live-chat" data-mode="live_chat" title="Открыть живой чат" aria-label="Открыть живой чат"><img src="<?php echo esc_url( WP_RU_MAX_PRO_URL . 'roboform.svg' ); ?>" alt=""><span class="wprmp-channel-label">Живой чат</span></button>
                <?php elseif ( 'max' === $key ) : ?><a class="wprmp-channel" href="<?php echo esc_url( $max_url ); ?>"<?php echo $max_target; ?> title="MAX" aria-label="MAX"><img src="<?php echo esc_url( WP_RU_MAX_PRO_URL . 'MAX.svg' ); ?>" alt=""><span class="wprmp-channel-label">MAX</span></a>
                <?php else : $is_custom = isset( $custom_channels[ $key ] ); $item = $is_custom ? $custom_channels[ $key ] : $channels[ $key ]; $device_class = ( empty( $item['desktop'] ) ? ' wprmp-hide-desktop' : '' ) . ( empty( $item['mobile'] ) ? ' wprmp-hide-mobile' : '' ); $labels = array( 'phone' => 'Телефон', 'telegram' => 'Telegram', 'vkontakte' => 'ВКонтакте', 'contact' => 'Форма обратной связи', 'email' => 'Email' ); $icons = array( 'phone' => 'phone-svg.svg', 'telegram' => 'telegram.svg', 'vkontakte' => 'vkontakte.svg', 'contact' => 'contact.svg', 'email' => 'email.svg' ); $label = $is_custom ? $item['label'] : $labels[ $key ]; $icon = $is_custom ? $item['icon_url'] : WP_RU_MAX_PRO_URL . $icons[ $key ]; ?>
                    <?php if ( ! $is_custom && 'contact' === $key ) : ?><button type="button" class="wprmp-channel wprmp-open-chat<?php echo esc_attr( $device_class ); ?>" data-mode="contact_form" title="<?php echo esc_attr( $label ); ?>" aria-label="<?php echo esc_attr( $label ); ?>"><img src="<?php echo esc_url( $icon ); ?>" alt=""><span class="wprmp-channel-label"><?php echo esc_html( $label ); ?></span></button>
                    <?php else : ?><a class="wprmp-channel<?php echo esc_attr( $device_class ); ?>" href="<?php echo esc_url( $is_custom ? $item['url'] : $this->link( array_merge( $item, array( 'type' => $key ) ) ) ); ?>"<?php echo $is_custom || ! in_array( $key, array( 'email', 'phone' ), true ) ? ' target="_blank" rel="noopener"' : ''; ?> title="<?php echo esc_attr( $label ); ?>" aria-label="<?php echo esc_attr( $label ); ?>"><img src="<?php echo esc_url( $icon ); ?>" alt=""><span class="wprmp-channel-label"><?php echo esc_html( $label ); ?></span></a><?php endif; ?>
                <?php endif; endforeach; ?></div>
        </div>
        <?php
    }

    public function message() {
        check_ajax_referer( 'wp_ru_max_pro_front', 'nonce' );
        $settings = wp_ru_max_pro_settings();
        if ( empty( $settings['enabled'] ) ) {
            wp_send_json_error( 'Виджет отключен.' );
        }
        $name = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );
        $email = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
        $phone = sanitize_text_field( wp_unslash( $_POST['phone'] ?? '' ) );
        $message = sanitize_textarea_field( wp_unslash( $_POST['message'] ?? '' ) );
        $consent = ! empty( $_POST['consent'] );
        $mailing = ! empty( $_POST['mailing'] );
        $conversation_id = sanitize_key( wp_unslash( $_POST['conversation_id'] ?? '' ) );
        $channel = sanitize_key( wp_unslash( $_POST['channel'] ?? 'live_chat' ) );
        if ( ! in_array( $channel, array( 'live_chat', 'contact_form' ), true ) ) {
            $channel = 'live_chat';
        }
        $is_new_conversation = '' === $conversation_id;
        if ( $is_new_conversation ) {
            $conversation_id = wp_generate_uuid4();
        }
        $pending = get_option( 'wp_ru_max_pro_pending_messages', array() );
        $found = false;
        foreach ( $pending as &$conversation ) {
            if ( ( $conversation['conversation_id'] ?? '' ) === $conversation_id && 'closed' !== ( $conversation['status'] ?? 'open' ) ) {
                if ( '' === $name ) {
                    $name = $conversation['name'] ?? '';
                }
                $conversation['messages'][] = array( 'role' => 'visitor', 'text' => $message, 'created_at' => current_time( 'mysql' ) );
                $conversation['channel'] = $channel;
                $conversation['message'] = $message;
                $found = true;
                break;
            }
        }
        unset( $conversation );
        if ( '' === $name || '' === $message ) {
            wp_send_json_error( 'Введите имя и сообщение.' );
        }
        if ( $is_new_conversation && ! $consent ) {
            wp_send_json_error( 'Необходимо принять условия обработки персональных данных.' );
        }
        if ( ! $found ) {
            $pending[] = array(
                'id' => $conversation_id,
                'conversation_id' => $conversation_id,
                'channel' => $channel,
                'status' => 'open',
                'created_at' => current_time( 'mysql' ),
                'name' => $name,
                'email' => $email,
                'phone' => $phone,
                'consent' => $consent,
                'mailing' => $mailing,
                'message' => $message,
                'url' => esc_url_raw( wp_get_referer() ),
                'messages' => array( array( 'role' => 'visitor', 'text' => $message, 'created_at' => current_time( 'mysql' ) ) ),
            );
        }
        update_option( 'wp_ru_max_pro_pending_messages', array_slice( $pending, -100 ) );
        if ( ! empty( $settings['chat']['target'] ) && class_exists( 'WP_Ru_Max_API' ) ) {
            $base = get_option( 'wp_ru_max_settings', array() );
            ( new WP_Ru_Max_API( $base['bot_token'] ?? '' ) )->send_message(
                $settings['chat']['target'],
                "<b>Новое сообщение с сайта</b>\nИмя: " . esc_html( $name ) . "\nEmail: " . esc_html( $email ) . "\nТелефон: " . esc_html( $phone ) . "\n\n" . esc_html( $message ),
                'html'
            );
        }
        $reply = ! empty( $settings['chat']['manager_online'] )
            ? 'Менеджер уже видит ваше сообщение и скоро ответит.'
            : ( ! empty( $settings['chat']['bot_enabled'] ) ? ( $settings['chat']['bot_offline_message'] ?: 'Сообщение принято. Мы скоро ответим.' ) : 'Сообщение отправлено. Мы скоро ответим.' );
        $thread = array( array( 'role' => 'visitor', 'text' => $message ), array( 'role' => 'bot', 'text' => $reply, 'name' => $settings['chat']['bot_name'] ) );
        if ( ! empty( $settings['chat']['bot_enabled'] ) ) {
            $pending = get_option( 'wp_ru_max_pro_pending_messages', array() );
            foreach ( $pending as &$conversation ) {
                if ( ( $conversation['conversation_id'] ?? '' ) === $conversation_id ) {
                    $conversation['messages'][] = array( 'role' => 'bot', 'text' => $reply, 'name' => $settings['chat']['bot_name'], 'created_at' => current_time( 'mysql' ) );
                    $thread = $conversation['messages'];
                    break;
                }
            }
            unset( $conversation );
            update_option( 'wp_ru_max_pro_pending_messages', $pending );
        }
        wp_send_json_success( array(
            'message' => 'Сообщение отправлено.',
            'conversation_id' => $conversation_id,
            'messages' => $thread,
            'bot_reply' => $reply,
            'bot_name' => $settings['chat']['bot_name'],
        ) );
    }

    public function history() {
        check_ajax_referer( 'wp_ru_max_pro_front', 'nonce' );
        $id = sanitize_key( wp_unslash( $_POST['conversation_id'] ?? '' ) );
        $pending = get_option( 'wp_ru_max_pro_pending_messages', array() );
        foreach ( $pending as $conversation ) {
            if ( ( $conversation['conversation_id'] ?? '' ) === $id ) {
                wp_send_json_success( array( 'messages' => array_values( (array) ( $conversation['messages'] ?? array() ) ) ) );
            }
        }
        wp_send_json_success( array( 'messages' => array() ) );
    }
}
