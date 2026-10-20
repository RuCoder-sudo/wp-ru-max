<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WP_Ru_Max_API {

    private $token;

    const DEFAULT_IMAGE_SIZE_LIMIT = 5242880; // 5 * 1024 * 1024
    const MAX_CA_BUNDLE = 'assets/max-russian-trusted-ca-bundle.pem';

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
            $value = wp_ru_max_utf8( $value );
            $original = (string) $value;
            $value    = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $original );
            if ( null === $value ) {
                // preg_replace вернул null (ошибка regex) — передаём оригинал, а не null.
                $value = function_exists( 'iconv' )
                    ? @iconv( 'UTF-8', 'UTF-8//IGNORE', $original )
                    : $original;
                if ( false === $value ) {
                    $value = $original;
                }
            }
            if ( $truncate && wp_ru_max_strlen( $value, 'UTF-8' ) > 4096 ) {
                $value = wp_ru_max_substr( $value, 0, 4090, 'UTF-8' ) . "\n...";
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
        $args = array_merge( array(
            'method'    => $method,
            'headers'   => array(
                'Authorization' => $this->token,
                'Content-Type'  => 'application/json',
            ),
            'timeout'   => 20,
            // Никогда не отключаем проверку сертификата: это позволяет
            // подменить ответы API и токеном отправлять данные не туда.
        ), $this->get_max_ssl_args( $url ) );

        if ( $body ) {
            $body_clean = $this->sanitize_utf8( $body );
            $json = wp_json_encode( $body_clean, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

            if ( false === $json ) {
                return new WP_Error( 'json_encode', 'Не удалось закодировать тело запроса в JSON. Проверьте кодировку текста.' );
            }

            $args['body'] = $json;
        }

        $response = wp_ru_max_remote_request_with_ssl_fallback( 'request', $url, $args );

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
        $is_success = $http_code >= 200 && $http_code < 300;

        $settings = get_option( 'wp_ru_max_settings', array() );
        if ( ! empty( $settings['enable_bot_api_log'] ) ) {
            WP_Ru_Max_Logger::log( 'api', $is_success ? 'success' : 'error', "[$method $endpoint] HTTP $http_code", array(
                'request'      => $body,
                'response_raw' => $body_raw,
                'response'     => $data,
            ) );
        }

        if ( ! $is_success ) {
            $error_msg = is_array( $data ) && isset( $data['message'] ) ? (string) $data['message'] : "HTTP $http_code";
            WP_Ru_Max_Logger::log( 'api', 'error', "[$method $endpoint] Ошибка HTTP $http_code: $error_msg", array(
                'endpoint'     => $endpoint,
                'http_code'    => $http_code,
                'response_raw' => $body_raw,
                'response'     => $data,
            ) );
            return new WP_Error( 'api_error', $error_msg, array( 'http_code' => $http_code, 'body' => $data ) );
        }

        if ( 204 === $http_code ) {
            return array();
        }

        if ( ! is_array( $data ) || JSON_ERROR_NONE !== json_last_error() ) {
            $json_error = function_exists( 'json_last_error_msg' ) ? json_last_error_msg() : 'некорректный JSON';
            return new WP_Error( 'invalid_api_response', 'MAX API вернул некорректный JSON: ' . $json_error, array(
                'http_code' => $http_code,
                'body'      => wp_ru_max_substr( $body_raw, 0, 500 ),
            ) );
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

        $response = wp_ru_max_remote_request_with_ssl_fallback( 'post', $url, array_merge( array(
            'timeout'   => 60,
            'headers'   => array(
                'Authorization' => $this->token,
                'Content-Type'  => 'multipart/form-data; boundary=' . $boundary,
            ),
            'body' => $multipart,
        ), $this->get_max_ssl_args( $url ) ) );

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
        $is_success = $http_code >= 200 && $http_code < 300;

        WP_Ru_Max_Logger::log( 'api', $is_success ? 'success' : 'error', "[MULTIPART POST $endpoint] HTTP $http_code", array(
            'filename'     => $filename,
            'content_type' => $content_type,
            'http_code'    => $http_code,
            'response_raw' => $body_raw,
            'response'     => $data,
        ) );

        if ( ! $is_success ) {
            $error_msg = is_array( $data ) && isset( $data['message'] ) ? (string) $data['message'] : "HTTP $http_code: $body_raw";
            return new WP_Error( 'multipart_error', $error_msg, array( 'http_code' => $http_code, 'body' => $data ) );
        }

        if ( ! is_array( $data ) || JSON_ERROR_NONE !== json_last_error() ) {
            $json_error = function_exists( 'json_last_error_msg' ) ? json_last_error_msg() : 'некорректный JSON';
            return new WP_Error( 'invalid_multipart_response', 'MAX API вернул некорректный JSON: ' . $json_error, array(
                'http_code' => $http_code,
                'body'      => wp_ru_max_substr( $body_raw, 0, 500 ),
            ) );
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
        $len = wp_ru_max_strlen( $this->token );
        if ( $len <= 10 ) {
            return str_repeat( '*', $len );
        }
        return wp_ru_max_substr( $this->token, 0, 6 ) . '***' . wp_ru_max_substr( $this->token, -4 );
    }

    /**
     * MAX uses the Russian Trusted CA chain. Keep peer verification enabled,
     * but provide the public CA bundle on hosts whose system bundle does not
     * include that chain. The bundle is used only for MAX API hosts.
     */
    private function get_max_ssl_args( $url ) {
        $args = array( 'sslverify' => true );
        $api_host = strtolower( (string) wp_parse_url( WP_RU_MAX_API_BASE, PHP_URL_HOST ) );
        $url_host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );

        $is_max_host = $api_host !== '' && $url_host === $api_host;
        $is_max_subdomain = strlen( $url_host ) > strlen( '.max.ru' )
            && substr( $url_host, -strlen( '.max.ru' ) ) === '.max.ru';

        if (
            ( $is_max_host || $is_max_subdomain )
            && defined( 'WP_RU_MAX_PLUGIN_DIR' )
        ) {
            $ca_file = trailingslashit( WP_RU_MAX_PLUGIN_DIR ) . self::MAX_CA_BUNDLE;
            if ( is_readable( $ca_file ) ) {
                $args['sslcertificates'] = $ca_file;
            }
        }

        return $args;
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
        if ( ! $this->validate_image_url( $image_url ) ) {
            return false;
        }

        $response = wp_remote_head( $image_url, array( 'timeout' => 8, 'sslverify' => true ) );
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
        $limit_mb = isset( $settings['image_size_limit_mb'] ) ? (float) $settings['image_size_limit_mb'] : 5.0;
        // 0 means use the upload pipeline's maximum supported source size.
        $limit_mb = $limit_mb <= 0 ? 50.0 : min( 50.0, max( 0.5, $limit_mb ) );
        return (int) round( $limit_mb * 1024 * 1024 );
    }

    /**
     * Produce a MAX-compatible binary variant when the source is oversized,
     * unusually large in pixels, or in a less portable format.
     *
     * @return array|WP_Error
     */
    private function prepare_max_image_binary( $body, $source_url, $size_limit ) {
        if ( ! function_exists( 'getimagesizefromstring' ) ) {
            return new WP_Error( 'image_inspector_missing', 'На сервере недоступна проверка типа изображения.' );
        }
        $info = @getimagesizefromstring( $body );
        if ( ! is_array( $info ) || empty( $info['mime'] ) || empty( $info[0] ) || empty( $info[1] ) ) {
            return new WP_Error( 'invalid_image', 'Полученный файл не является корректным изображением.' );
        }
        $mime = strtolower( (string) $info['mime'] );
        $allowed = array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp' );
        if ( ! in_array( $mime, $allowed, true ) ) {
            return new WP_Error( 'unsupported_image_type', 'Формат изображения не поддерживается. Используйте JPEG, PNG, GIF или WebP.' );
        }
        if ( (int) $info[0] < 32 || (int) $info[1] < 32 || ( (int) $info[0] * (int) $info[1] ) > 50000000 ) {
            return new WP_Error( 'invalid_image_dimensions', 'Размеры изображения некорректны или превышают безопасный предел обработки.' );
        }

        $needs_conversion = in_array( $mime, array( 'image/gif', 'image/webp' ), true );
        $needs_resize = (int) $info[0] > 1920 || (int) $info[1] > 1920 || strlen( $body ) > $size_limit;
        if ( ! $needs_conversion && ! $needs_resize ) {
            $path = (string) wp_parse_url( $source_url, PHP_URL_PATH );
            $name = sanitize_file_name( basename( $path ) );
            if ( '' === $name ) {
                $name = 'wp-ru-max-image.' . ( 'image/png' === $mime ? 'png' : 'jpg' );
            }
            return array( 'body' => $body, 'mime' => $mime, 'filename' => $name );
        }

        if ( ! function_exists( 'wp_get_image_editor' ) ) {
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }
        $source_file = wp_tempnam( 'wp-ru-max-source-image' );
        if ( ! $source_file || false === file_put_contents( $source_file, $body ) ) {
            if ( $source_file && file_exists( $source_file ) ) {
                wp_delete_file( $source_file );
            }
            return new WP_Error( 'image_temp_file_failed', 'Не удалось подготовить временный файл изображения.' );
        }

        $variant_file = '';
        $temporary_files = array();
        try {
            $editor = wp_get_image_editor( $source_file );
            if ( is_wp_error( $editor ) ) {
                return new WP_Error( 'image_editor_failed', 'Не удалось обработать изображение: ' . $editor->get_error_message() );
            }
            if ( (int) $info[0] > 1920 || (int) $info[1] > 1920 ) {
                $resize = $editor->resize( 1920, 1920, false );
                if ( is_wp_error( $resize ) ) {
                    return new WP_Error( 'image_resize_failed', 'Не удалось уменьшить изображение: ' . $resize->get_error_message() );
                }
            }
            $editor->set_quality( 84 );
            $variant_file = wp_tempnam( 'wp-ru-max-image.jpg' );
            if ( ! $variant_file ) {
                return new WP_Error( 'image_temp_file_failed', 'Не удалось создать подготовленную копию изображения.' );
            }
            $temporary_files[] = $variant_file;
            $saved = $editor->save( $variant_file, 'image/jpeg' );
            if ( is_wp_error( $saved ) ) {
                return new WP_Error( 'image_conversion_failed', 'Не удалось преобразовать изображение в JPEG: ' . $saved->get_error_message() );
            }
            $variant_file = $saved['path'] ?? $variant_file;
            if ( ! in_array( $variant_file, $temporary_files, true ) ) {
                $temporary_files[] = $variant_file;
            }
            $variant_size = (int) @filesize( $variant_file );
            if ( $variant_size > $size_limit ) {
                if ( (int) $info[0] > 1280 || (int) $info[1] > 1280 ) {
                    $resize = $editor->resize( 1280, 1280, false );
                    if ( is_wp_error( $resize ) ) {
                        return new WP_Error( 'image_resize_failed', 'Не удалось дополнительно уменьшить изображение.' );
                    }
                }
                $editor->set_quality( 70 );
                $saved = $editor->save( $variant_file, 'image/jpeg' );
                if ( is_wp_error( $saved ) ) {
                    return new WP_Error( 'image_conversion_failed', 'Не удалось сохранить уменьшенное JPEG-изображение.' );
                }
                $variant_file = $saved['path'] ?? $variant_file;
                if ( ! in_array( $variant_file, $temporary_files, true ) ) {
                    $temporary_files[] = $variant_file;
                }
                $variant_size = (int) @filesize( $variant_file );
            }
            if ( $variant_size <= 0 || $variant_size > $size_limit ) {
                return new WP_Error(
                    'image_too_large_after_processing',
                    sprintf( 'Изображение после оптимизации превышает лимит MAX в %s МБ.', round( $size_limit / 1048576, 1 ) )
                );
            }
            $variant_body = file_get_contents( $variant_file );
            if ( false === $variant_body ) {
                return new WP_Error( 'image_read_failed', 'Не удалось прочитать обработанную копию изображения.' );
            }
            return array(
                'body'     => $variant_body,
                'mime'     => 'image/jpeg',
                'filename' => 'wp-ru-max-image.jpg',
            );
        } finally {
            if ( file_exists( $source_file ) ) {
                wp_delete_file( $source_file );
            }
            foreach ( $temporary_files as $temporary_file ) {
                if ( file_exists( $temporary_file ) ) {
                    wp_delete_file( $temporary_file );
                }
            }
        }
    }

    /**
     * Validate a remote image URL using WordPress' built-in SSRF checks.
     */
    private function validate_image_url( $image_url ) {
        $image_url = esc_url_raw( (string) $image_url );
        if ( '' === $image_url || ! function_exists( 'wp_http_validate_url' ) ) {
            return false;
        }

        return (bool) wp_http_validate_url( $image_url );
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
        if ( ! $this->validate_image_url( $image_url ) ) {
            return new WP_Error( 'invalid_image_url', 'URL изображения недействителен или заблокирован политикой безопасности WordPress.' );
        }

        $size_limit = $this->get_image_size_limit();
        WP_Ru_Max_Logger::log( 'api', 'info', 'Начало загрузки изображения в MAX.', array(
            'image_url' => $image_url,
        ) );

        // Скачиваем файл с верхним техническим пределом, затем при
        // необходимости оптимизируем его до лимита, заданного в настройках.
        $image_response = wp_remote_get( $image_url, array(
            'timeout'             => 30,
            'sslverify'           => true,
            'limit_response_size' => 50 * 1024 * 1024 + 1,
        ) );

        if ( is_wp_error( $image_response ) ) {
            $err = 'Не удалось скачать изображение: ' . $image_response->get_error_message();
            WP_Ru_Max_Logger::log( 'api', 'error', $err, array( 'image_url' => $image_url ) );
            return new WP_Error( 'download_failed', $err );
        }

        $image_code = wp_remote_retrieve_response_code( $image_response );
        $image_body = wp_remote_retrieve_body( $image_response );

        if ( $image_code < 200 || $image_code >= 300 || empty( $image_body ) ) {
            $err = "Ошибка скачивания изображения (HTTP {$image_code}). URL: {$image_url}";
            WP_Ru_Max_Logger::log( 'api', 'error', $err );
            return new WP_Error( 'download_failed', $err );
        }

        $prepared = $this->prepare_max_image_binary( $image_body, $image_url, $size_limit );
        if ( is_wp_error( $prepared ) ) {
            WP_Ru_Max_Logger::log( 'api', 'error', $prepared->get_error_message(), array( 'image_url' => $image_url ) );
            return $prepared;
        }
        $image_body = $prepared['body'];
        $image_size = strlen( $image_body );
        $content_type = $prepared['mime'];
        $filename = $prepared['filename'];

        WP_Ru_Max_Logger::log( 'api', 'info', 'Изображение проверено и подготовлено для MAX.', array(
            'size_bytes' => $image_size,
            'http_code'  => $image_code,
            'content_type' => $content_type,
            'filename'   => $filename,
        ) );

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
        if ( ! $this->validate_image_url( $upload_url ) ) {
            return new WP_Error( 'invalid_upload_url', 'MAX API вернул небезопасный upload URL.' );
        }

        // Шаг 2: загрузить файл на полученный upload URL (поле "data")
        $boundary = '----WPRuMaxBoundary' . md5( microtime() );

        $multipart  = "--{$boundary}\r\n";
        $multipart .= "Content-Disposition: form-data; name=\"data\"; filename=\"{$filename}\"\r\n";
        $multipart .= "Content-Type: {$content_type}\r\n\r\n";
        $multipart .= $image_body . "\r\n";
        $multipart .= "--{$boundary}--\r\n";

        $upload_response = wp_ru_max_remote_request_with_ssl_fallback( 'post', $upload_url, array_merge( array(
            'timeout'   => 60,
            'headers'   => array(
                'Content-Type' => 'multipart/form-data; boundary=' . $boundary,
            ),
            'body' => $multipart,
        ), $this->get_max_ssl_args( $upload_url ) ) );

        if ( is_wp_error( $upload_response ) ) {
            $err = 'Ошибка загрузки на upload URL: ' . $upload_response->get_error_message();
            WP_Ru_Max_Logger::log( 'api', 'error', $err );
            return new WP_Error( 'upload_failed', $err );
        }

        $upload_code = wp_remote_retrieve_response_code( $upload_response );
        $upload_raw  = wp_remote_retrieve_body( $upload_response );
        $upload_data = json_decode( $upload_raw, true );

        $upload_success = $upload_code >= 200 && $upload_code < 300;
        WP_Ru_Max_Logger::log( 'api', $upload_success ? 'info' : 'warning', "Загрузка на upload URL завершена (HTTP {$upload_code}).", array(
            'http_code'    => $upload_code,
            'response_raw' => $upload_raw,
            'response'     => $upload_data,
        ) );

        if ( $upload_success && is_array( $upload_data ) && JSON_ERROR_NONE === json_last_error() ) {
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
     *   3. Если оба способа загрузки фото не сработали — явная ошибка.
     */
    public function send_message_with_image( $chat_id, $text, $image_url, $format = 'html', $buttons = array() ) {
        $chat_id = $this->normalize_chat_id( $chat_id );
        $text = $this->sanitize_utf8( $text, true );

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

        WP_Ru_Max_Logger::log( 'api', 'error', 'Отправка изображения по URL не удалась: ' . $result->get_error_message() . '. Текст без выбранного изображения не отправлен.', array(
            'image_url' => $image_url,
        ) );

        return new WP_Error(
            'image_send_failed',
            'Не удалось отправить выбранное изображение в MAX. Текстовое сообщение без изображения не отправлялось.'
        );
    }

    /**
     * Отправить сообщение с автоматическим повтором при ошибке.
     */
    public function send_with_retry( $chat_id, $text, $format = 'html', $buttons = array(), $image_url = false, $max_retries = 2, $retry_delay = 30 ) {
        // Ограничиваем диапазон, чтобы никто не передал отрицательное значение
        // или бесконечный цикл через слишком большой max_retries.
        $max_retries = max( 0, min( 10, (int) $max_retries ) );
        $retry_delay = max( 1, min( 300, (int) $retry_delay ) );
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
