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
use Joomla\CMS\Uri\Uri;
use Joomla\CMS\User\UserFactoryInterface;
use Joomla\Registry\Registry;

/**
 * RegisterFlowTrait
 *
 * Verantwoordelijk voor:
 * - Registratie van nieuwe gebruikers (GET tonen + POST verwerken)
 * - Invite-activatie via GET (alleen UI, geen DB-wijzigingen)
 * - Invite-activatie via POST (token consumeren, account activeren)
 * - Versturen van invite-links per e-mail
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

        // Controleer of e-mail al bestaat
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

        $defaultGroup = (int) $app->get('new_usertype', 2);

        // Maak nieuwe gebruiker aan
        $user = new \Joomla\CMS\User\User();
        $user->set('name',       $name);
        $user->set('username',   $this->generateUsername($name));
        $user->set('email',      strtolower(trim($email)));
        $requireAdminApproval = (int) $this->params->get('require_admin_approval', 0);
        $user->set('block',      $requireAdminApproval === 1 ? 1 : 0);
        $user->set('activation', $this->createPendingActivation());
        $user->set('password',   bin2hex(random_bytes(32)));
        $user->set('groups',     [$defaultGroup]);

        // 👈 CRITIEK: SLA GEBRUIKER OP IN DATABASE
        if (!$user->save()) {
            $this->log(0, 'register_save_failed');
            $this->finishRegisterError(Text::_('PLG_SYSTEM_SIMPLELOGIN_REGISTER_FAILED'));
            return;
        }

        // Stuur invite-link naar gebruiker
        $this->sendInviteLink($user->id);

        // Stuur admin notificatie (alleen als ingeschakeld)
        if ((int) $this->params->get('notify_admin_registration', 0) === 1) {
            $sitename = $app->get('sitename');
            [$subject, $body, $isHtml] = $this->resolveMailTemplate('mail_admin_subject', 'mail_admin_body');
            $this->mailService->sendMail(
                $app->get('mailfrom'),
                $subject,
                $body,
                [
                    '#name'     => $user->name,
                    '#email'    => $user->email,
                    '#sitename' => $sitename,
                ],
                $isHtml
			);
			
		
        }

		foreach ($this->mailService->getLastImageErrors() as $error) {
			$status = ($error['status'] === 'not_found') ? 'image_not_found' : 'image_too_large';
			$this->log(null, $status, null, $error['url'] . ' | ' . $error['message']);
		}
		
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
     */
    protected function handleInviteActivation(object $row, int $loginId, string $validator): void
    {
        // 👇 NIEUW: Check of gebruiker nog bestaat
        $db = Factory::getDbo();
        $userExists = $db->setQuery(
            $db->getQuery(true)
                ->select('1')
                ->from('#__users')
                ->where('id = ' . (int) $row->user_id)
        )->loadResult();

        if (!$userExists) {
            $this->log((int) $row->user_id, 'invite_user_not_found', $loginId);
            $this->finishTokenError(Text::_('PLG_SYSTEM_SIMPLELOGIN_INVITE_USER_NOT_FOUND'));
            return;
        }

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

        // Check of gebruiker AL goedgekeurd is
        $user = $db->setQuery(
            $db->getQuery(true)
                ->select('block')
                ->from('#__users')
                ->where('id = ' . (int) $row->user_id)
        )->loadObject();

        if ($user && (int) $user->block === 0) {
            // Gebruiker is AL goedgekeurd → start login flow
            $this->log((int) $row->user_id, 'invite_already_approved', $loginId);
            $this->statusMessage = Text::_('PLG_SYSTEM_SIMPLELOGIN_INVITE_ALREADY_APPROVED');
            $this->statusType    = 'success';
            $this->postLogin     = true;
            $this->autoSubmit    = true;
            return;
        }

        // Check if admin approval is required
        $requireAdminApproval = (int) $this->params->get('require_admin_approval', 0);

        if ($requireAdminApproval === 1) {
            // Consume token to prevent reuse
            $this->consumeToken($loginId, (int) $row->user_id, 'invite');
            // Clear activation marker (email IS confirmed)
            $db->setQuery(
                $db->getQuery(true)
                    ->update($db->quoteName('#__users'))
                    ->set($db->quoteName('activation') . ' = ' . $db->quote(''))
                    ->where($db->quoteName('id') . ' = ' . (int) $row->user_id)
            )->execute();

            $this->log((int) $row->user_id, 'invite_pending_approval', $loginId);
            $this->statusMessage = Text::_('PLG_SYSTEM_SIMPLELOGIN_REGISTER_PENDING_APPROVAL');
            $this->statusType    = 'info';
            $this->postLogin     = false;
            $this->autoSubmit    = false;
            $this->showLoginForm = false;
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
     */
    protected function handleInvitePostActivation(object $row, int $loginId, string $validator): void
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

        // 👇 NIEUW: Check of gebruiker nog bestaat
        $userExists = $db->setQuery(
            $db->getQuery(true)
                ->select('1')
                ->from('#__users')
                ->where('id = ' . (int) $row->user_id)
        )->loadResult();

        if (!$userExists) {
            $this->log((int) $row->user_id, 'invite_post_user_not_found', $loginId);
            $this->setError(Text::_('PLG_SYSTEM_SIMPLELOGIN_INVITE_USER_NOT_FOUND'));
            $this->redirectWithMessage();
            return;
        }

        // Check of gebruiker AL goedgekeurd is
        $user = $db->setQuery(
            $db->getQuery(true)
                ->select('block')
                ->from('#__users')
                ->where('id = ' . (int) $row->user_id)
        )->loadObject();

        if ($user && (int) $user->block === 0) {
            // Gebruiker is AL goedgekeurd → clear activation en consume token
            $db->setQuery(
                $db->getQuery(true)
                    ->update($db->quoteName('#__users'))
                    ->set($db->quoteName('activation') . ' = ' . $db->quote(''))
                    ->where($db->quoteName('id') . ' = ' . (int) $row->user_id)
            )->execute();

            $this->log((int) $row->user_id, 'invite_post_already_approved', $loginId);

            // Stuur direct een login-link
            $this->sendLoginLink((int) $row->user_id);

            $this->statusMessage = Text::_('PLG_SYSTEM_SIMPLELOGIN_INVITE_ALREADY_APPROVED');
            $this->statusType    = 'success';
            $this->redirectWithMessage();
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

        // 👈 GECORRIGEERD: Gebruik mail_invite_* i.p.v. mail_login_*
        [$subject, $body, $isHtml] = $this->resolveMailTemplate('mail_invite_subject', 'mail_invite_body');
        $this->mailService->sendMail(
            $user->email,
            $subject,
            $body,
            [
                '#name'   => $user->name,
                '#link'   => $inviteLink,
				'#expiry' => $expiryMinutes,
            ],
            $isHtml
        );

        $this->log($userId, 'invite_sent', $loginId);
    }
}