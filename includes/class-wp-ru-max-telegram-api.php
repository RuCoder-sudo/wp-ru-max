<?php
/**
 * Telegram Bot API — обёртка для отправки сообщений в Telegram-каналы и чаты.
 *
 * Использует официальный Telegram Bot API:
 *   https://api.telegram.org/bot{TOKEN}/{METHOD}
 *
 * НЕ путать с MAX API (platform-api2.max.ru) — это разные платформы с разными токенами.
 * Telegram-токены имеют формат  «1234567890:AAHdq...» (цифры + двоеточие + строка).
 * MAX-токены — другой формат.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WP_Ru_Max_Telegram_API {

    const API_BASE = 'https://api.telegram.org/bot';

    /** @var string Токен бота */
    private $token;

    /** @var array|null Переопределённые настройки прокси для тестового запроса */
    private $proxy_config;

    /** @var array|null Временные параметры HTTP-прокси для cURL */
    private $active_http_proxy = null;

    /**
     * @param string $token Токен Telegram-бота (формат: 123456789:AAHdq...)
     * @param array|null $proxy_config Настройки прокси; null использует сохранённые настройки сайта.
     */
    public function __construct( $token, $proxy_config = null ) {
        $this->token        = trim( (string) $token );
        $this->proxy_config = is_array( $proxy_config ) ? $proxy_config : null;
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  ПРИВАТНЫЙ HTTP-СЛОЙ
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Выполняет HTTP POST к методу Telegram Bot API.
     *
     * @param string $method Метод Telegram API (sendMessage, sendPhoto, …)
     * @param array  $params Параметры запроса
     * @return array|WP_Error Разобранный ответ API или WP_Error
     */
    private function request( $method, array $params = array() ) {
        if ( empty( $this->token ) ) {
            return new WP_Error( 'tg_no_token', 'Токен Telegram-бота не задан.' );
        }

        if ( ! in_array( $method, array( 'sendMessage', 'sendPhoto' ), true ) ) {
            return new WP_Error( 'tg_method_not_allowed', 'Этот метод Telegram Bot API не разрешён.' );
        }

        $proxy = $this->get_proxy_config();
        if ( ! empty( $proxy['enabled'] ) && in_array( $proxy['type'], array( 'cloudflare_worker', 'google_script', 'php_proxy' ), true ) ) {
            $response = $this->request_via_relay( $method, $params, $proxy );
        } else {
            $url = self::API_BASE . $this->token . '/' . $method;
            $args = array(
                'body'        => $params,
                'timeout'     => 45,
                'redirection' => 3,
            );

            if ( ! empty( $proxy['enabled'] ) && 'http' === $proxy['type'] ) {
                if ( ! function_exists( 'curl_init' ) ) {
                    return new WP_Error( 'tg_proxy_curl_required', 'Для HTTP-прокси на этом сервере требуется включённое расширение PHP cURL. Используйте прямое подключение или прокси-посредник.' );
                }
                $this->active_http_proxy = $proxy;
                add_action( 'http_api_curl', array( $this, 'configure_http_proxy' ), 10, 3 );
                try {
                    $response = wp_remote_post( $url, $args );
                } finally {
                    remove_action( 'http_api_curl', array( $this, 'configure_http_proxy' ), 10 );
                    $this->active_http_proxy = null;
                }
            } else {
                $response = wp_remote_post( $url, $args );
            }
        }

        if ( is_wp_error( $response ) ) {
            $message = $response->get_error_message();
            if ( false !== stripos( $message, 'curl error 7' ) || false !== stripos( $message, 'timed out' ) ) {
                $message .= ' Проверьте исходящие HTTPS-соединения хостинга или включите доверенный Telegram-прокси в настройках Telegram.';
            }
            WP_Ru_Max_Logger::log( 'social', 'error',
                '[Telegram] HTTP Error (' . $method . '): ' . $message,
                array( 'method' => $method, 'proxy' => ! empty( $proxy['enabled'] ) ? $proxy['type'] : 'direct' )
            );
            return new WP_Error( $response->get_error_code(), $message, $response->get_error_data() );
        }

        $http_code = wp_remote_retrieve_response_code( $response );
        $body_raw  = wp_remote_retrieve_body( $response );
        $data      = json_decode( $body_raw, true );

        // Telegram API отвечает { "ok": true, "result": {...} } или { "ok": false, "description": "..." }
        if ( ! is_array( $data ) || empty( $data['ok'] ) ) {
            $err_msg = '';
            if ( is_array( $data ) ) {
                $err_msg = $data['description'] ?? '';
            }
            if ( empty( $err_msg ) ) {
                $err_msg = "Telegram API вернул HTTP $http_code";
            }
            WP_Ru_Max_Logger::log( 'social', 'error',
                "[Telegram] Ошибка ($method): $err_msg",
                array( 'method' => $method, 'http_code' => $http_code )
            );
            return new WP_Error( 'tg_api_error', $err_msg, array( 'http_code' => $http_code ) );
        }

        return $data;
    }

    /**
     * Использует сохранённые настройки прокси или безопасные значения по умолчанию.
     *
     * @return array
     */
    private function get_proxy_config() {
        $config = $this->proxy_config;
        if ( null === $config ) {
            $social = get_option( 'wp_ru_max_social', array() );
            $config = isset( $social['telegram_proxy'] ) && is_array( $social['telegram_proxy'] )
                ? $social['telegram_proxy']
                : array();
        }
        return wp_parse_args( $config, array(
            'enabled'  => false,
            'type'     => 'http',
            'address'  => '',
            'username' => '',
            'password' => '',
            'url'      => '',
            'secret'   => '',
        ) );
    }

    /**
     * Устанавливает HTTP CONNECT-прокси только для запросов к Telegram Bot API.
     *
     * @param resource $handle cURL handle из WordPress HTTP API.
     * @param array    $args   Аргументы HTTP-запроса.
     * @param string   $url    Адрес запроса.
     */
    public function configure_http_proxy( $handle, $args, $url ) {
        if ( empty( $this->active_http_proxy ) || 'api.telegram.org' !== strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) ) ) {
            return;
        }

        $proxy = $this->active_http_proxy;
        $host  = trim( (string) ( $proxy['host'] ?? '' ) );
        $port  = absint( $proxy['port'] ?? 0 );
        if ( '' === $host || $port < 1 || $port > 65535 ) {
            return;
        }

        $proxy_address = ( false !== strpos( $host, ':' ) ? '[' . $host . ']' : $host ) . ':' . $port;
        curl_setopt( $handle, CURLOPT_PROXY, $proxy_address );
        if ( defined( 'CURLPROXY_HTTP' ) ) {
            curl_setopt( $handle, CURLOPT_PROXYTYPE, CURLPROXY_HTTP );
        }
        if ( ! empty( $proxy['username'] ) ) {
            curl_setopt( $handle, CURLOPT_PROXYAUTH, CURLAUTH_BASIC );
            curl_setopt( $handle, CURLOPT_PROXYUSERPWD, (string) $proxy['username'] . ':' . (string) ( $proxy['password'] ?? '' ) );
        }
    }

    /**
     * Отправляет запрос через пользовательский HTTPS relay с единым контрактом.
     * Relay должен вернуть JSON-ответ Telegram Bot API без изменения структуры.
     *
     * @param string $method Telegram API method.
     * @param array  $params Telegram API parameters.
     * @param array  $proxy  Proxy configuration.
     * @return array|WP_Error HTTP response or error.
     */
    private function request_via_relay( $method, array $params, array $proxy ) {
        $relay_url = esc_url_raw( (string) ( $proxy['url'] ?? '' ) );
        $url_parts = wp_parse_url( $relay_url );
        if (
            ! $url_parts
            || empty( $url_parts['scheme'] )
            || 'https' !== strtolower( $url_parts['scheme'] )
            || empty( $url_parts['host'] )
            || ! wp_http_validate_url( $relay_url )
        ) {
            return new WP_Error( 'tg_proxy_url_invalid', 'Укажите корректный HTTPS URL доверенного прокси-посредника.' );
        }
        if ( strlen( (string) ( $proxy['secret'] ?? '' ) ) < 16 ) {
            return new WP_Error( 'tg_proxy_secret_required', 'Для прокси-посредника задайте секретный ключ длиной не менее 16 символов.' );
        }

        $response = wp_safe_remote_post( $relay_url, array(
            'headers'     => array(
                'Content-Type'          => 'application/json; charset=utf-8',
                'X-WP-Ru-Max-Proxy-Key' => (string) $proxy['secret'],
            ),
            'body'        => wp_json_encode( array(
                'method' => $method,
                'token'  => $this->token,
                'params' => $params,
                'secret' => (string) $proxy['secret'],
            ) ),
            'timeout'     => 45,
            'redirection' => 3,
        ) );

        if ( is_wp_error( $response ) ) {
            return $response;
        }
        return $response;
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  ПУБЛИЧНЫЕ МЕТОДЫ
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Отправить текстовое сообщение.
     *
     * Поддерживаемые теги при parse_mode='HTML':
     *   <b>, <strong>, <i>, <em>, <u>, <ins>, <s>, <del>, <tg-spoiler>,
     *   <a href="...">, <code>, <pre>
     * Все прочие символы <, >, & в тексте должны быть заменены на
     * &lt; &gt; &amp; — иначе Telegram вернёт ошибку 400.
     * Используйте build_message() из WP_Ru_Max_Social_Poster — он экранирует
     * подставляемые значения автоматически.
     *
     * @param string $chat_id    ID чата или @username публичного канала
     * @param string $text       Текст сообщения
     * @param string $parse_mode 'HTML' | 'MarkdownV2' | '' (plain text)
     * @return array|WP_Error
     */
    public function send_message( $chat_id, $text, $parse_mode = 'HTML' ) {
        // Telegram ограничивает длину одного сообщения 4096 символами
        if ( wp_ru_max_strlen( $text, 'UTF-8' ) > 4096 ) {
            $text = wp_ru_max_substr( $text, 0, 4090, 'UTF-8' ) . "\n...";
        }

        $params = array(
            'chat_id' => $chat_id,
            'text'    => $text,
        );
        if ( ! empty( $parse_mode ) ) {
            $params['parse_mode'] = $parse_mode;
        }

        return $this->request( 'sendMessage', $params );
    }

    /**
     * Отправить фото с подписью.
     *
     * Если URL фото недоступен или отклонён Telegram, автоматически
     * выполняется fallback на sendMessage с тем же текстом.
     *
     * @param string $chat_id    ID чата или @username
     * @param string $photo_url  URL публично доступного изображения (JPEG/PNG/GIF/WEBP)
     * @param string $caption    Подпись (до 1024 символов)
     * @param string $parse_mode 'HTML' | 'MarkdownV2' | ''
     * @return array|WP_Error
     */
    public function send_photo( $chat_id, $photo_url, $caption = '', $parse_mode = 'HTML', $fallback_to_text = true ) {
        // Telegram ограничивает подпись к фото 1024 символами
        if ( wp_ru_max_strlen( $caption, 'UTF-8' ) > 1024 ) {
            $caption = wp_ru_max_substr( $caption, 0, 1020, 'UTF-8' ) . "\n...";
        }

        $params = array(
            'chat_id' => $chat_id,
            'photo'   => $photo_url,
        );
        if ( ! empty( $caption ) ) {
            $params['caption'] = $caption;
        }
        if ( ! empty( $parse_mode ) ) {
            $params['parse_mode'] = $parse_mode;
        }

        $result = $this->request( 'sendPhoto', $params );

        // Если фото отклонено (недоступный URL, неподдерживаемый тип и т.д.)
        // — отправляем текст отдельно, чтобы публикация не пропала полностью.
        if ( is_wp_error( $result ) && $fallback_to_text ) {
            WP_Ru_Max_Logger::log( 'social', 'warning',
                '[Telegram] sendPhoto не удался (' . $result->get_error_message() . ') — fallback на sendMessage.',
                array( 'chat_id' => $chat_id, 'photo_url' => $photo_url )
            );
            if ( ! empty( $caption ) ) {
                return $this->send_message( $chat_id, $caption, $parse_mode );
            }
        }

        return $result;
    }

    /**
     * Отправить сообщение с inline-кнопкой «Читать далее» (URL-кнопка).
     *
     * @param string $chat_id    ID чата или @username
     * @param string $text       Текст сообщения
     * @param string $btn_text   Надпись на кнопке
     * @param string $btn_url    URL, на который ведёт кнопка
     * @param string $parse_mode 'HTML' | 'MarkdownV2' | ''
     * @return array|WP_Error
     */
    public function send_message_with_button( $chat_id, $text, $btn_text, $btn_url, $parse_mode = 'HTML' ) {
        if ( wp_ru_max_strlen( $text, 'UTF-8' ) > 4096 ) {
            $text = wp_ru_max_substr( $text, 0, 4090, 'UTF-8' ) . "\n...";
        }

        $keyboard = array(
            'inline_keyboard' => array(
                array(
                    array(
                        'text' => $btn_text,
                        'url'  => $btn_url,
                    ),
                ),
            ),
        );

        $params = array(
            'chat_id'      => $chat_id,
            'text'         => $text,
            'reply_markup' => wp_json_encode( $keyboard ),
        );
        if ( ! empty( $parse_mode ) ) {
            $params['parse_mode'] = $parse_mode;
        }

        return $this->request( 'sendMessage', $params );
    }

    /**
     * Отправить фото с inline-кнопкой «Читать далее».
     *
     * @param string $chat_id    ID чата или @username
     * @param string $photo_url  URL изображения
     * @param string $caption    Подпись к фото
     * @param string $btn_text   Надпись на кнопке
     * @param string $btn_url    URL кнопки
     * @param string $parse_mode 'HTML' | 'MarkdownV2' | ''
     * @return array|WP_Error
     */
    public function send_photo_with_button( $chat_id, $photo_url, $caption, $btn_text, $btn_url, $parse_mode = 'HTML' ) {
        if ( wp_ru_max_strlen( $caption, 'UTF-8' ) > 1024 ) {
            $caption = wp_ru_max_substr( $caption, 0, 1020, 'UTF-8' ) . "\n...";
        }

        $keyboard = array(
            'inline_keyboard' => array(
                array(
                    array(
                        'text' => $btn_text,
                        'url'  => $btn_url,
                    ),
                ),
            ),
        );

        $params = array(
            'chat_id'      => $chat_id,
            'photo'        => $photo_url,
            'caption'      => $caption,
            'reply_markup' => wp_json_encode( $keyboard ),
        );
        if ( ! empty( $parse_mode ) ) {
            $params['parse_mode'] = $parse_mode;
        }

        $result = $this->request( 'sendPhoto', $params );

        // Fallback: если фото не прошло — отправляем текст с кнопкой
        if ( is_wp_error( $result ) ) {
            WP_Ru_Max_Logger::log( 'social', 'warning',
                '[Telegram] sendPhoto+button не удался (' . $result->get_error_message() . ') — fallback на sendMessage+button.',
                array( 'chat_id' => $chat_id )
            );
            return $this->send_message_with_button( $chat_id, $caption, $btn_text, $btn_url, $parse_mode );
        }

        return $result;
    }
}
