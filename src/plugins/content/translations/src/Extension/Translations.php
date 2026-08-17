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
use Joomla\CMS\Event\Model\BeforeSaveEvent;
use Joomla\CMS\Event\Model\PrepareDataEvent;
use Joomla\CMS\Event\Model\PrepareFormEvent;
use Joomla\CMS\Event\Table\AfterStoreEvent;
use Joomla\CMS\Extension\ComponentInterface;
use Joomla\CMS\Log\Log;
use Joomla\CMS\MVC\Factory\MVCFactoryServiceInterface;
use Joomla\CMS\MVC\Model\AdminModel;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Table\ContentHistory;
use Joomla\Component\Translations\Administrator\Helper\ContentTypesHelper;
use Joomla\Database\DatabaseAwareInterface;
use Joomla\Database\DatabaseAwareTrait;
use Joomla\Database\ParameterType;
use Joomla\Event\SubscriberInterface;

/**
 * Content plugin for the Translations component. Adds the "no need for translation"
 * toggle to a source item's form, cascades a source item's trash or delete to its translated
 * items and the queue rows that track them, for every managed content type, and sends a
 * source item's translations back to pending once the source has changed.
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
     * The form group the toggle is submitted under. The queue holds the flag, so the group is
     * named for this component and the item's own table never sees it.
     *
     * @var    string
     * @since  0.11.0
     */
    private const GROUP = 'com_translations';

    /**
     * The form field this plugin adds to a source item's form.
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
     * Queue row ids captured per menu item id during onContentBeforeSave, while the stored title is
     * still readable, so onContentAfterSave can invalidate once the save has succeeded.
     *
     * @var    array<int, int>
     * @since  0.9.0
     */
    private array $capturedTitleChanges = [];

    /**
     * The toggle value captured during onContentBeforeSave, for the models that dispatch the after
     * save event without the submitted data. A new item has no id yet at that point, so this cannot
     * be keyed by id the way a title change is.
     *
     * @var    integer|null
     * @since  0.11.0
     */
    private ?int $capturedFlag = null;

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
            'onContentBeforeSave'   => 'onContentBeforeSave',
            'onContentAfterSave'    => 'onContentAfterSave',
            'onContentChangeState'  => 'onContentChangeState',
            'onCategoryChangeState' => 'onCategoryChangeState',
            'onContentBeforeDelete' => 'onContentBeforeDelete',
            'onContentAfterDelete'  => 'onContentAfterDelete',
            'onTableAfterStore'     => 'onTableAfterStore',
        ];
    }

    /**
     * Add the "no need for translation" toggle to a source-language item's form.
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

        if (ContentTypesHelper::getContentTypeForOptOutForm($form->getName()) === null) {
            return;
        }

        // Load the toggle for source-language items and new ones (language not set yet).
        $data     = $event->getData();
        $language = (string) (\is_object($data) ? ($data->language ?? '') : ($data['language'] ?? ''));

        if ($language !== '' && $language !== $this->getSourceLanguage()) {
            return;
        }

        $form->loadFile(\dirname(__DIR__, 2) . '/forms/translationoptout.xml');
    }

    /**
     * Default a new item's language to the source language, and show the queue's flag on the toggle.
     *
     * A new item has no language yet, so it is set to the source language here. The queue holds the flag,
     * so an existing item's toggle is filled from it rather than from anything stored on the item, which
     * keeps the form right after the flag was cleared from the grid.
     *
     * @param   PrepareDataEvent  $event  The event.
     *
     * @return  void
     *
     * @since   0.3.0
     */
    public function onContentPrepareData(PrepareDataEvent $event): void
    {
        $contentType = $event->getContext();
        $properties  = $this->managedProperties($contentType);
        $data        = $event->getData();

        if ($properties === null || !\is_object($data)) {
            return;
        }

        // A new item has no language yet; default it to the source language.
        if (empty($data->id)) {
            if ((string) ($data->language ?? '') === '' && $this->isManagedItem($data, $properties)) {
                $data->language = $this->getSourceLanguage();
            }

            return;
        }

        if (!isset($properties['optOutForm'])) {
            return;
        }

        $data->{self::GROUP} = [self::FIELD => $this->isFlagged((int) $data->id, $contentType) ? 1 : 0];
    }

    /**
     * Capture the submitted toggle, and a source menu item whose title is about to change.
     *
     * The tag and menu item models dispatch the after save event without the submitted data, so the
     * toggle is read here, where every model still passes it.
     *
     * A menu item is not versionable, so the title is the change signal: it is the only field
     * translated for this type. The stored title is readable only until the row is written, and the
     * save can still fail after this event, so the comparison is made here and acted on afterwards.
     *
     * @param   BeforeSaveEvent  $event  The event.
     *
     * @return  void
     *
     * @since   0.9.0
     */
    public function onContentBeforeSave(BeforeSaveEvent $event): void
    {
        $this->capturedFlag = null;

        $contentType = $event->getContext();
        $properties  = $this->managedProperties($contentType);

        if ($properties === null) {
            return;
        }

        if (isset($properties['optOutForm'])) {
            $data = $event->getData();

            if (\is_array($data[self::GROUP] ?? null)) {
                $this->capturedFlag = (int) ($data[self::GROUP][self::FIELD] ?? 0);
            }
        }

        if ($contentType !== 'com_menus.item' || $event->getIsNew()) {
            return;
        }

        $item   = $event->getItem();
        $itemId = (int) $item->id;

        // The queue holds source items only, so saving one of our translations finds nothing.
        $queueId = $this->queueId($itemId, $contentType);

        if ($queueId === null) {
            return;
        }

        // The table is bound with the submitted data before this event, so its title is the new one.
        $storedTitle = $this->storedTitle($itemId, (string) ($properties['table'] ?? ''));

        if ($storedTitle === (string) $item->title) {
            return;
        }

        $this->capturedTitleChanges[$itemId] = $queueId;
    }

    /**
     * Persist the toggle to the item's queue row when a source-language item is saved, and
     * invalidate a source menu item's translations when its title changed.
     *
     * @param   AfterSaveEvent  $event  The event.
     *
     * @return  void
     *
     * @since   0.3.0
     */
    public function onContentAfterSave(AfterSaveEvent $event): void
    {
        $contentType = $event->getContext();

        if ($contentType === 'com_menus.item') {
            $this->invalidateRenamedMenuItem((int) $event->getItem()->id);
        }

        $properties = $this->managedProperties($contentType);

        if ($properties === null || !isset($properties['optOutForm'])) {
            return;
        }

        $item = $event->getItem();

        // Only source-language originals are tracked in the queue.
        if ((string) ($item->language ?? '') !== $this->getSourceLanguage()) {
            return;
        }

        $doNotTranslate = $this->submittedFlag($event);

        // Only a form the toggle was added to submits it, so its absence means the item was saved
        // from a form without the toggle and the stored flag stands.
        if ($doNotTranslate === null) {
            return;
        }

        $this->storeFlag((int) $item->id, $contentType, $doNotTranslate);
    }

    /**
     * The toggle value submitted with the save, or null when the save carried none.
     *
     * @param   AfterSaveEvent  $event  The event.
     *
     * @return  integer|null
     *
     * @since   0.11.0
     */
    private function submittedFlag(AfterSaveEvent $event): ?int
    {
        // Consume the capture, so a later save cannot write it against another item.
        $captured           = $this->capturedFlag;
        $this->capturedFlag = null;

        $data = $event->getData();

        if (\is_array($data[self::GROUP] ?? null)) {
            return (int) ($data[self::GROUP][self::FIELD] ?? 0);
        }

        // The tag and menu item models dispatch this event without the submitted data, so the value
        // is the one read before the save.
        return $captured;
    }

    /**
     * Invalidate a source item's translations once a new version of it is stored.
     *
     * A version is stored only when the content changed, so a stored row is the change signal. The
     * row is written after onContentAfterSave, so the new version is not readable from the save event.
     *
     * @param   AfterStoreEvent  $event  The event.
     *
     * @return  void
     *
     * @since   0.7.0
     */
    public function onTableAfterStore(AfterStoreEvent $event): void
    {
        $table = $event->getArgument('subject');

        if (!$table instanceof ContentHistory || !$event->getArgument('result')) {
            return;
        }

        // A version row is keyed as "<type alias>.<id>", under the alias the item's model is
        // versioned by, so the content type it belongs to is read back from the map.
        $parts            = explode('.', (string) $table->item_id);
        $sourceId         = (int) array_pop($parts);
        $versionTypeAlias = implode('.', $parts);
        $contentType      = ContentTypesHelper::getContentTypeForVersionTypeAlias($versionTypeAlias);

        if ($contentType === null || $sourceId === 0) {
            return;
        }

        // The queue only holds source items, so a stored version of a translation finds nothing.
        $queueId = $this->queueId($sourceId, $contentType);

        if ($queueId === null) {
            return;
        }

        // Never let a failure here break the save this is running inside.
        try {
            $this->invalidateTranslations($queueId, (int) $table->version_id);
        } catch (\Throwable $e) {
            Log::add(
                \sprintf('Could not invalidate translations of %s %d: %s', $contentType, $sourceId, $e->getMessage()),
                Log::WARNING,
                'translations'
            );
        }
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

        // The association link is gone once the delete finishes, so anything that needs it is read now.
        // A managed source captures its translation group (an empty array still marks the source whose
        // queue row to clean); otherwise the item may be one of our translation drafts.
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

        // Core removes the row only for a model declaring an associationsContext, so a type this
        // component associates itself is cleaned here.
        if (!($properties['associationsByModel'] ?? true)) {
            $this->removeAssociation($itemId, (string) $properties['context_associations']);
        }

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
     * Remove a deleted item's association row, for a content type whose associations this component
     * writes rather than the item's own model.
     *
     * An association needs two members to mean anything, so when removing this row would leave fewer
     * the rest of the group goes with it, as core does for the types its models associate.
     *
     * @param   integer  $itemId   The deleted item id.
     * @param   string   $context  The #__associations context for the content type.
     *
     * @return  void
     *
     * @since   1.0.0
     */
    private function removeAssociation(int $itemId, string $context): void
    {
        $db    = $this->getDatabase();
        $query = $db->getQuery(true)
            ->select($db->quoteName('key'))
            ->from($db->quoteName('#__associations'))
            ->where($db->quoteName('context') . ' = :context')
            ->where($db->quoteName('id') . ' = :id')
            ->bind(':context', $context, ParameterType::STRING)
            ->bind(':id', $itemId, ParameterType::INTEGER);
        $db->setQuery($query);

        $key = $db->loadResult();

        if ($key === null) {
            return;
        }

        $query = $db->getQuery(true)
            ->select('COUNT(*)')
            ->from($db->quoteName('#__associations'))
            ->where($db->quoteName('context') . ' = :context')
            ->where($db->quoteName('key') . ' = :key')
            ->bind(':context', $context, ParameterType::STRING)
            ->bind(':key', $key, ParameterType::STRING);
        $db->setQuery($query);

        $members = (int) $db->loadResult();

        $query = $db->getQuery(true)
            ->delete($db->quoteName('#__associations'))
            ->where($db->quoteName('context') . ' = :context')
            ->where($db->quoteName('key') . ' = :key')
            ->bind(':context', $context, ParameterType::STRING)
            ->bind(':key', $key, ParameterType::STRING);

        if ($members > 2) {
            $query->where($db->quoteName('id') . ' = :id')
                ->bind(':id', $itemId, ParameterType::INTEGER);
        }

        $db->setQuery($query);
        $db->execute();
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
     * @param   string  $contentType  The content type key from the event.
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
     * Whether an item falls in the part of its content type this component translates.
     *
     * A category is shared by every extension that uses categories, so only the extension the map
     * names is ours. A menu item is associable on the site client alone, so an administrator one is
     * never translated.
     *
     * @param   object  $data        The form data.
     * @param   array   $properties  The content type's properties.
     *
     * @return  boolean
     *
     * @since   0.9.0
     */
    private function isManagedItem(object $data, array $properties): bool
    {
        if (
            isset($properties['limitToExtension'])
            && (string) ($data->extension ?? '') !== $properties['limitToExtension']
        ) {
            return false;
        }

        if (
            isset($properties['limitToClient'])
            && (int) ($data->client_id ?? 0) !== (int) $properties['limitToClient']
        ) {
            return false;
        }

        return true;
    }

    /**
     * Trash a managed source's translations.
     *
     * @param   integer  $sourceId     The source item id.
     * @param   string   $contentType  The content type key.
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
     * Whether the item is currently flagged "no need for translation".
     *
     * @param   integer  $itemId       The source item id.
     * @param   string   $contentType  The content type key.
     *
     * @return  boolean
     *
     * @since   0.3.0
     */
    private function isFlagged(int $itemId, string $contentType): bool
    {
        $db    = $this->getDatabase();
        $query = $db->getQuery(true)
            ->select($db->quoteName('do_not_translate'))
            ->from($db->quoteName('#__translations_queue'))
            ->where($db->quoteName('content_type') . ' = :contentType')
            ->where($db->quoteName('content_id') . ' = :contentId')
            ->bind(':contentType', $contentType, ParameterType::STRING)
            ->bind(':contentId', $itemId, ParameterType::INTEGER);
        $db->setQuery($query);

        return (bool) $db->loadResult();
    }

    /**
     * Store the flag on the item's queue row, creating the row only to record an opt-out.
     *
     * @param   integer  $itemId          The source item id.
     * @param   string   $contentType     The content type key.
     * @param   integer  $doNotTranslate  1 to opt out of translation, 0 to allow it.
     *
     * @return  void
     *
     * @since   0.3.0
     */
    private function storeFlag(int $itemId, string $contentType, int $doNotTranslate): void
    {
        $db      = $this->getDatabase();
        $queueId = $this->queueId($itemId, $contentType);

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
            'content_type'     => $contentType,
            'content_id'       => $itemId,
            'do_not_translate' => 1,
        ];
        $db->insertObject('#__translations_queue', $row);
    }

    /**
     * A source item's queue row id, or null when the item is not in the queue.
     *
     * @param   integer  $sourceId     The source item id.
     * @param   string   $contentType  The content type key.
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
     * @param   string   $contentType    The content type key.
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
     * Mark a source item's translations as needing re-translation.
     *
     * For a versioned type only translations made from an older version go back to pending. The
     * version is compared as newer rather than different, because a version row is also stored
     * without a new version being added: for a version note, and for the keep forever toggle, which
     * carries the toggled row's id.
     *
     * @param   integer       $queueId    The source item's queue row id.
     * @param   integer|null  $versionId  The version just stored for the source, or null when the
     *                                    content type has no version history.
     *
     * @return  void
     *
     * @since   0.7.0
     */
    private function invalidateTranslations(int $queueId, ?int $versionId = null): void
    {
        $pendingState = 'pending';

        // A published translation is updated in place, so it stays on the site.
        $staleStates = ['review', 'approved', 'published'];

        $db    = $this->getDatabase();
        $query = $db->getQuery(true)
            ->update($db->quoteName('#__translations_queue_states'))
            ->set($db->quoteName('translation_state') . ' = :pending')
            ->where($db->quoteName('queue_id') . ' = :queueId')
            ->whereIn($db->quoteName('translation_state'), $staleStates, ParameterType::STRING)
            ->bind(':pending', $pendingState, ParameterType::STRING)
            ->bind(':queueId', $queueId, ParameterType::INTEGER);

        // A type with no version history has nothing to compare, so every stale cell goes back.
        if ($versionId !== null) {
            $query->where($db->quoteName('source_version_id') . ' < :versionId')
                ->bind(':versionId', $versionId, ParameterType::INTEGER);
        }

        $db->setQuery($query);
        $db->execute();
    }

    /**
     * Send a source menu item's translations back to pending when its title changed during the save.
     *
     * @param   integer  $itemId  The menu item id.
     *
     * @return  void
     *
     * @since   0.9.0
     */
    private function invalidateRenamedMenuItem(int $itemId): void
    {
        if (!isset($this->capturedTitleChanges[$itemId])) {
            return;
        }

        $queueId = $this->capturedTitleChanges[$itemId];
        unset($this->capturedTitleChanges[$itemId]);

        // Never let a failure here break the save this is running inside.
        try {
            $this->invalidateTranslations($queueId);
        } catch (\Throwable $e) {
            Log::add(
                \sprintf('Could not invalidate translations of menu item %d: %s', $itemId, $e->getMessage()),
                Log::WARNING,
                'translations'
            );
        }
    }

    /**
     * The title a source item currently has in the database.
     *
     * @param   integer  $itemId  The source item id.
     * @param   string   $table   The content type's table.
     *
     * @return  string
     *
     * @since   0.9.0
     */
    private function storedTitle(int $itemId, string $table): string
    {
        $db    = $this->getDatabase();
        $query = $db->getQuery(true)
            ->select($db->quoteName('title'))
            ->from($db->quoteName($table))
            ->where($db->quoteName('id') . ' = :itemId')
            ->bind(':itemId', $itemId, ParameterType::INTEGER);
        $db->setQuery($query);

        return (string) $db->loadResult();
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
     * @param   string   $contentType  The content type key.
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
