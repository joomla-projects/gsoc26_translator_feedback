<?php

/**
 * @package     Joomla.Administrator
 * @subpackage  com_translations
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Installer\InstallerAdapter;
use Joomla\CMS\Installer\InstallerScriptInterface;
use Joomla\Component\Translations\Administrator\Helper\QueueBackfillHelper;
use Joomla\Database\DatabaseInterface;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;

/**
 * Installation script for com_translations.
 *
 * On a first install it reflects translations that already exist through
 * #__associations in the queue, so they are not shown as untranslated.
 *
 * There exists an InstallerScriptTrait since Joomla 6.0.0, but we are implementing
 * InstallerScriptInterface, due to Joomla 5 compatibility.
 *
 * @since  0.7.0
 */
return new class () implements ServiceProviderInterface {
    /**
     * Registers the installer script with the DI container.
     *
     * @param   Container  $container  The DI container.
     *
     * @return  void
     *
     * @since   0.7.0
     */
    public function register(Container $container)
    {
        $container->set(
            InstallerScriptInterface::class,
            new class ($container->get(DatabaseInterface::class)) implements InstallerScriptInterface {
                /**
                 * The database driver.
                 *
                 * @var    DatabaseInterface
                 * @since  0.7.0
                 */
                private DatabaseInterface $db;

                /**
                 * Constructor.
                 *
                 * @param   DatabaseInterface  $db  The database driver.
                 *
                 * @since   0.7.0
                 */
                public function __construct(DatabaseInterface $db)
                {
                    $this->db = $db;
                }

                /**
                 * Runs after the extension is installed.
                 *
                 * @param   InstallerAdapter  $adapter  The adapter calling this method.
                 *
                 * @return  boolean  True on success.
                 *
                 * @since   0.7.0
                 */
                public function install(InstallerAdapter $adapter): bool
                {
                    return true;
                }

                /**
                 * Runs after the extension is updated.
                 *
                 * @param   InstallerAdapter  $adapter  The adapter calling this method.
                 *
                 * @return  boolean  True on success.
                 *
                 * @since   0.7.0
                 */
                public function update(InstallerAdapter $adapter): bool
                {
                    return true;
                }

                /**
                 * Runs after the extension is uninstalled.
                 *
                 * @param   InstallerAdapter  $adapter  The adapter calling this method.
                 *
                 * @return  boolean  True on success.
                 *
                 * @since   0.7.0
                 */
                public function uninstall(InstallerAdapter $adapter): bool
                {
                    return true;
                }

                /**
                 * Runs before the install, update or uninstall procedure commences.
                 *
                 * @param   string            $type     The type of change (install, discover_install, update, uninstall).
                 * @param   InstallerAdapter  $adapter  The adapter calling this method.
                 *
                 * @return  boolean  True on success.
                 *
                 * @since   0.7.0
                 */
                public function preflight(string $type, InstallerAdapter $adapter): bool
                {
                    return true;
                }

                /**
                 * Runs after the install, update or uninstall procedure commences.
                 *
                 * @param   string            $type     The type of change (install, discover_install, update, uninstall).
                 * @param   InstallerAdapter  $adapter  The adapter calling this method.
                 *
                 * @return  boolean  True on success.
                 *
                 * @since   0.7.0
                 */
                public function postflight(string $type, InstallerAdapter $adapter): bool
                {
                    // Seed the queue from pre-existing associations once, on a first install.
                    if ($type !== 'install' && $type !== 'discover_install') {
                        return true;
                    }

                    // The component namespace is not registered in the autoloader during its own
                    // first install, so load the helpers by path.
                    $helpers = JPATH_ADMINISTRATOR . '/components/com_translations/src/Helper';
                    require_once $helpers . '/ContentTypesHelper.php';
                    require_once $helpers . '/QueueBackfillHelper.php';

                    $sourceLanguage = (string) ComponentHelper::getParams('com_translations')->get('source_language', 'en-GB');

                    QueueBackfillHelper::backfill($this->db, $sourceLanguage);

                    return true;
                }
            }
        );
    }
};
