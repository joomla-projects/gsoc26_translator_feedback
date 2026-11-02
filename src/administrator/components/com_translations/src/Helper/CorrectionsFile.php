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

/**
 * Reads a file of corrections to translations that were made outside the translator feedback view.
 *
 * A set of corrections, such as a repair of a whole site or a list a language team worked through,
 * is too large to make one translation at a time. The file says per translation which fields change
 * into what, and which feedback the changes stand for. Each field carries the SHA-256 of the value
 * it was corrected from, so a field that was edited since the file was made is left alone rather
 * than overwritten, and a field that already holds its correction is recognised as done: running
 * the same file again changes nothing.
 *
 * The file is JSON:
 *
 *     {
 *       "format": "translations-corrections/1",
 *       "source_origin": "repair",
 *       "items": [
 *         {"type": "com_content.article", "source_id": 157, "language": "de-DE", "id": 2221,
 *          "fields": {"title": {"before": "<sha256 of the current value>", "after": "Datenschutz-Workflow"}}}
 *       ],
 *       "feedback": [
 *         {"type": "com_content.article", "source_id": 157, "language": "de-DE", "source_text": "Privacy Workflow",
 *          "machine_draft": "Privacy Workflow", "human_correction": "Datenschutz-Workflow", "occurrences": 1}
 *       ]
 *     }
 *
 * @since  1.5.0
 */
final class CorrectionsFile
{
    /**
     * The format this reader understands.
     *
     * @var    string
     * @since  1.5.0
     */
    public const FORMAT = 'translations-corrections/1';

    /**
     * The origin recorded on the feedback when the file names none.
     *
     * @var    string
     * @since  1.5.0
     */
    public const DEFAULT_ORIGIN = 'repair';

    /**
     * The field still holds the value the correction was made from: apply it.
     *
     * @var    string
     * @since  1.5.0
     */
    public const APPLY = 'apply';

    /**
     * The field already holds the correction.
     *
     * @var    string
     * @since  1.5.0
     */
    public const DONE = 'done';

    /**
     * The field was changed since the correction was made, so it is left alone.
     *
     * @var    string
     * @since  1.5.0
     */
    public const CHANGED = 'changed';

    /**
     * Read and check a corrections file.
     *
     * @param   string  $json  The file's contents.
     *
     * @return  array{origin: string, items: array<int, array>, feedback: array<int, array>}
     *
     * @throws  \InvalidArgumentException  When the file is not a corrections file this reader understands.
     *
     * @since   1.5.0
     */
    public static function parse(string $json): array
    {
        $data = json_decode($json, true);

        if (!\is_array($data) || ($data['format'] ?? null) !== self::FORMAT) {
            throw new \InvalidArgumentException(\sprintf('This is not a corrections file in the format %s.', self::FORMAT));
        }

        $origin = (string) ($data['source_origin'] ?? self::DEFAULT_ORIGIN);

        // The origin goes into a varchar(20) column and is offered as a filter in the rules list.
        if (!preg_match('/^[a-z][a-z0-9_]{0,19}$/', $origin)) {
            throw new \InvalidArgumentException('The source_origin must be a short lower-case name, such as "repair".');
        }

        $items = [];

        foreach ((array) ($data['items'] ?? []) as $index => $item) {
            $items[] = self::item($item, (int) $index);
        }

        $feedback = [];

        foreach ((array) ($data['feedback'] ?? []) as $index => $entry) {
            $feedback[] = self::feedback($entry, (int) $index);
        }

        return ['origin' => $origin, 'items' => $items, 'feedback' => $feedback];
    }

    /**
     * Whether a field's correction is to be applied, is applied already, or no longer fits.
     *
     * @param   string  $current     The field's value as it is now.
     * @param   array   $correction  The field's correction: 'before' (SHA-256) and 'after'.
     *
     * @return  string  One of APPLY, DONE or CHANGED.
     *
     * @since   1.5.0
     */
    public static function status(string $current, array $correction): string
    {
        if ($current === (string) $correction['after']) {
            return self::DONE;
        }

        return hash_equals((string) $correction['before'], self::hash($current)) ? self::APPLY : self::CHANGED;
    }

