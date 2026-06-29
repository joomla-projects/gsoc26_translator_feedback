<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  Content.translations
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Plugin\Content\Translations\Extension;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use Joomla\CMS\Application\CMSApplicationInterface;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Event\Model\AfterCategoryChangeStateEvent;
use Joomla\CMS\Event\Model\AfterChangeStateEvent;
use Joomla\CMS\Event\Model\AfterDeleteEvent;
use Joomla\CMS\Event\Model\AfterSaveEvent;
use Joomla\CMS\Event\Model\BeforeDeleteEvent;
use Joomla\CMS\Event\Model\PrepareDataEvent;
use Joomla\CMS\Event\Model\PrepareFormEvent;
use Joomla\CMS\Extension\ComponentInterface;
use Joomla\CMS\MVC\Factory\MVCFactoryServiceInterface;
use Joomla\CMS\MVC\Model\AdminModel;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Component\Translations\Administrator\Helper\ContentTypesHelper;
use Joomla\Database\DatabaseAwareInterface;
use Joomla\Database\DatabaseAwareTrait;
use Joomla\Database\ParameterType;
use Joomla\Event\SubscriberInterface;

/**
 * Content plugin for the Translations component. Adds the "no need for translation"
 * toggle to the article form, and cascades a source item's trash or delete to its
 * translated items and the queue rows that track them, for every managed content type.
 *
 * @since  0.3.0
 */
final class Translations extends CMSPlugin implements SubscriberInterface, DatabaseAwareInterface
{
    use DatabaseAwareTrait;

    /**
     * Load the plugin language files automatically.
     *
     * @var    boolean
     * @since  0.3.0
     */
    protected $autoloadLanguage = true;

    /**
     * Content type stored in #__translations_queue for articles (matches the component).
     *
     * @var    string
     * @since  0.3.0
     */
    private const CONTENT_TYPE = 'com_content.article';

    /**
     * The form field this plugin adds to the article form.
     *
     * @var    string
     * @since  0.3.0
     */
    private const FIELD = 'no_need_for_translation';

    /**
     * Translated item ids captured per source id during onContentBeforeDelete, before
     * core removes the association link, so onContentAfterDelete can delete them.
     *
     * @var    array<int, int[]>
     * @since  0.4.0
     */
    private array $capturedTranslations = [];

    /**
     * Per-language queue cells captured during onContentBeforeDelete when the deleted item is one of
     * our translations, so onContentAfterDelete can clear the stale state row once the item is gone.
     *
     * @var    array<int, array{queueId: int, language: string}>
     * @since  0.4.0
     */
    private array $capturedCells = [];

    /**
     * Returns the events this subscriber listens to.
     *
     * @return  array
     *
     * @since   0.3.0
     */
    public static function getSubscribedEvents(): array
    {
        return [
            'onContentPrepareData'  => 'onContentPrepareData',
            'onContentPrepareForm'  => 'onContentPrepareForm',
            'onContentAfterSave'    => 'onContentAfterSave',
            'onContentChangeState'  => 'onContentChangeState',
            'onCategoryChangeState' => 'onCategoryChangeState',
            'onContentBeforeDelete' => 'onContentBeforeDelete',
            'onContentAfterDelete'  => 'onContentAfterDelete',
        ];
    }

    /**
     * Add the "no need for translation" toggle to the source-language article form.
     *
     * @param   PrepareFormEvent  $event  The event.
     *
     * @return  void
     *
     * @since   0.3.0
     */
    public function onContentPrepareForm(PrepareFormEvent $event): void
    {
        $form = $event->getForm();

        if ($form->getName() !== 'com_content.article') {
            return;
        }

        // Load the toggle for source-language articles and new ones (language not set yet).
        $data     = $event->getData();
        $language = (string) (\is_object($data) ? ($data->language ?? '') : ($data['language'] ?? ''));

        if ($language !== '' && $language !== $this->getSourceLanguage()) {
            return;
        }

        $form->loadFile(\dirname(__DIR__, 2) . '/forms/translationoptout.xml');
    }

