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
use Joomla\CMS\Router\Route;
use Joomla\CMS\Uri\Uri;
use Joomla\CMS\User\UserFactoryInterface;

/**
 * LoginFlowTrait
 *
 * Verantwoordelijk voor:
 * - De hoofdroutering van onAfterInitialise (via handleInitialise)
 * - De HTML-injectie van onAfterRender (via handleRender)
 * - Het verwerken van login-form POST
 * - De token GET- en POST-flow
 * - Het daadwerkelijk inloggen van een gebruiker via token
 * - Het versturen van login-links per e-mail
 *
 * showLoginForm-beleid:
 *   true  — alleen bij het startscherm (?simplelogin=1 zonder token)
 *           en na een succesvolle POST (e-mail verstuurd)
 *   false — bij alle fout- en tokensituaties: alleen de melding tonen
 *
 * Gebruikt state properties van Simplelogin:
 *   $this->statusMessage, $this->statusType, $this->autoSubmit,
 *   $this->redirectUrl, $this->showLoginForm, $this->postLogin,
 *   $this->selector, $this->validator, $this->registerFlow,
 *   $this->escapeProcessed
 *
 * Gebruikt methoden uit andere traits:
 *   LogTrait::log()
 *   SecurityTrait::isSuspiciousRequest(), isRateLimitedIp(),
 *               isRateLimitedUser(), isCooldown(), isPreflightRequest()
 *   UtilityTrait::normalizeEmail(), isValidEmail(), resolveUserIdByEmail(),
 *               generateToken(), consumeToken(), cleanup(),
 *               isAccountActivated(), setError(), finishTokenError(),
 *               redirectWithMessage(), loadPluginLanguage(),
 *               resolveStatusMessage(), loadTokenRow(),
 *               deleteUnactivatedUser()
 */
trait LoginFlowTrait
{
    // ===========================================================================
    // onAfterInitialise delegaat
    // ===========================================================================

    /**
     * Hoofdentrypoint voor alle plugin flows (frontend only).
     * Routeert naar: registratie, login-form POST, of token-flow.
     * Aangeroepen vanuit Simplelogin::onAfterInitialise().
     */
    public function handleInitialise(): void
    {
        $app = Factory::getApplication();

        if ($app->isClient('administrator')) {
            $doc = $app->getDocument();
            if ($doc) {
                $doc->addScript(
                    Uri::root(true) . '/media/plg_system_simplelogin/js/logreport.js',
                    [],
                    ['defer' => true]
                );
            }
            return;
        }

        $this->loadPluginLanguage();

        $input = $app->input;

        // -----------------------------------------------------------------------
        // Return URL opslaan bij eerste simplelogin-aanroep
        // -----------------------------------------------------------------------
        if ($input->getInt('simplelogin') === 1) {
            $session = $app->getSession();

            if (!$session->has('sl_return_url')) {
                $referrer = $_SERVER['HTTP_REFERER'] ?? '';
                $base     = Uri::root();

                if (
                    !empty($referrer)
                    && str_starts_with($referrer, $base)
                    && !str_contains($referrer, 'simplelogin')
                ) {
                    $session->set('sl_return_url', $referrer);
                } else {
                    $session->set('sl_return_url', (string) Uri::getInstance());
                }
            }
        }

        // -----------------------------------------------------------------------
        // Restore message after redirect
        // -----------------------------------------------------------------------
        $session = $app->getSession();

        if ($session->has('sl_statusMessage')) {
            $this->statusMessage = $session->get('sl_statusMessage');
            $this->statusType    = $session->get('sl_statusType', 'info');
            $this->showLoginForm = (bool) $session->get('sl_showLoginForm', false);
            $this->statusMessage = $this->resolveStatusMessage($this->statusMessage);

            $session->remove('sl_statusMessage');
            $session->remove('sl_statusType');
            $session->remove('sl_showLoginForm');
        }

        if ($session->has('sl_register_flow')) {
            $this->registerFlow = (bool) $session->get('sl_register_flow');
            $session->remove('sl_register_flow');
        }

        // -----------------------------------------------------------------------
        // Registratie flow
        // -----------------------------------------------------------------------
        $task = $input->getCmd('sl_task');

        if ($task === 'register') {
            $session = $app->getSession();

            if (!$session->has('sl_return_url')) {
                $referrer = $_SERVER['HTTP_REFERER'] ?? '';
                $base     = Uri::root();

                if (
                    !empty($referrer)
                    && str_starts_with($referrer, $base)
                    && !str_contains($referrer, 'sl_task=register')
                ) {
                    $session->set('sl_return_url', $referrer);
                }
            }

            $this->log(null, 'register_flow_triggered');
            $this->handleRegister();
            return;
        }

        // -----------------------------------------------------------------------
        // Alleen Simplelogin flows verder laten gaan
        // -----------------------------------------------------------------------
        if ($input->getInt('simplelogin') !== 1) {
            $this->log(null, 'simplelogin_missing');
            return;
        }

        $this->log(null, 'simplelogin_triggered');

        // -----------------------------------------------------------------------
        // Token input veilig ophalen
        // -----------------------------------------------------------------------
        $selector  = (string) $input->getCmd('selector', '');
        $validator = (string) $input->getString('validator', '');

        $this->selector  = $selector ?: '';
        $this->validator = $validator ?: '';

        $this->log(null, 'selector_xxx', null, $selector ?: 'NULL');
        $this->log(null, 'validator_present_' . ($validator ? 'yes' : 'no'));

        // -----------------------------------------------------------------------
        // POST FLOW
        // -----------------------------------------------------------------------
        if ($input->getMethod() === 'POST') {

            if ($selector && $validator) {
                $row = $this->loadTokenRow($selector);

                if (!$row) {
                    $this->log(null, 'token_not_found_post');
                    return;
                }

                if (($row->type ?? 'login') === 'invite') {
                    $this->handleInvitePostActivation($row, (int) $row->id, $validator);
                    return;
                }

                $this->handleTokenPost($selector, $validator);
                return;
            }

            $this->handlePost();
            return;
        }

        // -----------------------------------------------------------------------
        // GET FLOW
        // -----------------------------------------------------------------------
        if ($selector && $validator) {
            $this->handleTokenFlow($selector, $validator);
            return;
        }

        // -----------------------------------------------------------------------
        // Default: login startscherm — enige plek buiten succesvolle POST
        // waar showLoginForm bewust op true staat
        // -----------------------------------------------------------------------
        $this->showLoginScreen();
    }

