<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WP_Ru_Max_Notifications {

    private static $instance = null;

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $settings = get_option( 'wp_ru_max_settings', array() );
        if ( ! empty( $settings['notifications_enabled'] ) ) {
            add_filter( 'wp_mail', array( $this, 'intercept_email' ), 10, 1 );
        }
    }

    public function intercept_email( $args ) {
        $settings = get_option( 'wp_ru_max_settings', array() );

        if ( empty( $settings['notifications_enabled'] ) ) {
            return $args;
        }

        $to              = isset( $args['to'] ) ? $args['to'] : '';
        $subject         = isset( $args['subject'] ) ? $args['subject'] : '';
        $message         = isset( $args['message'] ) ? $args['message'] : '';
        $notify_from     = isset( $settings['notify_from_email'] ) ? $settings['notify_from_email'] : 'any';
        $chat_ids        = isset( $settings['notify_chat_ids'] ) ? (array) $settings['notify_chat_ids'] : array();
        $template        = isset( $settings['notify_template'] ) ? $settings['notify_template'] : "<b>{email_subject}</b>\n{email_message}";
        $format          = isset( $settings['notify_format'] ) ? $settings['notify_format'] : 'html';

        if ( $notify_from !== 'any' ) {
            $to_emails = is_array( $to ) ? $to : explode( ',', $to );
            $matched   = false;
            $allowed   = array_map( 'trim', explode( ',', $notify_from ) );
            foreach ( $to_emails as $email ) {
                if ( in_array( trim( $email ), $allowed, true ) ) {
                    $matched = true;
                    break;
                }
            }
            if ( ! $matched ) {
                return $args;
            }
        }

        $clean_message = wp_strip_all_tags( $message );
        $clean_message = html_entity_decode( $clean_message, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        $clean_message = preg_replace( '/\n{3,}/', "\n\n", $clean_message );
        $clean_message = trim( $clean_message );

        $text = str_replace(
            array( '{email_subject}', '{email_message}' ),
            array( $subject, $clean_message ),
            $template
        );

        $api = new WP_Ru_Max_API();
        foreach ( $chat_ids as $chat_id ) {
            $chat_id = trim( $chat_id );
            if ( empty( $chat_id ) ) {
                continue;
            }

            $result = $api->send_message( $chat_id, $text, $format );

            if ( is_wp_error( $result ) ) {
                WP_Ru_Max_Logger::log( 'notifications', 'error', "Ошибка отправки уведомления в $chat_id: " . $result->get_error_message(), array(
                    'chat_id' => $chat_id,
                    'subject' => $subject,
                ) );
            } else {
                WP_Ru_Max_Logger::log( 'notifications', 'success', "Уведомление отправлено в $chat_id. Тема: $subject", array(
                    'chat_id' => $chat_id,
                    'subject' => $subject,
                ) );
            }
        }

        return $args;
    }

    public function send_test( $chat_id ) {
        $message = "<b>Тестовое уведомление WP Ru-max</b>\n\nЛичные уведомления настроены и работают корректно!\n\nСайт: " . get_bloginfo( 'url' );
        $api     = new WP_Ru_Max_API();
        return $api->send_message( $chat_id, $message, 'html' );
    }
}
