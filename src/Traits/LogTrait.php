<?php

namespace Adstam\Plugin\System\Simplelogin\Traits;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Log\Log;

/**
 * LogTrait
 *
 * Verantwoordelijk voor:
 * - Centrale logmethode voor audit- en throttle-acties
 * - Wegschrijven naar #__simple_login_log
 * - Wegschrijven naar #__simple_login_throttle (alleen voor throttle-waardige statussen)
 * - Status-definitietabel (type, debugonly, throttle per status-sleutel)
 * - Hulpfuncties voor IP-adres en user-agent
 * - E-mailadres hashing voor privacy in de logtabel
 * - Ophalen van username op basis van user_id
 *
 * Gebruikt state properties van Simplelogin:
 *   (geen — alle methoden zijn stateless hulpfuncties)
 *
 * Gebruikt methoden uit andere traits:
 *   (geen — deze trait heeft geen afhankelijkheden op andere traits)
 */
trait LogTrait
{
    // ===========================================================================
    // Centrale logmethode
    // ===========================================================================

    /**
     * Centrale logmethode voor zowel audit/throttle-acties als debug-only diagnostiek.
     *
     * @param int|null    $userId     Joomla user ID, of null indien onbekend
     * @param string      $status     Statussleutel uit getStatusDefinition()
     * @param int|null    $loginId    ID uit #__simple_login, of null indien niet van toepassing
     * @param string|null $identifier E-mailadres of gebruikersnaam voor logging
     */
    private function log(
        ?int $userId,
        string $status,
        ?int $loginId = null,
        ?string $identifier = null
    ): void {
        $definition = $this->getStatusDefinition($status);

        $debugOnly  = $definition['debugonly'];
        $toThrottle = $definition['throttle'];
        $type       = $definition['type'];

        if ($debugOnly && !$this->isDebug()) {
            return;
        }

        $db = Factory::getDbo();

        $resolvedIdentifier = $identifier
            ?? ($userId !== null ? $this->loadUsername($userId) : null);

        $emailHash = null;

        if ($identifier && filter_var($identifier, FILTER_VALIDATE_EMAIL)) {
            $emailHash = $this->hashEmail($identifier);
        }

        $created   = date('Y-m-d H:i:s');
        $packedIp  = $this->getPackedIp();
        $userAgent = $this->getUserAgent();

        // ------------------------------------------------------------
        // LOG TABLE
        // ------------------------------------------------------------
        try {
            $db->setQuery(
                $db->getQuery(true)
                    ->insert('#__simple_login_log')
                    ->columns([
                        'user_id',
                        'username',
                        'ip',
                        'email_hash',
                        'user_agent',
                        'created',
                        'status',
                        'type',
                        'login_id',
                    ])
                    ->values(implode(',', [
                        $userId !== null ? (int) $userId : 'NULL',
                        $resolvedIdentifier !== null ? $db->quote($resolvedIdentifier) : 'NULL',
                        'UNHEX(' . $db->quote($packedIp) . ')',
                        $emailHash !== null ? $db->quote($emailHash) : 'NULL',
                        $db->quote($userAgent),
                        $db->quote($created),
                        $db->quote($status),
                        $db->quote($type),
                        $loginId !== null ? (int) $loginId : 'NULL',
                    ]))
            )->execute();
        } catch (\Exception $e) {
            Log::add(
                'Simplelogin log insert failed: ' . $e->getMessage(),
                Log::ERROR,
                ['simplelogin']
            );
        }

        // ------------------------------------------------------------
        // THROTTLE TABLE
        // ------------------------------------------------------------
        if (!$toThrottle) {
            return;
        }

        try {
            $db->setQuery(
                $db->getQuery(true)
                    ->insert('#__simple_login_throttle')
                    ->columns([
                        'user_id',
                        'username',
                        'ip',
                        'created',
                        'status',
                        'login_id',
                    ])
                    ->values(implode(',', [
                        $userId !== null ? (int) $userId : 'NULL',
                        $resolvedIdentifier !== null ? $db->quote($resolvedIdentifier) : 'NULL',
                        'UNHEX(' . $db->quote($packedIp) . ')',
                        $db->quote($created),
                        $db->quote($status),
                        $loginId !== null ? (int) $loginId : 'NULL',
                    ]))
            )->execute();
        } catch (\Exception $e) {
            Log::add(
                'Simplelogin throttle insert failed: ' . $e->getMessage(),
                Log::ERROR,
                ['simplelogin']
            );
        }
    }

