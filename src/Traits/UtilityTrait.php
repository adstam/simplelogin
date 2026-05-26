<?php

namespace Adstam\Plugin\System\Simplelogin\Traits;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Uri\Uri;
use Joomla\CMS\User\UserFactoryInterface;

/**
 * UtilityTrait
 *
 * Verantwoordelijk voor:
 * - Token genereren, consumeren en intrekken
 * - Opschonen van throttle-, log- en tokenrecords na succesvolle login
 * - Verwijderen van niet-geactiveerde accounts bij verlopen invite-tokens
 * - E-mail validatie en normalisatie
 * - Gebruikersnaam genereren bij registratie
 * - Account activatiestatus controleren
 * - Activatiemarker aanmaken en herkennen
 * - Foutstate instellen (setError, finishTokenError, finishRegisterError)
 * - PRG redirect met sessiebehoud van statusmelding
 * - Token rij ophalen uit de database
 * - Taalbestand laden
 * - Opgeslagen statusmelding vertalen na redirect
 * - Debug-modus detectie
 * - User ID ophalen op basis van e-mailadres
 *
 * showLoginForm-beleid:
 *   true  — alleen bij het startscherm (?simplelogin=1 zonder token)
 *           en na een succesvolle POST (e-mail verstuurd)
 *   false — bij alle fout- en tokensituaties: alleen de melding tonen
 *
 * Gebruikt state properties van Simplelogin:
 *   $this->statusMessage, $this->statusType, $this->showLoginForm,
 *   $this->postLogin, $this->autoSubmit, $this->registerFlow
 *
 * Gebruikt methoden uit andere traits:
 *   LogTrait::log()
 */
trait UtilityTrait
{
    // ===========================================================================
    // Token helpers
    // ===========================================================================

    /**
     * Genereert een selector/validator/hashedToken triplet voor een nieuw token.
     *
     * @return array{0: string, 1: string, 2: string} [$selector, $validator, $hashedToken]
     */
    private function generateToken(): array
    {
        $selector    = bin2hex(random_bytes(8));
        $validator   = bin2hex(random_bytes(32));
        $hashedToken = password_hash($validator, PASSWORD_DEFAULT);

        return [$selector, $validator, $hashedToken];
    }

    /**
     * Markeer token atomisch als gebruikt.
     * Trekt daarna alle overige openstaande tokens van dezelfde gebruiker in.
     *
     * @return bool false als het token al gebruikt was (race condition)
     */
    private function consumeToken(int $loginId, int $userId, string $type): bool
    {
        $db = Factory::getDbo();

        $db->setQuery(
            $db->getQuery(true)
                ->update('#__simple_login')
                ->set('used = 1')
                ->where('id = '    . (int) $loginId)
                ->where('used = 0')
        )->execute();

        if ($db->getAffectedRows() !== 1) {
            return false;
        }

        $this->revokeUserTokens($userId, $type);

        return true;
    }

    /**
     * Trek alle overige openstaande tokens van een gebruiker in (per type).
     */
    private function revokeUserTokens(int $userId, string $type): void
    {
        $db = Factory::getDbo();

        $db->setQuery(
            $db->getQuery(true)
                ->update('#__simple_login')
                ->set('used = 1')
                ->where('user_id = ' . (int) $userId)
                ->where('type = '    . $db->quote($type))
                ->where('used = 0')
        )->execute();
    }

    /**
     * Haalt een token-rij op uit de database op basis van selector.
     */
    private function loadTokenRow(string $selector): ?object
    {
        $db = Factory::getDbo();

        $row = $db->setQuery(
            $db->getQuery(true)
                ->select('*')
                ->from('#__simple_login')
                ->where('selector = ' . $db->quote($selector))
                ->setLimit(1)
        )->loadObject();

        return $row ?: null;
    }

    // ===========================================================================
    // Cleanup
    // ===========================================================================

