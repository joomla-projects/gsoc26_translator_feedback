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

use Joomla\CMS\Application\ApplicationHelper;
use Joomla\CMS\Application\CMSApplicationInterface;
use Joomla\CMS\Extension\ComponentInterface;
use Joomla\CMS\MVC\Factory\MVCFactoryServiceInterface;
use Joomla\CMS\MVC\Model\AdminModel;
use Joomla\CMS\MVC\Model\BaseDatabaseModel;
use Joomla\Component\Fields\Administrator\Helper\FieldsHelper;
use Joomla\Component\Fields\Administrator\Model\FieldModel;
use Joomla\Component\Translations\Administrator\Helper\ContentTypesHelper;
use Joomla\Database\ParameterType;
use Joomla\Registry\Registry;

/**
 * Producer model: turns a source item and a target language into an unpublished
 * translated draft, linked back to the source via #__associations, with its
 * per-language queue state set to "review" (ready for a translator to correct).
 *
 * @since  0.3.0
 */
class TranslationModel extends BaseDatabaseModel
{
    /**
     * Custom-field types whose values are translatable.
     *
     * @var    string[]
     * @since  0.4.0
     */
    private const TRANSLATABLE_FIELD_TYPES = ['text', 'textarea', 'editor', 'note'];

    /**
     * Prefix that namespaces a custom field in the combined strings collection,
     * so a field never collides with a column name.
     *
     * @var    string
     * @since  0.4.0
     */
    private const CUSTOM_FIELD_PREFIX = 'com_fields:';

    /**
     * Translate a source item into one target language.
     *
     * Creates the unpublished draft with its association to the source and
     * sets the per-language queue state to "review", ready for translator feedback.
     *
     * @param   integer                  $sourceItemId     The source item id.
     * @param   string                   $targetLanguage   The target language code, e.g. 'fr-FR'.
     * @param   string                   $contentType      The content type alias, e.g. 'com_content.article'.
     * @param   CMSApplicationInterface  $application      The application, used to boot the component.
     *
     * @return  void
     *
     * @throws  \RuntimeException  If the item is missing or not translatable, or the draft cannot be created.
     *
     * @since   0.3.0
     */
    public function translate(int $sourceItemId, string $targetLanguage, string $contentType, CMSApplicationInterface $application): void
    {
        $properties = ContentTypesHelper::getProperties($contentType);
        $sourceItem = $this->getSourceItem($sourceItemId, (string) ($properties['table'] ?? ''));

        // Some tables hold several extensions' items; only translate the mapped extension.
        if (isset($properties['limitToExtension'])
            && ($sourceItem['extension'] ?? null) !== $properties['limitToExtension']) {
            throw new \RuntimeException(\sprintf('Item %d is outside the %s extension.', $sourceItemId, $properties['limitToExtension']));
        }

        // An all-languages item is shown for every language, so there is nothing to translate.
        if ($sourceItem['language'] === '*') {
            throw new \RuntimeException(\sprintf('Item %d applies to all languages.', $sourceItemId));
        }

        if ($sourceItem['language'] === $targetLanguage) {
            throw new \RuntimeException(\sprintf('Item %d is already in %s.', $sourceItemId, $targetLanguage));
        }

        if ($this->isDoNotTranslate($sourceItemId, $contentType)) {
            throw new \RuntimeException(\sprintf('Item %d is marked as not to be translated.', $sourceItemId));
        }

        $this->createDraft($sourceItem, $targetLanguage, $contentType, $application, $properties);
        $this->markReadyForReview($sourceItemId, $targetLanguage, $contentType);
    }

