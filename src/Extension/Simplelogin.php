<?php
namespace Adstam\Plugin\System\Simplelogin\Extension;

defined('_JEXEC') or die;
// versie 0.8.7
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Uri\Uri;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Language\Text;
use Joomla\CMS\User\UserFactoryInterface;
use Joomla\Event\Event;
use Joomla\CMS\HTML\HTMLHelper;

class Simplelogin extends CMSPlugin
{
    // ---------------------------------------------------------------------------
    // State properties – gevuld tijdens request, uitgelezen door onAfterRender
    // ---------------------------------------------------------------------------

    protected string $statusMessage = '';
    protected string $statusType    = 'info';
    protected bool   $autoSubmit    = false;
    protected string $redirectUrl   = '';
    protected bool   $showLoginForm = false;
    protected bool   $postLogin     = false;
    protected string $selector      = '';
    protected string $validator     = '';
    protected bool   $escapeProcessed = false;
    protected bool   $registerFlow    = false;

		public static function getSubscribedEvents(): array
		{
     	return [
        'onAfterInitialise' => 'onAfterInitialise',
        'onAfterRender'     => 'onAfterRender',
        'onAfterRoute'      => 'onAfterRoute',
        'onUserAfterSave'   => 'onUserAfterSave',
        'onAjaxSimplelogin' => 'onAjaxSimplelogin',
        'onBeforeRender'    => 'onBeforeRender',
				'onAfterDispatch'   => 'onAfterDispatch',
      ];
    }

    // ===========================================================================
    // Constructor
    // ===========================================================================

    public function __construct($dispatcher, array $config)
    {
        parent::__construct($dispatcher, $config);

        Log::addLogger(
            ['text_file' => 'plg_system_simplelogin.php'],
            Log::ALL,
            ['simplelogin']
        );
    }

    // ===========================================================================
    // Joomla event handlers
    // ===========================================================================

    /**
     * Hoofdentrypoint voor alle plugin flows (frontend only).
     * Routeert naar: registratie, login-form POST, of token-flow.
     */
	public function onAfterInitialise(Event $event): void
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
    // Default: login startscherm
    // -----------------------------------------------------------------------
    $this->showLoginScreen();
}
		 
     

public function onAfterRender(Event $event): void
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
    // Vangt de Joomla login pagina op die nog simplelogin=1 in de URL heeft
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
    // Overlay injectie (ongewijzigd)
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

    /**
     * Verstuurt een invite-link na registratie (getriggerd door handleRegister via $user->save()).
     */
public function onUserAfterSave(array $user, bool $isNew, bool $success, string $msg): void
{
    if (!$isNew || !$success) {
        return;
    }

    $app = Factory::getApplication();

    if (!$app->getSession()->get('sl_invite_pending', false)) {
        return;
    }

    $app->getSession()->set('sl_invite_pending', false);

    $this->sendInviteLink((int) $user['id']);
}

    /**
     * AJAX handler – bereikbaar via index.php?option=com_ajax&plugin=simplelogin&format=json
     */
