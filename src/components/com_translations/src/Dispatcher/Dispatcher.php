<?php

/**
 * @package     Joomla.Site
 * @subpackage  com_translations
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Component\Translations\Site\Dispatcher;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use Joomla\CMS\Access\Exception\NotAllowed;
use Joomla\CMS\Dispatcher\ComponentDispatcher;
use Joomla\CMS\Form\Form;
use Joomla\CMS\MVC\Controller\BaseController;

/**
 * ComponentDispatcher class for com_translations.
 *
 * Translators work on the same screens in the site as in the administrator, so a site request is
 * served by the administrator controllers, models, views and templates rather than by a second
 * set written for the site. Pointing the controller at the administrator component is what makes
 * that happen; the language and form lookups that go with it are redirected here as well,
 * because both resolve against the site by default.
 *
 * @since  0.9.0
 */
class Dispatcher extends ComponentDispatcher
{
    /**
     * Load the language.
     *
     * @return  void
     *
     * @since   0.9.0
     */
    protected function loadLanguage()
    {
        // The component keeps one set of strings, in the administrator.
        $this->app->getLanguage()->load($this->option, JPATH_ADMINISTRATOR) ||
        $this->app->getLanguage()->load($this->option, JPATH_SITE);
    }

    /**
     * Method to check component access permission.
     *
     * @return  void
     *
     * @since   0.9.0
     *
     * @throws  NotAllowed
     */
    protected function checkAccess()
    {
        // The site applies no permission of its own, so the component applies the one the
        // administrator would have applied.
        if (!$this->app->getIdentity()->authorise('core.manage', $this->option)) {
            throw new NotAllowed($this->app->getLanguage()->_('JERROR_ALERTNOAUTHOR'), 403);
        }
    }

    /**
     * Get a controller from the component.
     *
     * @param   string  $name    Controller name
     * @param   string  $client  Optional client (like Administrator, Site etc.)
     * @param   array   $config  Optional controller config
     *
     * @return  BaseController
     *
     * @since   0.9.0
     */
    public function getController(string $name, string $client = '', array $config = []): BaseController
    {
        $config['base_path'] = JPATH_ADMINISTRATOR . '/components/' . $this->option;
        $client              = 'Administrator';

        // Forms are searched for under JPATH_COMPONENT, which on a site request is the site
        // folder, so the administrator forms are added to the search path.
        Form::addFormPath($config['base_path'] . '/forms');

        return parent::getController($name, $client, $config);
    }
}
