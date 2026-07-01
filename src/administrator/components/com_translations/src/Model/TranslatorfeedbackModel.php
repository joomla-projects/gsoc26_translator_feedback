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
use Joomla\CMS\Event\CustomFields\PrepareDomEvent;
use Joomla\CMS\Extension\ComponentInterface;
use Joomla\CMS\Factory;

use Joomla\CMS\Form\Form;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\MVC\Factory\MVCFactoryServiceInterface;
use Joomla\CMS\MVC\Model\AdminModel;
use Joomla\CMS\MVC\Model\FormModel;
use Joomla\Component\Fields\Administrator\Helper\FieldsHelper;
use Joomla\Component\Translations\Administrator\Helper\ContentTypesHelper;
use Joomla\Database\ParameterType;
use Joomla\Event\DispatcherInterface;
use Joomla\Registry\Registry;

/**
 * Side-by-side translation feedback model.
 *
 * Loads one source item and its translation in a single target language for
 * display, for any content type listed in the content type map. The queue tables
 * store no pointer to the draft, so the translation is resolved from the source
 * through Joomla associations.
 *
 * @since  0.2.0
 */
class TranslatorfeedbackModel extends FormModel
{
    /**
     * Custom-field types whose values are translatable.
     *
     * @var    string[]
     * @since  0.4.0
     */
    private const TRANSLATABLE_FIELD_TYPES = ['text', 'textarea', 'editor', 'note'];

    /**
     * Prefix that namespaces a custom field in the feedback maps, so it never collides with a column field.
     *
     * @var    string
     * @since  0.4.0
     */
    private const CUSTOM_FIELD_PREFIX = 'com_fields:';

    /**
     * Cached source + translation pair (see getItem()).
     *
     * @var    object|null
     * @since  0.2.0
     */
    private $item;

    /**
     * Read the request state: which source item, which content type, which target language.
     *
     * @return  void
     *
     * @since   0.2.0
     */
    protected function populateState()
    {
        $input = Factory::getApplication()->getInput(); // No DI: models are not application aware.

        $this->setState('content_id', $input->getInt('id'));
        $this->setState('content_type', $input->getCmd('contentType', 'com_content.article'));
        $this->setState('target_language', $input->getCmd('target'));
    }

