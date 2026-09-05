<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Small compatibility helpers for hosts where optional PHP extensions are
 * unavailable. WordPress itself does not require mbstring or iconv.
 */
if ( ! function_exists( 'wp_ru_max_strlen' ) ) {
    function wp_ru_max_strlen( $value, $encoding = 'UTF-8' ) {
        $value = (string) $value;

        if ( function_exists( 'mb_strlen' ) ) {
            return mb_strlen( $value, $encoding );
        }

        $value = function_exists( 'wp_check_invalid_utf8' )
            ? wp_check_invalid_utf8( $value )
            : $value;
        $length = preg_match_all( '/./us', $value, $matches );

        return false === $length ? strlen( $value ) : $length;
    }
}

if ( ! function_exists( 'wp_ru_max_substr' ) ) {
    function wp_ru_max_substr( $value, $start, $length = null, $encoding = 'UTF-8' ) {
        $value = (string) $value;
        $start = (int) $start;

        if ( function_exists( 'mb_substr' ) ) {
            if ( null === $length ) {
                return mb_substr( $value, $start, null, $encoding );
            }
            return mb_substr( $value, $start, (int) $length, $encoding );
        }

        $value = function_exists( 'wp_check_invalid_utf8' )
            ? wp_check_invalid_utf8( $value )
            : $value;
        $characters = preg_split( '//u', $value, -1, PREG_SPLIT_NO_EMPTY );

        if ( false === $characters ) {
            return null === $length ? substr( $value, $start ) : substr( $value, $start, (int) $length );
        }

        if ( $start < 0 ) {
            $start = max( 0, count( $characters ) + $start );
        }

        if ( null === $length ) {
            return implode( '', array_slice( $characters, $start ) );
        }

        return implode( '', array_slice( $characters, $start, (int) $length ) );
    }
}

if ( ! function_exists( 'wp_ru_max_utf8' ) ) {
    function wp_ru_max_utf8( $value ) {
        $value = (string) $value;

        if ( function_exists( 'mb_convert_encoding' ) ) {
            $converted = @mb_convert_encoding( $value, 'UTF-8', 'UTF-8' );
            if ( false !== $converted ) {
                return $converted;
            }
        }

        if ( function_exists( 'wp_check_invalid_utf8' ) ) {
            return wp_check_invalid_utf8( $value );
        }

        if ( function_exists( 'iconv' ) ) {
            $converted = @iconv( 'UTF-8', 'UTF-8//IGNORE', $value );
            if ( false !== $converted ) {
                return $converted;
            }
        }

        return $value;
    }
}

/**
 * Повторяет HTTPS-запрос без дополнительного CA-файла только после ошибки
 * проверки сертификата. Проверка SSL при этом остаётся включённой: сначала
 * используется комплект сертификатов плагина, затем системный комплект
 * WordPress/cURL. Это помогает старым хостингам, где один из комплектов
 * неполный или собран из устаревших сертификатов.
 */
if ( ! function_exists( 'wp_ru_max_remote_request_with_ssl_fallback' ) ) {
    function wp_ru_max_remote_request_with_ssl_fallback( $method, $url, $args = array() ) {
        $method = strtolower( (string) $method );
        $method = in_array( $method, array( 'get', 'post', 'head', 'request' ), true ) ? $method : 'request';
        $callback = 'wp_remote_' . $method;

        if ( ! function_exists( $callback ) ) {
            return new WP_Error( 'http_method_unavailable', 'WordPress не поддерживает HTTP-метод ' . $method . '.' );
        }

        $response = call_user_func( $callback, $url, $args );
        if ( ! is_wp_error( $response ) || empty( $args['sslcertificates'] ) ) {
            return $response;
        }

        $error_message = strtolower( (string) $response->get_error_message() );
        $certificate_error = false !== strpos( $error_message, 'curl error 60' )
            || false !== strpos( $error_message, 'ssl certificate' )
            || false !== strpos( $error_message, 'certificate problem' )
            || false !== strpos( $error_message, 'certificate verify failed' );

        if ( ! $certificate_error ) {
            return $response;
        }

        unset( $args['sslcertificates'] );
        return call_user_func( $callback, $url, $args );
    }
}
