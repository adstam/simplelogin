<?php
/**
 * @package   Simplelogin
 * @author    Ad Stam
 * @copyright Copyright (C) 2026 Ad Stam. All rights reserved.
 * @license   GNU General Public License version 2 or later; see LICENSE.txt
 * @link      https://demo.adstam.nl
 */

namespace StamPlusJ\Plugin\System\Simplelogin\Traits;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\User\UserFactoryInterface;

/**
 * AjaxTrait
 *
 * Verantwoordelijk voor:
 * - AJAX-methode routing (HashPasswords, GetLogRows, PurgeLogRows, ExportLog, ApproveUser, RejectUser)
 * - Wachtwoorden van alle niet-admin frontend-gebruikers overschrijven
 * - Logrijen ophalen en als HTML-tabel teruggeven
 * - Logrijen verwijderen (gefilterd op type)
 * - Logexport per e-mail versturen (logtabel + Joomla logbestand, laatste 24 uur)
 * - Admin goedkeuring/afkeuring van registraties
 * - Admin CSRF-token + rechtencontrole (core.manage op com_plugins)
 *
 * Gebruikt state properties van Simplelogin:
 *   (geen — alle methoden zijn request/response gebaseerd)
 *
 * Gebruikt methoden uit andere traits:
 *   (geen — assertPluginManageAccess() is volledig zelfstandig)
 */
trait AjaxTrait
{
    // ===========================================================================
    // AJAX handlers
    // ===========================================================================

    /**
     * Overschrijft wachtwoorden van alle niet-admin frontend-gebruikers
     * met random hashes zodat password-login onmogelijk is.
     */
    protected function ajaxHashPasswords(): array
    {
        $app = Factory::getApplication();
        $db  = Factory::getDbo();

        if (!\Joomla\CMS\Session\Session::checkToken(
            $app->input->getMethod() === 'POST' ? 'post' : 'get'
        )) {
            return ['success' => false, 'message' => Text::_('PLG_SYSTEM_SIMPLELOGIN_ERR_INVALID_TOKEN')];
        }

        if (!$app->getIdentity()->authorise('core.manage', 'com_plugins')) {
            return ['success' => false, 'message' => Text::_('PLG_SYSTEM_SIMPLELOGIN_ERR_NO_PERMISSION')];
        }

        $userIds = $db->setQuery(
            $db->getQuery(true)
                ->select('id')
                ->from('#__users')
                ->where('block = 0')
        )->loadColumn();

        $userFactory = Factory::getContainer()->get(UserFactoryInterface::class);
        $processed   = 0;
        $skipped     = 0;

        foreach ($userIds as $userId) {
            $targetUser = $userFactory->loadUserById($userId);

            if (!$targetUser || !$targetUser->id) {
                continue;
            }

            if ($targetUser->authorise('core.admin')) {
                $skipped++;
                continue;
            }

            try {
                $db->setQuery(
                    $db->getQuery(true)
                        ->update('#__users')
                        ->set('password = ' . $db->quote(
                            password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT)
                        ))
                        ->where('id = ' . (int) $targetUser->id)
                )->execute();
                $processed++;
            } catch (\Exception $e) {
                // Sla gebruiker over bij fout, ga door met de rest
            }
        }

