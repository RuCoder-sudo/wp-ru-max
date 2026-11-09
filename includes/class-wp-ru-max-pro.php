<?php
/**
 * Bundled WP Ru-max PRO functionality.
 *
 * This file keeps the public option names and AJAX actions of the former
 * standalone wp-ru-max-pro add-on. That makes the main plugin a drop-in
 * replacement without losing saved settings or conversations.
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function wp_ru_max_pro_is_available() {
    return defined( 'WP_RU_MAX_VERSION' ) && class_exists( 'WP_Ru_Max_License' );
}

function wp_ru_max_pro_is_enabled() {
    // The customer-communication module is bundled with the main plugin.
    // Keep the public helper for compatibility with the old add-on, but do
    // not gate the module behind either of the former PRO licenses.
    return wp_ru_max_pro_is_available();
}

/**
 * Parse values coming from both WordPress options and AJAX form posts.
 * jQuery serializes a false boolean as the string "false"; empty() would
 * incorrectly treat that string as enabled.
 */
function wp_ru_max_pro_bool( $value, $default = false ) {
    if ( is_bool( $value ) ) {
        return $value;
    }
    if ( is_int( $value ) || is_float( $value ) ) {
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

function wp_ru_max_pro_settings() {
    $defaults = array(
        'enabled' => true,
        'channels' => array(
            'phone'     => array( 'enabled' => false, 'value' => '', 'icon' => 'phone-svg.svg', 'desktop' => true, 'mobile' => true ),
            'telegram'  => array( 'enabled' => false, 'value' => '', 'icon' => 'telegram.svg', 'desktop' => true, 'mobile' => true ),
            'vkontakte' => array( 'enabled' => false, 'value' => '', 'icon' => 'vkontakte.svg', 'desktop' => true, 'mobile' => true ),
            'contact'   => array( 'enabled' => false, 'icon' => 'contact.svg', 'desktop' => true, 'mobile' => true ),
            'email'     => array( 'enabled' => false, 'value' => '', 'icon' => 'email.svg', 'desktop' => true, 'mobile' => true ),
        ),
        'custom_channels' => array(),
        'channel_order' => array( 'phone', 'telegram', 'vkontakte', 'contact', 'email' ),
        'style' => array(
            'mode' => 'chat',
            'layout' => 'circle',
            'icon' => 'chat',
            'icon_background' => '#4f46e5',
            'icon_color' => '#ffffff',
            'position' => 'right',
            'size' => 60,
            'cta' => 'Написать нам',
            'cta_behavior' => 'hover',
            'cta_text_color' => '#ffffff',
            'cta_background' => '#4f46e5',
            'page_title' => '',
            'backdrop_blur' => false,
            'attention' => 'pulse',
        ),
        'chat' => array(
            // Значок живого чата не показывается на сайте после установки,
            // пока владелец явно не включит его в настройках.
            'live_chat_enabled' => false,
            'live_recipients' => array(),
            'form_recipients' => array(),
            'target' => '',
            'target_type' => 'chat',
            // Empty keeps existing installs routed through the live-chat
            // recipient until the administrator configures a separate form
            // destination.
            'form_target' => '',
            'form_target_type' => 'chat',
            'title' => 'Живой чат',
            'welcome' => 'Здравствуйте! Чем можем помочь?',
            'manager_online' => false,
            'bot_enabled' => true,
            'schedule_enabled' => false,
            'schedule_days' => array( 1, 2, 3, 4, 5 ),
            'schedule_start' => '09:00',
            'schedule_end' => '18:00',
            'bot_name' => 'Помощник',
            'bot_offline_message' => 'Менеджер сейчас не в сети. Я уже передал ваше сообщение команде и постараюсь помочь прямо сейчас.',
            'faq_enabled' => true,
            'faq' => array(
                array( 'question' => 'Как быстро вы отвечаете?', 'answer' => 'Обычно менеджер отвечает в течение 15 минут в рабочее время.' ),
                array( 'question' => 'Можно ли обсудить заказ?', 'answer' => 'Да, напишите нам детали — мы подключим нужного специалиста.' ),
            ),
            'contact_form_enabled' => true,
            'privacy_consent_enabled' => true,
            'privacy_consent_text' => 'Да, я согласен(а) на обработку персональных данных и принимаю условия Публичной оферты. Подробнее:',
            'privacy_policy_url' => '',
            'mailing_consent_enabled' => true,
            'mailing_consent_text' => 'Я согласен(а) получать информационные рассылки и уведомления об акциях. Подписку можно отменить в любое время.',
            'quick_buttons' => array(
                array( 'label' => 'Позвать менеджера', 'message' => 'Хочу поговорить с менеджером' ),
                array( 'label' => 'Узнать стоимость', 'message' => 'Подскажите, пожалуйста, стоимость' ),
            ),
        ),
    );

    $saved = get_option( WP_RU_MAX_PRO_OPTION, array() );
    $saved = is_array( $saved ) ? $saved : array();
    $saved_chat = is_array( $saved['chat'] ?? null ) ? $saved['chat'] : array();
    $settings = wp_parse_args( $saved, $defaults );
    $settings['enabled'] = wp_ru_max_pro_bool( $settings['enabled'] ?? $defaults['enabled'], $defaults['enabled'] );

    foreach ( array( 'channels', 'style', 'chat' ) as $group ) {
        $settings[ $group ] = wp_parse_args(
            is_array( $saved[ $group ] ?? null ) ? $saved[ $group ] : array(),
            $defaults[ $group ]
        );
    }

    // Эти варианты больше не показываются в настройках. Старые сохранённые
    // значения переводим в доступный вариант, чтобы они не возвращались на
    // сайт после обновления плагина.
    if ( ! in_array( $settings['style']['layout'] ?? '', array( 'circle', 'grid', 'corner', 'menu', 'stack', 'rows' ), true ) ) {
        $settings['style']['layout'] = $defaults['style']['layout'];
    }

    $settings['chat']['faq'] = is_array( $settings['chat']['faq'] ?? null ) ? $settings['chat']['faq'] : $defaults['chat']['faq'];
    $settings['chat']['quick_buttons'] = is_array( $settings['chat']['quick_buttons'] ?? null ) ? $settings['chat']['quick_buttons'] : $defaults['chat']['quick_buttons'];

    foreach ( $defaults['channels'] as $key => $channel_defaults ) {
        $settings['channels'][ $key ] = wp_parse_args(
            is_array( $settings['channels'][ $key ] ?? null ) ? $settings['channels'][ $key ] : array(),
            $channel_defaults
        );
        foreach ( array( 'enabled', 'desktop', 'mobile' ) as $bool_key ) {
            $settings['channels'][ $key ][ $bool_key ] = wp_ru_max_pro_bool(
                $settings['channels'][ $key ][ $bool_key ] ?? $channel_defaults[ $bool_key ],
                $channel_defaults[ $bool_key ]
            );
        }
    }

    $settings['style']['backdrop_blur'] = wp_ru_max_pro_bool(
        $settings['style']['backdrop_blur'] ?? $defaults['style']['backdrop_blur'],
        $defaults['style']['backdrop_blur']
    );
    foreach ( array( 'live_chat_enabled', 'manager_online', 'bot_enabled', 'schedule_enabled', 'faq_enabled', 'contact_form_enabled', 'privacy_consent_enabled', 'mailing_consent_enabled' ) as $bool_key ) {
        $settings['chat'][ $bool_key ] = wp_ru_max_pro_bool(
            $settings['chat'][ $bool_key ] ?? $defaults['chat'][ $bool_key ],
            $defaults['chat'][ $bool_key ]
        );
    }
    foreach ( array( 'privacy_consent_text', 'mailing_consent_text' ) as $text_key ) {
        $settings['chat'][ $text_key ] = sanitize_text_field( $settings['chat'][ $text_key ] ?? $defaults['chat'][ $text_key ] );
        if ( '' === $settings['chat'][ $text_key ] ) {
            $settings['chat'][ $text_key ] = $defaults['chat'][ $text_key ];
        }
    }
    $settings['chat']['privacy_policy_url'] = esc_url_raw( $settings['chat']['privacy_policy_url'] ?? '' );
    $settings['chat']['target_type'] = in_array( $settings['chat']['target_type'] ?? 'chat', array( 'user', 'chat' ), true )
        ? $settings['chat']['target_type']
        : 'chat';
    $legacy_live_recipients = '' !== trim( (string) ( $settings['chat']['target'] ?? '' ) )
        ? array( array(
            'id' => trim( (string) $settings['chat']['target'] ),
            'type' => $settings['chat']['target_type'],
        ) )
        : array();
    $settings['chat']['live_recipients'] = array_key_exists( 'live_recipients', $saved_chat )
        ? wp_ru_max_pro_normalize_recipients( $saved_chat['live_recipients'] )
        : wp_ru_max_pro_normalize_recipients( $legacy_live_recipients );
    $settings['chat']['form_recipients'] = array_key_exists( 'form_recipients', $saved_chat )
        ? wp_ru_max_pro_normalize_recipients( $saved_chat['form_recipients'] )
        : wp_ru_max_pro_normalize_recipients(
            '' !== trim( (string) ( $settings['chat']['form_target'] ?? '' ) )
                ? array( array(
                    'id' => trim( (string) $settings['chat']['form_target'] ),
                    'type' => $settings['chat']['form_target_type'] ?? 'chat',
                ) )
                : array()
        );
    $form_field_defaults = array(
        'name' => array( 'visible' => true, 'required' => true, 'label' => 'Имя', 'placeholder' => 'Ваше имя' ),
        'email' => array( 'visible' => $settings['chat']['contact_form_enabled'], 'required' => false, 'label' => 'Email', 'placeholder' => 'Ваш email' ),
        'phone' => array( 'visible' => $settings['chat']['contact_form_enabled'], 'required' => false, 'label' => 'Телефон', 'placeholder' => 'Ваш телефон' ),
        'message' => array( 'visible' => true, 'required' => true, 'label' => 'Сообщение', 'placeholder' => 'Напишите сообщение' ),
    );
    $saved_form_fields = is_array( $settings['chat']['form_fields'] ?? null ) ? $settings['chat']['form_fields'] : array();
    $settings['chat']['form_fields'] = array();
    foreach ( $form_field_defaults as $field_key => $field_default ) {
        $field = is_array( $saved_form_fields[ $field_key ] ?? null ) ? $saved_form_fields[ $field_key ] : array();
        $settings['chat']['form_fields'][ $field_key ] = array(
            'visible' => 'message' === $field_key ? true : wp_ru_max_pro_bool( $field['visible'] ?? $field_default['visible'], $field_default['visible'] ),
            'required' => 'message' === $field_key ? true : wp_ru_max_pro_bool( $field['required'] ?? $field_default['required'], $field_default['required'] ),
            'label' => sanitize_text_field( $field['label'] ?? $field_default['label'] ) ?: $field_default['label'],
            'placeholder' => sanitize_text_field( $field['placeholder'] ?? $field_default['placeholder'] ) ?: $field_default['placeholder'],
        );
    }

    $custom_channels = array();
    foreach ( (array) ( $saved['custom_channels'] ?? array() ) as $custom ) {
        if ( ! is_array( $custom ) ) {
            continue;
        }
        $id = sanitize_key( $custom['id'] ?? '' );
        if ( '' === $id || in_array( $id, array_keys( $defaults['channels'] ), true ) ) {
            continue;
        }
        $label = sanitize_text_field( $custom['label'] ?? '' );
        $url = esc_url_raw( $custom['url'] ?? '' );
        $icon_url = esc_url_raw( $custom['icon_url'] ?? '' );
        if ( '' === $label || '' === $url || '' === $icon_url ) {
            continue;
        }
        $custom_channels[ $id ] = array(
            'id' => $id,
            'label' => $label,
            'url' => $url,
            'icon_url' => $icon_url,
            'enabled' => wp_ru_max_pro_bool( $custom['enabled'] ?? false ),
            'desktop' => wp_ru_max_pro_bool( $custom['desktop'] ?? true, true ),
            'mobile' => wp_ru_max_pro_bool( $custom['mobile'] ?? true, true ),
        );
    }
    $settings['custom_channels'] = $custom_channels;

    $order = is_array( $settings['channel_order'] ?? null ) ? $settings['channel_order'] : $defaults['channel_order'];
    $allowed_order = array_merge( array_keys( $defaults['channels'] ), array_keys( $custom_channels ) );
    $settings['channel_order'] = array_values( array_unique( array_merge(
        array_intersect( $order, $allowed_order ),
        array_diff( $defaults['channel_order'], $order ),
        array_diff( array_keys( $custom_channels ), $order )
    ) ) );

    return $settings;
}

function wp_ru_max_pro_normalize_recipients( $recipients ) {
    if ( ! is_array( $recipients ) ) {
        return array();
    }

    $normalized = array();
    $seen = array();
    foreach ( $recipients as $recipient ) {
        if ( ! is_array( $recipient ) ) {
            continue;
        }
        $id = sanitize_text_field( trim( (string) ( $recipient['id'] ?? '' ) ) );
        if ( '' === $id ) {
            continue;
        }
        $type = 'user' === ( $recipient['type'] ?? 'chat' ) ? 'user' : 'chat';
        $key = $type . '|' . strtolower( $id );
        if ( isset( $seen[ $key ] ) ) {
            continue;
        }
        $seen[ $key ] = true;
        $normalized[] = array(
            'id' => $id,
            'type' => $type,
        );
    }

    return $normalized;
}

function wp_ru_max_pro_get_delivery_recipients( $settings, $channel ) {
    $chat = is_array( $settings['chat'] ?? null ) ? $settings['chat'] : array();
    $recipients = 'contact_form' === $channel
        ? ( $chat['form_recipients'] ?? array() )
        : ( $chat['live_recipients'] ?? array() );

    // Preserve the old behavior: form requests without a dedicated route use
    // the live-chat recipients.
    if ( 'contact_form' === $channel && empty( $recipients ) ) {
        $recipients = $chat['live_recipients'] ?? array();
    }

    return wp_ru_max_pro_normalize_recipients( $recipients );
}

function wp_ru_max_pro_send_to_recipients( $recipients, $text, $format = 'html' ) {
    $recipients = wp_ru_max_pro_normalize_recipients( $recipients );
    $result = array(
        'total' => count( $recipients ),
        'sent' => 0,
        'failures' => array(),
    );

    if ( empty( $recipients ) ) {
        $result['failures'][] = array(
            'type' => '',
            'id' => '',
            'message' => 'MAX получатели не настроены.',
        );
        return $result;
    }

    $base_settings = get_option( 'wp_ru_max_settings', array() );
    $token = trim( (string) ( $base_settings['bot_token'] ?? '' ) );
    if ( '' === $token || ! class_exists( 'WP_Ru_Max_API' ) ) {
        foreach ( $recipients as $recipient ) {
            $result['failures'][] = array(
                'type' => $recipient['type'],
                'id' => $recipient['id'],
                'message' => '' === $token ? 'Токен бота MAX не задан.' : 'Класс API MAX не загружен.',
            );
        }
        return $result;
    }

    $api = new WP_Ru_Max_API( $token );
    foreach ( $recipients as $recipient ) {
        $response = 'user' === $recipient['type']
            ? $api->send_message_to_user( $recipient['id'], $text, $format )
            : $api->send_message( $recipient['id'], $text, $format );

        if ( is_wp_error( $response ) || false === $response || null === $response ) {
            $error_data = is_wp_error( $response ) ? $response->get_error_data() : array();
            $error_body = is_array( $error_data ) && is_array( $error_data['body'] ?? null )
                ? $error_data['body']
                : array();
            $error_code = sanitize_text_field( $error_body['code'] ?? '' );
            $error_message = is_wp_error( $response ) ? $response->get_error_message() : 'Пустой ответ MAX API.';

            if ( 'error.not.found' === $error_code ) {
                $error_message = 'MAX не нашёл получателя. ' . ( 'user' === $recipient['type']
                    ? 'Проверьте, что это user_id получателя из события bot_started или message_created именно этого бота, а не ID самого бота. Пользователь должен открыть диалог с ботом.'
                    : 'Проверьте, что это chat_id из события bot_added именно этого бота и что бот добавлен в чат или канал.' );
            } elseif ( 'chat.denied' === $error_code ) {
                $error_message = 'MAX запретил отправку. ' . ( 'user' === $recipient['type']
                    ? 'Получатель должен открыть диалог с ботом.'
                    : 'Добавьте бота в группу или канал и проверьте право отправки сообщений.' );
            }

            $result['failures'][] = array(
                'type' => $recipient['type'],
                'id' => $recipient['id'],
                'message' => $error_message,
                'code' => $error_code,
            );
            continue;
        }

        $result['sent']++;
    }

    return $result;
}

function wp_ru_max_pro_main_widget_settings() {
    $main = get_option( 'wp_ru_max_settings', array() );
    $sizes = array( 'small' => 32, 'medium' => 48, 'large' => 64 );
    return array(
        'enabled' => ! empty( $main['chat_widget_enabled'] ),
        'size' => $sizes[ $main['chat_widget_size'] ?? 'medium' ] ?? 48,
        'position' => 'left' === ( $main['chat_widget_position'] ?? 'right' ) ? 'left' : 'right',
        'bottom_offset' => max( 0, min( 300, absint( $main['chat_widget_bottom_offset'] ?? 20 ) ) ),
        'welcome_enabled' => array_key_exists( 'chat_widget_message_enabled', $main ) ? ! empty( $main['chat_widget_message_enabled'] ) : true,
    );
}

function wp_ru_max_pro_is_live_chat_available( $chat ) {
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

function wp_ru_max_pro_boot() {
    if ( ! wp_ru_max_pro_is_available() ) {
        return;
    }
    WP_Ru_Max_Pro_Admin::instance();
    WP_Ru_Max_Pro_Widget::instance();
}
add_action( 'plugins_loaded', 'wp_ru_max_pro_boot', 20 );

register_activation_hook( WP_RU_MAX_PRO_FILE, function() {
    if ( false === get_option( WP_RU_MAX_PRO_OPTION ) ) {
        add_option( WP_RU_MAX_PRO_OPTION, wp_ru_max_pro_settings() );
    }
} );
