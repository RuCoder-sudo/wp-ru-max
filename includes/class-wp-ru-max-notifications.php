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

    /**
     * Конвертирует HTML-письмо в чистый читаемый текст.
     * Корректно обрабатывает WooCommerce email с таблицами и вложенными тегами.
     */
    private function html_to_text( $html ) {
        // Удаляем script, style, head, svg полностью с содержимым
        $html = preg_replace( '/<(script|style|head|svg)[^>]*>.*?<\/(script|style|head|svg)>/is', '', $html );

        // Декодируем HTML-сущности до обработки тегов
        $html = html_entity_decode( $html, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

        // <br> → перевод строки
        $html = preg_replace( '/<br\s*\/?>/i', "\n", $html );

        // Параграфы
        $html = preg_replace( '/<\/p>/i', "\n\n", $html );
        $html = preg_replace( '/<p[^>]*>/i', '', $html );

        // Заголовки h1-h6
        $html = preg_replace( '/<h[1-6][^>]*>/i', "\n", $html );
        $html = preg_replace( '/<\/h[1-6]>/i', "\n", $html );

        // Ячейки таблицы — добавляем разделитель после содержимого
        $html = preg_replace( '/<th[^>]*>/i', '', $html );
        $html = preg_replace( '/<\/th>/i', ': ', $html );
        $html = preg_replace( '/<td[^>]*>/i', '', $html );
        $html = preg_replace( '/<\/td>/i', ' | ', $html );

        // Строки таблицы — каждая на новой строке
        $html = preg_replace( '/<tr[^>]*>/i', '', $html );
        $html = preg_replace( '/<\/tr>/i', "\n", $html );

        // <table>, <thead>, <tbody>, <tfoot> — просто удаляем теги
        $html = preg_replace( '/<\/?(table|thead|tbody|tfoot|caption)[^>]*>/i', "\n", $html );

        // <li> → маркированный список
        $html = preg_replace( '/<li[^>]*>/i', "\n• ", $html );
        $html = preg_replace( '/<\/li>/i', '', $html );
        $html = preg_replace( '/<\/?(ul|ol)[^>]*>/i', "\n", $html );

        // <div> — полностью убираем теги (WooCommerce использует сотни вложенных div для вёрстки)
        $html = preg_replace( '/<\/?div[^>]*>/i', '', $html );

        // <section>, <article> и другие блочные элементы
        $html = preg_replace( '/<\/?(?:section|article|header|footer|main|aside|nav|blockquote|center)[^>]*>/i', "\n", $html );

        // Горизонтальная линия — короткий разделитель
        $html = preg_replace( '/<hr[^>]*\/?>/i', "\n" . str_repeat( '-', 20 ) . "\n", $html );

        // Ссылки — оставляем только текст
        $html = preg_replace( '/<a[^>]*>(.*?)<\/a>/is', '$1', $html );

        // Удаляем все оставшиеся HTML-теги
        $html = wp_strip_all_tags( $html );

        // Повторное декодирование сущностей после удаления тегов
        $html = html_entity_decode( $html, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

        // Удаляем управляющие символы (кроме \n, \r, \t), включая нулевые байты
        $html = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $html );

        // Удаляем символы NBSP и другие невидимые пробелы
        $html = str_replace( "\xc2\xa0", ' ', $html );

        // Нормализация горизонтальных пробелов: табы и множественные пробелы → один пробел
        $html = preg_replace( '/[^\S\n]+/', ' ', $html );

        // Убираем пробелы в начале и конце каждой строки
        $html = preg_replace( '/^ +| +$/m', '', $html );

        // Убираем висячие разделители | в конце строк таблицы
        $html = preg_replace( '/ \|\s*(\n|$)/m', "\n", $html );

        // Удаляем строки, состоящие только из | и пробелов
        $html = preg_replace( '/^\s*\|\s*$/m', '', $html );

        // Сжимаем 3+ подряд пустых строки до 2
        $html = preg_replace( '/\n{3,}/', "\n\n", $html );

        return trim( $html );
    }

    /**
     * Получить кнопки уведомлений из настроек.
     */
    private function get_notify_buttons( $settings ) {
        $buttons = isset( $settings['notify_buttons'] ) ? (array) $settings['notify_buttons'] : array();
        return array_values( array_filter( $buttons, function( $b ) {
            return ! empty( $b['text'] ) && ! empty( $b['url'] );
        } ) );
    }

    public function intercept_email( $args ) {
        $settings = get_option( 'wp_ru_max_settings', array() );

        if ( empty( $settings['notifications_enabled'] ) ) {
            return $args;
        }

        $to          = isset( $args['to'] ) ? $args['to'] : '';
        $subject     = isset( $args['subject'] ) ? $args['subject'] : '';
        $message     = isset( $args['message'] ) ? $args['message'] : '';
        $notify_from = isset( $settings['notify_from_email'] ) ? trim( $settings['notify_from_email'] ) : 'any';
        $chat_ids    = isset( $settings['notify_chat_ids'] ) ? (array) $settings['notify_chat_ids'] : array();
        $template    = isset( $settings['notify_template'] ) ? $settings['notify_template'] : "<b>{email_subject}</b>\n{email_message}";
        $format      = isset( $settings['notify_format'] ) ? $settings['notify_format'] : 'html';
        $buttons     = $this->get_notify_buttons( $settings );

        // Нормализуем получателя письма
        $to_str = is_array( $to ) ? implode( ', ', $to ) : (string) $to;

        // Если нет chat_ids — логируем и выходим
        $chat_ids_clean = array_filter( array_map( 'trim', $chat_ids ) );
        if ( empty( $chat_ids_clean ) ) {
            WP_Ru_Max_Logger::log( 'notifications', 'warning',
                "Письмо перехвачено («{$subject}»), но список чатов пуст — добавьте ID чата в настройках «Личные уведомления».",
                array( 'to' => $to_str, 'subject' => $subject )
            );
            return $args;
        }

        // Проверяем фильтр по адресу получателя
        if ( $notify_from !== 'any' && ! empty( $notify_from ) ) {
            $to_emails = is_array( $to ) ? $to : array_map( 'trim', explode( ',', $to ) );
            $allowed   = array_map( 'trim', explode( ',', $notify_from ) );

            // Извлекаем чистые email из строк вида "Имя <email@domain.ru>"
            $to_clean = array();
            foreach ( $to_emails as $addr ) {
                if ( preg_match( '/<([^>]+)>/', $addr, $m ) ) {
                    $to_clean[] = strtolower( trim( $m[1] ) );
                } else {
                    $to_clean[] = strtolower( trim( $addr ) );
                }
            }
            $allowed_clean = array_map( 'strtolower', $allowed );

            $matched = false;
            foreach ( $to_clean as $email ) {
                if ( in_array( $email, $allowed_clean, true ) ) {
                    $matched = true;
                    break;
                }
            }

            if ( ! $matched ) {
                WP_Ru_Max_Logger::log( 'notifications', 'info',
                    "Письмо «{$subject}» пропущено — получатель ({$to_str}) не совпадает с фильтром «{$notify_from}».",
                    array( 'to' => $to_str, 'filter' => $notify_from )
                );
                return $args;
            }
        }

        // Конвертируем HTML-письмо в чистый текст
        $clean_message = $this->html_to_text( $message );

        $text = str_replace(
            array( '{email_subject}', '{email_message}' ),
            array( $subject, $clean_message ),
            $template
        );

        $api = new WP_Ru_Max_API();
        foreach ( $chat_ids_clean as $chat_id ) {
            $result = $api->send_message( $chat_id, $text, $format, $buttons );

            if ( is_wp_error( $result ) ) {
                WP_Ru_Max_Logger::log( 'notifications', 'error',
                    "Ошибка отправки уведомления в {$chat_id}: " . $result->get_error_message(),
                    array(
                        'chat_id'      => $chat_id,
                        'subject'      => $subject,
                        'text_length'  => mb_strlen( $text, 'UTF-8' ),
                        'text_preview' => mb_substr( $text, 0, 200, 'UTF-8' ),
                    )
                );
            } else {
                WP_Ru_Max_Logger::log( 'notifications', 'success',
                    "Уведомление отправлено в {$chat_id}. Тема: {$subject}",
                    array( 'chat_id' => $chat_id, 'subject' => $subject )
                );
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
