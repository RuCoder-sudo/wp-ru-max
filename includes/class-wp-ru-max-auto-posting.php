<?php
/**
 * Планировщик публикаций WP Ru-max в социальные сети.
 *
 * Очередь намеренно отделена от очереди MAX: каждая сеть получает свой статус,
 * а временная ошибка одной интеграции не блокирует остальные.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WP_Ru_Max_Auto_Posting {

    const QUEUE_OPTION       = 'wp_ru_max_autopost_queue';
    const SETTINGS_OPTION    = 'wp_ru_max_autopost_settings';
    const META_KEY           = 'wp_ru_max_autopost';
    const CRON_HOOK          = 'wp_ru_max_autopost_worker';
    const CRON_SCHEDULE      = 'wp_ru_max_autopost_every_minute';
    const LOCK_OPTION        = 'wp_ru_max_autopost_lock';
    const LOCK_TTL           = 10 * MINUTE_IN_SECONDS;
    const MAX_ATTEMPTS       = 6;

    private static $instance = null;

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public static function networks() {
        return array(
            'max'      => 'MAX',
            'telegram' => 'Telegram',
            'vk'       => 'ВКонтакте',
            'ok'       => 'Одноклассники',
            'dzen'     => 'Яндекс Дзен',
        );
    }

    /**
     * Возвращает только те сети, для которых на текущем сайте заполнены
     * рабочие настройки публикации.
     *
     * Список используется в редакторах как подсказка пользователю. Полный
     * список в networks() остаётся для обратной совместимости со старыми
     * заданиями очереди.
     */
    public static function configured_networks() {
        // Социальные сети и очередь автопостинга доступны только после
        // активации лицензии. Эта проверка также защищает AJAX и редакторы.
        if ( class_exists( 'WP_Ru_Max_License' ) && ! WP_Ru_Max_License::is_active() ) {
            return array();
        }

        $configured = array();
        $settings   = get_option( 'wp_ru_max_settings', array() );
        $channels   = array_filter( array_map( 'trim', (array) ( $settings['channels'] ?? array() ) ) );

        if ( ! empty( $settings['bot_token'] ) && ! empty( $channels ) ) {
            $configured['max'] = true;
        }

        $social = get_option( 'wp_ru_max_social', array() );
        $bots   = isset( $social['telegram_bots'] ) && is_array( $social['telegram_bots'] )
            ? $social['telegram_bots']
            : array();
        foreach ( $bots as $bot ) {
            $bot_chats = isset( $bot['chats'] ) && is_array( $bot['chats'] ) ? $bot['chats'] : array();
            $has_chat  = false;
            foreach ( $bot_chats as $chat ) {
                if ( ! empty( $chat['chat_id'] ) ) {
                    $has_chat = true;
                    break;
                }
            }
            if ( ! empty( $bot['token'] ) && $has_chat ) {
                $configured['telegram'] = true;
                break;
            }
        }

        $vk_token = trim( (string) ( $social['vk_access_token'] ?? '' ) );
        $vk_service_token = trim( (string) ( $social['vk_service_token'] ?? $social['vk_secret_key'] ?? '' ) );
        if ( '' !== $vk_token && '' !== $vk_service_token && hash_equals( $vk_service_token, $vk_token ) ) {
            // Service token приложения не подходит для публикации на стене.
            $vk_token = '';
        }
        $vk_group_tokens = isset( $social['vk_group_tokens'] ) && is_array( $social['vk_group_tokens'] )
            ? $social['vk_group_tokens']
            : array();
        $has_vk_group_token = false;
        foreach ( $vk_group_tokens as $group_token ) {
            if ( is_scalar( $group_token ) && '' !== trim( (string) $group_token ) ) {
                $has_vk_group_token = true;
                break;
            }
        }
        if (
            ! empty( $social['vk_enabled'] )
            && '' !== trim( (string) ( $social['vk_owner_id'] ?? '' ) )
            && ( '' !== $vk_token || $has_vk_group_token )
        ) {
            $configured['vk'] = true;
        }

        if (
            ! empty( $social['ok_enabled'] )
            && ! empty( $social['ok_app_id'] )
            && ! empty( $social['ok_public_key'] )
            && ! empty( $social['ok_secret_key'] )
            && ! empty( $social['ok_access_token'] )
        ) {
            $configured['ok'] = true;
        }

        if (
            ! empty( $social['dzen_enabled'] )
            && ! empty( $social['dzen_oauth_token'] )
            && ! empty( $social['dzen_channel_id'] )
        ) {
            $configured['dzen'] = true;
        }

        $configured = array_intersect_key( self::networks(), $configured );
        return apply_filters( 'wp_ru_max_autopost_configured_networks', $configured );
    }

    public static function default_settings() {
        return array(
            'enabled'             => true,
            'default_time'        => '10:00',
            'retry_attempts'      => self::MAX_ATTEMPTS,
            'retry_delay_minutes' => 5,
            'notify_enabled'      => false,
            'notify_emails'       => get_option( 'admin_email', '' ),
            'notify_on_success'   => false,
            'notify_on_error'     => true,
        );
    }

    private function __construct() {
        add_filter( 'cron_schedules', array( $this, 'add_cron_schedule' ) );
        add_action( self::CRON_HOOK, array( $this, 'run_worker' ) );
        add_action( 'init', array( $this, 'ensure_worker' ), 22 );
        // WP-Cron зависит от посещений сайта и может быть отключён на
        // хостинге. На обычном запросе обрабатываем только уже просроченные
        // задания; AJAX-запросы исключены, чтобы не отправлять старое задание
        // до выполнения текущего действия пользователя.
        add_action( 'wp_loaded', array( $this, 'process_due_on_request' ), 999 );
        // This singleton is created from the main plugin's init callback
        // (priority 10), so a new init hook at priority 6 would already be
        // missed. Register the meta immediately instead.
        $this->register_meta();
        add_action( 'save_post', array( $this, 'sync_post_on_save' ), 20, 3 );

        add_action( 'wp_ajax_wp_ru_max_autopost_get_meta', array( $this, 'ajax_get_meta' ) );
        add_action( 'wp_ajax_wp_ru_max_autopost_save_meta', array( $this, 'ajax_save_meta' ) );
        add_action( 'wp_ajax_wp_ru_max_autopost_calendar', array( $this, 'ajax_calendar' ) );
        add_action( 'wp_ajax_wp_ru_max_autopost_move', array( $this, 'ajax_move' ) );
        add_action( 'wp_ajax_wp_ru_max_autopost_delete', array( $this, 'ajax_delete' ) );
        add_action( 'wp_ajax_wp_ru_max_autopost_run', array( $this, 'ajax_run' ) );
        add_action( 'wp_ajax_wp_ru_max_autopost_save_settings', array( $this, 'ajax_save_settings' ) );
    }

    public function add_cron_schedule( $schedules ) {
        if ( ! isset( $schedules[ self::CRON_SCHEDULE ] ) ) {
            $schedules[ self::CRON_SCHEDULE ] = array(
                'interval' => MINUTE_IN_SECONDS,
                'display'  => 'WP Ru-max: автопостинг каждую минуту',
            );
        }
        return $schedules;
    }

    public function ensure_worker() {
        $settings = $this->get_settings();
        if ( empty( $settings['enabled'] ) ) {
            return;
        }
        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_event( time() + MINUTE_IN_SECONDS, self::CRON_SCHEDULE, self::CRON_HOOK );
        }
    }

    /**
     * Планирует ближайшую проверку для конкретного задания.
     *
     * Постоянный worker остаётся страховкой раз в минуту, а отдельное событие
     * позволяет не ждать следующего интервала после сохранения задания.
     */
    private function schedule_worker_for( $run_at = 0 ) {
        if ( empty( $this->get_settings()['enabled'] ) ) {
            return;
        }

        $due  = max( time() + 1, (int) $run_at );
        $next = wp_next_scheduled( self::CRON_HOOK );
        if ( ! $next || (int) $next > $due ) {
            wp_schedule_single_event( $due, self::CRON_HOOK );
        }
        $this->ensure_worker();
    }

    public function run_worker() {
        $this->process_due();
    }

    public function process_due_on_request() {
        if (
            ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() )
            || ( defined( 'DOING_AJAX' ) && DOING_AJAX )
            || ( defined( 'DOING_CRON' ) && DOING_CRON )
        ) {
            return;
        }
        $this->process_due();
    }

    public function register_meta() {
        foreach ( get_post_types( array( 'public' => true ), 'names' ) as $post_type ) {
            register_post_meta( $post_type, self::META_KEY, array(
                'single'            => true,
                'type'              => 'array',
                'show_in_rest'      => false,
                'sanitize_callback' => array( $this, 'sanitize_meta' ),
                'auth_callback'     => function() {
                    return current_user_can( 'edit_posts' );
                },
            ) );
        }
    }

    public function sanitize_meta( $value ) {
        if ( ! is_array( $value ) ) {
            return array();
        }
        return $this->normalize_config(
            isset( $value['networks'] ) ? $value['networks'] : array(),
            isset( $value['datetime'] ) ? $value['datetime'] : '',
            isset( $value['status'] ) ? $value['status'] : array(),
            ! empty( $value['active'] )
        );
    }

    private function get_settings() {
        return wp_parse_args( get_option( self::SETTINGS_OPTION, array() ), self::default_settings() );
    }

    public function get_post_config( $post_id ) {
        $config = get_post_meta( $post_id, self::META_KEY, true );
        if ( ! is_array( $config ) ) {
            $config = array();
        }
        return $this->normalize_config(
            isset( $config['networks'] ) ? $config['networks'] : array(),
            isset( $config['datetime'] ) ? $config['datetime'] : '',
            isset( $config['status'] ) ? $config['status'] : array(),
            ! empty( $config['active'] )
        );
    }

    private function normalize_config( $networks, $datetime, $status = array(), $active = false ) {
        // Не даём сохранить задание для сети, которая не подключена на
        // текущем сайте. Это защищает и AJAX, и обычный редактор.
        $allowed = array_keys( self::configured_networks() );
        $clean   = array();
        foreach ( (array) $networks as $network ) {
            $network = sanitize_key( $network );
            if ( in_array( $network, $allowed, true ) && ! in_array( $network, $clean, true ) ) {
                $clean[] = $network;
            }
        }
        $datetime = sanitize_text_field( (string) $datetime );
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $datetime ) ) {
            $datetime = '';
        }
        $normalized_status = array();
        foreach ( $clean as $network ) {
            $normalized_status[ $network ] = isset( $status[ $network ] )
                ? sanitize_key( $status[ $network ] )
                : 'pending';
        }
        return array(
            'networks' => $clean,
            'datetime' => $datetime,
            'status'   => $normalized_status,
            'active'   => (bool) $active && ! empty( $clean ) && '' !== $datetime,
        );
    }

    /**
     * Сохраняет конфигурацию записи и синхронизирует её с постоянной очередью.
     */
    public function save_post_config( $post_id, $networks, $datetime, $reset_status = true ) {
        $post = get_post( $post_id );
        if ( ! $post ) {
            return new WP_Error( 'post_not_found', 'Запись не найдена.' );
        }

        $old                = $this->get_post_config( $post_id );
        $status             = isset( $old['status'] ) ? (array) $old['status'] : array();
        $requested_networks = array();
        foreach ( (array) $networks as $network ) {
            $network = sanitize_key( $network );
            if ( '' !== $network && ! in_array( $network, $requested_networks, true ) ) {
                $requested_networks[] = $network;
            }
        }
        $allowed_networks = array_keys( self::configured_networks() );
        $unsupported      = array_diff( $requested_networks, $allowed_networks );
        if ( ! empty( $unsupported ) ) {
            $labels = self::networks();
            $names  = array();
            foreach ( $unsupported as $network ) {
                $names[] = $labels[ $network ] ?? $network;
            }
            return new WP_Error(
                'network_not_configured',
                'Нельзя запланировать публикацию: подключите или повторно авторизуйте сеть — ' . implode( ', ', $names ) . '.'
            );
        }

        /*
         * Завершённая статья является одноразовой публикацией. После этого
         * обычное сохранение записи не должно создавать новую отправку. Но
         * явное действие «Сохранить автопостинг» или перенос в календаре —
         * это новый запуск, поэтому reset_status=true намеренно сбрасывает
         * статусы и возвращает задание в очередь.
         */
        if ( ! $reset_status && $this->is_config_complete( $old ) ) {
            $networks = $old['networks'];
        } else {
            $networks = $requested_networks;
        }

        $has_schedule = ! empty( $networks ) && '' !== trim( (string) $datetime );
        $config = $this->normalize_config( $networks, $datetime, $status, $has_schedule );
        foreach ( $config['networks'] as $network ) {
            $previous_status = isset( $status[ $network ] ) ? $status[ $network ] : '';
            /*
             * При обычном сохранении записи sent/skipped остаются конечными
             * состояниями. Явное перепланирование обрабатывается отдельно:
             * reset_status=true возвращает каждую выбранную сеть в pending.
             */
            if ( $reset_status ) {
                $config['status'][ $network ] = 'pending';
            } elseif ( in_array( $previous_status, array( 'sent', 'skipped' ), true ) ) {
                $config['status'][ $network ] = $previous_status;
            }
        }
        update_post_meta( $post_id, self::META_KEY, $config );

        $queue = $this->get_queue();
        $key   = $this->job_key( $post_id );
        if ( empty( $config['networks'] ) || empty( $config['datetime'] ) ) {
            unset( $queue[ $key ] );
            $this->save_queue( $queue );
            return $config;
        }

        $timestamp = $this->datetime_to_timestamp( $config['datetime'] );
        if ( ! $timestamp ) {
            return new WP_Error( 'invalid_datetime', 'Укажите дату и время в формате ГГГГ-ММ-ДД ЧЧ:ММ.' );
        }

        /*
         * После успешной отправки запись остаётся видимой в календаре, но
         * больше не должна возвращаться в рабочую очередь даже если
         * пользователь передвинул её на другую дату.
         */
        if ( $this->is_config_complete( $config ) ) {
            unset( $queue[ $key ] );
            $this->save_queue( $queue );
            $this->ensure_worker();
            return $config;
        }

        $queue[ $key ] = array(
            'post_id'  => (int) $post_id,
            'run_at'   => $timestamp,
            'networks' => $config['networks'],
            'statuses' => $config['status'],
            'attempts' => isset( $queue[ $key ]['attempts'] ) && ! $reset_status
                ? $queue[ $key ]['attempts']
                : array(),
            'errors'   => array(),
            'updated'  => current_time( 'mysql' ),
        );
        $this->save_queue( $queue );
        $this->schedule_worker_for( $timestamp );
        WP_Ru_Max_Logger::log( 'autopost', 'info', 'Задание автопостинга для записи #' . (int) $post_id . ' сохранено в очередь.', array(
            'post_id'  => (int) $post_id,
            'datetime' => $config['datetime'],
            'networks' => $config['networks'],
            'queue_key' => $key,
            'run_at'   => $timestamp,
        ) );
        return $config;
    }

    public function sync_post_on_save( $post_id, $post, $update ) {
        if ( wp_is_post_revision( $post_id ) || ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) ) {
            return;
        }
        if ( ! $post || ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }
        if ( isset( $_POST['wp_ru_max_autopost_networks'] ) || isset( $_POST['wp_ru_max_autopost_datetime'] ) ) {
            $networks = isset( $_POST['wp_ru_max_autopost_networks'] )
                ? wp_unslash( $_POST['wp_ru_max_autopost_networks'] )
                : array();
            $datetime = isset( $_POST['wp_ru_max_autopost_datetime'] )
                ? wp_unslash( $_POST['wp_ru_max_autopost_datetime'] )
                : '';
            $datetime = str_replace( 'T', ' ', sanitize_text_field( (string) $datetime ) );
            $this->save_post_config( $post_id, $networks, $datetime, false );
        }
    }

    private function datetime_to_timestamp( $datetime ) {
        $timezone = function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'UTC' );
        $date     = DateTimeImmutable::createFromFormat( '!Y-m-d H:i', $datetime, $timezone );
        $errors   = DateTimeImmutable::getLastErrors();
        if ( ! $date || ( is_array( $errors ) && ( $errors['warning_count'] || $errors['error_count'] ) ) || $date->format( 'Y-m-d H:i' ) !== $datetime ) {
            return 0;
        }
        return $date->getTimestamp();
    }

    private function timestamp_to_datetime( $timestamp ) {
        return wp_date( 'Y-m-d H:i', (int) $timestamp, wp_timezone() );
    }

    private function job_key( $post_id ) {
        return 'post_' . (int) $post_id;
    }

    private function is_config_complete( $config ) {
        if ( empty( $config['networks'] ) ) {
            return false;
        }
        foreach ( (array) $config['networks'] as $network ) {
            if ( ! in_array( $config['status'][ $network ] ?? '', array( 'sent', 'skipped' ), true ) ) {
                return false;
            }
        }
        return true;
    }

    private function get_queue() {
        $stored = get_option( self::QUEUE_OPTION, array() );
        if ( ! is_array( $stored ) ) {
            return array();
        }

        /*
         * До стабильного ключа в очереди могли остаться варианты вроде
         * post_123_new и post_123_update. Оставляем ровно одно задание на
         * запись и переносим его на канонический ключ.
         */
        $queue   = array();
        $changed = false;
        foreach ( $stored as $stored_key => $job ) {
            $post_id = is_array( $job ) ? absint( $job['post_id'] ?? 0 ) : 0;
            if ( ! $post_id ) {
                $changed = true;
                continue;
            }
            $canonical_key = $this->job_key( $post_id );
            if ( (string) $stored_key === $canonical_key || ! isset( $queue[ $canonical_key ] ) ) {
                if ( isset( $queue[ $canonical_key ] ) && (string) $stored_key === $canonical_key ) {
                    $changed = true;
                }
                $queue[ $canonical_key ] = $job;
            } else {
                $changed = true;
            }
            if ( (string) $stored_key !== $canonical_key ) {
                $changed = true;
            }
        }

        if ( $changed || count( $queue ) !== count( $stored ) ) {
            $this->save_queue( $queue );
        }
        return $queue;
    }

    private function save_queue( $queue ) {
        update_option( self::QUEUE_OPTION, $queue, false );
    }

    /**
     * Восстанавливает очередь по расписанию, сохранённому в метаданных записи.
     *
     * Ранние версии календаря могли сохранить расписание записи, но не
     * создать соответствующий элемент в QUEUE_OPTION. В таком случае событие
     * видно в календаре, однако cron и счётчики очереди его не видят.
     */
    private function sync_queue_from_post_meta() {
        $queue = $this->get_queue();
        $changed = false;
        $now = time();

        /*
         * Метаданные статьи являются источником истины. Старое задание могло
         * остаться в option очереди после успешной отправки или переноса даты.
         * Сначала приводим существующие задания к текущей конфигурации, а
         * полностью завершённые удаляем.
         */
        foreach ( $queue as $key => $job ) {
            $post_id = absint( is_array( $job ) ? ( $job['post_id'] ?? 0 ) : 0 );
            if ( ! $post_id ) {
                unset( $queue[ $key ] );
                $changed = true;
                continue;
            }

            $post = get_post( $post_id );
            $config = $this->get_post_config( $post_id );
            $queued_at = $this->datetime_to_timestamp( $config['datetime'] );
            /*
             * До появления признака active старые опубликованные записи с
             * просроченной датой могли бесконечно возвращаться в очередь из
             * метаданных. Они не являются новым явным расписанием и не
             * должны неожиданно отправляться после обновления плагина.
             * Будущие задания сохраняем для совместимости.
             */
            if (
                ! $config['active']
                && $post
                && 'publish' === $post->post_status
                && $queued_at
                && $queued_at <= $now
            ) {
                unset( $queue[ $key ] );
                $changed = true;
                continue;
            }
            if ( empty( $config['networks'] ) || empty( $config['datetime'] ) || $this->is_config_complete( $config ) ) {
                unset( $queue[ $key ] );
                $changed = true;
                continue;
            }

            $run_at = $this->datetime_to_timestamp( $config['datetime'] );
            if ( ! $run_at ) {
                unset( $queue[ $key ] );
                $changed = true;
                continue;
            }

            $canonical_key = $this->job_key( $post_id );
            $job            = is_array( $job ) ? $job : array();
            $job['post_id'] = $post_id;
            $job['run_at']  = $run_at;
            $job['networks'] = $config['networks'];
            $job['statuses'] = $config['status'];
            $job['attempts'] = isset( $job['attempts'] ) && is_array( $job['attempts'] ) ? $job['attempts'] : array();
            $job['errors']   = isset( $job['errors'] ) && is_array( $job['errors'] ) ? $job['errors'] : array();

            foreach ( array_keys( $job['attempts'] ) as $network ) {
                if ( ! in_array( $network, $config['networks'], true ) ) {
                    unset( $job['attempts'][ $network ] );
                }
            }
            foreach ( array_keys( $job['errors'] ) as $network ) {
                if ( ! in_array( $network, $config['networks'], true ) ) {
                    unset( $job['errors'][ $network ] );
                }
            }

            $previous_job = isset( $queue[ $canonical_key ] ) ? $queue[ $canonical_key ] : null;
            if ( (string) $key !== $canonical_key || $previous_job !== $job ) {
                unset( $queue[ $key ] );
                $queue[ $canonical_key] = $job;
                $changed = true;
            }
        }

        $post_ids = get_posts( array(
            'post_type'      => 'post',
            'post_status'    => array( 'publish', 'future', 'draft', 'pending' ),
            'posts_per_page' => -1,
            'fields'         => 'ids',
        ) );

        foreach ( (array) $post_ids as $post_id ) {
            $post_id = (int) $post_id;
            if ( ! $post_id ) {
                continue;
            }

            $post = get_post( $post_id );
            if ( ! $post ) {
                continue;
            }

            $config = $this->get_post_config( $post_id );
            $key = $this->job_key( $post_id );
            if ( empty( $config['networks'] ) || empty( $config['datetime'] ) ) {
                continue;
            }

            $run_at = $this->datetime_to_timestamp( $config['datetime'] );
            if ( ! $run_at ) {
                continue;
            }

            if ( $this->is_config_complete( $config ) ) {
                if ( isset( $queue[ $key ] ) ) {
                    unset( $queue[ $key ] );
                    $changed = true;
                }
                continue;
            }

            /*
             * Для старой мета-записи без active не создаём новое просроченное
             * задание. Явное сохранение в календаре записывает active=true.
             */
            if ( ! $config['active'] && 'publish' === $post->post_status && $run_at <= time() ) {
                continue;
            }

            if ( ! isset( $queue[ $key ] ) ) {
                $queue[ $key ] = array(
                    'post_id'  => $post_id,
                    'run_at'   => $run_at,
                    'networks' => $config['networks'],
                    'statuses' => $config['status'],
                    'attempts' => array(),
                    'errors'   => array(),
                    'updated'  => current_time( 'mysql' ),
                );
                $changed = true;
            }
        }

        if ( $changed ) {
            $this->save_queue( $queue );
            $next_due = 0;
            foreach ( $queue as $job ) {
                $job_due = (int) ( $job['run_at'] ?? 0 );
                if ( $job_due > 0 && ( ! $next_due || $job_due < $next_due ) ) {
                    $next_due = $job_due;
                }
            }
            $this->schedule_worker_for( $next_due );
        }
    }

    public static function get_queue_summary() {
        $instance = self::instance();
        $instance->sync_queue_from_post_meta();
        $queue = $instance->get_queue();
        $pending = 0;
        $errors  = 0;
        foreach ( $queue as $job ) {
            $states = isset( $job['statuses'] ) ? (array) $job['statuses'] : array();
            if ( in_array( 'error', $states, true ) ) {
                $errors++;
            } else {
                $pending++;
            }
        }
        return array( 'total' => count( $queue ), 'pending' => $pending, 'errors' => $errors );
    }

    /**
     * Подробный список реально ожидающих заданий для панели автопостинга.
     * Перед выдачей список синхронизируется с метаданными записей, поэтому
     * старые завершённые задания не попадают в счётчик и список.
     */
    public static function get_queue_items() {
        $instance = self::instance();
        $instance->sync_queue_from_post_meta();
        $queue = $instance->get_queue();
        $items = array();

        foreach ( $queue as $job ) {
            $post_id = absint( $job['post_id'] ?? 0 );
            $post    = $post_id ? get_post( $post_id ) : null;
            if ( ! $post ) {
                continue;
            }

            $items[] = array(
                'id'        => $post_id,
                'title'     => get_the_title( $post ) ?: '(без названия)',
                'edit_url'  => admin_url( 'post.php?post=' . $post_id . '&action=edit' ),
                'datetime'  => $instance->timestamp_to_datetime( (int) ( $job['run_at'] ?? 0 ) ),
                'run_at'    => (int) ( $job['run_at'] ?? 0 ),
                'networks'  => (array) ( $job['networks'] ?? array() ),
                'statuses'  => (array) ( $job['statuses'] ?? array() ),
                'attempts'  => (array) ( $job['attempts'] ?? array() ),
                'errors'    => (array) ( $job['errors'] ?? array() ),
            );
        }

        usort( $items, function( $left, $right ) {
            return (int) $left['run_at'] <=> (int) $right['run_at'];
        } );

        return $items;
    }

    private function acquire_lock( &$token ) {
        $token = wp_generate_password( 32, false, false );
        if ( add_option( self::LOCK_OPTION, array( 'token' => $token, 'expires' => time() + self::LOCK_TTL ), '', false ) ) {
            return true;
        }
        $lock = get_option( self::LOCK_OPTION, array() );
        if ( ! empty( $lock['expires'] ) && (int) $lock['expires'] < time() ) {
            delete_option( self::LOCK_OPTION );
            return add_option( self::LOCK_OPTION, array( 'token' => $token, 'expires' => time() + self::LOCK_TTL ), '', false );
        }
        return false;
    }

    private function release_lock( $token ) {
        $lock = get_option( self::LOCK_OPTION, array() );
        if ( is_array( $lock ) && ! empty( $lock['token'] ) && hash_equals( (string) $lock['token'], (string) $token ) ) {
            delete_option( self::LOCK_OPTION );
        }
    }

    public function process_due( $force = false, $only_post_id = 0 ) {
        if ( class_exists( 'WP_Ru_Max_License' ) && ! WP_Ru_Max_License::is_active() ) {
            if ( $force ) {
                WP_Ru_Max_Logger::log( 'autopost', 'error', 'Автопостинг не запущен: лицензия плагина не активна.', array(
                    'only_post_id' => (int) $only_post_id,
                ) );
            }
            return 0;
        }

        $settings = $this->get_settings();
        if ( ! $force && empty( $settings['enabled'] ) ) {
            return 0;
        }
        $token = '';
        if ( ! $this->acquire_lock( $token ) ) {
            if ( $force ) {
                WP_Ru_Max_Logger::log( 'autopost', 'warning', 'Автопостинг не запущен: очередь уже обрабатывается другим запросом.', array(
                    'only_post_id' => (int) $only_post_id,
                ) );
            }
            return 0;
        }
        $processed = 0;
        try {
            // Перед отправкой удаляем старые завершённые задания и
            // восстанавливаем только действительно незавершённые записи.
            $this->sync_queue_from_post_meta();
            foreach ( $this->get_queue() as $key => $job ) {
                if ( $only_post_id && (int) ( $job['post_id'] ?? 0 ) !== (int) $only_post_id ) {
                    continue;
                }
                if ( ! $force && (int) ( $job['run_at'] ?? 0 ) > time() ) {
                    continue;
                }
                $max_attempts = max( 1, min( self::MAX_ATTEMPTS, (int) ( $settings['retry_attempts'] ?? self::MAX_ATTEMPTS ) ) );
                if ( $this->all_attempts_exhausted( $job, $max_attempts ) ) {
                    // Терминальная ошибка остаётся видимой в очереди и
                    // истории, но больше не создаёт бесполезные запросы.
                    continue;
                }
                try {
                    $this->process_job( $key, $job, $settings );
                } catch ( Throwable $e ) {
                    WP_Ru_Max_Logger::log( 'autopost', 'error', 'Критическая ошибка обработки автопостинга #' . (int) ( $job['post_id'] ?? 0 ) . ': ' . $e->getMessage(), array(
                        'post_id' => (int) ( $job['post_id'] ?? 0 ),
                        'job_key' => $key,
                        'exception' => get_class( $e ),
                    ) );
                }
                $processed++;
            }
        } finally {
            $this->release_lock( $token );
        }
        return $processed;
    }

    private function process_job( $key, $job, $settings ) {
        $post = get_post( (int) ( $job['post_id'] ?? 0 ) );
        $queue = $this->get_queue();
        if ( ! $post ) {
            unset( $queue[ $key ] );
            $this->save_queue( $queue );
            return;
        }

        $job['statuses'] = isset( $job['statuses'] ) ? (array) $job['statuses'] : array();
        $job['attempts'] = isset( $job['attempts'] ) ? (array) $job['attempts'] : array();
        $job['errors']   = isset( $job['errors'] ) ? (array) $job['errors'] : array();
        $config = $this->get_post_config( $post->ID );
        /*
         * Метаданные записи — источник истины после успешной отправки.
         * Это не даёт оставшемуся старому заданию повторно отправить уже
         * опубликованную запись.
         */
        foreach ( (array) $config['status'] as $network => $state ) {
            if ( in_array( $state, array( 'sent', 'skipped' ), true ) ) {
                $job['statuses'][ $network ] = $state;
            }
        }
        if ( $this->is_config_complete( $config ) ) {
            unset( $queue[ $key ] );
            $this->save_queue( $queue );
            return;
        }
        $all_sent = true;
        $had_error = false;
        $max_attempts = max( 1, min( self::MAX_ATTEMPTS, (int) ( $settings['retry_attempts'] ?? self::MAX_ATTEMPTS ) ) );
        foreach ( (array) $job['networks'] as $network ) {
            if ( $this->is_network_excluded( $post->ID, $network ) ) {
                $job['statuses'][ $network ] = 'skipped';
                $config['status'][ $network ] = 'skipped';
                update_post_meta( $post->ID, self::META_KEY, $config );
                continue;
            }
            if ( in_array( $config['status'][ $network ] ?? '', array( 'sent', 'skipped' ), true ) ) {
                $job['statuses'][ $network ] = $config['status'][ $network ];
                continue;
            }
            $attempts = (int) ( $job['attempts'][ $network ] ?? 0 );
            if ( $attempts >= $max_attempts ) {
                $all_sent  = false;
                $had_error = true;
                continue;
            }
            $result = $this->send_network( $network, $post );
            $job['attempts'][ $network ] = $attempts + 1;
            if ( is_wp_error( $result ) ) {
                $job['statuses'][ $network ] = 'error';
                $job['errors'][ $network ]   = $result->get_error_message();
                $config['status'][ $network ] = 'error';
                $all_sent  = false;
                $had_error = true;
                WP_Ru_Max_Logger::log( 'autopost', 'error', 'Автопостинг #' . $post->ID . ' → ' . $network . ': ' . $result->get_error_message(), array( 'post_id' => $post->ID, 'network' => $network ) );
            } else {
                $job['statuses'][ $network ] = 'sent';
                /*
                 * Фиксируем каждую сеть сразу после подтверждённой отправки.
                 * Если PHP/WP-Cron остановится на следующей сети, уже
                 * отправленная сеть не будет вызвана повторно.
                 */
                $config['status'][ $network ] = 'sent';
                WP_Ru_Max_Logger::log( 'autopost', 'success', 'Автопостинг #' . $post->ID . ' → ' . $network . ': опубликовано.', array( 'post_id' => $post->ID, 'network' => $network ) );
            }
            update_post_meta( $post->ID, self::META_KEY, $config );
        }

        $job['updated'] = current_time( 'mysql' );
        $config['status'] = $job['statuses'];
        update_post_meta( $post->ID, self::META_KEY, $config );

        if ( $all_sent && ! empty( $job['networks'] ) ) {
            unset( $queue[ $key ] );
            $this->save_queue( $queue );
            $this->send_notification( $post, 'success', $job );
            return;
        }

        $retry_delay = max( 1, (int) $settings['retry_delay_minutes'] );
        $job['run_at'] = time() + $retry_delay * MINUTE_IN_SECONDS;
        $queue[ $key ]  = $job;
        if ( $had_error && $this->all_attempts_exhausted( $job, $max_attempts ) ) {
            if ( empty( $job['error_notified'] ) ) {
                $this->send_notification( $post, 'error', $job );
                $job['error_notified'] = true;
                $queue[ $key ] = $job;
            }
        }
        $this->save_queue( $queue );
        $this->schedule_worker_for( (int) $job['run_at'] );
    }

    private function is_network_excluded( $post_id, $network ) {
        $excluded = get_post_meta( $post_id, 'wp_ru_max_autopost_excluded_networks', true );
        if ( ! is_array( $excluded ) ) {
            return false;
        }
        return in_array( sanitize_key( $network ), array_map( 'sanitize_key', $excluded ), true );
    }

    private function all_attempts_exhausted( $job, $max_attempts = self::MAX_ATTEMPTS ) {
        foreach ( (array) $job['networks'] as $network ) {
            if ( 'sent' !== ( $job['statuses'][ $network ] ?? '' ) && (int) ( $job['attempts'][ $network ] ?? 0 ) < $max_attempts ) {
                return false;
            }
        }
        return true;
    }

    private function send_network( $network, $post ) {
        switch ( $network ) {
            case 'max':
                $result = WP_Ru_Max_Post_Sender::instance()->send_post_manually( $post );
                return is_wp_error( $result ) ? $result : ( $result ? true : new WP_Error( 'max_failed', 'MAX не подтвердил отправку.' ) );
            case 'telegram':
                return $this->send_telegram( $post );
            case 'vk':
                return WP_Ru_Max_Social_Poster::post_to_vk( $post );
            case 'ok':
                return WP_Ru_Max_Social_Poster::post_to_ok( $post );
            case 'dzen':
                return WP_Ru_Max_Social_Poster::post_to_dzen( $post );
        }
        return new WP_Error( 'unknown_network', 'Неизвестная социальная сеть.' );
    }

    private function send_telegram( $post ) {
        $social = get_option( 'wp_ru_max_social', array() );
        $bots   = isset( $social['telegram_bots'] ) ? (array) $social['telegram_bots'] : array();
        $msg    = WP_Ru_Max_Social_Poster::build_message( $post, 'tg', $social );
        $sent   = 0;
        $errors = array();
        foreach ( $bots as $bot ) {
            if ( empty( $bot['token'] ) ) {
                continue;
            }
            $api = new WP_Ru_Max_Telegram_API( $bot['token'] );
            foreach ( (array) ( $bot['chats'] ?? array() ) as $chat ) {
                if ( empty( $chat['chat_id'] ) ) {
                    continue;
                }
                $thumb = get_the_post_thumbnail_url( $post->ID, 'large' );
                $url   = WP_Ru_Max_Social_Poster::decorate_url( get_permalink( $post ), 'tg', $social );
                $readmore = ! empty( $social['social_readmore_tg'] );
                $readmore_text = ! empty( $social['social_readmore_text_tg'] ) ? $social['social_readmore_text_tg'] : 'Читать далее';
                if ( $thumb && $readmore ) {
                    $result = $api->send_photo_with_button( $chat['chat_id'], $thumb, $msg, $readmore_text, $url, 'HTML' );
                } elseif ( $thumb ) {
                    $result = $api->send_photo( $chat['chat_id'], $thumb, $msg, 'HTML' );
                } elseif ( $readmore ) {
                    $result = $api->send_message_with_button( $chat['chat_id'], $msg, $readmore_text, $url, 'HTML' );
                } else {
                    $result = $api->send_message( $chat['chat_id'], $msg, 'HTML' );
                }
                if ( is_wp_error( $result ) ) {
                    $errors[] = $chat['chat_id'] . ': ' . $result->get_error_message();
                } else {
                    $sent++;
                }
            }
        }
        if ( $sent > 0 && empty( $errors ) ) {
            return true;
        }
        return new WP_Error( 'telegram_failed', $sent ? implode( '; ', $errors ) : 'Нет настроенных Telegram-ботов и чатов.' );
    }

    private function send_notification( $post, $type, $job ) {
        $settings = $this->get_settings();
        if ( empty( $settings['notify_enabled'] ) ) {
            return;
        }
        if ( 'success' === $type && empty( $settings['notify_on_success'] ) ) {
            return;
        }
        if ( 'error' === $type && empty( $settings['notify_on_error'] ) ) {
            return;
        }
        $emails = preg_split( '/[\s,;]+/', (string) $settings['notify_emails'], -1, PREG_SPLIT_NO_EMPTY );
        if ( empty( $emails ) ) {
            return;
        }
        $title = get_the_title( $post );
        if ( 'success' === $type ) {
            $subject = 'WP Ru-max: запись опубликована в социальных сетях';
            $body    = 'Запись «' . $title . '» (#' . $post->ID . ') опубликована: ' . implode( ', ', (array) $job['networks'] );
        } else {
            $subject = 'WP Ru-max: ошибка автопостинга';
            $body    = 'Запись «' . $title . '» (#' . $post->ID . ') не удалось опубликовать во все сети.' . "\n\n" . print_r( $job['errors'], true );
        }
        wp_mail( $emails, $subject, $body );
    }

    private function require_admin( $manage_options = true ) {
        check_ajax_referer( 'wp_ru_max_nonce', 'nonce' );
        if ( $manage_options ? ! current_user_can( 'manage_options' ) : ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( 'Нет прав доступа.' );
        }
    }

    public function ajax_get_meta() {
        $this->require_admin( false );
        $post_id = absint( $_POST['post_id'] ?? 0 );
        if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
            wp_send_json_error( 'Нет доступа к записи.' );
        }
        wp_send_json_success( $this->get_post_config( $post_id ) );
    }

    public function ajax_save_meta() {
        $this->require_admin( false );
        $post_id = absint( $_POST['post_id'] ?? 0 );
        if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
            wp_send_json_error( 'Нет доступа к записи.' );
        }
        $networks = isset( $_POST['networks'] ) ? (array) wp_unslash( $_POST['networks'] ) : array();
        $datetime = sanitize_text_field( wp_unslash( $_POST['datetime'] ?? '' ) );
        $result = $this->save_post_config( $post_id, $networks, $datetime );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }
        wp_send_json_success( $result );
    }

    public function ajax_calendar() {
        $this->require_admin();
        /*
         * WP-Cron запускается только при посещении сайта. Если хостинг
         * пропустил loopback-запрос, обновление календаря не должно оставлять
         * просроченное задание без попытки обработки.
         */
        $this->process_due();
        $month = sanitize_text_field( wp_unslash( $_POST['month'] ?? wp_date( 'Y-m' ) ) );
        if ( ! preg_match( '/^\d{4}-\d{2}$/', $month ) ) {
            $month = wp_date( 'Y-m' );
        }
        $timezone = function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'UTC' );
        $start = $month . '-01 00:00';
        $start_date = DateTimeImmutable::createFromFormat( '!Y-m-d H:i', $start, $timezone );
        $errors = DateTimeImmutable::getLastErrors();
        if ( ! $start_date || ( is_array( $errors ) && ( $errors['warning_count'] || $errors['error_count'] ) ) || $start_date->format( 'Y-m-d H:i' ) !== $start ) {
            $month = wp_date( 'Y-m' );
            $start = $month . '-01 00:00';
            $start_date = DateTimeImmutable::createFromFormat( '!Y-m-d H:i', $start, $timezone );
        }
        $first = $start_date ? $start_date->getTimestamp() : time();
        $last = $start_date ? $start_date->modify( '+1 month' )->getTimestamp() : ( $first + 31 * DAY_IN_SECONDS );
        $posts = get_posts( array(
            // Календарь автопостинга предназначен только для статей блога.
            // Страницы, товары и другие публичные типы здесь не показываем.
            'post_type'      => 'post',
            'post_status'    => array( 'publish', 'future', 'draft', 'pending' ),
            'posts_per_page' => 500,
            'orderby'        => 'date',
            'order'          => 'ASC',
        ) );
        $available_posts = array();
        foreach ( $posts as $post ) {
            if ( current_user_can( 'edit_post', $post->ID ) ) {
                $available_posts[] = array(
                    'id'    => (int) $post->ID,
                    'title' => get_the_title( $post ) ?: '(без названия)',
                );
            }
        }
        $events_by_post = array();
        foreach ( $posts as $post ) {
            $config = $this->get_post_config( $post->ID );
            $post_id = (int) $post->ID;
            $scheduled = ! empty( $config['datetime'] )
                ? $this->datetime_to_timestamp( $config['datetime'] )
                : 0;
            $scheduled_in_month = $scheduled >= $first && $scheduled < $last;

            /*
             * Одну запись показываем одной карточкой. Раньше опубликованная
             * запись и её завершённое задание добавлялись отдельно, из-за
             * чего после успешной отправки календарь визуально дублировал её.
             */
            if ( $scheduled_in_month ) {
                $completed = $this->is_config_complete( $config );
                $has_error = in_array( 'error', (array) $config['status'], true );
                $events_by_post[ $post_id ] = array(
                    'id'        => $post_id,
                    'title'     => get_the_title( $post ),
                    'date'      => substr( $config['datetime'], 0, 10 ),
                    'datetime'  => $config['datetime'],
                    'type'      => $completed ? 'completed' : ( $has_error ? 'error' : 'scheduled' ),
                    'scheduled' => ! empty( $config['active'] ),
                    'status'    => $post->post_status,
                    'networks'  => $config['networks'],
                    'states'    => $config['status'],
                );
                continue;
            }

            $published = get_post_timestamp( $post );
            if ( $published >= $first && $published < $last ) {
                $events_by_post[ $post_id ] = array(
                    'id'        => $post_id,
                    'title'     => get_the_title( $post ),
                    'date'      => wp_date( 'Y-m-d', $published ),
                    'datetime'  => wp_date( 'Y-m-d H:i', $published ),
                    'type'      => 'published',
                    'scheduled' => ! empty( $config['active'] ),
                    'status'    => $post->post_status,
                    'networks'  => array(),
                );
            }
        }
        $events = array_values( $events_by_post );
        wp_send_json_success( array(
            'month'           => $month,
            'events'          => $events,
            'available_posts' => $available_posts,
            'queue'           => self::get_queue_items(),
            'networks'        => self::configured_networks(),
            'summary'         => self::get_queue_summary(),
        ) );
    }

    public function ajax_move() {
        $this->require_admin();
        $post_id  = absint( $_POST['post_id'] ?? 0 );
        $datetime = sanitize_text_field( wp_unslash( $_POST['datetime'] ?? '' ) );
        if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
            wp_send_json_error( 'Нет доступа к записи.' );
        }
        $config = $this->get_post_config( $post_id );
        if ( empty( $config['networks'] ) ) {
            wp_send_json_error( 'Для записи не выбраны социальные сети.' );
        }
        $result = $this->save_post_config( $post_id, $config['networks'], $datetime, true );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }
        wp_send_json_success( $result );
    }

    public function ajax_delete() {
        $this->require_admin();
        $post_id = absint( $_POST['post_id'] ?? 0 );
        if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
            wp_send_json_error( 'Нет доступа к записи.' );
        }
        $config = $this->get_post_config( $post_id );
        $config['datetime'] = '';
        /*
         * Снятие даты убирает задание, но не стирает факт уже выполненной
         * публикации. Если эту же статью позже снова поставить в календарь,
         * она останется завершённой и не уйдёт повторно.
         */
        update_post_meta( $post_id, self::META_KEY, $config );
        $queue = $this->get_queue();
        unset( $queue[ $this->job_key( $post_id ) ] );
        $this->save_queue( $queue );
        wp_send_json_success( 'Задание удалено.' );
    }

    public function ajax_run() {
        $this->require_admin();
        $post_id = absint( $_POST['post_id'] ?? 0 );
        if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
            wp_send_json_error( 'Нет доступа к записи.' );
        }
        $config = $this->get_post_config( $post_id );
        $requested_networks = isset( $_POST['networks'] ) ? (array) wp_unslash( $_POST['networks'] ) : array();
        /*
         * Ручной запуск может прийти из старого интерфейса без networks.
         * В таком случае сначала используем сохранённые сети, а если их
         * нет — все сети, которые сейчас реально подключены.
         */
        $run_networks = ! empty( $requested_networks )
            ? $requested_networks
            : ( ! empty( $config['networks'] ) ? $config['networks'] : array_keys( self::configured_networks() ) );
        $run_config = $this->normalize_config( $run_networks, '' );
        // Старое задание могло содержать сеть, которую позже отключили.
        // Не возвращаем из-за этого прежнюю ошибку: берём актуальные сети.
        if ( empty( $run_config['networks'] ) && empty( $requested_networks ) ) {
            $run_config = $this->normalize_config( array_keys( self::configured_networks() ), '' );
        }
        if ( empty( $run_config['networks'] ) ) {
            wp_send_json_error( 'Подключите хотя бы одну социальную сеть перед ручным запуском.' );
        }
        $saved = $this->save_post_config( $post_id, $run_config['networks'], $this->timestamp_to_datetime( time() ), true );
        if ( is_wp_error( $saved ) ) {
            wp_send_json_error( $saved->get_error_message() );
        }
        $processed = $this->process_due( true, $post_id );
        if ( ! $processed ) {
            WP_Ru_Max_Logger::log( 'autopost', 'warning', 'Ручной запуск автопостинга для записи #' . $post_id . ' не обработал ни одного задания.', array(
                'post_id'  => $post_id,
                'networks' => $run_config['networks'],
                'config'   => $this->get_post_config( $post_id ),
            ) );
        }
        wp_send_json_success( $this->get_post_config( $post_id ) );
    }

    public function ajax_save_settings() {
        $this->require_admin();
        $settings = $this->get_settings();
        $settings['enabled']             = ! empty( $_POST['enabled'] );
        $settings['default_time']        = preg_match( '/^\d{2}:\d{2}$/', sanitize_text_field( wp_unslash( $_POST['default_time'] ?? '' ) ) ) ? sanitize_text_field( wp_unslash( $_POST['default_time'] ) ) : '10:00';
        $settings['retry_attempts']      = max( 1, min( self::MAX_ATTEMPTS, absint( $_POST['retry_attempts'] ?? self::MAX_ATTEMPTS ) ) );
        $settings['retry_delay_minutes'] = max( 1, min( 1440, absint( $_POST['retry_delay_minutes'] ?? 5 ) ) );
        $settings['notify_enabled']      = ! empty( $_POST['notify_enabled'] );
        $settings['notify_emails']       = sanitize_text_field( wp_unslash( $_POST['notify_emails'] ?? '' ) );
        $settings['notify_on_success']   = ! empty( $_POST['notify_on_success'] );
        $settings['notify_on_error']     = ! empty( $_POST['notify_on_error'] );
        update_option( self::SETTINGS_OPTION, $settings, false );
        wp_send_json_success( 'Настройки автопостинга сохранены.' );
    }
}