public function onAjaxSimplelogin(): array
{
    $input  = Factory::getApplication()->input;
    $method = (string) $input->getString('method', '');

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

        return ['success' => false, 'message' => Text::sprintf('PLG_SYSTEM_SIMPLELOGIN_ERR_UNKNOWN_METHOD', $method)];
    } catch (\Throwable $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

private function ajaxExportLog(): array
{
    if ($denied = $this->assertPluginManageAccess()) {
        return $denied;
    }

    $db  = Factory::getDbo();
    $app = Factory::getApplication();

    $since = date('Y-m-d H:i:s', strtotime('-24 hours'));

    // ----------------------------------------------------------------
    // Deel 1: log-tabel
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

    $body = implode("\n", $lines);

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
    $mailer->setBody($body);

    $sent = $mailer->send();

    Factory::getApplication()->getLanguage()->load(
        'plg_system_simplelogin',
        JPATH_PLUGINS . '/system/simplelogin'
    );

    if ($sent === true) {
        return ['success' => true, 'message' => Text::sprintf(
           'PLG_SYSTEM_SIMPLELOGIN_MSG_EXPORT_SENT',
            $config->get('mailfrom')
        )];
    }

    return ['success' => false, 'message' => Text::_(
    'PLG_SYSTEM_SIMPLELOGIN_MSG_EXPORT_FAILED'
    )];
		}

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

    $rows = \Adstam\Plugin\System\Simplelogin\Helper\ReportHelper::getLogRows($type);

    ob_start();
    require __DIR__ . '/../tmpl/logs_table.php';
    $html = ob_get_clean();

    return ['success' => true, 'data' => $html];
}

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
    $query = $db->getQuery(true)
        ->delete('#__simple_login_log');

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

    // ===========================================================================
    // Flow handlers
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
        $this->registerFlow    = false;
        $this->statusMessage = Text::_('PLG_SYSTEM_SIMPLELOGIN_REGISTER_EXISTS');
        $this->statusType    = 'success';
        $this->redirectWithMessage();
        return;
    }

    $config       = $app->getConfig();
    $defaultGroup = (int) $config->get('new_usertype', 2);

    $user = new \Joomla\CMS\User\User();

    $user->set('name', $name);
    $user->set('username', $this->generateUsername($name));
    $user->set('email', strtolower(trim($email)));
    $user->set('block', 0);
    $user->set('activation', $this->createPendingActivation());
    $user->set('password', bin2hex(random_bytes(32)));
    $user->set('groups', [$defaultGroup]);

    // Marker zetten VOOR save, zodat onUserAfterSave hem direct ziet
    $app->getSession()->set('sl_invite_pending', true);

    if (!$user->save()) {
        $app->getSession()->set('sl_invite_pending', false);
        $this->finishRegisterError(Text::_('PLG_SYSTEM_SIMPLELOGIN_REGISTER_FAILED'));
        return;
    }

    // Marker direct opruimen na succesvolle save
    $app->getSession()->set('sl_invite_pending', false);

    $this->registerFlow    = false;
    $this->statusMessage = Text::_('PLG_SYSTEM_SIMPLELOGIN_REGISTER_SUCCESS');
    $this->statusType    = 'success';
    $this->redirectWithMessage();
}

public function onAfterRoute(): void
{
    $app   = Factory::getApplication();
    $input = $app->input;

    if ($app->isClient('administrator')) {
        return;
    }

    // Laat Simplelogin flows met rust
    if (
        $input->getInt('simplelogin') === 1
        || $input->getCmd('selector')
        || $input->getCmd('validator')
        || $input->getCmd('sl_task') === 'register'
    ) {
        return;
    }

    // Detecteer core com_users requests
    $option = $input->getCmd('option');
    $view   = $input->getCmd('view');
    $task   = $input->getCmd('task');

    // Logout altijd doorlaten
    $taskLogout = 
        ($option === 'com_users' && str_contains((string) $task, 'logout'))
        || ($option === 'com_users' && $input->getCmd('task') === 'user.logout');

    if ($taskLogout) {
        $this->log(null, 'core_logout_allowed');
        return;
    }

    $isCoreLogin =
        ($option === 'com_users' && $view === 'login')
        || ($option === 'com_users' && str_contains((string) $task, 'login'));

    $isCoreRegister =
        ($option === 'com_users' && $view === 'registration');

    if (!$isCoreLogin && !$isCoreRegister) {
        return;
    }

    // ---------------------------------------------------------------------
    // Escape naar core login: session-flag i.p.v. URL-parameter
    // ---------------------------------------------------------------------
    $this->log(null, 'core_login_allowed_escape_trigger');

if ($app->getSession()->get('sl_pw_escape', false)) {
    // Verwijder de flag altijd – ook als escapeProcessed al true is
    $app->getSession()->remove('sl_pw_escape');
    
    if (!$this->escapeProcessed) {
        $this->escapeProcessed = true;
        $this->log(null, 'core_login_allowed_escape');
    }
    return;
}

    $this->log(null, $isCoreRegister ? 'core_register_blocked' : 'core_login_blocked');

    // ---------------------------------------------------------------------
    // Bepaal achtergrond
    // ---------------------------------------------------------------------
    $referrer = $_SERVER['HTTP_REFERER'] ?? '';
    $base     = Uri::root();

    $background = (
        !empty($referrer)
        && str_starts_with($referrer, $base)
        && !str_contains($referrer, 'com_users')
    )
        ? $referrer
        : Route::_('index.php', false);

    // ---------------------------------------------------------------------
    // Kies juiste Simplelogin entrypoint
    // ---------------------------------------------------------------------
    $allowPassword = (int) $this->params->get('allow_password_login', 0);

    if ($allowPassword === 1 && $isCoreRegister) {
        $this->log(null, 'core_register_allowed');
        return;
    }

		if (
    	 $allowPassword === 1
    	 && $isCoreLogin
    	 && !$app->getSession()->get('sl_pw_escape_active', false)
		) {

    	$app->getSession()->set('sl_pw_escape', true);
    	$app->getSession()->set('sl_pw_escape_active', true);

   		 $param = 'simplelogin=1';
		} else {
        $param = $isCoreRegister ? 'sl_task=register' : 'simplelogin=1';
    }

    $separator = str_contains($background, '?') ? '&' : '?';

    $app->redirect($background . $separator . $param);
    $app->close();
}



