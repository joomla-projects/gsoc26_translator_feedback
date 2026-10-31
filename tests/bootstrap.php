<?php

/**
 * Test bootstrap.
 *
 * The suite covers this package's own logic, so it loads the package's classes and
 * nothing else: there is no Joomla installation here, and no attempt to imitate one.
 * What the classes need from the framework comes from the framework packages the CMS
 * itself ships (joomla/registry, joomla/event); what they need from the CMS is
 * declared in tests/Stub/joomla.php, which exists only so a class can be loaded.
 *
 * Whether this package still fits the CMS it is installed into is a question about
 * signatures rather than behaviour, and PHPStan answers it: phpstan.neon scans a real
 * Joomla in joomla/.
 *
 * @package     Joomla.Tests
 * @subpackage  com_translations
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

// The package's classes guard themselves with _JEXEC and die without it. Defining it is
// not bootstrapping Joomla: it is a plain constant, and the classes still load nothing
// from the CMS.
if (!\defined('_JEXEC')) {
    \define('_JEXEC', 1);
}

// Where the package lives in the repository, in the layout Joomla installs it into.
// ContentTypesHelper reads its map from under JPATH_ADMINISTRATOR, so the tests read
// the map that ships rather than a copy of it.
if (!\defined('TRANSLATIONS_TEST_SRC')) {
    \define('TRANSLATIONS_TEST_SRC', \dirname(__DIR__) . '/src');
}

if (!\defined('JPATH_ADMINISTRATOR')) {
    \define('JPATH_ADMINISTRATOR', TRANSLATIONS_TEST_SRC . '/administrator');
}

if (!\defined('JPATH_SITE')) {
    \define('JPATH_SITE', TRANSLATIONS_TEST_SRC);
}

if (!\defined('JPATH_ROOT')) {
    \define('JPATH_ROOT', TRANSLATIONS_TEST_SRC);
}

require_once \dirname(__DIR__) . '/vendor/autoload.php';

// Before any package class is autoloaded, so a parent class it extends is there.
require_once __DIR__ . '/Stub/joomla.php';
