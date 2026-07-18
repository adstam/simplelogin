<?php
/**
 * @package   Simplelogin
 * @author    Ad Stam
 * @copyright Copyright (C) 2026 Ad Stam. All rights reserved.
 * @license   GNU General Public License version 2 or later; see LICENSE.txt
 * @link      https://demo.adstam.nl
 */

defined('_JEXEC') or die;

use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use Joomla\CMS\Extension\PluginInterface;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\Event\DispatcherInterface;
use Joomla\CMS\Mailer\MailerInterface;

use StamPlusJ\Plugin\System\Simplelogin\Extension\Simplelogin;
use StamPlusJ\Plugin\System\Simplelogin\Service\MailServiceInterface;
use StamPlusJ\Plugin\System\Simplelogin\Service\MailService;

return new class implements ServiceProviderInterface
{

    public function register(Container $container)
    {
		$container->set(
			MailServiceInterface::class,
			function (Container $container) {
				return new MailService();
			}
		);

        // Register the plugin
        $container->set(
            PluginInterface::class,
            function (Container $container) {
                $plugin = PluginHelper::getPlugin('system', 'simplelogin');

                return new Simplelogin(
                    $container->get(DispatcherInterface::class),
                    (array) $plugin,
                    $container->get(MailServiceInterface::class)
                );
            }
        );
    }

};