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

    /**
     * Public widget endpoints need a small abuse guard because a nonce is not
     * authentication for anonymous visitors.
     */
    private function allow_public_request( $action, $limit = 20 ) {
        $ip = isset( $_SERVER['REMOTE_ADDR'] )
            ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) )
            : 'unknown';
        $key   = 'wp_ru_max_public_' . md5( $action . '|' . $ip . '|' . wp_salt( 'auth' ) );
        $count = (int) get_transient( $key );

        if ( $count >= $limit ) {
            return false;
        }

        set_transient( $key, $count + 1, MINUTE_IN_SECONDS );
        return true;
    }

    public function assets() {
        if ( ! wp_ru_max_pro_is_enabled() ) {
            return;
        }
        $settings = wp_ru_max_pro_settings();
        if ( empty( $settings['enabled'] ) ) {
            return;
        }
        $css_path = WP_RU_MAX_PRO_DIR . 'assets/pro/widget.css';
        $js_path  = WP_RU_MAX_PRO_DIR . 'assets/pro/widget.js';
        $css_ver  = file_exists( $css_path ) ? (string) filemtime( $css_path ) : WP_RU_MAX_PRO_VERSION;
        $js_ver   = file_exists( $js_path ) ? (string) filemtime( $js_path ) : WP_RU_MAX_PRO_VERSION;
        wp_enqueue_style( 'wp-ru-max-pro', WP_RU_MAX_PRO_URL . 'widget.css', array(), $css_ver );
        wp_enqueue_script( 'wp-ru-max-pro', WP_RU_MAX_PRO_URL . 'widget.js', array(), $js_ver, true );
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
        $live_chat_enabled = wp_ru_max_pro_bool( $settings['chat']['live_chat_enabled'] ?? false, false );
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
        $form_fields = is_array( $settings['chat']['form_fields'] ?? null ) ? $settings['chat']['form_fields'] : array();
        $privacy_consent_enabled = ! empty( $settings['chat']['privacy_consent_enabled'] );
        $mailing_consent_enabled = ! empty( $settings['chat']['mailing_consent_enabled'] );
        ?>
        <div id="wp-ru-max-pro-menu" class="wprmp-menu wprmp-layout-<?php echo esc_attr( $style['layout'] ?? 'circle' ); ?> wprmp-position-<?php echo esc_attr( $style['position'] ); ?> wprmp-attention-<?php echo esc_attr( $style['attention'] ?? '' ); ?><?php echo ! empty( $style['backdrop_blur'] ) ? ' wprmp-has-blur' : ''; ?>" style="--wprmp-bg:<?php echo esc_attr( $icon_background ); ?>;--wprmp-bg-glass:<?php echo esc_attr( $glass_background ); ?>;--wprmp-color:<?php echo esc_attr( $style['icon_color'] ); ?>;--wprmp-size:<?php echo (int) $style['size']; ?>px;--wprmp-bottom-offset:<?php echo (int) $main['bottom_offset']; ?>px;--wprmp-form-bg:#4f46e5;--wprmp-form-color:#ffffff;--wprmp-cta-bg:<?php echo esc_attr( $style['cta_background'] ?? '' ); ?>;--wprmp-cta-color:<?php echo esc_attr( $style['cta_text_color'] ?? '' ); ?>">
            <div class="wprmp-panel" data-mode="live_chat" aria-hidden="true">
                <button class="wprmp-close" type="button" aria-label="Закрыть">×</button>
                <div class="wprmp-panel-brand"><img src="<?php echo esc_url( WP_RU_MAX_PRO_URL . 'roboform.svg' ); ?>" alt=""><div><h3><?php echo esc_html( $settings['chat']['title'] ); ?></h3><small><?php echo $live_available ? 'Менеджер онлайн' : 'Бот на связи 24/7'; ?></small></div></div>
                <p class="wprmp-mode-note wprmp-live-only">Живой чат: сообщение увидит менеджер, а диалог можно продолжить здесь.</p>
                <p class="wprmp-welcome"><?php echo esc_html( $settings['chat']['welcome'] ); ?></p><div class="wprmp-thread-live" aria-live="polite"></div>
                <form class="wprmp-form"><input type="hidden" name="channel" value="live_chat"><div class="wprmp-identity">
                    <?php foreach ( array( 'name' => 'text', 'email' => 'email', 'phone' => 'tel' ) as $field_key => $input_type ) : $field = $form_fields[ $field_key ] ?? array(); if ( empty( $field['visible'] ) ) continue; ?>
                        <input name="<?php echo esc_attr( $field_key ); ?>" type="<?php echo esc_attr( $input_type ); ?>" aria-label="<?php echo esc_attr( $field['label'] ?? $field_key ); ?>" placeholder="<?php echo esc_attr( $field['placeholder'] ?? $field['label'] ?? $field_key ); ?>" autocomplete="<?php echo 'name' === $field_key ? 'name' : ( 'phone' === $field_key ? 'tel' : 'email' ); ?>" <?php echo ! empty( $field['required'] ) ? 'required' : ''; ?>>
                    <?php endforeach; ?>
                </div>
                    <?php if ( $privacy_consent_enabled || $mailing_consent_enabled ) : ?>
                        <div class="wprmp-consents">
                            <?php if ( $privacy_consent_enabled ) : ?>
                                <label><input type="checkbox" name="consent" value="1" required><span><?php echo esc_html( $settings['chat']['privacy_consent_text'] ); ?> <?php if ( ! empty( $settings['chat']['privacy_policy_url'] ) ) : ?><a href="<?php echo esc_url( $settings['chat']['privacy_policy_url'] ); ?>" target="_blank" rel="noopener noreferrer">Политика конфиденциальности</a><?php else : ?>Политика конфиденциальности<?php endif; ?></span></label>
                            <?php endif; ?>
                            <?php if ( $mailing_consent_enabled ) : ?>
                                <label><input type="checkbox" name="mailing" value="1"><span><?php echo esc_html( $settings['chat']['mailing_consent_text'] ); ?></span></label>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                    <?php $message_field = $form_fields['message'] ?? array( 'label' => 'Сообщение', 'placeholder' => 'Напишите сообщение' ); ?><textarea name="message" aria-label="<?php echo esc_attr( $message_field['label'] ); ?>" placeholder="<?php echo esc_attr( $message_field['placeholder'] ); ?>" required></textarea><button type="submit">Отправить сообщение <span>→</span></button><span class="wprmp-form-status" role="status"></span>
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
        if ( ! $this->allow_public_request( 'message' ) ) {
            wp_send_json_error( 'Слишком много запросов. Попробуйте ещё раз через минуту.', 429 );
        }
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
        if ( ! is_array( $pending ) ) {
            $pending = array();
        }
        $found = false;
        foreach ( $pending as &$conversation ) {
            if ( ( $conversation['conversation_id'] ?? '' ) === $conversation_id && 'closed' !== ( $conversation['status'] ?? 'open' ) ) {
                if ( '' === $name ) {
                    $name = $conversation['name'] ?? '';
                }
                if ( '' === $email ) {
                    $email = $conversation['email'] ?? '';
                }
                if ( '' === $phone ) {
                    $phone = $conversation['phone'] ?? '';
                }
                $conversation['messages'][] = array( 'role' => 'visitor', 'text' => $message, 'created_at' => current_time( 'mysql' ) );
                $conversation['channel'] = $channel;
                $conversation['message'] = $message;
                $found = true;
                break;
            }
        }
        unset( $conversation );
        // An unknown or expired conversation ID must not bypass the initial
        // form's required-field and consent checks.
        if ( ! $found ) {
            $is_new_conversation = true;
        }
        $form_fields = $settings['chat']['form_fields'] ?? array();
        if ( '' === $message ) wp_send_json_error( 'Введите сообщение.' );
        if ( $is_new_conversation ) {
            $submitted_fields = array(
                'name' => $name,
                'email' => $email,
                'phone' => $phone,
            );
            foreach ( array( 'name' => 'имя', 'email' => 'email', 'phone' => 'телефон' ) as $field_key => $field_label ) {
                $field = $form_fields[ $field_key ] ?? array();
                if ( empty( $field['visible'] ) ) {
                    $submitted_fields[ $field_key ] = '';
                    continue;
                }
                if ( ! empty( $field['required'] ) && '' === trim( $submitted_fields[ $field_key ] ) ) {
                    wp_send_json_error( 'Заполните обязательное поле: ' . $field_label . '.' );
                }
            }
            $name  = $submitted_fields['name'];
            $email = $submitted_fields['email'];
            $phone = $submitted_fields['phone'];
            if ( ! empty( $form_fields['email']['visible'] ) && '' !== $email && ! is_email( $email ) ) {
                wp_send_json_error( 'Введите корректный email.' );
            }
            if ( '' === $name ) $name = 'Посетитель';
            if ( ! empty( $settings['chat']['privacy_consent_enabled'] ) && ! $consent ) {
                wp_send_json_error( 'Необходимо согласиться с обработкой персональных данных.' );
            }
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
        $max_error = '';
        $visible_contact_values = array(
            'name' => array( 'label' => $form_fields['name']['label'] ?? 'Имя', 'value' => $name ),
            'email' => array( 'label' => $form_fields['email']['label'] ?? 'Email', 'value' => $email ),
            'phone' => array( 'label' => $form_fields['phone']['label'] ?? 'Телефон', 'value' => $phone ),
        );
        $details = array();
        foreach ( $visible_contact_values as $field_key => $contact_field ) {
            $field = $form_fields[ $field_key ] ?? array();
            if ( ! empty( $field['visible'] ) && '' !== trim( (string) $contact_field['value'] ) ) {
                $details[] = sanitize_text_field( $contact_field['label'] ) . ': ' . esc_html( $contact_field['value'] );
            }
        }
        if ( $is_new_conversation && ! empty( $settings['chat']['privacy_consent_enabled'] ) ) {
            $details[] = 'Согласие на обработку персональных данных: Да';
        }
        if ( $is_new_conversation && ! empty( $settings['chat']['mailing_consent_enabled'] ) ) {
            $details[] = 'Согласие на рассылку: ' . ( $mailing ? 'Да' : 'Нет' );
        }
        $details[] = '';
        $details[] = esc_html( $message );
        $notice_title = 'contact_form' === $channel ? 'Новая заявка с сайта' : 'Новое сообщение с сайта';
        $message_text = '<b>' . $notice_title . "</b>\n" . implode( "\n", $details );
        $recipients = wp_ru_max_pro_get_delivery_recipients( $settings, $channel );
        $delivery = wp_ru_max_pro_send_to_recipients( $recipients, $message_text, 'html' );
        if ( 0 === $delivery['total'] ) {
            $max_error = 'На сайте сохранено, но получатели MAX не настроены. Откройте «Виджет контакты / ЧАТ» → «Настройки подключения».';
        } elseif ( $delivery['sent'] < $delivery['total'] ) {
            $max_error = $delivery['sent'] > 0
                ? 'На сайте сохранено; в MAX доставлено ' . $delivery['sent'] . ' из ' . $delivery['total'] . ' получателей. Проверьте недоставленные ID в журнале.'
                : 'На сайте сохранено, но MAX не подтвердил доставку. Проверьте доступ бота к получателям и журнал ошибок.';
        }
        foreach ( $delivery['failures'] as $failure ) {
            WP_Ru_Max_Logger::log(
                'contacts',
                'error',
                'Не удалось передать обращение менеджеру в MAX.',
                array(
                    'channel' => $channel,
                    'recipient_type' => $failure['type'],
                    'recipient_id' => $failure['id'],
                    'error_code' => $failure['code'] ?? '',
                    'error' => $failure['message'],
                )
            );
        }
        if ( $delivery['sent'] > 0 ) {
            WP_Ru_Max_Logger::log(
                'contacts',
                $delivery['sent'] === $delivery['total'] ? 'success' : 'warning',
                'Результат доставки обращения в MAX.',
                array(
                    'channel' => $channel,
                    'sent' => $delivery['sent'],
                    'total' => $delivery['total'],
                )
            );
        }
        $pending = get_option( 'wp_ru_max_pro_pending_messages', array() );
        if ( ! is_array( $pending ) ) {
            $pending = array();
        }
        foreach ( $pending as &$conversation ) {
            if ( ( $conversation['conversation_id'] ?? '' ) === $conversation_id ) {
                $conversation['max_delivery'] = '' === $max_error
                    ? 'sent'
                    : ( $delivery['sent'] > 0 ? 'partial' : 'failed' );
                break;
            }
        }
        unset( $conversation );
        update_option( 'wp_ru_max_pro_pending_messages', $pending );
        $reply = '' !== $max_error
            ? ( $delivery['sent'] > 0
                ? 'Сообщение принято на сайте и передано части получателей. Менеджер проверит доставку.'
                : 'Сообщение сохранено на сайте, но не доставлено менеджеру. Попробуйте отправить его позже.' )
            : ( ! empty( $settings['chat']['manager_online'] )
                ? 'Менеджер уже видит ваше сообщение и скоро ответит.'
                : ( ! empty( $settings['chat']['bot_enabled'] ) ? ( $settings['chat']['bot_offline_message'] ?: 'Сообщение принято. Мы скоро ответим.' ) : 'Сообщение отправлено. Мы скоро ответим.' ) );
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
            'message' => '' === $max_error ? 'Сообщение передано менеджеру в MAX.' : $max_error,
            'conversation_id' => $conversation_id,
            'messages' => $thread,
            'bot_reply' => $reply,
            'bot_name' => $settings['chat']['bot_name'],
        ) );
    }

    public function history() {
        check_ajax_referer( 'wp_ru_max_pro_front', 'nonce' );
        if ( ! $this->allow_public_request( 'history', 60 ) ) {
            wp_send_json_error( 'Слишком много запросов. Попробуйте ещё раз через минуту.', 429 );
        }
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
