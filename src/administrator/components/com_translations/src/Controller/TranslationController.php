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
 * Controller for translating queued items.
 *
 * The translate task turns one source item into an unpublished draft for one
 * target language; the work is done by TranslationModel.
 *
 * @since  0.3.0
 */
class TranslationController extends BaseController
{
    /**
     * Translate one source item into one target language.
     *
     * The trigger is a plain link, so the form token is checked on the query string.
     *
     * @return  void
     *
     * @since   0.3.0
     */
    public function translate()
    {
        $this->checkToken('get');

        $app            = $this->app;
        $sourceItemId   = $this->input->getInt('id');
        $targetLanguage = $this->input->getCmd('target');
        $contentType    = $this->input->getCmd('contentType');
        $queueUrl       = Route::_('index.php?option=com_translations&view=queue', false);

        if ($sourceItemId === 0 || $targetLanguage === '' || $contentType === '') {
            $app->enqueueMessage(Text::_('COM_TRANSLATIONS_TRANSLATE_ERROR'), 'error');
            $this->setRedirect($queueUrl);

            return;
        }

        /** @var \Joomla\Component\Translations\Administrator\Model\TranslationModel $model */
        $model = $this->getModel('Translation');

        try {
            $model->translate($sourceItemId, $targetLanguage, $contentType, $app);
            $app->enqueueMessage(Text::sprintf('COM_TRANSLATIONS_TRANSLATE_SUCCESS', $targetLanguage), 'message');
        } catch (\Throwable $e) {
            $app->enqueueMessage($e->getMessage() ?: Text::_('COM_TRANSLATIONS_TRANSLATE_ERROR'), 'error');
        }

        $this->setRedirect($queueUrl);
    }

    /**
     * Clear the "no need for translation" flag so an item can be translated again.
     *
     * The trigger is a plain link, so the form token is checked on the query string.
     *
     * @return  void
     *
     * @since   0.3.0
     */
    public function allowTranslation()
    {
        $this->checkToken('get');

        $app          = $this->app;
        $sourceItemId = $this->input->getInt('id');
        $contentType  = $this->input->getCmd('contentType');
        $queueUrl     = Route::_('index.php?option=com_translations&view=queue', false);

        if ($sourceItemId === 0 || $contentType === '') {
            $app->enqueueMessage(Text::_('COM_TRANSLATIONS_ALLOW_TRANSLATION_ERROR'), 'error');
            $this->setRedirect($queueUrl);

            return;
        }

        /** @var \Joomla\Component\Translations\Administrator\Model\TranslationModel $model */
        $model = $this->getModel('Translation');
        $model->allowTranslation($sourceItemId, $contentType);

        $app->enqueueMessage(Text::_('COM_TRANSLATIONS_ALLOW_TRANSLATION_SUCCESS'), 'message');
        $this->setRedirect($queueUrl);
    }
}
