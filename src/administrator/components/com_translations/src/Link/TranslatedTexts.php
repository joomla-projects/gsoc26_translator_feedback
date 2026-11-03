<?php

/**
 * @package     Joomla.Administrator
 * @subpackage  com_translations
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Component\Translations\Administrator\Link;

use Joomla\Component\Translations\Administrator\Helper\ContentTypesHelper;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Changes the links in translations that exist already.
 *
 * A translation's links are changed in place, in its text columns, and in the machine draft
 * recorded for it as well: the translator's correction is compared against that draft, so a link
 * changed in only one of them would count as a correction the translator made. Only the links
 * change, so no version is stored and nothing is sent for translation again.
 *
 * Only an item's own text columns are changed. Links in custom fields are pointed at the right
 * language when the item is translated.
 *
 * @since  2.0.0
 */
final class TranslatedTexts
{
    /**
     * The trashed state of Joomla's content tables.
     *
     * @var    integer
     * @since  2.0.0
     */
    private const TRASHED = -2;

    /**
     * Build the service.
     *
     * @param   DatabaseInterface  $db  The database.
     *
     * @since   2.0.0
     */
    public function __construct(private readonly DatabaseInterface $db)
    {
    }

    /**
     * Point the links in a language's translations at an item's translation, now that it exists.
     *
     * A translation made before the item it links to could only keep the link to the source
     * language's page. Every translation into the language that may link to the item is read, and
     * its links are pointed at the language's pages as far as these exist now.
     *
     * @param   string   $contentType  The content type just translated, e.g. 'com_content.article'.
     * @param   integer  $sourceId     The id of the source item just translated.
     * @param   string   $language     The language it was translated into.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function repointLinksTo(string $contentType, int $sourceId, string $language): void
    {
        $needles = $this->needles($contentType, $sourceId);

        if ($needles === []) {
            return;
        }

        $translator = new LinkTranslator(new DatabaseLinkLookup($this->db));
        $repoint    = static fn (string $html): string => $translator->translateHtml($html, $language);

        foreach (ContentTypesHelper::getContentTypes() as $textType) {
            foreach ($this->translationsMentioning($textType, $needles, $language) as $id) {
                $this->rewrite($textType, $id, $language, $repoint);
            }
        }
    }

    /**
     * Change the links in one translation.
     *
     * @param   string                     $contentType  The translation's content type key.
     * @param   integer                    $id           The translation's id.
     * @param   string                     $language     The translation's language.
     * @param   callable(string): string   $rewrite      Gets a text column's HTML and returns it with
     *                                                   its links changed.
     *
     * @return  boolean  Whether anything changed.
     *
     * @since   2.0.0
     */
    public function rewrite(string $contentType, int $id, string $language, callable $rewrite): bool
    {
        $properties = ContentTypesHelper::getProperties($contentType);
        $table      = (string) ($properties['table'] ?? '');
        $columns    = self::textColumns($properties);

        if ($columns === []) {
            return false;
        }

        $query = $this->db->getQuery(true)
            ->select($this->db->quoteName($columns))
            ->from($this->db->quoteName($table))
            ->where($this->db->quoteName('id') . ' = :id')
            ->bind(':id', $id, ParameterType::INTEGER);
        $row = $this->db->setQuery($query)->loadAssoc();

        if (!$row) {
            return false;
        }

        $changed = [];

        foreach ($columns as $column) {
            $value = (string) ($row[$column] ?? '');
            $new   = $rewrite($value);

            if ($new !== $value) {
                $changed[$column] = $new;
            }
        }

        if ($changed === []) {
            return false;
        }

        $update = $this->db->getQuery(true)
            ->update($this->db->quoteName($table))
            ->where($this->db->quoteName('id') . ' = :id')
            ->bind(':id', $id, ParameterType::INTEGER);

        foreach (array_keys($changed) as $index => $column) {
            $update->set($this->db->quoteName($column) . ' = :value' . $index)
                ->bind(':value' . $index, $changed[$column], ParameterType::STRING);
        }

        $this->db->setQuery($update)->execute();
        $this->rewriteMachineDraft($contentType, $properties, $id, $language, $rewrite);

        return true;
    }

