<?php
/**
 * @package   Simplelogin
 * @author    Ad Stam
 * @copyright Copyright (C) 2026 Ad Stam. All rights reserved.
 * @license   GNU General Public License version 2 or later; see LICENSE.txt
 * @link      https://demo.adstam.nl
 */

namespace StamPlusJ\Plugin\System\Simplelogin\Extension;

defined('_JEXEC') or die;

use StamPlusJ\Plugin\System\Simplelogin\Traits\AjaxTrait;
use StamPlusJ\Plugin\System\Simplelogin\Traits\LogTrait;
use StamPlusJ\Plugin\System\Simplelogin\Traits\LoginFlowTrait;
use StamPlusJ\Plugin\System\Simplelogin\Traits\RegisterFlowTrait;
use StamPlusJ\Plugin\System\Simplelogin\Traits\SecurityTrait;
use StamPlusJ\Plugin\System\Simplelogin\Traits\UtilityTrait;
use StamPlusJ\Plugin\System\Simplelogin\Service\MailServiceInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Uri\Uri;
use Joomla\CMS\Form\Form;
use Joomla\Event\Event;
use Joomla\Event\DispatcherInterface;

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
    // Dependencies
    // ---------------------------------------------------------------------------

    private MailServiceInterface $mailService;

    // ---------------------------------------------------------------------------
    // State properties
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
			'onContentPrepareForm' => 'onContentPrepareForm',
        ];
    }

    // ---------------------------------------------------------------------------
    // Constructor
    // ---------------------------------------------------------------------------

    public function __construct(DispatcherInterface $dispatcher, array $config, MailServiceInterface $mailService)
    {
        parent::__construct($dispatcher, $config);
        $this->mailService = $mailService;

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

        $this->log(null, $isCoreRegister ? 'core_register_blocked' : 'core_login_blocked');

        // Escape naar core login via session-flag
        if ($app->getSession()->get('sl_pw_escape', false)) {
            $app->getSession()->remove('sl_pw_escape');

            if (!$this->escapeProcessed) {
                $this->escapeProcessed = true;
                $this->log(null, 'core_login_allowed_escape');
            }
            return;
        }

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
    public function onUserAfterSave(array $data, bool $isNew, bool $result, ?string $msg = null): void
    {
        if (!$isNew || !$result) {
            return;
        }
        $app = Factory::getApplication();

        if (!$app->getSession()->get('sl_invite_pending', false)) {
            return;
        }

        $app->getSession()->set('sl_invite_pending', false);

        $this->sendInviteLink((int) $data['id']);
    }

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

            if ($method === 'ApproveUser') {
                return $this->ajaxApproveUser();
            }

            if ($method === 'RejectUser') {
                return $this->ajaxRejectUser();
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
     * onBeforeRender
     */
    public function onBeforeRender(): void
    {
        // Intentionally empty
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

        $this->loadLanguage('plg_system_simplelogin', JPATH_PLUGINS . '/system/simplelogin');

        $translations = json_encode([
            'name'      => Text::_('PLG_SYSTEM_SIMPLELOGIN_BTN_NAME'),
            'link'      => Text::_('PLG_SYSTEM_SIMPLELOGIN_BTN_LINK'),
            'expiry'    => Text::_('PLG_SYSTEM_SIMPLELOGIN_BTN_EXPIRY'),
            'email'     => Text::_('PLG_SYSTEM_SIMPLELOGIN_BTN_EMAIL'),
            'sitename'  => Text::_('PLG_SYSTEM_SIMPLELOGIN_BTN_SITENAME'),
            'reason'    => Text::_('PLG_SYSTEM_SIMPLELOGIN_BTN_REASON'),
            'adminlink' => Text::_('PLG_SYSTEM_SIMPLELOGIN_BTN_ADMINLINK'),
        ]);

        $app->getDocument()->addScriptDeclaration("var SimpleloginBtnLabels = {$translations};");

        HTMLHelper::_('script', 'plg_system_simplelogin/bodybuttons.js', ['relative' => true, 'version' => 'auto']);
    }

    // ===========================================================================
    // Template Validatie (AC9, AC10)
    // ===========================================================================

    /**
     * Valideert mail templates op afbeeldings-URLs bij opslaan van plugin parameters.
     * Toont pop-up bij te grote of externe afbeeldingen.
     *
     * @param string $context De context
     * @param object $table De extensie tabel
     * @param bool $isNew Of het een nieuwe extensie is
     * @return bool True om opslaan toe te staan
     */

	/**
	 * Voegt client-side validatie toe aan het plugin form voor afbeeldingen.
	 * Toont pop-up bij te grote of externe afbeeldingen (AC9, AC10).
	 */
	public function onContentPrepareForm($form, $data): void
	{
		// Alleen voor Simplelogin plugin in administrator
		if (!$form instanceof Form || $form->getName() !== 'com_plugins.plugin') {
			return;
		}

		if ($form->getValue('element') !== 'simplelogin' || $form->getValue('folder') !== 'system') {
			return;
		}

		$app = Factory::getApplication();
		$waarschuwingTitel = Text::_('PLG_SYSTEM_SIMPLELOGIN_TEMPLATE_VALIDATION_TITLE');

		$app->getDocument()->addScriptDeclaration('
			document.addEventListener("DOMContentLoaded", function() {
				const form = document.querySelector("form[name=\'adminForm\']");
				if (!form) return;

				form.addEventListener("submit", function(e) {
					const htmlFields = [
						"jform_params_loginmail_body_html",
						"jform_params_invitemail_body_html",
						"jform_params_mail_admin_body_html",
						"jform_params_mail_approval_body_html",
						"jform_params_mail_rejection_body_html"
					];

					let warnings = [];
					htmlFields.forEach(function(fieldId) {
						const field = document.getElementById(fieldId);
						if (!field) return;

						const html = field.value;
						const parser = new DOMParser();
						const doc = parser.parseFromString(html, "text/html");
						const imgs = doc.getElementsByTagName("img");

						for (let img of imgs) {
							const src = img.getAttribute("src");
							if (!src) continue;

							// Check of src in /media/ of /images/ zit
							if (!src.includes("/media/") && !src.includes("/images/")) {
								warnings.push("Afbeelding: " + src + " moet in /media/ of /images/ folder staan");
								continue;
							}

							// Check of afbeelding te groot is (simpele check op URL, geen echte size check mogelijk in JS)
							// Dit is een waarschuwing voor externe URLs
							if (src.startsWith("http") && !src.includes(window.location.hostname)) {
								warnings.push("Afbeelding: " + src + " is extern en wordt niet embedded");
							}
						}
					});

					if (warnings.length > 0) {
						e.preventDefault();
						alert("' . addslashes($waarschuwingTitel) . '\\n\\n" + warnings.join("\\n"));
						return false;
					}
				});
			});
		');
	}

}