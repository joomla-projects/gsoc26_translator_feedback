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
use Joomla\CMS\MVC\Model\BaseDatabaseModel;

/**
 * Rule set model: gathers translation rules into a set that can be carried to another site.
 *
 * A rule is matched against text in the site's source language, and that language is a component
 * option rather than a column, so the set records it alongside the rules. The columns that only
 * mean something on the site that wrote them are left out.
 *
 * @since  1.0.0
 */
class RulesetModel extends BaseDatabaseModel
{
    /**
     * The format name recorded in a set, so a reader can tell what it is holding.
     *
     * @var    string
     * @since  1.0.0
     */
    private const FORMAT = 'joomla-translation-rules';

    /**
     * The format version, raised when the shape of a set changes.
     *
     * @var    integer
     * @since  1.0.0
     */
    private const FORMAT_VERSION = 1;

    /**
     * The rule columns a set carries.
     *
     * @var    string[]
     * @since  1.0.0
     */
    private const EXPORTED_FIELDS = [
        'rule_name',
        'rule_type',
        'target_language',
        'rule_text',
        'source_term',
        'source_term_standard',
        'target_term',
        'search_keywords',
        'confidence',
        'source_origin',
    ];

    /**
     * Gather rules into a set.
     *
     * @param   int[]  $ruleIds  The rules to gather, or an empty array for everything the filters match.
     * @param   array  $filters  The Rules list filters, keyed without their "filter." prefix.
     *
     * @return  array  The rule set.
     *
     * @since   1.0.0
     */
    public function export(array $ruleIds, array $filters): array
    {
        return [
            'format'          => self::FORMAT,
            'version'         => self::FORMAT_VERSION,
            'source_language' => (string) ComponentHelper::getParams('com_translations')->get('source_language', 'en-GB'),
            'generated'       => Factory::getDate()->toISO8601(),
            'rules'           => $this->collect($ruleIds, $filters),
        ];
    }

    /**
     * Read the rules a set is made from.
     *
     * The Rules list model does the reading, so an export follows the same filters as the list
     * rather than repeating them here.
     *
     * @param   int[]  $ruleIds  The rules to read, or an empty array for everything the filters match.
     * @param   array  $filters  The Rules list filters, keyed without their "filter." prefix.
     *
     * @return  array  The rules, reduced to the columns a set carries.
     *
     * @since   1.0.0
     */
    private function collect(array $ruleIds, array $filters): array
    {
        /** @var RulesModel $rules */
        $rules = $this->getMVCFactory()->createModel('Rules', 'Administrator', ['ignore_request' => true]);

        foreach ($filters as $name => $value) {
            $rules->setState('filter.' . $name, $value);
        }

        if ($ruleIds !== []) {
            $rules->setState('filter.rule_ids', $ruleIds);
        }

        // A set is the whole selection rather than a page of it.
        $rules->setState('list.limit', 0);
        $rules->setState('list.start', 0);

        // The list shows fewer columns than a set carries.
        $rules->setState('list.select', 'a.' . implode(', a.', self::EXPORTED_FIELDS));

        $set = [];

        foreach ($rules->getItems() ?: [] as $rule) {
            $exported = [];

            foreach (self::EXPORTED_FIELDS as $field) {
                $exported[$field] = $rule->$field;
            }

            // The driver returns the decimal column as a string.
            $exported['confidence'] = (float) $exported['confidence'];

            $set[] = $exported;
        }

        return $set;
    }
}
