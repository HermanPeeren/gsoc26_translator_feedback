<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  Task.TranslationsLanguageFiles
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use Joomla\CMS\Extension\PluginInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\Database\DatabaseInterface;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use Joomla\Plugin\Task\TranslationsLanguageFiles\Extension\TranslationsLanguageFiles;

return new class () implements ServiceProviderInterface {
    /**
     * Registers the service provider with a DI container.
     *
     * @param   Container  $container  The DI container.
     *
     * @return  void
     *
     * @since   1.0.0
     */
    public function register(Container $container)
    {
        $container->set(
            PluginInterface::class,
            $container->lazy(TranslationsLanguageFiles::class, function (Container $container) {
                $plugin = new TranslationsLanguageFiles(
                    (array) PluginHelper::getPlugin('task', 'translationslanguagefiles'),
                    $container->get(DatabaseInterface::class)
                );
                $plugin->setApplication(Factory::getApplication());

                return $plugin;
            })
        );
    }
};
