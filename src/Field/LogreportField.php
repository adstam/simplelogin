<?php
namespace Adstam\Plugin\System\Simplelogin\Field;
defined('_JEXEC') or die;
use Joomla\CMS\Factory;
use Joomla\CMS\Form\FormField;
use Joomla\CMS\Language\Text;
use Joomla\CMS\HTML\HTMLHelper;
use Adstam\Plugin\System\Simplelogin\Helper\ReportHelper;

class LogReportField extends FormField
{
    protected $type = 'LogReport';

    protected function getInput()
    {
        $type = isset($_GET['simplelogin_log_type'])
            ? preg_replace('/[^a-zA-Z0-9_*]/', '', $_GET['simplelogin_log_type'])
            : '';
        $rows  = ReportHelper::getLogRows($type);
        $types = ReportHelper::getLogTypes();

        $labels = json_encode([
            'deleteType' => Text::_('PLG_SYSTEM_SIMPLELOGIN_LOG_DELETE_TYPE'),
            'deleteAll'  => Text::_('PLG_SYSTEM_SIMPLELOGIN_LOG_DELETE_ALL'),
            'confirmType'=> Text::_('PLG_SYSTEM_SIMPLELOGIN_LOG_CONFIRM_TYPE'),
            'confirmAll' => Text::_('PLG_SYSTEM_SIMPLELOGIN_LOG_CONFIRM_ALL'),
        ]);

        $doc = Factory::getApplication()->getDocument();
        $doc->addScriptDeclaration("var SimpleloginLogLabels = {$labels};");

        HTMLHelper::_('script', 'plg_system_simplelogin/logreport.js', ['relative' => true, 'version' => 'auto']);

        ob_start();
        require __DIR__ . '/../tmpl/logs.php';
        return ob_get_clean();
    }
}