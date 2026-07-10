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

use Jfcherng\Diff\SequenceMatcher;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Log\Log;
use Joomla\CMS\MVC\Model\BaseDatabaseModel;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\Component\Translations\Administrator\Event\DistilEvent;
use Joomla\Component\Translations\Administrator\Table\RuleTable;
use Joomla\Database\ParameterType;

/**
 * Distiller model: turns translator feedback into draft translation rules.
 *
 * Reads a batch of pending feedback, focuses each correction with a diff, asks the
 * "distillation" plugin group to distil rules from it, and writes the results as
 * unpublished (state 0) draft rules for review in the Rules view. All provider-agnostic
 * work lives here; only the LLM call belongs to a plugin, behind the onDistil event.
 *
 * @since  0.4.0
 */
class DistillerModel extends BaseDatabaseModel
{
    /**
     * The origin recorded on a rule distilled from feedback.
     *
     * @var    string
     * @since  0.4.0
     */
    private const SOURCE_ORIGIN = 'distilled';

    /**
     * Distil draft rules from one batch of pending feedback.
     *
     * Feedback is processed one target language at a time so its terminology stays coherent;
     * each language is committed as it finishes, so a later failure does not lose earlier work.
     *
     * @param   integer  $batchSize  The most feedback rows to process in one run.
     *
     * @return  integer  The number of feedback rows processed.
     *
     * @throws  \RuntimeException  When no distillation provider is enabled.
     *
     * @since   0.4.0
     */
    public function distill(int $batchSize = 20): int
    {
        $feedback = $this->loadPendingFeedback($batchSize);

        if ($feedback === []) {
            return 0;
        }

        $sourceLanguage = (string) ComponentHelper::getParams('com_translations')->get('source_language', 'en-GB');
        $processed      = 0;

        foreach ($this->groupByLanguage($feedback) as $targetLanguage => $rows) {
            $corrections = [];
            $rowIds      = [];

            foreach ($rows as $row) {
                $rowIds[] = (int) $row->id;
                $diff     = $this->diff((string) $row->machine_draft, (string) $row->human_correction);

                $this->storeDiff((int) $row->id, $diff);

                $corrections[] = [
                    'id'               => (int) $row->id,
                    'source_text'      => (string) $row->source_text,
                    'machine_draft'    => (string) $row->machine_draft,
                    'human_correction' => (string) $row->human_correction,
                    'diff'             => $diff,
                ];
            }

            $candidates = $this->requestCandidates(
                $corrections,
                $this->loadExistingRules($targetLanguage),
                $sourceLanguage,
                $targetLanguage
            );

            $this->persistRules($candidates, $targetLanguage);
            $this->markProcessed($rowIds);

            $processed += \count($rows);
        }

        return $processed;
    }

    /**
     * Load the oldest pending feedback rows, up to the batch size.
     *
     * @param   integer  $batchSize  The most rows to return.
     *
     * @return  object[]  The feedback rows, oldest first.
     *
     * @since   0.4.0
     */
    private function loadPendingFeedback(int $batchSize): array
    {
        $status = 'pending';
        $db     = $this->getDatabase();
        $query  = $db->getQuery(true)
            ->select('*')
            ->from($db->quoteName('#__translations_feedback'))
            ->where($db->quoteName('status') . ' = :status')
            ->order([$db->quoteName('created') . ' ASC', $db->quoteName('id') . ' ASC'])
            ->bind(':status', $status, ParameterType::STRING);
        $db->setQuery($query, 0, $batchSize);

        return $db->loadObjectList() ?: [];
    }

    /**
     * Group feedback rows by their target language.
     *
     * @param   object[]  $feedback  The feedback rows.
     *
     * @return  array  Rows keyed by target language code.
     *
     * @since   0.4.0
     */
    private function groupByLanguage(array $feedback): array
    {
        $byLanguage = [];

        foreach ($feedback as $row) {
            $byLanguage[(string) $row->target_language][] = $row;
        }

        return $byLanguage;
    }

