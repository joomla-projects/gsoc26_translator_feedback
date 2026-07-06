<?php

/**
 * @package     Joomla.Administrator
 * @subpackage  com_translations
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Component\Translations\Administrator\Controller;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Router\Route;

/**
 * Controller for the rules distiller.
 *
 * The distil task runs the distiller over one batch of pending feedback on demand,
 * the manual counterpart to the scheduled distiller task; the work is done by
 * DistillerModel.
 *
 * @since  0.4.0
 */
class DistillerController extends BaseController
{
    /**
     * Distil draft rules from the pending feedback, then return to the Rules view.
     *
     * @return  void
     *
     * @since   0.4.0
     */
    public function distill()
    {
        $this->checkToken();

        $app      = $this->app;
        $rulesUrl = Route::_('index.php?option=com_translations&view=rules', false);

        /** @var \Joomla\Component\Translations\Administrator\Model\DistillerModel $model */
        $model = $this->getModel('Distiller');

        try {
            $processed = $model->distill();

            if ($processed === 0) {
                $app->enqueueMessage(Text::_('COM_TRANSLATIONS_DISTILL_NONE'), 'info');
            } else {
                $app->enqueueMessage(Text::sprintf('COM_TRANSLATIONS_DISTILL_SUCCESS', $processed), 'message');
            }
        } catch (\Throwable $e) {
            $app->enqueueMessage($e->getMessage() ?: Text::_('COM_TRANSLATIONS_DISTILL_ERROR'), 'error');
        }

        $this->setRedirect($rulesUrl);
    }
}