    /**
     * Default a new article's language to the source language, and reflect the queue flag on the toggle.
     *
     * A new article has no language yet, so it is set to the source language here. For an existing article
     * the displayed toggle value is set from the queue before the form binds the stored attribs (the form
     * binds after onContentPrepareForm runs), so it survives even after the flag was cleared from the grid.
     *
     * @param   PrepareDataEvent  $event  The event.
     *
     * @return  void
     *
     * @since   0.3.0
     */
    public function onContentPrepareData(PrepareDataEvent $event): void
    {
        if ($event->getContext() !== 'com_content.article') {
            return;
        }

        $data = $event->getData();

        if (!\is_object($data)) {
            return;
        }

        // A new article has no language yet; default it to the source language.
        if (empty($data->id) && (string) ($data->language ?? '') === '') {
            $data->language = $this->getSourceLanguage();

            return;
        }

        // The edit form supplies the item with attribs as an array; leave other shapes alone.
        if (empty($data->id) || !\is_array($data->attribs ?? null)) {
            return;
        }

        $data->attribs[self::FIELD] = $this->isFlagged((int) $data->id) ? 1 : 0;
    }

    /**
     * Persist the toggle to the article's queue row when a source-language article is saved.
     *
     * @param   AfterSaveEvent  $event  The event.
     *
     * @return  void
     *
     * @since   0.3.0
     */
    public function onContentAfterSave(AfterSaveEvent $event): void
    {
        if ($event->getContext() !== 'com_content.article') {
            return;
        }

        $article = $event->getItem();

        // Only source-language originals are tracked in the queue.
        if ((string) ($article->language ?? '') !== $this->getSourceLanguage()) {
            return;
        }

        // The toggle lives in the article's attribs group, so it arrives nested under 'attribs'.
        $data           = $event->getData();
        $attribs        = $data['attribs'] ?? [];
        $doNotTranslate = (int) (\is_array($attribs) ? ($attribs[self::FIELD] ?? 0) : 0);

        $this->storeFlag((int) $article->id, $doNotTranslate);
    }

    /**
     * Trash a source item's translations when the source is trashed.
     *
     * Fires for articles, tags and menu items; categories use onCategoryChangeState.
     *
     * @param   AfterChangeStateEvent  $event  The event.
     *
     * @return  void
     *
     * @since   0.4.0
     */
    public function onContentChangeState(AfterChangeStateEvent $event): void
    {
        // -2 is the trashed state; publish, unpublish and archive do not cascade.
        if ($event->getValue() !== -2) {
            return;
        }

        $properties = $this->managedProperties($event->getContext());

        if ($properties === null) {
            return;
        }

        foreach ($event->getPks() as $pk) {
            $this->cascadeTrash((int) $pk, $event->getContext(), $properties);
        }
    }

    /**
     * Trash a category's translations when the source category is trashed.
     *
     * Categories fire their own change-state event, carrying the extension as the context, so this
     * mirrors onContentChangeState for the category content type.
     *
     * @param   AfterCategoryChangeStateEvent  $event  The event.
     *
     * @return  void
     *
     * @since   0.4.0
     */
    public function onCategoryChangeState(AfterCategoryChangeStateEvent $event): void
    {
        if ($event->getValue() !== -2) {
            return;
        }

        // This event fires only for categories; its context is the extension, not the content type.
        $contentType = 'com_categories.category';
        $properties  = $this->managedProperties($contentType);

        if ($properties === null || $event->getContext() !== ($properties['limitToExtension'] ?? null)) {
            return;
        }

        foreach ($event->getPks() as $pk) {
            $this->cascadeTrash((int) $pk, $contentType, $properties);
        }
    }

