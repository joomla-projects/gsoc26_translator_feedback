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

use Joomla\CMS\MVC\Factory\MVCFactoryInterface;
use Joomla\CMS\MVC\Model\ListModel;
use Joomla\Database\ParameterType;

/**
 * Methods supporting a list of translation rules.
 *
 * @since  0.4.0
 */
class RulesModel extends ListModel
{
    /**
     * The check filter value for terminology rules that translate a term another rule translates otherwise.
     *
     * @var    string
     * @since  3.0.0
     */
    public const CHECK_CONFLICTING = 'conflicting';

    /**
     * The check filter value for terminology rules written for one context only.
     *
     * @var    string
     * @since  3.0.0
     */
    public const CHECK_NARROW = 'narrow';

    /**
     * What the text of a rule written for one context says, as LIKE patterns.
     *
     * A terminology rule reaches a prompt when its term is in the text, whatever the text is
     * about, so "Item as Datei, in the file manager" is applied to every item.
     *
     * @var    string[]
     * @since  3.0.0
     */
    private const NARROW_PATTERNS = ['%context%', '%when referring to%', '%only in %', '%only when %', '%only for %'];

    /**
     * Constructor.
     *
     * @param   array                $config   An optional associative array of configuration settings.
     * @param   MVCFactoryInterface  $factory  The factory.
     *
     * @since   0.4.0
     */
    public function __construct($config = [], ?MVCFactoryInterface $factory = null)
    {
        if (empty($config['filter_fields'])) {
            $config['filter_fields'] = [
                'id', 'a.id',
                'rule_name', 'a.rule_name',
                'rule_type', 'a.rule_type',
                'target_language', 'a.target_language',
                'l.title', 'language_title',
                'source_origin', 'a.source_origin',
                'confidence', 'a.confidence',
                'state', 'a.state', 'published',
                'ordering', 'a.ordering',
                'created', 'a.created',
                'check',
            ];
        }

        parent::__construct($config, $factory);
    }

    /**
     * Auto-populate the model state.
     *
     * @param   string  $ordering   An optional ordering field.
     * @param   string  $direction  An optional direction (asc|desc).
     *
     * @return  void
     *
     * @since   0.4.0
     */
    protected function populateState($ordering = 'a.ordering', $direction = 'asc')
    {
        parent::populateState($ordering, $direction);
    }

    /**
     * Build a store id based on the model state.
     *
     * @param   string  $id  A prefix for the store id.
     *
     * @return  string  A store id.
     *
     * @since   0.4.0
     */
    protected function getStoreId($id = '')
    {
        $id .= ':' . $this->getState('filter.search');
        $id .= ':' . $this->getState('filter.published');
        $id .= ':' . $this->getState('filter.rule_type');
        $id .= ':' . $this->getState('filter.target_language');
        $id .= ':' . $this->getState('filter.source_origin');
        $id .= ':' . $this->getState('filter.check');
        $id .= ':' . serialize($this->getState('filter.rule_ids'));

        return parent::getStoreId($id);
    }

