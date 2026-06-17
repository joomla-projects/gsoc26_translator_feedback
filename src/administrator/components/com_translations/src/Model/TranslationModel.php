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
use Joomla\CMS\MVC\Factory\MVCFactoryServiceInterface;
use Joomla\CMS\MVC\Model\BaseDatabaseModel;
use Joomla\Component\Content\Administrator\Model\ArticleModel;
use Joomla\Database\ParameterType;
use Joomla\Registry\Registry;

/**
 * Producer model: turns a source article and a target language into an unpublished
 * translated draft article, linked back to the source via #__associations, with its
 * per-language queue state set to "review" (ready for a translator to correct).
 *
 * @since  0.3.0
 */
class TranslationModel extends BaseDatabaseModel
{
    /**
     * Content type stored in #__translations_queue for articles (matches QueueModel).
     *
     * @var    string
     * @since  0.3.0
     */
    private const CONTENT_TYPE = 'com_content.article';

    /**
     * Translatable keys inside the article's images JSON (alt text and captions).
     *
     * @var    string[]
     * @since  0.4.0
     */
    private const TRANSLATABLE_IMAGE_FIELDS = [
        'image_intro_alt',
        'image_intro_caption',
        'image_fulltext_alt',
        'image_fulltext_caption',
    ];

    /**
     * Translate a source article into one target language.
     *
     * Creates the unpublished draft article with its association to the source and
     * sets the per-language queue state to "review", ready for translator feedback.
     *
     * @param   integer                  $sourceArticleId  The source article id.
     * @param   string                   $targetLanguage   The target language code, e.g. 'fr-FR'.
     * @param   CMSApplicationInterface  $application      The application, used to boot com_content.
     *
     * @return  void
     *
     * @throws  \RuntimeException  If the article is missing or not translatable, or the draft cannot be created.
     *
     * @since   0.3.0
     */
    public function translate(int $sourceArticleId, string $targetLanguage, CMSApplicationInterface $application): void
    {
        $sourceArticle = $this->getSourceArticle($sourceArticleId);

        // An all-languages article is shown for every language, so there is nothing to translate.
        if ($sourceArticle['language'] === '*') {
            throw new \RuntimeException(\sprintf('Article %d applies to all languages.', $sourceArticleId));
        }

        if ($sourceArticle['language'] === $targetLanguage) {
            throw new \RuntimeException(\sprintf('Article %d is already in %s.', $sourceArticleId, $targetLanguage));
        }

        if ($this->isDoNotTranslate($sourceArticleId)) {
            throw new \RuntimeException(\sprintf('Article %d is marked as not to be translated.', $sourceArticleId));
        }

        $this->createDraft($sourceArticle, $targetLanguage, $application);
        $this->markReadyForReview($sourceArticleId, $targetLanguage);
    }

    /**
     * Clear the "no need for translation" flag on a source article's queue row.
     *
     * @param   integer  $sourceArticleId  The source article id.
     *
     * @return  void
     *
     * @since   0.3.0
     */
    public function allowTranslation(int $sourceArticleId): void
    {
        // Bound parameters are passed by reference, so the constant needs a variable.
        $contentType = self::CONTENT_TYPE;

        $db    = $this->getDatabase();
        $query = $db->getQuery(true)
            ->update($db->quoteName('#__translations_queue'))
            ->set($db->quoteName('do_not_translate') . ' = 0')
            ->where($db->quoteName('content_type') . ' = :contentType')
            ->where($db->quoteName('content_id') . ' = :contentId')
            ->bind(':contentType', $contentType, ParameterType::STRING)
            ->bind(':contentId', $sourceArticleId, ParameterType::INTEGER);
        $db->setQuery($query);
        $db->execute();
    }

