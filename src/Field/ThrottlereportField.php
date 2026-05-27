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