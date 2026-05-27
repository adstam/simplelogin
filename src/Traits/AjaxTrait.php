<?php
/**
 * @package   Simplelogin
 * @author    Ad Stam
 * @copyright Copyright (C) 2026 Ad Stam. All rights reserved.
 * @license   GNU General Public License version 2 or later; see LICENSE.txt
 * @link      https://demo.adstam.nl
 */

namespace Adstam\Plugin\System\Simplelogin\Traits;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\User\UserFactoryInterface;

/**
 * AjaxTrait
 *
 * Verantwoordelijk voor:
 * - AJAX-methode routing (HashPasswords, GetLogRows, PurgeLogRows, ExportLog)
 * - Wachtwoorden van alle niet-admin frontend-gebruikers overschrijven
 * - Logrijen ophalen en als HTML-tabel teruggeven
 * - Logrijen verwijderen (gefilterd op type)
 * - Logexport per e-mail versturen (logtabel + Joomla logbestand, laatste 24 uur)
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
        $denied = $this->assertPluginManageAccess();
            if ($denied !== null) {
                return $denied;
        }

        $type = preg_replace(
            '/[^a-zA-Z0-9_*]/',
            '',
            (string) Factory::getApplication()->input->getString('type', '')
        );

        $rows = \Adstam\Plugin\System\Simplelogin\Helper\ReportHelper::getLogRows($type);

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
        $denied = $this->assertPluginManageAccess();
            if ($denied !== null) {
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
        $denied = $this->assertPluginManageAccess();
            if ($denied !== null) {
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
                // Joomla log-regels beginnen met een datum: "2026-05-24T..."
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

        if (!\Joomla\CMS\Session\Session::checkToken('get')) {
            return ['success' => false, 'message' => Text::_('PLG_SYSTEM_SIMPLELOGIN_ERR_INVALID_TOKEN')];
        }

        if (!$app->getIdentity()->authorise('core.manage', 'com_plugins')) {
            return ['success' => false, 'message' => Text::_('PLG_SYSTEM_SIMPLELOGIN_ERR_NO_PERMISSION')];
        }

        return null;
    }
}