    /**
     * Load the source article's raw column values.
     *
     * The row is read directly rather than via com_content's getItem(), whose
     * computed objects break a re-save.
     *
     * @param   integer  $sourceArticleId  The source article id.
     *
     * @return  array  The article's column values.
     *
     * @throws  \RuntimeException  If the article does not exist.
     *
     * @since   0.3.0
     */
    private function getSourceArticle(int $sourceArticleId): array
    {
        $db    = $this->getDatabase();
        $query = $db->getQuery(true)
            ->select(
                $db->quoteName(
                    ['id', 'title', 'alias', 'introtext', 'fulltext', 'metadesc', 'metakey', 'note', 'images', 'language', 'catid', 'access', 'created_by']
                )
            )
            ->from($db->quoteName('#__content'))
            ->where($db->quoteName('id') . ' = :id')
            ->bind(':id', $sourceArticleId, ParameterType::INTEGER);
        $db->setQuery($query);

        $sourceArticle = $db->loadAssoc();

        if ($sourceArticle === null) {
            throw new \RuntimeException(\sprintf('Source article %d not found.', $sourceArticleId));
        }

        return $sourceArticle;
    }

    /**
     * Check whether the source article is flagged as not to be translated.
     *
     * The flag lives on the article's queue row; an article without a queue row
     * is translatable.
     *
     * @param   integer  $sourceArticleId  The source article id.
     *
     * @return  boolean  True when the article must not be translated.
     *
     * @since   0.3.0
     */
    private function isDoNotTranslate(int $sourceArticleId): bool
    {
        // Bound parameters are passed by reference, so the constant needs a variable.
        $contentType = self::CONTENT_TYPE;

        $db    = $this->getDatabase();
        $query = $db->getQuery(true)
            ->select($db->quoteName('do_not_translate'))
            ->from($db->quoteName('#__translations_queue'))
            ->where($db->quoteName('content_type') . ' = :contentType')
            ->where($db->quoteName('content_id') . ' = :contentId')
            ->bind(':contentType', $contentType, ParameterType::STRING)
            ->bind(':contentId', $sourceArticleId, ParameterType::INTEGER);
        $db->setQuery($query);

        return (bool) $db->loadResult();
    }

    /**
     * Gather an article's translatable strings into one collection, keyed by field.
     *
     * All of an item's strings are handed over together so a provider keeps the context
     * between them. Empty fields are left out so nothing is translated needlessly.
     *
     * @param   array  $sourceArticle  The source article's column values.
     *
     * @return  array  The translatable strings keyed by field name.
     *
     * @since   0.4.0
     */
    private function collectTranslatableStrings(array $sourceArticle): array
    {
        $strings = [];

        foreach (['title', 'introtext', 'fulltext', 'metadesc', 'metakey', 'note'] as $field) {
            $value = (string) ($sourceArticle[$field] ?? '');

            if (trim($value) !== '') {
                $strings[$field] = $value;
            }
        }

        // Image alt and caption text live inside the images JSON, gathered under a dotted images path.
        $images = new Registry($sourceArticle['images']);

        foreach (self::TRANSLATABLE_IMAGE_FIELDS as $field) {
            $value = (string) $images->get($field, '');

            if (trim($value) !== '') {
                $strings['images.' . $field] = $value;
            }
        }

        return $strings;
    }

    /**
     * Stand-in translation until a translation provider plugin supplies the real one.
     *
     * Returns each string prefixed with the target language so a draft is visibly "translated"
     * for testing, without calling any external service. Empty strings stay empty so the draft
     * gains no text the source does not have. This is the single point a provider plugin replaces.
     *
     * @param   array   $strings         The source strings keyed by field name.
     * @param   string  $targetLanguage  The target language code, e.g. 'fr-FR'.
     *
     * @return  array  The mock-translated strings, keyed as given.
     *
     * @since   0.3.0
     */
    private function mockTranslate(array $strings, string $targetLanguage): array
    {
        foreach ($strings as $field => $text) {
            $strings[$field] = trim($text) === ''
                ? $text
                : \sprintf('[MOCK:%s] %s', $targetLanguage, $text);
        }

        return $strings;
    }

