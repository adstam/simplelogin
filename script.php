<?php
/**
 * @package   Simplelogin
 * @author    Ad Stam
 * @copyright Copyright (C) 2026 Ad Stam. All rights reserved.
 * @license   GNU General Public License version 2 or later; see LICENSE.txt
 * @link      https://demo.adstam.nl
 */
defined('_JEXEC') or die;

use Joomla\CMS\Cache\Cache;

class PlgSystemSimpleloginInstallerScript
{
    public function postflight(string $type, $parent): void
    {
        if ($type === 'install' || $type === 'update') {
            $this->clearCaches();
        }
    }

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

		public function uninstall($parent): void
		{
        $this->clearCaches();
    }
		
}