    /**
     * Reduce the change from the machine draft to the human correction to its changed spans,
     * as labelled "before to after" pairs. Comparing at word level isolates the actual edit,
     * so a one-word fix inside a long paragraph is captured as that word rather than the whole
     * paragraph. Each span is labelled by size: a single-word swap ([term]) points to
     * terminology, a run of words ([phrase]) points to phrasing or tone, so the language model
     * has a hint to the kind of rule while diff_data and the prompt stay small and focused.
     *
     * @param   string  $machineDraft     The machine draft.
     * @param   string  $humanCorrection  The human correction.
     *
     * @return  string  The labelled changed spans, one per line.
     *
     * @since   0.4.0
     */
    private function diff(string $machineDraft, string $humanCorrection): string
    {
        // Tokenise into words that keep their trailing whitespace, so a run of changed words
        // stays one span (a reworded phrase) rather than being split by the spaces between them.
        $oldWords = $this->tokenize($machineDraft);
        $newWords = $this->tokenize($humanCorrection);

        $matcher = new SequenceMatcher($oldWords, $newWords);
        $spans   = [];

        foreach ($matcher->getOpcodes() as [$op, $oldStart, $oldEnd, $newStart, $newEnd]) {
            if ($op === SequenceMatcher::OP_EQ) {
                continue;
            }

            $before = trim(implode('', \array_slice($oldWords, $oldStart, $oldEnd - $oldStart)));
            $after  = trim(implode('', \array_slice($newWords, $newStart, $newEnd - $newStart)));

            if ($before === '') {
                $spans[] = '[added] ' . $after;
            } elseif ($after === '') {
                $spans[] = '[removed] ' . $before;
            } else {
                // A single-word swap points to terminology; a run of words points to phrasing or tone.
                $label   = $this->wordCount($before) > 1 || $this->wordCount($after) > 1 ? '[phrase]' : '[term]';
                $spans[] = $label . ' ' . $before . ' → ' . $after;
            }
        }

        return implode("\n", $spans);
    }

    /**
     * Count the words in a segment, so a change can be classed as a single term or a phrase.
     *
     * @param   string  $text  The segment.
     *
     * @return  integer  The word count.
     *
     * @since   0.4.0
     */
    private function wordCount(string $text): int
    {
        $text = trim($text);

        return $text === '' ? 0 : \count(preg_split('/\s+/u', $text) ?: []);
    }

    /**
     * Split text into tokens that each keep their trailing whitespace, so a run of changed
     * words stays a single span rather than being split by the spaces between them.
     *
     * @param   string  $text  The text to split.
     *
     * @return  string[]  The tokens.
     *
     * @since   0.4.0
     */
    private function tokenize(string $text): array
    {
        preg_match_all('/\S+\s*/u', $text, $matches);

        return $matches[0];
    }

    /**
     * Store a correction's diff back on its feedback row.
     *
     * @param   integer  $feedbackId  The feedback row id.
     * @param   string   $diff        The rendered diff.
     *
     * @return  void
     *
     * @since   0.4.0
     */
    private function storeDiff(int $feedbackId, string $diff): void
    {
        $db    = $this->getDatabase();
        $query = $db->getQuery(true)
            ->update($db->quoteName('#__translations_feedback'))
            ->set($db->quoteName('diff_data') . ' = :diff')
            ->where($db->quoteName('id') . ' = :id')
            ->bind(':diff', $diff, ParameterType::STRING)
            ->bind(':id', $feedbackId, ParameterType::INTEGER);
        $db->setQuery($query)->execute();
    }

    /**
     * Load the rules already learned for a target language, in a compact shape for the prompt,
     * so a provider merges its candidates into them rather than duplicating.
     *
     * @param   string  $targetLanguage  The target language code.
     *
     * @return  array  The existing rules.
     *
     * @since   0.4.0
     */
    private function loadExistingRules(string $targetLanguage): array
    {
        $db    = $this->getDatabase();
        $query = $db->getQuery(true)
            ->select($db->quoteName(['id', 'rule_type', 'rule_name', 'rule_text', 'source_term', 'target_term']))
            ->from($db->quoteName('#__translations_rules'))
            ->where($db->quoteName('target_language') . ' = :lang')
            ->bind(':lang', $targetLanguage, ParameterType::STRING);
        $db->setQuery($query);

        return $db->loadAssocList() ?: [];
    }

    /**
     * Ask the "distillation" plugin group to distil rule candidates from a batch of corrections.
     *
     * The first provider that answers wins; an enabled provider may legitimately return no
     * candidates (nothing worth learning), but with no provider at all there is nothing to
     * distil with, so that fails.
     *
     * @param   array   $corrections     The corrections to distil.
     * @param   array   $existingRules   The rules already learned for the language.
     * @param   string  $sourceLanguage  The source language code.
     * @param   string  $targetLanguage  The target language code.
     *
     * @return  array  The rule candidates.
     *
     * @throws  \RuntimeException  When no distillation provider is enabled.
     *
     * @since   0.4.0
     */
    private function requestCandidates(array $corrections, array $existingRules, string $sourceLanguage, string $targetLanguage): array
    {
        $dispatcher = $this->getDispatcher();
        PluginHelper::importPlugin('distillation', null, true, $dispatcher);

        $event = new DistilEvent('onDistil', [
            'corrections'    => $corrections,
            'existingRules'  => $existingRules,
            'sourceLanguage' => $sourceLanguage,
            'targetLanguage' => $targetLanguage,
        ]);
        $dispatcher->dispatch('onDistil', $event);

        // The first provider that answered wins, even when it distilled nothing.
        foreach ((array) $event->getArgument('result', []) as $providerResult) {
            if (\is_array($providerResult)) {
                return $providerResult;
            }
        }

        throw new \RuntimeException('No distillation provider is enabled. Enable a distillation plugin to distil rules.');
    }