    /**
     * Build the query to load the list data.
     *
     * @return  \Joomla\Database\QueryInterface
     *
     * @since   0.4.0
     */
    protected function getListQuery()
    {
        $db    = $this->getDatabase();
        $query = $db->getQuery(true);

        $query->select(
            $this->getState(
                'list.select',
                'a.id, a.rule_name, a.rule_type, a.target_language, a.source_term, a.target_term, '
                . 'a.confidence, a.source_origin, a.state, a.ordering, a.checked_out, a.checked_out_time, a.created'
            )
        )
            ->from($db->quoteName('#__translations_rules', 'a'));

        // Join over the content language for its title.
        $query->select($db->quoteName('l.title', 'language_title'))
            ->join(
                'LEFT',
                $db->quoteName('#__languages', 'l') . ' ON ' . $db->quoteName('l.lang_code') . ' = ' . $db->quoteName('a.target_language')
            );

        // Join over the users for the checkout name.
        $query->select($db->quoteName('uc.name', 'editor'))
            ->join(
                'LEFT',
                $db->quoteName('#__users', 'uc') . ' ON ' . $db->quoteName('uc.id') . ' = ' . $db->quoteName('a.checked_out')
            );

        // Filter by published state.
        $published = (string) $this->getState('filter.published');

        if (is_numeric($published)) {
            $state = (int) $published;
            $query->where($db->quoteName('a.state') . ' = :state')
                ->bind(':state', $state, ParameterType::INTEGER);
        } elseif ($published === '') {
            $query->whereIn($db->quoteName('a.state'), [0, 1]);
        }

        // Filter by rule type.
        if ($ruleType = $this->getState('filter.rule_type')) {
            $query->where($db->quoteName('a.rule_type') . ' = :ruleType')
                ->bind(':ruleType', $ruleType);
        }

        // Filter by target language.
        if ($language = $this->getState('filter.target_language')) {
            $query->where($db->quoteName('a.target_language') . ' = :language')
                ->bind(':language', $language);
        }

        // Filter by rule origin.
        if ($origin = $this->getState('filter.source_origin')) {
            $query->where($db->quoteName('a.source_origin') . ' = :origin')
                ->bind(':origin', $origin);
        }

        // Limit to named rules, so an export can carry a selection rather than a whole filter.
        if ($ruleIds = $this->getState('filter.rule_ids')) {
            $query->whereIn($db->quoteName('a.id'), $ruleIds);
        }

        // Filter by search in the name and term columns.
        $search = $this->getState('filter.search');

        if (!empty($search)) {
            if (stripos($search, 'id:') === 0) {
                $ruleId = (int) substr($search, 3);
                $query->where($db->quoteName('a.id') . ' = :ruleId')
                    ->bind(':ruleId', $ruleId, ParameterType::INTEGER);
            } else {
                $search = '%' . str_replace(' ', '%', trim($search)) . '%';
                $query->where(
                    '(' . $db->quoteName('a.rule_name') . ' LIKE :name'
                    . ' OR ' . $db->quoteName('a.source_term') . ' LIKE :sourceTerm'
                    . ' OR ' . $db->quoteName('a.target_term') . ' LIKE :targetTerm'
                    . ' OR ' . $db->quoteName('a.rule_text') . ' LIKE :ruleText)'
                )
                    ->bind(':name', $search)
                    ->bind(':sourceTerm', $search)
                    ->bind(':targetTerm', $search)
                    ->bind(':ruleText', $search);
            }
        }

        // Rules a translator should look at before they reach a prompt.
        $check = (string) $this->getState('filter.check');
        $term  = static fn (string $alias): string => 'COALESCE(NULLIF(' . $db->quoteName($alias . '.source_term_standard') . ", ''), LOWER("
            . $db->quoteName($alias . '.source_term') . '))';

        if ($check === self::CHECK_CONFLICTING || $check === self::CHECK_NARROW) {
            $query->where($db->quoteName('a.rule_type') . ' = ' . $db->quote('terminology'));
        }

        // Another rule for the same term in the same language gives it another translation; the
        // rules of a term are listed together, so they can be read side by side.
        if ($check === self::CHECK_CONFLICTING) {
            $other = $db->getQuery(true)
                ->select('1')
                ->from($db->quoteName('#__translations_rules', 'b'))
                ->where($db->quoteName('b.id') . ' <> ' . $db->quoteName('a.id'))
                ->where($db->quoteName('b.rule_type') . ' = ' . $db->quote('terminology'))
                ->where($db->quoteName('b.target_language') . ' = ' . $db->quoteName('a.target_language'))
                ->whereIn($db->quoteName('b.state'), [0, 1])
                ->where($term('b') . ' = ' . $term('a'))
                ->where($db->quoteName('b.target_term') . ' <> ' . $db->quoteName('a.target_term'));

            $query->where($term('a') . " <> ''")
                ->where('EXISTS (' . $other . ')')
                ->order($term('a') . ' ASC');
        }

        if ($check === self::CHECK_NARROW) {
            $query->extendWhere(
                'AND',
                array_map(static fn (string $pattern): string => $db->quoteName('a.rule_text') . ' LIKE ' . $db->quote($pattern), self::NARROW_PATTERNS),
                'OR'
            );
        }

        // Add the list ordering clause.
        $orderCol  = $this->state->get('list.ordering', 'a.ordering');
        $orderDirn = $this->state->get('list.direction', 'ASC');

        $query->order($db->escape($orderCol . ' ' . $orderDirn));

        return $query;
    }
}
