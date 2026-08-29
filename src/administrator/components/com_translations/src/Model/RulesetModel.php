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
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Model\BaseDatabaseModel;
use Joomla\Component\Translations\Administrator\Table\RuleTable;
use Joomla\Database\ParameterType;

/**
 * Rule set model: carries translation rules between sites, as a set gathered here and read back there.
 *
 * A rule is matched against text in the site's source language, and that language is a component
 * option rather than a column, so the set records it alongside the rules. The columns that only
 * mean something on the site that wrote them are left out, which is why an imported rule is a new
 * rule here rather than the one that was exported.
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
     * The largest set that is read, in bytes.
     *
     * The set is read whole, so its size bounds the memory a request needs.
     *
     * @var    integer
     * @since  1.0.0
     */
    private const MAX_FILE_SIZE = 2097152;

    /**
     * The most rules a set may carry.
     *
     * Each rule is a write of its own, so their number bounds how long a run takes.
     *
     * @var    integer
     * @since  1.0.0
     */
    private const MAX_RULES = 5000;

    /**
     * The states an imported rule may be given.
     *
     * @var    int[]
     * @since  1.0.0
     */
    private const IMPORT_STATES = [0, 1];

    /**
     * The state of a rule the translator has thrown away.
     *
     * @var    integer
     * @since  1.0.0
     */
    private const TRASHED = -2;

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

    /**
     * Read a rule set into the site.
     *
     * @param   array    $file   The uploaded file, as the input hands it over.
     * @param   integer  $state  The state to give the rules that arrive.
     *
     * @return  array  What was written, what was passed over and what was refused.
     *
     * @throws  \RuntimeException  When the file cannot be read as a rule set at all.
     *
     * @since   1.0.0
     */
    public function import(array $file, int $state): array
    {
        $rules  = $this->read($file);
        $state  = \in_array($state, self::IMPORT_STATES, true) ? $state : 0;
        $known  = $this->knownLanguages(array_column($rules, 'target_language'));
        $held   = $this->heldKeys($known);
        $report = ['imported' => 0, 'duplicates' => 0, 'trashed' => [], 'rejected' => []];

        foreach ($rules as $rule) {
            $name = \is_array($rule) && \is_scalar($rule['rule_name'] ?? null) ? (string) $rule['rule_name'] : '';

            try {
                $values = $this->values($rule, $known);
                $key    = $this->ruleKey($values);

                if (isset($held[$key])) {
                    if ($held[$key]) {
                        $report['duplicates']++;
                    } else {
                        $report['trashed'][] = $name;
                    }

                    continue;
                }

                $this->write($values, $state);

                // Hold the key so a set carrying the same rule twice writes it once.
                $held[$key] = true;

                $report['imported']++;
            } catch (\Exception $e) {
                $report['rejected'][] = ['name' => $name, 'reason' => $e->getMessage()];
            }
        }

        return $report;
    }

    /**
     * Read the rules out of an uploaded set.
     *
     * The file is read where PHP put it and never stored, so nothing an upload carries is placed
     * under the document root and the temporary copy goes with the request.
     *
     * @param   array  $file  The uploaded file, as the input hands it over.
     *
     * @return  array  The rules the set carries.
     *
     * @throws  \RuntimeException  When the file is not a rule set this version can read.
     *
     * @since   1.0.0
     */
    private function read(array $file): array
    {
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);

        if ($error !== UPLOAD_ERR_OK) {
            throw new \RuntimeException($this->uploadError($error));
        }

        if ((int) $file['size'] > self::MAX_FILE_SIZE) {
            throw new \RuntimeException(
                Text::sprintf(
                    'COM_TRANSLATIONS_RULES_IMPORT_ERROR_SIZE',
                    HTMLHelper::_('number.bytes', self::MAX_FILE_SIZE)
                )
            );
        }

        $set = json_decode((string) file_get_contents($file['tmp_name']), true);

        if (!\is_array($set) || ($set['format'] ?? '') !== self::FORMAT) {
            throw new \RuntimeException(Text::_('COM_TRANSLATIONS_RULES_IMPORT_ERROR_FORMAT'));
        }

        if (($set['version'] ?? 0) !== self::FORMAT_VERSION) {
            throw new \RuntimeException(
                Text::sprintf('COM_TRANSLATIONS_RULES_IMPORT_ERROR_VERSION', self::FORMAT_VERSION)
            );
        }

        if (!\is_array($set['rules'] ?? null) || $set['rules'] === []) {
            throw new \RuntimeException(Text::_('COM_TRANSLATIONS_RULES_IMPORT_ERROR_EMPTY'));
        }

        if (\count($set['rules']) > self::MAX_RULES) {
            throw new \RuntimeException(
                Text::sprintf('COM_TRANSLATIONS_RULES_IMPORT_ERROR_COUNT', self::MAX_RULES)
            );
        }

        return $set['rules'];
    }

    /**
     * Name what went wrong with an upload.
     *
     * @param   integer  $error  The code PHP reported.
     *
     * @return  string  The message to show.
     *
     * @since   1.0.0
     */
    private function uploadError(int $error): string
    {
        switch ($error) {
            case UPLOAD_ERR_NO_FILE:
                return Text::_('COM_TRANSLATIONS_RULES_IMPORT_ERROR_NO_FILE');

            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                return Text::_('COM_TRANSLATIONS_RULES_IMPORT_ERROR_TOO_LARGE');

            case UPLOAD_ERR_NO_TMP_DIR:
                return Text::_('COM_TRANSLATIONS_RULES_IMPORT_ERROR_NO_TMP_DIR');

            default:
                return Text::_('COM_TRANSLATIONS_RULES_IMPORT_ERROR_UPLOAD');
        }
    }

    /**
     * Take the columns a rule set carries out of one of its rules.
     *
     * The values come from a file, so each one is checked and cast before it is bound. Only the
     * term columns are nullable; the rest are declared NOT NULL.
     *
     * @param   mixed  $rule   The rule as the set states it.
     * @param   array  $known  The content languages this site has.
     *
     * @return  array  The rule's columns.
     *
     * @throws  \RuntimeException  When the rule is not shaped like one, or names a language the site lacks.
     *
     * @since   1.0.0
     */
    private function values($rule, array $known): array
    {
        if (!\is_array($rule)) {
            throw new \RuntimeException(Text::_('COM_TRANSLATIONS_RULES_IMPORT_ERROR_SHAPE'));
        }

        $values = [];

        foreach (self::EXPORTED_FIELDS as $field) {
            if (!\array_key_exists($field, $rule)) {
                throw new \RuntimeException(Text::sprintf('COM_TRANSLATIONS_RULES_IMPORT_ERROR_FIELD', $field));
            }

            if ($rule[$field] !== null && !\is_scalar($rule[$field])) {
                throw new \RuntimeException(Text::sprintf('COM_TRANSLATIONS_RULES_IMPORT_ERROR_FIELD', $field));
            }

            $values[$field] = $rule[$field];
        }

        $values['rule_name']       = (string) $values['rule_name'];
        $values['rule_type']       = (string) $values['rule_type'];
        $values['target_language'] = (string) $values['target_language'];
        $values['rule_text']       = (string) $values['rule_text'];
        $values['search_keywords'] = (string) $values['search_keywords'];
        $values['confidence']      = (float) $values['confidence'];
        $values['source_origin']   = (string) $values['source_origin'];

        // A rule the site has no language for stores happily and is never retrieved, so it is
        // refused here rather than left to sit inert.
        if (!\in_array($values['target_language'], $known, true)) {
            throw new \RuntimeException(
                Text::sprintf('COM_TRANSLATIONS_RULES_IMPORT_ERROR_LANGUAGE', $values['target_language'])
            );
        }

        return $values;
    }

    /**
     * Identify a rule by what it matches on rather than by how it is worded.
     *
     * A provider restates a rule's name differently each time it refines it, so the name cannot
     * identify one. A term rule is identified by its standard form, falling back to the term it
     * was written with, since the standard form is null for phrases and for short words; a style
     * rule carries no terms at all, so its text is what identifies it.
     *
     * @param   array  $rule  The rule's columns.
     *
     * @return  string  The key the rule is held under.
     *
     * @since   1.0.0
     */
    private function ruleKey(array $rule): string
    {
        if ($rule['rule_type'] === 'style') {
            $identity = (string) $rule['rule_text'];
        } else {
            $standard = (string) $rule['source_term_standard'];
            $identity = $standard !== '' ? $standard : (string) $rule['source_term'];
        }

        // The stored collation ignores case where a PHP comparison would not.
        return md5($rule['target_language'] . "\n" . $rule['rule_type'] . "\n" . mb_strtolower(trim($identity)));
    }

    /**
     * Read the keys the site's own rules already hold.
     *
     * @param   string[]  $languages  The content languages to look under.
     *
     * @return  array  True against each key a live rule holds, false where only a trashed one does.
     *
     * @since   1.0.0
     */
    private function heldKeys(array $languages): array
    {
        if ($languages === []) {
            return [];
        }

        $db    = $this->getDatabase();
        $query = $db->getQuery(true)
            ->select(
                $db->quoteName(
                    ['rule_type', 'target_language', 'rule_text', 'source_term', 'source_term_standard', 'state']
                )
            )
            ->from($db->quoteName('#__translations_rules'))
            ->whereIn($db->quoteName('target_language'), $languages, ParameterType::STRING);

        $db->setQuery($query);

        $held = [];

        foreach ($db->loadAssocList() ?: [] as $rule) {
            $key  = $this->ruleKey($rule);
            $live = (int) $rule['state'] !== self::TRASHED;

            $held[$key] = ($held[$key] ?? false) || $live;
        }

        return $held;
    }

    /**
     * Read which of the languages a set names this site actually has.
     *
     * @param   string[]  $languages  The languages the set names.
     *
     * @return  string[]  The ones the site has.
     *
     * @since   1.0.0
     */
    private function knownLanguages(array $languages): array
    {
        $languages = array_unique(array_filter(array_map('strval', $languages)));

        if ($languages === []) {
            return [];
        }

        $db    = $this->getDatabase();
        $query = $db->getQuery(true)
            ->select($db->quoteName('lang_code'))
            ->from($db->quoteName('#__languages'))
            ->whereIn($db->quoteName('lang_code'), array_values($languages), ParameterType::STRING);

        $db->setQuery($query);

        return $db->loadColumn() ?: [];
    }

    /**
     * Write an imported rule.
     *
     * The table is written directly, as the distiller writes its own rules: the rule model stamps
     * every new rule as hand authored and recomputes the standard form through the RAG provider,
     * which would both lose the origin the set carries and call a provider once per rule.
     *
     * @param   array    $values  The rule's columns.
     * @param   integer  $state   The state to give it.
     *
     * @return  void
     *
     * @throws  \Exception  When the rule is not valid, or cannot be stored.
     *
     * @since   1.0.0
     */
    private function write(array $values, int $state): void
    {
        /** @var RuleTable $table */
        $table = $this->getTable('Rule', 'Administrator');

        $values['state'] = $state;

        if (!$table->bind($values) || !$table->check() || !$table->store()) {
            throw new \RuntimeException(Text::_('COM_TRANSLATIONS_RULES_IMPORT_ERROR_STORE'));
        }
    }
}