    /**
     * Before a delete removes the association link, capture what onContentAfterDelete needs: a
     * managed source's translation group, or a translation draft's stale queue cell.
     *
     * @param   BeforeDeleteEvent  $event  The event.
     *
     * @return  void
     *
     * @since   0.4.0
     */
    public function onContentBeforeDelete(BeforeDeleteEvent $event): void
    {
        $properties = $this->managedProperties($event->getContext());

        if ($properties === null) {
            return;
        }

        $contentType = $event->getContext();
        $context     = (string) $properties['context_associations'];
        $item        = $event->getItem();
        $itemId      = (int) $item->id;

        // Core removes the association link during the delete that follows, so anything that needs it is
        // read now. A managed source captures its translation group (an empty array still marks the source
        // whose queue row to clean); otherwise the item may be one of our translation drafts.
        if ($this->queueId($itemId, $contentType) !== null) {
            $this->capturedTranslations[$itemId] = $this->translationGroupIds($itemId, $context);

            return;
        }

        $queueId = $this->sourceQueueIdForTranslation($itemId, $context, $contentType);

        if ($queueId !== null) {
            $this->capturedCells[$itemId] = [
                'queueId'  => $queueId,
                'language' => (string) ($item->language ?? ''),
            ];
        }
    }

    /**
     * Clean up after a delete: a source's translations and queue rows, or a translation draft's stale cell.
     *
     * @param   AfterDeleteEvent  $event  The event.
     *
     * @return  void
     *
     * @since   0.4.0
     */
    public function onContentAfterDelete(AfterDeleteEvent $event): void
    {
        $properties = $this->managedProperties($event->getContext());

        if ($properties === null) {
            return;
        }

        $contentType = $event->getContext();
        $itemId      = (int) $event->getItem()->id;

        // A managed source: delete its translations and clean its queue rows.
        if (isset($this->capturedTranslations[$itemId])) {
            $translations = $this->capturedTranslations[$itemId];
            unset($this->capturedTranslations[$itemId]);

            // The translations were trashed with the source, so they can now be deleted.
            if ($translations !== []) {
                $this->deleteTranslations($translations, $properties);
            }

            $this->removeFromQueue($itemId, $contentType);

            return;
        }

        // One of our translation drafts: clear its now-stale per-language queue cell.
        if (isset($this->capturedCells[$itemId])) {
            $cell = $this->capturedCells[$itemId];
            unset($this->capturedCells[$itemId]);

            $this->clearQueueCell($cell['queueId'], $cell['language']);
        }
    }

    /**
     * The component's configured source language.
     *
     * @return  string
     *
     * @since   0.3.0
     */
    private function getSourceLanguage(): string
    {
        return (string) ComponentHelper::getParams('com_translations')->get('source_language', 'en-GB');
    }

    /**
     * The content type's translation properties from the map, or null when the type is not managed.
     *
     * @param   string  $contentType  The content type alias from the event.
     *
     * @return  array|null
     *
     * @since   0.4.0
     */
    private function managedProperties(string $contentType): ?array
    {
        return \in_array($contentType, ContentTypesHelper::getContentTypes(), true)
            ? ContentTypesHelper::getProperties($contentType)
            : null;
    }

    /**
     * Trash a managed source's translations.
     *
     * @param   integer  $sourceId     The source item id.
     * @param   string   $contentType  The content type alias.
     * @param   array    $properties   The content type's properties.
     *
     * @return  void
     *
     * @since   0.4.0
     */
    private function cascadeTrash(int $sourceId, string $contentType, array $properties): void
    {
        // Only sources this component manages cascade.
        if ($this->queueId($sourceId, $contentType) === null) {
            return;
        }

        $translations = $this->translationGroupIds($sourceId, (string) $properties['context_associations']);

        if ($translations !== []) {
            $this->trashTranslations($translations, $properties);
        }
    }

    /**
     * Whether the article is currently flagged "no need for translation".
     *
     * @param   integer  $articleId  The article id.
     *
     * @return  boolean
     *
     * @since   0.3.0
     */
    private function isFlagged(int $articleId): bool
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
            ->bind(':contentId', $articleId, ParameterType::INTEGER);
        $db->setQuery($query);

