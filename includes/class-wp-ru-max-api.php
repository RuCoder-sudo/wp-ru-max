<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WP_Ru_Max_API {

    private $token;

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
     * Для строк текста сообщений также обрезает до 4096 символов (лимит MAX API).
     */
    private function sanitize_utf8( $value, $truncate = false ) {
        if ( is_string( $value ) ) {
            // Заменяем невалидные UTF-8 байты — сначала принудительно конвертируем
            $value = @mb_convert_encoding( $value, 'UTF-8', 'UTF-8' );
            // Удаляем управляющие символы кроме \t, \n, \r
            $value = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', (string) $value );
            // Если preg_replace вернул null (невалидный UTF-8), очищаем через iconv
            if ( null === $value ) {
                $value = iconv( 'UTF-8', 'UTF-8//IGNORE', (string) $value );
                if ( false === $value ) {
                    $value = '';
                }
            }
            // Обрезаем до 4096 символов для поля text (MAX API limit)
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
            'timeout' => 15,
        );

        if ( $body ) {
            $body_clean = $this->sanitize_utf8( $body );
            // Используем JSON_UNESCAPED_UNICODE чтобы emoji и кириллица
            // кодировались как UTF-8 байты, а не суррогатные пары (\uD83D\uDD14).
            // Строгие JSON-парсеры (Go, Rust) отвергают суррогатные пары — отсюда "Can't deserialize body".
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

    public function get_me() {
        return $this->request( 'GET', '/me' );
    }

    /**
     * Построить массив inline-клавиатуры из массива кнопок.
     * Каждая кнопка — ['text' => '...', 'url' => '...'].
     * Каждая кнопка размещается в отдельной строке клавиатуры.
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
                    'type'    => 'link',
                    'text'    => $text,
                    'url'     => $url,
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
     *
     * @param string $chat_id
     * @param string $text
     * @param string $format  'html' | 'markdown' | 'none'
     * @param array  $buttons Массив кнопок: [['text'=>'...','url'=>'...'], ...]
     */
    public function send_message( $chat_id, $text, $format = 'html', $buttons = array() ) {
        // Очищаем текст и обрезаем до лимита MAX API (4096 символов)
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
     * Отправить сообщение с изображением.
     *
     * @param string $chat_id
     * @param string $text
     * @param string $image_url
     * @param string $format
     * @param array  $buttons
     */
    public function send_message_with_image( $chat_id, $text, $image_url, $format = 'html', $buttons = array() ) {
        // Очищаем текст и обрезаем до лимита MAX API (4096 символов)
        $text = $this->sanitize_utf8( $text, true );

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
            return $this->send_message( $chat_id, $text, $format, $buttons );
        }

        return $result;
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
