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

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\MVC\Factory\MVCFactoryInterface;
use Joomla\CMS\MVC\Model\ListModel;
use Joomla\Component\Translations\Administrator\Helper\ContentTypesHelper;
use Joomla\Database\ParameterType;

/**
 * List model for the translation queue grid.
 *
 * One row per source item of the chosen content type, target languages as columns.
 * getItems() then attaches each item's per language translation state
 * map via a single follow-up query.
 *
 * @since  0.1.0
 */
class QueueModel extends ListModel
{
    /**
     * Content type shown until another is chosen in the filter.
     *
     * @var    string
     * @since  0.1.0
     */
    private const DEFAULT_CONTENT_TYPE = 'com_content.article';

    /**
     * Constructor.
     *
     * @param   array                     $config   An optional associative array of configuration settings.
     * @param   MVCFactoryInterface|null  $factory  The factory.
     *
     * @since   0.1.0
     */
    public function __construct($config = [], ?MVCFactoryInterface $factory = null)
    {
        if (empty($config['filter_fields'])) {
            $config['filter_fields'] = [
                'id', 'a.id',
                'title', 'a.title',
                'status',
                'languages',
                'noneed',
            ];
        }

        parent::__construct($config, $factory);
    }

    /**
     * Method to auto-populate the model state.
     *
     * @param   string  $ordering   An optional ordering field.
     * @param   string  $direction  An optional direction (asc|desc).
     *
     * @return  void
     *
     * @since   0.1.0
     */
    protected function populateState($ordering = 'a.title', $direction = 'ASC')
    {
        /** @var \Joomla\CMS\Application\CMSApplication $app */
        $app   = Factory::getApplication(); // No DI because models are not application aware.
        $input = $app->getInput();

        $search = $this->getUserStateFromRequest($this->context . '.filter.search', 'filter_search');
        $this->setState('filter.search', $search);

        // Status and languages are multi-selects. Emptied multi-selects submit nothing,
        // so the helper can't clear them. Read the submitted 'filter' array directly;
        // fall back to stored state otherwise.
        $submittedFilter = $input->get('filter', null, 'array');

        $this->setState('filter.status', $this->getMultiFilterState('status', $submittedFilter));
        $this->setState('filter.languages', $this->getMultiFilterState('languages', $submittedFilter));

        // Read like the multi-selects (from the submitted filter array) so the Clear button resets it too.
        $this->setState('filter.noneed', $this->getSingleFilterState('noneed', $submittedFilter));

        // The content type whose source items fill the grid; chosen by the queue tabs, defaults to articles.
        $contentType = $this->getUserStateFromRequest($this->context . '.filter.contenttype', 'filter_contenttype', self::DEFAULT_CONTENT_TYPE, 'cmd');

        // Fall back to the default when the request names a content type the queue does not handle.
        if (!\in_array($contentType, ContentTypesHelper::getContentTypes(), true)) {
            $contentType = self::DEFAULT_CONTENT_TYPE;
        }

        $this->setState('filter.contenttype', $contentType);

        // Source language: the content language originals are authored in (queue rows).
        // Configurable in config.xml, defaults to en-GB.
        $params = ComponentHelper::getParams('com_translations');
        $this->setState('source_language', (string) $params->get('source_language', 'en-GB'));

        parent::populateState($ordering, $direction);
    }

    /**
     * Multi-select filter value, with workaround for emptied-select-submits-nothing.
     *
     * @param   string      $name             Filter field name (e.g. 'status', 'languages').
     * @param   array|null  $submittedFilter  Submitted 'filter' array, or null on plain page load.
     *
     * @return  array  Selected values (empty = no filter).
     *
     * @since   0.1.0
     */
    private function getMultiFilterState(string $name, ?array $submittedFilter): array
    {
        if (is_array($submittedFilter)) {
            return (array) ($submittedFilter[$name] ?? []);
        }

        /** @var \Joomla\CMS\Application\CMSApplication $app */
        $app    = Factory::getApplication();
        $stored = (array) $app->getUserState($this->context . '.filter', []);

        return (array) ($stored[$name] ?? []);
    }