    /**
     * Clear the "no need for translation" flag on a source item's queue row.
     *
     * @param   integer  $sourceItemId  The source item id.
     * @param   string   $contentType   The content type alias, e.g. 'com_content.article'.
     *
     * @return  void
     *
     * @since   0.3.0
     */
    public function allowTranslation(int $sourceItemId, string $contentType): void
    {
        $db    = $this->getDatabase();
        $query = $db->getQuery(true)
            ->update($db->quoteName('#__translations_queue'))
            ->set($db->quoteName('do_not_translate') . ' = 0')
            ->where($db->quoteName('content_type') . ' = :contentType')
            ->where($db->quoteName('content_id') . ' = :contentId')
            ->bind(':contentType', $contentType, ParameterType::STRING)
            ->bind(':contentId', $sourceItemId, ParameterType::INTEGER);
        $db->setQuery($query);
        $db->execute();
    }

    /**
     * Load the source item's raw column values.
     *
     * The row is read directly rather than via the component's getItem(), whose
     * computed objects break a re-save.
     *
     * @param   integer  $sourceItemId  The source item id.
     * @param   string   $table         The content type's database table.
     *
     * @return  array  The item's column values.
     *
     * @throws  \RuntimeException  If the item does not exist.
     *
     * @since   0.3.0
     */
    private function getSourceItem(int $sourceItemId, string $table): array
    {
        $db    = $this->getDatabase();
        $query = $db->getQuery(true)
            ->select('*')
            ->from($db->quoteName($table))
            ->where($db->quoteName('id') . ' = :id')
            ->bind(':id', $sourceItemId, ParameterType::INTEGER);
        $db->setQuery($query);

        $sourceItem = $db->loadAssoc();

        if ($sourceItem === null) {
            throw new \RuntimeException(\sprintf('Source item %d not found.', $sourceItemId));
        }

        return $sourceItem;
    }

    /**
     * Check whether the source item is flagged as not to be translated.
     *
     * The flag lives on the item's queue row; an item without a queue row
     * is translatable.
     *
     * @param   integer  $sourceItemId  The source item id.
     * @param   string   $contentType   The content type alias, e.g. 'com_content.article'.
     *
     * @return  boolean  True when the item must not be translated.
     *
     * @since   0.3.0
     */
    private function isDoNotTranslate(int $sourceItemId, string $contentType): bool
    {
        $db    = $this->getDatabase();
        $query = $db->getQuery(true)
            ->select($db->quoteName('do_not_translate'))
            ->from($db->quoteName('#__translations_queue'))
            ->where($db->quoteName('content_type') . ' = :contentType')
            ->where($db->quoteName('content_id') . ' = :contentId')
            ->bind(':contentType', $contentType, ParameterType::STRING)
            ->bind(':contentId', $sourceItemId, ParameterType::INTEGER);
        $db->setQuery($query);

        return (bool) $db->loadResult();
    }

    /**
     * Gather an item's translatable strings into one collection, keyed by field.
     *
     * All of an item's strings are handed over together so a provider keeps the context
     * between them. Empty fields are left out so nothing is translated needlessly. The
     * field list comes from the content type map: a plain name is a column, an array maps
     * a JSON column to its translatable sub-keys (gathered under a dotted path).
     *
     * @param   array  $sourceItem          The source item's column values.
     * @param   array  $translatableFields  The content type's translatable field list.
     *
     * @return  array  The translatable strings keyed by field name.
     *
     * @since   0.4.0
     */
    private function collectTranslatableStrings(array $sourceItem, array $translatableFields): array
    {
        $strings = [];

        foreach ($translatableFields as $field) {
            if (\is_string($field)) {
                $value = (string) ($sourceItem[$field] ?? '');

                if (trim($value) !== '') {
                    $strings[$field] = $value;
                }

                continue;
            }

            if (!\is_array($field)) {
                continue;
            }

            foreach ($field as $jsonColumn => $subKeys) {
                $registry = new Registry($sourceItem[$jsonColumn] ?? '');

                foreach ((array) $subKeys as $subKey) {
                    $subKey = (string) $subKey;
                    $value  = (string) $registry->get($subKey, '');

                    if (trim($value) !== '') {
                        $strings[$jsonColumn . '.' . $subKey] = $value;
                    }
                }
            }
        }

        return $strings;
    }

