<?php

namespace AdStam\Plugin\System\Simplelogin\Extension;

defined('_JEXEC') or die;

use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Application\CMSApplicationInterface;
use Joomla\Database\DatabaseInterface;
use Joomla\CMS\User\UserFactoryInterface;
use Joomla\CMS\Mail\MailFactoryInterface;
use Joomla\Event\Event;
use Joomla\CMS\Form\Form;
use Joomla\CMS\Log\Log;

class Simplelogin extends CMSPlugin
{
    protected CMSApplicationInterface $app;
    protected DatabaseInterface $db;
    protected UserFactoryInterface $userFactory;
    protected MailFactoryInterface $mailFactory;

    protected string $statusMessage = '';
    protected string $statusType = 'danger';
    protected bool $showForm = true;
    protected string $redirectUrl = '';

    public function __construct(
        &$subject,
        $config,
        CMSApplicationInterface $app,
        DatabaseInterface $db,
        UserFactoryInterface $userFactory,
        MailFactoryInterface $mailFactory
    ) {
        parent::__construct($subject, $config);

        $this->app = $app;
        $this->db = $db;
        $this->userFactory = $userFactory;
        $this->mailFactory = $mailFactory;

        Log::addLogger(['text_file' => 'plg_system_simplelogin.php']);
    }

    public function onAfterInitialise(Event $event): void
    {
        if ($this->app->isClient('administrator')) {
            return;
        }

        if ($this->app->input->getInt('simplelogin') === 1) {
            $this->handleRequest();
        }
    }

    private function handleRequest(): void
    {
        $input = $this->app->input;

        $token = $input->getString('token');
        $username = $input->getString('username');

        if ($token) {
            $this->handleTokenLogin($token);
        } elseif ($username) {
            $this->sendLoginLink($username);
        } elseif ($input->getMethod() === 'POST') {
            $this->handlePost();
        }
    }

    /**
     * ?? RATE LIMIT CHECK
     */
    private function isRateLimited(?string $username): bool
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

        $minutes = 10;
        $limit = 5;

        $since = date('Y-m-d H:i:s', strtotime("-{$minutes} minutes"));

        $query = $this->db->getQuery(true)
            ->select('COUNT(*)')
            ->from('#__simple_login_throttle')
            ->where('created > ' . $this->db->quote($since))
            ->where(
                '(' .
                'ip = ' . $this->db->quote($ip) .
                ' OR username = ' . $this->db->quote($username) .
                ')'
            );

        $count = (int) $this->db->setQuery($query)->loadResult();