    /**
     * Single-select filter value, read from the submitted filter array so Clear resets it.
     *
     * @param   string      $name             Filter field name .
     * @param   array|null  $submittedFilter  Submitted 'filter' array, or null on plain page load.
     *
     * @return  string  Selected value (empty = default/no filter).
     *
     * @since   0.3.0
     */
    private function getSingleFilterState(string $name, ?array $submittedFilter): string
    {
        if (is_array($submittedFilter)) {
            return (string) ($submittedFilter[$name] ?? '');
        }

        /** @var \Joomla\CMS\Application\CMSApplication $app */
        $app    = Factory::getApplication();
        $stored = (array) $app->getUserState($this->context . '.filter', []);

        return (string) ($stored[$name] ?? '');
    }

    /**
     * Method to get a store id based on model configuration state.
     *
     * @param   string  $id  A prefix for the store id.
     *
     * @return  string  A store id.
     *
     * @since   0.1.0
     */
    protected function getStoreId($id = '')
    {
        $id .= ':' . $this->getState('filter.search');
        $id .= ':' . implode(',', (array) $this->getState('filter.status'));
        $id .= ':' . implode(',', (array) $this->getState('filter.languages'));
        $id .= ':' . $this->getState('filter.noneed');
        $id .= ':' . $this->getState('filter.contenttype');

        return parent::getStoreId($id);
    }