    /**
     * Persist rule candidates as draft rules, skipping any that fail validation.
     *
     * @param   array   $candidates      The rule candidates.
     * @param   string  $targetLanguage  The target language code.
     *
     * @return  void
     *
     * @since   0.4.0
     */
    private function persistRules(array $candidates, string $targetLanguage): void
    {
        foreach ($candidates as $candidate) {
            if (!\is_array($candidate)) {
                continue;
            }

            try {
                $this->saveRule($candidate, $targetLanguage);
            } catch (\Throwable $e) {
                // Skip a malformed candidate rather than lose the rest of the batch.
                Log::add(
                    \sprintf('Skipped a distilled rule for %s: %s', $targetLanguage, $e->getMessage()),
                    Log::WARNING,
                    'translations'
                );
            }
        }
    }

    /**
     * Save one rule candidate: refine an existing rule when the candidate carries its id,
     * else insert a new draft (state 0) for review.
     *
     * @param   array   $candidate       The rule candidate.
     * @param   string  $targetLanguage  The target language code.
     *
     * @return  void
     *
     * @throws  \RuntimeException  When the rule fails validation or cannot be stored.
     *
     * @since   0.4.0
     */
    private function saveRule(array $candidate, string $targetLanguage): void
    {
        /** @var RuleTable $table */
        $table       = $this->getTable('Rule', 'Administrator');
        $feedbackIds = array_values(array_unique(array_map('intval', (array) ($candidate['source_feedback_ids'] ?? []))));
        $existingId  = (int) ($candidate['id'] ?? 0);

        // The rule fields the provider (re)states each time. Bind them (rather than set them
        // directly) so the table's _jsonEncode encodes source_feedback_ids and the array is
        // not dropped by the driver on store.
        $data = [
            'rule_name'       => (string) ($candidate['rule_name'] ?? ''),
            'rule_type'       => (string) ($candidate['rule_type'] ?? ''),
            'target_language' => $targetLanguage,
            'rule_text'       => (string) ($candidate['rule_text'] ?? ''),
            'source_term'     => $this->nullableTerm($candidate['source_term'] ?? null),
            'target_term'     => $this->nullableTerm($candidate['target_term'] ?? null),
            'search_keywords' => (string) ($candidate['search_keywords'] ?? ''),
        ];

        if ($existingId > 0 && $table->load($existingId)) {
            // Refine in place: overlay the provider's improved wording, accumulate the evidence,
            // and never let confidence drop; the original state and origin are kept.
            $data['confidence']          = max((float) $table->confidence, (float) ($candidate['confidence'] ?? 0));
            $data['source_feedback_ids'] = $this->mergeFeedbackIds($table->source_feedback_ids, $feedbackIds);
        } else {
            $data['confidence']          = (float) ($candidate['confidence'] ?? 0);
            $data['source_feedback_ids'] = $feedbackIds;
            $data['source_origin']       = self::SOURCE_ORIGIN;
            $data['state']               = 0;
        }

        if (!$table->bind($data) || !$table->check() || !$table->store()) {
            throw new \RuntimeException('The distilled rule could not be stored.');
        }
    }

    /**
     * Merge new feedback ids into a rule's stored list, keeping each id once.
     *
     * @param   mixed  $stored  The rule's stored source_feedback_ids (a JSON string, or an array).
     * @param   int[]  $newIds  The feedback ids to add.
     *
     * @return  int[]  The merged ids.
     *
     * @since   0.4.0
     */
    private function mergeFeedbackIds($stored, array $newIds): array
    {
        $current = [];

        if (\is_string($stored) && $stored !== '') {
            $decoded = json_decode($stored, true);
            $current = \is_array($decoded) ? $decoded : [];
        } elseif (\is_array($stored)) {
            $current = $stored;
        }

        return array_values(array_unique(array_map('intval', array_merge($current, $newIds))));
    }

    /**
     * Normalise a term to a non-empty string or null, since the term columns are nullable.
     *
     * @param   mixed  $term  The candidate term.
     *
     * @return  string|null  The term, or null when empty.
     *
     * @since   0.4.0
     */
    private function nullableTerm($term): ?string
    {
        $term = trim((string) $term);

        return $term === '' ? null : $term;
    }

    /**
     * Mark feedback rows as processed so they are not distilled again.
     *
     * @param   int[]  $feedbackIds  The feedback row ids.
     *
     * @return  void
     *
     * @since   0.4.0
     */
    private function markProcessed(array $feedbackIds): void
    {
        if ($feedbackIds === []) {
            return;
        }

        $processed = 'processed';
        $db        = $this->getDatabase();
        $query     = $db->getQuery(true)
            ->update($db->quoteName('#__translations_feedback'))
            ->set($db->quoteName('status') . ' = :status')
            ->whereIn($db->quoteName('id'), $feedbackIds)
            ->bind(':status', $processed, ParameterType::STRING);
        $db->setQuery($query)->execute();
    }
}
