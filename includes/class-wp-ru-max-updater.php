<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WP_Ru_Max_Updater {

    private $github_user = 'RuCoder-sudo';
    private $github_repo = 'wp-ru-max';
    private $plugin_file;
    private $plugin_slug;
    private $current_version;
    private $cache_key;
    private $cache_ttl = 43200; // 12 часов
    private $was_active = false;
    private $was_network_active = false;

    public function __construct( $plugin_file, $current_version ) {
        $this->plugin_file     = $plugin_file;
        $this->plugin_slug     = plugin_basename( $plugin_file );
        $this->current_version = $current_version;
        $this->cache_key       = 'wp_ru_max_github_update';

        // Одноразовый сброс старого кэша проверки обновлений
        if ( get_option( 'wp_ru_max_updater_cache_reset_v2' ) !== '1' ) {
            delete_transient( $this->cache_key );
            delete_site_transient( 'update_plugins' );
            update_option( 'wp_ru_max_updater_cache_reset_v2', '1' );
        }

        add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'check_update' ) );
        add_filter( 'plugins_api',                           array( $this, 'plugin_info' ), 10, 3 );
        add_filter( 'upgrader_pre_install',                  array( $this, 'before_install' ), 9, 2 );
        add_filter( 'upgrader_post_install',                 array( $this, 'after_install' ), 10, 3 );
    }

    /**
     * Получить данные о последнем релизе с GitHub (с кешированием)
     */
    private function get_github_release() {
        $cached = get_transient( $this->cache_key );
        if ( false !== $cached ) {
            return $cached;
        }

        $url      = "https://api.github.com/repos/{$this->github_user}/{$this->github_repo}/releases/latest";
        $response = wp_remote_get( $url, array(
            'timeout'    => 10,
            'user-agent' => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . get_bloginfo( 'url' ),
            'headers'    => array( 'Accept' => 'application/vnd.github.v3+json' ),
        ) );

        if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
            return false;
        }

        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( empty( $data['tag_name'] ) ) {
            return false;
        }

        set_transient( $this->cache_key, $data, $this->cache_ttl );
        return $data;
    }

    /**
     * Нормализовать версию к каноничному виду MAJOR.MINOR.PATCH.
     */
    private function normalize_version( $version ) {
        $version = ltrim( (string) $version, 'vV' );

        $parts    = preg_split( '/[._\-+]/', $version );
        $expanded = array();

        foreach ( $parts as $p ) {
            if ( ! ctype_digit( $p ) ) {
                continue;
            }
            if ( strlen( $p ) >= 2 && $p[0] === '0' ) {
                $expanded[] = 0;
                $expanded[] = (int) substr( $p, 1 );
            } else {
                $expanded[] = (int) $p;
            }
        }

        while ( count( $expanded ) < 3 ) {
            $expanded[] = 0;
        }

        return implode( '.', array_slice( $expanded, 0, 3 ) );
    }

    /**
     * Проверка обновлений — вызывается WordPress при проверке плагинов
     */
    public function check_update( $transient ) {
        if ( empty( $transient->checked ) ) {
            return $transient;
        }

        $release = $this->get_github_release();
        if ( ! $release ) {
            return $transient;
        }

        $latest_version = $this->normalize_version( $release['tag_name'] );

        if ( version_compare( $latest_version, $this->current_version, '>' ) ) {
            if ( isset( $transient->no_update ) && is_array( $transient->no_update ) ) {
                unset( $transient->no_update[ $this->plugin_slug ] );
            }
            if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
                $transient->response = array();
            }
            $transient->response[ $this->plugin_slug ] = (object) array(
                'id'           => $this->plugin_slug,
                'slug'         => dirname( $this->plugin_slug ),
                'plugin'       => $this->plugin_slug,
                'new_version'  => $latest_version,
                'url'          => "https://github.com/{$this->github_user}/{$this->github_repo}",
                'package'      => "https://github.com/{$this->github_user}/{$this->github_repo}/archive/refs/tags/{$release['tag_name']}.zip",
                'tested'       => '6.7',
                'requires_php' => '7.4',
                'icons'        => array(),
                'banners'      => array(),
            );
        } else {
            // WordPress may retain the previous response entry in the site
            // transient after a successful update. Remove it explicitly;
            // otherwise the same update is offered again until the second
            // manual click refreshes the transient.
            if ( isset( $transient->response ) && is_array( $transient->response ) ) {
                unset( $transient->response[ $this->plugin_slug ] );
            }
            if ( ! isset( $transient->no_update ) || ! is_array( $transient->no_update ) ) {
                $transient->no_update = array();
            }
            $transient->no_update[ $this->plugin_slug ] = (object) array(
                'id'          => $this->plugin_slug,
                'slug'        => dirname( $this->plugin_slug ),
                'plugin'      => $this->plugin_slug,
                'new_version' => $this->current_version,
                'url'         => "https://github.com/{$this->github_user}/{$this->github_repo}",
                'package'     => '',
            );
        }

        return $transient;
    }

    /**
     * Информация о плагине в модальном окне WordPress
     */
    public function plugin_info( $result, $action, $args ) {
        if ( $action !== 'plugin_information' ) {
            return $result;
        }
        if ( ! isset( $args->slug ) || $args->slug !== dirname( $this->plugin_slug ) ) {
            return $result;
        }

        $release = $this->get_github_release();
        if ( ! $release ) {
            return $result;
        }

        $latest_version = $this->normalize_version( $release['tag_name'] );
        $changelog      = ! empty( $release['body'] ) ? nl2br( esc_html( $release['body'] ) ) : 'Смотрите GitHub Releases.';

        return (object) array(
            'name'          => 'WP Ru-max',
            'slug'          => dirname( $this->plugin_slug ),
            'version'       => $latest_version,
            'author'        => '<a href="https://fixcoder.ru/" target="_blank">RuCoder</a>',
            'homepage'      => "https://github.com/{$this->github_user}/{$this->github_repo}",
            'requires'      => '5.8',
            'tested'        => '6.7',
            'requires_php'  => '7.4',
            'last_updated'  => $release['published_at'] ?? '',
            'sections'      => array(
                'description' => 'Интеграция WordPress с мессенджером MAX (max.ru). Автопубликация записей, уведомления с форм и чат-виджет для сайта.',
                'changelog'   => $changelog,
            ),
            'download_link' => "https://github.com/{$this->github_user}/{$this->github_repo}/archive/refs/tags/{$release['tag_name']}.zip",
        );
    }

    /**
     * Запомнить состояние плагина до того, как WordPress временно отключит его.
     */
    public function before_install( $response, $hook_extra ) {
        if ( ! isset( $hook_extra['plugin'] ) || $hook_extra['plugin'] !== $this->plugin_slug ) {
            return $response;
        }

        if ( ! function_exists( 'is_plugin_active' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $this->was_active = is_plugin_active( $this->plugin_slug );
        if ( is_multisite() && function_exists( 'is_plugin_active_for_network' ) ) {
            $this->was_network_active = is_plugin_active_for_network( $this->plugin_slug );
        }

        return $response;
    }

    /**
     * После установки — переименовать папку в правильное имя
     * и при необходимости вернуть его в активное состояние.
     */
    public function after_install( $response, $hook_extra, $result ) {
        if ( ! isset( $hook_extra['plugin'] ) || $hook_extra['plugin'] !== $this->plugin_slug ) {
            return $response;
        }
        if ( is_wp_error( $response ) ) {
            return $response;
        }
        if ( ! is_array( $result ) ) {
            return new WP_Error( 'install_result_missing', 'WP Ru-max: WordPress не вернул результат установки обновления.' );
        }

        global $wp_filesystem;

        // Инициализируем WP_Filesystem перед использованием — без этого $wp_filesystem может быть null.
        if ( ! function_exists( 'WP_Filesystem' ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        WP_Filesystem();

        if ( ! $wp_filesystem || ! is_object( $wp_filesystem ) ) {
            return new WP_Error( 'fs_unavailable', 'WP_Filesystem не инициализирован, переименование папки пропущено.' );
        }

        $plugin_folder = WP_PLUGIN_DIR . DIRECTORY_SEPARATOR . dirname( $this->plugin_slug );
        $destination   = isset( $result['destination'] ) && is_string( $result['destination'] )
            ? $result['destination']
            : '';

        // Проверяем результат переименования — без этого при ошибке плагин
        // остался бы в папке с временным именем и стал бы недоступен.
        // WordPress can already install an update into the existing plugin
        // directory. Moving a directory onto itself fails on some filesystem
        // adapters, so only move when the paths are actually different.
        if ( '' === $destination ) {
            return new WP_Error( 'destination_missing', 'WP Ru-max: WordPress не вернул папку установленного обновления.' );
        }
        $normalized_destination = untrailingslashit( wp_normalize_path( $destination ) );
        $normalized_plugin_dir  = untrailingslashit( wp_normalize_path( $plugin_folder ) );
        if ( $normalized_destination !== $normalized_plugin_dir && ! $wp_filesystem->move( $destination, $plugin_folder ) ) {
            return new WP_Error( 'rename_failed', 'WP Ru-max: не удалось переименовать папку плагина после обновления.' );
        }

        // Возвращаем только прежнее состояние. Обновление не должно
        // неожиданно активировать плагин, который был отключён администратором.
        if ( $this->was_active ) {
            if ( ! function_exists( 'activate_plugin' ) ) {
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
            }
            $activate_result = activate_plugin( $this->plugin_slug, '', $this->was_network_active );
            if ( is_wp_error( $activate_result ) ) {
                error_log( 'WP Ru-max updater: реактивация не удалась — ' . $activate_result->get_error_message() );
            }
        }

        // Do not leave the old response object in the site transient. Core
        // normally refreshes it too, but custom update filters and older
        // WordPress versions can otherwise show the same release again.
        delete_site_transient( 'update_plugins' );

        // The third argument is input install-result data. The filter itself
        // must return the response value, not that data array.
        return $response;
    }
}
