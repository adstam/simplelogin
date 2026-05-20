<?php

namespace Adstam\Plugin\System\Simplelogin\Field;

defined('_JEXEC') or die;

use Joomla\CMS\Form\FormField;
use Adstam\Plugin\System\Simplelogin\Helper\ReportHelper;

class ThrottleReportField extends FormField
{
    protected $type = 'ThrottleReport';

    protected function getInput()
    {
        $rows = ReportHelper::getThrottleRows();

        ob_start();

        require __DIR__ . '/../tmpl/throttle.php';

        return ob_get_clean();
    }
}