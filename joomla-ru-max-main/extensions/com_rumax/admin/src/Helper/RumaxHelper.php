<?php
/**
 * Shared Joomla implementation for the Ru-max MAX integration.
 *
 * The helper deliberately keeps the public option names and frontend data
 * contract close to the WordPress plugin so that the original assets can be
 * reused without changing their visual or interaction behaviour.
 */
namespace Joomla\Component\Rumax\Administrator\Helper;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Uri\Uri;

class RumaxHelper
{
    public const VERSION = '1.0.2';
    public const MAX_API = 'https://platform-api2.max.ru';

    private static $settings;
    private static $processing = false;
    private static $schemaReady;

    public static function defaults()
    {
        return [
            'bot_token' => '',
            'bot_name' => '',
            'post_sender_enabled' => false,
            'channels' => [],
            'send_new_post' => true,
            'send_updated_post' => false,
            'show_read_more' => true,
            'post_types' => ['com_content.article'],
            'filter_categories' => [],
            'filter_tags' => [],
            'send_delay_seconds' => 0,
            'retry_count' => 2,
            'retry_delay_seconds' => 5,
            'image_size_limit_mb' => 5,
            'post_template' => "{title}\n\n{excerpt}\n{url}",
            'cron_key' => '',
            'notifications_enabled' => false,
            'notify_user_registration' => true,
            'notify_customer_order' => true,
            'notify_from_email' => 'any',
            'notify_chat_ids' => [],
            'notify_template' => "<b>{email_subject}</b>\n{email_message}",
            'notify_format' => 'html',
            'send_files_by_url' => true,
            'enable_bot_api_log' => false,
            'enable_post_sender_log' => false,
            'delete_on_uninstall' => false,
            'chat_widget_enabled' => false,
            'chat_widget_size' => 'medium',
            'chat_widget_url' => '',
            'chat_widget_message_enabled' => true,
            'chat_widget_message' => 'Здравствуйте! У вас есть вопросы!? Мы всегда на связи. Кликните, чтобы нам написать!',
            'chat_widget_position' => 'right',
            'chat_widget_bottom_offset' => 20,
            'chat_widget_show_delay' => 0,
            'chat_widget_sound' => 'none',
            'chat_widget_sound_delay' => 3,
            'chat_widget_sound_pages' => 'all',
            'chat_widget_sound_specific_pages' => '',
            'chat_widget_sound_once_per_session' => false,
            'chat_widget_hide_delay' => 0,
            'chat_widget_repeat_delay' => 0,
            'chat_widget_animation' => 'none',
            'chat_widget_utm_source' => '',
            'chat_widget_utm_medium' => '',
            'chat_widget_utm_campaign' => '',
            'chat_widget_utm_content' => '',
            'chat_widget_ya_metrika_enabled' => false,
            'chat_widget_ya_metrika_counter' => '',
            'chat_widget_ya_metrika_goal' => 'chat_widget_click',
            'chat_widget_retention_enabled' => false,
            'chat_widget_retention_title' => 'Специальное предложение!',
            'chat_widget_retention_message' => 'Уже уходите? Получите скидку 10% на первый заказ, если ответим на ваш вопрос в течение 5 минут!',
            'chat_widget_retention_text_align' => 'left',
            'chat_widget_retention_buttons_align' => 'right',
            'chat_widget_retention_btn_radius' => 8,
            'chat_widget_retention_stay_text' => 'Остаться',
            'chat_widget_retention_leave_text' => 'Все равно уйти',
            'chat_widget_retention_stay_bg' => '#4a90d9',
            'chat_widget_retention_stay_color' => '#ffffff',
            'chat_widget_retention_leave_bg' => '#f0f0f0',
            'chat_widget_retention_leave_color' => '#555555',
            'share_button_enabled' => false,
            'max_oauth_enabled' => false,
            'license_key' => '',
            'license_status' => '',
            'license_domain' => '',
        ];
    }

    public static function socialDefaults()
    {
        return [
            'auto_send_default' => false,
            'telegram_enabled' => false,
            'telegram_bots' => [['name' => 'Основной бот', 'token' => '', 'chat_id' => '']],
            'telegram_template' => "{title}\n\n{excerpt}\n\n{url}",
            'vk_enabled' => false,
            'vk_owner_id' => '',
            'vk_access_token' => '',
            'vk_app_id' => '',
            'vk_group_tokens' => [],
            'ok_enabled' => false,
            'ok_app_id' => '',
            'ok_public_key' => '',
            'ok_secret_key' => '',
            'ok_access_token' => '',
            'ok_group_id' => '',
            'dzen_enabled' => false,
            'dzen_channel_id' => '',
            'dzen_oauth_token' => '',
            'social_post_types' => ['com_content.article'],
            'social_hashtag_taxonomies' => [],
            'social_url_params' => '',
            'social_unique_link' => false,
        ];
    }

