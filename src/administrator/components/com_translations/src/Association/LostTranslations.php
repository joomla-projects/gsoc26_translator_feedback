<?php

/**
 * @package     Joomla.Administrator
 * @subpackage  com_translations
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Component\Translations\Administrator\Association;

use Joomla\Component\Translations\Administrator\Helper\ContentTypesHelper;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Finds the translations that lost their association with their source, and associates them again.
 *
 * Joomla writes an item's association group from the list in its edit form. Saved with a list
 * that is out of date - the form was opened before a translation was made - the group is written
 * without that translation, which then stands on its own: the queue sees a source with no
 * translation in that language and translates it a second time once the source changes.
 *
 * The queue still knows which source was translated into which language, and with which title.
 * For every one whose language is missing from the source's group, the translation is looked for
 * among the items in that language that have no association at all: by that title, and where
 * several carry it, by the fields that point at related items (an article's category). Exactly one
 * match is associated; none or several are reported, with the ids to choose from.
 *
 * @since  3.0.0
 */
final class LostTranslations
{
    /**
     * The translation was found and associated, or would be on a dry run.
     *
     * @var    string
     * @since  3.0.0
     */
    public const ASSOCIATED = 'associated';

    /**
     * No item without an association has the title.
     *
     * @var    string
     * @since  3.0.0
     */
    public const NONE = 'none';

    /**
     * Several items without an association have the title, and nothing tells them apart.
     *
     * @var    string
     * @since  3.0.0
     */
    public const SEVERAL = 'several';

    /**
     * The groups read so far, keyed by content type and item id.
     *
     * @var    array<string, array<string, int>>
     * @since  3.0.0
     */
    private array $groups = [];

    /**
     * Constructor.
     *
     * @param   DatabaseInterface  $database  The site's database.
     *
     * @since   3.0.0
     */
    public function __construct(private readonly DatabaseInterface $database)
    {
    }

    /**
     * Find the lost translations, and associate them again unless this is a dry run.
     *
     * @param   boolean  $dryRun  Only find them.
     *
     * @return  array<array{type: string, source: int, title: string, language: string, draftTitle: string, outcome: string, ids: int[]}>
     *          One entry per source and language whose translation is lost.
     *
     * @since   3.0.0
     */
    public function restore(bool $dryRun): array
    {
        $found   = [];
        $claimed = [];

        foreach (ContentTypesHelper::getContentTypes() as $contentType) {
            $properties = ContentTypesHelper::getProperties($contentType);
            $context    = (string) ($properties['context_associations'] ?? '');
            $table      = (string) ($properties['table'] ?? '');

            if ($context === '' || $table === '') {
                continue;
            }

            foreach ($this->translatedSources($contentType, $table) as $translated) {
                $sourceId = (int) $translated->content_id;
                $language = (string) $translated->target_language;
                $group    = $this->group($sourceId, $context, $table);

                // Still associated, or translated again since: there is nothing lost to find.
                if (isset($group[$language])) {
                    continue;
                }

                $draft = json_decode((string) $translated->machine_draft, true);
                $title = \is_array($draft) ? trim((string) ($draft['title'] ?? '')) : '';

                if ($title === '') {
                    continue;
                }

                $candidates = array_values(array_diff($this->unassociatedByTitle($table, $context, $language, $title, $properties), $claimed));

                if (\count($candidates) > 1) {
                    $candidates = $this->narrowByRelatedFields($candidates, $sourceId, $language, $table, $properties);
                }

                $outcome = match (\count($candidates)) {
                    0       => self::NONE,
                    1       => self::ASSOCIATED,
                    default => self::SEVERAL,
                };

                if ($outcome === self::ASSOCIATED) {
                    $claimed[] = $candidates[0];

                    if (!$dryRun) {
                        $group[(string) $translated->language] = $sourceId;
                        $group[$language]                      = $candidates[0];
                        $this->writeGroup($group, $context);
                    }
                }

                $found[] = [
                    'type'       => $contentType,
                    'source'     => $sourceId,
                    'title'      => (string) $translated->title,
                    'language'   => $language,
                    'draftTitle' => $title,
                    'outcome'    => $outcome,
                    'ids'        => $candidates,
                ];
            }
        }

        return $found;
    }