        return (bool) $db->loadResult();
    }

    /**
     * Store the flag on the article's queue row, creating the row only to record an opt-out.
     *
     * @param   integer  $articleId       The article id.
     * @param   integer  $doNotTranslate  1 to opt out of translation, 0 to allow it.
     *
     * @return  void
     *
     * @since   0.3.0
     */
    private function storeFlag(int $articleId, int $doNotTranslate): void
    {
        $db      = $this->getDatabase();
        $queueId = $this->queueId($articleId, self::CONTENT_TYPE);

        if ($queueId !== null) {
            $row = (object) [
                'id'               => $queueId,
                'do_not_translate' => $doNotTranslate,
            ];
            $db->updateObject('#__translations_queue', $row, 'id');

            return;
        }

        // No row yet: create one only to record an opt-out, never just to store the default.
        if ($doNotTranslate === 0) {
            return;
        }

        $row = (object) [
            'content_type'     => self::CONTENT_TYPE,
            'content_id'       => $articleId,
            'do_not_translate' => 1,
        ];
        $db->insertObject('#__translations_queue', $row);
    }

    /**
     * A source item's queue row id, or null when the item is not in the queue.
     *
     * @param   integer  $sourceId     The source item id.
     * @param   string   $contentType  The content type alias.
     *
     * @return  integer|null
     *
     * @since   0.4.0
     */
    private function queueId(int $sourceId, string $contentType): ?int
    {
        $db    = $this->getDatabase();
        $query = $db->getQuery(true)
            ->select($db->quoteName('id'))
            ->from($db->quoteName('#__translations_queue'))
            ->where($db->quoteName('content_type') . ' = :contentType')
            ->where($db->quoteName('content_id') . ' = :contentId')
            ->bind(':contentType', $contentType, ParameterType::STRING)
            ->bind(':contentId', $sourceId, ParameterType::INTEGER);
        $db->setQuery($query);

        $queueId = $db->loadResult();

        return $queueId === null ? null : (int) $queueId;
    }

    /**
     * The ids of a source item's translations: its association group, minus itself.
     *
     * @param   integer  $sourceId  The source item id.
     * @param   string   $context   The #__associations context for the content type.
     *
     * @return  int[]
     *
     * @since   0.4.0
     */
    private function translationGroupIds(int $sourceId, string $context): array
    {
        $db    = $this->getDatabase();
        $query = $db->getQuery(true)
            ->select($db->quoteName('groupAssociation.id'))
            ->from($db->quoteName('#__associations', 'sourceAssociation'))
            ->join(
                'INNER',
                $db->quoteName('#__associations', 'groupAssociation'),
                $db->quoteName('groupAssociation.key') . ' = ' . $db->quoteName('sourceAssociation.key')
            )
            ->where($db->quoteName('sourceAssociation.context') . ' = :context')
            ->where($db->quoteName('groupAssociation.context') . ' = :groupContext')
            ->where($db->quoteName('sourceAssociation.id') . ' = :sourceId')
            ->bind(':context', $context, ParameterType::STRING)
            ->bind(':groupContext', $context, ParameterType::STRING)
            ->bind(':sourceId', $sourceId, ParameterType::INTEGER);
        $db->setQuery($query);

        $ids = [];

        foreach ($db->loadColumn() as $id) {
            if ((int) $id !== $sourceId) {
                $ids[] = (int) $id;
            }
        }

        return $ids;
    }

    /**
     * The queue id of a translation draft's source, found through its association group.
     *
     * A translation has no queue row of its own; its source does. The draft and its source share an
     * #__associations key, and the group member that has a #__translations_queue row is the source.
     * Returns null when the item is not one of our translations (a plain multilingual item).
     *
     * @param   integer  $translationId  The translation draft's item id.
     * @param   string   $context        The #__associations context for the content type.
     * @param   string   $contentType    The content type alias.
     *
     * @return  integer|null
     *
     * @since   0.4.0
     */
    private function sourceQueueIdForTranslation(int $translationId, string $context, string $contentType): ?int
    {
        $db    = $this->getDatabase();
        $query = $db->getQuery(true)
            ->select($db->quoteName('queue.id'))
            ->from($db->quoteName('#__associations', 'translationAssociation'))
            ->join(
                'INNER',
                $db->quoteName('#__associations', 'sourceAssociation'),
                $db->quoteName('sourceAssociation.key') . ' = ' . $db->quoteName('translationAssociation.key')
            )
            ->join(
                'INNER',
                $db->quoteName('#__translations_queue', 'queue'),
                $db->quoteName('queue.content_id') . ' = ' . $db->quoteName('sourceAssociation.id')
                . ' AND ' . $db->quoteName('queue.content_type') . ' = :contentType'
            )
            ->where($db->quoteName('translationAssociation.id') . ' = :translationId')
            ->where($db->quoteName('translationAssociation.context') . ' = :context')
            ->where($db->quoteName('sourceAssociation.context') . ' = :groupContext')
            ->bind(':translationId', $translationId, ParameterType::INTEGER)
            ->bind(':context', $context, ParameterType::STRING)
            ->bind(':groupContext', $context, ParameterType::STRING)
            ->bind(':contentType', $contentType, ParameterType::STRING);
        $db->setQuery($query);

        $queueId = $db->loadResult();

        return $queueId === null ? null : (int) $queueId;
    }

    /**
     * Remove a single per-language state row so its queue cell reverts to "no translation yet".
     *
     * @param   integer  $queueId   The source's queue row id.
     * @param   string   $language  The translation's language code.
     *
     * @return  void
     *
     * @since   0.4.0
     */
    private function clearQueueCell(int $queueId, string $language): void
    {
        $db    = $this->getDatabase();
        $query = $db->getQuery(true)
            ->delete($db->quoteName('#__translations_queue_states'))
            ->where($db->quoteName('queue_id') . ' = :queueId')
            ->where($db->quoteName('target_language') . ' = :language')
            ->bind(':queueId', $queueId, ParameterType::INTEGER)
            ->bind(':language', $language, ParameterType::STRING);
        $db->setQuery($query);
        $db->execute();
    }

    /**
     * Trash the given items through the managing component's model.
     *
     * @param   int[]  $ids         The item ids.
     * @param   array  $properties  The content type's properties.
     *
     * @return  void
     *
     * @since   0.4.0
     */
    private function trashTranslations(array $ids, array $properties): void
    {
        // publish() takes the ids by reference; -2 is the trashed state.
        $pks = $ids;
        $this->model($properties)->publish($pks, -2);
    }

    /**
     * Delete the given items through the managing component's model.
     *
     * @param   int[]  $ids         The item ids.
     * @param   array  $properties  The content type's properties.
     *
     * @return  void
     *
     * @since   0.4.0
     */
    private function deleteTranslations(array $ids, array $properties): void
    {
        // delete() takes the ids by reference.
        $pks = $ids;
        $this->model($properties)->delete($pks);
    }

    /**
     * Boot the managing component and create its admin model.
     *
     * @param   array  $properties  The content type's properties.
     *
     * @return  AdminModel
     *
     * @since   0.4.0
     */
    private function model(array $properties): AdminModel
    {
        /** @var CMSApplicationInterface $application */
        $application = $this->getApplication();

        /** @var ComponentInterface&MVCFactoryServiceInterface $component */
        $component = $application->bootComponent((string) $properties['component']);

        /** @var AdminModel $model */
        $model = $component->getMVCFactory()->createModel((string) $properties['model'], 'Administrator', ['ignore_request' => true]);

        return $model;
    }

    /**
     * Remove a source's queue row and its per-language state rows.
     *
     * @param   integer  $sourceId     The source item id.
     * @param   string   $contentType  The content type alias.
     *
     * @return  void
     *
     * @since   0.4.0
     */
    private function removeFromQueue(int $sourceId, string $contentType): void
    {
        $queueId = $this->queueId($sourceId, $contentType);

        if ($queueId === null) {
            return;
        }

        $db = $this->getDatabase();

        // The state rows have no delete cascade, so remove them before the queue row.
        $query = $db->getQuery(true)
            ->delete($db->quoteName('#__translations_queue_states'))
            ->where($db->quoteName('queue_id') . ' = :queueId')
            ->bind(':queueId', $queueId, ParameterType::INTEGER);
        $db->setQuery($query);
        $db->execute();

        $query = $db->getQuery(true)
            ->delete($db->quoteName('#__translations_queue'))
            ->where($db->quoteName('id') . ' = :queueId')
            ->bind(':queueId', $queueId, ParameterType::INTEGER);
        $db->setQuery($query);
        $db->execute();
    }
}
