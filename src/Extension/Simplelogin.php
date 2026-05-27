<?php
/**
 * @package   Simplelogin
 * @author    Ad Stam
 * @copyright Copyright (C) 2026 Ad Stam. All rights reserved.
 * @license   GNU General Public License version 2 or later; see LICENSE.txt
 * @link      https://demo.adstam.nl
 */

namespace Adstam\Plugin\System\Simplelogin\Extension;

defined('_JEXEC') or die;

use Adstam\Plugin\System\Simplelogin\Traits\AjaxTrait;
use Adstam\Plugin\System\Simplelogin\Traits\LogTrait;
use Adstam\Plugin\System\Simplelogin\Traits\LoginFlowTrait;
use Adstam\Plugin\System\Simplelogin\Traits\RegisterFlowTrait;
use Adstam\Plugin\System\Simplelogin\Traits\SecurityTrait;
use Adstam\Plugin\System\Simplelogin\Traits\UtilityTrait;
use Joomla\CMS\Factory;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Uri\Uri;
use Joomla\Event\Event;

class Simplelogin extends CMSPlugin
{
    // ---------------------------------------------------------------------------
    // Traits
    // ---------------------------------------------------------------------------

    use LoginFlowTrait;
    use RegisterFlowTrait;
    use SecurityTrait;
    use LogTrait;
    use UtilityTrait;
    use AjaxTrait;

    // ---------------------------------------------------------------------------
    // State properties — gevuld tijdens request, uitgelezen door onAfterRender
    // ---------------------------------------------------------------------------

    protected string $statusMessage    = '';
    protected string $statusType       = 'info';
    protected bool   $autoSubmit       = false;
    protected string $redirectUrl      = '';
    protected bool   $showLoginForm    = false;
    protected bool   $postLogin        = false;
    protected string $selector         = '';
    protected string $validator        = '';
    protected bool   $escapeProcessed  = false;
    protected bool   $registerFlow     = false;

    // ---------------------------------------------------------------------------
    // Event subscriptions
    // ---------------------------------------------------------------------------

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

    // ---------------------------------------------------------------------------
    // Constructor
    // ---------------------------------------------------------------------------

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
    // Event handlers
    // ===========================================================================

    /**
     * Hoofdentrypoint voor alle plugin flows (frontend only).
     * Delegeert naar LoginFlowTrait::handleInitialise().
     */
    public function onAfterInitialise(Event $event): void
    {
        $this->handleInitialise();
    }

    /**
     * Injecteer overlay/register-layout in de HTML output.
     * Delegeert naar LoginFlowTrait::handleRender().
     */
    public function onAfterRender(Event $event): void
    {
        $this->handleRender();
    }

    /**
     * Blokkeert core com_users login/registratie en stuurt door naar Simplelogin.
     */
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

        $this->log(null, 'core_login_allowed_escape_trigger');

        // Escape naar core login via session-flag
        if ($app->getSession()->get('sl_pw_escape', false)) {
            $app->getSession()->remove('sl_pw_escape');

            if (!$this->escapeProcessed) {
                $this->escapeProcessed = true;
                $this->log(null, 'core_login_allowed_escape');
            }
            return;
        }

        $this->log(null, $isCoreRegister ? 'core_register_blocked' : 'core_login_blocked');

        // Bepaal achtergrond voor redirect
        $referrer = $_SERVER['HTTP_REFERER'] ?? '';
        $base     = Uri::root();

        $background = (
            !empty($referrer)
            && str_starts_with($referrer, $base)
            && !str_contains($referrer, 'com_users')
        )
            ? $referrer
            : Route::_('index.php', false);

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
     * Verstuurt een invite-link na registratie.
     * Getriggerd door handleRegister() via $user->save().
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
     * AJAX handler — bereikbaar via index.php?option=com_ajax&plugin=simplelogin&format=json
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

            return [
                'success' => false,
                'message' => Text::sprintf('PLG_SYSTEM_SIMPLELOGIN_ERR_UNKNOWN_METHOD', $method),
            ];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * onBeforeRender — momenteel geen actie; placeholder voor toekomstig gebruik.
     */
    public function onBeforeRender(): void
    {
        // Intentionally empty — placeholder for future use.
    }

    /**
     * Laadt JS-vertalingen en bodybuttons script in de plugin-beheerpagina.
     */
    public function onAfterDispatch(): void
    {
        $app = Factory::getApplication();

        if (!$app->isClient('administrator')) {
            return;
        }

        $input = $app->getInput();

        if ($input->get('option') !== 'com_plugins' || $input->get('view') !== 'plugin') {
            return;
        }

        $app->getLanguage()->load('plg_system_simplelogin', JPATH_PLUGINS . '/system/simplelogin');

        $translations = json_encode([
            'name'   => Text::_('PLG_SYSTEM_SIMPLELOGIN_BTN_NAME'),
            'link'   => Text::_('PLG_SYSTEM_SIMPLELOGIN_BTN_LINK'),
            'expiry' => Text::_('PLG_SYSTEM_SIMPLELOGIN_BTN_EXPIRY'),
        ]);

        $app->getDocument()->addScriptDeclaration("var SimpleloginBtnLabels = {$translations};");

        HTMLHelper::_('script', 'plg_system_simplelogin/bodybuttons.js', ['relative' => true, 'version' => 'auto']);
    }
}