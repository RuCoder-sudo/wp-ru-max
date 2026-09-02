<?php
/**
 * Постер в социальные сети: ВКонтакте, Одноклассники, Яндекс Дзен.
 *
 * Каждый метод читает настройки из get_option('wp_ru_max_social', []).
 * MAX и Telegram реализованы в WP_Ru_Max_API и WP_Ru_Max_Telegram_API соответственно.
 *
 * ВНИМАНИЕ: не изменять код MAX API (platform-api2.max.ru) — он работает отдельно.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WP_Ru_Max_Social_Poster {

    // ─────────────────────────────────────────────────────────────────────────
    //  ВКОНТАКТЕ
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Опубликовать запись ВКонтакте через wall.post API (POST-запрос).
     *
     * Документация: https://dev.vk.com/ru/method/wall.post
     *
     * @param WP_Post $post WordPress-запись
     * @return array|WP_Error
     */
    public static function post_to_vk( $post ) {
        $social = get_option( 'wp_ru_max_social', array() );

        if ( empty( $social['vk_enabled'] ) ) {
            return new WP_Error( 'vk_disabled', 'Публикация ВКонтакте отключена.' );
        }

        // Извлечь токен (старые установки могли сохранить полный OAuth URL)
        $access_token = self::get_vk_access_token( $social );
        $owner_id     = trim( $social['vk_owner_id'] ?? '' );

        if ( empty( $access_token ) ) {
            return new WP_Error(
                'vk_no_token',
                'Не задан токен сообщества VK. Откройте настройки социальных сетей, сохраните ID приложения и группу, затем нажмите «Авторизовать сообщество VK».'
            );
        }

        $url     = self::decorate_url( get_permalink( $post ), 'vk', $social );
        $message = self::build_message( $post, 'vk', $social, $url );
        /*
         * VK API 5.199 может отклонять обычный URL в attachments ошибкой
         * link_photo_sizing_rule: для такого вложения VK ожидает фотографию.
         * URL уже входит в стандартный текст публикации, поэтому оставляем
         * его кликабельным в message и не отправляем как attachments.
         */
        if ( '' !== $url && false === strpos( $message, $url ) ) {
            $message .= "\n\n" . $url;
        }
        if ( ! empty( $social['social_readmore_vk'] ) && '' !== $url ) {
            $readmore_text = trim( (string) ( $social['social_readmore_text_vk'] ?? '' ) );
            $readmore_text = '' !== $readmore_text ? $readmore_text : 'Читать далее';
            // В VK wall.post нет API для inline-кнопок. Оставляем отдельную
            // кликабельную ссылку с пользовательским текстом под сообщением.
            $message .= "\n\n" . sanitize_text_field( $readmore_text ) . ': ' . $url;
        }

        // Авторизация выполняется для сообщества, поэтому VK требует
        // отрицательный owner_id. Принимаем в настройках и 189154877,
        // и -189154877, но в API всегда отправляем -189154877.
        if ( preg_match( '/^\d+$/', $owner_id ) && 0 !== (int) $owner_id ) {
            $owner_id = '-' . absint( $owner_id );
        }

        // VK не импортирует картинку страницы по URL автоматически.
        $photo_access_token = self::get_vk_photo_access_token( $social );
        $photo_attachment = self::upload_vk_post_photo(
            self::get_vk_post_image_url( $post ),
            $owner_id,
            $photo_access_token,
            $post->ID,
            $social
        );

        // Параметры wall.post с опциональным главным изображением.
        $params = array(
            'message'      => $message,
            'access_token' => $access_token,
            'v'            => '5.199',
        );
        if ( $photo_attachment ) {
            $params['attachments'] = $photo_attachment;
        }

        // owner_id < 0 → паблик/группа; > 0 → пользователь; 0 → не передаём (текущий пользователь)
        if ( ! empty( $owner_id ) ) {
            $params['owner_id'] = $owner_id;
        }

        // ВАЖНО: используем POST, а не GET.
        // Причины: 1) токен не попадает в server logs / referer,
        //          2) URL может превысить максимальную длину при длинных текстах.
        $response = wp_remote_post( 'https://api.vk.com/method/wall.post', array(
            'body'    => $params,
            'timeout' => 20,
        ) );

        if ( is_wp_error( $response ) ) {
            WP_Ru_Max_Logger::log( 'social', 'error',
                '[VK] HTTP Error: ' . $response->get_error_message(),
                array( 'post_id' => $post->ID )
            );
            return $response;
        }

        $data = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( isset( $data['error'] ) ) {
            $err_msg  = $data['error']['error_msg'] ?? 'Неизвестная ошибка VK API';
            $err_code = (int) ( $data['error']['error_code'] ?? 0 );

            // Человекочитаемые подсказки к частым кодам ошибок VK
            $hints = array(
                5   => 'Токен устарел или недействителен — авторизуйтесь заново.',
                7   => 'Нет прав на публикацию в стену (нужны права: wall, groups).',
                15  => 'Токен не имеет нужных прав. Для загрузки фото повторите авторизацию VK ID с правами photos, wall и groups.',
                214 => 'Стена закрыта: в настройках группы разрешена запись только руководителям.',
                220 => 'Нет прав на публикацию: для этого приложения стена ограничена.',
                27  => 'VK отклонил авторизацию сообщества. Для wall.post нужен Community Access Token, а для загрузки фото — отдельный пользовательский токен.',
                28  => 'Используется сервисный ключ приложения. Нажмите «Авторизовать сообщество VK» заново, чтобы получить токен сообщества.',
            );
            if ( isset( $hints[ $err_code ] ) ) {
                $err_msg .= ' Подсказка: ' . $hints[ $err_code ];
            }

            WP_Ru_Max_Logger::log( 'social', 'error',
                "[VK] Ошибка API (код $err_code): $err_msg",
                array( 'post_id' => $post->ID, 'response' => $data )
            );
            return new WP_Error( 'vk_api_error', $err_msg );
        }

        $vk_post_id = $data['response']['post_id'] ?? '?';
        WP_Ru_Max_Logger::log( 'social', 'success',
            "[VK] Запись #{$post->ID} «{$post->post_title}» опубликована. VK post_id: $vk_post_id",
            array( 'post_id' => $post->ID, 'vk_post_id' => $vk_post_id )
        );
        return $data;
    }

    /**
     * Загружает главное изображение записи в VK и возвращает attachment ID.
     *
     * @return string|false Например: photo-189154877_123456789
     */
    private static function upload_vk_post_photo( $image_url, $owner_id, $access_token, $post_id = 0, $social = array() ) {
        if ( empty( $image_url ) ) {
            WP_Ru_Max_Logger::log(
                'social',
                'info',
                '[VK] Изображение пропущено: у записи нет миниатюры или изображения в содержимом.',
                array( 'post_id' => $post_id )
            );
            return false;
        }
        if ( empty( $access_token ) ) {
            WP_Ru_Max_Logger::log(
                'social',
                'info',
                '[VK] Изображение пропущено: для загрузки фото нужен пользовательский VK-токен. Текст публикации будет отправлен.',
                array( 'post_id' => $post_id )
            );
            return false;
        }
        if ( ! function_exists( 'download_url' ) && defined( 'ABSPATH' ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        if ( ! function_exists( 'download_url' ) ) {
            self::log_vk_photo_warning( $post_id, 'WordPress или PHP не поддерживает загрузку изображения в VK.' );
            return false;
        }
        /*
         * Не читаем $social из воздуха: раньше эта переменная существовала
         * только в post_to_vk(), из-за чего проверка прав фото-токена
         * выполнялась с warning и не давала полезной диагностики.
         */
        $scopes_raw = is_array( $social ) ? ( $social['vk_user_scopes'] ?? '' ) : '';
        if ( is_array( $scopes_raw ) ) {
            $scopes_raw = implode( ',', $scopes_raw );
        }
        $scopes = preg_split( '/[\s,]+/', strtolower( trim( (string) $scopes_raw ) ), -1, PREG_SPLIT_NO_EMPTY );
        if ( ! empty( $scopes ) && ! in_array( 'photos', $scopes, true ) ) {
            self::log_vk_photo_warning(
                $post_id,
                'Пользовательский VK-токен не содержит право photos (полученные права: ' .
                implode( ', ', $scopes ) .
                '). Повторите авторизацию VK ID с правами photos, wall и groups.'
            );
            return false;
        }

        // Для стены сообщества VK ожидает положительный group_id. Старый
        // вариант с отрицательным owner_id приводил к ответу без upload_url.
        $upload_params = array(
            'access_token' => $access_token,
            'v'            => '5.199',
        );
        if ( (int) $owner_id < 0 ) {
            $upload_params['group_id'] = absint( $owner_id );
        } else {
            $upload_params['owner_id'] = (int) $owner_id;
        }
        $server_response = wp_remote_post( 'https://api.vk.com/method/photos.getWallUploadServer', array(
            'timeout' => 20,
            'body'    => $upload_params,
        ) );
        if ( is_wp_error( $server_response ) ) {
            self::log_vk_photo_warning( $post_id, 'Не удалось получить сервер загрузки изображения: ' . $server_response->get_error_message() );
            return false;
        }

        $server_data = json_decode( wp_remote_retrieve_body( $server_response ), true );
        $upload_url  = $server_data['response']['upload_url'] ?? '';
        if ( empty( $upload_url ) ) {
            $vk_error = $server_data['error'] ?? array();
            if ( ! empty( $vk_error ) ) {
                $details = sprintf(
                    ' код %s: %s',
                    (string) ( $vk_error['error_code'] ?? 'unknown' ),
                    (string) ( $vk_error['error_msg'] ?? 'неизвестная ошибка' )
                );
                self::log_vk_photo_warning( $post_id, 'VK не предоставил сервер загрузки изображения.' . $details . ' Публикация продолжится без изображения.' );
            } else {
                WP_Ru_Max_Logger::log(
                    'social',
                    'info',
                    '[VK] Изображение пропущено: VK не предоставил URL сервера загрузки. Публикация продолжится без изображения.',
                    array( 'post_id' => $post_id, 'http_code' => (int) wp_remote_retrieve_response_code( $server_response ) )
                );
            }
            return false;
        }

        $tmp_file = download_url( esc_url_raw( $image_url ), 20 );
        if ( is_wp_error( $tmp_file ) ) {
            self::log_vk_photo_warning( $post_id, 'Не удалось скачать главное изображение: ' . $tmp_file->get_error_message() );
            return false;
        }

        $attachment = false;
        try {
            $mime = function_exists( 'mime_content_type' ) ? mime_content_type( $tmp_file ) : 'image/jpeg';
            $path = parse_url( $image_url, PHP_URL_PATH );
            $name = $path ? sanitize_file_name( basename( $path ) ) : 'image.jpg';
            $name = '' !== $name ? $name : 'image.jpg';
            $file_contents = file_get_contents( $tmp_file );
            if ( false === $file_contents ) {
                self::log_vk_photo_warning( $post_id, 'Не удалось прочитать скачанное изображение.' );
                return false;
            }

            /*
             * Передаём multipart явно. WP_Http в ряде версий преобразует
             * массив body в application/x-www-form-urlencoded и превращает
             * CURLFile в строку, из-за чего VK получает не изображение.
             */
            $boundary = '--------------------------' . wp_generate_password( 24, false, false );
            $multipart = '--' . $boundary . "\r\n"
                . 'Content-Disposition: form-data; name="photo"; filename="' . str_replace( '"', '', $name ) . '"' . "\r\n"
                . 'Content-Type: ' . ( $mime ?: 'image/jpeg' ) . "\r\n\r\n"
                . $file_contents . "\r\n"
                . '--' . $boundary . "--\r\n";

            $upload_response = wp_remote_post( $upload_url, array(
                'timeout' => 30,
                'headers' => array(
                    'Content-Type'   => 'multipart/form-data; boundary=' . $boundary,
                    'Content-Length' => (string) strlen( $multipart ),
                ),
                'body'    => $multipart,
            ) );

            if ( is_wp_error( $upload_response ) ) {
                self::log_vk_photo_warning( $post_id, 'Ошибка загрузки изображения в VK: ' . $upload_response->get_error_message() );
            } else {
                $upload_data = json_decode( wp_remote_retrieve_body( $upload_response ), true );
                $photo       = $upload_data['photo'] ?? '';
                $server      = $upload_data['server'] ?? '';
                $hash        = $upload_data['hash'] ?? '';
                if ( '' !== (string) $photo && '' !== (string) $server && '' !== (string) $hash ) {
                    $save_params = array(
                        'photo'        => $photo,
                        'server'       => $server,
                        'hash'         => $hash,
                        'access_token' => $access_token,
                        'v'            => '5.199',
                    );
                    if ( (int) $owner_id < 0 ) {
                        $save_params['group_id'] = absint( $owner_id );
                    } else {
                        $save_params['user_id'] = (int) $owner_id;
                    }
                    $save_response = wp_remote_post( 'https://api.vk.com/method/photos.saveWallPhoto', array(
                        'timeout' => 20,
                        'body'    => $save_params,
                    ) );
                    $save_data = is_wp_error( $save_response )
                        ? array()
                        : json_decode( wp_remote_retrieve_body( $save_response ), true );
                    $saved_photo = $save_data['response'][0] ?? array();
                    if ( empty( $save_data['error'] ) && ! empty( $saved_photo['id'] ) ) {
                        $attachment = 'photo' . $owner_id . '_' . absint( $saved_photo['id'] );
                    } else {
                        $save_error = $save_data['error'] ?? array();
                        $details = ! empty( $save_error )
                            ? sprintf(
                                ' код %s: %s',
                                (string) ( $save_error['error_code'] ?? 'unknown' ),
                                (string) ( $save_error['error_msg'] ?? 'неизвестная ошибка' )
                            )
                            : '';
                        self::log_vk_photo_warning( $post_id, 'VK не сохранил изображение на стене.' . $details );
                    }
                } else {
                    self::log_vk_photo_warning( $post_id, 'VK не принял файл изображения.' );
                }
            }
        } finally {
            if ( file_exists( $tmp_file ) ) {
                wp_delete_file( $tmp_file );
            }
        }

        return $attachment;
    }

    /**
     * Возвращает изображение записи для вложения в публикацию VK.
     *
     * Приоритет у стандартной миниатюры WordPress. Если она не назначена,
     * берём первую обычную картинку из содержимого записи — это важно для
     * записей, где изображение вставлено редактором прямо в текст.
     *
     * @param WP_Post $post WordPress-запись.
     * @return string
     */
    private static function get_vk_post_image_url( $post ) {
        $thumbnail_url = get_the_post_thumbnail_url( $post->ID, 'large' );
        if ( ! empty( $thumbnail_url ) ) {
            return esc_url_raw( $thumbnail_url );
        }

        $content = (string) ( $post->post_content ?? '' );
        if ( '' === $content || ! preg_match_all(
            '/<img\b[^>]*(?:src|data-src|data-lazy-src)\s*=\s*["\']([^"\']+)["\'][^>]*>/i',
            $content,
            $matches
        ) ) {
            return '';
        }

        foreach ( $matches[1] as $candidate ) {
            $candidate = esc_url_raw( html_entity_decode( $candidate, ENT_QUOTES, get_bloginfo( 'charset' ) ?: 'UTF-8' ) );
            if ( '' !== $candidate && 0 !== strpos( strtolower( $candidate ), 'data:' ) ) {
                return $candidate;
            }
        }

        return '';
    }

    /**
     * Возвращает пользовательский токен для методов загрузки фото VK.
     * Токен сообщества подходит для wall.post, но VK запрещает им
     * photos.getWallUploadServer/photos.saveWallPhoto. Не используем
     * vk_access_token как запасной вариант: в новых установках это токен
     * сообщества, и такой fallback снова приводит к ошибке 27.
     */
    private static function get_vk_photo_access_token( $social ) {
        $token = self::extract_vk_token( $social['vk_user_access_token'] ?? '' );
        if ( '' === $token ) {
            WP_Ru_Max_Logger::log(
                'social',
                'info',
                '[VK] Изображение пропущено: не найден пользовательский VK-токен. Авторизуйте VK заново, чтобы загрузка фото не выполнялась токеном сообщества.',
                array()
            );
            return '';
        }

        $expires_at = (int) ( $social['vk_user_expires_at'] ?? 0 );
        $refresh = trim( (string) ( $social['vk_user_refresh_token'] ?? '' ) );
        if ( '' === $refresh || 0 === $expires_at || $expires_at > time() + 60 ) {
            return $token;
        }

        // Пользовательский VK ID-токен был выдан Web-приложению, а не
        // приложению сообщества, поэтому refresh тоже должен использовать
        // отдельный client_id.
        $app_id = trim( (string) ( $social['vk_user_app_id'] ?? '' ) );
        if ( '' === $app_id ) {
            return $token;
        }
        $body = array(
            'grant_type'    => 'refresh_token',
            'refresh_token' => $refresh,
            'client_id'     => $app_id,
        );
        if ( ! empty( $social['vk_device_id'] ) ) {
            $body['device_id'] = $social['vk_device_id'];
        }
        $response = wp_remote_post( 'https://id.vk.ru/oauth2/auth', array(
            'timeout' => 20,
            'headers' => array( 'Content-Type' => 'application/x-www-form-urlencoded' ),
            'body'    => $body,
        ) );
        if ( is_wp_error( $response ) ) {
            return $token;
        }
        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( ! is_array( $data ) || empty( $data['access_token'] ) ) {
            return $token;
        }

        $social['vk_user_access_token']  = sanitize_text_field( $data['access_token'] );
        $social['vk_user_refresh_token'] = sanitize_text_field( $data['refresh_token'] ?? $refresh );
        $social['vk_user_expires_at']    = time() + max( 0, (int) ( $data['expires_in'] ?? 0 ) );
        if ( isset( $data['scope'] ) ) {
            $social['vk_user_scopes'] = sanitize_text_field( $data['scope'] );
        }
        update_option( 'wp_ru_max_social', $social );
        return $social['vk_user_access_token'];
    }

    private static function log_vk_photo_warning( $post_id, $message ) {
        WP_Ru_Max_Logger::log( 'social', 'warning', '[VK] ' . $message, array(
            'post_id' => $post_id,
        ) );
    }

    /**
     * Извлекает access_token из строки.
     *
     * В старых установках после legacy VK OAuth пользователь мог вставить URL вида:
     *   https://oauth.vk.com/blank.html#access_token=TOKEN&expires_in=0&...
     * Он может вставить полный URL — мы извлекаем только токен.
     */
    private static function extract_vk_token( $value ) {
        $value = trim( $value );
        if ( empty( $value ) ) {
            return '';
        }

        // Если вставлен полный URL — ищем access_token в фрагменте (#) или query string (?)
        if ( strpos( $value, 'access_token=' ) !== false ) {
            // Пробуем фрагмент (#...)
            if ( strpos( $value, '#' ) !== false ) {
                $fragment = substr( $value, strpos( $value, '#' ) + 1 );
            } elseif ( strpos( $value, '?' ) !== false ) {
                // Пользователь мог убрать # и оставить ?
                $fragment = substr( $value, strpos( $value, '?' ) + 1 );
            } else {
                $fragment = $value;
            }
            parse_str( $fragment, $parsed );
            if ( ! empty( $parsed['access_token'] ) ) {
                return $parsed['access_token'];
            }
        }

        return $value; // Уже чистый токен
    }

    /**
     * Возвращает действующий VK Access Token.
     *
     * Старые установки с бессрочным legacy-токеном продолжают работать:
     * Community Access Token обычно бессрочный (expires_in=0). Блок
     * обновления оставлен только для совместимости с ранее сохранёнными
     * пользовательскими токенами VK ID.
     */
    public static function get_vk_access_token( $social = null ) {
        if ( null === $social ) {
            $social = get_option( 'wp_ru_max_social', array() );
        }

        /*
         * Для публикации в сообщество нужен именно токен сообщества.
         * Он хранится отдельно от service token приложения. Приоритет
         * group_tokens также исправляет старые установки, где в
         * vk_access_token остался service token после не полного OAuth flow.
         */
        $owner_id = trim( (string) ( $social['vk_owner_id'] ?? '' ) );
        $group_id = ltrim( $owner_id, '-' );
        if ( '' !== $group_id && ! empty( $social['vk_group_tokens'][ $group_id ] ) ) {
            return self::extract_vk_token( $social['vk_group_tokens'][ $group_id ] );
        }

        $access_token = self::extract_vk_token( $social['vk_access_token'] ?? '' );
        $service_token = trim( (string) ( $social['vk_service_token'] ?? $social['vk_secret_key'] ?? '' ) );

        /*
         * Защищённый ключ/service token иногда ошибочно попадает в поле
         * vk_access_token при ручном сохранении настроек. Не отправляем его
         * в wall.post: VK принимает service token только для служебных
         * методов, а для публикации нужен токен сообщества.
         */
        if ( '' !== $access_token && '' !== $service_token && hash_equals( $service_token, $access_token ) ) {
            return '';
        }

        $expires_at   = (int) ( $social['vk_expires_at'] ?? 0 );
        $refresh      = trim( (string) ( $social['vk_refresh_token'] ?? '' ) );

        if ( '' === $access_token || '' === $refresh || ( $expires_at > time() + 60 ) || 0 === $expires_at ) {
            return $access_token;
        }

        $app_id  = trim( (string) ( $social['vk_app_id'] ?? '' ) );
        $service = $service_token;
        if ( '' === $app_id ) {
            return $access_token;
        }

        $body = array(
            'grant_type'    => 'refresh_token',
            'refresh_token' => $refresh,
            'client_id'     => $app_id,
        );
        if ( '' !== $service ) {
            $body['service_token'] = $service;
        }
        if ( ! empty( $social['vk_device_id'] ) ) {
            $body['device_id'] = $social['vk_device_id'];
        }

        $response = wp_remote_post( 'https://id.vk.ru/oauth2/auth', array(
            'timeout' => 20,
            'headers' => array( 'Content-Type' => 'application/x-www-form-urlencoded' ),
            'body'    => $body,
        ) );
        if ( is_wp_error( $response ) ) {
            return $access_token;
        }

        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( ! is_array( $data ) || empty( $data['access_token'] ) ) {
            return $access_token;
        }

        $social['vk_access_token']  = sanitize_text_field( $data['access_token'] );
        $social['vk_refresh_token'] = sanitize_text_field( $data['refresh_token'] ?? $refresh );
        $social['vk_expires_at']    = time() + max( 0, (int) ( $data['expires_in'] ?? 0 ) );
        update_option( 'wp_ru_max_social', $social );
        return $social['vk_access_token'];
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  ОДНОКЛАССНИКИ
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Опубликовать запись в Одноклассниках через mediatopic.post API.
     *
     * Документация: https://apiok.ru/dev/methods/rest/mediatopic/mediatopic.post
     *
     * Что НЕ поддерживается в mediatopic.post:
     *  - Внешние фото по URL в блоке «photo» (требует предварительной загрузки через photosV2)
     *  - Произвольные URL-превью через блок «link» без предварительного scraping'а OK
     * Поэтому используем только блок «text» со ссылкой, встроенной в текст.
     *
     * @param WP_Post $post WordPress-запись
     * @return array|WP_Error
     */
    public static function post_to_ok( $post ) {
        $social = get_option( 'wp_ru_max_social', array() );

        if ( empty( $social['ok_enabled'] ) ) {
            return new WP_Error( 'ok_disabled', 'Публикация в Одноклассниках отключена.' );
        }

        $app_id       = trim( $social['ok_app_id']       ?? '' );
        $public_key   = trim( $social['ok_public_key']   ?? '' );
        $secret_key   = trim( $social['ok_secret_key']   ?? '' );
        $access_token = trim( $social['ok_access_token'] ?? '' );
        $group_id     = trim( $social['ok_group_id']     ?? '' );

        if ( empty( $app_id ) || empty( $public_key ) || empty( $secret_key ) ) {
            return new WP_Error(
                'ok_missing_keys',
                'Не заданы App ID, Public Key или Secret Key Одноклассников. Заполните все три поля в настройках.'
            );
        }
        if ( empty( $access_token ) ) {
            return new WP_Error(
                'ok_no_token',
                'Не задан Access Token Одноклассников. Авторизуйтесь через OK OAuth и вставьте токен в настройки.'
            );
        }

        $message = self::build_message( $post, 'ok', $social );
        $url     = self::decorate_url( get_permalink( $post ), 'ok', $social );

        // Вставляем ссылку в текст (OK API не принимает внешние фото по URL и не
        // создаёт link-превью из произвольных URL без отдельного API-вызова).
        $text_with_link = $message . "\n\n" . $url;

        // Медиа-топик: только текстовый блок
        $attachment = array(
            'media' => array(
                array(
                    'type' => 'text',
                    'text' => $text_with_link,
                ),
            ),
        );

        $attachment_json = wp_json_encode( $attachment, JSON_UNESCAPED_UNICODE );

        // Базовые параметры запроса.
        // ВАЖНО: access_token и application_key НЕ включаются в строку подписи.
        $params = array(
            'application_key' => $public_key,
            'attachment'      => $attachment_json,
            'format'          => 'json',
            'method'          => 'mediatopic.post',
        );

        // type и gid зависят от того, публикуем в группу или в личный профиль
        if ( ! empty( $group_id ) ) {
            $params['type'] = 'GROUP_THEME';
            $params['gid']  = $group_id;
        } else {
            $params['type'] = 'USER';
        }

        // Подпись OK API (RFC от Одноклассников):
        //   1. session_secret  = md5( access_token + application_secret_key )   [нижний регистр]
        //   2. Отсортировать все params по ключу (ksort)
        //   3. Конкатенировать: key=value для каждого параметра, без разделителей
        //   4. Добавить session_secret в конец строки
        //   5. sig = md5( итоговая_строка )                                      [нижний регистр]
        $session_secret = md5( $access_token . $secret_key );
        ksort( $params );
        $sig_str = '';
        foreach ( $params as $k => $v ) {
            $sig_str .= $k . '=' . $v;
        }
        $sig_str .= $session_secret;

        $params['sig']          = md5( $sig_str );
        $params['access_token'] = $access_token;

        $response = wp_remote_post( 'https://api.ok.ru/fb.do', array(
            'body'    => $params,
            'timeout' => 20,
        ) );

        if ( is_wp_error( $response ) ) {
            WP_Ru_Max_Logger::log( 'social', 'error',
                '[OK] HTTP Error: ' . $response->get_error_message(),
                array( 'post_id' => $post->ID )
            );
            return $response;
        }

        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );

        // OK API возвращает ошибку в виде { error_code: N, error_message: "..." }
        if ( is_array( $data ) && array_key_exists( 'error_code', $data ) ) {
            $err_code = (int) $data['error_code'];
            $err_msg  = $data['error_message'] ?? "Ошибка OK API: код $err_code";

            $hints = array(
                100 => 'Передан некорректный параметр.',
                102 => 'Токен устарел или отозван — авторизуйтесь заново.',
                103 => 'Ошибка подписи — проверьте Secret Key и Public Key.',
                104 => 'Доступ запрещён — нет прав на публикацию в группе.',
                4   => 'Некорректный параметр запроса — проверьте ID группы.',
            );
            if ( isset( $hints[ $err_code ] ) ) {
                $err_msg .= ' Подсказка: ' . $hints[ $err_code ];
            }

            WP_Ru_Max_Logger::log( 'social', 'error',
                "[OK] Ошибка API (код $err_code): $err_msg",
                array( 'post_id' => $post->ID, 'response_raw' => $body )
            );
            return new WP_Error( 'ok_api_error', $err_msg );
        }

        WP_Ru_Max_Logger::log( 'social', 'success',
            "[OK] Запись #{$post->ID} «{$post->post_title}» опубликована.",
            array( 'post_id' => $post->ID, 'response' => $data )
        );
        return $data;
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  ЯНДЕКС ДЗЕН
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Опубликовать запись в Яндекс Дзен через Publisher API.
     *
     * Эндпоинт: POST https://api.zen.yandex.ru/v1.0/channel/createPost
     * Authorization: OAuth <токен>
     *
     * Структура тела запроса:
     * {
     *   "channel_id": "...",
     *   "content": {
     *     "title": "...",
     *     "blocks": [ { "type": "PARAGRAPH", "text": "..." }, ... ]
     *   }
     * }
     *
     * АЛЬТЕРНАТИВА: RSS-синдикация — добавьте URL фида в настройках канала Дзен
     * («Настройки» → «Контент» → «Внешний источник») — это более надёжный способ
     * для большинства блогов и не требует токена.
     *
     * @param WP_Post $post WordPress-запись
     * @return array|WP_Error
     */
    public static function post_to_dzen( $post ) {
        $social = get_option( 'wp_ru_max_social', array() );

        if ( empty( $social['dzen_enabled'] ) ) {
            return new WP_Error( 'dzen_disabled', 'Публикация в Яндекс Дзен отключена.' );
        }

        $oauth_token = trim( $social['dzen_oauth_token'] ?? '' );
        $channel_id  = trim( $social['dzen_channel_id']  ?? '' );

        if ( empty( $oauth_token ) ) {
            return new WP_Error(
                'dzen_no_token',
                'Не задан OAuth-токен Яндекса. Получите его на https://oauth.yandex.ru/ и вставьте в настройки Дзена.'
            );
        }
        if ( empty( $channel_id ) ) {
            return new WP_Error(
                'dzen_no_channel',
                'Не задан ID канала Яндекс Дзен. Найдите его в настройках канала на dzen.ru (обычно — длинная строка цифр и букв в URL).'
            );
        }

        $title = get_the_title( $post );
        $url   = self::decorate_url( get_permalink( $post ), 'dzen', $social );

        // Текст публикации: полный текст записи очищается от HTML-тегов
        $content_raw  = ! empty( $post->post_content )
            ? apply_filters( 'the_content', $post->post_content )
            : get_the_excerpt( $post );
        $content_text = wp_strip_all_tags( $content_raw );

        // Строим блоки статьи согласно формату Dzen Publisher API
        $blocks = array();

        // Заглавное изображение
        $thumb_url = get_the_post_thumbnail_url( $post->ID, 'large' );
        if ( $thumb_url ) {
            $blocks[] = array(
                'type'     => 'IMAGE',
                'imageUrl' => $thumb_url,
            );
        }

        // Основной текст
        if ( ! empty( $content_text ) ) {
            $blocks[] = array(
                'type' => 'PARAGRAPH',
                'text' => $content_text,
            );
        }

        // Ссылка на оригинальную запись
        $blocks[] = array(
            'type' => 'PARAGRAPH',
            'text' => 'Читать полностью: ' . $url,
        );

        // ВАЖНО: Структура payload — title и blocks идут внутри ключа "content",
        // а не на верхнем уровне JSON-объекта.
        $payload = array(
            'channel_id' => $channel_id,
            'content'    => array(
                'title'  => $title,
                'blocks' => $blocks,
            ),
        );

        $json_body = wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

        $response = wp_remote_post( 'https://api.zen.yandex.ru/v1.0/channel/createPost', array(
            'headers' => array(
                'Authorization' => 'OAuth ' . $oauth_token,
                'Content-Type'  => 'application/json; charset=UTF-8',
            ),
            'body'    => $json_body,
            'timeout' => 30,
        ) );

        if ( is_wp_error( $response ) ) {
            WP_Ru_Max_Logger::log( 'social', 'error',
                '[Дзен] HTTP Error: ' . $response->get_error_message(),
                array( 'post_id' => $post->ID )
            );
            return $response;
        }

        $http_code = wp_remote_retrieve_response_code( $response );
        $body_raw  = wp_remote_retrieve_body( $response );
        $data      = json_decode( $body_raw, true );

        if ( $http_code < 200 || $http_code >= 300 ) {
            $err_msg = '';
            if ( is_array( $data ) ) {
                $err_msg = $data['message'] ?? $data['error'] ?? $data['description'] ?? '';
            }
            if ( empty( $err_msg ) ) {
                $err_msg = "HTTP $http_code";
            }

            $hints = array(
                400 => 'Неверный формат запроса — проверьте ID канала.',
                401 => 'OAuth-токен недействителен или истёк. Получите новый на oauth.yandex.ru.',
                403 => 'Нет прав доступа к каналу — проверьте ID канала и права токена.',
                404 => 'Канал не найден — проверьте ID канала в настройках.',
                429 => 'Превышен лимит запросов — попробуйте позже.',
            );
            if ( isset( $hints[ $http_code ] ) ) {
                $err_msg .= ' Подсказка: ' . $hints[ $http_code ];
            }

            WP_Ru_Max_Logger::log( 'social', 'error',
                "[Дзен] Ошибка API (HTTP $http_code): $err_msg",
                array( 'post_id' => $post->ID, 'response_raw' => $body_raw )
            );
            return new WP_Error( 'dzen_api_error', $err_msg );
        }

        $dzen_post_id = '';
        if ( is_array( $data ) ) {
            $dzen_post_id = $data['postId'] ?? $data['id'] ?? $data['post_id'] ?? '?';
        }
        WP_Ru_Max_Logger::log( 'social', 'success',
            "[Дзен] Запись #{$post->ID} «{$post->post_title}» опубликована. Дзен post_id: $dzen_post_id",
            array( 'post_id' => $post->ID, 'dzen_post_id' => $dzen_post_id )
        );
        return $data;
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  ВСПОМОГАТЕЛЬНЫЕ МЕТОДЫ
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Строит текст публикации из шаблона для нужной соц. сети.
     *
     * Поддерживаемые плейсхолдеры:
     *   {title}      — заголовок записи
     *   {excerpt}    — анонс / первые слова текста
     *   {url}        — ссылка на запись (с UTM, если настроено)
     *   {image}      — URL главного изображения записи
     *   {author}     — имя автора
     *   {date}       — дата публикации в формате дд.мм.гггг
     *   {terms}      — хэштеги из таксономий (настраиваются в «Настройках»)
     *   {categories} — категории через запятую
     *   {tags}       — теги через запятую
     *   {site_name}  — название сайта
     *
     * Для Telegram ({net}='tg') все вставляемые значения HTML-экранируются,
     * чтобы заголовки/анонсы с символами <, >, & не вызывали ошибку 400
     * при parse_mode=HTML.
     *
     * @param WP_Post    $post   WordPress-запись
     * @param string     $net    Код сети: 'tg', 'vk', 'ok', 'dzen'
     * @param array|null $social Настройки wp_ru_max_social (null = автозагрузка)
     * @return string
     */
    public static function build_message( $post, $net, $social = null, $url = null ) {
        if ( null === $social ) {
            $social = get_option( 'wp_ru_max_social', array() );
        }

        $tpl_key = 'social_template_' . $net;
        $tpl     = ! empty( $social[ $tpl_key ] )
            ? $social[ $tpl_key ]
            : "{title}\n\n{excerpt}\n\n{url}";

        $title   = get_the_title( $post );
        $url     = null !== $url ? (string) $url : self::decorate_url( get_permalink( $post ), $net, $social );
        $image   = (string) ( get_the_post_thumbnail_url( $post->ID, 'large' ) ?: '' );
        $excerpt = has_excerpt( $post )
            ? get_the_excerpt( $post )
            : wp_trim_words( strip_tags( $post->post_content ), 50 );
        $excerpt = wp_strip_all_tags( $excerpt );
        $author  = get_the_author_meta( 'display_name', $post->post_author );
        $date    = get_the_date( 'd.m.Y', $post );
        $site    = get_bloginfo( 'name' );

        // Хэштеги из таксономий (только если плейсхолдер присутствует в шаблоне)
        $terms_str = '';
        if ( strpos( $tpl, '{terms}' ) !== false && ! empty( $social['social_hashtag_taxonomies'] ) ) {
            foreach ( (array) $social['social_hashtag_taxonomies'] as $taxonomy ) {
                $terms = get_the_terms( $post->ID, $taxonomy );
                if ( $terms && ! is_wp_error( $terms ) ) {
                    foreach ( $terms as $term ) {
                        // Оставляем только буквы, цифры, нижнее подчёркивание
                        $slug       = preg_replace( '/[^a-zA-Z0-9а-яёА-ЯЁ]/u', '_', $term->slug );
                        $terms_str .= '#' . $slug . ' ';
                    }
                }
            }
            $terms_str = trim( $terms_str );
        }

        // Категории и теги WordPress
        $categories = '';
        $cats = get_the_category( $post->ID );
        if ( $cats ) {
            $categories = implode( ', ', array_map( static function ( $c ) { return $c->name; }, $cats ) );
        }
        $tags_str = '';
        $tags = get_the_tags( $post->ID );
        if ( $tags ) {
            $tags_str = implode( ', ', array_map( static function ( $t ) { return $t->name; }, $tags ) );
        }

        // Для Telegram HTML-экранируем переменные, вставляемые в шаблон.
        // Пользовательский шаблон может содержать валидные HTML-теги (<b>, <i>, <a> и т.д.),
        // поэтому экранируем только ЗНАЧЕНИЯ, не сам шаблон.
        if ( 'tg' === $net ) {
            $esc = ENT_QUOTES | ENT_HTML5;
            $enc = 'UTF-8';
            $title      = htmlspecialchars( $title,      $esc, $enc );
            $excerpt    = htmlspecialchars( $excerpt,    $esc, $enc );
            $author     = htmlspecialchars( $author,     $esc, $enc );
            $date       = htmlspecialchars( $date,       $esc, $enc );
            $site       = htmlspecialchars( $site,       $esc, $enc );
            $categories = htmlspecialchars( $categories, $esc, $enc );
            $tags_str   = htmlspecialchars( $tags_str,   $esc, $enc );
            $terms_str  = htmlspecialchars( $terms_str,  $esc, $enc );
            $image      = htmlspecialchars( $image,      $esc, $enc );
            // URL не экранируем — он может корректно использоваться в href=""
        }

        $msg = str_replace(
            array( '{title}', '{excerpt}', '{url}', '{image}', '{author}', '{date}', '{terms}', '{categories}', '{tags}', '{site_name}' ),
            array(  $title,    $excerpt,    $url,    $image,   $author,    $date,    $terms_str, $categories,   $tags_str, $site        ),
            $tpl
        );

        // Обрезать по лимиту платформы (если включено в «Настройках»)
        $cut_key = 'social_cut_limit_' . $net;
        if ( ! empty( $social[ $cut_key ] ) ) {
            $limits = array( 'tg' => 4096, 'vk' => 16384, 'ok' => 32000, 'dzen' => 100000 );
            $limit  = $limits[ $net ] ?? 4096;
            if ( wp_ru_max_strlen( $msg, 'UTF-8' ) > $limit ) {
                $msg = wp_ru_max_substr( $msg, 0, $limit - 3, 'UTF-8' ) . '...';
            }
        }

        return $msg;
    }

    /**
     * Сохраняет пользовательский шаблон с поддерживаемыми HTML-тегами.
     * sanitize_textarea_field() удаляет эти теги, поэтому шаблон нельзя
     * обрабатывать как обычное текстовое поле.
     *
     * @param string $value Шаблон из формы администратора.
     * @return string
     */
    public static function sanitize_template( $value ) {
        return wp_kses(
            (string) $value,
            array(
                'b' => array(),
                'i' => array(),
                'u' => array(),
                'a' => array(
                    'href' => true,
                ),
            )
        );
    }

    /**
     * Добавляет UTM-параметры и/или уникальный суффикс к URL.
     *
     * @param string     $url    Исходный URL
     * @param string     $net    Код сети
     * @param array      $social Настройки wp_ru_max_social
     * @return string
     */
    public static function decorate_url( $url, $net, $social ) {
        if ( ! empty( $social['social_url_params'] ) ) {
            $separator = strpos( $url, '?' ) !== false ? '&' : '?';
            $url      .= $separator . ltrim( $social['social_url_params'], '?&' );
        }
        if ( ! empty( $social['social_unique_link'] ) ) {
            $separator = strpos( $url, '?' ) !== false ? '&' : '?';
            $url      .= $separator . '_wprumax=' . substr( md5( uniqid( '', true ) ), 0, 6 );
        }
        return $url;
    }
}