    /**
     * Build the list query: one row per source-language item (the grid rows).
     *
     * @return  \Joomla\Database\QueryInterface
     *
     * @since   0.1.0
     */
    protected function getListQuery()
    {
        $db             = $this->getDatabase();
        $query          = $db->getQuery(true);
        $sourceLanguage = (string) $this->getState('source_language', 'en-GB');
        $contentType    = (string) $this->getState('filter.contenttype', self::DEFAULT_CONTENT_TYPE);

        $properties = ContentTypesHelper::getProperties($contentType);
        $table      = (string) ($properties['table'] ?? '');
        $stateField = (string) ($properties['stateField'] ?? '');

        $query->select(
            [
                $db->quoteName('a.id'),
                $db->quoteName('a.title'),
                $db->quoteName('a.language'),
                $db->quoteName('a.' . $stateField),
                $db->quoteName('noNeed.do_not_translate'),
            ]
        )
            ->from($db->quoteName($table, 'a'))
            // LEFT join keeps source items that have no queue row; their do_not_translate is then NULL.
            ->join(
                'LEFT',
                $db->quoteName('#__translations_queue', 'noNeed')
                . ' ON ' . $db->quoteName('noNeed.content_id') . ' = ' . $db->quoteName('a.id')
                . ' AND ' . $db->quoteName('noNeed.content_type') . ' = ' . $db->quote($contentType)
            )
            ->where($db->quoteName('a.language') . ' = :sourceLanguage')
            ->where($db->quoteName('a.' . $stateField) . ' <> -2')
            ->bind(':sourceLanguage', $sourceLanguage);

        // "No need for translation" items are hidden by default; the filter can reveal or isolate them.
        $noNeed = (string) $this->getState('filter.noneed', '');

        if ($noNeed === 'only') {
            $query->where($db->quoteName('noNeed.do_not_translate') . ' = 1');
        } elseif ($noNeed !== 'show') {
            $query->where(
                '(' . $db->quoteName('noNeed.do_not_translate') . ' IS NULL OR '
                . $db->quoteName('noNeed.do_not_translate') . ' = 0)'
            );
        }

        // Filter - search on the item title (title LIKE), or "id:<n>" for direct lookup.
        $search = $this->getState('filter.search');

        if (!empty($search)) {
            if (stripos($search, 'id:') === 0) {
                $articleId = (int) substr($search, 3);
                $query->where($db->quoteName('a.id') . ' = :articleId')
                    ->bind(':articleId', $articleId, ParameterType::INTEGER);
            } else {
                $search = '%' . str_replace(' ', '%', $db->escape(trim($search), true)) . '%';
                $query->where($db->quoteName('a.title') . ' LIKE :search')
                    ->bind(':search', $search);
            }
        }

        // Filter - state (multi-select). The dropdown mixes two different kinds of value:
        //   - real states (pending / translating / review / approved / published), stored in translation_state;
        //   - '__none__', which is NOT stored anywhere, it is a UI-only flag for "no translation yet",
        //     meaning the item has no state row at all.
        // Because '__none__' is the absence of a row, it needs the opposite SQL (NOT EXISTS) from the real
        // states (EXISTS over an IN list). So we split the picks into the two cases and OR them back together.
        $statuses = array_values(
            array_filter((array) $this->getState('filter.status'), static fn($s) => $s !== '')
        );

        if (!empty($statuses)) {
            $conditions   = [];
            // $wantNone     - did the user tick "No translation yet"? (the '__none__' flag)
            // $realStatuses - the genuine states only, with '__none__' stripped out, ready for an IN (...) list.
            $wantNone     = \in_array('__none__', $statuses, true);
            $realStatuses = array_values(array_filter($statuses, static fn($s) => $s !== '__none__'));

            if ($wantNone) {
                // "No translation yet": the item has NO queue/state rows at all, so NOT EXISTS is the test.
                // Correlated to the current grid row by content_id = a.id (content_type keeps it to the chosen type).
                // The state JOIN isn't strictly needed here, but it mirrors subReal so the two read the same.
                $subNone = $db->getQuery(true)
                    ->select('1')
                    ->from($db->quoteName('#__translations_queue', 'queue'))
                    ->innerJoin(
                        $db->quoteName('#__translations_queue_states', 'queueState')
                        . ' ON ' . $db->quoteName('queueState.queue_id') . ' = ' . $db->quoteName('queue.id')
                    )
                    ->where($db->quoteName('queue.content_id') . ' = ' . $db->quoteName('a.id'))
                    ->where($db->quoteName('queue.content_type') . ' = ' . $db->quote($contentType));
                $conditions[] = 'NOT EXISTS (' . $subNone . ')';
            }

            if (!empty($realStatuses)) {
                // Real states: the item has AT LEAST ONE language whose state is in the picked list, so EXISTS.
                // bindArray binds on the OUTER $query, not this subquery: the subquery is embedded into the main
                // query as a string, so placeholders bound on it would be lost - binding on $query resolves them.
                $placeholders = $query->bindArray($realStatuses, ParameterType::STRING);

                $subReal = $db->getQuery(true)
                    ->select('1')
                    ->from($db->quoteName('#__translations_queue', 'queue'))
                    ->innerJoin(
                        $db->quoteName('#__translations_queue_states', 'queueState')
                        . ' ON ' . $db->quoteName('queueState.queue_id') . ' = ' . $db->quoteName('queue.id')
                    )
                    ->where($db->quoteName('queue.content_id') . ' = ' . $db->quoteName('a.id'))
                    ->where($db->quoteName('queue.content_type') . ' = ' . $db->quote($contentType))
                    ->where($db->quoteName('queueState.translation_state') . ' IN (' . implode(',', $placeholders) . ')');
                $conditions[] = 'EXISTS (' . $subReal . ')';
            }

            if (!empty($conditions)) {
                $query->where('(' . implode(' OR ', $conditions) . ')');
            }
        }

        // Ordering (whitelisted to a.title and a.id via filter_fields).
        $orderCol  = $this->state->get('list.ordering', 'a.title');
        $orderDirn = $this->state->get('list.direction', 'ASC');
        $query->order($db->escape($orderCol) . ' ' . $db->escape($orderDirn));

        return $query;
    }