    public static function proDefaults()
    {
        return [
            'enabled' => true,
            'channels' => [
                'phone' => ['enabled' => false, 'value' => '', 'desktop' => true, 'mobile' => true],
                'telegram' => ['enabled' => false, 'value' => '', 'desktop' => true, 'mobile' => true],
                'vkontakte' => ['enabled' => false, 'value' => '', 'desktop' => true, 'mobile' => true],
                'contact' => ['enabled' => false, 'value' => '', 'desktop' => true, 'mobile' => true],
                'email' => ['enabled' => false, 'value' => '', 'desktop' => true, 'mobile' => true],
            ],
            'custom_channels' => [],
            'channel_order' => ['phone', 'telegram', 'vkontakte', 'contact', 'email'],
            'style' => [
                'layout' => 'circle', 'icon_background' => '#4f46e5', 'icon_color' => '#ffffff',
                'cta' => 'Написать нам', 'cta_background' => '#4f46e5', 'cta_text_color' => '#ffffff',
                'backdrop_blur' => false, 'attention' => 'pulse',
            ],
            'chat' => [
                'live_chat_enabled' => true, 'target' => '', 'title' => 'Живой чат',
                'welcome' => 'Здравствуйте! Чем можем помочь?', 'manager_online' => false,
                'bot_enabled' => true, 'schedule_enabled' => false, 'schedule_days' => [1, 2, 3, 4, 5],
                'schedule_start' => '09:00', 'schedule_end' => '18:00', 'bot_name' => 'Помощник',
                'bot_offline_message' => 'Менеджер сейчас не в сети. Я уже передал ваше сообщение команде и постараюсь помочь прямо сейчас.',
                'faq_enabled' => true,
                'faq' => [
                    ['question' => 'Как быстро вы отвечаете?', 'answer' => 'Обычно менеджер отвечает в течение 15 минут в рабочее время.'],
                    ['question' => 'Можно ли обсудить заказ?', 'answer' => 'Да, напишите нам детали — мы подключим нужного специалиста.'],
                ],
                'contact_form_enabled' => true,
                'quick_buttons' => [
                    ['label' => 'Позвать менеджера', 'message' => 'Хочу поговорить с менеджером'],
                    ['label' => 'Узнать стоимость', 'message' => 'Подскажите, пожалуйста, стоимость'],
                ],
            ],
        ];
    }

    public static function db()
    {
        return Factory::getDbo();
    }

    /**
     * System plugins run on every frontend request. Never let a partially
     * installed component turn a missing table into a site-wide 500.
     */
    public static function schemaReady()
    {
        if (self::$schemaReady !== null) {
            return self::$schemaReady;
        }
        try {
            self::ensureSchema();
            $db = self::db();
            foreach (['settings', 'queue', 'history', 'post_meta', 'conversations'] as $table) {
                $query = $db->getQuery(true)->select('1')->from($db->quoteName('#__rumax_' . $table))->setLimit(1);
                $db->setQuery($query)->loadResult();
            }
            self::$schemaReady = true;
        } catch (\Throwable $exception) {
            self::$schemaReady = false;
            error_log('[Ru-max] Database schema is not ready: ' . $exception->getMessage());
        }
        return self::$schemaReady;
    }

    /**
     * Joomla can finish copying a component while skipping its SQL step when
     * an older package manifest is used. Re-run the idempotent install SQL so
     * an upgrade repairs that partial state automatically.
     */
    public static function ensureSchema()
    {
        $file = dirname(__DIR__, 2) . '/sql/install.mysql.utf8.sql';
        if (!is_file($file)) {
            return false;
        }
        $sql = file_get_contents($file);
        if ($sql === false) {
            return false;
        }
        $db = self::db();
        foreach (preg_split('/;\s*(?:\r\n|\r|\n|$)/', $sql) as $statement) {
            $statement = trim($statement);
            if ($statement === '' || strpos($statement, '--') === 0) {
                continue;
            }
            $db->setQuery($statement)->execute();
        }
        return true;
    }

    public static function now()
    {
        return Factory::getDate()->toSql();
    }

    public static function getSettings()
    {
        if (self::$settings !== null) {
            return self::$settings;
        }
        if (!self::schemaReady()) {
            self::$settings = self::defaults();
            return self::$settings;
        }
        $db = self::db();
        $query = $db->getQuery(true)->select($db->quoteName('data'))
            ->from($db->quoteName('#__rumax_settings'))->where($db->quoteName('id') . ' = 1');
        $db->setQuery($query);
        $raw = $db->loadResult();
        $saved = $raw ? json_decode($raw, true) : [];
        self::$settings = is_array($saved) ? array_replace_recursive(self::defaults(), $saved) : self::defaults();
        return self::$settings;
    }

    public static function saveSettings(array $settings)
    {
        $settings = array_replace_recursive(self::defaults(), $settings);
        $db = self::db();
        $query = $db->getQuery(true)->select('COUNT(*)')->from($db->quoteName('#__rumax_settings'))->where('id = 1');
        $db->setQuery($query);
        $exists = (int) $db->loadResult() > 0;
        $data = json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($exists) {
            $query = $db->getQuery(true)->update($db->quoteName('#__rumax_settings'))
                ->set($db->quoteName('data') . ' = ' . $db->quote($data))
                ->set($db->quoteName('updated_at') . ' = ' . $db->quote(self::now()))
                ->where('id = 1');
        } else {
            $query = $db->getQuery(true)->insert($db->quoteName('#__rumax_settings'))
                ->columns($db->quoteName(['id', 'data', 'updated_at']))
                ->values('1, ' . $db->quote($data) . ', ' . $db->quote(self::now()));
        }
        $db->setQuery($query)->execute();
        self::$settings = $settings;
        return $settings;
    }

