<?php
defined('_JEXEC') or die;

require_once JPATH_ADMINISTRATOR . '/components/com_rumax/src/Helper/RumaxHelper.php';

use Joomla\CMS\Factory;
use Joomla\Component\Rumax\Administrator\Helper\RumaxHelper;

$input = Factory::getApplication()->input;
$task = $input->getCmd('task', 'display');

if ($task === 'cron') {
    if (!RumaxHelper::validCronKey($input->getString('key'))) {
        http_response_code(403);
        echo json_encode(['success' => false, 'data' => 'Недействительный ключ cron.']);
        return;
    }
    echo json_encode(['success' => true, 'data' => ['processed' => RumaxHelper::processQueue(30)]], JSON_UNESCAPED_UNICODE);
    return;
}

if ($task !== 'ajax') {
    echo '<div class="alert alert-info">Ru-max работает через виджет и системный плагин.</div>';
    return;
}

$session = Factory::getApplication()->getSession();
$nonce = $input->getString('nonce');
$validToken = $session->checkToken('post') || ($nonce !== '' && hash_equals((string) RumaxHelper::token(), $nonce));
if (!$validToken) {
    echo json_encode(['success' => false, 'data' => 'Недействительный токен безопасности.']);
    return;
}

$action = $input->getCmd('action');
if (in_array($action, ['message', 'wp_ru_max_pro_message', 'wp_ru_max_contacts_message'], true)) {
    $result = RumaxHelper::updateConversation([
        'conversation_id' => $input->getString('conversation_id'),
        'channel' => $input->getCmd('channel', 'live_chat'),
        'name' => $input->getString('name'),
        'email' => $input->getString('email'),
        'phone' => $input->getString('phone'),
        'message' => $input->getString('message'),
    ]);
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return;
}
if (in_array($action, ['history', 'wp_ru_max_pro_history', 'wp_ru_max_contacts_history'], true)) {
    $conversation = RumaxHelper::getConversation($input->getString('conversation_id'));
    echo json_encode(['success' => true, 'data' => ['messages' => $conversation['messages'] ?? []]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return;
}

echo json_encode(['success' => false, 'data' => 'Неизвестное действие.'], JSON_UNESCAPED_UNICODE);