/**
 * Login-form POST handler.
 * Blokkeert en logt verdachte requests vóór rate limiting.
 */
private function handlePost(): void
{
    $app = Factory::getApplication();

    // Verdachte user-agent afvangen vóór verdere verwerking
    if ($this->isSuspiciousRequest()) {
        $this->setError(Text::_('PLG_SYSTEM_SIMPLELOGIN_ERR_REQUEST_DENIED'));
        return;
    }

    if (!$app->getSession()->checkToken()) {
        $this->setError(Text::_('PLG_SYSTEM_SIMPLELOGIN_SESSION_EXPIRED'));
        $this->showLoginForm = true;
        $this->redirectWithMessage();
        return;
    }

    $email = $this->normalizeEmail(
        (string) $app->input->getString('email', '')
    );

    if (!$this->isValidEmail($email)) {

        $this->log(
            null,
            'login_attempt_unknown',
            null,
            $email
        );

        $this->setError(Text::_('PLG_SYSTEM_SIMPLELOGIN_ERR_INVALID_EMAIL'));
        $this->showLoginForm = true;
        return;
    }

    $userId = $this->resolveUserIdByEmail($email);

    // ------------------------------------------------------------
    // Eerst limitering controleren
    // ------------------------------------------------------------

    if ($this->isRateLimitedIp()) {

        $this->log(
            $userId,
            'rate_limited_ip',
            null,
            $email
        );

        $this->setError(Text::_('PLG_SYSTEM_SIMPLELOGIN_ERR_RATE_LIMITED'));
        return;
    }

    if ($userId !== null && $this->isRateLimitedUser($userId)) {

        $this->log(
            $userId,
            'rate_limited_user',
            null,
            $email
        );

        $this->setError(Text::_('PLG_SYSTEM_SIMPLELOGIN_ERR_RATE_LIMITED'));
        return;
    }

    if ($userId !== null && $this->isCooldown($userId)) {

        $this->log(
            $userId,
            'cooldown_blocked',
            null,
            $email
        );

        $this->setError(Text::_('PLG_SYSTEM_SIMPLELOGIN_ERR_COOLDOWN'));
        return;
    }

    // ------------------------------------------------------------
    // PAS NU de daadwerkelijke attempt loggen
    // ------------------------------------------------------------

    $this->log(
        $userId,
        $userId !== null
            ? 'login_attempt_existing'
            : 'login_attempt_unknown',
        null,
        $email
    );

    // ------------------------------------------------------------
    // Login link sturen
    // ------------------------------------------------------------

    $this->sendLoginLink($userId);

    $this->redirectWithMessage();
}

    /**
     * Token flow: bepaalt het type (invite / login) en routeert verder.
     */