    // ===========================================================================
    // Status definitietabel
    // ===========================================================================

    /**
     * Geeft de definitie van een statussleutel terug:
     * - type      : logcategorie (voor de type-kolom in de logtabel)
     * - debugonly : true = alleen loggen als Joomla debug-modus aan staat
     * - throttle  : true = ook wegschrijven naar de throttle-tabel
     */
    private function getStatusDefinition(string $status): array
    {
        $map = [
            // AccountEvent
            'password_updated'                   => ['type' => 'AccountEvent',      'debugonly' => false, 'throttle' => true],
            'register_cleanup_deleted'           => ['type' => 'AccountEvent',      'debugonly' => false, 'throttle' => false],
            'register_existing_email'            => ['type' => 'AccountEvent',      'debugonly' => false, 'throttle' => false],
            'register_success'                   => ['type' => 'AccountEvent',      'debugonly' => false, 'throttle' => false],
            'user_not_found'                     => ['type' => 'AccountEvent',      'debugonly' => false, 'throttle' => true],

            // DebugDiagnostics
            'invite_email_not_found'             => ['type' => 'DebugDiagnostics',  'debugonly' => true,  'throttle' => false],
            'post_without_selector'              => ['type' => 'DebugDiagnostics',  'debugonly' => true,  'throttle' => false],
            'token_row_missing'                  => ['type' => 'DebugDiagnostics',  'debugonly' => true,  'throttle' => false],
            'unexpected_flow_state'              => ['type' => 'DebugDiagnostics',  'debugonly' => true,  'throttle' => false],

            // DebugFlowTrace
            'core_login_allowed_escape'          => ['type' => 'DebugFlowTrace',    'debugonly' => true,  'throttle' => false],
            'core_login_allowed_escape_trigger'  => ['type' => 'DebugFlowTrace',    'debugonly' => true,  'throttle' => false],
            'core_login_blocked'                 => ['type' => 'DebugFlowTrace',    'debugonly' => true,  'throttle' => false],
            'core_logout_allowed'                => ['type' => 'DebugFlowTrace',    'debugonly' => true,  'throttle' => false],
            'core_register_allowed'              => ['type' => 'DebugFlowTrace',    'debugonly' => true,  'throttle' => false],
            'core_register_blocked'              => ['type' => 'DebugFlowTrace',    'debugonly' => true,  'throttle' => false],
            'redirectwithmessage'                => ['type' => 'DebugFlowTrace',    'debugonly' => true,  'throttle' => false],
            'register_flow_triggered'            => ['type' => 'DebugFlowTrace',    'debugonly' => true,  'throttle' => false],
            'simplelogin_missing'                => ['type' => 'DebugFlowTrace',    'debugonly' => true,  'throttle' => false],
            'simplelogin_triggered'              => ['type' => 'DebugFlowTrace',    'debugonly' => true,  'throttle' => false],

            // DebugRequestTrace
            'clean_url'                          => ['type' => 'DebugRequestTrace', 'debugonly' => true,  'throttle' => false],
            'raw_query_before'                   => ['type' => 'DebugRequestTrace', 'debugonly' => true,  'throttle' => false],
            'selector_xxx'                       => ['type' => 'DebugRequestTrace', 'debugonly' => true,  'throttle' => false],
            'validator_present_yes'              => ['type' => 'DebugRequestTrace', 'debugonly' => true,  'throttle' => false],
            'validator_present_no'               => ['type' => 'DebugRequestTrace', 'debugonly' => true,  'throttle' => false],

            // InviteFlow
            'invite_activated'                   => ['type' => 'InviteFlow',        'debugonly' => false, 'throttle' => false],
            'invite_already_used'                => ['type' => 'InviteFlow',        'debugonly' => false, 'throttle' => false],
            'invite_expired'                     => ['type' => 'InviteFlow',        'debugonly' => false, 'throttle' => false],
            'invite_invalid'                     => ['type' => 'InviteFlow',        'debugonly' => false, 'throttle' => false],
            'invite_sent'                        => ['type' => 'InviteFlow',        'debugonly' => false, 'throttle' => false],

            // LoginFlow
            'link_request'                       => ['type' => 'LoginFlow',         'debugonly' => false, 'throttle' => false],
            'link_sent'                          => ['type' => 'LoginFlow',         'debugonly' => false, 'throttle' => true],
            'login_attempt_existing'             => ['type' => 'LoginFlow',         'debugonly' => false, 'throttle' => true],
            'login_attempt_unknown'              => ['type' => 'LoginFlow',         'debugonly' => false, 'throttle' => true],
            'login_failed'                       => ['type' => 'LoginFlow',         'debugonly' => false, 'throttle' => false],
            'login_success'                      => ['type' => 'LoginFlow',         'debugonly' => false, 'throttle' => false],
            'login_requires_activation'          => ['type' => 'LoginFlow',         'debugonly' => false, 'throttle' => true],
            'token_expired'                      => ['type' => 'LoginFlow',         'debugonly' => false, 'throttle' => false],
            'token_hit'                          => ['type' => 'LoginFlow',         'debugonly' => false, 'throttle' => true],
            'token_invalid'                      => ['type' => 'LoginFlow',         'debugonly' => false, 'throttle' => false],
            'token_reused'                       => ['type' => 'LoginFlow',         'debugonly' => false, 'throttle' => false],

            // SecurityIncident
            'cooldown_blocked'                   => ['type' => 'SecurityIncident',  'debugonly' => false, 'throttle' => true],
            'rate_limited_ip'                    => ['type' => 'SecurityIncident',  'debugonly' => false, 'throttle' => true],
            'rate_limited_user'                  => ['type' => 'SecurityIncident',  'debugonly' => false, 'throttle' => true],
            'scanner_detected'                   => ['type' => 'SecurityIncident',  'debugonly' => false, 'throttle' => false],
            'suspicious_request'                 => ['type' => 'SecurityIncident',  'debugonly' => false, 'throttle' => false],
        ];

        return $map[$status] ?? [
            'type'      => 'Unknown',
            'debugonly' => true,
            'throttle'  => false,
        ];
    }