    public static function getSocial()
    {
        $settings = self::getSettings();
        $saved = isset($settings['social']) && is_array($settings['social']) ? $settings['social'] : [];
        return array_replace_recursive(self::socialDefaults(), $saved);
    }

    public static function saveSocial(array $social)
    {
        $settings = self::getSettings();
        $settings['social'] = array_replace_recursive(self::socialDefaults(), $social);
        self::saveSettings($settings);
        return $settings['social'];
    }

    public static function getPro()
    {
        $settings = self::getSettings();
        $saved = isset($settings['pro']) && is_array($settings['pro']) ? $settings['pro'] : [];
        return array_replace_recursive(self::proDefaults(), $saved);
    }

    public static function savePro(array $pro)
    {
        $settings = self::getSettings();
        $settings['pro'] = array_replace_recursive(self::proDefaults(), $pro);
        self::saveSettings($settings);
        return $settings['pro'];
    }

    public static function mediaUrl($path = '')
    {
        return rtrim(Uri::root(), '/') . '/media/com_rumax/assets/' . ltrim($path, '/');
    }

    public static function ajaxUrl()
    {
        return Uri::root() . 'index.php?option=com_rumax&task=ajax&format=json';
    }

    public static function token()
    {
        return Factory::getApplication()->getSession()->getFormToken();
    }

    public static function validCronKey($key)
    {
        $expected = trim((string) (self::getSettings()['cron_key'] ?? ''));
        return $expected !== '' && $key !== '' && hash_equals($expected, (string) $key);
    }

