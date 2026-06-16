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

use Joomla\CMS\Application\CMSApplicationInterface;
use Joomla\CMS\Extension\ComponentInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Form\Form;
use Joomla\CMS\Language\Associations;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
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
     * @param   array                    $data         Submitted form values (translation_title/introtext/fulltext).
     * @param   CMSApplicationInterface  $application  The application, used to boot com_content.
     *
     * @return  boolean  True on success.
     *
     * @since   0.2.0
     */
    public function save(array $data, CMSApplicationInterface $application): bool
    {
        $item = $this->getItem();

        if (empty($item->translation_article)) {
            throw new \RuntimeException(Text::_('COM_TRANSLATIONS_TRANSLATOR_FEEDBACK_NO_TRANSLATION'));
        }

        $translationId = (int) $item->translation_article->id;

        // Reuse com_content's Article admin model - its save() runs the workflow + versioning for us.
        /** @var ComponentInterface&MVCFactoryServiceInterface $component */
        $component = $application->bootComponent('com_content');

        // 'ignore_request' => true: we hand this model our own data, so it must not read state from the current request.
        /** @var ArticleModel $articleModel */
        $articleModel = $component->getMVCFactory()->createModel('Article', 'Administrator', ['ignore_request' => true]);

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

        // Snapshot the translation as it stands now, before the overwrite below replaces it.
        // This is the machine draft (what was produced before this correction); once #__content
        // is overwritten these values exist nowhere else, so they must be captured for the feedback pair.
        $machineDraft = [
            'title'     => (string) ($article['title'] ?? ''), // those #__content columns can be null in theory so empty string rather than carry a null into feedback later.
            'introtext' => (string) ($article['introtext'] ?? ''),
            'fulltext'  => (string) ($article['fulltext'] ?? ''),
        ];

        // Overwrite only the three translated fields; for anything not submitted, keep the article's current value.
        $introtext = $data['translation_introtext'] ?? ($article['introtext'] ?? '');
        $fulltext  = $data['translation_fulltext'] ?? ($article['fulltext'] ?? '');

        $article['title']     = $data['translation_title'] ?? ($article['title'] ?? '');
        $article['introtext'] = $introtext;
        $article['fulltext']  = $fulltext;

        // The values now on the article are the human correction - the counterpart to the
        // machine draft captured above. Paired field by field, these become the feedback rows.
        $humanCorrection = [
            'title'     => (string) $article['title'],
            'introtext' => (string) $article['introtext'],
            'fulltext'  => (string) $article['fulltext'],
        ];

        // com_content's edit form works on a single combined body; keep it consistent with the two columns.
        $article['articletext'] = trim((string) $fulltext) !== ''
            ? $introtext . '<hr id="system-readmore">' . $fulltext
            : $introtext;

        $saved = (bool) $articleModel->save($article);

        // Record the correction as feedback - but only once the save actually persisted,
        // and only when there is a queue row to anchor it to.
        if ($saved) {
            $queueId = $this->getQueueId((int) $item->content_id);

            if ($queueId !== null) {
                $this->recordFeedback($queueId, $machineDraft, $humanCorrection);
            } else {
                // The translation feedback view is only reachable from a queue row, so a missing one is unexpected.
                // Log it rather than failing the save, since feedback has nothing to anchor to.
                Log::add(
                    sprintf('No queue row for content id %d; translation saved but feedback was not recorded.', (int) $item->content_id),
                    Log::WARNING,
                    'translations'
                );
            }
        }

        return $saved;
    }

    /**
     * Find the queue row a piece of feedback belongs to.
     *
     * Feedback is anchored to the queue row for the source article (one row per
     * source article, keyed by content type + id). Returns null when there is no
     * queue row, since feedback cannot be anchored without one.
     *
     * @param   integer  $contentId  The source article id.
     *
     * @return  integer|null  The queue row id, or null when none exists.
     *
     * @since   0.2.0
     */
    private function getQueueId(int $contentId): ?int
    {
        // Must match the content type the queue stores (see QueueModel::CONTENT_TYPE).
        $contentType = 'com_content.article';

        $db    = $this->getDatabase();
        $query = $db->getQuery(true)
            ->select($db->quoteName('id'))
            ->from($db->quoteName('#__translations_queue'))
            ->where($db->quoteName('content_type') . ' = :contentType')
            ->where($db->quoteName('content_id') . ' = :contentId')
            ->bind(':contentType', $contentType, ParameterType::STRING)
            ->bind(':contentId', $contentId, ParameterType::INTEGER);
        $db->setQuery($query);

        $queueId = $db->loadResult();

        return $queueId !== null ? (int) $queueId : null;
    }

    /**
     * Store the translator's correction as feedback: one preference pair per changed field.
     *
     * Each row pairs like with like - the source text, the machine draft and the human
     * correction for the same field - so the distiller later has clean, coherent pairs.
     * A field whose correction matches the machine draft is skipped (no change, nothing to
     * learn), the same principle Joomla versioning uses. status/created/context_tags/diff_data
     * fall back to their table defaults; diff_data is filled later by the diff feature.
     *
     * @param   integer  $queueId          The queue row this feedback belongs to.
     * @param   array    $machineDraft     Pre-edit field values (title/introtext/fulltext).
     * @param   array    $humanCorrection  Submitted field values (title/introtext/fulltext).
     *
     * @return  void
     *
     * @since   0.2.0
     */
    private function recordFeedback(int $queueId, array $machineDraft, array $humanCorrection): void
    {
        $item           = $this->getItem();
        $source         = $item->source_article;
        $targetLanguage = (string) $item->target_language;
        $translatorId   = (int) $this->getCurrentUser()->id;
        $db             = $this->getDatabase();

        foreach (['title', 'introtext', 'fulltext'] as $field) {
            // Only changed fields are worth learning from - an untouched field is not a correction.
            if ($humanCorrection[$field] === $machineDraft[$field]) {
                continue;
            }

            $row = (object) [
                'queue_id'         => $queueId,
                'source_text'      => $source !== null ? (string) ($source->$field ?? '') : '',
                'machine_draft'    => $machineDraft[$field],
                'human_correction' => $humanCorrection[$field],
                'target_language'  => $targetLanguage,
                'translator_id'    => $translatorId,
            ];

            $db->insertObject('#__translations_feedback', $row);
        }
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
