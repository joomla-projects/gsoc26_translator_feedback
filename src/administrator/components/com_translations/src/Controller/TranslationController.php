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
 * Controller for translating queued articles.
 *
 * The translate task turns one source article into an unpublished draft for one
 * target language; the work is done by TranslationModel.
 *
 * @since  0.3.0
 */
class TranslationController extends BaseController
{
    /**
     * Content type the queue trigger translates; comes from the request once the queue carries more than articles.
     *
     * @var    string
     * @since  0.4.0
     */
    private const CONTENT_TYPE = 'com_content.article';

    /**
     * Translate one source article into one target language.
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

        $app             = $this->app;
        $sourceArticleId = $this->input->getInt('id');
        $targetLanguage  = $this->input->getCmd('target');
        $queueUrl        = Route::_('index.php?option=com_translations&view=queue', false);

        if ($sourceArticleId === 0 || $targetLanguage === '') {
            $app->enqueueMessage(Text::_('COM_TRANSLATIONS_TRANSLATE_ERROR'), 'error');
            $this->setRedirect($queueUrl);

            return;
        }

        /** @var \Joomla\Component\Translations\Administrator\Model\TranslationModel $model */
        $model = $this->getModel('Translation');

        try {
            $model->translate($sourceArticleId, $targetLanguage, self::CONTENT_TYPE, $app);
            $app->enqueueMessage(Text::sprintf('COM_TRANSLATIONS_TRANSLATE_SUCCESS', $targetLanguage), 'message');
        } catch (\Throwable $e) {
            $app->enqueueMessage($e->getMessage() ?: Text::_('COM_TRANSLATIONS_TRANSLATE_ERROR'), 'error');
        }

        $this->setRedirect($queueUrl);
    }

    /**
     * Clear the "no need for translation" flag so an article can be translated again.
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

        $app             = $this->app;
        $sourceArticleId = $this->input->getInt('id');
        $queueUrl        = Route::_('index.php?option=com_translations&view=queue', false);

        if ($sourceArticleId === 0) {
            $app->enqueueMessage(Text::_('COM_TRANSLATIONS_ALLOW_TRANSLATION_ERROR'), 'error');
            $this->setRedirect($queueUrl);

            return;
        }

        /** @var \Joomla\Component\Translations\Administrator\Model\TranslationModel $model */
        $model = $this->getModel('Translation');
        $model->allowTranslation($sourceArticleId, self::CONTENT_TYPE);

        $app->enqueueMessage(Text::_('COM_TRANSLATIONS_ALLOW_TRANSLATION_SUCCESS'), 'message');
        $this->setRedirect($queueUrl);
    }
}
