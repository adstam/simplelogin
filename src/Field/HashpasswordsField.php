<?php
namespace Adstam\Plugin\System\Simplelogin\Field;
defined('_JEXEC') or die;
use Joomla\CMS\Form\FormField;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Language\Text;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Factory;

class HashpasswordsField extends FormField
{
    protected $type = 'Hashpasswords';

    public function getLabel(): string
    {
        return '';
    }

    protected function getInput(): string
    {
        $token = Session::getFormToken();
        $url   = 'index.php?option=com_ajax&plugin=simplelogin&group=system&format=json';

        $config = json_encode([
            'url'   => $url,
            'token' => $token,
        ]);

        $labels = json_encode([
            'confirm'    => Text::_('PLG_SYSTEM_SIMPLELOGIN_HASH_CONFIRM'),
            'processing' => Text::_('PLG_SYSTEM_SIMPLELOGIN_HASH_PROCESSING'),
            'warning'    => Text::_('PLG_SYSTEM_SIMPLELOGIN_HASH_WARNING'),
            'error'      => Text::_('PLG_SYSTEM_SIMPLELOGIN_HASH_ERROR'),
            'invalid'    => Text::_('PLG_SYSTEM_SIMPLELOGIN_HASH_INVALID'),
        ]);
				
        $doc = Factory::getApplication()->getDocument();
        $doc->addScriptDeclaration("var SimpleloginHashConfig = {$config};");
        $doc->addScriptDeclaration("var SimpleloginHashLabels = {$labels};");

        HTMLHelper::_('script', 'plg_system_simplelogin/hashpasswords.js', ['relative' => true, 'version' => 'auto']);

        $btnLabel = Text::_('PLG_SYSTEM_SIMPLELOGIN_HASH_BUTTON');

        return <<<HTML
            <button type="button" class="btn btn-danger simplelogin-hash-btn">
                {$btnLabel}
            </button>
            <div id="hash-result" style="margin-top:10px;"></div>
HTML;
    }
}