    /**
     * The sources a translation was made of, one row per source and language.
     *
     * A machine draft is stored once a translation is saved, and kept while the source moves on.
     *
     * @param   string  $contentType  The content type key.
     * @param   string  $table        Its database table.
     *
     * @return  object[]  Each with content_id, target_language, machine_draft, and the source's title and language.
     *
     * @since   3.0.0
     */
    private function translatedSources(string $contentType, string $table): array
    {
        $db    = $this->database;
        $query = $db->getQuery(true)
            ->select($db->quoteName(['queue.content_id', 'state.target_language', 'state.machine_draft', 'item.title', 'item.language']))
            ->from($db->quoteName('#__translations_queue', 'queue'))
            ->join('INNER', $db->quoteName('#__translations_queue_states', 'state'), $db->quoteName('state.queue_id') . ' = ' . $db->quoteName('queue.id'))
            ->join('INNER', $db->quoteName($table, 'item'), $db->quoteName('item.id') . ' = ' . $db->quoteName('queue.content_id'))
            ->where($db->quoteName('state.machine_draft') . ' IS NOT NULL')
            ->where($db->quoteName('queue.content_type') . ' = :contentType')
            ->order($db->quoteName(['queue.content_id', 'state.target_language']))
            ->bind(':contentType', $contentType, ParameterType::STRING);

        return $db->setQuery($query)->loadObjectList();
    }

    /**
     * The items in a language with a title and no association at all, leaving out the trashed.
     *
     * @param   string                $table       The content type's table.
     * @param   string                $context     Its associations context.
     * @param   string                $language    The language.
     * @param   string                $title       The title the translation was made with.
     * @param   array<string, mixed>  $properties  The content type's properties from the map.
     *
     * @return  integer[]  Their ids.
     *
     * @since   3.0.0
     */
    private function unassociatedByTitle(string $table, string $context, string $language, string $title, array $properties): array
    {
        $db    = $this->database;
        $query = $db->getQuery(true)
            ->select($db->quoteName('item.id'))
            ->from($db->quoteName($table, 'item'))
            ->join(
                'LEFT',
                $db->quoteName('#__associations', 'association'),
                $db->quoteName('association.id') . ' = ' . $db->quoteName('item.id')
                . ' AND ' . $db->quoteName('association.context') . ' = :context'
            )
            ->where($db->quoteName('association.id') . ' IS NULL')
            ->where($db->quoteName('item.language') . ' = :language')
            ->where($db->quoteName('item.title') . ' = :title')
            ->order($db->quoteName('item.id'))
            ->bind(':context', $context, ParameterType::STRING)
            ->bind(':language', $language, ParameterType::STRING)
            ->bind(':title', $title, ParameterType::STRING);

        if (isset($properties['stateField'])) {
            $query->where($db->quoteName('item.' . $properties['stateField']) . ' <> -2');
        }

        if (isset($properties['limitToExtension'])) {
            $extension = (string) $properties['limitToExtension'];
            $query->where($db->quoteName('item.extension') . ' = :extension')->bind(':extension', $extension, ParameterType::STRING);
        }

        if (isset($properties['limitToClient'])) {
            $clientId = (int) $properties['limitToClient'];
            $query->where($db->quoteName('item.client_id') . ' = :clientId')->bind(':clientId', $clientId, ParameterType::INTEGER);
        }

        return array_map('intval', $db->setQuery($query)->loadColumn());
    }