    // ===========================================================================
    // onAfterRender delegaat
    // ===========================================================================

    /**
     * Injecteer overlay of register-layout in de HTML output.
     * Aangeroepen vanuit Simplelogin::onAfterRender().
     */
    public function handleRender(): void
    {
        $app   = Factory::getApplication();
        $input = $app->input;

        if ($app->isClient('administrator')) {
            return;
        }

        $isLoginFlow    = $input->getInt('simplelogin') === 1;
        $isRegisterFlow = $input->getCmd('sl_task') === 'register' || $this->registerFlow;
        $hasMessage     = !empty($this->statusMessage);

        // -----------------------------------------------------------------------
        // URL stilletjes opschonen via history.replaceState
        // -----------------------------------------------------------------------
        $dirtyParams = ['simplelogin', 'selector', 'validator', 'sl_task', 'sl_pw'];
        $currentUri  = Uri::getInstance();
        $needsClean  = false;

        foreach ($dirtyParams as $param) {
            if ($currentUri->getVar($param) !== null) {
                $needsClean = true;
                break;
            }
        }

        if ($needsClean) {
            $cleanUri = clone $currentUri;

            foreach ($dirtyParams as $param) {
                $cleanUri->delVar($param);
            }

            $cleanUrl = htmlspecialchars((string) $cleanUri, ENT_QUOTES, 'UTF-8');

            $js = "<script>history.replaceState(null, '', '{$cleanUrl}');</script>";

            $app->setBody(
                str_replace('</head>', $js . '</head>', $app->getBody())
            );
        }

        // -----------------------------------------------------------------------
        // Overlay injectie
        // -----------------------------------------------------------------------
        if (!$isLoginFlow && !$isRegisterFlow && !$hasMessage) {
            return;
        }

        $layoutName = $isRegisterFlow ? 'simplelogin.register' : 'simplelogin.overlay';

        $layout = new \Joomla\CMS\Layout\FileLayout(
            $layoutName,
            JPATH_PLUGINS . '/system/simplelogin/layouts'
        );

        $html = $layout->render([
            'statusMessage'       => $this->statusMessage,
            'statusType'          => $this->statusType,
            'autoSubmit'          => $this->autoSubmit,
            'redirectUrl'         => $this->redirectUrl,
            'showLoginForm'       => $this->showLoginForm,
            'postLogin'           => $this->postLogin,
            'selector'            => $this->selector,
            'validator'           => $this->validator,
            'allowPasswordLogin'  => (bool) $this->params->get('allow_password_login', 0),
            'passwordLoginItemId' => (int) $this->params->get('password_login_itemid', 0),
        ]);

        $app->setBody(
            str_replace('</body>', $html . '</body>', $app->getBody())
        );
    }

