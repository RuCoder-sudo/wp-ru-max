<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WP_Ru_Max_API {

    private $token;

    // Лимит изображения в байтах (по умолчанию 5 МБ)
    const DEFAULT_IMAGE_SIZE_LIMIT = 5242880; // 5 * 1024 * 1024

    public function __construct( $token = null ) {
        if ( $token ) {
            $this->token = $token;
        } else {
            $settings = get_option( 'wp_ru_max_settings', array() );
            $this->token = isset( $settings['bot_token'] ) ? $settings['bot_token'] : '';
        }
    }

    /**
     * Рекурсивно очищает строки в массиве — удаляет невалидные UTF-8 последовательности
     * и символы управления, которые ломают json_encode.
     */
    private function sanitize_utf8( $value, $truncate = false ) {
        if ( is_string( $value ) ) {
            $value = @mb_convert_encoding( $value, 'UTF-8', 'UTF-8' );
            $value = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', (string) $value );
            if ( null === $value ) {
                $value = iconv( 'UTF-8', 'UTF-8//IGNORE', (string) $value );
                if ( false === $value ) {
                    $value = '';
                }
            }
            if ( $truncate && mb_strlen( $value, 'UTF-8' ) > 4096 ) {
                $value = mb_substr( $value, 0, 4090, 'UTF-8' ) . "\n...";
            }
            return $value;
        }
        if ( is_array( $value ) ) {
            return array_map( array( $this, 'sanitize_utf8' ), $value );
        }
        return $value;
    }

    private function request( $method, $endpoint, $body = null ) {
        if ( empty( $this->token ) ) {
            return new WP_Error( 'no_token', 'Токен бота не задан.' );
        }

        $url  = WP_RU_MAX_API_BASE . $endpoint;
        $args = array(
            'method'  => $method,
            'headers' => array(
                'Authorization' => $this->token,
                'Content-Type'  => 'application/json',
            ),
            'timeout' => 20,
        );

        if ( $body ) {
            $body_clean = $this->sanitize_utf8( $body );
            $json = json_encode( $body_clean, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

            if ( false === $json ) {
                return new WP_Error( 'json_encode', 'Не удалось закодировать тело запроса в JSON. Проверьте кодировку текста.' );
            }

            $args['body'] = $json;
        }

        $response = wp_remote_request( $url, $args );

        if ( is_wp_error( $response ) ) {
            WP_Ru_Max_Logger::log( 'api', 'error', 'HTTP Error: ' . $response->get_error_message(), array(
                'endpoint' => $endpoint,
                'method'   => $method,
                'token'    => $this->mask_token(),
            ) );
            return $response;
        }

        $http_code = wp_remote_retrieve_response_code( $response );
        $body_raw  = wp_remote_retrieve_body( $response );
        $data      = json_decode( $body_raw, true );

        $settings = get_option( 'wp_ru_max_settings', array() );
        if ( ! empty( $settings['enable_bot_api_log'] ) ) {
            WP_Ru_Max_Logger::log( 'api', $http_code === 200 ? 'success' : 'error', "[$method $endpoint] HTTP $http_code", array(
                'request'  => $body,
                'response' => $data,
            ) );
        }

        if ( $http_code !== 200 ) {
            $error_msg = isset( $data['message'] ) ? $data['message'] : "HTTP $http_code";
            return new WP_Error( 'api_error', $error_msg, array( 'http_code' => $http_code, 'body' => $data ) );
        }

        return $data;
    }

    /**
     * Возвращает замаскированную версию токена для безопасного логирования.
     * Пример: "001.ABCDEFGHIJ...UVWXYZ" → "001.ABC***XYZ"
     */
    private function mask_token() {
        if ( empty( $this->token ) ) {
            return '(не задан)';
        }
        $len = mb_strlen( $this->token );
        if ( $len <= 10 ) {
            return str_repeat( '*', $len );
        }
        return mb_substr( $this->token, 0, 6 ) . '***' . mb_substr( $this->token, -4 );
    }

    public function get_me() {
        // Кэшируем ответ на 5 минут для оптимизации производительности
        $cache_key = 'wp_ru_max_me_' . md5( $this->token );
        $cached    = get_transient( $cache_key );
        if ( false !== $cached ) {
            return $cached;
        }
        $result = $this->request( 'GET', '/me' );
        if ( ! is_wp_error( $result ) ) {
            set_transient( $cache_key, $result, 5 * MINUTE_IN_SECONDS );
        }
        return $result;
    }

    /**
     * Построить массив inline-клавиатуры из массива кнопок.
     */
    private function build_keyboard_attachment( $buttons ) {
        if ( empty( $buttons ) || ! is_array( $buttons ) ) {
            return null;
        }

        $rows = array();
        foreach ( $buttons as $btn ) {
            $text = isset( $btn['text'] ) ? trim( $btn['text'] ) : '';
            $url  = isset( $btn['url'] )  ? trim( $btn['url'] )  : '';
            if ( empty( $text ) || empty( $url ) ) {
                continue;
            }
            $rows[] = array(
                array(
                    'type' => 'link',
                    'text' => $text,
                    'url'  => $url,
                ),
            );
        }

        if ( empty( $rows ) ) {
            return null;
        }

        return array(
            'type'    => 'inline_keyboard',
            'payload' => array(
                'buttons' => $rows,
            ),
        );
    }

    /**
     * Отправить текстовое сообщение.
     */
    public function send_message( $chat_id, $text, $format = 'html', $buttons = array() ) {
        $text = $this->sanitize_utf8( $text, true );

        $payload = array(
            'text'   => $text,
            'format' => $format,
        );

        $keyboard = $this->build_keyboard_attachment( $buttons );
        if ( $keyboard ) {
            $payload['attachments'] = array( $keyboard );
        }

        return $this->request( 'POST', '/messages?chat_id=' . urlencode( $chat_id ), $payload );
    }

    /**
     * Проверяет размер изображения по URL.
     * Возвращает размер в байтах или false если не удалось определить.
     */
    public function check_image_size( $image_url ) {
        $response = wp_remote_head( $image_url, array( 'timeout' => 5 ) );
        if ( is_wp_error( $response ) ) {
            return false;
        }
        $content_length = wp_remote_retrieve_header( $response, 'content-length' );
        if ( $content_length !== '' && is_numeric( $content_length ) ) {
            return (int) $content_length;
        }
        return false;
    }

    /**
     * Получить лимит размера изображения из настроек.
     */
    public function get_image_size_limit() {
        $settings = get_option( 'wp_ru_max_settings', array() );
        $limit_mb = isset( $settings['image_size_limit_mb'] ) ? (int) $settings['image_size_limit_mb'] : 5;
        if ( $limit_mb <= 0 ) {
            $limit_mb = 5;
        }
        return $limit_mb * 1024 * 1024;
    }

    /**
     * Отправить сообщение с изображением.
     * Автоматически проверяет размер изображения и при превышении лимита
     * отправляет только текст.
     */
    public function send_message_with_image( $chat_id, $text, $image_url, $format = 'html', $buttons = array() ) {
        $text = $this->sanitize_utf8( $text, true );

        // Проверяем размер изображения
        $size_limit = $this->get_image_size_limit();
        $image_size = $this->check_image_size( $image_url );

        if ( $image_size !== false && $image_size > $size_limit ) {
            $size_mb = round( $image_size / 1048576, 2 );
            $limit_mb = round( $size_limit / 1048576, 0 );
            WP_Ru_Max_Logger::log( 'api', 'warning', "Изображение ({$size_mb} МБ) превышает лимит ({$limit_mb} МБ) — отправка без изображения.", array(
                'url'        => $image_url,
                'size_bytes' => $image_size,
                'limit_bytes'=> $size_limit,
            ) );
            return $this->send_message( $chat_id, $text, $format, $buttons );
        }

        $attachments = array(
            array(
                'type'    => 'image',
                'payload' => array(
                    'url' => $image_url,
                ),
            ),
        );

        $keyboard = $this->build_keyboard_attachment( $buttons );
        if ( $keyboard ) {
            $attachments[] = $keyboard;
        }

        $payload = array(
            'text'        => $text,
            'format'      => $format,
            'attachments' => $attachments,
        );

        $result = $this->request( 'POST', '/messages?chat_id=' . urlencode( $chat_id ), $payload );

        if ( is_wp_error( $result ) ) {
            // Фолбэк: отправка без изображения
            WP_Ru_Max_Logger::log( 'api', 'warning', 'Ошибка отправки с изображением, повторяем без изображения: ' . $result->get_error_message() );
            return $this->send_message( $chat_id, $text, $format, $buttons );
        }

        return $result;
    }

    /**
     * Отправить сообщение с автоматическим повтором при ошибке.
     *
     * @param string $chat_id
     * @param string $text
     * @param string $format
     * @param array  $buttons
     * @param string|false $image_url
     * @param int    $max_retries  Количество попыток повтора (0 = без повторов)
     * @param int    $retry_delay  Задержка между попытками в секундах
     */
    public function send_with_retry( $chat_id, $text, $format = 'html', $buttons = array(), $image_url = false, $max_retries = 2, $retry_delay = 30 ) {
        $attempt = 0;
        $last_error = null;

        while ( $attempt <= $max_retries ) {
            if ( $attempt > 0 ) {
                WP_Ru_Max_Logger::log( 'api', 'info', "Повтор отправки (попытка {$attempt} из {$max_retries}) в канал {$chat_id}." );
                sleep( min( $retry_delay, 10 ) ); // Максимум 10 сек синхронного ожидания
            }

            if ( $image_url ) {
                $result = $this->send_message_with_image( $chat_id, $text, $image_url, $format, $buttons );
            } else {
                $result = $this->send_message( $chat_id, $text, $format, $buttons );
            }

            if ( ! is_wp_error( $result ) ) {
                if ( $attempt > 0 ) {
                    WP_Ru_Max_Logger::log( 'api', 'success', "Отправка удалась с попытки {$attempt}." );
                }
                return $result;
            }

            $last_error = $result;
            $attempt++;
        }

        return $last_error;
    }

    public function test_connection( $token = null ) {
        if ( $token ) {
            $this->token = $token;
        }
        $result = $this->get_me();
        if ( is_wp_error( $result ) ) {
            WP_Ru_Max_Logger::log( 'api', 'error', 'Тест подключения НЕУДАЧЕН: ' . $result->get_error_message() );
            return array(
                'success' => false,
                'message' => $result->get_error_message(),
            );
        }
        $bot_name = isset( $result['first_name'] ) ? $result['first_name'] : 'Unknown';
        $username = isset( $result['username'] ) ? '@' . $result['username'] : '';
        WP_Ru_Max_Logger::log( 'api', 'success', "Тест подключения УСПЕШЕН. Бот: $bot_name $username" );
        return array(
            'success'  => true,
            'message'  => "Подключено! Бот: $bot_name $username",
            'bot_info' => $result,
        );
    }
}