    /**
     * Gather an item's custom-field values, keyed by field name.
     *
     * Read with FieldsHelper directly (not the display-preparing onContentPrepare), so the raw
     * stored values are returned rather than their rendered HTML. Every non-empty field is
     * collected, each flagged whether its type (text, textarea, editor, note) is translatable, so
     * the caller can translate those and copy the rest unchanged. A content type with no
     * custom-field context returns nothing.
     *
     * @param   array  $sourceItem  The source item's column values.
     * @param   array  $properties  The content type's properties from the map.
     *
     * @return  array  Per field name, ['id' => field id, 'value' => raw value, 'translatable' => bool].
     *
     * @since   0.4.0
     */
    private function collectCustomFields(array $sourceItem, array $properties): array
    {
        $context = (string) ($properties['context_custom_fields'] ?? '');

        if ($context === '') {
            return [];
        }

        $customFields = [];

        foreach (FieldsHelper::getFields($context, $sourceItem) as $field) {
            $value = (string) $field->rawvalue;

            if (trim($value) === '') {
                continue;
            }

            $customFields[$field->name] = [
                'id'           => (int) $field->id,
                'value'        => $value,
                'translatable' => \in_array($field->type, self::TRANSLATABLE_FIELD_TYPES, true),
            ];
        }

        return $customFields;
    }

    /**
     * Give a translated category the source category's directly assigned custom fields.
     *
     * A category's custom fields are scoped to the category id, so a field assigned only to the source
     * category is not assigned to the new draft and so is not stored when the draft is saved. The draft is
     * assigned those same fields here and their values written. Global and ancestor-assigned fields already
     * reach the draft through the save, so they are skipped.
     *
     * @param   integer                                                          $sourceId           The source category id.
     * @param   integer                                                          $draftId            The translated draft category id.
     * @param   array<string, array{id: int, value: string, translatable: bool}> $customFields       The collected custom fields.
     * @param   array<string, string>                                            $draftCustomFields  The values to store, keyed by field name.
     * @param   CMSApplicationInterface                                          $application        The application, used to boot com_fields.
     *
     * @return  void
     *
     * @since   0.4.0
     */
    private function copyDirectCustomFields(int $sourceId, int $draftId, array $customFields, array $draftCustomFields, CMSApplicationInterface $application): void
    {
        $db    = $this->getDatabase();
        $query = $db->getQuery(true)
            ->select($db->quoteName('field_id'))
            ->from($db->quoteName('#__fields_categories'))
            ->where($db->quoteName('category_id') . ' = :sourceId')
            ->bind(':sourceId', $sourceId, ParameterType::INTEGER);
        $db->setQuery($query);

        $directFieldIds = array_map('intval', $db->loadColumn());

        if ($directFieldIds === []) {
            return;
        }

        /** @var ComponentInterface&MVCFactoryServiceInterface $component */
        $component = $application->bootComponent('com_fields');

        /** @var FieldModel $fieldModel */
        $fieldModel = $component->getMVCFactory()->createModel('Field', 'Administrator', ['ignore_request' => true]);

        foreach ($customFields as $name => $customField) {
            $fieldId = (int) $customField['id'];

            // Global and ancestor-assigned fields are already on the draft; only direct ones need copying.
            if (!\in_array($fieldId, $directFieldIds, true)) {
                continue;
            }

            // Assign the draft to the field, guarding the composite key against a re-translation.
            if (!$this->categoryHasField($fieldId, $draftId)) {
                $assignment = (object) ['field_id' => $fieldId, 'category_id' => $draftId];
                $db->insertObject('#__fields_categories', $assignment);
            }

            $fieldModel->setFieldValue((string) $fieldId, (string) $draftId, $draftCustomFields[$name]);
        }
    }

