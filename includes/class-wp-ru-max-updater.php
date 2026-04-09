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

    public function __construct( $plugin_file, $current_version ) {
        $this->plugin_file     = $plugin_file;
        $this->plugin_slug     = plugin_basename( $plugin_file );
        $this->current_version = $current_version;
        $this->cache_key       = 'wp_ru_max_github_update';

        add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'check_update' ) );
        add_filter( 'plugins_api',                           array( $this, 'plugin_info' ), 10, 3 );
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
     * Нормализовать версию (убрать 'v' в начале если есть)
     */
    private function normalize_version( $version ) {
        return ltrim( $version, 'v' );
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
            $transient->response[ $this->plugin_slug ] = (object) array(
                'id'            => $this->plugin_slug,
                'slug'          => dirname( $this->plugin_slug ),
                'plugin'        => $this->plugin_slug,
                'new_version'   => $latest_version,
                'url'           => "https://github.com/{$this->github_user}/{$this->github_repo}",
                'package'       => "https://github.com/{$this->github_user}/{$this->github_repo}/archive/refs/tags/{$release['tag_name']}.zip",
                'tested'        => '6.7',
                'requires_php'  => '7.4',
                'icons'         => array(),
                'banners'       => array(),
            );
        } else {
            if ( isset( $transient->no_update ) ) {
                $transient->no_update[ $this->plugin_slug ] = (object) array(
                    'id'          => $this->plugin_slug,
                    'slug'        => dirname( $this->plugin_slug ),
                    'plugin'      => $this->plugin_slug,
                    'new_version' => $this->current_version,
                    'url'         => "https://github.com/{$this->github_user}/{$this->github_repo}",
                    'package'     => '',
                );
            }
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
            'name'              => 'WP Ru-max',
            'slug'              => dirname( $this->plugin_slug ),
            'version'           => $latest_version,
            'author'            => '<a href="https://рукодер.рф/" target="_blank">RuCoder</a>',
            'homepage'          => "https://github.com/{$this->github_user}/{$this->github_repo}",
            'requires'          => '5.8',
            'tested'            => '6.7',
            'requires_php'      => '7.4',
            'last_updated'      => $release['published_at'] ?? '',
            'sections'          => array(
                'description' => 'Интеграция WordPress с мессенджером MAX (max.ru). Автопубликация записей, уведомления с форм и чат-виджет для сайта.',
                'changelog'   => $changelog,
            ),
            'download_link'     => "https://github.com/{$this->github_user}/{$this->github_repo}/archive/refs/tags/{$release['tag_name']}.zip",
        );
    }

    /**
     * После установки — переименовать папку в правильное имя
     * (GitHub архив распаковывается в папку типа wp-ru-max-1.0.11)
     */
    public function after_install( $response, $hook_extra, $result ) {
        if ( ! isset( $hook_extra['plugin'] ) || $hook_extra['plugin'] !== $this->plugin_slug ) {
            return $response;
        }

        global $wp_filesystem;
        $plugin_folder = WP_PLUGIN_DIR . DIRECTORY_SEPARATOR . dirname( $this->plugin_slug );
        $wp_filesystem->move( $result['destination'], $plugin_folder );
        $result['destination'] = $plugin_folder;

        activate_plugin( $this->plugin_slug );

        return $result;
    }
}