    public static function escape($value)
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }

    public static function normalizeChatId($id)
    {
        $id = trim((string) $id);
        if (preg_match('/^id(\d+)(?:_\d+_bot)?$/i', $id, $match)) {
            return $match[1];
        }
        return $id;
    }

    public static function log($type, $status, $message, array $details = [])
    {
        $db = self::db();
        $query = $db->getQuery(true)->insert($db->quoteName('#__rumax_history'))
            ->columns($db->quoteName(['event_time', 'event_type', 'event_data', 'status', 'details']))
            ->values(implode(', ', [
                $db->quote(self::now()), $db->quote($type), $db->quote($message),
                $db->quote($status), $db->quote(json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
            ]));
        $db->setQuery($query)->execute();
    }

    public static function http($method, $url, $body = null, array $headers = [], $timeout = 20)
    {
        if (!function_exists('curl_init')) {
            return ['ok' => false, 'code' => 0, 'body' => '', 'error' => 'На сервере не включено расширение cURL.'];
        }
        $curl = curl_init($url);
        $payload = $body === null ? null : (is_string($body) ? $body : json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $headers = array_merge(['Accept: application/json'], $headers);
        if ($payload !== null && !array_filter($headers, static function ($header) {
            return stripos($header, 'content-type:') === 0;
        })) {
            $headers[] = 'Content-Type: application/json';
        }
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_TIMEOUT => (int) $timeout, CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER => $headers, CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        if ($payload !== null) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, $payload);
        }
        $response = curl_exec($curl);
        $error = curl_error($curl);
        $code = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        $decoded = json_decode((string) $response, true);
        return ['ok' => !$error && $code >= 200 && $code < 300, 'code' => $code, 'body' => (string) $response, 'data' => $decoded, 'error' => $error];
    }

    public static function maxRequest($method, $endpoint, $body = null, $token = null)
    {
        $token = trim((string) ($token ?: self::getSettings()['bot_token']));
        if ($token === '') {
            return ['ok' => false, 'code' => 0, 'body' => '', 'error' => 'Токен бота MAX не задан.'];
        }
        $result = self::http($method, self::MAX_API . $endpoint, $body, ['Authorization: ' . $token], 30);
        if (!$result['ok']) {
            self::log('api', 'error', 'Ошибка MAX API: ' . ($result['error'] ?: 'HTTP ' . $result['code']), ['endpoint' => $endpoint, 'code' => $result['code'], 'response' => $result['data'] ?? $result['body']]);
        }
        return $result;
    }

    public static function testConnection($token = null)
    {
        $result = self::maxRequest('GET', '/me', null, $token);
        if (!$result['ok']) {
            return ['success' => false, 'message' => $result['error'] ?: 'MAX API вернул HTTP ' . $result['code']];
        }
        $data = is_array($result['data'] ?? null) ? $result['data'] : [];
        $name = $data['first_name'] ?? $data['name'] ?? 'Unknown';
        $username = !empty($data['username']) ? ' @' . $data['username'] : '';
        return ['success' => true, 'message' => 'Подключено! Бот: ' . $name . $username, 'bot_info' => $data];
    }

    public static function sendMax($chatId, $text, $format = 'html', array $buttons = [], $imageUrl = '')
    {
        $payload = ['text' => self::truncate((string) $text), 'format' => $format];
        if ($buttons) {
            $rows = [];
            foreach ($buttons as $button) {
                if (!empty($button['text']) && !empty($button['url'])) {
                    $rows[] = [['type' => 'link', 'text' => $button['text'], 'url' => $button['url']]];
                }
            }
            if ($rows) {
                $payload['attachments'][] = ['type' => 'inline_keyboard', 'payload' => ['buttons' => $rows]];
            }
        }
        if ($imageUrl) {
            $payload['attachments'][] = ['type' => 'image', 'payload' => ['url' => $imageUrl]];
        }
        return self::maxRequest('POST', '/messages?chat_id=' . rawurlencode(self::normalizeChatId($chatId)), $payload);
    }

    public static function truncate($value, $limit = 4096)
    {
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', (string) $value);
        if (function_exists('mb_strlen') && mb_strlen($value, 'UTF-8') > $limit) {
            return mb_substr($value, 0, $limit - 8, 'UTF-8') . "\n...";
        }
        return strlen($value) > $limit ? substr($value, 0, $limit - 8) . "\n..." : $value;
    }

    public static function articlePayload($article)
    {
        $text = trim(strip_tags((string) ($article->introtext ?? '') . "\n" . (string) ($article->fulltext ?? $article->text ?? '')));
        $url = method_exists($article, 'getLink') ? $article->getLink() : Uri::root();
        $image = '';
        $images = json_decode((string) ($article->images ?? ''), true);
        if (is_array($images)) {
            $image = (string) (!empty($images['image_fulltext']) ? $images['image_fulltext'] : ($images['image_intro'] ?? ''));
        }
        if ($image && strpos($image, 'http') !== 0) {
            $image = rtrim(Uri::root(), '/') . '/' . ltrim($image, '/');
        }
        return ['id' => (int) ($article->id ?? 0), 'title' => (string) ($article->title ?? ''), 'text' => $text, 'excerpt' => self::truncate($text, 700), 'url' => $url, 'image' => $image];
    }

    public static function renderTemplate($template, array $payload)
    {
        $replace = [];
        foreach ($payload as $key => $value) {
            $replace['{' . $key . '}'] = (string) $value;
        }
        return strtr((string) $template, $replace);
    }

    public static function enqueueArticle($article, $isNew = true)
    {
        $settings = self::getSettings();
        if (empty($settings['post_sender_enabled']) || (!$isNew && empty($settings['send_updated_post']))) {
            return false;
        }
        $meta = self::getPostMeta((int) $article->id);
        if (!empty($meta['skip_max'])) {
            return false;
        }
        $payload = self::articlePayload($article);
        $delay = max(0, (int) $settings['send_delay_seconds']);
        self::addQueue('max', $payload['id'], $payload, time() + $delay);
        $social = self::getSocial();
        $networks = !empty($meta['networks']) && is_array($meta['networks']) ? $meta['networks'] : [];
        if (!empty($meta['autopost_datetime']) && $networks) {
            $due = strtotime((string) $meta['autopost_datetime']) ?: time();
            foreach ($networks as $network) {
                self::addQueue($network, $payload['id'], $payload, $due, 'autopost');
            }
        } elseif (!empty($social['auto_send_default'])) {
            foreach (['telegram', 'vkontakte', 'ok', 'dzen'] as $network) {
                if (!empty($social[$network . '_enabled'])) {
                    self::addQueue($network, $payload['id'], $payload, time(), 'autopost');
                }
            }
        }
        return true;
    }

    public static function addQueue($network, $contentId, array $payload, $due, $kind = 'publication')
    {
        $db = self::db();
        $now = self::now();
        $query = $db->getQuery(true)->insert($db->quoteName('#__rumax_queue'))
            ->columns($db->quoteName(['kind', 'network', 'content_id', 'payload', 'due_at', 'status', 'created_at', 'updated_at']))
            ->values(implode(', ', [
                $db->quote($kind), $db->quote($network), (int) $contentId,
                $db->quote(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
                $db->quote(date('Y-m-d H:i:s', max(time(), (int) $due))), $db->quote('pending'), $db->quote($now), $db->quote($now),
            ]));
        $db->setQuery($query)->execute();
        return (int) $db->insertid();
    }

    public static function processQueue($limit = 10)
    {
        if (self::$processing) {
            return 0;
        }
        self::$processing = true;
        $db = self::db();
        $query = $db->getQuery(true)->select('*')->from($db->quoteName('#__rumax_queue'))
            ->where($db->quoteName('status') . ' = ' . $db->quote('pending'))
            ->where($db->quoteName('due_at') . ' <= ' . $db->quote(self::now()))
            ->order($db->quoteName('due_at') . ' ASC')->setLimit((int) $limit);
        $db->setQuery($query);
        $jobs = $db->loadObjectList();
        $processed = 0;
        foreach ($jobs as $job) {
            $payload = json_decode($job->payload, true) ?: [];
            $result = self::publish($job->network, $payload);
            $attempts = (int) $job->attempts + 1;
            $status = $result['ok'] ? 'sent' : ($attempts >= 3 ? 'failed' : 'pending');
            $nextDue = $status === 'pending' ? date('Y-m-d H:i:s', time() + max(5, (int) self::getSettings()['retry_delay_seconds'])) : $job->due_at;
            $query = $db->getQuery(true)->update($db->quoteName('#__rumax_queue'))
                ->set($db->quoteName('status') . ' = ' . $db->quote($status))
                ->set($db->quoteName('attempts') . ' = ' . $attempts)
                ->set($db->quoteName('last_error') . ' = ' . $db->quote($result['ok'] ? '' : ($result['error'] ?: 'HTTP ' . $result['code'])))
                ->set($db->quoteName('due_at') . ' = ' . $db->quote($nextDue))
                ->set($db->quoteName('updated_at') . ' = ' . $db->quote(self::now()))
                ->where('id = ' . (int) $job->id);
            $db->setQuery($query)->execute();
            $processed++;
        }
        self::$processing = false;
        return $processed;
    }

    public static function publish($network, array $payload)
    {
        $settings = self::getSettings();
        $social = self::getSocial();
        if ($network === 'max') {
            $channels = (array) ($settings['channels'] ?? []);
            $targets = $channels ?: [(string) ($settings['chat_id'] ?? '')];
            $last = ['ok' => false, 'error' => 'Канал MAX не задан.'];
            foreach ($targets as $target) {
                $target = is_array($target) ? ($target['id'] ?? $target['chat_id'] ?? '') : $target;
                if ($target !== '') {
                    $last = self::sendMax($target, self::renderTemplate($settings['post_template'] ?? "{title}\n\n{excerpt}\n{url}", $payload), $settings['notify_format'] ?? 'html', [], $payload['image'] ?? '');
                }
            }
            return $last;
        }
        if ($network === 'telegram') {
            $bots = (array) ($social['telegram_bots'] ?? []);
            $bot = $bots[0] ?? [];
            if (empty($bot['token']) || empty($bot['chat_id'])) {
                return ['ok' => false, 'error' => 'Telegram не настроен.'];
            }
            $text = self::renderTemplate($social['telegram_template'], $payload);
            $endpoint = 'https://api.telegram.org/bot' . rawurlencode($bot['token']) . '/sendMessage';
            return self::http('POST', $endpoint, ['chat_id' => $bot['chat_id'], 'text' => $text, 'parse_mode' => 'HTML', 'disable_web_page_preview' => false]);
        }
        if ($network === 'vkontakte') {
            if (empty($social['vk_access_token']) || empty($social['vk_owner_id'])) {
                return ['ok' => false, 'error' => 'ВКонтакте не настроен.'];
            }
            return self::http('POST', 'https://api.vk.com/method/wall.post', [
                'owner_id' => $social['vk_owner_id'], 'from_group' => 1,
                'message' => $payload['title'] . "\n\n" . $payload['excerpt'] . "\n\n" . $payload['url'],
                'access_token' => $social['vk_access_token'], 'v' => '5.199',
            ]);
        }
        if ($network === 'ok') {
            if (empty($social['ok_access_token']) || empty($social['ok_public_key']) || empty($social['ok_secret_key'])) {
                return ['ok' => false, 'error' => 'Одноклассники не настроены.'];
            }
            $params = ['application_key' => $social['ok_public_key'], 'format' => 'json', 'method' => 'mediatopic.post', 'access_token' => $social['ok_access_token'], 'type' => empty($social['ok_group_id']) ? 'USER' : 'GROUP_THEME', 'gid' => $social['ok_group_id'], 'attachment' => json_encode(['media' => [['type' => 'text', 'text' => $payload['title'] . "\n\n" . $payload['url']]]], JSON_UNESCAPED_UNICODE)];
            ksort($params);
            $signature = '';
            foreach ($params as $key => $value) {
                if ($key !== 'access_token') {
                    $signature .= $key . '=' . $value;
                }
            }
            $params['sig'] = md5($signature . md5($social['ok_access_token'] . $social['ok_secret_key']));
            return self::http('POST', 'https://api.ok.ru/fb.do', $params, ['Content-Type: application/x-www-form-urlencoded']);
        }
        if ($network === 'dzen') {
            if (empty($social['dzen_oauth_token']) || empty($social['dzen_channel_id'])) {
                return ['ok' => false, 'error' => 'Яндекс Дзен не настроен.'];
            }
            return self::http('POST', 'https://api.zen.yandex.ru/v1.0/channel/createPost', [
                'channel_id' => $social['dzen_channel_id'],
                'access_token' => $social['dzen_oauth_token'],
                'content' => ['title' => $payload['title'], 'blocks' => [['type' => 'paragraph', 'text' => $payload['excerpt'] . "\n\n" . $payload['url']]]],
            ]);
        }
        return ['ok' => false, 'error' => 'Неизвестная сеть.'];
    }

    public static function getPostMeta($id)
    {
        $db = self::db();
        $query = $db->getQuery(true)->select('data')->from($db->quoteName('#__rumax_post_meta'))->where('content_id = ' . (int) $id);
        $db->setQuery($query);
        return ($raw = $db->loadResult()) ? (json_decode($raw, true) ?: []) : [];
    }

    public static function savePostMeta($id, array $data)
    {
        $db = self::db();
        $exists = $db->setQuery($db->getQuery(true)->select('COUNT(*)')->from($db->quoteName('#__rumax_post_meta'))->where('content_id = ' . (int) $id))->loadResult();
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($exists) {
            $query = $db->getQuery(true)->update($db->quoteName('#__rumax_post_meta'))->set('data = ' . $db->quote($json))->set('updated_at = ' . $db->quote(self::now()))->where('content_id = ' . (int) $id);
        } else {
            $query = $db->getQuery(true)->insert($db->quoteName('#__rumax_post_meta'))->columns($db->quoteName(['content_id', 'data', 'updated_at']))->values((int) $id . ', ' . $db->quote($json) . ', ' . $db->quote(self::now()));
        }
        return $db->setQuery($query)->execute();
    }

    public static function conversation($data)
    {
        $db = self::db();
        $id = (string) ($data['conversation_id'] ?? '');
        $current = $db->setQuery($db->getQuery(true)->select('data')->from($db->quoteName('#__rumax_conversations'))->where('id = ' . $db->quote($id)))->loadResult();
        $saved = $current ? json_decode($current, true) : [];
        $saved = is_array($saved) ? array_replace($saved, $data) : $data;
        $json = json_encode($saved, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($current) {
            $query = $db->getQuery(true)->update($db->quoteName('#__rumax_conversations'))->set('data = ' . $db->quote($json))->set('updated_at = ' . $db->quote(self::now()))->where('id = ' . $db->quote($id));
        } else {
            $query = $db->getQuery(true)->insert($db->quoteName('#__rumax_conversations'))->columns($db->quoteName(['id', 'data', 'status', 'created_at', 'updated_at']))->values($db->quote($id) . ', ' . $db->quote($json) . ', ' . $db->quote('open') . ', ' . $db->quote(self::now()) . ', ' . $db->quote(self::now()));
        }
        $db->setQuery($query)->execute();
        return $saved;
    }

    public static function renderWidget($moduleOverride = [])
    {
        $settings = self::getSettings();
        $pro = self::getPro();
        if (!empty($moduleOverride)) {
            $settings = array_replace($settings, $moduleOverride);
        }
        if (empty($settings['chat_widget_enabled']) || empty($pro['enabled'])) {
            return '';
        }
        $sizeMap = ['small' => 42, 'medium' => 64, 'large' => 80];
        $px = $sizeMap[$settings['chat_widget_size']] ?? 64;
        $position = ($settings['chat_widget_position'] ?? 'right') === 'left' ? 'left' : 'right';
        $url = trim((string) ($settings['chat_widget_url'] ?? ''));
        $utm = array_filter(['utm_source' => $settings['chat_widget_utm_source'] ?? '', 'utm_medium' => $settings['chat_widget_utm_medium'] ?? '', 'utm_campaign' => $settings['chat_widget_utm_campaign'] ?? '', 'utm_content' => $settings['chat_widget_utm_content'] ?? '']);
        if ($url && $utm) {
            $url .= (strpos($url, '?') !== false ? '&' : '?') . http_build_query($utm);
        }
        $image = $settings['chat_widget_size'] === 'small' ? 'max-32x32.png' : ($settings['chat_widget_size'] === 'large' ? 'max-256x256.png' : 'max-64x64.png');
        $anim = ($settings['chat_widget_animation'] ?? 'none') !== 'none' ? ' wp-ru-max-anim-' . self::escape($settings['chat_widget_animation']) : '';
        $proStyle = $pro['style'];
        $bg = preg_match('/^#[0-9a-f]{3,8}$/i', (string) ($proStyle['icon_background'] ?? '')) ? $proStyle['icon_background'] : '#4f46e5';
        $rgb = [79, 70, 229];
        if (strlen($bg) === 7) {
            $rgb = [hexdec(substr($bg, 1, 2)), hexdec(substr($bg, 3, 2)), hexdec(substr($bg, 5, 2))];
        }
        $channels = $pro['channels'];
        $enabled = ['max'];
        if (!empty($pro['chat']['live_chat_enabled'])) {
            $enabled[] = 'live_chat';
        }
        foreach ($pro['channel_order'] as $key) {
            if (!empty($channels[$key]['enabled'])) {
                $enabled[] = $key;
            }
        }
        $icons = ['phone' => 'phone-svg.svg', 'telegram' => 'telegram.svg', 'vkontakte' => 'vkontakte.svg', 'contact' => 'contact.svg', 'email' => 'email.svg'];
        $labels = ['phone' => 'Телефон', 'telegram' => 'Telegram', 'vkontakte' => 'ВКонтакте', 'contact' => 'Форма обратной связи', 'email' => 'Email'];
        ob_start();
        ?>
<div id="wp-ru-max-widget" style="position:fixed;bottom:<?php echo (int) $settings['chat_widget_bottom_offset']; ?>px;<?php echo $position; ?>:20px;z-index:99999;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;display:block;">
 <div id="wp-ru-max-balloon" style="position:absolute;bottom:<?php echo $px + 14; ?>px;<?php echo $position === 'left' ? 'left:0;' : 'right:0;'; ?>background:#fff;border:1px solid #e0e0e0;border-radius:14px;padding:12px 16px;max-width:265px;min-width:265px;box-shadow:0 4px 24px rgba(0,0,0,.15);display:none;word-break:break-word;">
  <button id="wp-ru-max-close" type="button" style="position:absolute;top:6px;right:8px;background:none;border:0;cursor:pointer;color:#aaa;font-size:18px" title="Закрыть" aria-label="Закрыть">&times;</button>
  <div id="wp-ru-max-typing" style="color:#222;font-size:14px;line-height:1.5;padding-right:18px"></div>
  <div style="position:absolute;bottom:-8px;<?php echo $position === 'left' ? 'left:14px;' : 'right:14px;'; ?>width:0;height:0;border-left:8px solid transparent;border-right:8px solid transparent;border-top:8px solid #fff"></div>
 </div>
 <a href="<?php echo self::escape($url ?: '#'); ?>" <?php echo $url ? 'target="_blank" rel="noopener noreferrer"' : ''; ?> id="wp-ru-max-icon" class="wp-ru-max-icon<?php echo $anim; ?>" style="display:block;width:<?php echo $px; ?>px;height:<?php echo $px; ?>px;border-radius:50%;overflow:hidden;box-shadow:0 4px 16px rgba(0,0,0,.28);cursor:pointer;text-decoration:none" aria-label="Открыть меню связи" title="MAX">
  <img src="<?php echo self::mediaUrl($image); ?>" width="<?php echo $px; ?>" height="<?php echo $px; ?>" alt="MAX" style="display:block;width:100%;height:100%;object-fit:cover">
 </a>
 <script>window.wpRuMaxSettings=<?php echo json_encode(['message' => (string) $settings['chat_widget_message'], 'welcomeEnabled' => !empty($settings['chat_widget_message_enabled']), 'showDelay' => (int) $settings['chat_widget_show_delay'] * 1000, 'sound' => $settings['chat_widget_sound'], 'soundDelay' => (int) $settings['chat_widget_sound_delay'] * 1000, 'soundsUrl' => self::mediaUrl('sounds/'), 'soundPages' => $settings['chat_widget_sound_pages'], 'soundSpecificPages' => preg_split('/\r\n|\r|\n/', (string) $settings['chat_widget_sound_specific_pages']), 'soundOncePerSession' => !empty($settings['chat_widget_sound_once_per_session']), 'hideDelay' => (int) $settings['chat_widget_hide_delay'] * 1000, 'repeatDelay' => (int) $settings['chat_widget_repeat_delay'] * 1000, 'animation' => $settings['chat_widget_animation'], 'retentionEnabled' => !empty($settings['chat_widget_retention_enabled']), 'homeUrl' => Uri::root(), 'yaMetrikaEnabled' => !empty($settings['chat_widget_ya_metrika_enabled']), 'yaMetrikaCounter' => (int) $settings['chat_widget_ya_metrika_counter'], 'yaMetrikaGoal' => $settings['chat_widget_ya_metrika_goal']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;</script>
 <div id="wp-ru-max-pro-menu" class="wprmp-menu wprmp-layout-<?php echo self::escape($proStyle['layout']); ?> wprmp-position-<?php echo $position; ?><?php echo !empty($proStyle['backdrop_blur']) ? ' wprmp-has-blur' : ''; ?>" style="--wprmp-bg:<?php echo self::escape($bg); ?>;--wprmp-bg-glass:rgba(<?php echo implode(',', $rgb); ?>,.22);--wprmp-color:<?php echo self::escape($proStyle['icon_color']); ?>;--wprmp-size:<?php echo $px; ?>px;--wprmp-bottom-offset:<?php echo (int) $settings['chat_widget_bottom_offset']; ?>px">
  <div class="wprmp-panel" data-mode="live_chat" aria-hidden="true"><button class="wprmp-close" type="button" aria-label="Закрыть">×</button><div class="wprmp-panel-brand"><img src="<?php echo self::mediaUrl('pro/roboform.svg'); ?>" alt=""><div><h3><?php echo self::escape($pro['chat']['title']); ?></h3><small><?php echo !empty($pro['chat']['manager_online']) ? 'Менеджер онлайн' : 'Бот на связи 24/7'; ?></small></div></div><p class="wprmp-welcome"><?php echo self::escape($pro['chat']['welcome']); ?></p><div class="wprmp-thread-live" aria-live="polite"></div>
   <form class="wprmp-form"><input type="hidden" name="channel" value="live_chat"><div class="wprmp-identity"><div class="wprmp-form-row"><input name="name" placeholder="Имя" required><input name="email" type="email" placeholder="Email"></div><input name="phone" placeholder="Телефон"></div><div class="wprmp-consents"><label><input type="checkbox" name="consent" value="1" required><span>Да, я согласен(а) на обработку персональных данных.</span></label><label><input type="checkbox" name="mailing" value="1"><span>Я согласен(а) получать уведомления.</span></label></div><textarea name="message" placeholder="Сообщение" required></textarea><button type="submit">Отправить сообщение <span>→</span></button><span class="wprmp-form-status" role="status"></span></form>
   <?php if (!empty($pro['chat']['faq'])): ?><div class="wprmp-faq"><strong>Частые вопросы</strong><?php foreach ($pro['chat']['faq'] as $item): ?><button type="button" class="wprmp-faq-item"><span><?php echo self::escape($item['question']); ?></span><i><?php echo self::escape($item['answer']); ?></i></button><?php endforeach; ?></div><?php endif; ?>
   <?php if (!empty($pro['chat']['quick_buttons'])): ?><div class="wprmp-quick-buttons"><?php foreach ($pro['chat']['quick_buttons'] as $button): ?><button type="button" data-message="<?php echo self::escape($button['message']); ?>"><?php echo self::escape($button['label']); ?></button><?php endforeach; ?></div><?php endif; ?>
  </div>
  <div class="wprmp-channels" aria-hidden="true"><?php foreach ($enabled as $key): if ($key === 'max'): ?><a class="wprmp-channel" href="<?php echo self::escape($url ?: '#'); ?>" <?php echo $url ? 'target="_blank" rel="noopener"' : ''; ?> title="MAX"><img src="<?php echo self::mediaUrl('pro/MAX.svg'); ?>" alt=""><span class="wprmp-channel-label">MAX</span></a><?php elseif ($key === 'live_chat'): ?><button type="button" class="wprmp-channel wprmp-open-live-chat" data-mode="live_chat" title="Открыть живой чат"><img src="<?php echo self::mediaUrl('pro/roboform.svg'); ?>" alt=""><span class="wprmp-channel-label">Живой чат</span></button><?php elseif (isset($channels[$key])): $channel = $channels[$key]; $value = (string) ($channel['value'] ?? ''); $href = $key === 'phone' ? 'tel:' . preg_replace('/[^0-9+]/', '', $value) : ($key === 'email' ? 'mailto:' . $value : ($key === 'telegram' ? 'https://t.me/' . ltrim($value, '@') : ($key === 'vkontakte' ? 'https://vk.com/' . ltrim($value, '@') : '#'))); if ($key === 'contact'): ?><button type="button" class="wprmp-channel wprmp-open-chat" data-mode="contact_form" title="<?php echo self::escape($labels[$key]); ?>"><img src="<?php echo self::mediaUrl('pro/' . $icons[$key]); ?>" alt=""><span class="wprmp-channel-label"><?php echo self::escape($labels[$key]); ?></span></button><?php else: ?><a class="wprmp-channel" href="<?php echo self::escape($href); ?>" target="_blank" rel="noopener" title="<?php echo self::escape($labels[$key]); ?>"><img src="<?php echo self::mediaUrl('pro/' . $icons[$key]); ?>" alt=""><span class="wprmp-channel-label"><?php echo self::escape($labels[$key]); ?></span></a><?php endif; endif; endforeach; ?></div>
 </div>
</div>
<div id="wp-ru-max-retention-modal" class="wp-ru-max-retention-modal" role="dialog" aria-modal="true"><div class="wp-ru-max-retention-content"><h3><?php echo self::escape($settings['chat_widget_retention_title']); ?></h3><p><?php echo nl2br(self::escape($settings['chat_widget_retention_message'])); ?></p><div class="wp-ru-max-retention-actions"><button class="wp-ru-max-retention-stay" type="button"><?php echo self::escape($settings['chat_widget_retention_stay_text']); ?></button><button class="wp-ru-max-retention-leave" type="button"><?php echo self::escape($settings['chat_widget_retention_leave_text']); ?></button></div></div></div>
 <script>window.wpRuMaxProFront=<?php echo json_encode(['ajaxUrl' => self::ajaxUrl(), 'nonce' => self::token(), 'managerOnline' => !empty($pro['chat']['manager_online'])], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;window.wpRuMaxContacts=window.wpRuMaxProFront;</script>
</div>
        <?php
        return ob_get_clean();
    }

    public static function updateConversation(array $input)
    {
        $id = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) ($input['conversation_id'] ?? ''));
        if (!$id) {
            $id = bin2hex(random_bytes(16));
        }
        $existing = self::conversation(['conversation_id' => $id]);
        $messages = isset($existing['messages']) && is_array($existing['messages']) ? $existing['messages'] : [];
        $message = trim(strip_tags((string) ($input['message'] ?? '')));
        $name = trim(strip_tags((string) ($input['name'] ?? ($existing['name'] ?? ''))));
        if ($name === '' || $message === '') {
            return ['success' => false, 'data' => 'Введите имя и сообщение.'];
        }
        $messages[] = ['role' => 'visitor', 'text' => $message, 'created_at' => self::now()];
        $reply = 'Сообщение принято. Мы скоро ответим.';
        if (!empty(self::getPro()['chat']['manager_online'])) {
            $reply = 'Менеджер уже видит ваше сообщение и скоро ответит.';
        }
        $messages[] = ['role' => 'bot', 'text' => $reply, 'name' => self::getPro()['chat']['bot_name'], 'created_at' => self::now()];
        self::conversation(['conversation_id' => $id, 'name' => $name, 'email' => trim((string) ($input['email'] ?? '')), 'phone' => trim((string) ($input['phone'] ?? '')), 'message' => $message, 'messages' => array_slice($messages, -50), 'url' => Uri::current()]);
        $pro = self::getPro();
        if (!empty($pro['chat']['target'])) {
            self::sendMax($pro['chat']['target'], "<b>Новое сообщение с сайта</b>\nИмя: " . self::escape($name) . "\nEmail: " . self::escape($input['email'] ?? '') . "\nТелефон: " . self::escape($input['phone'] ?? '') . "\n\n" . self::escape($message));
        }
        return ['success' => true, 'data' => ['message' => 'Сообщение отправлено.', 'conversation_id' => $id, 'messages' => $messages]];
    }

    public static function getConversation($id)
    {
        $db = self::db();
        $raw = $db->setQuery($db->getQuery(true)->select('data')->from($db->quoteName('#__rumax_conversations'))->where('id = ' . $db->quote($id)))->loadResult();
        return $raw ? (json_decode($raw, true) ?: []) : [];
    }
}