        return [
            'success' => true,
            'message' => Text::sprintf('PLG_SYSTEM_SIMPLELOGIN_MSG_HASH_RESULT', $processed, $skipped),
        ];
    }

    /**
     * Haalt logrijen op en geeft ze terug als HTML-tabel.
     * Optioneel gefilterd op type (wildcard * aan het einde toegestaan).
     */
    private function ajaxGetLogRows(): array
    {
        if ($denied = $this->assertPluginManageAccess()) {
            return $denied;
        }

        $type = preg_replace(
            '/[^a-zA-Z0-9_*]/',
            '',
            (string) Factory::getApplication()->input->getString('type', '')
        );

        $rows = \StamPlusJ\Plugin\System\Simplelogin\Helper\ReportHelper::getLogRows($type);

        ob_start();
        require __DIR__ . '/../tmpl/logs_table.php';
        $html = ob_get_clean();

        return ['success' => true, 'data' => $html];
    }

    /**
     * Verwijdert logrijen uit #__simple_login_log.
     * Optioneel gefilterd op type (wildcard * aan het einde toegestaan).
     */
    private function ajaxPurgeLogRows(): array
    {
        if ($denied = $this->assertPluginManageAccess()) {
            return $denied;
        }

        $type = preg_replace(
            '/[^a-zA-Z0-9_*]/',
            '',
            (string) Factory::getApplication()->input->getString('type', '')
        );

        $db    = Factory::getDbo();
        $query = $db->getQuery(true)->delete('#__simple_login_log');

        if (!empty($type)) {
            if (str_ends_with($type, '*')) {
                $prefix = rtrim($type, '*');
                $query->where('type LIKE ' . $db->quote($prefix . '%'));
            } else {
                $query->where('type = ' . $db->quote($type));
            }
        }

        $db->setQuery($query)->execute();

        $affected = $db->getAffectedRows();

        return [
            'success' => true,
            'message' => Text::sprintf('PLG_SYSTEM_SIMPLELOGIN_MSG_ROWS_DELETED', $affected),
        ];
    }

    /**
     * Exporteert de logtabel en het Joomla-logbestand van de laatste 24 uur
     * en verstuurt het resultaat per e-mail naar het site-mailadres.
     */
    private function ajaxExportLog(): array
    {
        if ($denied = $this->assertPluginManageAccess()) {
            return $denied;
        }

        $db  = Factory::getDbo();
        $app = Factory::getApplication();

        $since = date('Y-m-d H:i:s', strtotime('-24 hours'));

        // ----------------------------------------------------------------
        // Deel 1: logtabel
        // ----------------------------------------------------------------
        $rows = $db->setQuery(
            $db->getQuery(true)
                ->select(['created', 'type', 'status', 'username', 'user_agent'])
                ->from('#__simple_login_log')
                ->where('created >= ' . $db->quote($since))
                ->order('created ASC')
        )->loadAssocList();

        $lines   = [];
        $lines[] = '=== SIMPLELOGIN LOG TABLE (laatste 24 uur) ===';
        $lines[] = str_repeat('-', 60);

        if (empty($rows)) {
            $lines[] = '(geen regels)';
        } else {
            foreach ($rows as $row) {
                $lines[] = sprintf(
                    '[%s] %-22s %-30s user: %s',
                    $row['created'],
                    $row['type'],
                    $row['status'],
                    $row['username'] ?? '-'
                );
            }
        }

        $lines[] = '';

        // ----------------------------------------------------------------
        // Deel 2: Joomla-logbestand
        // ----------------------------------------------------------------
        $logPath = $app->get('log_path', JPATH_ROOT . '/logs')
            . '/plg_system_simplelogin.php';

        $lines[] = '=== SIMPLELOGIN FILE LOG ===';
        $lines[] = str_repeat('-', 60);

        if (!is_file($logPath)) {
            $lines[] = '(logbestand niet gevonden: ' . $logPath . ')';
        } else {
            $content   = file_get_contents($logPath);
            $cutoff    = strtotime('-24 hours');
            $fileLines = explode("\n", $content);
            $found     = false;

            foreach ($fileLines as $fileLine) {
                if (preg_match('/^(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2})/', $fileLine, $m)) {
                    if (strtotime($m[1]) < $cutoff) {
                        continue;
                    }
                }

                if (str_starts_with($fileLine, '#') || trim($fileLine) === '') {
                    continue;
                }

                $lines[] = $fileLine;
                $found   = true;
            }

            if (!$found) {
                $lines[] = '(geen regels in de laatste 24 uur)';
            }
        }

        // ----------------------------------------------------------------
        // Mail versturen
        // ----------------------------------------------------------------
        $config  = $app->getConfig();
        $mailer  = Factory::getMailer();

        $mailer->setSender([$config->get('mailfrom'), $config->get('fromname')]);
        $mailer->addRecipient($config->get('mailfrom'));
        $mailer->setSubject(
            '[' . $config->get('sitename') . '] Simplelogin log export ' . date('Y-m-d H:i')
        );
        $mailer->setBody(implode("\n", $lines));

        $sent = $mailer->send();

        $app->getLanguage()->load(
            'plg_system_simplelogin',
            JPATH_PLUGINS . '/system/simplelogin'
        );

        if ($sent === true) {
            return [
                'success' => true,
                'message' => Text::sprintf(
                    'PLG_SYSTEM_SIMPLELOGIN_MSG_EXPORT_SENT',
                    $config->get('mailfrom')
                ),
            ];
        }

        return [
            'success' => false,
            'message' => Text::_('PLG_SYSTEM_SIMPLELOGIN_MSG_EXPORT_FAILED'),
        ];
    }

    // ===========================================================================
    // Admin Approval Handlers
    // ===========================================================================

    /**
     * Goedkeurt een pending registratie (block=0 zetten).
     */
    private function ajaxApproveUser(): array
    {
        $app = Factory::getApplication();
        $input = $app->input;
        $userId = $input->getInt('user_id', 0);

        // DEBUG: Log alle input
        error_log('SimpleLogin DEBUG: ajaxApproveUser called, user_id=' . $userId);

        if ($denied = $this->assertPluginManageAccess()) {
            error_log('SimpleLogin DEBUG: Access denied in ajaxApproveUser');
            return $denied;
        }

        error_log('SimpleLogin DEBUG: Access granted, processing user_id=' . $userId);

        if ($userId <= 0) {
            error_log('SimpleLogin DEBUG: Invalid user_id');
            return ['success' => false, 'message' => Text::_('PLG_SYSTEM_SIMPLELOGIN_ERR_INVALID_TOKEN')];
        }

        $db = Factory::getDbo();

        // Controleer of gebruiker bestaat en geblokkeerd is
        $user = $db->setQuery(
            $db->getQuery(true)
                ->select('block')
                ->from('#__users')
                ->where('id = ' . (int) $userId)
        )->loadObject();

        if (!$user) {
            error_log('SimpleLogin DEBUG: User not found for id=' . $userId);
            return ['success' => false, 'message' => Text::_('PLG_SYSTEM_SIMPLELOGIN_APPROVAL_INVALID_USER')];
        }

        error_log('SimpleLogin DEBUG: User found, block=' . $user->block);

        if ((int) $user->block !== 1) {
            error_log('SimpleLogin DEBUG: User not blocked (block=' . $user->block . ')');
            return ['success' => false, 'message' => Text::_('PLG_SYSTEM_SIMPLELOGIN_APPROVAL_INVALID_USER')];
        }

        // Deblokkeer gebruiker
        $result = $db->setQuery(
            $db->getQuery(true)
                ->update('#__users')
                ->set('block = 0')
                ->where('id = ' . (int) $userId)
        )->execute();

        error_log('SimpleLogin DEBUG: Update execute result=' . ($result ? 'true' : 'false'));

        if (!$result) {
            error_log('SimpleLogin DEBUG: Update failed!');
            return ['success' => false, 'message' => Text::_('PLG_SYSTEM_SIMPLELOGIN_APPROVAL_DB_ERROR')];
        }

        // Controleer of de update echt is doorgevoerd
        $check = $db->setQuery(
            $db->getQuery(true)
                ->select('block')
                ->from('#__users')
                ->where('id = ' . (int) $userId)
        )->loadResult();

        error_log('SimpleLogin DEBUG: Post-update block=' . $check);

        $this->log($userId, 'admin_approved_registration');

        $this->sendApprovalEmail($userId);

        return [
            'success' => true,
            'message' => Text::_('PLG_SYSTEM_SIMPLELOGIN_APPROVAL_SUCCESS'),
        ];
    }

