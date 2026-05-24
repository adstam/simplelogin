<?php
namespace Adstam\Plugin\System\Simplelogin\Field;

defined('_JEXEC') or die;

use Joomla\CMS\Form\FormField;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Session\Session;

class ExportlogField extends FormField
{
    protected $type = 'exportlog';

    public function getInput(): string
    {
        $token = Session::getFormToken();

        // Sleutels beschikbaar maken voor Joomla.Text._() in JS
        Text::script('PLG_SYSTEM_SIMPLELOGIN_BTN_EXPORT_SENDING');
        Text::script('PLG_SYSTEM_SIMPLELOGIN_MSG_EXPORT_SENT');
        Text::script('PLG_SYSTEM_SIMPLELOGIN_MSG_EXPORT_FAILED');

        return '<button type="button" id="sl-export-log-btn"
                    class="btn btn-outline-secondary"
                    data-token="' . $token . '">
                    ' . Text::_('PLG_SYSTEM_SIMPLELOGIN_BTN_EXPORT_LOG') . '
                </button>
                <span id="sl-export-log-status" class="ms-2"></span>';
    }
}