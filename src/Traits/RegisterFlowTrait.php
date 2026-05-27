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
use Joomla\CMS\Uri\Uri;
use Joomla\CMS\User\UserFactoryInterface;

/**
 * RegisterFlowTrait
 *
 * Verantwoordelijk voor:
 * - Registratie van nieuwe gebruikers (GET tonen + POST verwerken)
 * - Invite-activatie via GET (alleen UI, geen DB-wijzigingen)
 * - Invite-activatie via POST (token consumeren, account activeren)
 * - Versturen van invite-links per e-mail
 * - Opbouwen van mailbodies via #hashtag-vervanging
 *
 * showLoginForm-beleid:
 *   false bij alle fout- en tokensituaties — alleen de melding tonen
 *
 * Gebruikt state properties van Simplelogin:
 *   $this->statusMessage, $this->statusType, $this->autoSubmit,
 *   $this->showLoginForm, $this->postLogin, $this->registerFlow
 *
 * Gebruikt methoden uit andere traits:
 *   LogTrait::log()
 *   UtilityTrait::normalizeEmail(), isValidEmail(), generateUsername(),
 *               generateToken(), consumeToken(), isAccountActivated(),
 *               isPendingActivation(), createPendingActivation(),
 *               deleteUnactivatedUser(), setError(),
 *               finishRegisterError(), finishTokenError(), redirectWithMessage()
 */
trait RegisterFlowTrait
{
    // ===========================================================================
    // Registratie flow
    // ===========================================================================

    /**
     * Registratie flow.
     * GET  → toon het registratieformulier.
     * POST → valideer e-mail, maak account aan, stuur invite.
     */
    private function handleRegister(): void
    {
        $app   = Factory::getApplication();
        $input = $app->input;

        if ($input->getMethod() !== 'POST') {
            $this->showLoginForm = true;
            return;
        }

        if (!$app->getSession()->checkToken()) {
            $this->finishRegisterError(Text::_('PLG_SYSTEM_SIMPLELOGIN_REGISTER_SESSION_EXPIRED'));
            return;
        }

        $name  = trim((string) $input->getString('name', ''));
        $email = $this->normalizeEmail(
            (string) $input->getString('email', '')
        );

        if (!$this->isValidEmail($email) || empty($name)) {
            $this->finishRegisterError(Text::_('PLG_SYSTEM_SIMPLELOGIN_REGISTER_INVALID_INPUT'));
            return;
        }

        $db = Factory::getDbo();

        $exists = $db->setQuery(
            $db->getQuery(true)
                ->select('id')
                ->from('#__users')
                ->where('email = ' . $db->quote($email))
        )->loadResult();

        if ($exists) {
            $this->registerFlow  = false;
            $this->statusMessage = Text::_('PLG_SYSTEM_SIMPLELOGIN_REGISTER_EXISTS');
            $this->statusType    = 'success';
            $this->redirectWithMessage();
            return;
        }

        $config       = $app->getConfig();
        $defaultGroup = (int) $config->get('new_usertype', 2);

        $user = new \Joomla\CMS\User\User();
        $user->set('name',       $name);
        $user->set('username',   $this->generateUsername($name));
        $user->set('email',      strtolower(trim($email)));
        $user->set('block',      0);
        $user->set('activation', $this->createPendingActivation());
        $user->set('password',   bin2hex(random_bytes(32)));
        $user->set('groups',     [$defaultGroup]);

        // Marker zetten VOOR save, zodat onUserAfterSave hem direct ziet
        $app->getSession()->set('sl_invite_pending', true);

        if (!$user->save()) {
            $app->getSession()->set('sl_invite_pending', false);
            $this->finishRegisterError(Text::_('PLG_SYSTEM_SIMPLELOGIN_REGISTER_FAILED'));
            return;
        }

        // Marker direct opruimen na succesvolle save
        $app->getSession()->set('sl_invite_pending', false);

        $this->registerFlow  = false;
        $this->statusMessage = Text::_('PLG_SYSTEM_SIMPLELOGIN_REGISTER_SUCCESS');
        $this->statusType    = 'success';
        $this->redirectWithMessage();
    }

