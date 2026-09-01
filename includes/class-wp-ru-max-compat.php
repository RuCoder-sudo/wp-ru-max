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