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
use Joomla\CMS\Uri\Uri;
use Joomla\Registry\Registry;

/**
 * AjaxTrait
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
                // Sla gebruiker over bij fout
            }
        }

        return [
            'success' => true,
            'message' => Text::sprintf('PLG_SYSTEM_SIMPLELOGIN_MSG_HASH_RESULT', $processed, $skipped),
        ];
    }

    /**
     * Haalt logrijen op en geeft ze terug als HTML-tabel.
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
     * Exporteert de logtabel en het Joomla-logbestand.
     */
    private function ajaxExportLog(): array
    {
        if ($denied = $this->assertPluginManageAccess()) {
            return $denied;
        }

        $db  = Factory::getDbo();
        $app = Factory::getApplication();

        $since = date('Y-m-d H:i:s', strtotime('-24 hours'));

        // Logtabel
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

        // Joomla-logbestand
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

        // Mail versturen
        $mailer  = Factory::getMailer();

        $mailer->setSender([$app->get('mailfrom'), $app->get('fromname')]);
        $mailer->addRecipient($app->get('mailfrom'));
        $mailer->setSubject(
            '[' . $app->get('sitename') . '] Simplelogin log export ' . date('Y-m-d H:i')
        );
        $mailer->setBody(implode("\n", $lines));

        $sent = $mailer->send();

        if ($sent === true) {
            return [
                'success' => true,
                'message' => Text::sprintf(
                    'PLG_SYSTEM_SIMPLELOGIN_MSG_EXPORT_SENT',
                    $app->get('mailfrom')
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
     * Goedkeurt een pending registratie.
     */
    private function ajaxApproveUser(): array
    {
        $app = Factory::getApplication();
        $input = $app->input;
        $userId = $input->getInt('user_id', 0);

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
                ->select('block, activation')
                ->from('#__users')
                ->where('id = ' . (int) $userId)
        )->loadObject();

        if (!$user) {
            return ['success' => false, 'message' => Text::_('PLG_SYSTEM_SIMPLELOGIN_APPROVAL_INVALID_USER')];
        }

        if ((int) $user->block !== 1) {
            return ['success' => false, 'message' => Text::_('PLG_SYSTEM_SIMPLELOGIN_APPROVAL_INVALID_USER')];
        }

        // Deblokkeer gebruiker. 'activation' blijft ongewijzigd:
        // - als de gebruiker al zelf geactiveerd had (activation al leeg), verandert er niets
        // - als de gebruiker nog niet geactiveerd had (pending-marker), blijft die staan zodat
        //   de invite-activatieflow bij het klikken op de link zelf de "al goedgekeurd"-tak
        //   (block === 0) oppakt: geen approval-mail, direct inloglink na activeren.
        $db->setQuery(
            $db->getQuery(true)
                ->update('#__users')
                ->set([
                    'block = 0',
                ])
                ->where('id = ' . (int) $userId)
        )->execute();

        $this->log($userId, 'admin_approved_registration');

        // Alleen approval-mail sturen als gebruiker AL geactiveerd is
        $user = Factory::getContainer()->get(UserFactoryInterface::class)->loadUserById($userId);
        if ($user && !$this->isPendingActivation($user->activation)) {
            $this->sendApprovalEmail($userId);
        }

        return [
            'success' => true,
            'message' => Text::_('PLG_SYSTEM_SIMPLELOGIN_APPROVAL_SUCCESS'),
        ];
    }

    /**
     * Keurt een pending registratie af.
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

        // 👇 Verstuur afkeurmail VOOR het verwijderen
        $this->sendRejectionEmail($userId, $reason);

        // Verwijder gebruiker
        $db->setQuery(
            $db->getQuery(true)
                ->delete('#__users')
                ->where('id = ' . (int) $userId)
        )->execute();

        $db->setQuery(
            $db->getQuery(true)
                ->delete('#__user_usergroup_map')
                ->where('user_id = ' . (int) $userId)
        )->execute();

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
     * AJAX handler.
     */
    public function onAjaxSimplelogin(): array
    {
        $input  = Factory::getApplication()->input;
        $method = (string) $input->getString('method', '');
        $userId = $input->getInt('user_id', 0);

        try {
            if ($method === 'HashPasswords') {
                return $this->ajaxHashPasswords();
            }

            if ($method === 'GetLogRows') {
                return $this->ajaxGetLogRows();
            }

            if ($method === 'PurgeLogRows') {
                return $this->ajaxPurgeLogRows();
            }

            if ($method === 'ExportLog') {
                return $this->ajaxExportLog();
            }

            if ($method === 'ApproveUser') {
                return $this->ajaxApproveUser();
            }

            if ($method === 'RejectUser') {
                return $this->ajaxRejectUser();
            }

            return [
                'success' => false,
                'message' => Text::sprintf('PLG_SYSTEM_SIMPLELOGIN_ERR_UNKNOWN_METHOD', $method),
            ];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    // ===========================================================================
    // Toegangscontrole
    // ===========================================================================

    /**
     * Controleert CSRF-token en core.manage rechten.
     */
    private function assertPluginManageAccess(): ?array
    {
        $app = Factory::getApplication();

        if (!\Joomla\CMS\Session\Session::checkToken('get') &&
            !\Joomla\CMS\Session\Session::checkToken('post')) {
            return ['success' => false, 'message' => Text::_('PLG_SYSTEM_SIMPLELOGIN_ERR_INVALID_TOKEN')];
        }

        if (!$app->getIdentity()->authorise('core.manage', 'com_plugins')) {
            return ['success' => false, 'message' => Text::_('PLG_SYSTEM_SIMPLELOGIN_ERR_NO_PERMISSION')];
        }

        return null;
    }

    // ===========================================================================
    // Mail methodes (ALLEEN TEMPLATES GECORRIGEERD)
    // ===========================================================================

    /**
     * Verstuurd goedkeuringsmail naar gebruiker.
     */
    private function sendApprovalEmail(int $userId): void
    {
        $user = Factory::getContainer()->get(UserFactoryInterface::class)->loadUserById($userId);
        if (!$user || !$user->id || !$user->email) {
            return;
        }

        // Alleen sturen als gebruiker AL geactiveerd is
        if ($this->isPendingActivation($user->activation)) {
            return;
        }

        $loginUrl = Uri::root() . 'index.php?simplelogin=1';

        // 👈 GECORRIGEERD: Juiste templates
        [$subject, $body, $isHtml] = $this->resolveMailTemplate('mail_approval_subject', 'mail_approval_body');
        $this->mailService->sendMail(
            $user->email,
            $subject,
            $body,
            [
                '#name'  => $user->name,
                '#link'  => $loginUrl,
            ],
            $isHtml
        );
    }

    /**
     * Verstuurd afkeurmail naar gebruiker.
     */
    private function sendRejectionEmail(int $userId, string $reason = ''): void
    {
        $user = Factory::getContainer()->get(UserFactoryInterface::class)->loadUserById($userId);
        if (!$user || !$user->id || !$user->email) {
            return;
        }

        // 👈 GECORRIGEERD: Juiste templates + GEEN #link
        [$subject, $body, $isHtml] = $this->resolveMailTemplate('mail_rejection_subject', 'mail_rejection_body');
        $this->mailService->sendMail(
            $user->email,
            $subject,
            $body,
            [
                '#name'   => $user->name,
                '#reason' => $reason,
            ],
            $isHtml
        );
    }
}