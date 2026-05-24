<?php

namespace Adstam\Plugin\System\Simplelogin\Traits;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\User\UserFactoryInterface;

/**
 * SecurityTrait
 *
 * Verantwoordelijk voor:
 * - Rate limiting op IP-niveau
 * - Rate limiting op user-niveau
 * - Cooldown tussen opeenvolgende login-pogingen
 * - Detectie van verdachte user-agents (bots, scanners, CLI tools)
 * - Detectie van scanner-preflight op token-links
 * - Overschrijven van wachtwoorden zodat password-login onmogelijk is
 *
 * Gebruikt state properties van Simplelogin:
 *   (geen directe state — alle methoden zijn puur controle/actie)
 *
 * Gebruikt methoden uit andere traits:
 *   LogTrait::log()
 *   LogTrait::getPackedIp()
 *   LogTrait::getUserAgent()
 */
trait SecurityTrait
{
    // ===========================================================================
    // Wachtwoord enforcement
    // ===========================================================================

    /**
     * Overschrijft het wachtwoord met een willekeurige hash
     * zodat password-login onmogelijk is.
     */
    private function enforcePasswordForUser(int $userId): void
    {
        $userFactory = Factory::getContainer()->get(UserFactoryInterface::class);
        $user        = $userFactory->loadUserById($userId);

        if (!$user || !$user->id) {
            return;
        }

        $db = Factory::getDbo();

        $db->setQuery(
            $db->getQuery(true)
                ->update('#__users')
                ->set(
                    'password = ' .
                    $db->quote(password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT))
                )
                ->where('id = ' . (int) $userId)
        )->execute();

        $this->log($userId, 'password_updated');
    }

    // ===========================================================================
    // Rate limiting en cooldown
    // ===========================================================================

    /**
     * Controleert of er recent al een request is binnengekomen
     * voor dit IP of deze gebruiker (cooldown tussen pogingen).
     */
    private function isCooldown(?int $userId): bool
    {
        $db       = Factory::getDbo();
        $cooldown = (int) $this->params->get('request_cooldown_seconds', 30);

        $window = date('Y-m-d H:i:s', strtotime("-{$cooldown} seconds"));

        $validStatuses  = ['login_attempt_existing', 'login_attempt_unknown'];
        $quotedStatuses = array_map([$db, 'quote'], $validStatuses);

        $query = $db->getQuery(true)
            ->select('COUNT(*)')
            ->from('#__simple_login_throttle')
            ->where('created > ' . $db->quote($window))
            ->where('status IN (' . implode(',', $quotedStatuses) . ')')
            ->extendWhere(
                'AND',
                [
                    'ip = ' . $db->quote($this->getPackedIp()),
                    $userId !== null
                        ? 'user_id = ' . (int) $userId
                        : '1=0',
                ],
                'OR'
            );

        return (int) $db->setQuery($query)->loadResult() > 0;
    }

    /**
     * Rate limiting op IP-niveau.
     * Blokkeert als het aantal pogingen vanuit dit IP de limiet overschrijdt.
     */
    private function isRateLimitedIp(): bool
    {
        $db     = Factory::getDbo();
        $limit  = (int) $this->params->get('rate_limit_ip_max', 10);
        $window = (int) $this->params->get('rate_limit_ip_window', 5);

        $since = date('Y-m-d H:i:s', strtotime("-{$window} minutes"));

        $validStatuses  = ['login_attempt_existing', 'login_attempt_unknown'];
        $quotedStatuses = array_map([$db, 'quote'], $validStatuses);

        $count = (int) $db->setQuery(
            $db->getQuery(true)
                ->select('COUNT(*)')
                ->from('#__simple_login_throttle')
                ->where('ip = ' . $db->quote($this->getPackedIp()))
                ->where('created > ' . $db->quote($since))
                ->where('status IN (' . implode(',', $quotedStatuses) . ')')
        )->loadResult();

        return $count >= $limit;
    }

    /**
     * Rate limiting op user-niveau.
     * Blokkeert als het aantal pogingen voor deze gebruiker de limiet overschrijdt.
     */
    private function isRateLimitedUser(int $userId): bool
    {
        $db     = Factory::getDbo();
        $limit  = (int) $this->params->get('rate_limit_user_max', 5);
        $window = (int) $this->params->get('rate_limit_user_window', 10);

        $since = date('Y-m-d H:i:s', strtotime("-{$window} minutes"));

        $count = (int) $db->setQuery(
            $db->getQuery(true)
                ->select('COUNT(*)')
                ->from('#__simple_login_throttle')
                ->where('user_id = ' . (int) $userId)
                ->where('created > ' . $db->quote($since))
                ->where(
                    'status IN (' .
                    $db->quote('login_attempt_existing') . ',' .
                    $db->quote('link_sent') .
                    ')'
                )
        )->loadResult();

        return $count >= $limit;
    }

    // ===========================================================================
    // Verdachte requests en scanner detectie
    // ===========================================================================

    /**
     * Detecteert verdachte user-agents (bots, scanners, CLI tools).
     */
    private function isSuspiciousRequest(?int $userId = null, ?int $loginId = null): bool
    {
        $ua         = strtolower($this->getUserAgent());
        $signatures = [
            'curl', 'wget', 'python', 'bot', 'spider',
            'scanner', 'headless', 'phantom', 'httpclient', 'libwww',
        ];

        foreach ($signatures as $sig) {
            if (str_contains($ua, $sig)) {
                $this->log($userId, 'suspicious_request', $loginId);
                return true;
            }
        }

        return false;
    }

    /**
     * Detecteert scanner-preflight op basis van throttle-frequentie en user-agent.
     * Roept detectScannerPreflight() aan en vertaalt het resultaat naar een bool.
     */
    private function isPreflightRequest(object $row): bool
    {
        $type = $this->detectScannerPreflight($row);

        return $type === 'hard' || $type === 'soft';
    }

    /**
     * Bepaalt het type scanner-preflight: 'hard', 'soft', of null.
     *
     * hard — verdachte user-agent zonder token-rij
     * soft — te hoge token-hit frequentie of verdachte user-agent met token-rij
     * null — geen verdacht gedrag gedetecteerd
     */
    private function detectScannerPreflight(?object $row): ?string
    {
        $loginId = $row ? (int) $row->id      : null;
        $userId  = $row ? (int) $row->user_id : null;

        if (!$row) {
            return $this->isSuspiciousRequest($userId, $loginId) ? 'hard' : null;
        }

        $db     = Factory::getDbo();
        $window = date('Y-m-d H:i:s', strtotime('-2 seconds'));

        $count = (int) $db->setQuery(
            $db->getQuery(true)
                ->select('COUNT(*)')
                ->from('#__simple_login_throttle')
                ->where('login_id = ' . (int) $row->id)
                ->where('created > '  . $db->quote($window))
                ->where('status = '   . $db->quote('token_hit'))
        )->loadResult();

        if ($count > 5) {
            $this->log($userId, 'suspicious_request', $loginId);
            return 'soft';
        }

        if ($this->isSuspiciousRequest($userId, $loginId)) {
            return 'soft';
        }

        return null;
    }
}