<?php
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
        // Verwijder autoloader cache
        $cacheFile = JPATH_CACHE . '/autoload_psr4.php';
        if (file_exists($cacheFile)) {
            @unlink($cacheFile);
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