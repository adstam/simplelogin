<?php

namespace Adstam\Plugin\System\Simplelogin\Helper;

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
        ];
    }
}