<?php
defined('_JEXEC') or die;

use Joomla\CMS\Factory;

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
        $cacheFile = JPATH_CACHE . '/autoload_psr4.php';
        if (file_exists($cacheFile)) {
            @unlink($cacheFile);
        }

        $app = Factory::getApplication();
        $app->cleanCache('_system');
        $app->cleanCache('com_plugins');
    }
}