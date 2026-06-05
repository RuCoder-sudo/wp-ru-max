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
        // Хук для отложенной отправки через WP-Cron
        add_action( 'wp_ru_max_delayed_send', array( $this, 'do_delayed_send' ), 10, 1 );
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

        // Проверка мета: отправлять ли эту запись
        $skip = get_post_meta( $post->ID, 'wp_ru_max_skip', true );
        if ( $skip === '' || $skip === null || $skip === false ) {
            $legacy = get_post_meta( $post->ID, '_wp_ru_max_skip', true );
            if ( $legacy !== '' && $legacy !== null && $legacy !== false ) {
                $skip = $legacy;
            }
        }

        $skip_str = is_scalar( $skip ) ? trim( (string) $skip ) : '';

        if ( $skip_str === '' ) {
            // Явное значение не задано — применяем глобальную настройку «По умолчанию»
            $default_on = ! empty( $settings['auto_send_default'] );
            if ( ! $default_on ) {
                WP_Ru_Max_Logger::log( 'post_sender', 'info', "Запись #{$post->ID} пропущена — автоотправка отключена для этой статьи (глобальный По умолчанию: ВЫКЛ).", array( 'post_id' => $post->ID ) );
                return;
            }
            // Глобальный По умолчанию: ВКЛ — продолжаем отправку
        } elseif ( $skip_str !== '0' ) {
            WP_Ru_Max_Logger::log( 'post_sender', 'info', "Запись #{$post->ID} пропущена — автоотправка отключена для этой статьи (тумблер: ВЫКЛ).", array( 'post_id' => $post->ID, 'skip' => $skip ) );
            return;
        }

        // Фильтр по категориям
        if ( ! $this->matches_category_filter( $post->ID, $settings ) ) {
            WP_Ru_Max_Logger::log( 'post_sender', 'info', "Запись #{$post->ID} пропущена — не подходит под фильтр категорий/тегов.", array( 'post_id' => $post->ID ) );
            return;
        }

        $channels = isset( $settings['channels'] ) ? (array) $settings['channels'] : array();
        if ( empty( $channels ) ) {
            WP_Ru_Max_Logger::log( 'post_sender', 'warning', 'Нет настроенных каналов для отправки публикации.', array( 'post_id' => $post->ID ) );
            return;
        }

        // Отложенная отправка
        $delay = isset( $settings['send_delay_seconds'] ) ? (int) $settings['send_delay_seconds'] : 0;

        if ( $delay > 0 ) {
            // Запись данных для отложенного запуска
            $job_key = 'wp_ru_max_delayed_' . $post->ID . '_' . time();
            set_transient( $job_key, array(
                'post_id' => $post->ID,
                'is_new'  => $is_new,
            ), $delay + 300 );

            wp_schedule_single_event( time() + $delay, 'wp_ru_max_delayed_send', array( $job_key ) );

            WP_Ru_Max_Logger::log( 'post_sender', 'info', "Запись #{$post->ID} поставлена в очередь на отправку через {$delay} сек.", array(
                'post_id' => $post->ID,
                'delay'   => $delay,
                'job_key' => $job_key,
            ) );
            return;
        }

        // Немедленная отправка
        $this->send_post( $post, $is_new, $settings );
    }

    /**
     * Обработчик отложенной отправки (WP-Cron).
     */
    public function do_delayed_send( $job_key ) {
        $data = get_transient( $job_key );
        if ( ! $data || empty( $data['post_id'] ) ) {
            return;
        }
        delete_transient( $job_key );

        $post = get_post( $data['post_id'] );
        if ( ! $post || $post->post_status !== 'publish' ) {
            WP_Ru_Max_Logger::log( 'post_sender', 'warning', "Отложенная отправка: запись #{$data['post_id']} не найдена или снята с публикации." );
            return;
        }

        $settings = get_option( 'wp_ru_max_settings', array() );
        $this->send_post( $post, $data['is_new'], $settings );
    }

    /**
     * Отправляет запись во все настроенные каналы.
     */
    private function send_post( $post, $is_new, $settings ) {
        $channels   = isset( $settings['channels'] ) ? (array) $settings['channels'] : array();
        $message    = $this->build_post_message( $post, $is_new, $settings );
        $buttons    = $this->get_buttons( $settings, $post );
        $api        = new WP_Ru_Max_API();
        $send_image = isset( $settings['send_post_image'] ) ? (bool) $settings['send_post_image'] : true;

        // Настройки retry
        $max_retries = isset( $settings['retry_count'] ) ? (int) $settings['retry_count'] : 2;
        $retry_delay = isset( $settings['retry_delay_seconds'] ) ? (int) $settings['retry_delay_seconds'] : 5;

        foreach ( $channels as $channel ) {
            $chat_id = trim( $channel );
            if ( empty( $chat_id ) ) {
                continue;
            }

            $thumbnail_url = $send_image ? $this->get_post_image_url( $post ) : false;

            if ( $thumbnail_url && $send_image ) {
                // Используем send_with_retry с изображением
                $result = $api->send_with_retry(
                    $chat_id,
                    $message,
                    'html',
                    $buttons,
                    $thumbnail_url,
                    $max_retries,
                    $retry_delay
                );
            } else {
                $result = $api->send_with_retry(
                    $chat_id,
                    $message,
                    'html',
                    $buttons,
                    false,
                    $max_retries,
                    $retry_delay
                );
            }

            if ( is_wp_error( $result ) ) {
                WP_Ru_Max_Logger::log( 'post_sender', 'error', "Ошибка отправки записи #{$post->ID} в канал $chat_id: " . $result->get_error_message(), array(
                    'post_id' => $post->ID,
                    'chat_id' => $chat_id,
                    'is_new'  => $is_new,
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

    /**
     * Проверяет, подходит ли запись под фильтр категорий/тегов.
     * Если фильтр не настроен — пропускает все записи.
     */
    private function matches_category_filter( $post_id, $settings ) {
        $filter_cats = isset( $settings['filter_categories'] ) ? array_filter( array_map( 'intval', (array) $settings['filter_categories'] ) ) : array();
        $filter_tags = isset( $settings['filter_tags'] ) ? array_filter( array_map( 'intval', (array) $settings['filter_tags'] ) ) : array();

        // Если оба фильтра пустые — отправляем все
        if ( empty( $filter_cats ) && empty( $filter_tags ) ) {
            return true;
        }

        // Проверяем категории
        if ( ! empty( $filter_cats ) ) {
            $post_cats = wp_get_post_categories( $post_id, array( 'fields' => 'ids' ) );
            if ( array_intersect( $filter_cats, $post_cats ) ) {
                return true;
            }
        }

        // Проверяем теги
        if ( ! empty( $filter_tags ) ) {
            $post_tags = wp_get_post_tags( $post_id, array( 'fields' => 'ids' ) );
            if ( array_intersect( $filter_tags, $post_tags ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Получить кнопки из настроек с заменой плейсхолдеров в URL.
     */
    private function get_buttons( $settings, $post = null ) {
        $buttons = isset( $settings['post_buttons'] ) ? (array) $settings['post_buttons'] : array();
        $buttons = array_values( array_filter( $buttons, function( $b ) {
            return ! empty( $b['text'] ) && ! empty( $b['url'] );
        } ) );

        if ( empty( $buttons ) || ! $post ) {
            return $buttons;
        }

        $title     = get_the_title( $post );
        $url       = get_permalink( $post );
        $author    = get_the_author_meta( 'display_name', $post->post_author );
        $date      = get_the_date( 'd.m.Y', $post );
        $home_url  = home_url();
        $site_name = get_bloginfo( 'name' );

        foreach ( $buttons as &$btn ) {
            $btn['url'] = str_replace(
                array( '{url}', '{title}', '{author}', '{date}', '{home_url}', '{site_name}' ),
                array( $url, $title, $author, $date, $home_url, $site_name ),
                $btn['url']
            );
            $btn['url'] = $this->replace_field_placeholders( $btn['url'], $post );

            $btn['url'] = preg_replace_callback( '/\{encode:([^}]+)\}/', function( $m ) {
                return urlencode( $m[1] );
            }, $btn['url'] );
        }
        unset( $btn );

        return $buttons;
    }

    /**
     * Заменяет плейсхолдеры {meta_KEY} и {acf_KEY} в строке шаблона.
     */
    private function replace_field_placeholders( $text, $post ) {
        $text = preg_replace_callback( '/\{meta_([a-zA-Z0-9_\-]+)\}/', function( $m ) use ( $post ) {
            $val = get_post_meta( $post->ID, $m[1], true );
            return is_scalar( $val ) ? (string) $val : '';
        }, $text );

        $text = preg_replace_callback( '/\{acf_([a-zA-Z0-9_\-]+)\}/', function( $m ) use ( $post ) {
            if ( function_exists( 'get_field' ) ) {
                $val = get_field( $m[1], $post->ID );
                if ( is_array( $val ) ) {
                    $val = implode( ', ', $val );
                }
                return is_scalar( $val ) ? (string) $val : '';
            }
            return '';
        }, $text );

        return $text;
    }

    /**
     * Построить сообщение для записи.
     */
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
        $pt_obj   = get_post_type_object( $post->post_type );
        $pt_label = $pt_obj ? $pt_obj->label : $post->post_type;

        $excerpt_max_chars = isset( $settings['excerpt_max_chars'] ) ? intval( $settings['excerpt_max_chars'] ) : 300;
        if ( $excerpt_max_chars > 0 && mb_strlen( $excerpt ) > $excerpt_max_chars ) {
            $excerpt = mb_substr( $excerpt, 0, $excerpt_max_chars ) . '…';
        }

        $template = isset( $settings['post_message_template'] ) ? trim( $settings['post_message_template'] ) : '';

        if ( ! empty( $template ) ) {
            $msg = str_replace(
                array( '{title}', '{excerpt}', '{url}', '{author}', '{date}', '{status}', '{site_name}', '{post_type}' ),
                array( $title, $excerpt, $url, $author, $date, $action, get_bloginfo( 'name' ), $pt_label ),
                $template
            );
            $msg = $this->replace_field_placeholders( $msg, $post );
            return $msg;
        }

        $show_read_more    = isset( $settings['show_read_more'] ) ? (bool) $settings['show_read_more'] : true;
        $show_action_label = isset( $settings['show_action_label'] ) ? (bool) $settings['show_action_label'] : true;
        $show_author_date  = isset( $settings['show_author_date'] ) ? (bool) $settings['show_author_date'] : true;

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

    public function send_post_manually( $post ) {
        $settings   = get_option( 'wp_ru_max_settings', array() );
        $channels   = isset( $settings['channels'] ) ? (array) $settings['channels'] : array();

        if ( empty( $channels ) ) {
            return new WP_Error( 'no_channels', 'Нет настроенных каналов. Добавьте канал на вкладке «Отправка публикаций».' );
        }

        $message    = $this->build_post_message( $post, false, $settings );
        $buttons    = $this->get_buttons( $settings, $post );
        $api        = new WP_Ru_Max_API();
        $send_image = isset( $settings['send_post_image'] ) ? (bool) $settings['send_post_image'] : true;
        $max_retries = isset( $settings['retry_count'] ) ? (int) $settings['retry_count'] : 2;
        $retry_delay = isset( $settings['retry_delay_seconds'] ) ? (int) $settings['retry_delay_seconds'] : 5;
        $errors     = array();

        foreach ( $channels as $channel ) {
            $chat_id = trim( $channel );
            if ( empty( $chat_id ) ) {
                continue;
            }

            $thumbnail_url = $send_image ? $this->get_post_image_url( $post ) : false;
            $result = $api->send_with_retry( $chat_id, $message, 'html', $buttons, $thumbnail_url ?: false, $max_retries, $retry_delay );

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

    /**
     * Получить URL изображения для записи.
     *
     * Порядок поиска:
     *   1. Миниатюра записи (Featured Image)
     *   2. Первый <img> из тела записи
     *   3. Первое прикреплённое изображение (медиабиблиотека)
     *
     * Возвращает URL строкой или false если ничего не найдено.
     */
    private function get_post_image_url( $post ) {
        // 1. Миниатюра записи
        $url = get_the_post_thumbnail_url( $post->ID, 'large' );
        if ( $url ) {
            WP_Ru_Max_Logger::log( 'post_sender', 'info', "Изображение для записи #{$post->ID}: миниатюра.", array( 'url' => $url ) );
            return $url;
        }

        // 2. Первый <img> из тела записи
        if ( ! empty( $post->post_content ) ) {
            preg_match( '/<img[^>]+src=["\']([^"\']+)["\'][^>]*>/i', $post->post_content, $matches );
            if ( ! empty( $matches[1] ) ) {
                $url = $matches[1];
                // Пропускаем иконки и служебные изображения (меньше 50px обычно emoji/иконки)
                if ( strpos( $url, 'data:' ) === false && strpos( $url, 'emoji' ) === false ) {
                    WP_Ru_Max_Logger::log( 'post_sender', 'info', "Изображение для записи #{$post->ID}: первый <img> из контента.", array( 'url' => $url ) );
                    return $url;
                }
            }
        }

        // 3. Первое прикреплённое изображение
        $attachments = get_attached_media( 'image', $post->ID );
        if ( ! empty( $attachments ) ) {
            $first      = reset( $attachments );
            $attach_url = wp_get_attachment_url( $first->ID );
            if ( $attach_url ) {
                WP_Ru_Max_Logger::log( 'post_sender', 'info', "Изображение для записи #{$post->ID}: прикреплённый файл.", array( 'url' => $attach_url ) );
                return $attach_url;
            }
        }

        WP_Ru_Max_Logger::log( 'post_sender', 'info', "Изображение для записи #{$post->ID} не найдено — отправка без картинки." );
        return false;
    }
}
