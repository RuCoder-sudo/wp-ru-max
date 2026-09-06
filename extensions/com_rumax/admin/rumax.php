<?php
defined('_JEXEC') or die;

require_once __DIR__ . '/src/Helper/RumaxHelper.php';

use Joomla\CMS\Factory;
use Joomla\Component\Rumax\Administrator\Helper\RumaxHelper;

$app = Factory::getApplication();
$input = $app->input;
$task = $input->getCmd('task', 'display');

if ($task === 'ajax') {
    if (!$app->getSession()->checkToken('post')) {
        echo json_encode(['success' => false, 'message' => 'Недействительный токен безопасности.']);
        return;
    }
    $action = $input->getCmd('action');
    $response = ['success' => false, 'message' => 'Неизвестное действие.'];
    if ($action === 'test_connection') {
        $response = RumaxHelper::testConnection($input->getString('bot_token'));
    } elseif ($action === 'send_test') {
        $settings = RumaxHelper::getSettings();
        $target = $input->getString('chat_id') ?: ($settings['channels'][0]['id'] ?? $settings['chat_id'] ?? '');
        $response = RumaxHelper::sendMax($target, $input->getString('message') ?: 'Тестовое сообщение Ru-max из Joomla.') + ['success' => false];
        $response['success'] = !empty($response['ok']);
        $response['message'] = $response['success'] ? 'Тестовое сообщение отправлено.' : ($response['error'] ?: 'Не удалось отправить сообщение.');
    } elseif ($action === 'process_queue') {
        $count = RumaxHelper::processQueue(30);
        $response = ['success' => true, 'message' => 'Обработано заданий: ' . $count . '.'];
    } elseif ($action === 'clear_logs') {
        $db = RumaxHelper::db();
        $db->setQuery($db->getQuery(true)->delete($db->quoteName('#__rumax_history')))->execute();
        $response = ['success' => true, 'message' => 'Журнал очищен.'];
    } elseif ($action === 'send_push') {
        $settings = RumaxHelper::getSettings();
        $message = trim($input->getString('message'));
        $sent = 0;
        foreach ((array) ($settings['channels'] ?? []) as $channel) {
            $id = is_array($channel) ? ($channel['id'] ?? $channel['chat_id'] ?? '') : $channel;
            if ($id && RumaxHelper::sendMax($id, $message)['ok']) {
                $sent++;
            }
        }
        $response = ['success' => $sent > 0, 'message' => $sent ? 'Отправлено каналов: ' . $sent : 'Не найден канал MAX.'];
    }
    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return;
}

if ($task === 'save') {
    if (!$app->getSession()->checkToken('post')) {
        $app->enqueueMessage('Недействительный токен безопасности.', 'error');
    } else {
        $settings = RumaxHelper::getSettings();
        $posted = $input->post->get('settings', [], 'array');
        $postedTab = $input->post->getCmd('tab', 'main');
        $settings = array_replace_recursive($settings, $posted);
        $checkboxesByTab = [
            'publications' => ['post_sender_enabled', 'send_new_post', 'send_updated_post', 'show_read_more'],
            'notifications' => ['notifications_enabled', 'notify_user_registration', 'notify_customer_order'],
            'widget' => ['chat_widget_enabled', 'chat_widget_message_enabled', 'chat_widget_retention_enabled'],
            'settings' => ['enable_bot_api_log', 'enable_post_sender_log', 'share_button_enabled', 'send_files_by_url'],
        ];
        $checkboxes = $checkboxesByTab[$postedTab] ?? [];
        foreach ($checkboxes as $checkbox) {
            $settings[$checkbox] = isset($posted[$checkbox]) ? 1 : 0;
        }
        if (isset($posted['notify_chat_ids']) && is_string($posted['notify_chat_ids'])) {
            $settings['notify_chat_ids'] = array_values(array_filter(array_map('trim', explode(',', $posted['notify_chat_ids']))));
        }
        $settings['channels'] = array_values(array_filter((array) ($settings['channels'] ?? []), static function ($item) {
            return is_array($item) && !empty($item['id']);
        }));
        RumaxHelper::saveSettings($settings);
        $postedSocial = $input->post->get('social', [], 'array');
        $postedPro = $input->post->get('pro', [], 'array');
        if ($postedSocial) {
            foreach (['telegram_enabled', 'vk_enabled', 'ok_enabled', 'dzen_enabled'] as $checkbox) {
                if ($postedTab === 'social') {
                    $postedSocial[$checkbox] = isset($postedSocial[$checkbox]) ? 1 : 0;
                }
            }
            RumaxHelper::saveSocial($postedSocial);
        }
        if ($postedPro) {
            if ($postedTab === 'contacts') {
                $postedPro['enabled'] = isset($postedPro['enabled']) ? 1 : 0;
                foreach ((array) ($postedPro['channels'] ?? []) as $key => $channel) {
                    $postedPro['channels'][$key]['enabled'] = isset($channel['enabled']) ? 1 : 0;
                }
            }
            RumaxHelper::savePro($postedPro);
        }
        $app->enqueueMessage('Настройки Ru-max сохранены.', 'message');
    }
}

$settings = RumaxHelper::getSettings();
$social = RumaxHelper::getSocial();
$pro = RumaxHelper::getPro();
$tab = $input->getCmd('tab', 'main');
$db = RumaxHelper::db();
$history = $db->setQuery($db->getQuery(true)->select('*')->from($db->quoteName('#__rumax_history'))->order('event_time DESC')->setLimit(100))->loadObjectList();
$queue = $db->setQuery($db->getQuery(true)->select('*')->from($db->quoteName('#__rumax_queue'))->order('due_at ASC')->setLimit(100))->loadObjectList();
$token = RumaxHelper::token();
$tokenHtml = '<input type="hidden" name="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '" value="1">';
require __DIR__ . '/tmpl/default.php';