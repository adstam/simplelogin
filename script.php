<?php
/**
 * @package    Simplelogin
 * @author     Ad Stam
 * @copyright  Copyright (C) 2026 Ad Stam. All rights reserved.
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 * @link       https://demo.adstam.nl
 */

defined('_JEXEC') or die;

use Joomla\CMS\Cache\Cache;
use Joomla\CMS\Factory;
use Joomla\CMS\Table\Table;

class PlgSystemSimpleloginInstallerScript
{
    /**
     * Draait na installatie, update of discover_install.
     *
     * @param   string  $type    Type actie (install, update, discover_install)
     * @param   object  $parent  Installer object
     *
     * @return  void
     */
    public function postflight(string $type, $parent): void
    {
        if (in_array($type, ['install', 'discover_install'], true)) {
            $this->setDefaultParams();
        }

        if (in_array($type, ['install', 'update', 'discover_install'], true)) {
            $this->clearCaches();
        }
    }

    /**
     * Draait bij het de-installeren van de plugin.
     *
     * @param   object  $parent  Installer object
     *
     * @return  void
     */
    public function uninstall($parent): void
    {
        $this->clearCaches();
    }

    /**
     * Vult de params JSON in #__extensions aan met de HTML-defaults 
     * zodat de mails direct na installatie werken zonder dat de beheerder 
     * eerst de instellingen hoeft op te slaan.
     *
     * @return  void
     */
    private function setDefaultParams(): void
    {
        $db = Factory::getDbo();
        
        // 1. Haal de huidige params op uit de database
        $query = $db->getQuery(true)
            ->select($db->quoteName('params'))
            ->from($db->quoteName('#__extensions'))
            ->where($db->quoteName('element') . ' = ' . $db->quote('simplelogin'))
            ->where($db->quoteName('folder') . ' = ' . $db->quote('system'));
        
        $db->setQuery($query);
        $paramsJson = $db->loadResult();
        $params = json_decode($paramsJson ?: '{}', true);

        // 2. Definieer de standaard HTML-templates voor alle mailtypes
        $defaults = [
            'mail_login_body_html'     => '<p>#name,</p><p>Here\'s your link to login:<br><br><a href="#link">Click here to login</a></p><p>Please note that the link is valid for #expiry minutes.</p>',
            'mail_invite_body_html'    => '<p>#name,</p><p>Here\'s your link to confirm your registration:<br><br><a href="#link">Click here to confirm your account</a></p><p>Please note that the link is valid for #expiry minutes.</p>',
            'mail_admin_body_html'     => '<p>#name (#email) has registered on #sitename.</p><p>If admin approval is needed goto to the plugin and decide your action for this new user.</p>',
            'mail_approval_body_html'  => '<p>#name,</p><p>Your registration has been approved. You can now login here:<br><br><a href="#link">Click here to login</a></p><p>This link is valid for #expiry minutes.</p>',
            'mail_rejection_body_html' => '<p>#name,</p><p>Your registration has been rejected.</p><p>Reason: #reason</p>',
        ];

        // 3. Vul alleen aan wat er op dit moment nog niet in de JSON staat
        $updated = false;
        foreach ($defaults as $key => $val) {
            if (!isset($params[$key]) || $params[$key] === '') {
                $params[$key] = $val;
                $updated = true;
            }
        }

        // 4. Sla de bijgewerkte JSON weer op als er iets is toegevoegd
        if ($updated) {
            $table = Table::getInstance('extension');
            $id = $table->find(['element' => 'simplelogin', 'folder' => 'system']);
            
            if ($id && $table->load($id)) {
                $table->params = json_encode($params);
                $table->store();
            }
        }
    }

    /**
     * Schoont de autoloader cache, invalidate OPcache en leegt de Joomla-caches.
     *
     * @return  void
     */
    private function clearCaches(): void
    {
        // Gebruik expliciet het administrator cache pad waar de autoloader staat
        $cacheFile = JPATH_ADMINISTRATOR . '/cache/autoload_psr4.php';
        
        if (file_exists($cacheFile)) {
            @unlink($cacheFile);
            
            // Dwing PHP om de OPcache voor dit specifieke bestand te resetten
            if (function_exists('opcache_invalidate')) {
                @opcache_invalidate($cacheFile, true);
            }
        }

        // Leeg Joomla cache via Cache klasse
        $options = ['defaultgroup' => '_system'];
        $cache = Cache::getInstance('callback', $options);
        $cache->clean();

        $options = ['defaultgroup' => 'com_plugins'];
        $cache = Cache::getInstance('callback', $options);
        $cache->clean();
    }
}