    // ===========================================================================
    // Invite activatie
    // ===========================================================================

    /**
     * Invite-activatie GET: alleen UI voorbereiden, geen state changes.
     * Cruciaal tegen Outlook SafeLinks en andere link-scanners.
     *
     * Bij een verlopen link wordt het account direct verwijderd via
     * deleteUnactivatedUser() — niet via de generieke cleanupExpiredRegistrations()
     * omdat die een dubbele tijdscheck gebruikt die net-verlopen tokens overslaat.
     */
    private function handleInviteActivation(object $row, int $loginId, string $validator): void
    {
				if ((int) $row->used === 1) {
            $this->log((int) $row->user_id, 'invite_already_used', $loginId);
            $this->finishTokenError(Text::_('PLG_SYSTEM_SIMPLELOGIN_INVITE_ALREADY_USED'));
            return;
        }

        if (!empty($row->expires) && strtotime($row->expires) < time()) {
            $this->log((int) $row->user_id, 'invite_expired', $loginId);
            $this->deleteUnactivatedUser((int) $row->user_id, $loginId);
            $this->finishTokenError(Text::_('PLG_SYSTEM_SIMPLELOGIN_INVITE_EXPIRED_REGISTER_AGAIN'));
            return;
        }

        if (!password_verify($validator, $row->token)) {
            $this->log((int) $row->user_id, 'invite_invalid', $loginId);
            $this->finishTokenError(Text::_('PLG_SYSTEM_SIMPLELOGIN_INVITE_INVALID'));
            return;
        }

        // Alleen UI + POST trigger — geen DB-wijzigingen hier
        $this->statusMessage = Text::_('PLG_SYSTEM_SIMPLELOGIN_STATUS_ACTIVATING');
        $this->statusType    = 'info';
        $this->postLogin     = true;
        $this->autoSubmit    = true;
    }

    /**
     * Invite-activatie POST: token consumeren, account activeren, login-link sturen.
     *
     * Bij een verlopen link wordt het account direct verwijderd via
     * deleteUnactivatedUser() zodat de gebruiker direct opnieuw kan registreren.
     */
    private function handleInvitePostActivation(object $row, int $loginId, string $validator): void
    {
        
				$app = Factory::getApplication();

        if (!$app->getSession()->checkToken()) {
            $this->setError(Text::_('PLG_SYSTEM_SIMPLELOGIN_SESSION_EXPIRED'));
            $this->redirectWithMessage();
            return;
        }

        $db = Factory::getDbo();

        if ((int) $row->used === 1) {
            $this->log((int) $row->user_id, 'invite_post_already_used', $loginId);
            $this->finishTokenError(Text::_('PLG_SYSTEM_SIMPLELOGIN_INVITE_ALREADY_USED'));
            return;
        }

        if (!password_verify($validator, $row->token)) {
            $this->log((int) $row->user_id, 'invite_post_invalid', $loginId);
            $this->finishTokenError(Text::_('PLG_SYSTEM_SIMPLELOGIN_INVITE_INVALID'));
            return;
        }

        if (!empty($row->expires) && strtotime($row->expires) < time()) {
            $this->log((int) $row->user_id, 'invite_post_expired', $loginId);
            $this->deleteUnactivatedUser((int) $row->user_id, $loginId);
            $this->finishTokenError(Text::_('PLG_SYSTEM_SIMPLELOGIN_INVITE_EXPIRED_REGISTER_AGAIN'));
            return;
        }

        if (!$this->consumeToken($loginId, (int) $row->user_id, 'invite')) {
            $this->log((int) $row->user_id, 'invite_post_already_used', $loginId);
            $this->finishTokenError(Text::_('PLG_SYSTEM_SIMPLELOGIN_INVITE_NO_LONGER_VALID'));
            return;
        }

        // Account activeren: ingeschakeld en activatiemarker wissen
        $db->setQuery(
            $db->getQuery(true)
                ->update($db->quoteName('#__users'))
                ->set([
                    $db->quoteName('block')      . ' = 0',
                    $db->quoteName('activation') . ' = ' . $db->quote(''),
                ])
                ->where($db->quoteName('id') . ' = ' . (int) $row->user_id)
        )->execute();

        $this->log((int) $row->user_id, 'invite_activated', $loginId);

        // Stuur direct een login-link
        $this->sendLoginLink((int) $row->user_id);

        $this->statusMessage = Text::_('PLG_SYSTEM_SIMPLELOGIN_INVITE_ACTIVATED');
        $this->statusType    = 'success';
        $this->redirectWithMessage();
    }

