<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WP_Ru_Max_Post_Sender {

    private static $instance = null;

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $settings = get_option( 'wp_ru_max_settings', array() );
        if ( ! empty( $settings['post_sender_enabled'] ) ) {
            add_action( 'transition_post_status', array( $this, 'on_post_status_change' ), 10, 3 );
        }
    }

    public function on_post_status_change( $new_status, $old_status, $post ) {
        $settings = get_option( 'wp_ru_max_settings', array() );

        if ( empty( $settings['post_sender_enabled'] ) ) {
            return;
        }

        $post_types = isset( $settings['post_types'] ) ? (array) $settings['post_types'] : array( 'post' );
        if ( ! in_array( $post->post_type, $post_types, true ) ) {
            return;
        }

        $is_new     = ( $old_status !== 'publish' && $new_status === 'publish' );
        $is_updated = ( $old_status === 'publish' && $new_status === 'publish' );

        if ( $is_new && empty( $settings['send_new_post'] ) ) {
            return;
        }
        if ( $is_updated && empty( $settings['send_updated_post'] ) ) {
            return;
        }
        if ( ! $is_new && ! $is_updated ) {
            return;
        }

        $skip = get_post_meta( $post->ID, '_wp_ru_max_skip', true );
        if ( $skip === '1' ) {
            WP_Ru_Max_Logger::log( 'post_sender', 'info', "Запись #{$post->ID} пропущена — автоотправка отключена для этой статьи.", array( 'post_id' => $post->ID ) );
            return;
        }

        $channels = isset( $settings['channels'] ) ? (array) $settings['channels'] : array();
        if ( empty( $channels ) ) {
            WP_Ru_Max_Logger::log( 'post_sender', 'warning', 'Нет настроенных каналов для отправки публикации.', array( 'post_id' => $post->ID ) );
            return;
        }

        $message        = $this->build_post_message( $post, $is_new, $settings );
        $api            = new WP_Ru_Max_API();
        $send_image     = isset( $settings['send_post_image'] ) ? (bool) $settings['send_post_image'] : true;

        foreach ( $channels as $channel ) {
            $chat_id = trim( $channel );
            if ( empty( $chat_id ) ) {
                continue;
            }

            $thumbnail_url = $send_image ? get_the_post_thumbnail_url( $post->ID, 'large' ) : false;

            if ( $thumbnail_url ) {
                $result = $api->send_message_with_image( $chat_id, $message, $thumbnail_url, 'html' );
            } else {
                $result = $api->send_message( $chat_id, $message, 'html' );
            }

            if ( is_wp_error( $result ) ) {
                WP_Ru_Max_Logger::log( 'post_sender', 'error', "Ошибка отправки записи #{$post->ID} в канал $chat_id: " . $result->get_error_message(), array(
                    'post_id'  => $post->ID,
                    'chat_id'  => $chat_id,
                    'is_new'   => $is_new,
                ) );
            } else {
                WP_Ru_Max_Logger::log( 'post_sender', 'success', "Запись #{$post->ID} успешно отправлена в канал $chat_id.", array(
                    'post_id' => $post->ID,
                    'chat_id' => $chat_id,
                    'is_new'  => $is_new,
                ) );
            }
        }
    }

    private function build_post_message( $post, $is_new, $settings = array() ) {
        if ( empty( $settings ) ) {
            $settings = get_option( 'wp_ru_max_settings', array() );
        }

        $title    = get_the_title( $post );
        $url      = get_permalink( $post );
        $excerpt  = has_excerpt( $post ) ? get_the_excerpt( $post ) : wp_trim_words( strip_tags( $post->post_content ), 100 );
        $excerpt  = wp_strip_all_tags( $excerpt );
        $author   = get_the_author_meta( 'display_name', $post->post_author );
        $date     = get_the_date( 'd.m.Y', $post );
        $action   = $is_new ? 'Новая публикация' : 'Обновлённая публикация';

        $show_read_more    = isset( $settings['show_read_more'] ) ? (bool) $settings['show_read_more'] : true;
        $show_action_label = isset( $settings['show_action_label'] ) ? (bool) $settings['show_action_label'] : true;
        $show_author_date  = isset( $settings['show_author_date'] ) ? (bool) $settings['show_author_date'] : true;
        $excerpt_max_chars = isset( $settings['excerpt_max_chars'] ) ? intval( $settings['excerpt_max_chars'] ) : 300;

        if ( $excerpt_max_chars > 0 && mb_strlen( $excerpt ) > $excerpt_max_chars ) {
            $excerpt = mb_substr( $excerpt, 0, $excerpt_max_chars ) . '…';
        }

        $msg = '';
        if ( $show_action_label ) {
            $msg .= "<b>$action</b>\n\n";
        }
        $msg .= "<b>$title</b>\n";

        if ( $excerpt ) {
            $msg .= "\n" . $excerpt . "\n";
        }

        if ( $show_author_date ) {
            $msg .= "\nАвтор: $author";
            $msg .= "\nДата: $date";
        }

        if ( $show_read_more ) {
            $msg .= "\n\n<a href=\"$url\">Читать полностью</a>";
        }

        return $msg;
    }

    /**
     * Send a post manually from Gutenberg or any other admin context.
     * Returns true on success, WP_Error on failure.
     */
    public function send_post_manually( $post ) {
        $settings = get_option( 'wp_ru_max_settings', array() );
        $channels = isset( $settings['channels'] ) ? (array) $settings['channels'] : array();

        if ( empty( $channels ) ) {
            return new WP_Error( 'no_channels', 'Нет настроенных каналов. Добавьте канал на вкладке «Отправка публикаций».' );
        }

        $message    = $this->build_post_message( $post, false, $settings );
        $api        = new WP_Ru_Max_API();
        $send_image = isset( $settings['send_post_image'] ) ? (bool) $settings['send_post_image'] : true;
        $errors     = array();

        foreach ( $channels as $channel ) {
            $chat_id = trim( $channel );
            if ( empty( $chat_id ) ) {
                continue;
            }

            $thumbnail_url = $send_image ? get_the_post_thumbnail_url( $post->ID, 'large' ) : false;
            $result = $thumbnail_url
                ? $api->send_message_with_image( $chat_id, $message, $thumbnail_url, 'html' )
                : $api->send_message( $chat_id, $message, 'html' );

            if ( is_wp_error( $result ) ) {
                $errors[] = $chat_id . ': ' . $result->get_error_message();
                WP_Ru_Max_Logger::log( 'post_sender', 'error', "Ручная отправка записи #{$post->ID} в канал $chat_id НЕУДАЧНА: " . $result->get_error_message() );
            } else {
                WP_Ru_Max_Logger::log( 'post_sender', 'success', "Ручная отправка записи #{$post->ID} в канал $chat_id успешна." );
            }
        }

        if ( count( $errors ) === count( $channels ) && ! empty( $errors ) ) {
            return new WP_Error( 'send_failed', implode( '; ', $errors ) );
        }

        return true;
    }

    public function send_test( $chat_id ) {
        $message = "<b>Тестовое сообщение WP Ru-max</b>\n\nОтправка публикаций настроена и работает корректно!\n\nСайт: " . get_bloginfo( 'url' );
        $api     = new WP_Ru_Max_API();
        return $api->send_message( $chat_id, $message, 'html' );
    }
}