    // ===========================================================================
    // Hulpfuncties
    // ===========================================================================

    /**
     * Geeft het ruwe IP-adres van de huidige request terug.
     */
    private function getIp(): string
    {
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }

    /**
     * Geeft de user-agent string van de huidige request terug.
     */
    private function getUserAgent(): string
    {
        return $_SERVER['HTTP_USER_AGENT'] ?? '';
    }

    /**
     * Geeft het IP-adres terug als hex-string geschikt voor UNHEX() in MySQL.
     * Werkt voor zowel IPv4 als IPv6.
     */
    private function getPackedIp(): string
    {
        return bin2hex(inet_pton($this->getIp()) ?: inet_pton('0.0.0.0'));
    }

    /**
     * Hasht een e-mailadres met SHA-256 voor privacy-vriendelijke opslag.
     */
    private function hashEmail(?string $email): ?string
    {
        if (empty($email)) {
            return null;
        }

        return hash('sha256', strtolower(trim($email)));
    }

    /**
     * Laadt de gebruikersnaam op basis van een user_id.
     * Wordt gebruikt als fallback identifier in de logtabel.
     */
    private function loadUsername(int $userId): ?string
    {
        $db = Factory::getDbo();

        return $db->setQuery(
            $db->getQuery(true)
                ->select('username')
                ->from('#__users')
                ->where('id = ' . (int) $userId)
        )->loadResult() ?: null;
    }
}
