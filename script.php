// script.php (in de root van je plugin)
<?php
defined('_JEXEC') or die;

use Joomla\CMS\Factory;

class PlgSystemSimpleloginInstallerScript
{
    public function postflight(string $type, \stdClass $parent): void
    {
        if ($type === 'install' || $type === 'update') {
            $this->clearCaches();
        }
    }

    private function clearCaches(): void
    {
        // Verwijder Joomla's autoloader cache
        $cacheFile = JPATH_CACHE . '/autoload_psr4.php';
        if (file_exists($cacheFile)) {
            @unlink($cacheFile);
        }

        // Leeg ook de CMS cache
        $app = Factory::getApplication();
        $app->cleanCache('_system');
        $app->cleanCache('com_plugins');
    }
}