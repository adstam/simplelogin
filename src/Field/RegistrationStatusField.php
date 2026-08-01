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

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Form\FormField;

/**
 * Leest de globale com_users-instelling "Zelfregistratie toestaan" server-side
 * uit en zet die als een ECHT, lokaal veld (verborgen input) in dit formulier.
 *
 * showon in Joomla is client-side JS die in de DOM van HETZELFDE formulier naar
 * een veld met de gegeven naam zoekt. Een cross-component referentie zoals
 * showon="com_users.allowUserRegistration:1" werkt daardoor NIET -- dat veld
 * bestaat simpelweg niet op deze pagina. Door de waarde hier zelf te "kopiëren"
 * naar een eigen, lokaal veld (registration_enabled), kan showon daar wel
 * gewoon op filteren: showon="registration_enabled:1".
 *
 * De waarde wordt bij elke keer laden van het formulier vers uit de com_users-
 * configuratie gelezen (nooit uit een eerder opgeslagen plugin-parameter) --
 * filter="unset" op het XML-veld zorgt dat er ook niets van wordt opgeslagen.
 */
class RegistrationstatusField extends FormField
{
    protected $type = 'Registrationstatus';

    protected function getInput(): string
    {
        $enabled = (int) ComponentHelper::getParams('com_users')->get('allowUserRegistration', 0);

        return '<input type="hidden" id="' . $this->id . '" name="' . $this->name . '" value="' . $enabled . '">';
    }
}
