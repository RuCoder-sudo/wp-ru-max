<?php
defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Component\Rumax\Administrator\Helper\RumaxHelper;

require_once JPATH_ADMINISTRATOR . '/components/com_rumax/src/Helper/RumaxHelper.php';

class PlgContentRumax extends CMSPlugin
{
    protected $app;

    public function onContentAfterSave($context, $article, $isNew, $data = [])
    {
        if (!$this->params->get('enabled', 1) || $context !== 'com_content.article' || empty($article->id)) {
            return true;
        }
        $input = Factory::getApplication()->input;
        $meta = RumaxHelper::getPostMeta((int) $article->id);
        if (isset($data['rumax']) && is_array($data['rumax'])) {
            $meta = array_replace($meta, $data['rumax']);
            RumaxHelper::savePostMeta((int) $article->id, $meta);
        } elseif ($input->post->get('rumax', [], 'array')) {
            $meta = array_replace($meta, $input->post->get('rumax', [], 'array'));
            RumaxHelper::savePostMeta((int) $article->id, $meta);
        }
        RumaxHelper::enqueueArticle($article, (bool) $isNew);
        return true;
    }

    public function onContentPrepare($context, &$article, &$params, $page = 0)
    {
        if ($this->app->isClient('administrator') || $context !== 'com_content.article' || empty($article->id)) {
            return;
        }
        $settings = RumaxHelper::getSettings();
        if (empty($settings['share_button_enabled']) || !empty($article->_rumax_share_added)) {
            return;
        }
        $article->_rumax_share_added = true;
        $url = method_exists($article, 'getLink') ? $article->getLink() : Factory::getApplication()->get('live_site');
        $title = htmlspecialchars((string) $article->title, ENT_QUOTES, 'UTF-8');
        $safeUrl = htmlspecialchars((string) $url, ENT_QUOTES, 'UTF-8');
        $icon = RumaxHelper::mediaUrl('max-32x32.png');
        $article->text .= '<div class="wp-ru-max-share-wrap"><button type="button" class="wp-ru-max-share-btn" data-url="' . $safeUrl . '" data-title="' . $title . '"><img src="' . $icon . '" width="20" height="20" alt="">Поделиться в MAX</button><div class="wp-ru-max-share-popup" hidden><button type="button" class="wp-ru-max-share-open-max"><img src="' . $icon . '" width="18" height="18" alt=""> Открыть MAX</button><button type="button" class="wp-ru-max-share-copy">Скопировать ссылку</button><div class="wp-ru-max-share-notice" role="status"></div></div></div>';
        $document = Factory::getApplication()->getDocument();
        $document->addStyleDeclaration(self::shareCss());
        $document->addScriptDeclaration(self::shareJs());
    }

    public function onContentPrepareForm($form, $data)
    {
        if (!is_object($form) || $form->getName() !== 'com_content.article') {
            return true;
        }
        $form->loadFile(__DIR__ . '/forms/rumax.xml', false);
        return true;
    }

    private static function shareCss()
    {
        return '.wp-ru-max-share-wrap{position:relative;display:block;text-align:left;margin:28px 0 16px;padding-top:20px;border-top:1px solid #e5e7eb}.wp-ru-max-share-btn{display:inline-flex;align-items:center;gap:8px;padding:11px 22px;background:#0077ff;color:#fff;border:0;border-radius:10px;font-size:15px;font-weight:600;cursor:pointer;box-shadow:0 2px 10px rgba(0,119,255,.3)}.wp-ru-max-share-btn img{display:block}.wp-ru-max-share-popup{position:absolute;top:calc(100% - 6px);left:0;z-index:9999;background:#fff;border:1px solid #e0e7ef;border-radius:12px;box-shadow:0 8px 32px rgba(0,0,0,.15);padding:8px;min-width:230px}.wp-ru-max-share-popup[hidden]{display:none}.wp-ru-max-share-popup button{display:block;width:100%;padding:10px 14px;border:0;border-radius:8px;background:transparent;text-align:left;cursor:pointer}.wp-ru-max-share-notice{font-size:12px;color:#16a34a;padding:4px 14px}';
    }

    private static function shareJs()
    {
        return "(function(){document.addEventListener('click',function(e){var wrap=e.target.closest('.wp-ru-max-share-wrap');document.querySelectorAll('.wp-ru-max-share-popup').forEach(function(p){if(!wrap||!wrap.contains(p))p.hidden=true});if(!wrap)return;var btn=e.target.closest('.wp-ru-max-share-btn'),open=e.target.closest('.wp-ru-max-share-open-max'),copy=e.target.closest('.wp-ru-max-share-copy'),pop=wrap.querySelector('.wp-ru-max-share-popup'),url=btn?btn.dataset.url:wrap.querySelector('.wp-ru-max-share-btn').dataset.url,title=wrap.querySelector('.wp-ru-max-share-btn').dataset.title||document.title;if(btn){if(navigator.share){navigator.share({title:title,url:url}).catch(function(){});}else{pop.hidden=!pop.hidden;}}if(open){var a=document.createElement('a');a.href='maxim://forward?text='+encodeURIComponent(title+'\\n'+url);a.click();navigator.clipboard&&navigator.clipboard.writeText(url);pop.hidden=true;}if(copy){navigator.clipboard&&navigator.clipboard.writeText(url);var n=wrap.querySelector('.wp-ru-max-share-notice');if(n)n.textContent='Ссылка скопирована';}});})();";
    }
}