    /**
     * Load the translation feedback form for the current content type.
     *
     * Each content type has its own form (translatorfeedback_<type>.xml) listing
     * the fields that type can translate, so the form name carries the type key.
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
        $source = 'translatorfeedback_' . $this->contentTypeKey();

        // 'jform' namespaces the fields, load_data triggers loadFormData() below.
        $form = $this->loadForm('com_translations.' . $source, $source, ['control' => 'jform', 'load_data' => $loadData]);

        // Custom fields vary per item, so inject the draft's translatable ones into the form at prepare time.
        $item = $this->getItem();

        if ($item->translation_item !== null) {
            $this->injectCustomFields($form, $item->translation_item, ContentTypesHelper::getProperties($item->content_type));
        }

        return $form;
    }

    /**
     * Inject the draft's translatable custom fields into the feedback form.
     *
     * Custom fields are not declared in the static form because they vary per item, so they are
     * built at prepare time the way core's Fields tab does (FieldsHelper::prepareForm): each field
     * type plugin turns its field into a <field> node through onCustomFieldsPrepareDom, the nodes
     * are merged into the form, and each is set to the draft's stored value. Only the translatable
     * types are added, so a translator edits just the fields they can correct.
     *
     * @param   Form   $form        The feedback form.
     * @param   array  $draftItem   The translation draft's column values.
     * @param   array  $properties  The content type's properties from the map.
     *
     * @return  void
     *
     * @since   0.4.0
     */
    private function injectCustomFields(Form $form, array $draftItem, array $properties): void
    {
        $context = (string) ($properties['context_custom_fields'] ?? '');

        if ($context === '') {
            return;
        }

        // Keep only the fields a translator can correct.
        $fields = [];

        foreach (FieldsHelper::getFields($context, $draftItem) as $field) {
            if (\in_array($field->type, self::TRANSLATABLE_FIELD_TYPES, true)) {
                $fields[] = $field;
            }
        }

        if ($fields === []) {
            return;
        }

        // Build the custom field form the way FieldsHelper::prepareForm() does.
        $xml        = new \DOMDocument('1.0', 'UTF-8');
        $fieldsNode = $xml->appendChild(new \DOMElement('form'))->appendChild(new \DOMElement('fields'));
        $fieldsNode->setAttribute('name', 'com_fields');

        // The field type plugins are registered on the global dispatcher (the getFields read above imports them
        // there), so the nodes are built by dispatching on it; the model's own dispatcher would not reach them.
        /** @var DispatcherInterface $dispatcher */
        $dispatcher = Factory::getContainer()->get(DispatcherInterface::class);

        foreach ($fields as $field) {
            $dispatcher->dispatch('onCustomFieldsPrepareDom', new PrepareDomEvent('onCustomFieldsPrepareDom', [
                'subject'  => $field,
                'fieldset' => $fieldsNode,
                'form'     => $form,
            ]));
        }

        $form->load((string) $xml->saveXML());

        // Set each field to the draft's value and size it to match its read-only source pane.
        foreach ($fields as $field) {
            if ($field->rawvalue !== null) {
                $form->setValue($field->name, 'com_fields', $field->rawvalue);
            }

            // A custom textarea carries its own row count; match it to the declared textareas (the editor keeps its natural height).
            if ($field->type === 'textarea') {
                $form->setFieldAttribute($field->name, 'rows', '3', 'com_fields');
            }
        }
    }

    /**
     * Bind the translation item into the form fields.
     *
     * @return  array  The form data (empty when there is no translation yet).
     *
     * @since   0.2.0
     */
    protected function loadFormData()
    {
        $item = $this->getItem();

        if ($item->translation_item === null) {
            return [];
        }

        $values = $this->flattenFields($item->translation_item, $item->translatable_fields);
        $data   = [];

        foreach ($values as $field => $value) {
            $data['translation_' . $field] = $value;
        }

        return $data;
    }

