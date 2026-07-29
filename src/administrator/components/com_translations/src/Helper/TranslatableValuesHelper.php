<?php

/**
 * @package     Joomla.Administrator
 * @subpackage  com_translations
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Component\Translations\Administrator\Helper;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use Joomla\Component\Fields\Administrator\Helper\FieldsHelper;
use Joomla\Registry\Registry;

/**
 * Reads an item's translatable values, keyed the same way wherever they are read.
 *
 * The producer stores the values it translated and the editor reads the values a translator
 * ends up with; the two are compared to build the feedback pairs, so both sides key a field
 * by the same name here rather than each deriving its own.
 *
 * @since  0.7.0
 */
class TranslatableValuesHelper
{
    /**
     * Custom-field types whose values are translatable.
     *
     * @var    string[]
     * @since  0.7.0
     */
    public const TRANSLATABLE_FIELD_TYPES = ['text', 'textarea', 'editor', 'note'];

    /**
     * Prefix that namespaces a custom field in the feedback maps, so it never collides with a column field.
     *
     * @var    string
     * @since  0.7.0
     */
    public const CUSTOM_FIELD_PREFIX = 'com_fields:';

    /**
     * Flatten an item's translatable fields into one value map keyed by field.
     *
     * Plain columns are read directly; a JSON column's sub-fields are read from inside it
     * and keyed by their sub-field name (the same keys the form uses). Empty values are kept,
     * so every field the item has is present. The field list comes from the content type map:
     * a plain name is a column, an array maps a JSON column to its translatable sub-keys.
     *
     * @param   array  $row                 The item's column values.
     * @param   array  $translatableFields  The content type's translatable field list.
     *
     * @return  array  The field values keyed by field name.
     *
     * @since   0.7.0
     */
    public static function flattenFields(array $row, array $translatableFields): array
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
     * Gather an item's translatable custom-field values, keyed by field name.
     *
     * Read with FieldsHelper directly (the raw stored value, not the display HTML), and limited
     * to the translatable types so only fields a translator can correct are returned. A content
     * type with no custom-field context returns nothing.
     *
     * @param   array  $item        The item's column values.
     * @param   array  $properties  The content type's properties from the map.
     *
     * @return  array  Per field name, ['label' => string, 'value' => string, 'type' => string].
     *
     * @since   0.7.0
     */
    public static function collectCustomFields(array $item, array $properties): array
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
}