    // ===========================================================================
    // Flow handlers
    // ===========================================================================

    /**
     * Login-form POST handler.
     * Bij fouten: alleen melding tonen, geen invulveld.
     * Bij succes (e-mail verstuurd): melding + invulveld tonen.
     */
    private function handlePost(): void
    {
        $app = Factory::getApplication();

        if ($this->isSuspiciousRequest()) {
            $this->setError(Text::_('PLG_SYSTEM_SIMPLELOGIN_ERR_REQUEST_DENIED'));
            return;
        }

        if (!$app->getSession()->checkToken()) {
            $this->setError(Text::_('PLG_SYSTEM_SIMPLELOGIN_SESSION_EXPIRED'));
            $this->redirectWithMessage();
            return;
        }

        $email = $this->normalizeEmail(
            (string) $app->input->getString('email', '')
        );

        if (!$this->isValidEmail($email)) {
            $this->log(null, 'login_attempt_unknown', null, $email);
            $this->setError(Text::_('PLG_SYSTEM_SIMPLELOGIN_ERR_INVALID_EMAIL'));
            return;
        }

        $userId = $this->resolveUserIdByEmail($email);

        // Eerst limitering controleren
        if ($this->isRateLimitedIp()) {
            $this->log($userId, 'rate_limited_ip', null, $email);
            $this->setError(Text::_('PLG_SYSTEM_SIMPLELOGIN_ERR_RATE_LIMITED'));
            return;
        }

        if ($userId !== null && $this->isRateLimitedUser($userId)) {
            $this->log($userId, 'rate_limited_user', null, $email);
            $this->setError(Text::_('PLG_SYSTEM_SIMPLELOGIN_ERR_RATE_LIMITED'));
            return;
        }

        if ($userId !== null && $this->isCooldown($userId)) {
            $this->log($userId, 'cooldown_blocked', null, $email);
            $this->setError(Text::_('PLG_SYSTEM_SIMPLELOGIN_ERR_COOLDOWN'));
            return;
        }

        // Pas nu de daadwerkelijke attempt loggen
        $this->log(
            $userId,
            $userId !== null ? 'login_attempt_existing' : 'login_attempt_unknown',
            null,
            $email
        );

        $this->sendLoginLink($userId);

        // Succesvolle POST: invulveld wél tonen zodat gebruiker opnieuw kan aanvragen
        $this->showLoginForm = true;
        $this->redirectWithMessage();
    }

