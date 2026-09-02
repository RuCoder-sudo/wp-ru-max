<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WP_Ru_Max_Pro_License {
    const VERIFY_URL = 'https://fixcoder.ru/wp-json/wp-ru-max-km/v1/verify';
    const VERIFY_CA = 'assets/fixcoder-root-yr.pem';
    const SYNC_TRANSIENT = 'wp_ru_max_pro_license_sync';
    private static $instance;

    public static function instance() {
        return self::$instance ?: ( self::$instance = new self() );
    }

    private function __construct() {
        add_action( 'wp_ajax_wp_ru_max_pro_deactivate', array( $this, 'deactivate' ) );
        add_action( 'init', array( $this, 'maybe_sync' ), 20 );
    }

    public function maybe_sync() {
        if ( false !== get_transient( self::SYNC_TRANSIENT ) ) {
            return;
        }
        $this->sync();
        set_transient( self::SYNC_TRANSIENT, 1, wp_ru_max_pro_is_enabled() ? 10 * MINUTE_IN_SECONDS : MINUTE_IN_SECONDS );
    }

    public function sync() {
        if ( ! class_exists( 'WP_Ru_Max_License' ) || ! WP_Ru_Max_License::is_active() ) {
            delete_option( WP_RU_MAX_PRO_LICENSE_OPTION );
            return false;
        }
        $main_license = WP_Ru_Max_License::get_data();
        $key = strtoupper( sanitize_text_field( $main_license['key'] ?? '' ) );
        if ( '' === $key ) {
            return false;
        }

        $request_args = array(
            'timeout' => 15,
            'sslverify' => true,
            'headers' => array(
                'Content-Type' => 'application/json',
                'X-WPRM-Secret' => defined( 'WP_Ru_Max_License::API_SECRET' ) ? WP_Ru_Max_License::API_SECRET : '',
            ),
            'body' => wp_json_encode( array( 'key' => $key ) ),
        );
        $ca_file = defined( 'WP_RU_MAX_PLUGIN_DIR' ) ? WP_RU_MAX_PLUGIN_DIR . self::VERIFY_CA : '';
        if ( $ca_file && is_readable( $ca_file ) ) {
            $request_args['sslcertificates'] = $ca_file;
        }
        $response = wp_remote_post( self::VERIFY_URL, $request_args );
        $body = ! is_wp_error( $response ) ? json_decode( wp_remote_retrieve_body( $response ), true ) : array();
        if ( is_wp_error( $response ) || ! is_array( $body ) || ! array_key_exists( 'valid', $body ) ) {
            return false;
        }
        if ( empty( $body['valid'] ) || empty( $body['pro_enabled'] ) ) {
            // A single incomplete or stale response must not remove an
            // already working entitlement. The main license recheck owns
            // suspension decisions; this sync only refreshes a cache.
            return false;
        }
        update_option( WP_RU_MAX_PRO_LICENSE_OPTION, array(
            'status' => 'active',
            'key' => $key,
            'activated_at' => current_time( 'mysql' ),
            'last_checked' => current_time( 'mysql' ),
        ) );
        return true;
    }

    public function deactivate() {
        check_ajax_referer( 'wp_ru_max_pro_nonce', 'nonce' );
        if ( current_user_can( 'manage_options' ) ) {
            delete_option( WP_RU_MAX_PRO_LICENSE_OPTION );
        }
        wp_send_json_success( 'Лицензия отключена.' );
    }
}
