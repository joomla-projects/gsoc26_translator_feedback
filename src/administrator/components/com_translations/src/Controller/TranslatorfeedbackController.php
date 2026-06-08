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
 * Controller for the side-by-side translation feedback view.
 *
 * Handles saving the edited translation back into its draft #__content article
 * (the actual write is delegated to com_content's ArticleModel by TranslatorfeedbackModel).
 *
 * Extends BaseController, not FormController: we own no #__content table, so there
 * is no record lifecycle (checkout/edit-state) for this controller to manage - it
 * just validates the request, delegates the write to the model, and redirects.
 *
 * @since  0.2.0
 */
class TranslatorfeedbackController extends BaseController
{
    /**
     * Save the edited translation, then return to the translation feedback view.
     *
     * @return  void
     *
     * @since   0.2.0
     */
    public function save()
    {
        $this->checkToken();

        $app            = $this->app;
        $contentId      = $this->input->getInt('id');
        $targetLanguage = $this->input->getCmd('target');

        // Editor fields carry HTML, read raw so the markup is preserved (admin only screen).
        $form = $this->input->post->get('jform', [], 'raw');

        /** @var \Joomla\Component\Translations\Administrator\Model\TranslatorfeedbackModel $model */
        $model = $this->getModel('Translatorfeedback');

        $error = null;

        try {
            $saved = $model->save(\is_array($form) ? $form : []);
        } catch (\Throwable $e) {
            $saved = false;
            $error = $e->getMessage();
        }

        if ($saved) {
            $app->enqueueMessage(Text::_('COM_TRANSLATIONS_TRANSLATOR_FEEDBACK_SAVE_SUCCESS'), 'message');
        } else {
            $app->enqueueMessage($error ?: Text::_('COM_TRANSLATIONS_TRANSLATOR_FEEDBACK_SAVE_ERROR'), 'error');
        }

        $url = 'index.php?option=com_translations&view=translatorfeedback&layout=edit&id=' . $contentId
            . '&target=' . urlencode($targetLanguage);

        $this->setRedirect(Route::_($url, false));
    }

    /**
     * Leave the translation feedback view without saving.
     *
     * @return  void
     *
     * @since   0.2.0
     */
    public function cancel()
    {
        $this->checkToken();

        $this->setRedirect(Route::_('index.php?option=com_translations&view=queue', false));
    }
}