/**
 * Keurt een pending registratie af (account verwijderen).
 */
private function ajaxRejectUser(): array
{
    $app = Factory::getApplication();
    $input = $app->input;
    $userId = $input->getInt('user_id', 0);
    $reason = $input->getString('reason', '');

    if ($denied = $this->assertPluginManageAccess()) {
        return $denied;
    }

    if ($userId <= 0) {
        return ['success' => false, 'message' => Text::_('PLG_SYSTEM_SIMPLELOGIN_ERR_INVALID_TOKEN')];
    }

    $db = Factory::getDbo();

    // Controleer of gebruiker bestaat en geblokkeerd is
    $user = $db->setQuery(
        $db->getQuery(true)
            ->select('block')
            ->from('#__users')
            ->where('id = ' . (int) $userId)
    )->loadObject();

    if (!$user) {
        return ['success' => false, 'message' => Text::_('PLG_SYSTEM_SIMPLELOGIN_APPROVAL_INVALID_USER')];
    }

    if ((int) $user->block !== 1) {
        return ['success' => false, 'message' => Text::_('PLG_SYSTEM_SIMPLELOGIN_APPROVAL_INVALID_USER')];
    }

    // 👇 NIEUW: Verstuur afkeurmail VOOR het verwijderen
    $this->sendRejectionEmail($userId, $reason);

    // Verwijder gebruiker
    $result1 = $db->setQuery(
        $db->getQuery(true)
            ->delete('#__users')
            ->where('id = ' . (int) $userId)
    )->execute();

    $result2 = $db->setQuery(
        $db->getQuery(true)
            ->delete('#__user_usergroup_map')
            ->where('user_id = ' . (int) $userId)
    )->execute();

    if (!$result1 || !$result2) {
        return ['success' => false, 'message' => Text::_('PLG_SYSTEM_SIMPLELOGIN_APPROVAL_DB_ERROR')];
    }

    $this->log($userId, 'admin_rejected_registration');

    return [
        'success' => true,
        'message' => Text::_('PLG_SYSTEM_SIMPLELOGIN_APPROVAL_REJECTED'),
    ];
}

    // ===========================================================================
    // AJAX dispatcher
    // ===========================================================================

    /**
     * AJAX handler — bereikbaar via index.php?option=com_ajax&plugin=simplelogin&format=json
     */
    public function onAjaxSimplelogin(): array
    {
        $input  = Factory::getApplication()->input;
        $method = (string) $input->getString('method', '');
        $userId = $input->getInt('user_id', 0);

        // DEBUG: Log alle input
        error_log('SimpleLogin AJAX: method=' . $method . ', user_id=' . $userId);

        try {
            if ($method === 'HashPasswords') {
                error_log('SimpleLogin DEBUG: Routing to HashPasswords');
                return $this->ajaxHashPasswords();
            }

            if ($method === 'GetLogRows') {
                error_log('SimpleLogin DEBUG: Routing to GetLogRows');
                return $this->ajaxGetLogRows();
            }

            if ($method === 'PurgeLogRows') {
                error_log('SimpleLogin DEBUG: Routing to PurgeLogRows');
                return $this->ajaxPurgeLogRows();
            }

            if ($method === 'ExportLog') {
                error_log('SimpleLogin DEBUG: Routing to ExportLog');
                return $this->ajaxExportLog();
            }

            if ($method === 'ApproveUser') {
                error_log('SimpleLogin DEBUG: Routing to ApproveUser');
                return $this->ajaxApproveUser();
            }

            if ($method === 'RejectUser') {
                error_log('SimpleLogin DEBUG: Routing to RejectUser');
                return $this->ajaxRejectUser();
            }

            error_log('SimpleLogin DEBUG: Unknown method: ' . $method);
            return [
                'success' => false,
                'message' => Text::sprintf('PLG_SYSTEM_SIMPLELOGIN_ERR_UNKNOWN_METHOD', $method),
            ];
        } catch (\Throwable $e) {
            error_log('SimpleLogin DEBUG: Exception in onAjaxSimplelogin: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    // ===========================================================================
    // Toegangscontrole
    // ===========================================================================

    /**
     * Controleert CSRF-token en core.manage rechten op com_plugins.
     * Geeft null terug bij succes, of een fout-array bij mislukking.
     *
     * @return array{success: false, message: string}|null
     */
    private function assertPluginManageAccess(): ?array
    {
        $app = Factory::getApplication();

        // Check BOTH GET and POST tokens (AJAX uses POST)
        if (!\Joomla\CMS\Session\Session::checkToken('get') && 
            !\Joomla\CMS\Session\Session::checkToken('post')) {
            error_log('SimpleLogin DEBUG: CSRF token check failed');
            return ['success' => false, 'message' => Text::_('PLG_SYSTEM_SIMPLELOGIN_ERR_INVALID_TOKEN')];
        }

        if (!$app->getIdentity()->authorise('core.manage', 'com_plugins')) {
            error_log('SimpleLogin DEBUG: core.manage permission denied');
            return ['success' => false, 'message' => Text::_('PLG_SYSTEM_SIMPLELOGIN_ERR_NO_PERMISSION')];
        }

        return null;
    }
	
		/**
	 * Verstuurd goedkeuringsmail naar gebruiker.
	 */
	private function sendApprovalEmail(int $userId): void
	{
		$db = Factory::getDbo();
		$app = Factory::getApplication();
		$config = $app->getConfig();

		$user = Factory::getContainer()->get(UserFactoryInterface::class)->loadUserById($userId);
		if (!$user || !$user->id || !$user->email) {
			return;
		}

		$loginUrl = Uri::root() . 'index.php?simplelogin=1';

		$subject = $this->params->get('mail_approval_subject', 'Your registration has been approved');
		$body = $this->mailService->buildMailBody(
			$this->params->get('mail_approval_body', ''),
			[
				'#name'  => $user->name,
				'#link'  => $loginUrl,
				'#sitename' => $config->get('sitename'),
			]
		);

		$this->mailService->sendMail($user->email, $subject, $body);
	}

	/**
	 * Verstuurd afkeurmail naar gebruiker.
	 */
	private function sendRejectionEmail(int $userId, string $reason = ''): void
	{
		$db = Factory::getDbo();
		$app = Factory::getApplication();
		$config = $app->getConfig();

		$user = Factory::getContainer()->get(UserFactoryInterface::class)->loadUserById($userId);
		if (!$user || !$user->id || !$user->email) {
			return;
		}

		$subject = $this->params->get('mail_rejection_subject', 'Your registration has been rejected');
		$body = $this->mailService->buildMailBody(
			$this->params->get('mail_rejection_body', ''),
			[
				'#name'    => $user->name,
				'#reason'  => $reason,
				'#sitename' => $config->get('sitename'),
			]
		);

		$this->mailService->sendMail($user->email, $subject, $body);
	}
}