        return $count >= $limit;
    }

    private function logAttempt(?string $username): void
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

        $query = $this->db->getQuery(true)
            ->insert('#__simple_login_throttle')
            ->columns(['ip', 'username', 'created'])
            ->values(
                implode(',', [
                    $this->db->quote($ip),
                    $this->db->quote($username),
                    $this->db->quote(date('Y-m-d H:i:s'))
                ])
            );

        $this->db->setQuery($query)->execute();
    }

    private function handleTokenLogin(string $token): void
    {
        $query = $this->db->getQuery(true)
            ->select('*')
            ->from('#__simple_login');

        $rows = $this->db->setQuery($query)->loadObjectList();

        foreach ($rows as $row) {
            if (password_verify($token, $row->token)) {

                if (strtotime($row->expires) < time()) {
                    $this->setError('Deze link is verlopen.');
                    return;
                }

                if ($row->attempts > 0) {
                    $this->setError('Deze link is al gebruikt.');
                    return;
                }

                $user = $this->userFactory->loadUserById((int) $row->user_id);

                if (!$user || !$user->id) {
                    $this->setError('Gebruiker niet gevonden.');
                    return;
                }

                $this->db->setQuery(
                    'UPDATE #__simple_login SET attempts = 1 WHERE id = ' . (int) $row->id
                )->execute();

                $this->app->login(
                    ['username' => $user->username, 'password' => ''],
                    ['silent' => true]
                );

                Log::add('Token login: user ' . $user->id, Log::INFO);

                $this->redirectUrl = $this->getRedirectUrl();
                $this->statusMessage = 'Je bent succesvol ingelogd!';
                $this->statusType = 'success';
                $this->showForm = false;

                return;
            }
        }

        $this->setError('Deze link is ongeldig.');
    }

    private function sendLoginLink(string $username): void
    {
        // ?? RATE LIMIT
        if ($this->isRateLimited($username)) {
            usleep(random_int(200000, 800000));
            $this->setError('Te veel pogingen. Probeer het later opnieuw.');
            return;
        }

        $this->logAttempt($username);

        $query = $this->db->getQuery(true)
            ->select(['id', 'email', 'name'])
            ->from('#__users')
            ->where('username = ' . $this->db->quote($username));

        $user = $this->db->setQuery($query)->loadObject();

        // anti-enumeration
        if (!$user) {
            $this->statusMessage = 'Als deze gebruiker bestaat, is er een e-mail verzonden.';
            $this->statusType = 'success';
            return;
        }

        $rawToken = bin2hex(random_bytes(32));
        $hashedToken = password_hash($rawToken, PASSWORD_DEFAULT);

        $expiry = date('Y-m-d H:i:s', strtotime('+' . (int) $this->params->get('expiry_minutes', 15) . ' minutes'));

        $this->db->setQuery(
            "INSERT INTO #__simple_login (user_id, token, expires, attempts)
             VALUES ({$user->id}, {$this->db->quote($hashedToken)}, {$this->db->quote($expiry)}, 0)"
        )->execute();

        $link = Uri::root() . "?simplelogin=1&token={$rawToken}";

        $mailer = $this->mailFactory->createMailer();
        $config = $this->app->getConfig();

        $mailer->setSender([$config->get('mailfrom'), $config->get('fromname')]);
        $mailer->addRecipient($user->email);
        $mailer->setSubject($this->params->get('mail_subject', 'Inloglink'));
        $mailer->setBody("Klik op deze link om in te loggen:\n\n{$link}");

        $mailer->Send();

        Log::add('Login link sent to user: ' . $user->id, Log::INFO);

        $this->statusMessage = 'Als deze gebruiker bestaat, is er een e-mail verzonden.';
        $this->statusType = 'success';
        $this->showForm = false;
    }

    private function handlePost(): void
    {
        if (!$this->app->getSession()->checkToken()) {
            $this->setError('Ongeldige sessie.');
            return;
        }

        $username = $this->app->input->getString('username');
        $this->sendLoginLink($username);
    }

    private function getRedirectUrl(): string
    {
        $itemId = (int) $this->params->get('landing_itemid', 0);

        return $itemId
            ? Route::_('index.php?Itemid=' . $itemId, false)
            : Route::_('index.php', false);
    }

    private function setError(string $message): void
    {
        $this->statusMessage = $message;
        $this->statusType = 'danger';
    }

    public function onAfterRender(Event $event): void
    {
        if ($this->app->isClient('administrator') || $this->app->input->getInt('simplelogin') !== 1) {
            return;
        }

        $layout = new \Joomla\CMS\Layout\FileLayout(
            'simplelogin.overlay',
            JPATH_PLUGINS . '/system/simplelogin/layouts'
        );

        $html = $layout->render([
            'statusMessage' => $this->statusMessage,
            'statusType' => $this->statusType,
            'showForm' => $this->showForm,
            'redirectUrl' => $this->redirectUrl
        ]);

        $body = $this->app->getBody();
        $this->app->setBody(str_replace('</body>', $html . '</body>', $body));
    }

    public function onContentPrepareForm(Form $form, $data = []): void
    {
        if ($form->getName() !== 'com_plugins.plugin') {
            return;
        }

        if (($data['element'] ?? '') !== 'simplelogin') {
            return;
        }

        $form->setFieldAttribute('landing_itemid', 'showon', 'landing_page_option:custom');
        $form->setFieldAttribute('password_login_itemid', 'showon', 'show_password_login:yes');
    }
}