    /**
     * Save the edited translation into its draft item.
     *
     * The write is delegated to the type's managing component so the normal workflow
     * and versioning run; only the translatable fields are overwritten on the existing
     * draft. The translation is resolved (via associations) by getItem().
     *
     * @param   array                    $data         Submitted form values, keyed translation_<field> plus a com_fields array.
     * @param   CMSApplicationInterface  $application  The application, used to boot the component.
     *
     * @return  boolean  True on success.
     *
     * @since   0.2.0
     */
    public function save(array $data, CMSApplicationInterface $application): bool
    {
        $item = $this->getItem();

        if ($item->translation_item === null) {
            throw new \RuntimeException(Text::_('COM_TRANSLATIONS_TRANSLATOR_FEEDBACK_NO_TRANSLATION'));
        }

        $properties         = ContentTypesHelper::getProperties($item->content_type);
        $translatableFields = (array) ($properties['translatableFields'] ?? []);
        $translationId      = (int) $item->translation_item['id'];

        // Reuse the type's admin model - its save() runs the workflow + versioning for us.
        /** @var ComponentInterface&MVCFactoryServiceInterface $component */
        $component = $application->bootComponent((string) ($properties['component'] ?? ''));

        // 'ignore_request' => true: we hand this model our own data, so it must not read state from the current request.
        /** @var AdminModel $model */
        $model = $component->getMVCFactory()->createModel((string) ($properties['model'] ?? ''), 'Administrator', ['ignore_request' => true]);

        // Reload the raw row - plain column values, avoiding the computed objects getItem() adds (e.g. tags).
        $row = $this->loadItem($translationId, (string) ($properties['table'] ?? ''));

        if ($row === null) {
            throw new \RuntimeException(Text::_('COM_TRANSLATIONS_TRANSLATOR_FEEDBACK_NO_TRANSLATION'));
        }

        // Snapshot the translation as it stands now, before the overwrite below replaces it.
        // This is the machine draft (what was produced before this correction); once the row
        // is overwritten these values exist nowhere else, so they must be captured for the feedback pair.
        $machineDraft        = $this->flattenFields($row, $translatableFields);
        $machineCustomFields = $this->collectCustomFields($row, $properties);

        // Overwrite each translated field; anything not submitted keeps the row's current value.
        $row = $this->applyTranslation($row, $translatableFields, $data);

        // The values now on the row are the human correction - the counterpart to the
        // machine draft captured above. Paired field by field, these become the feedback rows.
        $humanCorrection = $this->flattenFields($row, $translatableFields);

        // Custom fields are stored apart from the columns: put the corrected values on the draft for the
        // managing model to persist, and fold both sides into the feedback maps under a namespaced key.
        $submittedCustomFields = (array) ($data['com_fields'] ?? []);

        foreach ($machineCustomFields as $name => $customField) {
            $machineDraft[self::CUSTOM_FIELD_PREFIX . $name]    = $customField['value'];
            $humanCorrection[self::CUSTOM_FIELD_PREFIX . $name] = (string) ($submittedCustomFields[$name] ?? $customField['value']);
        }

        if ($submittedCustomFields !== []) {
            $row['com_fields'] = $submittedCustomFields;
        }

        // com_content's edit form works on a single combined body; keep it consistent with the two columns.
        if (\array_key_exists('introtext', $row) || \array_key_exists('fulltext', $row)) {
            $introtext = (string) ($row['introtext'] ?? '');
            $fulltext  = (string) ($row['fulltext'] ?? '');

            $row['articletext'] = trim($fulltext) !== ''
                ? $introtext . '<hr id="system-readmore">' . $fulltext
                : $introtext;
        }

        $saved = (bool) $model->save($row);

        // Record the correction as feedback - but only once the save actually persisted,
        // and only when there is a queue row to anchor it to.
        if ($saved) {
            $queueId = $this->getQueueId((int) $item->content_id, $item->content_type);

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
     * Feedback is anchored to the queue row for the source item (one row per
     * source item, keyed by content type + id). Returns null when there is no
     * queue row, since feedback cannot be anchored without one.
     *
     * @param   integer  $contentId    The source item id.
     * @param   string   $contentType  The content type key, e.g. 'com_content.article'.
     *
     * @return  integer|null  The queue row id, or null when none exists.
     *
     * @since   0.2.0
     */
    private function getQueueId(int $contentId, string $contentType): ?int
    {
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
     * @param   array    $machineDraft     Pre-edit field values keyed by field.
     * @param   array    $humanCorrection  Post-edit field values keyed by field.
     *
     * @return  void
     *
     * @since   0.2.0
     */
    private function recordFeedback(int $queueId, array $machineDraft, array $humanCorrection): void
    {
        $item           = $this->getItem();
        $sourceValues   = $item->source_values;
        $targetLanguage = (string) $item->target_language;
        $translatorId   = (int) $this->getCurrentUser()->id;
        $db             = $this->getDatabase();

        // The custom-field source values feed the same pairs, under the namespaced key save() used.
        foreach ((array) $item->source_custom_fields as $name => $customField) {
            $sourceValues[self::CUSTOM_FIELD_PREFIX . $name] = $customField['value'];
        }

        foreach ($machineDraft as $field => $machineValue) {
            // Only changed fields are worth learning from - an untouched field is not a correction.
            if (($humanCorrection[$field] ?? '') === $machineValue) {
                continue;
            }

            $row = (object) [
                'queue_id'         => $queueId,
                'source_text'      => $sourceValues[$field] ?? '',
                'machine_draft'    => $machineValue,
                'human_correction' => $humanCorrection[$field] ?? '',
                'target_language'  => $targetLanguage,
                'translator_id'    => $translatorId,
            ];

            $db->insertObject('#__translations_feedback', $row);
        }
    }

    /**
     * Flatten an item's translatable fields into one value map keyed by field.
     *
     * Plain columns are read directly; a JSON column's sub-fields are read from inside it
     * and keyed by their sub-field name (the same keys the form uses). Unlike the producer's
     * collection, empty values are kept, so the editor shows every field the source has.
     * The field list comes from the content type map: a plain name is a column, an array
     * maps a JSON column to its translatable sub-keys.
     *
     * @param   array  $row                 The item's column values.
     * @param   array  $translatableFields  The content type's translatable field list.
     *
     * @return  array  The field values keyed by field name.
     *
     * @since   0.4.0
     */
    private function flattenFields(array $row, array $translatableFields): array
    {
        $values = [];

        foreach ($translatableFields as $field) {
            if (\is_string($field)) {
                $values[$field] = (string) ($row[$field] ?? '');

                continue;
            }

            if (!\is_array($field)) {
                continue;
            }

            foreach ($field as $jsonColumn => $subKeys) {
                $registry = new Registry($row[$jsonColumn] ?? '');

                foreach ((array) $subKeys as $subKey) {
                    $subKey          = (string) $subKey;
                    $values[$subKey] = (string) $registry->get($subKey, '');
                }
            }
        }

        return $values;
    }

    /**
     * Overwrite an item's translatable fields with the submitted translation.
     *
     * Mirrors flattenFields: a plain field takes its submitted value (falling back to the
     * row's current value), a JSON column's sub-fields are set in place so the other keys
     * (such as an image path) survive. Fields not present in the submission are left untouched.
     *
     * @param   array  $row                 The item's column values.
     * @param   array  $translatableFields  The content type's translatable field list.
     * @param   array  $data                Submitted form values, keyed translation_<field>.
     *
     * @return  array  The row with the translated fields applied.
     *
     * @since   0.4.0
     */
    private function applyTranslation(array $row, array $translatableFields, array $data): array
    {
        foreach ($translatableFields as $field) {
            if (\is_string($field)) {
                $row[$field] = $data['translation_' . $field] ?? ($row[$field] ?? '');

                continue;
            }

            if (!\is_array($field)) {
                continue;
            }

            foreach ($field as $jsonColumn => $subKeys) {
                $registry = new Registry($row[$jsonColumn] ?? '');

                foreach ((array) $subKeys as $subKey) {
                    $subKey = (string) $subKey;
                    $registry->set($subKey, $data['translation_' . $subKey] ?? $registry->get($subKey, ''));
                }

                $row[$jsonColumn] = $registry->toString();
            }
        }

        return $row;
    }

    /**
     * Gather an item's translatable custom-field values, keyed by field name.
     *
     * Read with FieldsHelper directly (the raw stored value, not the display HTML), the same read
     * the producer uses, and limited to the translatable types so the editor shows only fields a
     * translator can correct. A content type with no custom-field context returns nothing.
     *
     * @param   array  $item        The item's column values.
     * @param   array  $properties  The content type's properties from the map.
     *
     * @return  array  Per field name, ['label' => string, 'value' => string, 'type' => string].
     *
     * @since   0.4.0
     */
    private function collectCustomFields(array $item, array $properties): array
    {
        $context = (string) ($properties['context_custom_fields'] ?? '');

        if ($context === '') {
            return [];
        }

        $customFields = [];

        foreach (FieldsHelper::getFields($context, $item) as $field) {
            if (!\in_array($field->type, self::TRANSLATABLE_FIELD_TYPES, true)) {
                continue;
            }

            $customFields[$field->name] = [
                'label' => (string) $field->label,
                'value' => (string) $field->rawvalue,
                'type'  => (string) $field->type,
            ];
        }

        return $customFields;
    }

    /**
     * Get the source item and its target-language translation.
     *
     * @return  object  { content_id, content_type, target_language, source_language, source_item,
     *                    translation_item, source_values, source_custom_fields, translation_custom_fields,
     *                    translatable_fields } - the items may be null.
     *
     * @since   0.2.0
     */
    public function getItem()
    {
        if ($this->item !== null) {
            return $this->item;
        }

        $contentId      = (int) $this->getState('content_id');
        $contentType    = (string) $this->getState('content_type');
        $targetLanguage = (string) $this->getState('target_language');

        $properties         = ContentTypesHelper::getProperties($contentType);
        $table              = (string) ($properties['table'] ?? '');
        $context            = (string) ($properties['context_associations'] ?? '');
        $translatableFields = (array) ($properties['translatableFields'] ?? []);

        $sourceItem      = $this->loadItem($contentId, $table);
        $translationItem = null;

        // The queue tables hold no draft pointer, so resolve the translation from the
        // source item through its association group (keyed by language).
        if ($sourceItem !== null && $targetLanguage !== '' && $context !== '') {
            $group = $this->getAssociationGroup($contentId, $context, $table);

            if (isset($group[$targetLanguage])) {
                $translationItem = $this->loadItem((int) $group[$targetLanguage], $table);
            }
        }

        $this->item = (object) [
            'content_id'                => $contentId,
            'content_type'              => $contentType,
            'target_language'           => $targetLanguage,
            'source_language'           => $sourceItem !== null ? (string) ($sourceItem['language'] ?? '') : '',
            'source_item'               => $sourceItem,
            'translation_item'          => $translationItem,
            'source_values'             => $sourceItem !== null ? $this->flattenFields($sourceItem, $translatableFields) : [],
            'source_custom_fields'      => $sourceItem !== null ? $this->collectCustomFields($sourceItem, $properties) : [],
            'translation_custom_fields' => $translationItem !== null ? $this->collectCustomFields($translationItem, $properties) : [],
            'translatable_fields'       => $translatableFields,
        ];

        return $this->item;
    }

    /**
     * Load a single item's raw column values from its table.
     *
     * @param   integer  $id     The item id.
     * @param   string   $table  The content type's database table.
     *
     * @return  array|null  The item row, or null when not found.
     *
     * @since   0.2.0
     */
    private function loadItem(int $id, string $table): ?array
    {
        if ($id <= 0 || $table === '') {
            return null;
        }

        $db    = $this->getDatabase();
        $query = $db->getQuery(true)
            ->select('*')
            ->from($db->quoteName($table))
            ->where($db->quoteName('id') . ' = :id')
            ->bind(':id', $id, ParameterType::INTEGER);
        $db->setQuery($query);

        return $db->loadAssoc() ?: null;
    }

    /**
     * Load the item ids of the source item's association group, keyed by language.
     *
     * Joomla keeps all language versions of an item in one association group under a
     * shared key. Returns an empty array when the item has no associations yet.
     *
     * @param   integer  $sourceItemId  The source item id.
     * @param   string   $context       The associations context, e.g. 'com_content.item'.
     * @param   string   $table         The content type's database table.
     *
     * @return  array  Item ids keyed by language code.
     *
     * @since   0.4.0
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
     * The content type's short key, used to name its feedback form.
     *
     * The map keys items by their full key (com_content.article); the form file is
     * named by the item part (translatorfeedback_article).
     *
     * @return  string  The key after the last dot, e.g. 'article'.
     *
     * @since   0.4.0
     */
    private function contentTypeKey(): string
    {
        $contentType = (string) $this->getState('content_type');
        $dot         = strrpos($contentType, '.');

        return $dot === false ? $contentType : substr($contentType, $dot + 1);
    }
}
