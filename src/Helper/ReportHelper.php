<?php
/**
 * @package   Simplelogin
 * @author    Ad Stam
 * @copyright Copyright (C) 2026 Ad Stam. All rights reserved.
 * @license   GNU General Public License version 2 or later; see LICENSE.txt
 * @link      https://demo.adstam.nl
 */

namespace StamPlusJ\Plugin\System\Simplelogin\Helper;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;

class ReportHelper
{
    public static function getThrottleRows(): array
    {
        $db = Factory::getDbo();

        $query = $db->getQuery(true)
            ->select([
                't.*',
                'u.username'
            ])
            ->from($db->quoteName('#__simple_login_throttle', 't'))
            ->leftJoin(
                $db->quoteName('#__users', 'u')
                . ' ON u.id = t.user_id'
            )
            ->order('t.created DESC');

        $db->setQuery($query);

        return $db->loadObjectList();
    }

    public static function getLogRows(string $type = ''): array
    {
        $db = Factory::getDbo();

        $query = $db->getQuery(true)
            ->select([
                'l.*',
                'u.username'
            ])
            ->from($db->quoteName('#__simple_login_log', 'l'))
            ->leftJoin(
                $db->quoteName('#__users', 'u')
                . ' ON u.id = l.user_id'
            );

//        if ($type !== '') {
//           $query->where(
//                $db->quoteName('l.type')
//                . ' = ' . $db->quote($type)
//            );
//        }
					if (!empty($type)) {
    				 if (str_ends_with($type, '*')) {
        		 		$prefix = rtrim($type, '*');
        				$query->where('type LIKE ' . $db->quote($prefix . '%'));
    				 } else {
        		 	  $query->where('type = ' . $db->quote($type));
    				 }
					}

        $query->order('l.created DESC');

        $db->setQuery($query, 0, 200);

        return $db->loadObjectList();
    }

    public static function getLogTypes(): array
    {
        return [
            'LoginFlow',
            'AccountEvent',
            'DebugDiagnostics',
            'DebugFlowTrace',
            'DebugRequestTrace',
            'InviteFlow',
            'SecurityIncident',
			'ImageError',
        ];
    }
	
	    /**
     * Haalt alle pending registraties op (gebruikers met block=1 en pending activation).
     *
     * @return array
     */
    public static function getPendingApprovals(): array
    {
        $db = Factory::getDbo();

        // LET OP: activation alleen filteren op 'sl-pending:%' is niet genoeg -- na het
        // bevestigen van de invite-mail wordt activation al leeggemaakt (zie
        // RegisterFlowTrait::handleInviteActivation()), terwijl block=1 blijft staan
        // tot een admin goed- of afkeurt. Op dat moment is activation='' niet meer te
        // onderscheiden van een normaal (om andere redenen) geblokkeerd account. Daarom
        // koppelen we expliciet aan een bestaand invite-token van deze plugin, zodat
        // alleen accounts die daadwerkelijk via de Simplelogin-registratie kwamen in
        // deze lijst verschijnen -- en dus alleen die accounts hier goedgekeurd of
        // (definitief!) verwijderd kunnen worden via Approve/Reject.
        $inviteExists = $db->getQuery(true)
            ->select('1')
            ->from($db->quoteName('#__simple_login', 'sl'))
            ->where('sl.user_id = u.id')
            ->where('sl.type = ' . $db->quote('invite'));

        $query = $db->getQuery(true)
            ->select([
                'u.id',
                'u.name',
                'u.username',
                'u.email',
                'u.block',
                'u.activation',
                'u.registerDate',
            ])
            ->from($db->quoteName('#__users', 'u'))
            ->where($db->quoteName('u.block') . ' = 1')
            ->where('EXISTS (' . (string) $inviteExists . ')')
            ->order('u.registerDate DESC');

        $db->setQuery($query);

        return $db->loadObjectList();
    }
}