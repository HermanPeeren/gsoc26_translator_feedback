<?php

/**
 * The CMS classes this package extends, declared so its classes can be loaded.
 *
 * These are placeholders, not imitations: they carry no behaviour, and no test asserts
 * anything about them. A test that needed one of them to *do* something would be testing
 * Joomla rather than this package, which is not what this suite is for.
 *
 * Nothing here checks that the package still matches the real class it extends. PHPStan
 * does that, against a real Joomla in joomla/.
 *
 * @package     Joomla.Tests
 * @subpackage  com_translations
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Joomla\CMS\Plugin
{
    if (!class_exists(CMSPlugin::class, false)) {
        /**
         * Placeholder for the plugin base class.
         */
        class CMSPlugin
        {
            /**
             * The plugin's parameters, set by the CMS when it instantiates the plugin.
             *
             * @var  \Joomla\Registry\Registry|null
             */
            protected $params;
        }
    }
}

namespace Joomla\CMS\MVC\Model
{
    if (!class_exists(BaseDatabaseModel::class, false)) {
        /**
         * Placeholder for the database model base class.
         */
        class BaseDatabaseModel
        {
        }
    }

    if (!class_exists(FormModel::class, false)) {
        /**
         * Placeholder for the form model base class.
         */
        class FormModel extends BaseDatabaseModel
        {
        }
    }
}