private function handleTokenFlow(
    string $selector,
    string $validator
): void {
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

    // ------------------------------------------------------------
    // Scanner detectie
    // ------------------------------------------------------------
    if ($this->isPreflightRequest($row)) {
        $this->log((int) $row->user_id, 'scanner_detected', $loginId);
        $this->finishTokenError(Text::_('PLG_SYSTEM_SIMPLELOGIN_TOKEN_SCANNER_BLOCKED'));
        return;
    }

    // ------------------------------------------------------------
    // used / expired checks � met type-bewust foutbericht
    // ------------------------------------------------------------
    if ((int) $row->used === 1) {
        $this->log((int) $row->user_id, $isInvite ? 'invite_already_used' : 'token_reused', $loginId);
        $this->finishTokenError(Text::_(
            $isInvite
                ? 'PLG_SYSTEM_SIMPLELOGIN_INVITE_ALREADY_USED'
                : 'PLG_SYSTEM_SIMPLELOGIN_TOKEN_REUSED'
        ));
        return;
    }

    if (!empty($row->expires) && strtotime($row->expires) < time()) {
        $this->log((int) $row->user_id, $isInvite ? 'invite_expired' : 'token_expired', $loginId);
        $this->finishTokenError(Text::_(
            $isInvite
                ? 'PLG_SYSTEM_SIMPLELOGIN_INVITE_EXPIRED'
                : 'PLG_SYSTEM_SIMPLELOGIN_TOKEN_EXPIRED'
        ));
        return;
    }

    // ------------------------------------------------------------
    // Routeer naar juiste flow
    // ------------------------------------------------------------
    if ($isInvite) {
        $this->handleInviteActivation($row, $loginId, $validator);
        return;
    }

    $this->statusMessage = Text::_('PLG_SYSTEM_SIMPLELOGIN_STATUS_LOGGING_IN');
    $this->statusType    = 'info';
    $this->postLogin     = true;
    $this->autoSubmit    = true;
}
		 
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
     * Invite-activatie GET: alleen UI voorbereiden, geen state changes.
     * (Cruciaal tegen Outlook SafeLinks en andere link-scanners.)
     */
    private function handleInviteActivation(object $row, int $loginId, string $validator): void
    {
        if ((int) $row->used === 1) {
            $this->log((int) $row->user_id, 'invite_already_used', $loginId);
            $this->setError(Text::_('PLG_SYSTEM_SIMPLELOGIN_INVITE_ALREADY_USED'));
            $this->showLoginForm = true;
            return;
        }

        if (!empty($row->expires) && strtotime($row->expires) < time()) {
            $this->log((int) $row->user_id, 'invite_expired', $loginId);
            $this->setError(Text::_('PLG_SYSTEM_SIMPLELOGIN_INVITE_EXPIRED'));
            $this->showLoginForm = true;
            return;
        }

        if (!password_verify($validator, $row->token)) {
            $this->log((int) $row->user_id, 'invite_invalid', $loginId);
            $this->setError(Text::_('PLG_SYSTEM_SIMPLELOGIN_INVITE_INVALID'));
            $this->showLoginForm = true;
            return;
        }

        // Alleen UI + POST trigger – geen DB-wijzigingen hier
        $this->statusMessage = Text::_('PLG_SYSTEM_SIMPLELOGIN_STATUS_ACTIVATING');
        $this->statusType    = 'info';
        $this->postLogin     = true;
        $this->autoSubmit    = true;
    }

    private function handleInvitePostActivation(object $row, int $loginId, string $validator): void
    {
        $app = Factory::getApplication();

        if (!$app->getSession()->checkToken()) {
            $this->setError(Text::_('PLG_SYSTEM_SIMPLELOGIN_SESSION_EXPIRED'));
            $this->showLoginForm = true;
            $this->redirectWithMessage();
            return;
        }

        $db = Factory::getDbo();

        if ((int) $row->used === 1) {
            $this->log((int) $row->user_id, 'invite_post_already_used', $loginId);
            $this->setError(Text::_('PLG_SYSTEM_SIMPLELOGIN_INVITE_ALREADY_USED'));
            $this->showLoginForm = true;
            return;
        }

        if (!password_verify($validator, $row->token)) {
            $this->log((int) $row->user_id, 'invite_post_invalid', $loginId);
            $this->setError(Text::_('PLG_SYSTEM_SIMPLELOGIN_INVITE_INVALID'));
            $this->showLoginForm = true;
            return;
        }

        if (!empty($row->expires) && strtotime($row->expires) < time()) {
            $this->log((int) $row->user_id, 'invite_post_expired', $loginId);
            $this->setError(Text::_('PLG_SYSTEM_SIMPLELOGIN_INVITE_EXPIRED'));
            $this->showLoginForm = true;
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
                    $db->quoteName('block') . ' = 0',
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

    /**
     * Token login: valideer, log in, redirect.
     * Ontvangt het al opgehaalde $row-object van handleTokenFlow om dubbele query te voorkomen.
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

            if ($itemId > 0) {
                $this->redirectUrl = Route::_('index.php?Itemid=' . $itemId, false);
            } else {
                // fallback naar homepage als configuratie fout is
                $this->redirectUrl = Route::_('index.php', false);
            }
        } else {
            // default = homepage
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

        $config = $app->getConfig();
        $mailer = Factory::getMailer();
        $mailer->setSender([$config->get('mailfrom'), $config->get('fromname')]);
        $mailer->addRecipient($email);

        $params       = $this->params;
        $bodyTemplate = $params->get('mail_invite_body', '');
        $expiryMin    = (int) $params->get('invite_expiry_minutes', 60);

        $mailBody    = $this->buildMailBody($bodyTemplate, $user->name, $inviteLink, $expiryMin);
        $mailSubject = $params->get('mail_invite_subject', '');

        $mailer->setSubject($mailSubject);
        $mailer->setBody($mailBody);
        $mailer->send();

        $this->log($userId, 'invite_sent', $loginId);
    }

    /**
     * Vervangt #hashtags in de mailbody met de geldende waarden.
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

        $params       = $this->params;
        $bodyTemplate = $params->get('mail_login_body', '');
        $expiryMin    = (int) $params->get('expiry_minutes', 60);

        $mailBody    = $this->buildMailBody($bodyTemplate, $user->name, $loginLink, $expiryMin);
        $mailSubject = $params->get('mail_login_subject', '');

        $mailer->setSubject($mailSubject);
        $mailer->setBody($mailBody);
        $mailer->send();

        $this->log($userId, 'link_sent');

        $this->statusMessage = Text::_('PLG_SYSTEM_SIMPLELOGIN_LINK_SENT');
    }

    // ===========================================================================
    // Security helpers
    // ===========================================================================

    /**
     * Overschrijft het wachtwoord met een willekeurige hash zodat password-login onmogelijk is.
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

    /**
     * Controleert of er recent al een request is binnengekomen (op IP en/of user_id).
     */
    private function isCooldown(?int $userId): bool
    {
        $db       = Factory::getDbo();
        $cooldown = (int) $this->params->get('request_cooldown_seconds', 30);

        $window = date(
            'Y-m-d H:i:s',
            strtotime("-{$cooldown} seconds")
        );

        $validStatuses = [
            'login_attempt_existing',
            'login_attempt_unknown'
        ];

        $quotedStatuses = array_map(
            [$db, 'quote'],
            $validStatuses
        );

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
                        : '1=0'
                ],
                'OR'
            );

        return (int) $db->setQuery($query)->loadResult() > 0;
    }

    /**
     * Rate limiting op IP-niveau.
     */
    private function isRateLimitedIp(): bool
    {
        $db     = Factory::getDbo();
        $limit  = (int) $this->params->get('rate_limit_ip_max', 10);
        $window = (int) $this->params->get('rate_limit_ip_window', 5);

        $since = date(
            'Y-m-d H:i:s',
            strtotime("-{$window} minutes")
        );

        $validStatuses = [
            'login_attempt_existing',
            'login_attempt_unknown'
        ];

        $quotedStatuses = array_map(
            [$db, 'quote'],
            $validStatuses
        );

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
     * Rate limiting op user-niveau (user_id).
     */
    private function isRateLimitedUser(int $userId): bool
    {
        $db     = Factory::getDbo();
        $limit  = (int) $this->params->get('rate_limit_user_max', 5);
        $window = (int) $this->params->get('rate_limit_user_window', 10);

        $since = date(
            'Y-m-d H:i:s',
            strtotime("-{$window} minutes")
        );

        $count = (int) $db->setQuery(
            $db->getQuery(true)
                ->select('COUNT(*)')
                ->from('#__simple_login_throttle')
                ->where('user_id = ' . (int) $userId)
                ->where('created > ' . $db->quote($since))
                ->where(
                    'status IN (' .
                    $db->quote('login_attempt_existing') .
                    ',' .
                    $db->quote('link_sent') .
                    ')'
                )
        )->loadResult();

        return $count >= $limit;
    }

    /**
     * Detecteert verdachte user-agents (bots, scanners, CLI tools).
     */
    private function isSuspiciousRequest(?int $userId = null, ?int $loginId = null): bool
    {
        $ua         = strtolower($this->getUserAgent());
        $signatures = ['curl', 'wget', 'python', 'bot', 'spider', 'scanner', 'headless', 'phantom', 'httpclient', 'libwww'];

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
     */
    private function isPreflightRequest(object $row): bool
    {
        $type = $this->detectScannerPreflight($row);

        if ($type === 'hard') {
            return true;
        }

        if ($type === 'soft') {
            return true;
        }

        return false;
    }

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
                ->where('created > ' . $db->quote($window))
                ->where('status = '  . $db->quote('token_hit'))
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


    // ===========================================================================
    // AJAX handlers
    // ===========================================================================

    /**
     * Overschrijft wachtwoorden van alle niet-admin frontend-gebruikers met random hashes.
     */
    protected function ajaxHashPasswords(): array
    {
        $app = Factory::getApplication();
        $db  = Factory::getDbo();

        if (!\Joomla\CMS\Session\Session::checkToken($app->input->getMethod() === 'POST' ? 'post' : 'get')) {
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
                        ->set('password = ' . $db->quote(password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT)))
                        ->where('id = ' . (int) $targetUser->id)
                )->execute();
                $processed++;
            } catch (\Exception $e) {
                // Skip bij fout
            }
        }

        return [
            'success' => true,
            'message' => Text::sprintf('PLG_SYSTEM_SIMPLELOGIN_MSG_HASH_RESULT', $processed, $skipped),
        ];
    }


    // ===========================================================================
    // Database / utility helpers
    // ===========================================================================

    public function onAfterDispatch(): void
    {
        $app = Factory::getApplication();
        if (!$app->isClient('administrator')) return;

        $input = $app->getInput();
        if ($input->get('option') !== 'com_plugins' || $input->get('view') !== 'plugin') return;

        // Expliciet laden voor zekerheid
        $app->getLanguage()->load('plg_system_simplelogin', JPATH_PLUGINS . '/system/simplelogin');

        $translations = json_encode([
            'name'   => Text::_('PLG_SYSTEM_SIMPLELOGIN_BTN_NAME'),
            'link'   => Text::_('PLG_SYSTEM_SIMPLELOGIN_BTN_LINK'),
            'expiry' => Text::_('PLG_SYSTEM_SIMPLELOGIN_BTN_EXPIRY'),
        ]);

        $app->getDocument()->addScriptDeclaration("var SimpleloginBtnLabels = {$translations};");

        HTMLHelper::_('script', 'plg_system_simplelogin/bodybuttons.js', ['relative' => true, 'version' => 'auto']);
    }

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
     * Toont het login-scherm (fallback state).
     */
    private function showLoginScreen(): void
    {
        $this->statusMessage = Text::_('PLG_SYSTEM_SIMPLELOGIN_STATUS_LOGIN_TITLE');
        $this->statusType    = 'info';
        $this->showLoginForm = true;
    }

    /**
     * Zet een foutmelding in de state properties.
     */
    private function setError(string $msg): void
    {
        $this->statusMessage = $msg;
        $this->statusType    = 'danger';
    }

    private function getIp(): string
    {
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }

    private function getUserAgent(): string
    {
        return $_SERVER['HTTP_USER_AGENT'] ?? '';
    }

    /**
     * Ruimt throttle- en token-records op na een succesvolle login.
     */
