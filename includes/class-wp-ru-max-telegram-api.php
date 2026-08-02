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

    /**
     * @param string $token Токен Telegram-бота (формат: 123456789:AAHdq...)
     */
    public function __construct( $token ) {
        $this->token = trim( (string) $token );
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

        $url      = self::API_BASE . $this->token . '/' . $method;
        $response = wp_remote_post( $url, array(
            'body'    => $params,
            'timeout' => 20,
        ) );

        if ( is_wp_error( $response ) ) {
            WP_Ru_Max_Logger::log( 'social', 'error',
                '[Telegram] HTTP Error (' . $method . '): ' . $response->get_error_message(),
                array( 'method' => $method )
            );
            return $response;
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
                array( 'method' => $method, 'response_raw' => $body_raw )
            );
            return new WP_Error( 'tg_api_error', $err_msg, array( 'http_code' => $http_code ) );
        }

        return $data;
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
        if ( mb_strlen( $text, 'UTF-8' ) > 4096 ) {
            $text = mb_substr( $text, 0, 4090, 'UTF-8' ) . "\n...";
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
    public function send_photo( $chat_id, $photo_url, $caption = '', $parse_mode = 'HTML' ) {
        // Telegram ограничивает подпись к фото 1024 символами
        if ( mb_strlen( $caption, 'UTF-8' ) > 1024 ) {
            $caption = mb_substr( $caption, 0, 1020, 'UTF-8' ) . "\n...";
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
        if ( is_wp_error( $result ) ) {
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
        if ( mb_strlen( $text, 'UTF-8' ) > 4096 ) {
            $text = mb_substr( $text, 0, 4090, 'UTF-8' ) . "\n...";
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
        if ( mb_strlen( $caption, 'UTF-8' ) > 1024 ) {
            $caption = mb_substr( $caption, 0, 1020, 'UTF-8' ) . "\n...";
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
