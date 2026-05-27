<?php
/**
 * @package   Simplelogin
 * @author    Ad Stam
 * @copyright Copyright (C) 2026 Ad Stam. All rights reserved.
 * @license   GNU General Public License version 2 or later; see LICENSE.txt
 * @link      https://demo.adstam.nl
 */
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
        $type = Factory::getApplication()->input->get('simplelogin_log_type', '', 'CMD');
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