    /**
     * The print a correction identifies the value it was made from by.
     *
     * @param   string  $value  A field's value.
     *
     * @return  string  The SHA-256 of the value, in lower-case hexadecimal.
     *
     * @since   1.5.0
     */
    public static function hash(string $value): string
    {
        return hash('sha256', $value);
    }

    /**
     * Check one translation's corrections.
     *
     * @param   mixed    $item   The entry as decoded.
     * @param   integer  $index  Its position in the list, for the error message.
     *
     * @return  array{type: string, source_id: int, language: string, id: int, fields: array<string, array{before: string, after: string}>}
     *
     * @throws  \InvalidArgumentException  When the entry is incomplete.
     *
     * @since   1.5.0
     */
    private static function item($item, int $index): array
    {
        if (!\is_array($item)) {
            throw new \InvalidArgumentException(\sprintf('Item %d is not an object.', $index));
        }

        $checked = self::identity($item, 'Item ' . $index);
        $id      = (int) ($item['id'] ?? 0);
        $fields  = [];

        if ($id <= 0) {
            throw new \InvalidArgumentException(\sprintf('Item %d has no id of the translation.', $index));
        }

        foreach ((array) ($item['fields'] ?? []) as $name => $field) {
            if (
                !preg_match('/^[a-z_][a-z0-9_]*$/', (string) $name)
                || !\is_array($field)
                || !preg_match('/^[0-9a-f]{64}$/', (string) ($field['before'] ?? ''))
                || !\is_string($field['after'] ?? null)
            ) {
                throw new \InvalidArgumentException(\sprintf('Item %d has a field "%s" without a before print and an after text.', $index, $name));
            }

            $fields[(string) $name] = ['before' => (string) $field['before'], 'after' => $field['after']];
        }

        if ($fields === []) {
            throw new \InvalidArgumentException(\sprintf('Item %d corrects no fields.', $index));
        }

        return $checked + ['id' => $id, 'fields' => $fields];
    }

    /**
     * Check one piece of feedback.
     *
     * @param   mixed    $entry  The entry as decoded.
     * @param   integer  $index  Its position in the list, for the error message.
     *
     * @return  array{type: string, source_id: int, language: string, source_text: string, machine_draft: string, human_correction: string, occurrences: int}
     *
     * @throws  \InvalidArgumentException  When the entry is incomplete.
     *
     * @since   1.5.0
     */
    private static function feedback($entry, int $index): array
    {
        if (!\is_array($entry)) {
            throw new \InvalidArgumentException(\sprintf('Feedback %d is not an object.', $index));
        }

        $checked = self::identity($entry, 'Feedback ' . $index);

        foreach (['source_text', 'machine_draft', 'human_correction'] as $key) {
            if (!\is_string($entry[$key] ?? null) || trim($entry[$key]) === '') {
                throw new \InvalidArgumentException(\sprintf('Feedback %d has no %s.', $index, $key));
            }

            $checked[$key] = $entry[$key];
        }

        if (trim($checked['machine_draft']) === trim($checked['human_correction'])) {
            throw new \InvalidArgumentException(\sprintf('Feedback %d corrects nothing: its draft and its correction are the same.', $index));
        }

        $checked['occurrences'] = max(1, (int) ($entry['occurrences'] ?? 1));

        return $checked;
    }

    /**
     * Check the part of an entry that names the source item and the language.
     *
     * @param   array   $entry  The entry as decoded.
     * @param   string  $label  How the entry is named in an error message.
     *
     * @return  array{type: string, source_id: int, language: string}
     *
     * @throws  \InvalidArgumentException  When a part is missing.
     *
     * @since   1.5.0
     */
    private static function identity(array $entry, string $label): array
    {
        $type     = (string) ($entry['type'] ?? '');
        $sourceId = (int) ($entry['source_id'] ?? 0);
        $language = (string) ($entry['language'] ?? '');

        if (!preg_match('/^com_[a-z]+\.[a-z]+$/', $type) || $sourceId <= 0 || !preg_match('/^[a-z]{2,3}-[A-Z]{2}$/', $language)) {
            throw new \InvalidArgumentException(\sprintf('%s needs a type, a source_id and a language.', $label));
        }

        return ['type' => $type, 'source_id' => $sourceId, 'language' => $language];
    }
}
