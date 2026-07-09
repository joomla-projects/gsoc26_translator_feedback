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
 * Handles opening, saving and closing the editor. The draft being edited is a real
 * item in its managing component's table, so the writes (save, check-out, check-in)
 * are delegated to that component's model by TranslatorfeedbackModel.
 *
 * Extends BaseController, not FormController: the component owns no table of its own,
 * so there is no record of ours to run a form lifecycle over. The draft's own lifecycle
 * (check-out on open, check-in on close) is driven here through the managing model.
 *
 * @since  0.2.0
 */
class TranslatorfeedbackController extends BaseController
{
    /**
     * Open the editor: check the draft out, then show the side-by-side view.
     *
     * Reached from the queue. When another user already holds the draft the check-out is
     * refused and the request is sent back to the queue with a notice, as core does for a
     * locked record - the editor is never opened for a second user.
     *
     * @return  void
     *
     * @since   0.4.0
     */
    public function edit()
    {
        // The trigger is a plain link, so the form token is checked on the query string.
        $this->checkToken('get');

        $contentId      = $this->input->getInt('id');
        $targetLanguage = $this->input->getCmd('target');
        $contentType    = $this->input->getCmd('contentType');

        /** @var \Joomla\Component\Translations\Administrator\Model\TranslatorfeedbackModel $model */
        $model = $this->getModel('Translatorfeedback');

        // Lock the draft; when another user already holds it, block and return to the queue.
        if (!$model->checkoutDraft($this->app)) {
            $this->app->enqueueMessage(Text::_('COM_TRANSLATIONS_TRANSLATOR_FEEDBACK_LOCKED'), 'error');
            $this->setRedirect(Route::_('index.php?option=com_translations&view=queue', false));

            return;
        }

        $this->setRedirect(Route::_($this->editUrl($contentId, $targetLanguage, $contentType), false));
    }

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
        $contentType    = $this->input->getCmd('contentType');

        // Editor fields carry HTML, read raw so the markup is preserved (admin only screen).
        $form = $this->input->post->get('jform', [], 'raw');

        /** @var \Joomla\Component\Translations\Administrator\Model\TranslatorfeedbackModel $model */
        $model = $this->getModel('Translatorfeedback');

        $error = null;

        try {
            $saved = $model->save(\is_array($form) ? $form : [], $app);
        } catch (\Throwable $e) {
            $saved = false;
            $error = $e->getMessage();
        }

        if ($saved) {
            $app->enqueueMessage(Text::_('COM_TRANSLATIONS_TRANSLATOR_FEEDBACK_SAVE_SUCCESS'), 'message');
        } else {
            $app->enqueueMessage($error ?: Text::_('COM_TRANSLATIONS_TRANSLATOR_FEEDBACK_SAVE_ERROR'), 'error');
        }

        // Save keeps the editor open (apply), so the draft stays checked out for the same user.
        $this->setRedirect(Route::_($this->editUrl($contentId, $targetLanguage, $contentType), false));
    }

    /**
     * Leave the translation feedback view without saving, releasing the draft.
     *
     * @return  void
     *
     * @since   0.2.0
     */
    public function cancel()
    {
        $this->checkToken();

        /** @var \Joomla\Component\Translations\Administrator\Model\TranslatorfeedbackModel $model */
        $model = $this->getModel('Translatorfeedback');
        $model->checkinDraft($this->app);

        $this->setRedirect(Route::_('index.php?option=com_translations&view=queue', false));
    }

    /**
     * Build the editor URL for one source item, target language and content type.
     *
     * @param   integer  $contentId       The source item id.
     * @param   string   $targetLanguage  The target language code.
     * @param   string   $contentType     The content type key.
     *
     * @return  string  The editor route.
     *
     * @since   0.4.0
     */
    private function editUrl(int $contentId, string $targetLanguage, string $contentType): string
    {
        return 'index.php?option=com_translations&view=translatorfeedback&layout=edit&id=' . $contentId
            . '&target=' . urlencode($targetLanguage) . '&contentType=' . urlencode($contentType);
    }
}
