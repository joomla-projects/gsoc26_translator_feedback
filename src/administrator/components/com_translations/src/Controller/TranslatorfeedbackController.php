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
        $this->write('save', 'COM_TRANSLATIONS_TRANSLATOR_FEEDBACK_SAVE_SUCCESS', false);
    }

    /**
     * Save the edited translation and leave the translation feedback view.
     *
     * @return  void
     *
     * @since   0.7.0
     */
    public function save2close()
    {
        $this->write('save', 'COM_TRANSLATIONS_TRANSLATOR_FEEDBACK_SAVE_SUCCESS', true);
    }

    /**
     * Approve the reviewed translation and leave the translation feedback view.
     *
     * @return  void
     *
     * @since   0.7.0
     */
    public function approve()
    {
        $this->write('approve', 'COM_TRANSLATIONS_TRANSLATOR_FEEDBACK_APPROVE_SUCCESS', true);
    }

    /**
     * Approve and publish the reviewed translation, then leave the translation feedback view.
     *
     * @return  void
     *
     * @since   0.7.0
     */
    public function approveAndPublish()
    {
        $this->write('approveAndPublish', 'COM_TRANSLATIONS_TRANSLATOR_FEEDBACK_APPROVE_PUBLISH_SUCCESS', true);
    }

    /**
     * Run one of the model's write actions on the submitted translation.
     *
     * @param   string   $action          The model method to run: save, approve or approveAndPublish.
     * @param   string   $successMessage  The language key to enqueue when the action succeeds.
     * @param   boolean  $close           Whether to release the draft and return to the queue.
     *
     * @return  void
     *
     * @since   0.7.0
     */
    private function write(string $action, string $successMessage, bool $close): void
    {
        $this->checkToken();

        $app            = $this->app;
        $contentId      = $this->input->getInt('id');
        $targetLanguage = $this->input->getCmd('target');
        $contentType    = $this->input->getCmd('contentType');

        // Editor fields carry HTML, read raw so the markup is preserved (admin only screen).
        $form = $this->input->post->get('jform', [], 'raw');
        $form = \is_array($form) ? $form : [];

        /** @var \Joomla\Component\Translations\Administrator\Model\TranslatorfeedbackModel $model */
        $model = $this->getModel('Translatorfeedback');

        $error = null;

        try {
            $saved = match ($action) {
                'approve'           => $model->approve($form, $app),
                'approveAndPublish' => $model->approveAndPublish($form, $app),
                default             => $model->save($form, $app),
            };
        } catch (\Throwable $e) {
            $saved = false;
            $error = $e->getMessage();
        }

        if (!$saved) {
            $app->enqueueMessage($error ?: Text::_('COM_TRANSLATIONS_TRANSLATOR_FEEDBACK_SAVE_ERROR'), 'error');

            // Staying in the editor keeps the draft checked out, so the translator can retry.
            $this->setRedirect(Route::_($this->editUrl($contentId, $targetLanguage, $contentType), false));

            return;
        }

        $app->enqueueMessage(Text::_($successMessage), 'message');

        if (!$close) {
            $this->setRedirect(Route::_($this->editUrl($contentId, $targetLanguage, $contentType), false));

            return;
        }

        $model->checkinDraft($app);
        $this->setRedirect(Route::_('index.php?option=com_translations&view=queue', false));
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
     * Proxy for getModel.
     *
     * The prefix is set because a model is otherwise looked for under the name of the running
     * application, and the models all live in the administrator.
     *
     * @param   string  $name    The model name. Optional.
     * @param   string  $prefix  The class prefix. Optional.
     * @param   array   $config  Configuration array for the model. Optional.
     *
     * @return  \Joomla\CMS\MVC\Model\BaseDatabaseModel  The model.
     *
     * @since   0.9.0
     */
    public function getModel($name = 'Translatorfeedback', $prefix = 'Administrator', $config = [])
    {
        return parent::getModel($name, $prefix, $config);
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