    /**
     * Keep the candidates whose related fields point where the source's translation would point.
     *
     * An article's translation sits in the translation of its source's category, or in the source's
     * category while that has none, so two articles with the same title are told apart by it.
     *
     * @param   integer[]             $candidates  The candidates' ids.
     * @param   integer               $sourceId    The source item's id.
     * @param   string                $language    The translation's language.
     * @param   string                $table       The content type's table.
     * @param   array<string, mixed>  $properties  The content type's properties from the map.
     *
     * @return  integer[]  The candidates left; all of them when no field tells them apart.
     *
     * @since   3.0.0
     */
    private function narrowByRelatedFields(array $candidates, int $sourceId, string $language, string $table, array $properties): array
    {
        $db   = $this->database;
        $left = $candidates;

        foreach ((array) ($properties['associatedFields'] ?? []) as $field => $relatedType) {
            $query = $db->getQuery(true)
                ->select($db->quoteName($field))
                ->from($db->quoteName($table))
                ->where($db->quoteName('id') . ' = :sourceId')
                ->bind(':sourceId', $sourceId, ParameterType::INTEGER);
            $sourceValue = (int) $db->setQuery($query)->loadResult();

            $related  = ContentTypesHelper::getProperties((string) $relatedType);
            $group    = $this->group($sourceValue, (string) ($related['context_associations'] ?? ''), (string) ($related['table'] ?? ''));
            $expected = array_values(array_unique([$sourceValue, $group[$language] ?? $sourceValue]));

            $query = $db->getQuery(true)
                ->select($db->quoteName('id'))
                ->from($db->quoteName($table))
                ->whereIn($db->quoteName('id'), $left)
                ->whereIn($db->quoteName($field), $expected)
                ->order($db->quoteName('id'));
            $narrowed = array_map('intval', $db->setQuery($query)->loadColumn());

            if ($narrowed !== []) {
                $left = $narrowed;
            }
        }

        return $left;
    }

    /**
     * The ids of an item's association group, keyed by language.
     *
     * @param   integer  $itemId   The item's id.
     * @param   string   $context  The associations context.
     * @param   string   $table    The content type's table.
     *
     * @return  array<string, int>  Empty when the item has no associations.
     *
     * @since   3.0.0
     */
    private function group(int $itemId, string $context, string $table): array
    {
        if ($context === '' || $table === '') {
            return [];
        }

        $cacheKey = $context . '.' . $itemId;

        if (isset($this->groups[$cacheKey])) {
            return $this->groups[$cacheKey];
        }

        $db    = $this->database;
        $query = $db->getQuery(true)
            ->select($db->quoteName(['item.language', 'item.id']))
            ->from($db->quoteName('#__associations', 'own'))
            ->join('INNER', $db->quoteName('#__associations', 'other'), $db->quoteName('other.key') . ' = ' . $db->quoteName('own.key'))
            ->join('INNER', $db->quoteName($table, 'item'), $db->quoteName('item.id') . ' = ' . $db->quoteName('other.id'))
            ->where($db->quoteName('own.context') . ' = :context')
            ->where($db->quoteName('other.context') . ' = :otherContext')
            ->where($db->quoteName('own.id') . ' = :itemId')
            ->bind(':context', $context, ParameterType::STRING)
            ->bind(':otherContext', $context, ParameterType::STRING)
            ->bind(':itemId', $itemId, ParameterType::INTEGER);

        return $this->groups[$cacheKey] = array_map('intval', $db->setQuery($query)->loadAssocList('language', 'id'));
    }

    /**
     * Write an association group, replacing whatever its members belonged to before.
     *
     * @param   array<string, int>  $idsByLanguage  The group's ids keyed by language.
     * @param   string              $context        The associations context.
     *
     * @return  void
     *
     * @since   3.0.0
     */
    private function writeGroup(array $idsByLanguage, string $context): void
    {
        $db  = $this->database;
        $ids = array_values(array_map('intval', $idsByLanguage));

        $db->setQuery(
            $db->getQuery(true)
                ->delete($db->quoteName('#__associations'))
                ->where($db->quoteName('context') . ' = :context')
                ->whereIn($db->quoteName('id'), $ids)
                ->bind(':context', $context, ParameterType::STRING)
        )->execute();

        // The key Joomla gives a group it writes itself.
        $key   = md5(json_encode($idsByLanguage));
        $query = $db->getQuery(true)
            ->insert($db->quoteName('#__associations'))
            ->columns($db->quoteName(['id', 'context', 'key']));

        foreach ($ids as $id) {
            $query->values(implode(',', [$id, $db->quote($context), $db->quote($key)]));
        }

        $db->setQuery($query)->execute();

        // The groups read so far may hold the members' old groups.
        $this->groups = [];
    }
}
