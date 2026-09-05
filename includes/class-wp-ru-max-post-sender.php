<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WP_Ru_Max_Post_Sender {

    private static $instance = null;

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    const QUEUE_OPTION = 'wp_ru_max_queue';
    const QUEUE_LOCK   = 'wp_ru_max_queue_lock';
    const QUEUE_LOCK_TTL = 15 * MINUTE_IN_SECONDS;
    const QUEUE_WORKER_HOOK = 'wp_ru_max_queue_worker';
    const QUEUE_WORKER_SCHEDULE = 'wp_ru_max_every_minute';
    const MAX_RETRY_ATTEMPTS = 6;

    private function __construct() {
        // Регистрируем хук всегда, а настройку проверяем внутри обработчика.
        // Иначе состояние, прочитанное при создании singleton, могло оставить
        // автопостинг без хука до следующего полного запроса WordPress.
        add_action( 'transition_post_status', array( $this, 'on_post_status_change' ), 10, 3 );
        // Хук для отложенной отправки через WP-Cron (основной путь)
        add_action( 'wp_ru_max_delayed_send', array( $this, 'do_delayed_send' ), 10, 1 );
        add_action( self::QUEUE_WORKER_HOOK, array( $this, 'run_queue_worker' ) );
        add_filter( 'cron_schedules', array( $this, 'add_cron_schedule' ) );

        // Подстраховка: WP-Cron запускается только при заходе посетителя на сайт
        // (page-load триггер), поэтому на сайтах с низким ночным трафиком
        // или при заблокированном loopback-запросе (spawn_cron) запланированное
        // событие может «зависнуть» и не сработать вовремя. Дополнительно
        // проверяем очередь на каждом заходе (фронт и админка) — это не зависит
        // от того, срабатывает ли сам WP-Cron.
        add_action( 'init', array( $this, 'maybe_process_due_queue' ), 20 );
        // Некоторые cron-обработчики и плагины добавляют задания после init.
        // Повторная проверка после полной загрузки WordPress закрывает это окно;
        // блокировка очереди не допускает двойную отправку.
        add_action( 'wp_loaded', array( $this, 'maybe_process_due_queue' ), 999 );
        // В административных запросах дополнительно проверяем очередь после
        // загрузки админской части. Это помогает сайтам, где фронтенд-запросы
        // редкие, а WP-Cron/loopback отключён или работает с задержкой.
        add_action( 'admin_init', array( $this, 'maybe_process_due_queue' ), 999 );
        add_action( 'init', array( $this, 'ensure_queue_worker' ), 21 );
    }

    public function add_cron_schedule( $schedules ) {
        if ( ! isset( $schedules[ self::QUEUE_WORKER_SCHEDULE ] ) ) {
            $schedules[ self::QUEUE_WORKER_SCHEDULE ] = array(
                'interval' => MINUTE_IN_SECONDS,
                'display'  => 'WP Ru-max: каждую минуту',
            );
        }
        return $schedules;
    }

    public function ensure_queue_worker() {
        $settings = get_option( 'wp_ru_max_settings', array() );
        if ( empty( $settings['post_sender_enabled'] ) ) {
            return;
        }
        $next = wp_next_scheduled( self::QUEUE_WORKER_HOOK );
        if ( ! $next ) {
            wp_schedule_event( time() + MINUTE_IN_SECONDS, self::QUEUE_WORKER_SCHEDULE, self::QUEUE_WORKER_HOOK );
            return;
        }

        // После обновления плагина заменить старое пятиминутное расписание
        // на минутное, иначе уже существующее событие не изменится само.
        $event = function_exists( 'wp_get_scheduled_event' )
            ? wp_get_scheduled_event( self::QUEUE_WORKER_HOOK )
            : false;
        if ( $event && isset( $event->schedule ) && $event->schedule !== self::QUEUE_WORKER_SCHEDULE ) {
            wp_clear_scheduled_hook( self::QUEUE_WORKER_HOOK );
            wp_schedule_event( time() + MINUTE_IN_SECONDS, self::QUEUE_WORKER_SCHEDULE, self::QUEUE_WORKER_HOOK );
        }
    }

    public function run_queue_worker() {
        $settings = get_option( 'wp_ru_max_settings', array() );
        if ( empty( $settings['post_sender_enabled'] ) ) {
            return;
        }
        $this->maybe_process_due_queue();
    }

    /**
     * Возвращает текущую очередь отложенных отправок (job_key => данные).
     */
    private function get_queue() {
        $queue = get_option( self::QUEUE_OPTION, array() );
        return is_array( $queue ) ? $queue : array();
    }

    private function save_queue( $queue ) {
        update_option( self::QUEUE_OPTION, $queue, false );
    }

    /**
     * Пытается установить блокировку очереди.
     *
     * В WordPress нет функции add_transient(). Для блокировки используется
     * add_option(): добавление опции с уже существующим именем возвращает
     * false, а уникальное имя опции защищено на уровне базы данных.
     *
     * В блокировке хранится срок действия, чтобы после аварийного завершения
     * запроса очередь не осталась заблокированной навсегда.
     */
    private function acquire_queue_lock( &$lock_token ) {
        $lock_token = wp_generate_uuid4();
        $now        = time();
        $lock_data  = array(
            'token'   => $lock_token,
            'expires' => $now + self::QUEUE_LOCK_TTL,
        );

        if ( add_option( self::QUEUE_LOCK, $lock_data, '', false ) ) {
            return true;
        }

        // Восстанавливаемся после запроса, который завершился до снятия
        // блокировки. Удаляем только то значение, которое прочитали:
        // условный DELETE не позволит удалить уже заменённую блокировку.
        global $wpdb;
        $existing_lock = get_option( self::QUEUE_LOCK, array() );
        $expires = is_array( $existing_lock ) && isset( $existing_lock['expires'] )
            ? (int) $existing_lock['expires']
            : 0;

        if ( $expires <= 0 || $expires <= $now ) {
            $existing_value = maybe_serialize( $existing_lock );
            $deleted        = $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s LIMIT 1",
                    self::QUEUE_LOCK,
                    $existing_value
                )
            );

            if ( 1 === (int) $deleted ) {
                wp_cache_delete( self::QUEUE_LOCK, 'options' );
                // Если другой запрос уже занял имя, add_option() вернёт false.
                return add_option( self::QUEUE_LOCK, $lock_data, '', false );
            }
        }

        return false;
    }

    /**
     * Продлевает lease только для текущего владельца блокировки.
     */
    private function refresh_queue_lock( $lock_token ) {
        global $wpdb;

        $current_lock = get_option( self::QUEUE_LOCK, array() );
        if (
            ! is_array( $current_lock )
            || empty( $current_lock['token'] )
            || ! hash_equals( (string) $current_lock['token'], (string) $lock_token )
        ) {
            return false;
        }

        $refreshed_lock = array(
            'token'   => (string) $lock_token,
            'expires' => time() + self::QUEUE_LOCK_TTL,
        );
        $updated = $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s LIMIT 1",
                maybe_serialize( $refreshed_lock ),
                self::QUEUE_LOCK,
                maybe_serialize( $current_lock )
            )
        );

        wp_cache_delete( self::QUEUE_LOCK, 'options' );
        return 1 === (int) $updated;
    }

    /**
     * Снимает только собственную блокировку очереди.
     */
    private function release_queue_lock( $lock_token ) {
        global $wpdb;

        $lock = get_option( self::QUEUE_LOCK, array() );
        if (
            is_array( $lock )
            && isset( $lock['token'] )
            && hash_equals( (string) $lock['token'], (string) $lock_token )
        ) {
            // Условное удаление защищает от ситуации, когда другой запрос
            // успел заменить lease между get_option() и DELETE.
            $deleted = $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s LIMIT 1",
                    self::QUEUE_LOCK,
                    maybe_serialize( $lock )
                )
            );
            if ( 1 === (int) $deleted ) {
                wp_cache_delete( self::QUEUE_LOCK, 'options' );
            }
        }
    }

    private function queue_add( $job_key, $post_id, $is_new, $due, $from_future = false ) {
        $queue = $this->get_queue();
        $queue[ $job_key ] = array(
            'post_id'      => $post_id,
            'is_new'       => $is_new,
            'from_future'  => $from_future,
            'due'          => $due,
        );
        $this->save_queue( $queue );
    }

    /**
     * Возвращает timestamp публикации записи без зависимости от времени
     * запуска текущего запроса. Это важно, когда WP-Cron запустил переход
     * future -> publish с опозданием.
     */
    private function get_post_publish_timestamp( $post ) {
        if ( ! empty( $post->post_date_gmt ) && '0000-00-00 00:00:00' !== $post->post_date_gmt ) {
            $timestamp = strtotime( $post->post_date_gmt . ' UTC' );
            if ( false !== $timestamp ) {
                return (int) $timestamp;
            }
        }

        if ( ! empty( $post->post_date ) && '0000-00-00 00:00:00' !== $post->post_date ) {
            $timezone = function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'UTC' );
            $date = DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $post->post_date, $timezone );
            if ( $date ) {
                return (int) $date->getTimestamp();
            }
        }

        return time();
    }

    /**
     * Считает срок обработки от даты публикации только для задания,
     * пришедшего из future -> publish. Для ручной публикации draft и для
     * обновления уже опубликованной записи задержка идёт от текущего момента.
     */
    private function get_queue_due( $post, $delay, $use_post_date ) {
        $delay = max( 0, (int) $delay );
        $base  = $use_post_date ? $this->get_post_publish_timestamp( $post ) : time();

        // WP-Cron не всегда принимает timestamp из прошлого как немедленное
        // задание, поэтому оставляем минимум одну секунду на текущий запрос.
        return max( time() + 1, $base + $delay );
    }

    /**
     * Публичный статус очереди для админ-панели (диагностика).
     */
    public static function get_queue_status() {
        $queue = get_option( self::QUEUE_OPTION, array() );
        $queue = is_array( $queue ) ? $queue : array();
        $now   = time();
        $overdue = 0;
        foreach ( $queue as $job ) {
            if ( isset( $job['due'] ) && $job['due'] < $now ) {
                $overdue++;
            }
        }
        return array(
            'total'   => count( $queue ),
            'overdue' => $overdue,
        );
    }

    /**
     * Проверяет очередь и обрабатывает все задания, время которых уже наступило.
     * Вызывается на каждом 'init' (лёгкая проверка — выходит сразу, если очередь пуста),
     * а также вручную из админки («Обработать очередь сейчас»).
     */
    public function maybe_process_due_queue( $force_all = false ) {
        $settings = get_option( 'wp_ru_max_settings', array() );
        if ( ! $force_all && empty( $settings['post_sender_enabled'] ) ) {
            return 0;
        }

        $queue = $this->get_queue();
        if ( empty( $queue ) ) {
            return 0;
        }

        // Атомарная блокировка через add_option(). В WordPress функции
        // add_transient() не существует.
        $lock_token = '';
        if ( ! $this->acquire_queue_lock( $lock_token ) ) {
            return 0;
        }

        $now       = time();
        $processed = 0;

        try {
            foreach ( $queue as $job_key => $data ) {
                $is_due = $force_all || ( isset( $data['due'] ) && $data['due'] <= $now );
                if ( $is_due ) {
                    // Обработка поста включает сетевые запросы к MAX. Продлеваем
                    // lease перед каждым заданием, чтобы длинная очередь не
                    // стала доступна второму запросу во время работы первого.
                    if ( ! $this->refresh_queue_lock( $lock_token ) ) {
                        break;
                    }
                    $this->process_job( $job_key );
                    // Снимаем оригинальное wp-cron событие, если оно ещё не сработало,
                    // чтобы успешно обработанная запись не отправилась повторно позже.
                    // Если отправка не удалась, process_job() оставляет задание
                    // в очереди и планирует повтор — его удалять нельзя.
                    $remaining_queue = $this->get_queue();
                    wp_clear_scheduled_hook( 'wp_ru_max_delayed_send', array( $job_key ) );
                    if ( isset( $remaining_queue[ $job_key ]['due'] ) ) {
                        wp_schedule_single_event(
                            (int) $remaining_queue[ $job_key ]['due'],
                            'wp_ru_max_delayed_send',
                            array( $job_key )
                        );
                    }
                    $processed++;
                }
            }
        } finally {
            $this->release_queue_lock( $lock_token );
        }

        return $processed;
    }

    /**
     * Обрабатывает одно задание из очереди. Идемпотентно: если задание уже было
     * удалено из очереди (например, обработано другим триггером первым), просто
     * ничего не делает.
     */
    private function process_job( $job_key ) {
        $queue = $this->get_queue();
        if ( ! isset( $queue[ $job_key ] ) ) {
            return; // Уже обработано.
        }
        $data = $queue[ $job_key ];

        $post = get_post( $data['post_id'] );
        if ( ! $post ) {
            unset( $queue[ $job_key ] );
            $this->save_queue( $queue );
            WP_Ru_Max_Logger::log( 'post_sender', 'warning', "Отложенная отправка: запись #{$data['post_id']} не найдена. Задание удалено из очереди.", array(
                'post_id' => $data['post_id'],
            ) );
            return;
        }

        // WordPress может запустить наше задание чуть раньше собственного
        // cron-события публикации записи. Раньше такая запись удалялась
        // навсегда со статусом future, поэтому автоматическая отправка
        // терялась, хотя ручная отправка продолжала работать.
        if ( 'future' === $post->post_status ) {
            $settings       = get_option( 'wp_ru_max_settings', array() );
            $send_delay     = isset( $settings['send_delay_seconds'] ) ? max( 0, (int) $settings['send_delay_seconds'] ) : 0;
            $next_attempt   = $this->get_queue_due( $post, $send_delay + 15, ! empty( $data['from_future'] ) );
            $data['due']    = $next_attempt;
            $data['attempts'] = 0;
            $queue[ $job_key ] = $data;
            $this->save_queue( $queue );
            wp_schedule_single_event( $next_attempt, 'wp_ru_max_delayed_send', array( $job_key ) );

            WP_Ru_Max_Logger::log( 'post_sender', 'info', "Отложенная отправка: запись #{$post->ID} ещё не опубликована (future). Задание перенесено до публикации.", array(
                'post_id'          => $post->ID,
                'job_key'          => $job_key,
                'post_date_gmt'    => $post->post_date_gmt,
                'next_attempt_gmt' => gmdate( 'Y-m-d H:i:s', $next_attempt ),
            ) );
            return;
        }

        if ( 'publish' !== $post->post_status ) {
            unset( $queue[ $job_key ] );
            $this->save_queue( $queue );
            WP_Ru_Max_Logger::log( 'post_sender', 'warning', "Отложенная отправка: запись #{$post->ID} не отправлена — текущий статус: {$post->post_status}. Задание удалено из очереди.", array(
                'post_id' => $post->ID,
                'status'  => $post->post_status,
            ) );
            return;
        }

        if ( $this->is_network_excluded( $post->ID, 'max' ) ) {
            unset( $queue[ $job_key ] );
            $this->save_queue( $queue );
            WP_Ru_Max_Logger::log( 'post_sender', 'info', "Отложенная отправка записи #{$post->ID} пропущена — MAX исключён в настройках статьи.", array(
                'post_id' => $post->ID,
            ) );
            return;
        }

        $settings = get_option( 'wp_ru_max_settings', array() );
        $sent = $this->send_post( $post, ! empty( $data['is_new'] ), $settings );

        if ( $sent ) {
            unset( $queue[ $job_key ] );
            $this->save_queue( $queue );
            return;
        }

        // Не теряем задание при временном отказе API, сетевой ошибке или
        // сбое WP-Cron. Даём несколько повторных попыток с интервалом 5 минут.
        $attempts = isset( $data['attempts'] ) ? (int) $data['attempts'] : 0;
        if ( $attempts < self::MAX_RETRY_ATTEMPTS ) {
            $attempts++;
            $retry_delay = 5 * MINUTE_IN_SECONDS;
            $data['attempts'] = $attempts;
            $data['due']      = time() + $retry_delay;
            $queue[ $job_key ] = $data;
            $this->save_queue( $queue );
            wp_schedule_single_event( $data['due'], 'wp_ru_max_delayed_send', array( $job_key ) );
            WP_Ru_Max_Logger::log( 'post_sender', 'warning', "Отправка записи #{$post->ID} не завершена. Повторная попытка {$attempts}/" . self::MAX_RETRY_ATTEMPTS . " запланирована через 5 минут.", array(
                'post_id'  => $post->ID,
                'job_key'  => $job_key,
                'attempts' => $attempts,
            ) );
        } else {
            // Не теряем публикацию из-за временного сбоя API/WP-Cron после
            // последней быстрой попытки. Оставляем её в постоянной очереди и
            // проверяем раз в час — после исправления токена/сети она уйдёт
            // автоматически, а администратор может также нажать flush.
            $data['attempts']      = 0;
            $data['due']           = time() + HOUR_IN_SECONDS;
            $data['last_error_at'] = current_time( 'mysql' );
            $queue[ $job_key ] = $data;
            $this->save_queue( $queue );
            wp_schedule_single_event( $data['due'], 'wp_ru_max_delayed_send', array( $job_key ) );
            WP_Ru_Max_Logger::log( 'post_sender', 'error', "Отправка записи #{$post->ID} не удалась после " . self::MAX_RETRY_ATTEMPTS . " попыток. Задание оставлено в очереди и будет проверено через час.", array(
                'post_id' => $post->ID,
                'job_key' => $job_key,
            ) );
        }
    }

    public function on_post_status_change( $new_status, $old_status, $post ) {
        $settings = get_option( 'wp_ru_max_settings', array() );

        if ( empty( $settings['post_sender_enabled'] ) ) {
            return;
        }

        $post_types = isset( $settings['post_types'] ) ? (array) $settings['post_types'] : array( 'post' );
        if ( ! in_array( $post->post_type, $post_types, true ) ) {
            return;
        }

        $is_new     = ( $old_status !== 'publish' && $new_status === 'publish' );
        $is_updated = ( $old_status === 'publish' && $new_status === 'publish' );

        if ( $is_new && empty( $settings['send_new_post'] ) ) {
            return;
        }
        if ( $is_updated && empty( $settings['send_updated_post'] ) ) {
            return;
        }
        if ( ! $is_new && ! $is_updated ) {
            return;
        }

        // Общий переключатель статьи применяется ко всем автоматическим
        // отправкам, а список исключений позволяет отключить отдельные сети.
        $skip = get_post_meta( $post->ID, 'wp_ru_max_skip', true );
        if ( $skip === '' || $skip === null || $skip === false ) {
            $legacy = get_post_meta( $post->ID, '_wp_ru_max_skip', true );
            if ( $legacy !== '' && $legacy !== null && $legacy !== false ) {
                $skip = $legacy;
            }
        }

        $skip_str = is_scalar( $skip ) ? trim( (string) $skip ) : '';

        if ( $skip_str === '' ) {
            $social = get_option( 'wp_ru_max_social', array() );
            $default_on = array_key_exists( 'auto_send_default', $social )
                ? ! empty( $social['auto_send_default'] )
                : ! empty( $settings['auto_send_default'] );
            if ( ! $default_on ) {
                WP_Ru_Max_Logger::log( 'post_sender', 'info', "Запись #{$post->ID} пропущена — автоотправка отключена (общий По умолчанию: ВЫКЛ).", array( 'post_id' => $post->ID ) );
                return;
            }
        } elseif ( $skip_str !== '0' ) {
            WP_Ru_Max_Logger::log( 'post_sender', 'info', "Запись #{$post->ID} пропущена — общая автоотправка выключена в настройках статьи.", array( 'post_id' => $post->ID, 'skip' => $skip ) );
            return;
        }

        if ( $this->is_network_excluded( $post->ID, 'max' ) ) {
            WP_Ru_Max_Logger::log( 'post_sender', 'info', "Запись #{$post->ID} пропущена для MAX — сеть исключена в настройках статьи.", array( 'post_id' => $post->ID, 'network' => 'max' ) );
            return;
        }

        // Фильтр по категориям
        if ( ! $this->matches_category_filter( $post->ID, $settings ) ) {
            WP_Ru_Max_Logger::log( 'post_sender', 'info', "Запись #{$post->ID} пропущена — не подходит под фильтр категорий/тегов.", array( 'post_id' => $post->ID ) );
            return;
        }

        $channels = isset( $settings['channels'] ) ? (array) $settings['channels'] : array();
        if ( empty( $channels ) ) {
            WP_Ru_Max_Logger::log( 'post_sender', 'warning', 'Нет настроенных каналов для отправки публикации.', array( 'post_id' => $post->ID ) );
            return;
        }

        // Отложенная отправка
        $delay = isset( $settings['send_delay_seconds'] ) ? (int) $settings['send_delay_seconds'] : 0;

        if ( $delay > 0 ) {
            // Запись данных для отложенного запуска. Храним задание в постоянной
            // опции (а не только в transient), чтобы её можно было перебрать и
            // обработать даже если само wp-cron событие не сработает вовремя.
            $job_key = $this->get_or_create_job_key( $post->ID, $is_new );
            $from_future = 'future' === $old_status;
            $due         = $this->get_queue_due( $post, $delay, $from_future );
            $this->queue_add( $job_key, $post->ID, $is_new, $due, $from_future );

            // Стабильный ключ не допускает накопления одинаковых заданий при
            // повторном переходе записи в publish или повторном сохранении.
            $scheduled_event = wp_next_scheduled( 'wp_ru_max_delayed_send', array( $job_key ) );
            if ( ! $scheduled_event || (int) $scheduled_event !== (int) $due ) {
                if ( $scheduled_event ) {
                    wp_clear_scheduled_hook( 'wp_ru_max_delayed_send', array( $job_key ) );
                }
                wp_schedule_single_event( $due, 'wp_ru_max_delayed_send', array( $job_key ) );
            }
            $scheduled_event = wp_next_scheduled( 'wp_ru_max_delayed_send', array( $job_key ) );

            WP_Ru_Max_Logger::log( 'post_sender', 'info', "Запись #{$post->ID} поставлена в очередь на отправку через {$delay} сек.", array(
                'post_id'         => $post->ID,
                'delay'           => $delay,
                'job_key'         => $job_key,
                'published_at'    => $post->post_date,
                'published_at_gmt'=> $post->post_date_gmt,
                'queue_due_gmt'   => gmdate( 'Y-m-d H:i:s', $due ),
                'cron_event_time' => $scheduled_event ? gmdate( 'Y-m-d H:i:s', $scheduled_event ) : null,
                'cron_scheduled'  => (bool) $scheduled_event,
            ) );
            if ( ! $scheduled_event ) {
                WP_Ru_Max_Logger::log( 'post_sender', 'error', "Не удалось запланировать WP-Cron для записи #{$post->ID}. Задание сохранено в очереди и будет обработано при следующем запуске сайта.", array(
                    'post_id' => $post->ID,
                    'job_key' => $job_key,
                    'due'     => $due,
                ) );
            }
            return;
        }

        // Немедленная отправка. Не теряем публикацию при единичной ошибке
        // API: сохраняем её в той же постоянной очереди, что и отложенные
        // задания, и передаём WP-Cron несколько попыток.
        $sent = $this->send_post( $post, $is_new, $settings );
        if ( ! $sent ) {
            $job_key = $this->get_or_create_job_key( $post->ID, $is_new );
            $due     = time() + 5 * MINUTE_IN_SECONDS;
            $this->queue_add( $job_key, $post->ID, $is_new, $due );
            wp_schedule_single_event( $due, 'wp_ru_max_delayed_send', array( $job_key ) );
            WP_Ru_Max_Logger::log( 'post_sender', 'warning', "Автоматическая отправка записи #{$post->ID} не завершена. Задание сохранено для повторной попытки через 5 минут.", array(
                'post_id' => $post->ID,
                'job_key' => $job_key,
                'due'     => gmdate( 'Y-m-d H:i:s', $due ),
            ) );
        }
    }

    private function is_network_excluded( $post_id, $network ) {
        $excluded = get_post_meta( $post_id, 'wp_ru_max_autopost_excluded_networks', true );
        if ( ! is_array( $excluded ) ) {
            return false;
        }
        return in_array( sanitize_key( $network ), array_map( 'sanitize_key', $excluded ), true );
    }

    /**
     * Возвращает существующее задание этой записи или создаёт стабильный ключ.
     * Это устраняет дубли после повторного transition_post_status и позволяет
     * новому переходу future → publish восстановить задание, удалённое старой
     * версией плагина.
     */
    private function get_or_create_job_key( $post_id, $is_new ) {
        $queue = $this->get_queue();
        foreach ( $queue as $job_key => $job ) {
            if (
                isset( $job['post_id'] ) && (int) $job['post_id'] === (int) $post_id
                && ! empty( $job['is_new'] ) === ! empty( $is_new )
            ) {
                return $job_key;
            }
        }

        return 'wp_ru_max_post_' . (int) $post_id . '_' . ( $is_new ? 'new' : 'update' );
    }

    /**
     * Обработчик отложенной отправки (WP-Cron). Основной путь срабатывания —
     * если по какой-то причине WP-Cron не вызвал этот хук вовремя, то же самое
     * задание всё равно будет подхвачено и отправлено через maybe_process_due_queue()
     * при следующем заходе на сайт (см. хук 'init' в конструкторе).
     */
    public function do_delayed_send( $job_key ) {
        $settings = get_option( 'wp_ru_max_settings', array() );
        if ( empty( $settings['post_sender_enabled'] ) ) {
            return 0;
        }

        // WP-Cron и резервный запуск через init могут прийти одновременно.
        // Используем ту же блокировку, чтобы одно задание не ушло дважды.
        $lock_token = '';
        if ( ! $this->acquire_queue_lock( $lock_token ) ) {
            return 0;
        }

        $processed = 0;
        try {
            if ( $this->refresh_queue_lock( $lock_token ) ) {
                $this->process_job( $job_key );
                $processed = 1;
            }
        } finally {
            $this->release_queue_lock( $lock_token );
        }

        return $processed;
    }

    /**
     * Отправляет запись во все настроенные каналы.
     */
    private function send_post( $post, $is_new, $settings ) {
        $channels   = isset( $settings['channels'] ) ? (array) $settings['channels'] : array();
        $message    = $this->build_post_message( $post, $is_new, $settings );
        $buttons    = $this->get_buttons( $settings, $post );
        $api        = new WP_Ru_Max_API();
        $send_image = isset( $settings['send_post_image'] ) ? (bool) $settings['send_post_image'] : true;

        // Настройки retry
        $max_retries = isset( $settings['retry_count'] ) ? (int) $settings['retry_count'] : 2;
        $retry_delay = isset( $settings['retry_delay_seconds'] ) ? (int) $settings['retry_delay_seconds'] : 5;
        $all_sent = true;

        foreach ( $channels as $channel ) {
            $chat_id = trim( $channel );
            if ( empty( $chat_id ) ) {
                continue;
            }

            $thumbnail_url = $send_image ? $this->get_post_image_url( $post ) : false;

            if ( $thumbnail_url && $send_image ) {
                // Используем send_with_retry с изображением
                $result = $api->send_with_retry(
                    $chat_id,
                    $message,
                    'html',
                    $buttons,
                    $thumbnail_url,
                    $max_retries,
                    $retry_delay
                );
            } else {
                $result = $api->send_with_retry(
                    $chat_id,
                    $message,
                    'html',
                    $buttons,
                    false,
                    $max_retries,
                    $retry_delay
                );
            }

            if ( is_wp_error( $result ) ) {
                $all_sent = false;
                WP_Ru_Max_Logger::log( 'post_sender', 'error', "Ошибка отправки записи #{$post->ID} в канал $chat_id: " . $result->get_error_message(), array(
                    'post_id' => $post->ID,
                    'chat_id' => $chat_id,
                    'is_new'  => $is_new,
                ) );
            } else {
                WP_Ru_Max_Logger::log( 'post_sender', 'success', "Запись #{$post->ID} успешно отправлена в канал $chat_id.", array(
                    'post_id' => $post->ID,
                    'chat_id' => $chat_id,
                    'is_new'  => $is_new,
                ) );
            }
        }

        return $all_sent && ! empty( $channels );
    }

    /**
     * Проверяет, подходит ли запись под фильтр категорий/тегов.
     * Если фильтр не настроен — пропускает все записи.
     */
    private function matches_category_filter( $post_id, $settings ) {
        $filter_cats = isset( $settings['filter_categories'] ) ? array_filter( array_map( 'intval', (array) $settings['filter_categories'] ) ) : array();
        $filter_tags = isset( $settings['filter_tags'] ) ? array_filter( array_map( 'intval', (array) $settings['filter_tags'] ) ) : array();

        // Если оба фильтра пустые — отправляем все
        if ( empty( $filter_cats ) && empty( $filter_tags ) ) {
            return true;
        }

        // Проверяем категории
        if ( ! empty( $filter_cats ) ) {
            $post_cats = wp_get_post_categories( $post_id, array( 'fields' => 'ids' ) );
            if ( array_intersect( $filter_cats, $post_cats ) ) {
                return true;
            }
        }

        // Проверяем теги
        if ( ! empty( $filter_tags ) ) {
            $post_tags = wp_get_post_tags( $post_id, array( 'fields' => 'ids' ) );
            if ( array_intersect( $filter_tags, $post_tags ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Получить кнопки из настроек с заменой плейсхолдеров в URL.
     */
    private function get_buttons( $settings, $post = null ) {
        $buttons = isset( $settings['post_buttons'] ) ? (array) $settings['post_buttons'] : array();
        $buttons = array_values( array_filter( $buttons, function( $b ) {
            return ! empty( $b['text'] ) && ! empty( $b['url'] );
        } ) );

        if ( empty( $buttons ) || ! $post ) {
            return $buttons;
        }

        $title     = get_the_title( $post );
        $url       = get_permalink( $post );
        $author    = get_the_author_meta( 'display_name', $post->post_author );
        $date      = get_the_date( 'd.m.Y', $post );
        $home_url  = home_url();
        $site_name = get_bloginfo( 'name' );

        foreach ( $buttons as &$btn ) {
            $btn['url'] = str_replace(
                array( '{url}', '{title}', '{author}', '{date}', '{home_url}', '{site_name}' ),
                array( $url, $title, $author, $date, $home_url, $site_name ),
                $btn['url']
            );
            $btn['url'] = $this->replace_field_placeholders( $btn['url'], $post );

            $btn['url'] = preg_replace_callback( '/\{encode:([^}]+)\}/', function( $m ) {
                return urlencode( $m[1] );
            }, $btn['url'] );
        }
        unset( $btn );

        return $buttons;
    }

    /**
     * Заменяет плейсхолдеры {meta_KEY} и {acf_KEY} в строке шаблона.
     */
    private function replace_field_placeholders( $text, $post ) {
        // Значения meta/ACF экранируются htmlspecialchars, чтобы символы <, >, &
        // не ломали HTML-разметку при отправке сообщений с parse_mode=html.
        $text = preg_replace_callback( '/\{meta_([a-zA-Z0-9_\-]+)\}/', function( $m ) use ( $post ) {
            $val = get_post_meta( $post->ID, $m[1], true );
            return is_scalar( $val ) ? htmlspecialchars( (string) $val, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ) : '';
        }, $text );

        $text = preg_replace_callback( '/\{acf_([a-zA-Z0-9_\-]+)\}/', function( $m ) use ( $post ) {
            if ( function_exists( 'get_field' ) ) {
                $val = get_field( $m[1], $post->ID );
                if ( is_array( $val ) ) {
                    $val = implode( ', ', $val );
                }
                return is_scalar( $val ) ? htmlspecialchars( (string) $val, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ) : '';
            }
            return '';
        }, $text );

        return $text;
    }

    /**
     * Построить сообщение для записи.
     */
    private function build_post_message( $post, $is_new, $settings = array() ) {
        if ( empty( $settings ) ) {
            $settings = get_option( 'wp_ru_max_settings', array() );
        }

        $title    = get_the_title( $post );
        $url      = get_permalink( $post );
        $excerpt  = has_excerpt( $post ) ? get_the_excerpt( $post ) : wp_trim_words( strip_tags( $post->post_content ), 100 );
        $excerpt  = wp_strip_all_tags( $excerpt );
        $author   = get_the_author_meta( 'display_name', $post->post_author );
        $date     = get_the_date( 'd.m.Y', $post );
        $action   = $is_new ? 'Новая публикация' : 'Обновлённая публикация';
        $pt_obj   = get_post_type_object( $post->post_type );
        $pt_label = $pt_obj ? $pt_obj->label : $post->post_type;

        $excerpt_max_chars = isset( $settings['excerpt_max_chars'] ) ? intval( $settings['excerpt_max_chars'] ) : 300;
        if ( $excerpt_max_chars > 0 && wp_ru_max_strlen( $excerpt ) > $excerpt_max_chars ) {
            $excerpt = wp_ru_max_substr( $excerpt, 0, $excerpt_max_chars ) . '…';
        }

        $template = isset( $settings['post_message_template'] ) ? trim( $settings['post_message_template'] ) : '';

        $excerpt_h  = htmlspecialchars( $excerpt, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
        $action_h   = htmlspecialchars( $action, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
        $site_name_h = htmlspecialchars( get_bloginfo( 'name' ), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
        $pt_label_h = htmlspecialchars( $pt_label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
        $url_h      = esc_url( $url );

        if ( ! empty( $template ) ) {
            $msg = str_replace(
                array( '{title}', '{excerpt}', '{url}', '{author}', '{date}', '{status}', '{site_name}', '{post_type}' ),
                array(
                    htmlspecialchars( $title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ),
                    $excerpt_h,
                    $url_h,
                    htmlspecialchars( $author, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ),
                    htmlspecialchars( $date, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ),
                    $action_h,
                    $site_name_h,
                    $pt_label_h,
                ),
                $template
            );
            $msg = $this->replace_field_placeholders( $msg, $post );
            return $msg;
        }

        $show_read_more    = isset( $settings['show_read_more'] ) ? (bool) $settings['show_read_more'] : true;
        $show_action_label = isset( $settings['show_action_label'] ) ? (bool) $settings['show_action_label'] : true;
        $show_author_date  = isset( $settings['show_author_date'] ) ? (bool) $settings['show_author_date'] : true;

        // Экранируем спецсимволы HTML (&, <, >) в динамических полях,
        // иначе они сломают парсинг HTML-разметки на стороне MAX/Telegram.
        $title_h  = htmlspecialchars( $title,  ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
        $author_h = htmlspecialchars( $author, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
        $date_h   = htmlspecialchars( $date,   ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );

        $msg = '';
        if ( $show_action_label ) {
            $msg .= "<b>$action</b>\n\n";
        }
        $msg .= "<b>$title_h</b>\n";

        if ( $excerpt ) {
            $msg .= "\n" . $excerpt_h . "\n";
        }

        if ( $show_author_date ) {
            $msg .= "\nАвтор: $author_h";
            $msg .= "\nДата: $date_h";
        }

        if ( $show_read_more ) {
            $msg .= "\n\n<a href=\"$url_h\">Читать полностью</a>";
        }

        return $msg;
    }

    public function send_post_manually( $post ) {
        $settings   = get_option( 'wp_ru_max_settings', array() );
        $channels   = isset( $settings['channels'] ) ? (array) $settings['channels'] : array();

        if ( empty( $channels ) ) {
            return new WP_Error( 'no_channels', 'Нет настроенных каналов. Добавьте канал на вкладке «Отправка публикаций».' );
        }

        $message    = $this->build_post_message( $post, false, $settings );
        $buttons    = $this->get_buttons( $settings, $post );
        $api        = new WP_Ru_Max_API();
        $send_image = isset( $settings['send_post_image'] ) ? (bool) $settings['send_post_image'] : true;
        $max_retries = isset( $settings['retry_count'] ) ? (int) $settings['retry_count'] : 2;
        $retry_delay = isset( $settings['retry_delay_seconds'] ) ? (int) $settings['retry_delay_seconds'] : 5;
        $errors     = array();

        foreach ( $channels as $channel ) {
            $chat_id = trim( $channel );
            if ( empty( $chat_id ) ) {
                continue;
            }

            $thumbnail_url = $send_image ? $this->get_post_image_url( $post ) : false;
            $result = $api->send_with_retry( $chat_id, $message, 'html', $buttons, $thumbnail_url ?: false, $max_retries, $retry_delay );

            if ( is_wp_error( $result ) ) {
                $errors[] = $chat_id . ': ' . $result->get_error_message();
                WP_Ru_Max_Logger::log( 'post_sender', 'error', "Ручная отправка записи #{$post->ID} в канал $chat_id НЕУДАЧНА: " . $result->get_error_message() );
            } else {
                WP_Ru_Max_Logger::log( 'post_sender', 'success', "Ручная отправка записи #{$post->ID} в канал $chat_id успешна." );
            }
        }

        if ( count( $errors ) === count( $channels ) && ! empty( $errors ) ) {
            return new WP_Error( 'send_failed', implode( '; ', $errors ) );
        }

        return true;
    }

    public function send_test( $chat_id ) {
        $message = "<b>Тестовое сообщение WP Ru-max</b>\n\nОтправка публикаций настроена и работает корректно!\n\nСайт: " . get_bloginfo( 'url' );
        $api     = new WP_Ru_Max_API();
        return $api->send_message( $chat_id, $message, 'html' );
    }

    /**
     * Получить URL изображения для записи.
     *
     * Порядок поиска:
     *   1. Миниатюра записи (Featured Image)
     *   2. Первый <img> из тела записи
     *   3. Первое прикреплённое изображение (медиабиблиотека)
     *
     * Возвращает URL строкой или false если ничего не найдено.
     */
    private function get_post_image_url( $post ) {
        // 1. Миниатюра записи
        $url = get_the_post_thumbnail_url( $post->ID, 'large' );
        if ( $url ) {
            WP_Ru_Max_Logger::log( 'post_sender', 'info', "Изображение для записи #{$post->ID}: миниатюра.", array( 'url' => $url ) );
            return $url;
        }

        // 2. Первый <img> из тела записи
        if ( ! empty( $post->post_content ) ) {
            preg_match( '/<img[^>]+src=["\']([^"\']+)["\'][^>]*>/i', $post->post_content, $matches );
            if ( ! empty( $matches[1] ) ) {
                $url = $matches[1];
                // Пропускаем иконки и служебные изображения (меньше 50px обычно emoji/иконки)
                if ( strpos( $url, 'data:' ) === false && strpos( $url, 'emoji' ) === false ) {
                    WP_Ru_Max_Logger::log( 'post_sender', 'info', "Изображение для записи #{$post->ID}: первый <img> из контента.", array( 'url' => $url ) );
                    return $url;
                }
            }
        }

        // 3. Первое прикреплённое изображение
        $attachments = get_attached_media( 'image', $post->ID );
        if ( ! empty( $attachments ) ) {
            $first      = reset( $attachments );
            $attach_url = wp_get_attachment_url( $first->ID );
            if ( $attach_url ) {
                WP_Ru_Max_Logger::log( 'post_sender', 'info', "Изображение для записи #{$post->ID}: прикреплённый файл.", array( 'url' => $attach_url ) );
                return $attach_url;
            }
        }

        WP_Ru_Max_Logger::log( 'post_sender', 'info', "Изображение для записи #{$post->ID} не найдено — отправка без картинки." );
        return false;
    }
}