    /**
     * Ruimt throttle-, log- en tokenrecords op na een succesvolle login.
     * Verwijdert ook niet-geactiveerde accounts waarvan de invite-link verlopen is.
     */
    private function cleanup(int $userId): void
    {
        $db = Factory::getDbo();

        // Stap 1: verwijder niet-geactiveerde accounts met verlopen invite-tokens
        $this->cleanupExpiredRegistrations();

        // Stap 2: throttle — verwijder records ouder dan throttle_cleanup_time
        $throttleMinutes = max(1, (int) $this->params->get('throttle_cleanup_time', 60));

        $db->setQuery(
            $db->getQuery(true)
                ->delete('#__simple_login_throttle')
                ->where(
                    'created < DATE_SUB(NOW(), INTERVAL ' . $throttleMinutes . ' MINUTE)'
                )
        )->execute();

        // Stap 3: log — verwijder records ouder dan log_retention_days
        $logDays = (int) $this->params->get('log_retention_days', 30);

        if ($logDays > 0) {
            $db->setQuery(
                $db->getQuery(true)
                    ->delete('#__simple_login_log')
                    ->where(
                        'created < DATE_SUB(NOW(), INTERVAL ' . (int) $logDays . ' DAY)'
                    )
            )->execute();
        }

        // Stap 4: tokens — verwijder gebruikte en verlopen tokens
        $db->setQuery("
            DELETE FROM #__simple_login
            WHERE used = 1
            OR expires < NOW()
        ")->execute();
    }

    /**
     * Generieke cleanup: zoekt verlopen invite-tokens van niet-geactiveerde
     * accounts en verwijdert die accounts. Gebruikt een dubbele tijdscheck
     * (expires < NOW() én ouder dan invite_expiry_minutes) om tokens die
     * nét zijn verlopen over te slaan.
     * Aangeroepen vanuit cleanup() bij een succesvolle login.
     */
    private function cleanupExpiredRegistrations(): void
    {
        $db            = Factory::getDbo();
        $expiryMinutes = max(1, (int) $this->params->get('invite_expiry_minutes', 30));

        $expiredInvites = $db->setQuery(
            $db->getQuery(true)
                ->select(['id', 'user_id'])
                ->from('#__simple_login')
                ->where('type = '   . $db->quote('invite'))
                ->where('used = 0')
                ->where('expires < DATE_SUB(NOW(), INTERVAL ' . $expiryMinutes . ' MINUTE)')
        )->loadObjectList();

        if (empty($expiredInvites)) {
            return;
        }

        $userFactory = Factory::getContainer()->get(UserFactoryInterface::class);

        foreach ($expiredInvites as $invite) {
            $userId = (int) $invite->user_id;

            if (!$userId) {
                continue;
            }

            $user = $userFactory->loadUserById($userId);

            if (!$user || !$user->id || !$this->isPendingActivation($user->activation)) {
                continue;
            }

            $this->deleteUnactivatedUser($userId, (int) $invite->id);
        }
    }

    /**
     * Verwijdert een specifiek niet-geactiveerd account direct op basis van
     * een bekend user_id en login_id. Controleert eerst of het account
     * inderdaad nog pending is.
     *
     * Gebruikt door:
     * - cleanupExpiredRegistrations() voor de generieke cleanup bij login
     * - RegisterFlowTrait bij een verlopen invite-link in de registratieflow,
     *   waarbij de dubbele tijdscheck van cleanupExpiredRegistrations() te
     *   laat zou zijn om het account direct op te ruimen
     */
    private function deleteUnactivatedUser(int $userId, int $loginId): void
    {
        $userFactory = Factory::getContainer()->get(UserFactoryInterface::class);
        $user        = $userFactory->loadUserById($userId);

        if (!$user || !$user->id || !$this->isPendingActivation($user->activation)) {
            return;
        }

        $db = Factory::getDbo();

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

        $this->log($userId, 'register_cleanup_deleted', $loginId);
    }

    // ===========================================================================
    // E-mail helpers
    // ===========================================================================

    /**
     * Normaliseert een e-mailadres: lowercase en trimmen.
     */
    private function normalizeEmail(string $email): string
    {
        return strtolower(trim($email));
    }

    /**
     * Valideert een e-mailadres met PHP's ingebouwde filter.
     */
    private function isValidEmail(string $email): bool
    {
        return (bool) filter_var($email, FILTER_VALIDATE_EMAIL);
    }

    /**
     * Zoekt een user_id op basis van e-mailadres.
     * Geeft null terug als de gebruiker niet bestaat.
     */
    private function resolveUserIdByEmail(string $email): ?int
    {
        $db = Factory::getDbo();

        $id = $db->setQuery(
            $db->getQuery(true)
                ->select('id')
                ->from('#__users')
                ->where('email = ' . $db->quote($email))
        )->loadResult();

        return $id ? (int) $id : null;
    }

    // ===========================================================================
    // Gebruikersnaam generatie
    // ===========================================================================

    /**
     * Genereert een unieke gebruikersnaam op basis van de volledige naam.
     * Formaat: voornaam.achternaam.12345
     */
    private function generateUsername(string $name): string
    {
        $base = preg_replace('/[^a-z0-9]+/i', '.', strtolower($name));
        $base = trim($base, '.');

        return $base . '.' . random_int(10000, 99999);
    }

    // ===========================================================================
    // Account activatie
    // ===========================================================================

    private const PENDING_ACTIVATION_PREFIX = 'sl-pending:';

    /**
     * Maakt een activatiemarker aan voor een nieuw geregistreerd account.
     */
    private function createPendingActivation(): string
    {
        return self::PENDING_ACTIVATION_PREFIX . bin2hex(random_bytes(16));
    }

    /**
     * Controleert of de activatiemarker een Simplelogin pending-marker is.
     */
    private function isPendingActivation(?string $activation): bool
    {
        return str_starts_with(trim((string) $activation), self::PENDING_ACTIVATION_PREFIX);
    }

    /**
     * Controleert of een account een loginlink mag ontvangen en mag inloggen.
     * Blokkeert geblokkeerde accounts en accounts die nog niet geactiveerd zijn.
     */
    private function isAccountActivated(\Joomla\CMS\User\User $user): bool
    {
        if ($this->isPendingActivation($user->activation)) {
            return false;
        }

        if ((int) $user->block === 1) {
            return false;
        }

        return true;
    }

    // ===========================================================================
    // Foutstate helpers
    // ===========================================================================

    /**
     * Zet een foutmelding in de state properties.
     * showLoginForm wordt bewust NIET gezet — alleen de melding tonen.
     */
    private function setError(string $msg): void
    {
        $this->statusMessage = $msg;
        $this->statusType    = 'danger';
    }

    /**
     * Tokenfout: alleen de foutmelding tonen via PRG, geen invulveld.
     */
    private function finishTokenError(string $message): void
    {
        $this->postLogin     = false;
        $this->autoSubmit    = false;
        $this->showLoginForm = false;
        $this->setError($message);
        $this->redirectWithMessage();
    }

    /**
     * Registratiefout: alleen de foutmelding tonen via PRG, geen invulveld.
     */
    private function finishRegisterError(string $message): void
    {
        $this->registerFlow  = true;
        $this->showLoginForm = false;
        $this->postLogin     = false;
        $this->autoSubmit    = false;
        $this->setError($message);
        $this->redirectWithMessage();
    }

    // ===========================================================================
    // PRG redirect
    // ===========================================================================

    /**
     * Redirect met behoud van status via session (PRG pattern).
     * Slaat statusmelding, type en loginform-vlag op in de sessie,
     * verwijdert Simplelogin-parameters uit de return-URL en redirect.
     */
    private function redirectWithMessage(): void
    {
        $app     = Factory::getApplication();
        $session = $app->getSession();

        $this->log(null, 'redirectwithmessage');

        $session->set('sl_statusMessage', $this->statusMessage);
        $session->set('sl_statusType',    $this->statusType);
        $session->set('sl_showLoginForm', $this->showLoginForm);
        $session->set('sl_register_flow', $this->registerFlow);

        $returnUrl = $session->get('sl_return_url');

        if ($returnUrl) {
            $uri = new Uri($returnUrl);

            $this->log(null, 'raw_query_before');

            parse_str($uri->getQuery(), $queryArray);

            foreach (['simplelogin', 'selector', 'validator', 'sl_task', 'sl_pw', 'allow_pw'] as $var) {
                unset($queryArray[$var]);
            }

            $uri->setQuery($queryArray);

            $cleanUrl = (string) $uri;

            $this->log(null, 'clean_url');
            $this->log(null, 'clean_url: ' . $cleanUrl);

            $session->remove('sl_return_url');

            $app->redirect($cleanUrl);
        } else {
            $app->redirect(Route::_('index.php', false));
        }

        $app->close();
    }

    // ===========================================================================
    // Taal en debug helpers
    // ===========================================================================

    /**
     * Laadt het plugin-taalbestand (eenmalig via static flag).
     */
    private function loadPluginLanguage(): void
    {
        static $loaded = false;

        if ($loaded) {
            return;
        }

        Factory::getApplication()->getLanguage()->load(
            'plg_system_simplelogin',
            JPATH_PLUGINS . '/system/simplelogin'
        );

        $loaded = true;
    }

    /**
     * Vertaalt een opgeslagen statusmelding als het een taalsleutel is.
     * Wordt gebruikt na herstel van de sessie (post-redirect).
     */
    private function resolveStatusMessage(string $message): string
    {
        if ($message === '' || !str_starts_with($message, 'PLG_SYSTEM_SIMPLELOGIN_')) {
            return $message;
        }

        $translated = Text::_($message);

        return $translated !== $message ? $translated : $message;
    }

    /**
     * Controleert of Joomla in debug-modus staat.
     */
    private function isDebug(): bool
    {
        return (bool) Factory::getApplication()->get('debug');
    }
}
