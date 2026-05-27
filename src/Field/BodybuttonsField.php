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
use Joomla\CMS\Uri\Uri;

class BodybuttonsField extends FormField
{
    protected $type = 'Bodybuttons';

    public function getInput(): string
    {
        /** @var \Joomla\CMS\Document\HtmlDocument $doc */
        $doc = \Joomla\CMS\Factory::getApplication()->getDocument();
        $doc->addScript(
            Uri::root() . 'media/plg_system_simplelogin/js/admin-body-buttons.js',
            [],
            ['defer' => true]
        );

        return '<div id="simplelogin-var-buttons-placeholder"></div>';
    }
}