    /**
     * Token flow: bepaalt het type (invite / login) en routeert verder.
     * Vangt verlopen invite-tokens af en ruimt het bijbehorende niet-geactiveerde
     * account direct op via deleteUnactivatedUser().
     */
    private function handleTokenFlow(string $selector, string $validator): void
    {
        $db = Factory::getDbo();

        $row = $db->setQuery(
            $db->getQuery(true)
                ->select('*')
                ->from('#__simple_login')
                ->where('selector = ' . $db->quote($selector))
                ->setLimit(1)
        )->loadObject();

        if (!$row) {
            $this->log(null, 'token_invalid');
            $this->finishTokenError(Text::_('PLG_SYSTEM_SIMPLELOGIN_TOKEN_INVALID'));
            return;
        }

        $loginId  = (int) $row->id;
        $isInvite = ($row->type ?? 'login') === 'invite';

        $this->log((int) $row->user_id, 'token_hit', $loginId);

        // Scanner detectie
        if ($this->isPreflightRequest($row)) {
            $this->log((int) $row->user_id, 'scanner_detected', $loginId);
            $this->finishTokenError(Text::_('PLG_SYSTEM_SIMPLELOGIN_TOKEN_SCANNER_BLOCKED'));
            return;
        }

        // used check
        if ((int) $row->used === 1) {
            $this->log((int) $row->user_id, $isInvite ? 'invite_already_used' : 'token_reused', $loginId);
            $this->finishTokenError(Text::_(
                $isInvite
                    ? 'PLG_SYSTEM_SIMPLELOGIN_INVITE_ALREADY_USED'
                    : 'PLG_SYSTEM_SIMPLELOGIN_TOKEN_REUSED'
            ));
            return;
        }

        // expired check — bij invite: account direct opruimen zodat
        // de gebruiker meteen opnieuw kan registreren
        if (!empty($row->expires) && strtotime($row->expires) < time()) {
            $this->log((int) $row->user_id, $isInvite ? 'invite_expired' : 'token_expired', $loginId);

            if ($isInvite) {
                $this->deleteUnactivatedUser((int) $row->user_id, $loginId);
            }

            $this->finishTokenError(Text::_(
                $isInvite
                    ? 'PLG_SYSTEM_SIMPLELOGIN_INVITE_EXPIRED_REGISTER_AGAIN'
                    : 'PLG_SYSTEM_SIMPLELOGIN_TOKEN_EXPIRED'
            ));
            return;
        }

        // Routeer naar juiste flow
        if ($isInvite) {
            $this->handleInviteActivation($row, $loginId, $validator);
            return;
        }

        $this->statusMessage = Text::_('PLG_SYSTEM_SIMPLELOGIN_STATUS_LOGGING_IN');
        $this->statusType    = 'info';
        $this->postLogin     = true;
        $this->autoSubmit    = true;
    }

    /**
     * Token POST flow: valideer token en log gebruiker in.
     */
    private function handleTokenPost(string $selector, string $validator): void
    {
        $row = $this->loadTokenRow($selector);

        if (!$row) {
            $this->finishTokenError(Text::_('PLG_SYSTEM_SIMPLELOGIN_TOKEN_INVALID'));
            return;
        }

        $loginId = (int) $row->id;

        if ((int) $row->used === 1) {
            $this->finishTokenError(Text::_('PLG_SYSTEM_SIMPLELOGIN_TOKEN_REUSED'));
            return;
        }

        if (!empty($row->expires) && strtotime($row->expires) < time()) {
            $this->finishTokenError(Text::_('PLG_SYSTEM_SIMPLELOGIN_TOKEN_EXPIRED'));
            return;
        }

        if (!password_verify($validator, $row->token)) {
            $this->finishTokenError(Text::_('PLG_SYSTEM_SIMPLELOGIN_TOKEN_INVALID'));
            return;
        }

        $minAge = (int) $this->params->get('token_min_age_seconds', 5);

        if (strtotime($row->created) > time() - $minAge) {
            $this->finishTokenError(Text::_('PLG_SYSTEM_SIMPLELOGIN_TOKEN_ATTEMPT_INVALID'));
            return;
        }

        $this->handleTokenLogin($row, $loginId);
    }

    /**
     * Token login: valideer, log in, redirect.
     * Ontvangt het al opgehaalde $row-object om dubbele query te voorkomen.
     */
    private function handleTokenLogin(object $row, int $loginId): void
    {
        $app = Factory::getApplication();

        $userFactory = Factory::getContainer()->get(UserFactoryInterface::class);
        $user        = $userFactory->loadUserById((int) $row->user_id);

        if (!$user || !$user->id) {
            $this->log(null, 'login_failed', $loginId);
            $this->setError(Text::_('PLG_SYSTEM_SIMPLELOGIN_ERR_USER_NOT_FOUND'));
            return;
        }

        if (!$this->isAccountActivated($user)) {
            $this->log((int) $user->id, 'login_requires_activation', $loginId);
            $this->finishTokenError(Text::_('PLG_SYSTEM_SIMPLELOGIN_LOGIN_REQUIRES_ACTIVATION'));
            return;
        }

        if (!$this->consumeToken($loginId, (int) $row->user_id, $row->type ?? 'login')) {
            $this->log((int) $row->user_id, 'token_reused', $loginId);
            $this->finishTokenError(Text::_('PLG_SYSTEM_SIMPLELOGIN_TOKEN_REUSED'));
            return;
        }

        $session = $app->getSession();
        $session->set('user', $user);
        $app->loadIdentity($user);

        $this->log((int) $user->id, 'login_success', $loginId);

        // Landing page bepalen op basis van plugin settings
        $landingOption = (string) $this->params->get('landing_page_option', 'homepage');

        if ($landingOption === 'custom') {
            $itemId = (int) $this->params->get('landing_itemid', 0);

            $this->redirectUrl = $itemId > 0
                ? Route::_('index.php?Itemid=' . $itemId, false)
                : Route::_('index.php', false);
        } else {
            $this->redirectUrl = Route::_('index.php', false);
        }

        $this->statusMessage = Text::_('PLG_SYSTEM_SIMPLELOGIN_STATUS_LOGIN_SUCCESS');
        $this->statusType    = 'success';
        $this->autoSubmit    = true;

        $this->cleanup((int) $user->id);

        if ((int) $this->params->get('allow_password_login', 0) === 0 && !$user->authorise('core.login.admin')) {
            $this->enforcePasswordForUser((int) $user->id);
        }
    }

