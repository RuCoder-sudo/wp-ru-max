<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WP_Ru_Max_Notifications {

    private static $instance = null;

    /**
     * Данные текущего WooCommerce-письма, заполняемые хуком woocommerce_email_before_send_mail.
     * Сбрасываются после каждого вызова intercept_email.
     */
    private static $current_woo_order = null;

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $settings = get_option( 'wp_ru_max_settings', array() );
        if ( ! empty( $settings['notifications_enabled'] ) ) {
            // Перехватываем письма через стандартный фильтр wp_mail.
            // Совместимо с WP Mail SMTP, FluentSMTP, Postman SMTP и другими плагинами-почтовиками:
            // они заменяют транспорт через phpmailer_init/PHPMailer, но фильтр wp_mail
            // всегда срабатывает до отправки.
            add_filter( 'wp_mail', array( $this, 'intercept_email' ), 5, 1 );

            // WooCommerce: перехватываем данные заказа ДО отправки письма,
            // чтобы знать order_id и статус внутри wp_mail фильтра.
            add_action( 'woocommerce_email_before_send_mail', array( $this, 'capture_woo_order_info' ), 1, 1 );

            // Уведомления об обновлении плагинов и ядра WordPress
            if ( ! empty( $settings['notify_plugin_updates'] ) ) {
                add_action( 'upgrader_process_complete', array( $this, 'notify_plugin_update' ), 10, 2 );
            }

            // Уведомления о критических ошибках PHP
            if ( ! empty( $settings['notify_site_errors'] ) ) {
                add_action( 'shutdown', array( $this, 'notify_site_error' ) );
            }
        }
    }

    /**
     * Перехватывает данные WooCommerce-письма перед его отправкой.
     * Вызывается хуком woocommerce_email_before_send_mail.
     *
     * @param WC_Email $email Объект WooCommerce письма.
     */
    public function capture_woo_order_info( $email ) {
        if ( ! isset( $email->object ) ) {
            return;
        }

        // Поддержка WC_Order и любых его наследников
        if ( ! ( $email->object instanceof WC_Abstract_Order ) &&
             ! ( $email->object instanceof WC_Order ) ) {
            // fallback: проверяем через интерфейс
            if ( ! method_exists( $email->object, 'get_id' ) || ! method_exists( $email->object, 'get_status' ) ) {
                return;
            }
        }

        $order_id = (int) $email->object->get_id();
        if ( ! $order_id ) {
            return;
        }

        $status   = (string) $email->object->get_status();
        $email_id = isset( $email->id ) ? (string) $email->id : '';

        self::$current_woo_order = array(
            'order_id' => $order_id,
            'status'   => $status,
            'email_id' => $email_id,
        );
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

    /**
     * Экранирует email-адреса в тексте, заменяя @ на [at],
     * чтобы MAX не превращал их в кликабельные ссылки mailto:.
     * Поддерживает уведомления Jetpack Contact Form и любые другие.
     */
    private function escape_emails_for_max( $text ) {
        return preg_replace_callback(
            '/[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}/',
            function ( $m ) {
                return str_replace( '@', '[at]', $m[0] );
            },
            $text
        );
    }

    /**
     * Общая защита от дублей уведомлений по номеру (#ID) в теме письма.
     * Работает для MPHB, YClients, Bookly и любых других плагинов бронирования.
     * Извлекает первый #NNNN из темы письма и блокирует повторную отправку того же ID.
     *
     * @param string $subject Тема письма.
     * @param int    $ttl     Время жизни защиты от дублей в секундах (по умолчанию 30).
     * @return bool true — отправить, false — дубль, пропустить.
     */
    private function general_dedup_check( $subject, $ttl = 30 ) {
        if ( ! preg_match( '/#(\d+)/', $subject, $m ) ) {
            return true; // Нет номера в теме — пропускаем без дедупликации
        }
        $key = 'wp_ru_max_gdedup_' . $m[1];
        if ( get_transient( $key ) ) {
            return false;
        }
        set_transient( $key, 1, $ttl );
        return true;
    }

    /**
     * Проверяет и регистрирует дедупликацию WooCommerce-уведомления.
     * Возвращает true, если уведомление нужно отправить.
     * Возвращает false, если это дубль и отправку нужно пропустить.
     *
     * @param int    $order_id  ID заказа.
     * @param string $status    Статус заказа (без префикса 'wc-').
     * @param int    $ttl       Время жизни защиты от дублей в секундах.
     */
    private function woo_dedup_check( $order_id, $status, $ttl = 60 ) {
        $key = 'wp_ru_max_woo_dedup_' . $order_id . '_' . $status;
        if ( get_transient( $key ) ) {
            return false;
        }
        set_transient( $key, 1, $ttl );
        return true;
    }

    public function intercept_email( $args ) {
        $settings = get_option( 'wp_ru_max_settings', array() );

        if ( empty( $settings['notifications_enabled'] ) ) {
            self::$current_woo_order = null;
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

        // ── WooCommerce: фильтр по статусу и защита от дублей ────────────────
        $woo_info = self::$current_woo_order;
        self::$current_woo_order = null; // сбрасываем сразу

        if ( ! empty( $settings['woo_filter_enabled'] ) && ! empty( $woo_info ) ) {
            $order_id = $woo_info['order_id'];
            // WooCommerce хранит статус с префиксом 'wc-' в БД, но get_status() возвращает без 'wc-'
            $status   = ltrim( $woo_info['status'], 'wc-' );

            // Фильтр по статусам
            $allowed_statuses = isset( $settings['woo_notify_statuses'] ) ? (array) $settings['woo_notify_statuses'] : array();
            if ( ! empty( $allowed_statuses ) ) {
                $allowed_clean = array_map( function( $s ) { return ltrim( $s, 'wc-' ); }, $allowed_statuses );
                if ( ! in_array( $status, $allowed_clean, true ) ) {
                    WP_Ru_Max_Logger::log( 'notifications', 'info',
                        "WooCommerce заказ #{$order_id} (статус: {$status}) пропущен — статус не входит в список уведомлений.",
                        array( 'order_id' => $order_id, 'status' => $status, 'allowed' => $allowed_clean )
                    );
                    return $args;
                }
            }

            // Защита от дублей: одно уведомление по одному заказу+статус за 60 секунд
            $dedup_ttl = isset( $settings['woo_dedup_ttl'] ) ? max( 10, intval( $settings['woo_dedup_ttl'] ) ) : 60;
            if ( ! $this->woo_dedup_check( $order_id, $status, $dedup_ttl ) ) {
                WP_Ru_Max_Logger::log( 'notifications', 'info',
                    "WooCommerce заказ #{$order_id} (статус: {$status}) — дубль уведомления пропущен (защита от спама).",
                    array( 'order_id' => $order_id, 'status' => $status )
                );
                return $args;
            }
        }
        // ─────────────────────────────────────────────────────────────────────

        // ── Общая защита от дублей для плагинов бронирования (MPHB, Bookly и др.) ──
        // Работает только для не-WooCommerce писем, содержащих номер #ID в теме.
        // Предотвращает дублирование, когда плагин отправляет 2 письма по одному бронированию.
        if ( empty( $woo_info ) && ! empty( $settings['general_dedup_enabled'] ) ) {
            $general_ttl = isset( $settings['general_dedup_ttl'] ) ? max( 5, intval( $settings['general_dedup_ttl'] ) ) : 30;
            if ( ! $this->general_dedup_check( $subject, $general_ttl ) ) {
                WP_Ru_Max_Logger::log( 'notifications', 'info',
                    "Дубль уведомления пропущен — общая защита от дублей (тема: «{$subject}»).",
                    array( 'subject' => $subject )
                );
                return $args;
            }
        }
        // ─────────────────────────────────────────────────────────────────────

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

        // Экранируем email-адреса: MAX автоматически делает их кликабельными ссылками
        // (актуально для уведомлений Jetpack Contact Form и любых других форм)
        $clean_message = $this->escape_emails_for_max( $clean_message );

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

    /**
     * Отправляет уведомление в MAX при обновлении плагинов или ядра WordPress.
     * Вызывается хуком upgrader_process_complete.
     */
    public function notify_plugin_update( $upgrader, $hook_extra ) {
        if ( empty( $hook_extra['action'] ) || $hook_extra['action'] !== 'update' ) {
            return;
        }

        $settings = get_option( 'wp_ru_max_settings', array() );
        $chat_ids = isset( $settings['notify_chat_ids'] )
            ? array_filter( array_map( 'trim', (array) $settings['notify_chat_ids'] ) )
            : array();

        if ( empty( $chat_ids ) ) {
            return;
        }

        $type = isset( $hook_extra['type'] ) ? $hook_extra['type'] : '';
        $site = get_bloginfo( 'name' );

        if ( $type === 'core' ) {
            global $wp_version;
            $ver  = isset( $wp_version ) ? $wp_version : '—';
            $text = "<b>WordPress обновлён</b>\n\nСайт: {$site}\nВерсия WordPress: {$ver}";

        } elseif ( $type === 'plugin' ) {
            $slugs = isset( $hook_extra['plugins'] ) ? (array) $hook_extra['plugins'] : array();
            if ( empty( $slugs ) ) {
                return;
            }
            if ( ! function_exists( 'get_plugin_data' ) ) {
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
            }
            $names = array();
            foreach ( $slugs as $slug ) {
                $path = WP_PLUGIN_DIR . '/' . $slug;
                $data = file_exists( $path ) ? get_plugin_data( $path, false, false ) : array();
                $names[] = ! empty( $data['Name'] ) ? $data['Name'] : $slug;
            }
            $text = "<b>Плагины обновлены</b>\n\nСайт: {$site}\nПлагины:\n— " . implode( "\n— ", $names );

        } else {
            return;
        }

        $api = new WP_Ru_Max_API();
        foreach ( $chat_ids as $chat_id ) {
            $result = $api->send_message( $chat_id, $text, 'html' );
            if ( is_wp_error( $result ) ) {
                WP_Ru_Max_Logger::log( 'notifications', 'error',
                    'Ошибка уведомления об обновлении → ' . $chat_id . ': ' . $result->get_error_message() );
            } else {
                WP_Ru_Max_Logger::log( 'notifications', 'success',
                    'Уведомление об обновлении отправлено → ' . $chat_id );
            }
        }
    }

    /**
     * Отправляет уведомление в MAX при критической PHP-ошибке.
     * Вызывается хуком shutdown.
     */
    public function notify_site_error() {
        $error = error_get_last();
        if ( ! $error ) {
            return;
        }

        $fatal_types = array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR );
        if ( ! in_array( $error['type'], $fatal_types, true ) ) {
            return;
        }

        // Не чаще 1 раза в 5 минут, чтобы не спамить при повторяющихся ошибках
        $lock = 'wp_ru_max_error_notified';
        if ( get_transient( $lock ) ) {
            return;
        }
        set_transient( $lock, 1, 5 * MINUTE_IN_SECONDS );

        $settings = get_option( 'wp_ru_max_settings', array() );
        $chat_ids = isset( $settings['notify_chat_ids'] )
            ? array_filter( array_map( 'trim', (array) $settings['notify_chat_ids'] ) )
            : array();

        if ( empty( $chat_ids ) ) {
            return;
        }

        $site = get_bloginfo( 'name' );
        $msg  = mb_substr( $error['message'], 0, 300, 'UTF-8' );
        $file = basename( $error['file'] );
        $text = "<b>Критическая ошибка сайта!</b>\n\nСайт: {$site}\nОшибка: {$msg}\nФайл: {$file}, строка {$error['line']}";

        $api = new WP_Ru_Max_API();
        foreach ( $chat_ids as $chat_id ) {
            $api->send_message( $chat_id, $text, 'html' );
        }

        WP_Ru_Max_Logger::log( 'notifications', 'error',
            "Критическая ошибка — уведомление отправлено. {$msg}" );
    }
}