    // ===========================================================================
    // Mail helpers
    // ===========================================================================

    /**
     * Maakt een invite-token aan en verstuurt de activatielink.
     * Aangeroepen vanuit Simplelogin::onUserAfterSave().
     */
    private function sendInviteLink(int $userId): void
    {
        $db  = Factory::getDbo();
        $app = Factory::getApplication();

        $email = $db->setQuery(
            $db->getQuery(true)
                ->select('email')
                ->from('#__users')
                ->where('id = ' . $userId)
        )->loadResult();

        if (!$email) {
            $this->log($userId, 'invite_email_not_found');
            return;
        }

        $user = Factory::getContainer()
            ->get(UserFactoryInterface::class)
            ->loadUserById($userId);

        [$selector, $validator, $hashedToken] = $this->generateToken();

        $expiryMinutes = max(1, (int) $this->params->get('invite_expiry_minutes', 30));
        $expiry        = date('Y-m-d H:i:s', strtotime('+' . $expiryMinutes . ' minutes'));

        $db->setQuery(
            $db->getQuery(true)
                ->insert('#__simple_login')
                ->columns(['user_id', 'selector', 'token', 'expires', 'created', 'used', 'type'])
                ->values(implode(',', [
                    $userId,
                    $db->quote($selector),
                    $db->quote($hashedToken),
                    $db->quote($expiry),
                    'NOW()',
                    0,
                    $db->quote('invite'),
                ]))
        )->execute();

        $loginId    = (int) $db->insertid();
        $inviteLink = Uri::root() . "index.php?simplelogin=1&selector={$selector}&validator={$validator}";

        $config = $app->getConfig();
        $mailer = Factory::getMailer();
        $mailer->setSender([$config->get('mailfrom'), $config->get('fromname')]);
        $mailer->addRecipient($email);

        $bodyTemplate = $this->params->get('mail_invite_body', '');
        $expiryMin    = (int) $this->params->get('invite_expiry_minutes', 60);

        $mailer->setSubject($this->params->get('mail_invite_subject', ''));
        $mailer->setBody($this->buildMailBody($bodyTemplate, $user->name, $inviteLink, $expiryMin));
        $mailer->send();

        $this->log($userId, 'invite_sent', $loginId);
    }

    /**
     * Vervangt #hashtags in de mailbody met de geldende waarden.
     * Wordt gebruikt door zowel sendInviteLink() als sendLoginLink().
     */
    private function buildMailBody(string $body, string $name, string $link, int $expiryMinutes): string
    {
        $replacements = [
            '#name'   => htmlspecialchars($name, ENT_QUOTES, 'UTF-8'),
            '#link'   => $link,
            '#expiry' => (string) $expiryMinutes,
        ];

        $result = str_replace(
            array_keys($replacements),
            array_values($replacements),
            $body
        );

        // Als #link niet in de body stond, link onderaan toevoegen
        if (!str_contains($body, '#link')) {
            $result .= "\n\n" . $link;
        }

        return $result;
    }
}