    /**
     * Create the unpublished draft article for one target language.
     *
     * The draft is saved through com_content's ArticleModel so versioning, events and
     * association handling run exactly as for a hand created article. Passing the source
     * article under 'associations' makes core write the #__associations link itself.
     *
     * @param   array                    $sourceArticle   The source article's column values.
     * @param   string                   $targetLanguage  The target language code.
     * @param   CMSApplicationInterface  $application     The application, used to boot com_content.
     *
     * @return  void
     *
     * @throws  \RuntimeException  If the draft cannot be saved.
     *
     * @since   0.3.0
     */
    private function createDraft(array $sourceArticle, string $targetLanguage, CMSApplicationInterface $application): void
    {
        // Save through com_content's article model so versioning and the content events run.
        /** @var ComponentInterface&MVCFactoryServiceInterface $component */
        $component = $application->bootComponent('com_content');

        // Ignore the request because the model gets its data from us.
        /** @var ArticleModel $articleModel */
        $articleModel = $component->getMVCFactory()->createModel('Article', 'Administrator', ['ignore_request' => true]);

        // Hand all the article's translatable strings over together, then read the result back per field.
        $translated = $this->mockTranslate($this->collectTranslatableStrings($sourceArticle), $targetLanguage);

        $title     = $translated['title']     ?? (string) $sourceArticle['title'];
        $introtext = $translated['introtext'] ?? (string) $sourceArticle['introtext'];
        $fulltext  = $translated['fulltext']  ?? (string) $sourceArticle['fulltext'];
        $metadesc  = $translated['metadesc']  ?? (string) $sourceArticle['metadesc'];
        $metakey   = $translated['metakey']   ?? (string) $sourceArticle['metakey'];
        $note      = $translated['note']      ?? (string) $sourceArticle['note'];

        // Rebuild the images JSON from the source, overlaying any translated alt and caption text.
        $images = new Registry($sourceArticle['images']);

        foreach (self::TRANSLATABLE_IMAGE_FIELDS as $field) {
            if (isset($translated['images.' . $field])) {
                $images->set($field, $translated['images.' . $field]);
            }
        }

        // The draft must be saved together with the source's existing association group,
        // otherwise core re-keys the group and earlier drafts fall out of it.
        $associations = $this->getAssociationGroup((int) $sourceArticle['id']);

        $associations[$sourceArticle['language']] = (int) $sourceArticle['id'];

        $draft = [
            'id'           => 0,
            'title'        => $title,
            // Aliases are unique per category regardless of language, so the draft cannot reuse the source's.
            'alias'        => $sourceArticle['alias'] . '-' . strtolower($targetLanguage),
            'introtext'    => $introtext,
            'fulltext'     => $fulltext,
            'metadesc'     => $metadesc,
            'metakey'      => $metakey,
            'note'         => $note,
            'images'       => $images->toArray(),
            'language'     => $targetLanguage,
            'catid'        => (int) $sourceArticle['catid'],
            // Keep the draft unpublished until a translator approves it.
            'state'        => 0,
            'access'       => (int) $sourceArticle['access'],
            'created_by'   => (int) $sourceArticle['created_by'],
            // Joomla links the draft into this association group on save.
            'associations' => $associations,
        ];

        // com_content's edit form works on a single combined body; keep it consistent with the two columns.
        $draft['articletext'] = trim($fulltext) !== ''
            ? $introtext . '<hr id="system-readmore">' . $fulltext
            : $introtext;

        if (!$articleModel->save($draft)) {
            throw new \RuntimeException(
                \sprintf('Could not create the %s draft for article %d.', $targetLanguage, (int) $sourceArticle['id'])
            );
        }
    }