    /**
     * Whether a custom field is already assigned to a category.
     *
     * @param   integer  $fieldId     The field id.
     * @param   integer  $categoryId  The category id.
     *
     * @return  boolean
     *
     * @since   0.4.0
     */
    private function categoryHasField(int $fieldId, int $categoryId): bool
    {
        $db    = $this->getDatabase();
        $query = $db->getQuery(true)
            ->select('COUNT(*)')
            ->from($db->quoteName('#__fields_categories'))
            ->where($db->quoteName('field_id') . ' = :fieldId')
            ->where($db->quoteName('category_id') . ' = :categoryId')
            ->bind(':fieldId', $fieldId, ParameterType::INTEGER)
            ->bind(':categoryId', $categoryId, ParameterType::INTEGER);
        $db->setQuery($query);

        return (bool) $db->loadResult();
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
     * Map translated strings back to their fields for the draft.
     *
     * Mirrors collectTranslatableStrings: a plain field name becomes a column value (falling
     * back to the source when nothing was translated), a JSON column is rebuilt from the
     * source with its translated sub-keys overlaid so untranslated keys (like the image path) survive.
     *
     * @param   array  $sourceItem          The source item's column values.
     * @param   array  $translatableFields  The content type's translatable field list.
     * @param   array  $translated          The translated strings, keyed as collected.
     *
     * @return  array  The draft field values keyed by column.
     *
     * @since   0.4.0
     */
    private function packTranslatedFields(array $sourceItem, array $translatableFields, array $translated): array
    {
        $fields = [];

        foreach ($translatableFields as $field) {
            if (\is_string($field)) {
                $fields[$field] = $translated[$field] ?? (string) ($sourceItem[$field] ?? '');

                continue;
            }

            if (!\is_array($field)) {
                continue;
            }

            foreach ($field as $jsonColumn => $subKeys) {
                $registry = new Registry($sourceItem[$jsonColumn] ?? '');

                foreach ((array) $subKeys as $subKey) {
                    $subKey = (string) $subKey;

                    if (isset($translated[$jsonColumn . '.' . $subKey])) {
                        $registry->set($subKey, $translated[$jsonColumn . '.' . $subKey]);
                    }
                }

                $fields[$jsonColumn] = $registry->toArray();
            }
        }

        return $fields;
    }

    /**
     * Create the unpublished draft for one target language.
     *
     * The draft is saved through the managing component's model so versioning, events and
     * associations run as for a hand created item. A model with an associationsContext
     * writes the #__associations link itself; tags, whose model does not, get it written here.
     *
     * @param   array                    $sourceItem      The source item's column values.
     * @param   string                   $targetLanguage  The target language code.
     * @param   string                   $contentType     The content type alias, e.g. 'com_content.article'.
     * @param   CMSApplicationInterface  $application     The application, used to boot the component.
     * @param   array                    $properties      The content type's properties from the map.
     *
     * @return  void
     *
     * @throws  \RuntimeException  If the draft cannot be saved.
     *
     * @since   0.3.0
     */
    private function createDraft(array $sourceItem, string $targetLanguage, string $contentType, CMSApplicationInterface $application, array $properties): void
    {
        // Save through the managing component's model so versioning and the content events run.
        /** @var ComponentInterface&MVCFactoryServiceInterface $component */
        $component = $application->bootComponent((string) ($properties['component'] ?? ''));

        // Ignore the request because the model gets its data from us.
        /** @var AdminModel $model */
        $model = $component->getMVCFactory()->createModel((string) ($properties['model'] ?? ''), 'Administrator', ['ignore_request' => true]);

        // Ignoring the request skips populateState, so set the state the model still derives from it;
        // a category reads its extension from state to confirm it can be associated.
        foreach ((array) ($properties['modelState'] ?? []) as $stateKey => $stateValue) {
            $model->setState($stateKey, $stateValue);
        }

        // Hand all translatable strings over together: the item's columns and its translatable custom fields.
        $translatableFields = (array) ($properties['translatableFields'] ?? []);
        $customFields       = $this->collectCustomFields($sourceItem, $properties);
        $strings            = $this->collectTranslatableStrings($sourceItem, $translatableFields);

        foreach ($customFields as $name => $customField) {
            if ($customField['translatable']) {
                $strings[self::CUSTOM_FIELD_PREFIX . $name] = $customField['value'];
            }
        }

        $translated = $this->mockTranslate($strings, $targetLanguage);
        $fields     = $this->packTranslatedFields($sourceItem, $translatableFields, $translated);

        $draft = array_merge($fields, [
            'id'       => 0,
            'language' => $targetLanguage,
        ]);

        // Carry every custom field onto the draft (the fields plugin's onContentAfterSave stores them, keyed by
        // name): the translatable ones translated, the rest copied unchanged so a non-translated field is not lost.
        $draftCustomFields = [];

        foreach ($customFields as $name => $customField) {
            $draftCustomFields[$name] = $customField['translatable']
                ? ($translated[self::CUSTOM_FIELD_PREFIX . $name] ?? $customField['value'])
                : $customField['value'];
        }

        if ($draftCustomFields !== []) {
            $draft['com_fields'] = $draftCustomFields;
        }

        // Join the draft to the source's existing association group rather than a fresh one.
        $context                 = (string) ($properties['context_associations'] ?? '');
        $modelWritesAssociations = (bool) ($properties['associationsByModel'] ?? true);
        $associations            = [];

        if ($context !== '') {
            $associations = $this->getAssociationGroup((int) $sourceItem['id'], $context, (string) ($properties['table'] ?? ''));
            $associations[$sourceItem['language']] = (int) $sourceItem['id'];

            // Update the language's existing translation in place rather than inserting a duplicate.
            if (isset($associations[$targetLanguage])) {
                $draft['id'] = (int) $associations[$targetLanguage];
            }

            // A model with an associationsContext writes the link itself on save.
            if ($modelWritesAssociations) {
                $draft['associations'] = $associations;
            }
        }

        // Suffix on a clash with the source, otherwise let the component build the alias from the title.
        // Set it even when empty, because com_menus derives a menu item's path from the alias during check.
        $slug           = ApplicationHelper::stringURLSafe((string) ($fields['title'] ?? ''), $targetLanguage);
        $draft['alias'] = $slug === $sourceItem['alias'] ? $slug . '-' . strtolower($targetLanguage) : '';

        // Carry the source's untranslated structural fields onto the draft unchanged.
        foreach ((array) ($properties['draftCopyFields'] ?? []) as $field) {
            $draft[$field] = $sourceItem[$field] ?? null;
        }

        // Repoint single foreign keys (such as catid) at the related item's translation, keeping the source's when none exists yet.
        foreach ((array) ($properties['associatedFields'] ?? []) as $field => $relatedType) {
            $relatedItemId = (int) ($sourceItem[$field] ?? 0);

            if ($relatedItemId === 0) {
                continue;
            }

            $translatedId = $this->translatedRelatedId($relatedItemId, (string) $relatedType, $targetLanguage);

            if ($translatedId !== null) {
                $draft[$field] = $translatedId;
            }
        }

        // Repoint many-to-many relations (tags) at the related items' translations, keeping the source's where none exists yet.
        // Tags are keyed in the map by a type alias that can differ from our key (a category's tags use com_content.category).
        $tagTypeAlias = (string) ($properties['context_tags'] ?? $contentType);

        foreach ((array) ($properties['m2m_relation'] ?? []) as $dataKey => $relatedType) {
            $translatedIds = [];

            foreach ($this->getSourceTagIds($tagTypeAlias, (int) $sourceItem['id']) as $relatedItemId) {
                $translatedIds[] = $this->translatedRelatedId($relatedItemId, (string) $relatedType, $targetLanguage) ?? $relatedItemId;
            }

            $draft[$dataKey] = $translatedIds;
        }

        // Keep the draft unpublished until a translator approves it.
        $draft[(string) ($properties['stateField'] ?? '')] = 0;

        // Force any fields the draft must hold at a fixed value, e.g. a translated menu item is never the home item.
        foreach ((array) ($properties['draftForceFields'] ?? []) as $field => $value) {
            $draft[$field] = $value;
        }

        // A content type kept in language specific containers (a menu item lives in a menu) gets the target
        // language container, created when it does not exist yet.
        if (isset($properties['languageMenu'])) {
            $menuField         = (string) $properties['languageMenu'];
            $draft[$menuField] = $this->deriveLanguageMenu(
                $component,
                (string) ($sourceItem[$menuField] ?? ''),
                (string) $sourceItem['language'],
                $targetLanguage
            );
        }

        // com_content combines intro and full text into one body field; only relevant when those fields exist.
        if (isset($fields['introtext']) || isset($fields['fulltext'])) {
            $introtext = (string) ($fields['introtext'] ?? '');
            $fulltext  = (string) ($fields['fulltext'] ?? '');

            $draft['articletext'] = trim($fulltext) !== ''
                ? $introtext . '<hr id="system-readmore">' . $fulltext
                : $introtext;
        }

        if (!$model->save($draft)) {
            throw new \RuntimeException(
                \sprintf('Could not create the %s draft for item %d.', $targetLanguage, (int) $sourceItem['id'])
            );
        }

        // A model without an associationsContext (tags) leaves the link unwritten, so write it here.
        if ($context !== '' && !$modelWritesAssociations) {
            $associations[$targetLanguage] = (int) $model->getState($model->getName() . '.id');
            $this->writeAssociations($associations, $context);
        }

        // A category's custom fields are scoped to its own id, so a field assigned only to the source
        // category does not reach the new draft on save; copy those assignments and store their values.
        if (!empty($properties['copyCustomFieldAssignments']) && $customFields !== []) {
            $this->copyDirectCustomFields(
                (int) $sourceItem['id'],
                (int) $model->getState($model->getName() . '.id'),
                $customFields,
                $draftCustomFields,
                $application
            );
        }
    }

    /**
     * Find a related item's translation in the target language.
     *
     * Looks up the related item's association group, the same key mechanism the item itself uses,
     * and returns its target language member, or null when the related item has no translation yet.
     *
     * @param   integer  $relatedItemId   The source related item id, e.g. a category id.
     * @param   string   $relatedType     The related content type alias, e.g. 'com_categories.category'.
     * @param   string   $targetLanguage  The target language code.
     *
     * @return  integer|null  The translated related item id, or null when it has none.
     *
     * @since   0.4.0
     */
    private function translatedRelatedId(int $relatedItemId, string $relatedType, string $targetLanguage): ?int
    {
        $properties = ContentTypesHelper::getProperties($relatedType);
        $group      = $this->getAssociationGroup(
            $relatedItemId,
            (string) ($properties['context_associations'] ?? ''),
            (string) ($properties['table'] ?? '')
        );

        return isset($group[$targetLanguage]) ? (int) $group[$targetLanguage] : null;
    }

    /**
     * Load the tag ids attached to a source item.
     *
     * Tags live in the content item tag map rather than on the item's own table, so they are
     * read separately, keyed by the type alias the tags are stored under and the item id.
     *
     * @param   string   $tagTypeAlias  The type alias the item's tags are stored under, e.g. 'com_content.article'.
     * @param   integer  $sourceItemId  The source item id.
     *
     * @return  integer[]  The attached tag ids.
     *
     * @since   0.4.0
     */
    private function getSourceTagIds(string $tagTypeAlias, int $sourceItemId): array
    {
        $db    = $this->getDatabase();
        $query = $db->getQuery(true)
            ->select($db->quoteName('tag_id'))
            ->from($db->quoteName('#__contentitem_tag_map'))
            ->where($db->quoteName('type_alias') . ' = :tagTypeAlias')
            ->where($db->quoteName('content_item_id') . ' = :contentId')
            ->bind(':tagTypeAlias', $tagTypeAlias, ParameterType::STRING)
            ->bind(':contentId', $sourceItemId, ParameterType::INTEGER);
        $db->setQuery($query);

        return array_map('intval', $db->loadColumn());
    }

    /**
     * Derive a menu item's target language menu, creating that menu when it does not exist.
     *
     * The menu of a source item carries no language association, so the target language menu is named by
     * stripping any source language suffix from the source menutype and appending the target language code
     * ("mainmenu" or "mainmenu-en-gb" becomes "mainmenu-fr-fr"). The menu is created when missing so the
     * translated item has somewhere to live.
     *
     * @param   ComponentInterface&MVCFactoryServiceInterface  $component        The booted managing component.
     * @param   string                                         $sourceMenutype  The source item's menutype.
     * @param   string                                         $sourceLanguage  The source item's language code.
     * @param   string                                         $targetLanguage  The target language code.
     *
     * @return  string  The target language menutype.
     *
     * @throws  \RuntimeException  If the menu cannot be created.
     *
     * @since   0.4.0
     */
    private function deriveLanguageMenu(
        ComponentInterface&MVCFactoryServiceInterface $component,
        string $sourceMenutype,
        string $sourceLanguage,
        string $targetLanguage
    ): string {
        $sourceSuffix = '-' . strtolower($sourceLanguage);
        $base         = str_ends_with($sourceMenutype, $sourceSuffix)
            ? substr($sourceMenutype, 0, -strlen($sourceSuffix))
            : $sourceMenutype;
        $menutype     = $base . '-' . strtolower($targetLanguage);

        /** @var \Joomla\CMS\Table\MenuType $menu */
        $menu = $component->getMVCFactory()->createTable('MenuType', 'Administrator');

        // Nothing to create when the target language menu already exists.
        if ($menu->load(['menutype' => $menutype])) {
            return $menutype;
        }

        $menu->bind(['menutype' => $menutype, 'title' => $menutype, 'client_id' => 0]);

        if (!$menu->check() || !$menu->store()) {
            throw new \RuntimeException(\sprintf('Could not create the %s menu.', $menutype));
        }

        return $menutype;
    }

    /**
     * Load the item ids of the source item's association group, keyed by language.
     *
     * Joomla keeps all language versions of an item in one association group
     * under a shared key. Returns an empty array when the item has no
     * associations yet.
     *
     * @param   integer  $sourceItemId  The source item id.
     * @param   string   $context       The associations context, e.g. 'com_content.item'.
     * @param   string   $table         The content type's database table.
     *
     * @return  array  Item ids keyed by language code.
     *
     * @since   0.3.0
     */
    private function getAssociationGroup(int $sourceItemId, string $context, string $table): array
    {
        $db    = $this->getDatabase();
        $query = $db->getQuery(true)
            ->select($db->quoteName(['item.language', 'item.id']))
            ->from($db->quoteName('#__associations', 'sourceAssociation'))
            ->join(
                'INNER',
                $db->quoteName('#__associations', 'groupAssociation'),
                $db->quoteName('groupAssociation.key') . ' = ' . $db->quoteName('sourceAssociation.key')
            )
            ->join(
                'INNER',
                $db->quoteName($table, 'item'),
                $db->quoteName('item.id') . ' = ' . $db->quoteName('groupAssociation.id')
            )
            ->where($db->quoteName('sourceAssociation.context') . ' = :context')
            ->where($db->quoteName('groupAssociation.context') . ' = :groupContext')
            ->where($db->quoteName('sourceAssociation.id') . ' = :sourceId')
            ->bind(':context', $context, ParameterType::STRING)
            ->bind(':groupContext', $context, ParameterType::STRING)
            ->bind(':sourceId', $sourceItemId, ParameterType::INTEGER);
        $db->setQuery($query);

        return $db->loadAssocList('language', 'id');
    }

    /**
     * Write the source item's association group to #__associations directly.
     *
     * Mirrors the write in AdminModel::save(): the items are removed and re-inserted
     * under one shared key, so every language version stays in one group. Needed for
     * content types whose model sets no associationsContext (tags), where save() leaves
     * the link unwritten.
     *
     * @param   array   $idsByLanguage  The group's item ids keyed by language, including the new draft.
     * @param   string  $context        The associations context, e.g. 'com_tags.item'.
     *
     * @return  void
     *
     * @since   0.4.0
     */
    private function writeAssociations(array $idsByLanguage, string $context): void
    {
        $ids = array_values(array_filter(array_map('intval', $idsByLanguage)));

        // A lone item has nothing to associate with.
        if (\count($ids) < 2) {
            return;
        }

        $db = $this->getDatabase();

        // Clear the items' current rows so the whole group is re-keyed in one place.
        $query = $db->getQuery(true)
            ->delete($db->quoteName('#__associations'))
            ->where($db->quoteName('context') . ' = :context')
            ->whereIn($db->quoteName('id'), $ids, ParameterType::INTEGER)
            ->bind(':context', $context, ParameterType::STRING);
        $db->setQuery($query);
        $db->execute();

        // One shared key ties the language versions together, the way core keys them.
        $key   = md5((string) json_encode($idsByLanguage));
        $query = $db->getQuery(true)
            ->insert($db->quoteName('#__associations'))
            ->columns($db->quoteName(['id', 'context', 'key']));

        foreach ($ids as $id) {
            $query->values(
                implode(',', $query->bindArray([$id, $context, $key], [ParameterType::INTEGER, ParameterType::STRING, ParameterType::STRING]))
            );
        }

        $db->setQuery($query);
        $db->execute();
    }

    /**
     * Set the source item's state for one target language to "review".
     *
     * One state row exists per (queue row, target language); no row means "no
     * translation yet". The first translation inserts the row, a repeat run
     * updates it. The row's modified time is maintained by the database.
     *
     * @param   integer  $sourceItemId    The source item id.
     * @param   string   $targetLanguage  The target language code.
     * @param   string   $contentType     The content type alias, e.g. 'com_content.article'.
     *
     * @return  void
     *
     * @since   0.3.0
     */
    private function markReadyForReview(int $sourceItemId, string $targetLanguage, string $contentType): void
    {
        $queueId     = $this->getOrCreateQueueId($sourceItemId, $contentType);
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
     * Find the queue row for a source item, creating it when missing.
     *
     * The queue holds one row per source item, keyed by content type + id.
     * A source item gets its row with its first translation.
     *
     * @param   integer  $sourceItemId  The source item id.
     * @param   string   $contentType   The content type alias, e.g. 'com_content.article'.
     *
     * @return  integer  The queue row id.
     *
     * @since   0.3.0
     */
    private function getOrCreateQueueId(int $sourceItemId, string $contentType): int
    {
        $db    = $this->getDatabase();
        $query = $db->getQuery(true)
            ->select($db->quoteName('id'))
            ->from($db->quoteName('#__translations_queue'))
            ->where($db->quoteName('content_type') . ' = :contentType')
            ->where($db->quoteName('content_id') . ' = :contentId')
            ->bind(':contentType', $contentType, ParameterType::STRING)
            ->bind(':contentId', $sourceItemId, ParameterType::INTEGER);
        $db->setQuery($query);

        $queueId = $db->loadResult();

        if ($queueId !== null) {
            return (int) $queueId;
        }

        $queueRow = (object) [
            'content_type' => $contentType,
            'content_id'   => $sourceItemId,
        ];

        $db->insertObject('#__translations_queue', $queueRow, 'id');

        return (int) $queueRow->id;
    }
}
