<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WP_Ru_Max_Logger {

    private static $instance = null;

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public static function log( $event_type, $status, $event_data, $details = null ) {
        global $wpdb;
        $table = $wpdb->prefix . 'ru_max_history';

        $details_str = '';
        if ( is_array( $details ) || is_object( $details ) ) {
            $details_str = wp_json_encode( $details, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );
        } elseif ( ! is_null( $details ) ) {
            $details_str = strval( $details );
        }

        $wpdb->insert(
            $table,
            array(
                'event_type' => sanitize_text_field( $event_type ),
                'event_data' => sanitize_textarea_field( $event_data ),
                'status'     => sanitize_text_field( $status ),
                'details'    => $details_str,
            ),
            array( '%s', '%s', '%s', '%s' )
        );
    }

    public static function get_logs( $limit = 100, $offset = 0, $type = '' ) {
        global $wpdb;
        $table = $wpdb->prefix . 'ru_max_history';

        $where = '';
        if ( $type ) {
            $where = $wpdb->prepare( 'WHERE event_type = %s', $type );
        }

        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM $table $where ORDER BY id DESC LIMIT %d OFFSET %d",
                $limit,
                $offset
            )
        );

        return $results ? $results : array();
    }

    public static function get_count( $type = '' ) {
        global $wpdb;
        $table = $wpdb->prefix . 'ru_max_history';

        if ( $type ) {
            return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table WHERE event_type = %s", $type ) );
        }

        return (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table" );
    }

    public static function clear_logs( $type = '' ) {
        global $wpdb;
        $table = $wpdb->prefix . 'ru_max_history';

        if ( $type ) {
            $wpdb->delete( $table, array( 'event_type' => $type ), array( '%s' ) );
        } else {
            $wpdb->query( "TRUNCATE TABLE $table" );
        }
    }
}