    /**
     * Load the article ids of the source article's association group, keyed by language.
     *
     * Joomla keeps all language versions of an article in one association group
     * under a shared key. Returns an empty array when the article has no
     * associations yet.
     *
     * @param   integer  $sourceArticleId  The source article id.
     *
     * @return  array  Article ids keyed by language code.
     *
     * @since   0.3.0
     */
    private function getAssociationGroup(int $sourceArticleId): array
    {
        // The associations context com_content stores articles under.
        $context = 'com_content.item';

        $db    = $this->getDatabase();
        $query = $db->getQuery(true)
            ->select($db->quoteName(['article.language', 'article.id']))
            ->from($db->quoteName('#__associations', 'sourceAssociation'))
            ->join(
                'INNER',
                $db->quoteName('#__associations', 'groupAssociation'),
                $db->quoteName('groupAssociation.key') . ' = ' . $db->quoteName('sourceAssociation.key')
            )
            ->join(
                'INNER',
                $db->quoteName('#__content', 'article'),
                $db->quoteName('article.id') . ' = ' . $db->quoteName('groupAssociation.id')
            )
            ->where($db->quoteName('sourceAssociation.context') . ' = :context')
            ->where($db->quoteName('groupAssociation.context') . ' = :groupContext')
            ->where($db->quoteName('sourceAssociation.id') . ' = :sourceId')
            ->bind(':context', $context, ParameterType::STRING)
            ->bind(':groupContext', $context, ParameterType::STRING)
            ->bind(':sourceId', $sourceArticleId, ParameterType::INTEGER);
        $db->setQuery($query);

        return $db->loadAssocList('language', 'id');
    }

    /**
     * Set the source article's state for one target language to "review".
     *
     * One state row exists per (queue row, target language); no row means "no
     * translation yet". The first translation inserts the row, a repeat run
     * updates it. The row's modified time is maintained by the database.
     *
     * @param   integer  $sourceArticleId  The source article id.
     * @param   string   $targetLanguage   The target language code.
     *
     * @return  void
     *
     * @since   0.3.0
     */
    private function markReadyForReview(int $sourceArticleId, string $targetLanguage): void
    {
        $queueId     = $this->getOrCreateQueueId($sourceArticleId);
        $reviewState = 'review';

        // A state row may already exist from an earlier translation of this language.
        $db    = $this->getDatabase();
        $query = $db->getQuery(true)
            ->select($db->quoteName('id'))
            ->from($db->quoteName('#__translations_queue_states'))
            ->where($db->quoteName('queue_id') . ' = :queueId')
            ->where($db->quoteName('target_language') . ' = :targetLanguage')
            ->bind(':queueId', $queueId, ParameterType::INTEGER)
            ->bind(':targetLanguage', $targetLanguage, ParameterType::STRING);
        $db->setQuery($query);

        $stateId = $db->loadResult();

        if ($stateId !== null) {
            $stateId = (int) $stateId;

            $query = $db->getQuery(true)
                ->update($db->quoteName('#__translations_queue_states'))
                ->set($db->quoteName('translation_state') . ' = :state')
                ->where($db->quoteName('id') . ' = :stateId')
                ->bind(':state', $reviewState, ParameterType::STRING)
                ->bind(':stateId', $stateId, ParameterType::INTEGER);
            $db->setQuery($query);
            $db->execute();

            return;
        }

        $stateRow = (object) [
            'queue_id'          => $queueId,
            'target_language'   => $targetLanguage,
            'translation_state' => $reviewState,
        ];

        $db->insertObject('#__translations_queue_states', $stateRow);
    }

    /**
     * Find the queue row for a source article, creating it when missing.
     *
     * The queue holds one row per source article, keyed by content type + id.
     * A source article gets its row with its first translation.
     *
     * @param   integer  $sourceArticleId  The source article id.
     *
     * @return  integer  The queue row id.
     *
     * @since   0.3.0
     */
    private function getOrCreateQueueId(int $sourceArticleId): int
    {
        // Bound parameters are passed by reference, so the constant needs a variable.
        $contentType = self::CONTENT_TYPE;

        $db    = $this->getDatabase();
        $query = $db->getQuery(true)
            ->select($db->quoteName('id'))
            ->from($db->quoteName('#__translations_queue'))
            ->where($db->quoteName('content_type') . ' = :contentType')
            ->where($db->quoteName('content_id') . ' = :contentId')
            ->bind(':contentType', $contentType, ParameterType::STRING)
            ->bind(':contentId', $sourceArticleId, ParameterType::INTEGER);
        $db->setQuery($query);

        $queueId = $db->loadResult();

        if ($queueId !== null) {
            return (int) $queueId;
        }

        $queueRow = (object) [
            'content_type' => self::CONTENT_TYPE,
            'content_id'   => $sourceArticleId,
        ];

        $db->insertObject('#__translations_queue', $queueRow, 'id');

        return (int) $queueRow->id;
    }
}