    /**
     * The text columns of a content type: its translatable fields that are columns of their own.
     *
     * @param   array  $properties  The content type's properties from the map.
     *
     * @return  string[]
     *
     * @since   2.0.0
     */
    private static function textColumns(array $properties): array
    {
        return array_values(array_filter(
            (array) ($properties['translatableFields'] ?? []),
            static fn ($field): bool => \is_string($field) && $field !== 'title'
        ));
    }

    /**
     * What a link to an item has in it, as a search for candidates; a match still has to be read.
     *
     * @param   string   $contentType  The content type key.
     * @param   integer  $id           The item id.
     *
     * @return  string[]
     *
     * @since   2.0.0
     */
    private function needles(string $contentType, int $id): array
    {
        return match ($contentType) {
            'com_menus.item' => array_map(
                static fn (int $menuItemId): string => 'Itemid=' . $menuItemId,
                array_merge([$id], $this->aliasesOf($id))
            ),
            'com_content.article', 'com_categories.category', 'com_tags.tag' => ['id=' . $id],
            default => [],
        };
    }

    /**
     * The alias menu items that stand for a menu item.
     *
     * @param   integer  $id  The menu item id.
     *
     * @return  int[]
     *
     * @since   2.0.0
     */
    private function aliasesOf(int $id): array
    {
        $type    = MenuItem::ALIAS;
        $trashed = self::TRASHED;
        $query   = $this->db->getQuery(true)
            ->select($this->db->quoteName(['id', 'params']))
            ->from($this->db->quoteName('#__menu'))
            ->where($this->db->quoteName('type') . ' = :type')
            ->where($this->db->quoteName('published') . ' <> :trashed')
            ->bind(':type', $type, ParameterType::STRING)
            ->bind(':trashed', $trashed, ParameterType::INTEGER);

        $aliases = [];

        foreach ($this->db->setQuery($query)->loadObjectList() as $row) {
            $params = json_decode((string) $row->params, true);

            if (\is_array($params) && (int) ($params['aliasoptions'] ?? 0) === $id) {
                $aliases[] = (int) $row->id;
            }
        }

        return $aliases;
    }

    /**
     * The translations into a language whose text columns hold one of the needles.
     *
     * @param   string    $contentType  The content type key.
     * @param   string[]  $needles      What to search for.
     * @param   string    $language     The language.
     *
     * @return  int[]
     *
     * @since   2.0.0
     */
    private function translationsMentioning(string $contentType, array $needles, string $language): array
    {
        $properties = ContentTypesHelper::getProperties($contentType);
        $columns    = self::textColumns($properties);
        $context    = (string) ($properties['context_associations'] ?? '');
        $stateField = (string) ($properties['stateField'] ?? 'state');

        if ($columns === [] || $context === '') {
            return [];
        }

        $trashed = self::TRASHED;
        $query   = $this->db->getQuery(true)
            ->select($this->db->quoteName('item.id'))
            ->from($this->db->quoteName((string) ($properties['table'] ?? ''), 'item'))
            ->join(
                'INNER',
                $this->db->quoteName('#__associations', 'association'),
                $this->db->quoteName('association.id') . ' = ' . $this->db->quoteName('item.id')
                . ' AND ' . $this->db->quoteName('association.context') . ' = :context'
            )
            ->where($this->db->quoteName('item.language') . ' = :language')
            ->where($this->db->quoteName('item.' . $stateField) . ' <> :trashed')
            ->bind(':context', $context, ParameterType::STRING)
            ->bind(':language', $language, ParameterType::STRING)
            ->bind(':trashed', $trashed, ParameterType::INTEGER);

        if (isset($properties['limitToExtension'])) {
            $extension = (string) $properties['limitToExtension'];
            $query->where($this->db->quoteName('item.extension') . ' = :extension')
                ->bind(':extension', $extension, ParameterType::STRING);
        }

        $conditions = [];
        $patterns   = [];

        foreach ($columns as $column) {
            foreach ($needles as $needle) {
                $index        = \count($patterns);
                $patterns[]   = '%' . $needle . '%';
                $conditions[] = $this->db->quoteName('item.' . $column) . ' LIKE :needle' . $index;
            }
        }

        $query->where('(' . implode(' OR ', $conditions) . ')');

        foreach ($patterns as $index => $pattern) {
            $query->bind(':needle' . $index, $patterns[$index], ParameterType::STRING);
        }

        return array_map('intval', $this->db->setQuery($query)->loadColumn());
    }