private function cleanup(int $userId): void
{
    $db = Factory::getDbo();

    // Throttle: verwijder records ouder dan throttle_cleanup_time (default 60 minuten)
    $throttleMinutes = max(1, (int) $this->params->get('throttle_cleanup_time', 60));

    $db->setQuery(
        $db->getQuery(true)
            ->delete('#__simple_login_throttle')
            ->where(
                'created < DATE_SUB(NOW(), INTERVAL '
                . $throttleMinutes .
                ' MINUTE)'
            )
    )->execute();

    // Log: verwijder records ouder dan log_retention_days (default 30 dagen)
    $logDays = (int) $this->params->get('log_retention_days', 30);

    if ($logDays > 0) {
        $db->setQuery(
            $db->getQuery(true)
                ->delete('#__simple_login_log')
                ->where(
                    'created < DATE_SUB(NOW(), INTERVAL '
                    . (int) $logDays .
                    ' DAY)'
                )
        )->execute();
    }

    // Tokens: verwijder gebruikte en verlopen tokens
    $db->setQuery("
        DELETE FROM #__simple_login
        WHERE used = 1
        OR expires < NOW()
    ")->execute();
}

    private function normalizeEmail(string $email): string
    {
        return strtolower(trim($email));
    }

    private function isValidEmail(string $email): bool
    {
        return (bool) filter_var($email, FILTER_VALIDATE_EMAIL);
    }

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

    private function generateUsername(string $name): string
    {
        $base = preg_replace('/[^a-z0-9]+/i', '.', strtolower($name));
        $base = trim($base, '.');

        return $base . '.' . random_int(10000, 99999);
    }

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

    private function isDebug(): bool
    {
        return (bool) Factory::getApplication()->get('debug');
    }

    /**
     * Centrale logmethode voor zowel audit/throttle-acties als debug-only diagnostiek.
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
            ?? ($userId !== null
                ? $this->loadUsername($userId)
                : null);

        $emailHash = null;

        if (
            $identifier
            && filter_var($identifier, FILTER_VALIDATE_EMAIL)
        ) {
            $emailHash = $this->hashEmail($identifier);
        }

        $created   = date('Y-m-d H:i:s');
        $packedIp  = $this->getPackedIp();
        $userAgent = $this->getUserAgent();

        // ============================================================
        // LOG TABLE
        // ============================================================

        try {
            $queryLog = $db->getQuery(true)
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
                    'login_id'
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
                ]));
            $db->setQuery($queryLog)->execute();

        } catch (\Exception $e) {
            Log::add(
                'Simplelogin log insert failed: ' . $e->getMessage(),
                Log::ERROR,
    						['simplelogin']
            );
        }

        // ============================================================
        // THROTTLE TABLE
        // ============================================================

        if (!$toThrottle) {
            return;
        }

        try {
            $queryThrottle = $db->getQuery(true)
                ->insert('#__simple_login_throttle')
                ->columns([
                    'user_id',
                    'username',
                    'ip',
                    'created',
                    'status',
                    'login_id'
                ])
                ->values(implode(',', [
                    $userId !== null ? (int) $userId : 'NULL',
                    $resolvedIdentifier !== null ? $db->quote($resolvedIdentifier) : 'NULL',
                    'UNHEX(' . $db->quote($packedIp) . ')',
                    $db->quote($created),
                    $db->quote($status),
                    $loginId !== null ? (int) $loginId : 'NULL',
                ]));
            $db->setQuery($queryThrottle)->execute();

        } catch (\Exception $e) {
            Log::add(
                'Simplelogin throttle insert failed: ' . $e->getMessage(),
                Log::ERROR,
								['simplelogin']
            );
        }
    }

    private function getStatusDefinition(string $status): array
    {
        $map = [
            'password_updated'                   => ['type' => 'AccountEvent',     'debugonly' => false, 'throttle' => true],
            'register_existing_email'            => ['type' => 'AccountEvent',      'debugonly' => false, 'throttle' => false],
            'register_success'                   => ['type' => 'AccountEvent',      'debugonly' => false, 'throttle' => false],
            'user_not_found'                     => ['type' => 'AccountEvent',      'debugonly' => false, 'throttle' => true],

            'invite_email_not_found'             => ['type' => 'DebugDiagnostics',  'debugonly' => true,  'throttle' => false],
            'post_without_selector'              => ['type' => 'DebugDiagnostics',  'debugonly' => true,  'throttle' => false],
            'token_row_missing'                  => ['type' => 'DebugDiagnostics',  'debugonly' => true,  'throttle' => false],
            'unexpected_flow_state'              => ['type' => 'DebugDiagnostics',  'debugonly' => true,  'throttle' => false],

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

            'clean_url'                          => ['type' => 'DebugRequestTrace', 'debugonly' => true,  'throttle' => false],
            'raw_query_before'                   => ['type' => 'DebugRequestTrace', 'debugonly' => true,  'throttle' => false],
            'selector_xxx'                       => ['type' => 'DebugRequestTrace', 'debugonly' => true,  'throttle' => false],
            'validator_present_yes'              => ['type' => 'DebugRequestTrace', 'debugonly' => true,  'throttle' => false],
            'validator_present_no'               => ['type' => 'DebugRequestTrace', 'debugonly' => true,  'throttle' => false],

            'invite_activated'                   => ['type' => 'InviteFlow',        'debugonly' => false, 'throttle' => false],
            'invite_already_used'                => ['type' => 'InviteFlow',        'debugonly' => false, 'throttle' => false],
            'invite_expired'                     => ['type' => 'InviteFlow',        'debugonly' => false, 'throttle' => false],
            'invite_invalid'                     => ['type' => 'InviteFlow',        'debugonly' => false, 'throttle' => false],
            'invite_sent'                        => ['type' => 'InviteFlow',        'debugonly' => false, 'throttle' => false],

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

    /**
     * Redirect met behoud van status via session (PRG pattern).
     */
    private function redirectWithMessage(): void
    {
        $app     = Factory::getApplication();
        $session = $app->getSession();

        $this->log(null, 'redirectwithmessage');

        $session->set('sl_statusMessage', $this->statusMessage);
        $session->set('sl_statusType', $this->statusType);
        $session->set('sl_showLoginForm', $this->showLoginForm);
        $session->set('sl_register_flow', $this->registerFlow);

        $returnUrl = $session->get('sl_return_url');

        if ($returnUrl) {
            $uri = new Uri($returnUrl);

            $queryString = $uri->getQuery();

            $this->log(null, 'raw_query_before');
            $this->log(null, 'clean_url');

            parse_str($queryString, $queryArray);

            foreach ([
                'simplelogin',
                'selector',
                'validator',
                'sl_task',
                'sl_pw',
                'allow_pw'
            ] as $var) {
                unset($queryArray[$var]);
            }

            $uri->setQuery($queryArray);

            $cleanUrl = (string) $uri;

            $this->log(null, 'clean_url: ' . $cleanUrl);

            $session->remove('sl_return_url');

            $app->redirect($cleanUrl);
        } else {
            $fallback = Route::_('index.php', false);
            $app->redirect($fallback);
        }

        $app->close();
    }

    /**
     * Admin AJAX: CSRF-token + core.manage op com_plugins.
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

    /**
     * Registratiefout: melding bewaren en registratiescherm opnieuw tonen (PRG).
     */
    private function finishRegisterError(string $message): void
    {
        $this->registerFlow  = true;
        $this->showLoginForm = true;
        $this->postLogin     = false;
        $this->autoSubmit    = false;
        $this->setError($message);
        $this->redirectWithMessage();
    }

    /**
     * Tokenfout: melding tonen via PRG (voorkomt lege overlay na auto-POST).
     */
    private function finishTokenError(string $message): void
    {
        $this->postLogin     = false;
        $this->autoSubmit    = false;
        $this->showLoginForm = true;
        $this->setError($message);
        $this->redirectWithMessage();
    }

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
     * Vertaal opgeslagen status (ook als sessie nog een taalsleutel bevat).
     */
    private function resolveStatusMessage(string $message): string
    {
        if ($message === '' || !str_starts_with($message, 'PLG_SYSTEM_SIMPLELOGIN_')) {
            return $message;
        }

        $translated = Text::_($message);

        return $translated !== $message ? $translated : $message;
    }

    private const PENDING_ACTIVATION_PREFIX = 'sl-pending:';

    /**
     * Markeer account als wachtend op activatie via registratiemail.
     */
    private function createPendingActivation(): string
    {
        return self::PENDING_ACTIVATION_PREFIX . bin2hex(random_bytes(16));
    }

    private function isPendingActivation(?string $activation): bool
    {
        return str_starts_with(trim((string) $activation), self::PENDING_ACTIVATION_PREFIX);
    }

    /**
     * Account mag een loginlink ontvangen en mag inloggen.
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
                ->where('type = ' . $db->quote($type))
                ->where('used = 0')
        )->execute();
    }

    /**
     * Markeer token atomisch als gebruikt en trek overige tokens van dezelfde gebruiker in.
     */
    private function consumeToken(int $loginId, int $userId, string $type): bool
    {
        $db = Factory::getDbo();

        $db->setQuery(
            $db->getQuery(true)
                ->update('#__simple_login')
                ->set('used = 1')
                ->where('id = ' . (int) $loginId)
                ->where('used = 0')
        )->execute();

        if ($db->getAffectedRows() !== 1) {
            return false;
        }

        $this->revokeUserTokens($userId, $type);

        return true;
    }

    private function hashEmail(?string $email): ?string
    {
        if (empty($email)) {
            return null;
        }

        return hash('sha256', strtolower(trim($email)));
    }

    private function getPackedIp(): string
    {
        return bin2hex(inet_pton($this->getIp()) ?: inet_pton('0.0.0.0'));
    }
}
