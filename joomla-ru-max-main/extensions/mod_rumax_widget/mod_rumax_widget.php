<?php
defined('_JEXEC') or die;

use Joomla\Component\Rumax\Administrator\Helper\RumaxHelper;
use Joomla\CMS\Helper\ModuleHelper;

require_once JPATH_ADMINISTRATOR . '/components/com_rumax/src/Helper/RumaxHelper.php';

if ($params->get('enabled', 1)) {
    $widgetHtml = RumaxHelper::renderWidget();
    require ModuleHelper::getLayoutPath('mod_rumax_widget', $params->get('layout', 'default'));
}