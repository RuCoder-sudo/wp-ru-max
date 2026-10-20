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
        $image_data = self::get_post_image_data( $post, 'vk' );
        if ( empty( $image_data['url'] ) && 'none' !== ( $image_data['mode'] ?? 'auto' ) ) {
            return new WP_Error(
                'vk_post_image_missing',
                'Публикация ВКонтакте остановлена: ' . ( $image_data['error'] ?: 'не удалось определить изображение.' ) .
                ' Выберите миниатюру, изображение из статьи или отдельный файл в предпросмотре.'
            );
        }
        $photo_access_token = self::get_vk_photo_access_token( $social );
        $social = get_option( 'wp_ru_max_social', $social );
        $photo_result = self::upload_vk_post_photo(
            $image_data['url'],
            $owner_id,
            $photo_access_token,
            $post->ID,
            $social
        );
        if ( is_wp_error( $photo_result ) ) {
            // Do not publish a text-only VK post when an image was selected
            // but VK rejected its upload. That previously looked like success.
            return new WP_Error(
                'vk_photo_upload_failed',
                'Публикация ВКонтакте остановлена: изображение не загрузилось. ' . $photo_result->get_error_message()
            );
        }
        $photo_attachment = $photo_result;

        // При публикации с фото сначала используем тот же пользовательский
        // токен, которым фото было загружено и сохранено. При явной API-ошибке
        // ниже будет повторная попытка через Community Token.
        $wall_access_token = $photo_attachment && '' !== (string) $photo_access_token
            ? $photo_access_token
            : $access_token;

        // Параметры wall.post с опциональным главным изображением.
        $params = array(
            'message'      => $message,
            'access_token' => $wall_access_token,
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

        /*
         * Некоторые приложения могут загрузить фото пользовательским
         * токеном, но отклонить тем же токеном wall.post. Повторяем только
         * после явного API-ответа об ошибке (не после сетевого таймаута),
         * чтобы не создать дубликат при неизвестном результате запроса.
         */
        if ( isset( $data['error'] ) && $wall_access_token !== $access_token ) {
            $initial_error_code = (int) ( $data['error']['error_code'] ?? 0 );
            $initial_error_msg  = (string) ( $data['error']['error_msg'] ?? '' );
            $can_retry_with_community_token = in_array( $initial_error_code, array( 5, 7, 15, 27, 28 ), true )
                || ( 100 === $initial_error_code && false !== stripos( $initial_error_msg, 'attachments support only uploaded photo' ) );

            if ( $can_retry_with_community_token ) {
                $fallback_params = $params;
                $fallback_params['access_token'] = $access_token;
                $fallback_response = wp_remote_post( 'https://api.vk.com/method/wall.post', array(
                    'body'    => $fallback_params,
                    'timeout' => 20,
                ) );

                if ( is_wp_error( $fallback_response ) ) {
                    $data['error']['error_msg'] = $initial_error_msg .
                        ' Повтор с токеном сообщества не выполнен: ' . $fallback_response->get_error_message();
                } else {
                    $fallback_data = json_decode( wp_remote_retrieve_body( $fallback_response ), true );
                    if ( is_array( $fallback_data ) && ! isset( $fallback_data['error'] ) && isset( $fallback_data['response']['post_id'] ) ) {
                        $data = $fallback_data;
                    } elseif ( is_array( $fallback_data ) && isset( $fallback_data['error'] ) ) {
                        $fallback_data['error']['error_msg'] = (string) ( $fallback_data['error']['error_msg'] ?? 'Неизвестная ошибка VK API' ) .
                            ' Повтор с токеном сообщества также не прошёл; первая ошибка пользовательского токена: ' .
                            $initial_error_code . ' — ' . $initial_error_msg;
                        $data = $fallback_data;
                    }
                }
            }
        }

        if ( isset( $data['error'] ) ) {
            $err_msg  = $data['error']['error_msg'] ?? 'Неизвестная ошибка VK API';
            $err_code = (int) ( $data['error']['error_code'] ?? 0 );

            // Человекочитаемые подсказки к частым кодам ошибок VK
            $hints = array(
                5   => 'Токен устарел или недействителен — авторизуйтесь заново.',
                7   => 'Нет прав на публикацию в стену (нужны права: wall, groups).',
                15  => 'Токен не имеет нужных прав. Для загрузки фотографии VK должен отдельно предоставить пользовательское право photos; повторная авторизация не может добавить его самостоятельно.',
                214 => 'Стена закрыта: в настройках группы разрешена запись только руководителям.',
                220 => 'Нет прав на публикацию: для этого приложения стена ограничена.',
                27  => 'VK отклонил тип токена для этой операции. Для загрузки фото нужен пользовательский токен; Community Access Token используется для обычной публикации и резервной попытки.',
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
            "[VK] Запись #{$post->ID} «{$post->post_title}» опубликована" .
            ( $photo_attachment ? ' вместе с изображением.' : ' без изображения (оно не выбрано).' ) .
            " VK post_id: $vk_post_id",
            array(
                'post_id'        => $post->ID,
                'vk_post_id'     => $vk_post_id,
                'image_attached' => (bool) $photo_attachment,
            )
        );
        return $data;
    }

    /**
     * Загружает главное изображение записи в VK и возвращает attachment ID.
     *
     * @return string|false|WP_Error Например: photo-189154877_123456789
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
            return self::vk_photo_error( $post_id, 'Для загрузки фотографии нужен пользовательский токен VK с подтверждённым правом photos.' );
        }
        if ( ! function_exists( 'download_url' ) && defined( 'ABSPATH' ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        if ( ! function_exists( 'download_url' ) ) {
            return self::vk_photo_error( $post_id, 'WordPress не смог подготовить загрузку изображения.' );
        }
        /*
         * Не делаем вывод о доступе по строке scope: старые OAuth-токены,
         * полученные вне VK ID, могут не отдавать эту строку. Источник
         * истины — реальный вызов photos.getWallUploadServer ниже.
         */

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
            return self::vk_photo_error( $post_id, 'Не удалось получить сервер загрузки изображения: ' . $server_response->get_error_message() );
        }

        $server_data = json_decode( wp_remote_retrieve_body( $server_response ), true );
        $upload_url  = $server_data['response']['upload_url'] ?? '';
        if ( empty( $upload_url ) ) {
            $vk_error = $server_data['error'] ?? array();
            if ( ! empty( $vk_error ) ) {
                return self::vk_photo_error(
                    $post_id,
                    self::format_vk_photo_api_error(
                        $vk_error['error_code'] ?? 'unknown',
                        $vk_error['error_msg'] ?? 'неизвестная ошибка'
                    )
                );
            } else {
                return self::vk_photo_error( $post_id, 'VK не предоставил URL сервера загрузки.' );
            }
        }
        if ( is_array( $social ) ) {
            if ( ! empty( $social['vk_photo_access_token'] ) ) {
                $social['vk_photo_access_verified'] = 1;
            } else {
                $social['vk_user_photos_verified'] = 1;
            }
            update_option( 'wp_ru_max_social', $social );
        }

        $tmp_file = download_url( esc_url_raw( $image_url ), 20 );
        if ( is_wp_error( $tmp_file ) ) {
            return self::vk_photo_error( $post_id, 'Не удалось скачать изображение: ' . $tmp_file->get_error_message() );
        }

        $attachment = false;
        $prepared_file = '';
        try {
            $prepared = self::prepare_social_image_file( $tmp_file, $image_url, 'ВКонтакте' );
            if ( is_wp_error( $prepared ) ) {
                return self::vk_photo_error( $post_id, $prepared->get_error_message() );
            }
            $prepared_file = $prepared['path'];
            $file_contents = file_get_contents( $prepared_file );
            if ( false === $file_contents ) {
                return self::vk_photo_error( $post_id, 'Не удалось прочитать подготовленное изображение.' );
            }

            /*
             * Передаём multipart явно. WP_Http в ряде версий преобразует
             * массив body в application/x-www-form-urlencoded и превращает
             * CURLFile в строку, из-за чего VK получает не изображение.
             */
            $boundary = '--------------------------' . wp_generate_password( 24, false, false );
            $multipart = '--' . $boundary . "\r\n"
                . 'Content-Disposition: form-data; name="photo"; filename="' . str_replace( '"', '', $prepared['name'] ) . '"' . "\r\n"
                . 'Content-Type: ' . $prepared['mime'] . "\r\n\r\n"
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
                return self::vk_photo_error( $post_id, 'Ошибка загрузки изображения в VK: ' . $upload_response->get_error_message() );
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
                    if ( is_wp_error( $save_response ) ) {
                        return self::vk_photo_error( $post_id, 'VK не сохранил загруженное фото: ' . $save_response->get_error_message() );
                    }
                    $save_data = is_wp_error( $save_response )
                        ? array()
                        : json_decode( wp_remote_retrieve_body( $save_response ), true );
                    $saved_photo = $save_data['response'][0] ?? array();
                    $saved_owner_id = isset( $saved_photo['owner_id'] ) ? (int) $saved_photo['owner_id'] : 0;
                    $saved_photo_id = absint( $saved_photo['id'] ?? 0 );
                    if ( empty( $save_data['error'] ) && 0 !== $saved_owner_id && 0 < $saved_photo_id ) {
                        /*
                         * wall.post requires the photo owner returned by
                         * photos.saveWallPhoto. Do not substitute the wall's
                         * configured owner_id: VK may return a different
                         * signed owner ID for the saved photo.
                         */
                        $attachment = 'photo' . $saved_owner_id . '_' . $saved_photo_id;
                    } else {
                        $save_error = $save_data['error'] ?? array();
                        $details = ! empty( $save_error )
                            ? sprintf(
                                ' код %s: %s',
                                (string) ( $save_error['error_code'] ?? 'unknown' ),
                                (string) ( $save_error['error_msg'] ?? 'неизвестная ошибка' )
                            )
                            : ( 0 === $saved_owner_id ? ' VK не вернул owner_id сохранённого фото.' : '' );
                        return self::vk_photo_error( $post_id, 'VK не сохранил изображение на стене.' . $details );
                    }
                } else {
                    return self::vk_photo_error( $post_id, 'VK не принял файл изображения.' );
                }
            }
        } finally {
            if ( file_exists( $tmp_file ) ) {
                wp_delete_file( $tmp_file );
            }
            if ( '' !== $prepared_file && $prepared_file !== $tmp_file && file_exists( $prepared_file ) ) {
                wp_delete_file( $prepared_file );
            }
        }

        return $attachment;
    }

    /**
     * Resolve the selected per-network image source.
     *
     * Modes: auto (featured, content, attached media), featured, content,
     * custom (selected media attachment), and none.
     */
    public static function get_post_image_data( $post, $network = 'vk', $override = null ) {
        if ( ! is_object( $post ) || empty( $post->ID ) ) {
            return array( 'url' => '', 'mode' => 'auto', 'source' => 'none', 'attachment_id' => 0, 'error' => 'Запись не найдена.' );
        }

        $network = 'tg' === $network ? 'telegram' : sanitize_key( $network );
        if ( ! in_array( $network, array( 'max', 'telegram', 'vk', 'ok', 'dzen' ), true ) ) {
            $network = 'vk';
        }
        $mode_key = '_wp_ru_max_social_image_mode_' . $network;
        $id_key   = '_wp_ru_max_social_image_' . $network;
        $mode     = is_array( $override ) && isset( $override['mode'] )
            ? sanitize_key( $override['mode'] )
            : sanitize_key( (string) get_post_meta( $post->ID, $mode_key, true ) );
        $custom_id = is_array( $override ) && array_key_exists( 'attachment_id', $override )
            ? absint( $override['attachment_id'] )
            : absint( get_post_meta( $post->ID, $id_key, true ) );
        if ( ! in_array( $mode, array( 'auto', 'featured', 'content', 'custom', 'none' ), true ) ) {
            $mode = 'auto';
        }

        $empty = array(
            'url'           => '',
            'mode'          => $mode,
            'source'        => 'none',
            'attachment_id' => 0,
            'width'         => 0,
            'height'        => 0,
            'mime'          => '',
            'error'         => '',
        );
        if ( 'none' === $mode ) {
            return $empty;
        }

        $from_attachment = static function( $attachment_id, $source ) {
            $attachment_id = absint( $attachment_id );
            if ( ! $attachment_id || ! wp_attachment_is_image( $attachment_id ) ) {
                return false;
            }
            $image = wp_get_attachment_image_src( $attachment_id, 'large' );
            if ( ! is_array( $image ) || empty( $image[0] ) ) {
                return false;
            }
            return array(
                'url'           => esc_url_raw( $image[0] ),
                'mode'          => '',
                'source'        => $source,
                'attachment_id' => $attachment_id,
                'width'         => absint( $image[1] ?? 0 ),
                'height'        => absint( $image[2] ?? 0 ),
                'mime'          => (string) get_post_mime_type( $attachment_id ),
                'error'         => '',
            );
        };

        $from_content = static function( $content ) use ( $from_attachment ) {
            if ( ! preg_match_all( '/<img\b[^>]*>/is', (string) $content, $tags ) ) {
                return false;
            }
            foreach ( $tags[0] as $tag ) {
                $candidate = '';
                foreach ( array( 'src', 'data-src', 'data-lazy-src' ) as $attribute ) {
                    if ( preg_match( '/\b' . preg_quote( $attribute, '/' ) . '\s*=\s*(["\'])(.*?)\1/is', $tag, $match ) ) {
                        $candidate = $match[2];
                        break;
                    }
                }
                if ( '' === $candidate && preg_match( '/\bsrcset\s*=\s*(["\'])(.*?)\1/is', $tag, $match ) ) {
                    $srcset_parts = preg_split( '/\s*,\s*/', trim( $match[2] ) );
                    if ( ! empty( $srcset_parts[0] ) ) {
                        $candidate = preg_split( '/\s+/', trim( $srcset_parts[0] ) )[0] ?? '';
                    }
                }
                $candidate = html_entity_decode( trim( $candidate ), ENT_QUOTES, 'UTF-8' );
                if ( '' === $candidate || 0 === strpos( strtolower( $candidate ), 'data:' ) || false !== strpos( strtolower( $candidate ), 'emoji' ) ) {
                    continue;
                }
                if ( 0 === strpos( $candidate, '//' ) ) {
                    $candidate = ( is_ssl() ? 'https:' : 'http:' ) . $candidate;
                } elseif ( '/' === substr( $candidate, 0, 1 ) ) {
                    $candidate = home_url( $candidate );
                }
                $candidate = esc_url_raw( $candidate );
                if ( '' === $candidate || ! wp_http_validate_url( $candidate ) ) {
                    continue;
                }
                $attachment_id = attachment_url_to_postid( $candidate );
                if ( $attachment_id ) {
                    $resolved = $from_attachment( $attachment_id, 'content' );
                    if ( $resolved ) {
                        return $resolved;
                    }
                }
                return array(
                    'url'           => $candidate,
                    'mode'          => '',
                    'source'        => 'content',
                    'attachment_id' => 0,
                    'width'         => 0,
                    'height'        => 0,
                    'mime'          => '',
                    'error'         => '',
                );
            }
            return false;
        };

        $thumbnail_id = absint( get_post_thumbnail_id( $post->ID ) );
        $content_image = $from_content( $post->post_content ?? '' );
        if ( 'custom' === $mode ) {
            $image = $from_attachment( $custom_id, 'custom' );
            if ( $image ) {
                $image['mode'] = $mode;
                return $image;
            }
            $empty['error'] = 'Выберите изображение из медиабиблиотеки.';
            return $empty;
        }
        if ( 'featured' === $mode ) {
            $image = $from_attachment( $thumbnail_id, 'featured' );
            if ( $image ) {
                $image['mode'] = $mode;
                return $image;
            }
            $empty['error'] = 'У записи не задана миниатюра.';
            return $empty;
        }
        if ( 'content' === $mode ) {
            if ( $content_image ) {
                $content_image['mode'] = $mode;
                return $content_image;
            }
            $empty['error'] = 'В содержимом записи не найдено изображение.';
            return $empty;
        }

        $image = $from_attachment( $thumbnail_id, 'featured' );
        if ( $image ) {
            $image['mode'] = 'auto';
            return $image;
        }
        if ( $content_image ) {
            $content_image['mode'] = 'auto';
            return $content_image;
        }
        $attachments = get_attached_media( 'image', $post->ID );
        foreach ( (array) $attachments as $media ) {
            $image = $from_attachment( $media->ID, 'attached' );
            if ( $image ) {
                $image['mode'] = 'auto';
                return $image;
            }
        }
        $empty['error'] = 'Не найдена миниатюра, картинка в тексте или прикреплённое изображение.';
        return $empty;
    }

    public static function get_post_image_url( $post, $network = 'vk', $override = null ) {
        $image = self::get_post_image_data( $post, $network, $override );
        return (string) ( $image['url'] ?? '' );
    }

    /**
     * Check VK permission by requesting an upload URL only. No image is sent.
     *
     * @return true|WP_Error
     */
    private static function format_vk_photo_api_error( $code, $text ) {
        $code = (int) $code;
        $text = sanitize_text_field( (string) $text );

        if ( 27 === $code ) {
            return 'VK вернул код 27: в поле «Пользовательский токен для фото» передан токен сообщества. Не копируйте сюда значение из поля «Access Token» — оно предназначено для публикации на стене. Для загрузки изображения нужен пользовательский access token; токен сообщества и токен VK ID для этого метода не подходят. Ответ VK: ' . $text;
        }

        if ( 15 === $code ) {
            return 'VK вернул код 15: у пользовательского токена нет подтверждённого права photos для photos.getWallUploadServer. Запросите доступ для ID приложения и этого метода у devsupport@corp.vk.com. Ответ VK: ' . $text;
        }

        return 'VK отклонил загрузку фото (код ' . $code . '): ' . $text;
    }

    public static function verify_vk_photo_access( $access_token, $group_id ) {
        $params = array(
            'access_token' => self::extract_vk_token( (string) $access_token ),
            'group_id'     => absint( $group_id ),
            'v'            => '5.199',
        );
        if ( empty( $params['access_token'] ) || empty( $params['group_id'] ) ) {
            return new WP_Error( 'vk_photo_auth_missing', 'Для проверки нужны пользовательский токен и числовой ID сообщества VK.' );
        }
        $response = wp_remote_post( 'https://api.vk.com/method/photos.getWallUploadServer', array(
            'timeout' => 20,
            'body'    => $params,
        ) );
        if ( is_wp_error( $response ) ) {
            return new WP_Error( 'vk_photo_auth_http', 'Не удалось проверить право photos в VK: ' . $response->get_error_message() );
        }
        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( ! empty( $data['response']['upload_url'] ) ) {
            return true;
        }
        $error = $data['error'] ?? array();
        $code  = $error['error_code'] ?? 'unknown';
        $text  = $error['error_msg'] ?? 'VK не вернул URL загрузки.';
        return new WP_Error(
            'vk_photo_auth_denied',
            self::format_vk_photo_api_error( $code, $text )
        );
    }

    /**
     * Download and inspect an image without publishing it.
     *
     * @return array|WP_Error
     */
    public static function inspect_image_url( $url, $max_bytes = 0 ) {
        static $cache = array();
        $url = esc_url_raw( (string) $url );
        if ( '' === $url || ! wp_http_validate_url( $url ) ) {
            return new WP_Error( 'invalid_image_url', 'URL изображения пустой, некорректный или заблокирован политикой безопасности WordPress.' );
        }
        if ( isset( $cache[ $url ] ) ) {
            $cached = $cache[ $url ];
            if ( ! is_wp_error( $cached ) && $max_bytes > 0 && $cached['size'] > $max_bytes ) {
                return new WP_Error( 'image_too_large', 'Изображение превышает максимальный размер проверки.' );
            }
            return $cached;
        }

        $attachment_id = attachment_url_to_postid( $url );
        $local_file = $attachment_id ? get_attached_file( $attachment_id ) : '';
        if ( $local_file && is_file( $local_file ) && is_readable( $local_file ) ) {
            $info = @getimagesize( $local_file );
            $size = (int) @filesize( $local_file );
            if ( ! is_array( $info ) || empty( $info['mime'] ) || empty( $info[0] ) || empty( $info[1] ) ) {
                $cache[ $url ] = new WP_Error( 'invalid_image', 'Файл медиабиблиотеки не является корректным изображением.' );
                return $cache[ $url ];
            }
            $result = array(
                'mime'      => strtolower( (string) $info['mime'] ),
                'size'      => $size,
                'width'     => absint( $info[0] ),
                'height'    => absint( $info[1] ),
                'http_code' => 200,
            );
            if ( ! in_array( $result['mime'], array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp' ), true ) ) {
                $cache[ $url ] = new WP_Error( 'unsupported_image_type', 'Формат изображения не поддерживается для отправки. Используйте JPEG, PNG, GIF или WebP.' );
            } elseif ( $result['width'] < 32 || $result['height'] < 32 || $result['width'] * $result['height'] > 50000000 ) {
                $cache[ $url ] = new WP_Error( 'invalid_image_dimensions', 'Размеры изображения некорректны или превышают предел обработки.' );
            } elseif ( $max_bytes > 0 && $size > $max_bytes ) {
                $cache[ $url ] = new WP_Error( 'image_too_large', 'Изображение превышает максимальный размер проверки.' );
            } else {
                $cache[ $url ] = $result;
            }
            return $cache[ $url ];
        }

        $response = wp_safe_remote_get( $url, array(
            'timeout'             => 20,
            'redirection'         => 3,
            'limit_response_size' => 50 * 1024 * 1024 + 1,
        ) );
        if ( is_wp_error( $response ) ) {
            $cache[ $url ] = new WP_Error( 'image_unreachable', 'Сервер сайта не смог открыть изображение: ' . $response->get_error_message() );
            return $cache[ $url ];
        }
        $code = (int) wp_remote_retrieve_response_code( $response );
        $body = wp_remote_retrieve_body( $response );
        if ( $code < 200 || $code >= 300 || '' === $body ) {
            $cache[ $url ] = new WP_Error( 'image_http_error', 'Изображение недоступно для сервера сайта (HTTP ' . $code . ').' );
            return $cache[ $url ];
        }
        $size = strlen( $body );
        if ( $size > 50 * 1024 * 1024 || ( $max_bytes > 0 && $size > $max_bytes ) ) {
            $cache[ $url ] = new WP_Error( 'image_too_large', 'Изображение превышает максимальный размер проверки.' );
            return $cache[ $url ];
        }
        if ( ! function_exists( 'getimagesizefromstring' ) ) {
            $cache[ $url ] = new WP_Error( 'image_inspector_missing', 'На сервере недоступна проверка типа изображения.' );
            return $cache[ $url ];
        }
        $info = @getimagesizefromstring( $body );
        if ( ! is_array( $info ) || empty( $info['mime'] ) || empty( $info[0] ) || empty( $info[1] ) ) {
            $cache[ $url ] = new WP_Error( 'invalid_image', 'По этому адресу находится не корректное изображение.' );
            return $cache[ $url ];
        }
        $mime = strtolower( (string) $info['mime'] );
        if ( ! in_array( $mime, array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp' ), true ) ) {
            $cache[ $url ] = new WP_Error( 'unsupported_image_type', 'Формат изображения не поддерживается для отправки. Используйте JPEG, PNG, GIF или WebP.' );
            return $cache[ $url ];
        }
        if ( (int) $info[0] < 32 || (int) $info[1] < 32 || ( (int) $info[0] * (int) $info[1] ) > 50000000 ) {
            $cache[ $url ] = new WP_Error( 'invalid_image_dimensions', 'Размеры изображения некорректны или превышают предел обработки.' );
            return $cache[ $url ];
        }
        $cache[ $url ] = array(
            'mime'      => $mime,
            'size'      => $size,
            'width'     => absint( $info[0] ),
            'height'    => absint( $info[1] ),
            'http_code' => $code,
        );
        return $cache[ $url ];
    }

    /**
     * Prepare a compatible temporary image copy for a social network.
     *
     * @return array|WP_Error
     */
    private static function prepare_social_image_file( $path, $source_url, $network_label ) {
        $network_label = sanitize_text_field( (string) $network_label );
        $file_size = (int) @filesize( $path );
        if ( $file_size <= 0 || $file_size > 50 * 1024 * 1024 ) {
            return new WP_Error( 'social_image_size', 'Изображение для ' . $network_label . ' пустое или превышает технический предел загрузки (50 МБ).' );
        }
        $info = @getimagesize( $path );
        if ( ! is_array( $info ) || empty( $info['mime'] ) || empty( $info[0] ) || empty( $info[1] ) ) {
            return new WP_Error( 'social_invalid_image', 'Скачанный файл для ' . $network_label . ' не является корректным изображением.' );
        }
        $mime = strtolower( (string) $info['mime'] );
        if ( ! in_array( $mime, array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp' ), true ) ) {
            return new WP_Error( 'social_unsupported_image', 'Формат изображения для ' . $network_label . ' не поддерживается. Используйте JPEG, PNG, GIF или WebP.' );
        }
        if ( (int) $info[0] < 32 || (int) $info[1] < 32 || ( (int) $info[0] * (int) $info[1] ) > 50000000 ) {
            return new WP_Error( 'social_image_dimensions', 'Размеры изображения для ' . $network_label . ' некорректны или слишком велики для безопасной обработки.' );
        }

        $name_path = (string) wp_parse_url( $source_url, PHP_URL_PATH );
        $name = sanitize_file_name( basename( $name_path ) );
        if ( '' === $name || '.' === $name ) {
            $name = 'wp-ru-max-image.jpg';
        }
        $needs_conversion = ! in_array( $mime, array( 'image/jpeg', 'image/png' ), true );
        $needs_resize = (int) $info[0] > 2560 || (int) $info[1] > 2560 || $file_size > 12 * 1024 * 1024;
        if ( ! $needs_conversion && ! $needs_resize ) {
            return array( 'path' => $path, 'name' => $name, 'mime' => $mime );
        }

        if ( ! function_exists( 'wp_get_image_editor' ) ) {
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }
        $editor = wp_get_image_editor( $path );
        if ( is_wp_error( $editor ) ) {
            return new WP_Error( 'social_image_processing', 'Не удалось подготовить изображение для ' . $network_label . ': ' . $editor->get_error_message() );
        }
        // Do not ask WordPress to resize a small image to a larger bounding
        // box. Some GD/Imagick combinations return "could not calculate new
        // image size" for that no-op; formats such as WebP still need
        // conversion, but not enlargement.
        if ( (int) $info[0] > 2560 || (int) $info[1] > 2560 ) {
            $resize = $editor->resize( 2560, 2560, false );
            if ( is_wp_error( $resize ) ) {
                return new WP_Error( 'social_image_resize', 'Не удалось уменьшить изображение для ' . $network_label . ': ' . $resize->get_error_message() );
            }
        }
        $target_mime = 'image/png' === $mime && ! $needs_conversion ? 'image/png' : 'image/jpeg';
        $target_ext  = 'image/png' === $target_mime ? 'png' : 'jpg';
        if ( 'image/jpeg' === $target_mime ) {
            $editor->set_quality( 86 );
        }
        $variant = wp_tempnam( 'wp-ru-max-vk-image.' . $target_ext );
        if ( ! $variant ) {
            return new WP_Error( 'social_image_temp', 'Не удалось создать временную копию изображения для ' . $network_label . '.' );
        }
        $saved = $editor->save( $variant, $target_mime );
        if ( is_wp_error( $saved ) ) {
            wp_delete_file( $variant );
            return new WP_Error( 'social_image_process_save', 'Не удалось сохранить подготовленную копию изображения для ' . $network_label . ': ' . $saved->get_error_message() );
        }
        $final_path = $saved['path'] ?? $variant;
        if ( $final_path !== $variant && file_exists( $variant ) ) {
            wp_delete_file( $variant );
        }
        if ( ! file_exists( $final_path ) || (int) filesize( $final_path ) <= 0 ) {
            return new WP_Error( 'social_image_process_empty', 'Обработанная копия изображения для ' . $network_label . ' оказалась пустой.' );
        }
        return array(
            'path' => $final_path,
            'name' => 'wp-ru-max-image.' . $target_ext,
            'mime' => $target_mime,
        );
    }

    private static function vk_photo_error( $post_id, $message ) {
        self::log_vk_photo_warning( $post_id, $message );
        return new WP_Error( 'vk_photo_upload_failed', $message );
    }

    /**
     * Возвращает пользовательский токен для методов загрузки фото VK.
     * Токен сообщества подходит для wall.post, но VK запрещает им
     * photos.getWallUploadServer/photos.saveWallPhoto. Не используем
     * vk_access_token как запасной вариант: в новых установках это токен
     * сообщества, и такой fallback снова приводит к ошибке 27.
     */
    public static function get_vk_photo_access_token( $social = null ) {
        if ( null === $social ) {
            $social = get_option( 'wp_ru_max_social', array() );
        }
        // A manually configured API token for photo uploads is kept separate
        // from the refreshable VK ID token so later VK ID authorization cannot
        // replace the token that actually has photos permission.
        $photo_token = self::extract_vk_token( $social['vk_photo_access_token'] ?? '' );
        if ( '' !== $photo_token ) {
            return $photo_token;
        }

        $token = self::extract_vk_token( $social['vk_user_access_token'] ?? '' );
        if ( '' === $token ) {
            WP_Ru_Max_Logger::log(
                'social',
                'info',
                '[VK] Не найден пользовательский VK-токен для фото. Добавьте действующий пользовательский токен и проверьте его через photos.getWallUploadServer.',
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

        $verification = self::verify_vk_photo_access( $data['access_token'], absint( $social['vk_owner_id'] ?? 0 ) );
        $social['vk_user_photos_verified'] = is_wp_error( $verification ) ? 0 : 1;
        if ( is_wp_error( $verification ) ) {
            self::log_vk_photo_warning( 0, 'После обновления VK ID токена право photos не подтвердилось: ' . $verification->get_error_message() );
        }
        $social['vk_user_access_token']  = sanitize_text_field( $data['access_token'] );
        $social['vk_user_refresh_token'] = sanitize_text_field( $data['refresh_token'] ?? $refresh );
        $social['vk_user_expires_at']    = time() + max( 0, (int) ( $data['expires_in'] ?? 0 ) );
        if ( isset( $data['scope'] ) ) {
            $scope = $data['scope'];
            if ( is_array( $scope ) ) {
                $scope = implode( ',', array_map( 'sanitize_text_field', $scope ) );
            }
            $social['vk_user_scopes'] = sanitize_text_field( (string) $scope );
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
     * Пользователь может вставить полный URL возврата с access_token —
     * извлекаем только значение токена.
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
     * Make a signed Odnoklassniki REST call.
     *
     * @return array|WP_Error
     */
    private static function ok_api_request( $method, $params, $access_token, $public_key, $secret_key ) {
        $params['application_key'] = $public_key;
        $params['format'] = 'json';
        $params['method'] = $method;
        unset( $params['access_token'], $params['sig'] );

        // OK signs all request parameters except access_token and sig.
        // application_key is included; the session secret is md5(token + app secret).
        $session_secret = md5( $access_token . $secret_key );
        ksort( $params );
        $signature_source = '';
        foreach ( $params as $key => $value ) {
            if ( is_array( $value ) || is_object( $value ) ) {
                return new WP_Error( 'ok_invalid_parameter', 'Некорректный параметр запроса ' . sanitize_key( $key ) . ' для Одноклассников.' );
            }
            $signature_source .= $key . '=' . (string) $value;
        }
        $signature_source .= $session_secret;
        $params['sig'] = md5( $signature_source );
        $params['access_token'] = $access_token;

        $response = wp_remote_post( 'https://api.ok.ru/fb.do', array(
            'body'    => $params,
            'timeout' => 25,
        ) );
        if ( is_wp_error( $response ) ) {
            return new WP_Error( 'ok_http_error', 'Ошибка соединения с API Одноклассников (' . $method . '): ' . $response->get_error_message() );
        }

        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );
        if ( ! is_array( $data ) ) {
            if ( 'mediatopic.post' === $method && ( is_string( $data ) || is_int( $data ) ) ) {
                $topic_id = trim( (string) $data );
                if ( '' !== $topic_id && preg_match( '/^\d+$/D', $topic_id ) ) {
                    return array( 'topic_id' => $topic_id );
                }
            }
            return new WP_Error( 'ok_invalid_response', 'API Одноклассников вернул некорректный ответ для ' . $method . '.' );
        }
        if ( array_key_exists( 'error_code', $data ) ) {
            $code = (int) $data['error_code'];
            $message = (string) ( $data['error_msg'] ?? $data['error_message'] ?? 'неизвестная ошибка' );
            $hint = '';
            $permission_denied = 10 === $code || false !== stripos( $message, 'PERMISSION_DENIED' );
            if ( $permission_denied && ( false !== stripos( $message, 'PHOTO_CONTENT' ) || false !== stripos( $message, 'photo' ) ) ) {
                $hint = ' Проверьте, что у приложения есть одобрение PHOTO_CONTENT и пользователь разрешил это право при OAuth. Если тест фото уже проходит, запрашивать право повторно не нужно. Если право не одобрено приложению, запросите его с App ID и описанием сценария через api-support@ok.ru, затем заново авторизуйте пользователя и сохраните новый access token.';
            } elseif ( $permission_denied && false !== stripos( $message, 'GROUP_CONTENT' ) ) {
                $hint = ' Проверьте одобрение GROUP_CONTENT для приложения, согласие пользователя и его право публиковать записи в этой группе. GROUP_THEME задаёт тип публикации, это не отдельное право приложения. Если GROUP_CONTENT не одобрено, запросите его с App ID и описанием сценария через api-support@ok.ru, затем заново авторизуйте пользователя.';
            }
            if ( '' === $hint && 'photosV2.getUploadUrl' === $method ) {
                $hint = ' Проверьте, одобрено ли приложению PHOTO_CONTENT, выдал ли пользователь это право в OAuth и действителен ли access token.';
            } elseif ( '' === $hint && 'mediatopic.post' === $method ) {
                $hint = ' Для публикации в группе проверьте право GROUP_CONTENT и права аккаунта в этой группе.';
            }
            return new WP_Error(
                'ok_api_error',
                'Одноклассники API ' . $method . ' вернул ошибку ' . $code . ': ' . $message . $hint,
                array( 'api_response' => $data )
            );
        }

        return $data;
    }

    /**
     * Check that OK grants permission to start photo upload, without uploading a
     * file or publishing a media topic.
     *
     * @return true|WP_Error
     */
    public static function verify_ok_photo_access( $social = null ) {
        if ( null === $social ) {
            $social = get_option( 'wp_ru_max_social', array() );
        }
        $access_token = trim( (string) ( $social['ok_access_token'] ?? '' ) );
        $public_key   = trim( (string) ( $social['ok_public_key'] ?? '' ) );
        $secret_key   = trim( (string) ( $social['ok_secret_key'] ?? '' ) );
        if ( '' === $access_token || '' === $public_key || '' === $secret_key ) {
            return new WP_Error( 'ok_photo_credentials_missing', 'Для проверки фото в Одноклассниках нужны Access Token, Public Key и Secret Key.' );
        }

        $params = array( 'count' => 1 );
        $group_id = trim( (string) ( $social['ok_group_id'] ?? '' ) );
        if ( '' !== $group_id ) {
            $params['gid'] = $group_id;
        }
        $result = self::ok_api_request( 'photosV2.getUploadUrl', $params, $access_token, $public_key, $secret_key );
        if ( is_wp_error( $result ) ) {
            return $result;
        }
        if ( empty( $result['upload_url'] ) || empty( $result['photo_ids'] ) ) {
            return new WP_Error( 'ok_photo_upload_url_missing', 'Одноклассники не вернули URL загрузки и идентификатор для фото.' );
        }
        return true;
    }

    /**
     * Upload an image through photosV2 and return its temporary token for a
     * mediatopic.post attachment.
     *
     * @return string|WP_Error
     */
    private static function upload_ok_post_photo( $image_url, $group_id, $access_token, $public_key, $secret_key, $post_id ) {
        $image_url = esc_url_raw( (string) $image_url );
        if ( '' === $image_url || ! wp_http_validate_url( $image_url ) ) {
            return new WP_Error( 'ok_invalid_image_url', 'Одноклассники: URL изображения недействителен или заблокирован WordPress.' );
        }

        $image_response = wp_safe_remote_get( $image_url, array(
            'timeout'             => 30,
            'redirection'         => 3,
            'limit_response_size' => 50 * 1024 * 1024 + 1,
        ) );
        if ( is_wp_error( $image_response ) ) {
            return new WP_Error( 'ok_image_download_failed', 'Не удалось скачать изображение для Одноклассников: ' . $image_response->get_error_message() );
        }
        $http_code = (int) wp_remote_retrieve_response_code( $image_response );
        $image_body = wp_remote_retrieve_body( $image_response );
        if ( $http_code < 200 || $http_code >= 300 || '' === $image_body ) {
            return new WP_Error( 'ok_image_download_failed', 'Изображение для Одноклассников недоступно (HTTP ' . $http_code . ').' );
        }
        if ( strlen( $image_body ) > 50 * 1024 * 1024 ) {
            return new WP_Error( 'ok_image_too_large', 'Изображение превышает технический предел загрузки (50 МБ).' );
        }

        $source_file = wp_tempnam( 'wp-ru-max-ok-source' );
        if ( ! $source_file || false === file_put_contents( $source_file, $image_body ) ) {
            if ( $source_file && file_exists( $source_file ) ) {
                wp_delete_file( $source_file );
            }
            return new WP_Error( 'ok_image_temp_failed', 'Не удалось создать временный файл изображения для Одноклассников.' );
        }
        unset( $image_body );

        $prepared_file = '';
        try {
            $prepared = self::prepare_social_image_file( $source_file, $image_url, 'Одноклассников' );
            if ( is_wp_error( $prepared ) ) {
                return $prepared;
            }
            $prepared_file = $prepared['path'];
            $binary = file_get_contents( $prepared_file );
            if ( false === $binary || '' === $binary ) {
                return new WP_Error( 'ok_image_read_failed', 'Не удалось прочитать подготовленное изображение для Одноклассников.' );
            }

            $upload_params = array( 'count' => 1 );
            if ( '' !== (string) $group_id ) {
                $upload_params['gid'] = (string) $group_id;
            }
            $upload_info = self::ok_api_request( 'photosV2.getUploadUrl', $upload_params, $access_token, $public_key, $secret_key );
            if ( is_wp_error( $upload_info ) ) {
                return $upload_info;
            }
            $upload_url = esc_url_raw( (string) ( $upload_info['upload_url'] ?? '' ) );
            $photo_ids = $upload_info['photo_ids'] ?? array();
            if ( ! is_array( $photo_ids ) ) {
                $photo_ids = array( $photo_ids );
            }
            $photo_id = isset( $photo_ids[0] ) ? (string) $photo_ids[0] : '';
            if ( '' === $upload_url || ! wp_http_validate_url( $upload_url ) || '' === $photo_id ) {
                return new WP_Error( 'ok_photo_upload_url_missing', 'Одноклассники не вернули корректный URL загрузки или ID фотографии.' );
            }

            $boundary = '--------------------------' . wp_generate_password( 24, false, false );
            $filename = sanitize_file_name( $prepared['name'] );
            if ( '' === $filename ) {
                $filename = 'wp-ru-max-image.jpg';
            }
            $multipart = '--' . $boundary . "\r\n"
                . 'Content-Disposition: form-data; name="pic1"; filename="' . str_replace( '"', '', $filename ) . '"' . "\r\n"
                . 'Content-Type: ' . $prepared['mime'] . "\r\n\r\n"
                . $binary . "\r\n"
                . '--' . $boundary . "--\r\n";
            unset( $binary );

            $upload_response = wp_safe_remote_post( $upload_url, array(
                'timeout' => 35,
                'headers' => array(
                    'Content-Type'   => 'multipart/form-data; boundary=' . $boundary,
                    'Content-Length' => (string) strlen( $multipart ),
                ),
                'body' => $multipart,
            ) );
            unset( $multipart );
            if ( is_wp_error( $upload_response ) ) {
                return new WP_Error( 'ok_photo_upload_failed', 'Ошибка передачи изображения на сервер Одноклассников: ' . $upload_response->get_error_message() );
            }
            $upload_code = (int) wp_remote_retrieve_response_code( $upload_response );
            $upload_body = wp_remote_retrieve_body( $upload_response );
            $upload_data = json_decode( $upload_body, true );
            if ( $upload_code < 200 || $upload_code >= 300 || ! is_array( $upload_data ) ) {
                return new WP_Error( 'ok_photo_upload_failed', 'Сервер Одноклассников отклонил загрузку изображения (HTTP ' . $upload_code . ').' );
            }
            if ( array_key_exists( 'error_code', $upload_data ) ) {
                return new WP_Error(
                    'ok_photo_upload_failed',
                    'Одноклассники отклонили изображение: ' . (string) ( $upload_data['error_message'] ?? $upload_data['error_msg'] ?? 'код ' . $upload_data['error_code'] )
                );
            }
            $photo_token = (string) ( $upload_data['photos'][ $photo_id ]['token'] ?? '' );
            if ( '' === $photo_token ) {
                return new WP_Error( 'ok_photo_token_missing', 'Одноклассники приняли запрос, но не вернули token фотографии.' );
            }

            WP_Ru_Max_Logger::log( 'social', 'info', '[OK] Изображение загружено через photosV2 и готово к включению в медиатопик.', array(
                'post_id' => $post_id,
                'photo_id' => $photo_id,
            ) );
            return $photo_token;
        } finally {
            if ( file_exists( $source_file ) ) {
                wp_delete_file( $source_file );
            }
            if ( '' !== $prepared_file && $prepared_file !== $source_file && file_exists( $prepared_file ) ) {
                wp_delete_file( $prepared_file );
            }
        }
    }

    /**
     * Publishes a text-and-photo media topic to OK. Photos are uploaded first
     * through photosV2.getUploadUrl; the returned token is attached to the
     * mediatopic.post request.
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

        $image = self::get_post_image_data( $post, 'ok' );
        $photo_token = '';
        if ( 'none' !== ( $image['mode'] ?? 'auto' ) ) {
            if ( empty( $image['url'] ) ) {
                return new WP_Error(
                    'ok_post_image_missing',
                    'Публикация в Одноклассники остановлена: ' . ( $image['error'] ?? 'изображение не найдено.' ) .
                    ' Выберите миниатюру, картинку из статьи или отдельное изображение.'
                );
            }
            $photo_token = self::upload_ok_post_photo(
                $image['url'],
                $group_id,
                $access_token,
                $public_key,
                $secret_key,
                $post->ID
            );
            if ( is_wp_error( $photo_token ) ) {
                WP_Ru_Max_Logger::log( 'social', 'error', '[OK] Изображение не загружено; медиатопик не опубликован. ' . $photo_token->get_error_message(), array(
                    'post_id' => $post->ID,
                ) );
                return new WP_Error( 'ok_photo_upload_failed', 'Публикация в Одноклассники остановлена: изображение не загрузилось. ' . $photo_token->get_error_message() );
            }
        }

        $message = self::build_message( $post, 'ok', $social );
        $url     = self::decorate_url( get_permalink( $post ), 'ok', $social );
        $text_with_link = $message;
        if ( '' !== $url && false === strpos( $text_with_link, $url ) ) {
            $text_with_link .= "\n\n" . $url;
        }

        $media = array(
            array(
                'type' => 'text',
                'text' => $text_with_link,
            ),
        );
        if ( '' !== $photo_token ) {
            $media[] = array(
                'type' => 'photo',
                'list' => array(
                    array( 'id' => $photo_token ),
                ),
            );
        }
        $attachment_json = wp_json_encode( array( 'media' => $media ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
        if ( ! is_string( $attachment_json ) || '' === $attachment_json ) {
            return new WP_Error( 'ok_attachment_encode_failed', 'Не удалось подготовить текст и фото для публикации в Одноклассниках.' );
        }

        $params = array(
            'attachment' => $attachment_json,
            'type'       => ! empty( $group_id ) ? 'GROUP_THEME' : 'USER',
        );
        if ( ! empty( $group_id ) ) {
            $params['gid'] = $group_id;
        }
        $data = self::ok_api_request( 'mediatopic.post', $params, $access_token, $public_key, $secret_key );
        if ( is_wp_error( $data ) ) {
            WP_Ru_Max_Logger::log( 'social', 'error', '[OK] Ошибка публикации медиатопика: ' . $data->get_error_message(), array(
                'post_id' => $post->ID,
            ) );
            return $data;
        }

        WP_Ru_Max_Logger::log(
            'social',
            'success',
            "[OK] Запись #{$post->ID} «{$post->post_title}» опубликована" . ( '' !== $photo_token ? ' с изображением.' : ' без изображения (режим «без фото»).' ),
            array( 'post_id' => $post->ID, 'response' => $data, 'image_attached' => '' !== $photo_token )
        );
        return $data;
    }

    /**
     * Publish an ad-hoc VK wall post from the Direct Access screen.
     *
     * @return array|WP_Error
     */
    public static function post_direct_to_vk( $message, $image_url = '', $url = '' ) {
        $social = get_option( 'wp_ru_max_social', array() );
        if ( empty( $social['vk_enabled'] ) ) {
            return new WP_Error( 'vk_disabled', 'Публикация ВКонтакте отключена.' );
        }
        $community_token = self::get_vk_access_token( $social );
        if ( '' === $community_token ) {
            return new WP_Error( 'vk_no_token', 'Не настроен токен сообщества ВКонтакте.' );
        }
        $owner_id = trim( (string) ( $social['vk_owner_id'] ?? '' ) );
        if ( preg_match( '/^\d+$/', $owner_id ) && 0 !== (int) $owner_id ) {
            $owner_id = '-' . absint( $owner_id );
        }
        if ( '' === $owner_id ) {
            return new WP_Error( 'vk_owner_missing', 'Укажите ID группы или страницы ВКонтакте в настройках.' );
        }
        $message = sanitize_textarea_field( (string) $message );
        $url = esc_url_raw( (string) $url );
        if ( '' !== $url && false === strpos( $message, $url ) ) {
            $message .= "\n\n" . $url;
        }

        $photo_attachment = '';
        $photo_token = '';
        if ( '' !== (string) $image_url ) {
            $photo_token = self::get_vk_photo_access_token( $social );
            $photo_attachment = self::upload_vk_post_photo( $image_url, $owner_id, $photo_token, 0, $social );
            if ( is_wp_error( $photo_attachment ) ) {
                return $photo_attachment;
            }
        }
        $wall_token = '' !== $photo_attachment && '' !== $photo_token ? $photo_token : $community_token;
        $params = array(
            'owner_id'     => $owner_id,
            'message'      => $message,
            'access_token' => $wall_token,
            'v'            => '5.199',
        );
        if ( '' !== (string) $photo_attachment ) {
            $params['attachments'] = $photo_attachment;
        }
        $response = wp_remote_post( 'https://api.vk.com/method/wall.post', array(
            'timeout' => 25,
            'body'    => $params,
        ) );
        if ( is_wp_error( $response ) ) {
            return new WP_Error( 'vk_direct_http', 'Ошибка соединения с VK: ' . $response->get_error_message() );
        }
        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( ! is_array( $data ) ) {
            return new WP_Error( 'vk_direct_response', 'VK вернул некорректный ответ.' );
        }
        if ( isset( $data['error'] ) && $wall_token !== $community_token ) {
            $code = (int) ( $data['error']['error_code'] ?? 0 );
            if ( in_array( $code, array( 5, 7, 15, 27, 28 ), true ) ) {
                $retry_params = $params;
                $retry_params['access_token'] = $community_token;
                $retry = wp_remote_post( 'https://api.vk.com/method/wall.post', array(
                    'timeout' => 25,
                    'body'    => $retry_params,
                ) );
                if ( ! is_wp_error( $retry ) ) {
                    $retry_data = json_decode( wp_remote_retrieve_body( $retry ), true );
                    if ( is_array( $retry_data ) ) {
                        $data = $retry_data;
                    }
                }
            }
        }
        if ( isset( $data['error'] ) ) {
            return new WP_Error(
                'vk_direct_api',
                'VK API (код ' . absint( $data['error']['error_code'] ?? 0 ) . '): ' .
                sanitize_text_field( $data['error']['error_msg'] ?? 'неизвестная ошибка' )
            );
        }
        if ( empty( $data['response']['post_id'] ) ) {
            return new WP_Error( 'vk_direct_post_missing', 'VK не подтвердил создание публикации.' );
        }
        return $data;
    }

    /**
     * Publish an ad-hoc OK media topic from the Direct Access screen.
     *
     * @return array|WP_Error
     */
    public static function post_direct_to_ok( $message, $title = '', $image_url = '' ) {
        $social = get_option( 'wp_ru_max_social', array() );
        if ( empty( $social['ok_enabled'] ) ) {
            return new WP_Error( 'ok_disabled', 'Публикация в Одноклассниках отключена.' );
        }
        $access_token = trim( (string) ( $social['ok_access_token'] ?? '' ) );
        $public_key   = trim( (string) ( $social['ok_public_key'] ?? '' ) );
        $secret_key   = trim( (string) ( $social['ok_secret_key'] ?? '' ) );
        $group_id     = trim( (string) ( $social['ok_group_id'] ?? '' ) );
        if ( '' === $access_token || '' === $public_key || '' === $secret_key ) {
            return new WP_Error( 'ok_credentials_missing', 'Не заполнены токен, Public Key или Secret Key Одноклассников.' );
        }
        $text = sanitize_textarea_field( (string) $message );
        $title = sanitize_text_field( (string) $title );
        if ( '' !== $title ) {
            $text = $title . "\n\n" . $text;
        }
        $photo_token = '';
        if ( '' !== (string) $image_url ) {
            $photo_token = self::upload_ok_post_photo(
                $image_url,
                $group_id,
                $access_token,
                $public_key,
                $secret_key,
                0
            );
            if ( is_wp_error( $photo_token ) ) {
                return $photo_token;
            }
        }
        $media = array( array( 'type' => 'text', 'text' => $text ) );
        if ( '' !== $photo_token ) {
            $media[] = array(
                'type' => 'photo',
                'list' => array( array( 'id' => $photo_token ) ),
            );
        }
        $attachment = wp_json_encode( array( 'media' => $media ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
        if ( ! is_string( $attachment ) || '' === $attachment ) {
            return new WP_Error( 'ok_direct_attachment', 'Не удалось подготовить текст и изображение для Одноклассников.' );
        }
        $params = array(
            'attachment' => $attachment,
            'type'       => '' !== $group_id ? 'GROUP_THEME' : 'USER',
        );
        if ( '' !== $group_id ) {
            $params['gid'] = $group_id;
        }
        $result = self::ok_api_request( 'mediatopic.post', $params, $access_token, $public_key, $secret_key );
        if ( is_wp_error( $result ) ) {
            return $result;
        }
        if ( empty( $result['topic_id'] ) && empty( $result['id'] ) ) {
            return new WP_Error( 'ok_direct_post_missing', 'Одноклассники не подтвердили создание медиатопика.' );
        }
        return $result;
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
        $thumb_url = self::get_post_image_url( $post, 'dzen' );
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

    /**
     * Publish a direct Dzen article, optionally with a cover image URL.
     *
     * @return array|WP_Error
     */
    public static function post_direct_to_dzen( $title, $message, $image_url = '' ) {
        $social = get_option( 'wp_ru_max_social', array() );
        if ( empty( $social['dzen_enabled'] ) ) {
            return new WP_Error( 'dzen_disabled', 'Публикация в Яндекс Дзен отключена.' );
        }
        $oauth_token = trim( (string) ( $social['dzen_oauth_token'] ?? '' ) );
        $channel_id = trim( (string) ( $social['dzen_channel_id'] ?? '' ) );
        if ( '' === $oauth_token || '' === $channel_id ) {
            return new WP_Error( 'dzen_credentials_missing', 'Не заполнены OAuth-токен или ID канала Дзен.' );
        }
        $title = sanitize_text_field( (string) $title );
        $message = sanitize_textarea_field( (string) $message );
        $blocks = array();
        if ( '' !== (string) $image_url ) {
            $image_url = esc_url_raw( (string) $image_url );
            if ( ! wp_http_validate_url( $image_url ) ) {
                return new WP_Error( 'dzen_image_url', 'URL изображения для Дзена недействителен.' );
            }
            $blocks[] = array( 'type' => 'IMAGE', 'imageUrl' => $image_url );
        }
        $blocks[] = array( 'type' => 'PARAGRAPH', 'text' => $message );
        $payload = array(
            'channel_id' => $channel_id,
            'content'    => array(
                'title'  => '' !== $title ? $title : wp_trim_words( $message, 8, '' ),
                'blocks' => $blocks,
            ),
        );
        $response = wp_remote_post( 'https://api.zen.yandex.ru/v1.0/channel/createPost', array(
            'timeout' => 30,
            'headers' => array(
                'Authorization' => 'OAuth ' . $oauth_token,
                'Content-Type'  => 'application/json; charset=UTF-8',
            ),
            'body' => wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
        ) );
        if ( is_wp_error( $response ) ) {
            return new WP_Error( 'dzen_direct_http', 'Ошибка соединения с Дзеном: ' . $response->get_error_message() );
        }
        $http_code = (int) wp_remote_retrieve_response_code( $response );
        $body_raw = wp_remote_retrieve_body( $response );
        $data = json_decode( $body_raw, true );
        if ( $http_code < 200 || $http_code >= 300 ) {
            $message = is_array( $data ) ? ( $data['message'] ?? '' ) : '';
            return new WP_Error( 'dzen_direct_api', 'Дзен API (HTTP ' . $http_code . '): ' . sanitize_text_field( $message ?: wp_strip_all_tags( $body_raw ) ) );
        }
        // Dzen may return a post ID under different keys, or an empty 2xx
        // response. Match the established scheduled-publishing behavior:
        // the successful HTTP status is authoritative.
        return is_array( $data ) ? $data : array();
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
        $image   = self::get_post_image_url( $post, $net );
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