    /**
     * Change the links in the machine draft recorded for a translation the same way.
     *
     * @param   string                     $contentType  The translation's content type key.
     * @param   array                      $properties   The content type's properties from the map.
     * @param   integer                    $id           The translation's id.
     * @param   string                     $language     The translation's language.
     * @param   callable(string): string   $rewrite      Changes the links in a piece of HTML.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function rewriteMachineDraft(string $contentType, array $properties, int $id, string $language, callable $rewrite): void
    {
        $context      = (string) ($properties['context_associations'] ?? '');
        $groupContext = $context;

        $query = $this->db->getQuery(true)
            ->select($this->db->quoteName(['state.id', 'state.machine_draft']))
            ->from($this->db->quoteName('#__associations', 'own'))
            ->join(
                'INNER',
                $this->db->quoteName('#__associations', 'other'),
                $this->db->quoteName('other.key') . ' = ' . $this->db->quoteName('own.key')
                . ' AND ' . $this->db->quoteName('other.context') . ' = :groupContext'
            )
            ->join(
                'INNER',
                $this->db->quoteName('#__translations_queue', 'queue'),
                $this->db->quoteName('queue.content_id') . ' = ' . $this->db->quoteName('other.id')
                . ' AND ' . $this->db->quoteName('queue.content_type') . ' = :contentType'
            )
            ->join(
                'INNER',
                $this->db->quoteName('#__translations_queue_states', 'state'),
                $this->db->quoteName('state.queue_id') . ' = ' . $this->db->quoteName('queue.id')
                . ' AND ' . $this->db->quoteName('state.target_language') . ' = :language'
            )
            ->where($this->db->quoteName('own.context') . ' = :context')
            ->where($this->db->quoteName('own.id') . ' = :id')
            ->bind(':groupContext', $groupContext, ParameterType::STRING)
            ->bind(':contentType', $contentType, ParameterType::STRING)
            ->bind(':language', $language, ParameterType::STRING)
            ->bind(':context', $context, ParameterType::STRING)
            ->bind(':id', $id, ParameterType::INTEGER);

        foreach ($this->db->setQuery($query)->loadObjectList() as $state) {
            $draft = json_decode((string) $state->machine_draft, true);

            if (!\is_array($draft)) {
                continue;
            }

            foreach (self::textColumns($properties) as $column) {
                if (\is_string($draft[$column] ?? null)) {
                    $draft[$column] = $rewrite($draft[$column]);
                }
            }

            $json    = (string) json_encode($draft);
            $stateId = (int) $state->id;
            $update  = $this->db->getQuery(true)
                ->update($this->db->quoteName('#__translations_queue_states'))
                ->set($this->db->quoteName('machine_draft') . ' = :draft')
                ->where($this->db->quoteName('id') . ' = :stateId')
                ->bind(':draft', $json, ParameterType::STRING)
                ->bind(':stateId', $stateId, ParameterType::INTEGER);
            $this->db->setQuery($update)->execute();
        }
    }
}
