<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WP_Ru_Max_API {

    private $token;

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

    /**
     * Универсальный метод для JSON-запросов к MAX API.
     */
    private function request( $method, $endpoint, $body = null ) {
        if ( empty( $this->token ) ) {
            return new WP_Error( 'no_token', 'Токен бота не задан.' );
        }

        $url  = WP_RU_MAX_API_BASE . $endpoint;
        $args = array(
            'method'    => $method,
            'headers'   => array(
                'Authorization' => $this->token,
                'Content-Type'  => 'application/json',
            ),
            'timeout'   => 20,
            // platform-api2.max.ru использует сертификат Минцифры России,
            // который не входит в стандартный CA-bundle WordPress/cURL.
            // sslverify отключён для корректной работы с новым адресом API.
            'sslverify' => false,
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
                'request'      => $body,
                'response_raw' => $body_raw,
                'response'     => $data,
            ) );
        }

        if ( $http_code !== 200 ) {
            $error_msg = isset( $data['message'] ) ? $data['message'] : "HTTP $http_code";
            WP_Ru_Max_Logger::log( 'api', 'error', "[$method $endpoint] Ошибка HTTP $http_code: $error_msg", array(
                'endpoint'     => $endpoint,
                'http_code'    => $http_code,
                'response_raw' => $body_raw,
                'response'     => $data,
            ) );
            return new WP_Error( 'api_error', $error_msg, array( 'http_code' => $http_code, 'body' => $data ) );
        }

        return $data;
    }

    /**
     * Загружает файл через multipart/form-data напрямую на MAX API endpoint.
     * Возвращает распарсенный массив ответа или WP_Error.
     */
    private function request_multipart( $endpoint, $file_data, $filename, $content_type ) {
        if ( empty( $this->token ) ) {
            return new WP_Error( 'no_token', 'Токен бота не задан.' );
        }

        $url      = WP_RU_MAX_API_BASE . $endpoint;
        $boundary = '----WPRuMaxBoundary' . md5( microtime() );

        // Важно: MAX API принимает файл в multipart-поле "data", а не "file".
        // Поле с другим именем сервер молча игнорирует, из-за чего ответ выглядит
        // так, будто загрузка "прошла", но токен для вложения не возвращается.
        $multipart  = "--{$boundary}\r\n";
        $multipart .= "Content-Disposition: form-data; name=\"data\"; filename=\"{$filename}\"\r\n";
        $multipart .= "Content-Type: {$content_type}\r\n\r\n";
        $multipart .= $file_data . "\r\n";
        $multipart .= "--{$boundary}--\r\n";

        $response = wp_remote_post( $url, array(
            'timeout'   => 60,
            'sslverify' => false,
            'headers'   => array(
                'Authorization' => $this->token,
                'Content-Type'  => 'multipart/form-data; boundary=' . $boundary,
            ),
            'body' => $multipart,
        ) );

        if ( is_wp_error( $response ) ) {
            WP_Ru_Max_Logger::log( 'api', 'error', "[MULTIPART POST $endpoint] HTTP Error: " . $response->get_error_message(), array(
                'endpoint' => $endpoint,
                'filename' => $filename,
                'token'    => $this->mask_token(),
            ) );
            return $response;
        }

        $http_code = wp_remote_retrieve_response_code( $response );
        $body_raw  = wp_remote_retrieve_body( $response );
        $data      = json_decode( $body_raw, true );

        WP_Ru_Max_Logger::log( 'api', $http_code === 200 ? 'success' : 'error', "[MULTIPART POST $endpoint] HTTP $http_code", array(
            'filename'     => $filename,
            'content_type' => $content_type,
            'http_code'    => $http_code,
            'response_raw' => $body_raw,
            'response'     => $data,
        ) );

        if ( $http_code !== 200 ) {
            $error_msg = isset( $data['message'] ) ? $data['message'] : "HTTP $http_code: $body_raw";
            return new WP_Error( 'multipart_error', $error_msg, array( 'http_code' => $http_code, 'body' => $data ) );
        }

        return $data;
    }

    /**
     * Извлекает token загрузки из ответа MAX API.
     * Для изображений реальный формат ответа — вложенный:
     *   {"photos": {"<hash>": {"token": "..."}}}
     * Но на всякий случай также проверяем плоские варианты token/fileId,
     * которые встречаются для других типов вложений.
     */
    private function extract_upload_token( $data ) {
        if ( ! is_array( $data ) ) {
            return null;
        }
        if ( ! empty( $data['token'] ) ) {
            return $data['token'];
        }
        if ( ! empty( $data['fileId'] ) ) {
            return $data['fileId'];
        }
        if ( ! empty( $data['photos'] ) && is_array( $data['photos'] ) ) {
            foreach ( $data['photos'] as $photo ) {
                if ( is_array( $photo ) && ! empty( $photo['token'] ) ) {
                    return $photo['token'];
                }
            }
        }
        return null;
    }

    /**
     * Возвращает замаскированную версию токена для безопасного логирования.
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
     * Нормализует chat_id для MAX API.
     * MAX API иногда возвращает chat_id в формате "id{user_id}_{bot_id}_bot",
     * но при отправке принимает только числовой user_id.
     * Также поддерживает: числовые ID, @channel, -100123456789.
     */
    private function normalize_chat_id( $chat_id ) {
        $chat_id = trim( (string) $chat_id );

        // Формат "id7751383448_4_bot" → "7751383448"
        if ( preg_match( '/^id(\d+)_\d+_bot$/i', $chat_id, $m ) ) {
            return $m[1];
        }

        // Формат "id7751383448" → "7751383448"
        if ( preg_match( '/^id(\d+)$/i', $chat_id, $m ) ) {
            return $m[1];
        }

        return $chat_id;
    }

    public function send_message( $chat_id, $text, $format = 'html', $buttons = array() ) {
        $chat_id = $this->normalize_chat_id( $chat_id );
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
        $response = wp_remote_head( $image_url, array( 'timeout' => 8, 'sslverify' => false ) );
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
     * Скачивает изображение с WordPress и загружает его в MAX Upload API.
     * Возвращает token строкой или WP_Error при неудаче.
     *
     * Официальный порядок (см. dev.max.ru/docs-api/methods/POST/uploads):
     *   1. POST /uploads?type=image (без файла) → получить одноразовый upload URL
     *   2. multipart POST файла (поле "data") на этот upload URL → получить token
     * Прямая загрузка файла в теле шага 1 не поддерживается API и раньше
     * ошибочно использовалась как "Метод A" — из-за этого сервер лишь эхом
     * возвращал upload URL, а токен никогда не находился.
     */
    public function upload_image_binary( $image_url ) {
        WP_Ru_Max_Logger::log( 'api', 'info', 'Начало загрузки изображения в MAX.', array(
            'image_url' => $image_url,
        ) );

        // Шаг 1: Скачать изображение с сайта WordPress
        $image_response = wp_remote_get( $image_url, array(
            'timeout'   => 30,
            'sslverify' => false,
        ) );

        if ( is_wp_error( $image_response ) ) {
            $err = 'Не удалось скачать изображение: ' . $image_response->get_error_message();
            WP_Ru_Max_Logger::log( 'api', 'error', $err, array( 'image_url' => $image_url ) );
            return new WP_Error( 'download_failed', $err );
        }

        $image_code = wp_remote_retrieve_response_code( $image_response );
        $image_body = wp_remote_retrieve_body( $image_response );

        if ( $image_code !== 200 || empty( $image_body ) ) {
            $err = "Ошибка скачивания изображения (HTTP {$image_code}). URL: {$image_url}";
            WP_Ru_Max_Logger::log( 'api', 'error', $err );
            return new WP_Error( 'download_failed', $err );
        }

        WP_Ru_Max_Logger::log( 'api', 'info', 'Изображение скачано успешно.', array(
            'size_bytes' => strlen( $image_body ),
            'http_code'  => $image_code,
        ) );

        // Определить тип файла
        $content_type = wp_remote_retrieve_header( $image_response, 'content-type' );
        $content_type = $content_type ? strtok( $content_type, ';' ) : '';
        if ( ! $content_type || strpos( $content_type, 'image/' ) === false ) {
            $ext          = strtolower( pathinfo( parse_url( $image_url, PHP_URL_PATH ), PATHINFO_EXTENSION ) );
            $ext_map      = array( 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp' );
            $content_type = isset( $ext_map[ $ext ] ) ? $ext_map[ $ext ] : 'image/jpeg';
        }

        $filename = basename( parse_url( $image_url, PHP_URL_PATH ) ) ?: 'image.jpg';
        // Убедиться что имя файла без лишних параметров
        $filename = preg_replace( '/[^a-zA-Z0-9._-]/', '_', $filename );
        if ( empty( $filename ) ) {
            $filename = 'image.jpg';
        }

        WP_Ru_Max_Logger::log( 'api', 'info', 'Метаданные изображения определены.', array(
            'filename'     => $filename,
            'content_type' => $content_type,
        ) );

        // Шаг 1: запросить одноразовый upload URL
        WP_Ru_Max_Logger::log( 'api', 'info', 'Шаг 1: POST /uploads?type=image для получения upload URL.' );

        $upload_info = $this->request( 'POST', '/uploads?type=image' );

        if ( is_wp_error( $upload_info ) ) {
            $err = 'Не удалось получить upload URL: ' . $upload_info->get_error_message();
            WP_Ru_Max_Logger::log( 'api', 'error', $err );
            return new WP_Error( 'upload_url_failed', $err );
        }

        $upload_url = isset( $upload_info['url'] ) ? $upload_info['url'] : null;

        if ( ! $upload_url ) {
            $err = 'upload URL отсутствует в ответе /uploads.';
            WP_Ru_Max_Logger::log( 'api', 'error', $err, array( 'response' => $upload_info ) );
            return new WP_Error( 'upload_url_missing', $err );
        }

        // Шаг 2: загрузить файл на полученный upload URL (поле "data")
        $boundary = '----WPRuMaxBoundary' . md5( microtime() );

        $multipart  = "--{$boundary}\r\n";
        $multipart .= "Content-Disposition: form-data; name=\"data\"; filename=\"{$filename}\"\r\n";
        $multipart .= "Content-Type: {$content_type}\r\n\r\n";
        $multipart .= $image_body . "\r\n";
        $multipart .= "--{$boundary}--\r\n";

        $upload_response = wp_remote_post( $upload_url, array(
            'timeout'   => 60,
            'sslverify' => false,
            'headers'   => array(
                'Content-Type' => 'multipart/form-data; boundary=' . $boundary,
            ),
            'body' => $multipart,
        ) );

        if ( is_wp_error( $upload_response ) ) {
            $err = 'Ошибка загрузки на upload URL: ' . $upload_response->get_error_message();
            WP_Ru_Max_Logger::log( 'api', 'error', $err );
            return new WP_Error( 'upload_failed', $err );
        }

        $upload_code = wp_remote_retrieve_response_code( $upload_response );
        $upload_raw  = wp_remote_retrieve_body( $upload_response );
        $upload_data = json_decode( $upload_raw, true );

        WP_Ru_Max_Logger::log( 'api', $upload_code === 200 ? 'info' : 'warning', "Загрузка на upload URL завершена (HTTP {$upload_code}).", array(
            'http_code'    => $upload_code,
            'response_raw' => $upload_raw,
            'response'     => $upload_data,
        ) );

        if ( $upload_code === 200 ) {
            $token = $this->extract_upload_token( $upload_data );

            if ( $token ) {
                WP_Ru_Max_Logger::log( 'api', 'success', 'Изображение загружено успешно.', array(
                    'token' => substr( (string) $token, 0, 20 ) . '...',
                ) );
                return (string) $token;
            }

            WP_Ru_Max_Logger::log( 'api', 'warning', 'Загрузка прошла, но token не найден в ответе.', array(
                'response' => $upload_data,
            ) );
        }

        $final_err = 'Загрузка изображения не удалась. Проверьте журнал для деталей.';
        WP_Ru_Max_Logger::log( 'api', 'error', $final_err );
        return new WP_Error( 'upload_all_failed', $final_err );
    }

    /**
     * Отправить сообщение с изображением.
     * Порядок попыток:
     *   1. Бинарная загрузка (upload_image_binary) — token → attachment
     *   2. Передача по URL напрямую в attachment
     *   3. Отправка только текста (без изображения)
     */
    public function send_message_with_image( $chat_id, $text, $image_url, $format = 'html', $buttons = array() ) {
        $chat_id = $this->normalize_chat_id( $chat_id );
        $text = $this->sanitize_utf8( $text, true );

        // Проверяем размер изображения
        $size_limit = $this->get_image_size_limit();
        $image_size = $this->check_image_size( $image_url );

        if ( $image_size !== false && $image_size > $size_limit ) {
            $size_mb  = round( $image_size / 1048576, 2 );
            $limit_mb = round( $size_limit / 1048576, 0 );
            WP_Ru_Max_Logger::log( 'api', 'warning', "Изображение ({$size_mb} МБ) превышает лимит ({$limit_mb} МБ) — отправка без изображения.", array(
                'url'         => $image_url,
                'size_bytes'  => $image_size,
                'limit_bytes' => $size_limit,
            ) );
            return $this->send_message( $chat_id, $text, $format, $buttons );
        }

        // Попытка 1: бинарная загрузка через MAX Upload API
        $token = $this->upload_image_binary( $image_url );

        if ( ! is_wp_error( $token ) ) {
            // MAX обрабатывает файл асинхронно после загрузки — при мгновенной
            // отправке сообщения может вернуться "attachment.not.ready".
            // Небольшая пауза перед первой попыткой снижает частоту этой ошибки.
            usleep( 700000 );

            $attachments = array(
                array(
                    'type'    => 'image',
                    'payload' => array( 'token' => $token ),
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

            $not_ready_retries = 0;
            do {
                $result = $this->request( 'POST', '/messages?chat_id=' . urlencode( $chat_id ), $payload );

                if ( ! is_wp_error( $result ) ) {
                    WP_Ru_Max_Logger::log( 'api', 'success', 'Сообщение с изображением (бинарный токен) отправлено успешно.' );
                    return $result;
                }

                $error_data = $result->get_error_data();
                $is_not_ready = is_array( $error_data ) && isset( $error_data['body']['code'] ) && $error_data['body']['code'] === 'attachment.not.ready';

                if ( $is_not_ready && $not_ready_retries < 3 ) {
                    $not_ready_retries++;
                    WP_Ru_Max_Logger::log( 'api', 'info', "Вложение ещё обрабатывается (attachment.not.ready), повтор через " . ( $not_ready_retries * 2 ) . " сек. (попытка {$not_ready_retries}/3)." );
                    sleep( $not_ready_retries * 2 );
                    continue;
                }

                break;
            } while ( true );

            WP_Ru_Max_Logger::log( 'api', 'warning', 'Отправка с токеном изображения не удалась: ' . $result->get_error_message() . ' — пробуем по URL.' );
        } else {
            WP_Ru_Max_Logger::log( 'api', 'warning', 'Бинарная загрузка не удалась (' . $token->get_error_message() . '), пробуем по URL.' );
        }

        // Попытка 2: старый способ — по URL (запасной вариант)
        WP_Ru_Max_Logger::log( 'api', 'info', 'Попытка отправки изображения по URL.', array(
            'image_url' => $image_url,
            'chat_id'   => $chat_id,
        ) );

        $attachments = array(
            array(
                'type'    => 'image',
                'payload' => array( 'url' => $image_url ),
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

        if ( ! is_wp_error( $result ) ) {
            WP_Ru_Max_Logger::log( 'api', 'success', 'Сообщение с изображением (по URL) отправлено успешно.' );
            return $result;
        }

        WP_Ru_Max_Logger::log( 'api', 'warning', 'Отправка изображения по URL не удалась: ' . $result->get_error_message() . ' — отправляем только текст.', array(
            'image_url' => $image_url,
        ) );

        // Попытка 3: только текст
        return $this->send_message( $chat_id, $text, $format, $buttons );
    }

    /**
     * Отправить сообщение с автоматическим повтором при ошибке.
     */
    public function send_with_retry( $chat_id, $text, $format = 'html', $buttons = array(), $image_url = false, $max_retries = 2, $retry_delay = 30 ) {
        $attempt    = 0;
        $last_error = null;

        while ( $attempt <= $max_retries ) {
            if ( $attempt > 0 ) {
                WP_Ru_Max_Logger::log( 'api', 'info', "Повтор отправки (попытка {$attempt} из {$max_retries}) в канал {$chat_id}." );
                sleep( min( $retry_delay, 10 ) );
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
