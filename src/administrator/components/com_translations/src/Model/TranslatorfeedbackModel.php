<?php

/**
 * @package     Joomla.Administrator
 * @subpackage  com_translations
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Component\Translations\Administrator\Model;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use Joomla\CMS\Factory;
use Joomla\CMS\Form\Form;
use Joomla\CMS\Language\Associations;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Factory\MVCFactoryServiceInterface;
use Joomla\CMS\MVC\Model\FormModel;
use Joomla\Component\Content\Administrator\Model\ArticleModel;
use Joomla\Database\ParameterType;

/**
 * Side-by-side translation feedback model.
 *
 * Loads one source-language article and its translation in a single target
 * language for display. The queue tables store no pointer to the draft article,
 * so the translation is resolved from the source article through Joomla
 * associations (the same lookup the core components use.)
 *
 * @since  0.2.0
 */
class TranslatorfeedbackModel extends FormModel
{
    /**
     * Cached source + translation pair (see getItem()).
     *
     * @var    object|null
     * @since  0.2.0
     */
    private $item;

    /**
     * Read the request state: which source article and which target language.
     *
     * @return  void
     *
     * @since   0.2.0
     */
    protected function populateState()
    {
        $input = Factory::getApplication()->getInput(); // No DI: models are not application aware.

        $this->setState('content_id', $input->getInt('id'));
        $this->setState('target_language', $input->getCmd('target'));
    }

    /**
     * Load the translation feedback form.
     *
     * @param   array    $data      Data for the form.
     * @param   boolean  $loadData  True to load the form's own data.
     *
     * @return  Form  The form object.
     *
     * @since   0.2.0
     */
    public function getForm($data = [], $loadData = true)
    {
        // Build the form from forms/translatorfeedback.xml; 'jform' namespaces the fields, load_data triggers loadFormData() below.
        return $this->loadForm('com_translations.translatorfeedback', 'translatorfeedback', ['control' => 'jform', 'load_data' => $loadData]);
    }

    /**
     * Bind the translation article into the form fields.
     *
     * @return  array  The form data (empty when there is no translation yet).
     *
     * @since   0.2.0
     */
    protected function loadFormData()
    {
        $item = $this->getItem();

        if (empty($item->translation_article)) {
            return [];
        }

        return [
            'translation_title'     => $item->translation_article->title,
            'translation_introtext' => $item->translation_article->introtext,
            'translation_fulltext'  => $item->translation_article->fulltext,
        ];
    }

    /**
     * Save the edited translation into its draft #__content article.
     *
     * The write is delegated to com_content's ArticleModel so the normal workflow
     * and versioning run; only the translated fields are overwritten on the existing
     * article. The translation article is resolved (via associations) by getItem().
     *
     * @param   array  $data  Submitted form values (translation_title/introtext/fulltext).
     *
     * @return  boolean  True on success.
     *
     * @since   0.2.0
     */
    public function save(array $data): bool
    {
        $item = $this->getItem();

        if (empty($item->translation_article)) {
            throw new \RuntimeException(Text::_('COM_TRANSLATIONS_TRANSLATOR_FEEDBACK_NO_TRANSLATION'));
        }

        $translationId = (int) $item->translation_article->id;

        // Reuse com_content's Article admin model - its save() runs the workflow + versioning for us.
        $component = Factory::getApplication()->bootComponent('com_content');

        // com_content is booted as a generic component, so make sure it can give us an MVC factory before we ask for one.
        if (!$component instanceof MVCFactoryServiceInterface) {
            throw new \RuntimeException(Text::_('COM_TRANSLATIONS_TRANSLATOR_FEEDBACK_SAVE_ERROR'));
        }

        // 'ignore_request' => true: we hand this model our own data, so it must not read state from the current request.
        $articleModel = $component->getMVCFactory()->createModel('Article', 'Administrator', ['ignore_request' => true]);

        // And make sure that factory built the article model we expect before we call save() on it.
        if (!$articleModel instanceof ArticleModel) {
            throw new \RuntimeException(Text::_('COM_TRANSLATIONS_TRANSLATOR_FEEDBACK_SAVE_ERROR'));
        }

        // Load the raw article row - plain column values, avoiding the computed objects getItem() adds (e.g. tags).
        $db    = $this->getDatabase();
        $query = $db->getQuery(true)
            ->select('*')
            ->from($db->quoteName('#__content'))
            ->where($db->quoteName('id') . ' = :id')
            ->bind(':id', $translationId, ParameterType::INTEGER);
        $db->setQuery($query);

        $article = $db->loadAssoc();

        if ($article === null) {
            throw new \RuntimeException(Text::_('COM_TRANSLATIONS_TRANSLATOR_FEEDBACK_NO_TRANSLATION'));
        }

        // Overwrite only the three translated fields; for anything not submitted, keep the article's current value.
        $introtext = $data['translation_introtext'] ?? ($article['introtext'] ?? '');
        $fulltext  = $data['translation_fulltext'] ?? ($article['fulltext'] ?? '');

        $article['title']     = $data['translation_title'] ?? ($article['title'] ?? '');
        $article['introtext'] = $introtext;
        $article['fulltext']  = $fulltext;

        // com_content's edit form works on a single combined body; keep it consistent with the two columns.
        $article['articletext'] = trim((string) $fulltext) !== ''
            ? $introtext . '<hr id="system-readmore">' . $fulltext
            : $introtext;

        return (bool) $articleModel->save($article);
    }

    /**
     * Get the source article and its target-language translation.
     *
     * @return  object  { content_id, target_language, source_article, translation_article } - the articles may be null.
     *
     * @since   0.2.0
     */
    public function getItem()
    {
        if ($this->item !== null) {
            return $this->item;
        }

        $contentId      = (int) $this->getState('content_id');
        $targetLanguage = (string) $this->getState('target_language');

        $sourceArticle      = $this->loadArticle($contentId);
        $translationArticle = null;

        // The queue tables hold no draft pointer, so resolve the translation from the
        // source article through #__associations (Associations::getAssociations returns
        // one entry per language, keyed by language code, each carrying ->id).
        if ($sourceArticle !== null && $targetLanguage !== '' && Associations::isEnabled()) {
            $associations = Associations::getAssociations('com_content', '#__content', 'com_content.item', $contentId);

            if (isset($associations[$targetLanguage])) {
                $translationArticle = $this->loadArticle((int) $associations[$targetLanguage]->id);
            }
        }

        $this->item = (object) [
            'content_id'          => $contentId,
            'target_language'     => $targetLanguage,
            'source_article'      => $sourceArticle,
            'translation_article' => $translationArticle,
        ];

        return $this->item;
    }

    /**
     * Load a single article's display fields from #__content.
     *
     * @param   integer  $id  Article id.
     *
     * @return  object|null  The article row, or null when not found.
     *
     * @since   0.2.0
     */
    private function loadArticle(int $id)
    {
        if ($id <= 0) {
            return null;
        }

        $db    = $this->getDatabase();
        $query = $db->getQuery(true)
            ->select($db->quoteName(['id', 'title', 'introtext', 'fulltext', 'language']))
            ->from($db->quoteName('#__content'))
            ->where($db->quoteName('id') . ' = :id')
            ->bind(':id', $id, ParameterType::INTEGER);

        $db->setQuery($query);

        return $db->loadObject() ?: null;
    }
}
