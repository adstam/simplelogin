<?php
/**
 * @package   Simplelogin
 * @author    Ad Stam
 * @copyright Copyright (C) 2026 Ad Stam. All rights reserved.
 * @license   GNU General Public License version 2 or later; see LICENSE.txt
 * @link      https://demo.adstam.nl
 */

namespace StamPlusJ\Plugin\System\Simplelogin\Field;

defined('_JEXEC') or die;

use Joomla\CMS\Form\FormField;
use StamPlusJ\Plugin\System\Simplelogin\Helper\ReportHelper;

class ApprovalreportField extends FormField
{
    protected $type = 'Approvalreport';

    protected function getInput()
    {
        $rows = ReportHelper::getPendingApprovals();

        ob_start();

        require __DIR__ . '/../tmpl/approvals.php';

        return ob_get_clean();
    }
}