    /**
     * Target-language grid columns: enabled languages minus source and '*', narrowed by filter.
     *
     * @return  object[]  Keyed by lang_code (lang_code, title).
     *
     * @since   0.1.0
     */
    public function getTargetLanguages(): array
    {
        $db             = $this->getDatabase();
        $query          = $db->getQuery(true);
        $sourceLanguage = (string) $this->getState('source_language', 'en-GB');

        $query->select(
            [
                $db->quoteName('lang_code'),
                $db->quoteName('title'),
            ]
        )
            ->from($db->quoteName('#__languages'))
            ->where($db->quoteName('published') . ' = 1')
            ->where($db->quoteName('lang_code') . ' <> ' . $db->quote('*'))
            ->where($db->quoteName('lang_code') . ' <> :sourceLanguage')
            ->bind(':sourceLanguage', $sourceLanguage)
            ->order($db->quoteName('ordering') . ' ASC');

        $selected = array_values(array_filter((array) $this->getState('filter.languages')));

        if (!empty($selected)) {
            $query->whereIn($db->quoteName('lang_code'), $selected, ParameterType::STRING);
        }

        $db->setQuery($query);

        return $db->loadObjectList('lang_code');
    }

    /**
     * Display title of the configured source language, e.g. "English (en-GB)".
     *
     * @return  string  The #__languages title, or the raw language code as a fallback.
     *
     * @since   0.1.0
     */
    public function getSourceLanguageTitle(): string
    {
        $sourceLanguage = (string) $this->getState('source_language', 'en-GB');

        $db    = $this->getDatabase();
        $query = $db->getQuery(true)
            ->select($db->quoteName('title'))
            ->from($db->quoteName('#__languages'))
            ->where($db->quoteName('lang_code') . ' = :sourceLanguage')
            ->bind(':sourceLanguage', $sourceLanguage);

        $db->setQuery($query);

        return (string) ($db->loadResult() ?: $sourceLanguage);
    }

    /**
     * The content types the queue can show, as a list of aliases for the tab strip.
     *
     * @return  string[]  Content type aliases, e.g. 'com_content.article'.
     *
     * @since   0.4.0
     */
    public function getContentTypes(): array
    {
        return ContentTypesHelper::getContentTypes();
    }

    /**
     * Load items and attach each one's per language state map.
     *
     * @return  object[]  Items with ->states[langCode] => status map.
     *
     * @since   0.1.0
     */
    public function getItems()
    {
        $items = parent::getItems();

        if (empty($items)) {
            return $items;
        }

        $ids = [];

        foreach ($items as $item) {
            $ids[] = (int) $item->id;
        }

        $db          = $this->getDatabase();
        $contentType = (string) $this->getState('filter.contenttype', self::DEFAULT_CONTENT_TYPE);
        $query       = $db->getQuery(true)
            ->select(
                [
                    $db->quoteName('queue.content_id'),
                    $db->quoteName('queueState.target_language'),
                    $db->quoteName('queueState.translation_state', 'status'),
                ]
            )
            ->from($db->quoteName('#__translations_queue', 'queue'))
            ->innerJoin(
                $db->quoteName('#__translations_queue_states', 'queueState')
                . ' ON ' . $db->quoteName('queueState.queue_id') . ' = ' . $db->quoteName('queue.id')
            )
            ->where($db->quoteName('queue.content_type') . ' = :contentType')
            ->whereIn($db->quoteName('queue.content_id'), $ids, ParameterType::INTEGER)
            ->bind(':contentType', $contentType)
            ->order($db->quoteName('queueState.id') . ' ASC');

        $db->setQuery($query);
        $rows = $db->loadObjectList() ?: [];

        return self::applyStates($items, $rows);
    }

    /**
     * Fold queue rows into each item's per language state map. Pure (no DB).
     * Rows must be id ASC so a later row overwrites an earlier one (latest status wins per item+language).
     *
     * @param   object[]  $items  Source items.
     * @param   object[]  $rows   Queue rows (content_id, target_language, status), id ASC.
     *
     * @return  object[]  Items with ->states map attached.
     *
     * @since   0.1.0
     */
    protected static function applyStates(array $items, array $rows): array
    {
        $statesByItem = [];

        foreach ($rows as $row) {
            $statesByItem[(int) $row->content_id][$row->target_language] = $row->status;
        }

        foreach ($items as $item) {
            $item->states = $statesByItem[(int) $item->id] ?? [];
        }

        return $items;
    }
}
