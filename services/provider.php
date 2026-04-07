<?php

defined('_JEXEC') or die;

use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use Joomla\CMS\Extension\PluginInterface;
use Joomla\CMS\Factory;
use Adstam\Plugin\System\Simplelogin\Extension\Simplelogin;

return new class implements ServiceProviderInterface {

    public function register(Container $container)
    {
        $container->set(
            PluginInterface::class,
            function (Container $container) {

                $plugin = Factory::getApplication()
                    ->getPluginManager()
                    ->getPlugin('system', 'simplelogin');

                return new Simplelogin(
                    $container->get('dispatcher'),
                    (array) $plugin,
                    $container->get(\Joomla\CMS\Application\CMSApplicationInterface::class),
                    $container->get(\Joomla\Database\DatabaseInterface::class),
                    $container->get(\Joomla\CMS\User\UserFactoryInterface::class),
                    $container->get(\Joomla\CMS\Mail\MailFactoryInterface::class)
                );
            }
        );
    }
};