    // ===========================================================================
    // Mail helper
    // ===========================================================================

    /**
     * Maakt een login-token aan en verstuurt de inloglink.
     */
    private function sendLoginLink(?int $userId): void
    {
        $app = Factory::getApplication();
        $db  = Factory::getDbo();

        // Onbekende user: altijd neutrale melding, niets versturen
        if ($userId === null) {
            $this->log(null, 'user_not_found');
            $this->statusMessage = Text::_('PLG_SYSTEM_SIMPLELOGIN_LINK_SENT');
            return;
        }

        $this->log($userId, 'link_request');

        $user = Factory::getContainer()
            ->get(UserFactoryInterface::class)
            ->loadUserById($userId);

        if (!$user || !$user->id) {
            $this->statusMessage = Text::_('PLG_SYSTEM_SIMPLELOGIN_LINK_SENT');
            return;
        }

        $email = (string) $db->setQuery(
            $db->getQuery(true)
                ->select('email')
                ->from('#__users')
                ->where('id = ' . (int) $userId)
                ->setLimit(1)
        )->loadResult();

        if (!$this->isValidEmail($email)) {
            $this->statusMessage = Text::_('PLG_SYSTEM_SIMPLELOGIN_LINK_SENT');
            return;
        }

        if (!$this->isAccountActivated($user)) {
            $this->log($userId, 'login_requires_activation');
            $this->statusMessage = Text::_('PLG_SYSTEM_SIMPLELOGIN_LOGIN_REQUIRES_ACTIVATION');
            return;
        }

        [$selector, $validator, $hashedToken] = $this->generateToken();

        $expiryMinutes = (int) $this->params->get('expiry_minutes', 15);
        $expiry        = date('Y-m-d H:i:s', strtotime('+' . $expiryMinutes . ' minutes'));

        $db->setQuery(
            $db->getQuery(true)
                ->insert('#__simple_login')
                ->columns(['user_id', 'selector', 'token', 'expires', 'created', 'used', 'type'])
                ->values(implode(',', [
                    (int) $userId,
                    $db->quote($selector),
                    $db->quote($hashedToken),
                    $db->quote($expiry),
                    'NOW()',
                    0,
                    $db->quote('login'),
                ]))
        )->execute();

        $loginLink = Uri::root()
            . "index.php?simplelogin=1&selector={$selector}&validator={$validator}";

        $mailer = Factory::getMailer();
        $config = $app->getConfig();

        $mailer->setSender([$config->get('mailfrom'), $config->get('fromname')]);
        $mailer->addRecipient($email);

        $bodyTemplate = $this->params->get('mail_login_body', '');
        $expiryMin    = (int) $this->params->get('expiry_minutes', 60);

        $mailer->setSubject($this->params->get('mail_login_subject', ''));
        $mailer->setBody($this->buildMailBody($bodyTemplate, $user->name, $loginLink, $expiryMin));
        $mailer->send();

        $this->log($userId, 'link_sent');

        $this->statusMessage = Text::_('PLG_SYSTEM_SIMPLELOGIN_LINK_SENT');
    }

    // ===========================================================================
    // UI helper
    // ===========================================================================

    /**
     * Toont het login-scherm (startscherm — één van de twee gevallen
     * waarbij showLoginForm bewust op true staat).
     */
    private function showLoginScreen(): void
    {
        $this->statusMessage = Text::_('PLG_SYSTEM_SIMPLELOGIN_STATUS_LOGIN_TITLE');
        $this->statusType    = 'info';
        $this->showLoginForm = true;
    }
}
