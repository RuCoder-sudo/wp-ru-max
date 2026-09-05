<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WP_Ru_Max_Logger {

    private static $instance = null;
    private static $table_ready = false;

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public static function log( $event_type, $status, $event_data, $details = null ) {
        global $wpdb;
        $table = $wpdb->prefix . 'ru_max_history';

        self::ensure_table();

        $details_str = '';
        if ( is_array( $details ) || is_object( $details ) ) {
            $details_str = wp_json_encode( $details, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );
        } elseif ( ! is_null( $details ) ) {
            $details_str = strval( $details );
        }

        $inserted = $wpdb->insert(
            $table,
            array(
                'event_type' => sanitize_text_field( $event_type ),
                'event_data' => sanitize_textarea_field( $event_data ),
                'status'     => sanitize_text_field( $status ),
                'details'    => $details_str,
            ),
            array( '%s', '%s', '%s', '%s' )
        );

        /*
         * The table is normally created on activation. Sites that update the
         * plugin by copying files, however, can have all runtime code loaded
         * while the table is still missing. Retry once after dbDelta so a
         * failed first log does not make the whole diagnostics screen appear
         * empty.
         */
        if ( false === $inserted ) {
            self::$table_ready = false;
            self::ensure_table();
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
    }

    public static function get_logs( $limit = 100, $offset = 0, $type = '' ) {
        global $wpdb;
        $table = $wpdb->prefix . 'ru_max_history';

        self::ensure_table();

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

        self::ensure_table();

        if ( $type ) {
            return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table WHERE event_type = %s", $type ) );
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared — $table формируется из $wpdb->prefix, пользовательского ввода нет.
        return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM `%1$s`', $table ) );
    }

    public static function clear_logs( $type = '' ) {
        global $wpdb;
        $table = $wpdb->prefix . 'ru_max_history';

        self::ensure_table();

        if ( $type ) {
            $wpdb->delete( $table, array( 'event_type' => $type ), array( '%s' ) );
        } else {
            $wpdb->query( "TRUNCATE TABLE $table" );
        }
    }

    /**
     * Make the history table self-healing for installations updated by file
     * replacement rather than by the normal WordPress activation flow.
     */
    private static function ensure_table() {
        if ( self::$table_ready ) {
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'ru_max_history';
        $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

        if ( $exists !== $table && class_exists( 'WP_Ru_Max' ) && method_exists( 'WP_Ru_Max', 'create_table' ) ) {
            WP_Ru_Max::create_table();
        }

        self::$table_ready = true;
    }
}
