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
            $args['body'] = wp_json_encode( $body );
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

    public function send_message( $chat_id, $text, $format = 'html' ) {
        $payload = array(
            'text'   => $text,
            'format' => $format,
        );
        return $this->request( 'POST', '/messages?chat_id=' . urlencode( $chat_id ), $payload );
    }

    public function send_message_with_image( $chat_id, $text, $image_url, $format = 'html' ) {
        $payload = array(
            'text'        => $text,
            'format'      => $format,
            'attachments' => array(
                array(
                    'type'    => 'image',
                    'payload' => array(
                        'url' => $image_url,
                    ),
                ),
            ),
        );
        $result = $this->request( 'POST', '/messages?chat_id=' . urlencode( $chat_id ), $payload );

        if ( is_wp_error( $result ) ) {
            return $this->send_message( $chat_id, $text, $format );
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
