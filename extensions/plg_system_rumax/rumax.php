<?php
defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Component\Rumax\Administrator\Helper\RumaxHelper;

if (defined('JPATH_ADMINISTRATOR')) {
    require_once JPATH_ADMINISTRATOR . '/components/com_rumax/src/Helper/RumaxHelper.php';
}

class PlgSystemRumax extends CMSPlugin
{
    protected $app;
    private static $mailGuard = false;

    public function onAfterInitialise()
    {
        if (!$this->app->isClient('site') || !RumaxHelper::schemaReady()) {
            return;
        }
        try {
            RumaxHelper::processQueue(3);
        } catch (\Throwable $exception) {
            error_log('[Ru-max] Queue processing skipped: ' . $exception->getMessage());
        }
    }

    public function onBeforeCompileHead()
    {
        if (!$this->app->isClient('site')) {
            return;
        }
        if (!RumaxHelper::schemaReady()) {
            return;
        }
        try {
            $settings = RumaxHelper::getSettings();
        } catch (\Throwable $exception) {
            error_log('[Ru-max] Frontend assets skipped: ' . $exception->getMessage());
            return;
        }
        if (empty($settings['chat_widget_enabled']) || !$this->params->get('load_widget', 1)) {
            return;
        }
        $document = Factory::getApplication()->getDocument();
        $document->addStyleSheet(RumaxHelper::mediaUrl('css/chat-widget.css') . '?v=' . RumaxHelper::VERSION);
        $document->addStyleSheet(RumaxHelper::mediaUrl('pro/widget.css') . '?v=' . RumaxHelper::VERSION);
        $document->addScript(RumaxHelper::mediaUrl('js/chat-widget.js') . '?v=' . RumaxHelper::VERSION, ['version' => RumaxHelper::VERSION], ['defer' => true]);
        $document->addScript(RumaxHelper::mediaUrl('pro/widget.js') . '?v=' . RumaxHelper::VERSION, ['version' => RumaxHelper::VERSION], ['defer' => true]);
    }

    public function onAfterRender()
    {
        if (!$this->app->isClient('site')) {
            return;
        }
        if (!RumaxHelper::schemaReady()) {
            return;
        }
        try {
            $settings = RumaxHelper::getSettings();
        } catch (\Throwable $exception) {
            error_log('[Ru-max] Widget skipped: ' . $exception->getMessage());
            return;
        }
        if (empty($settings['chat_widget_enabled'])) {
            return;
        }
        $body = $this->app->getBody();
        if (stripos($body, 'id="wp-ru-max-widget"') !== false) {
            return;
        }
        $widget = RumaxHelper::renderWidget();
        if ($widget && stripos($body, '</body>') !== false) {
            $body = preg_replace('/<\/body>/i', $widget . '</body>', $body, 1);
            $this->app->setBody($body);
        }
    }

    /**
     * Joomla mail plugins can pass either a Mail object or a subject/body pair
     * depending on the Joomla version. Keep this handler intentionally loose.
     */
    public function onMailBeforeSend(&$subject, &$mail, &$mailer = null)
    {
        if (self::$mailGuard || !$this->app->isClient('site')) {
            return;
        }
        if (!RumaxHelper::schemaReady()) {
            return;
        }
        try {
            $settings = RumaxHelper::getSettings();
        } catch (\Throwable $exception) {
            error_log('[Ru-max] Mail notification skipped: ' . $exception->getMessage());
            return;
        }
        if (empty($settings['notifications_enabled'])) {
            return;
        }
        self::$mailGuard = true;
        try {
            $body = is_object($mail) && method_exists($mail, 'getBody') ? $mail->getBody() : (string) $mail;
            $recipients = (array) ($settings['notify_chat_ids'] ?? []);
            if (!$recipients) {
                $recipients = array_filter(array_map('trim', explode(',', (string) ($settings['chat_id'] ?? ''))));
            }
            $text = RumaxHelper::renderTemplate($settings['notify_template'], [
                'email_subject' => (string) $subject,
                'email_message' => strip_tags((string) $body),
                'site_name' => Factory::getApplication()->get('sitename'),
            ]);
            foreach ($recipients as $chatId) {
                RumaxHelper::sendMax($chatId, $text, $settings['notify_format'] ?? 'html');
            }
        } finally {
            self::$mailGuard = false;
        }
    }
}