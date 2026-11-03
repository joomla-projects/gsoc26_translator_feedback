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
use Joomla\Component\Translations\Administrator\Helper\DatabaseConnection;
use Joomla\Component\Translations\Administrator\Helper\RequestLog;
use Joomla\Component\Translations\Administrator\Helper\RuleMerger;
use Joomla\Component\Translations\Administrator\Helper\RuleRetriever;
use Joomla\Component\Translations\Administrator\Helper\RunLock;
use Joomla\Component\Translations\Administrator\Helper\RunResult;
use Joomla\Component\Translations\Administrator\Helper\TimeBudget;
use Joomla\Component\Translations\Administrator\Helper\WordNormaliser;
use Joomla\Component\Translations\Administrator\Table\RuleTable;
use Joomla\Database\ParameterType;

/**
 * Distiller model: turns translator feedback into translation rules.
 *
 * Reads a batch of pending feedback, focuses each correction with a diff, asks the
 * "rag" plugin group to distil rules from it, and writes the results for review in the
 * Rules view, unpublished unless the option publishes them on creation. All
 * provider-agnostic work lives here; only the LLM call belongs to a plugin, behind the
 * onDistil event.
 *
 * @since  0.4.0
 */
class DistillerModel extends BaseDatabaseModel
{
    /**
     * The origin recorded on a rule whose evidence carries no single origin of its own.
     *
     * @var    string
     * @since  0.4.0
     */
    private const SOURCE_ORIGIN = 'distilled';

    /**
     * The rule states offered as context: draft and published. A trashed rule is left out, so a
     * provider cannot refine one back into use.
     *
     * @var    int[]
     * @since  1.0.0
     */
    private const CONTEXT_STATES = [0, 1];

    /**
     * The most rules sent as context in one request, so the request stays bounded as the rule
     * base grows.
     *
     * @var    integer
     * @since  1.0.0
     */
    private const MAX_CONTEXT_RULES = 50;

    /**
     * The most style rules sent as context, since they apply to the whole language rather than
     * to a term and so are never narrowed by the corrections.
     *
     * @var    integer
     * @since  1.0.0
     */
    private const MAX_CONTEXT_STYLE_RULES = 15;

    /**
     * The rule fields a provider is given, so the columns read only for matching stay out of the
     * request.
     *
     * @var    string[]
     * @since  1.0.0
     */
    private const CONTEXT_RULE_FIELDS = ['id', 'rule_type', 'rule_name', 'rule_text', 'source_term', 'target_term'];

    /**
     * The most corrections in one request when the caller sets none.
     *
     * Every request carries the same system prompt and up to fifty existing rules as context,
     * about ten thousand tokens, so a request of a few corrections pays mostly for that context.
     * Measured on language-pack corrections, fifty per request cost a sixth per correction of
     * five per request, and took about 25 seconds at low effort.
     *
     * @var    integer
     * @since  1.2.0
     */
    public const DEFAULT_REQUEST_SIZE = 50;

    /**
     * Failed requests in a row that end a run, because the provider is then most likely
     * unreachable and every further request is paid for the same answer.
     *
     * @var    integer
     * @since  1.2.0
     */
    private const MAX_CONSECUTIVE_FAILURES = 2;

    /**
     * The input tokens a request aims to stay under, context included.
     *
     * @var    integer
     * @since  1.2.0
     */
    private const INPUT_TOKEN_BUDGET = 20000;

    /**
     * The part of the input budget kept for the system prompt and the context rules.
     *
     * @var    integer
     * @since  1.2.0
     */
    private const CONTEXT_RESERVE_TOKENS = 10000;

    /**
     * Characters per token used to estimate a request's size before it is sent; on the
     * language-pack corrections that were measured, a token was about three characters.
     *
     * @var    integer
     * @since  1.2.0
     */
    private const CHARS_PER_TOKEN = 3;

    /**
     * The length above which a correction's texts are sent as excerpts around their changes.
     *
     * @var    integer
     * @since  1.2.0
     */
    private const EXCERPT_THRESHOLD = 1500;

    /**
     * Distil draft rules from pending feedback, for as long as the run's time budget allows.
     *
     * Feedback is sent one target language at a time so its terminology stays coherent. Each
     * request is saved as soon as it is answered, so a later failure does not lose earlier work.
     * A row that failed before is sent in a smaller request, so it cannot hold back the rows it
     * was batched with, and not again in the same run; after its third failure it is set aside.
     *
     * @param   integer          $requestSize  The most corrections in one request.
     * @param   TimeBudget|null  $budget       How long the run may keep sending requests; the
     *                                         default for where it runs when null.
     *
     * @return  RunResult  What the run did, and whether it should run again.
     *
     * @throws  \RuntimeException  When no distillation provider is enabled.
     *
     * @since   0.4.0
     */
    public function distill(int $requestSize = self::DEFAULT_REQUEST_SIZE, ?TimeBudget $budget = null): RunResult
    {
        // Without a provider every request would fail and use up an attempt of rows that are fine.
        if (PluginHelper::getPlugin('rag') === []) {
            throw new \RuntimeException('No distillation provider is enabled. Enable a RAG plugin to distil rules.');
        }

        $budget = $budget ?? new TimeBudget();
        $result = new RunResult();
        $lock   = new RunLock($this->getDatabase(), 'distil');

        // Another run would pick the same pending rows and pay for them a second time.
        if (!$lock->acquire()) {
            $result->busy      = true;
            $result->remaining = $this->countPendingFeedback();

            return $result;
        }

        try {
            $this->distilWithinBudget($requestSize, $budget, $result);
        } finally {
            $lock->release();
        }

        $result->remaining = $this->countPendingFeedback();

        return $result;
    }

    /**
     * Send requests until the budget is used up, the work is done, or the provider keeps failing.
     *
     * @param   integer     $requestSize  The most corrections in one request.
     * @param   TimeBudget  $budget       How long the run may keep sending requests.
     * @param   RunResult   $result       The run's result, updated in place.
     *
     * @return  void
     *
     * @since   1.2.1
     */
    private function distilWithinBudget(int $requestSize, TimeBudget $budget, RunResult $result): void
    {
        $sourceLanguage = (string) ComponentHelper::getParams('com_translations')->get('source_language', 'en-GB');
        $tried          = [];
        $failures       = 0;

        while ($budget->allowsAnother()) {
            $rows = self::selectRequest($this->pendingCandidates($requestSize, $tried), $requestSize);

            if ($rows === []) {
                break;
            }

            foreach ($rows as $row) {
                $tried[] = (int) $row->id;
            }

            $budget->startRequest();
            $answered = $this->distilRequest($rows, $sourceLanguage, $result);
            $budget->endRequest();

            if ($answered) {
                $failures = 0;
            } elseif (++$failures === self::MAX_CONSECUTIVE_FAILURES) {
                $result->aborted   = true;
                $result->lastError = \sprintf(
                    'Stopped after %d failures in a row, the last being: %s',
                    $failures,
                    $result->lastError
                );

                break;
            }
        }
    }

    /**
     * Give the feedback rows that were set aside as failed a new set of attempts.
     *
     * For use once the cause of the failures is fixed, such as an invalid model or an empty
     * credit balance.
     *
     * @return  integer  The number of rows made pending again.
     *
     * @since   1.2.0
     */
    public function resetFailed(): int
    {
        $failed    = 'failed';
        $pending   = 'pending';
        $lastError = '';
        $db        = $this->getDatabase();
        $query     = $db->getQuery(true)
            ->update($db->quoteName('#__translations_feedback'))
            ->set($db->quoteName('status') . ' = :pending')
            ->set($db->quoteName('attempts') . ' = 0')
            ->set($db->quoteName('last_error') . ' = :lastError')
            ->where($db->quoteName('status') . ' = :failed')
            ->bind(':pending', $pending, ParameterType::STRING)
            ->bind(':lastError', $lastError, ParameterType::STRING)
            ->bind(':failed', $failed, ParameterType::STRING);
        $db->setQuery($query)->execute();

        return $db->getAffectedRows();
    }

    /**
     * Merge the existing rules that say the same thing, keeping the oldest of each set.
     *
     * @param   string  $language  The target language to merge, all languages when empty.
     *
     * @return  integer  The number of rules merged away (trashed).
     *
     * @since   1.2.0
     */
    public function mergeDuplicateRules(string $language = ''): int
    {
        $lock = new RunLock($this->getDatabase(), 'distil');

        // A distil run writes rules while it runs, so merging waits until it has finished.
        if (!$lock->acquire()) {
            throw new \RuntimeException('A distil run is still busy, so the rules are not merged now. Run this task again later.');
        }

        try {
            return RuleMerger::mergeDuplicates($this->getDatabase(), $language);
        } finally {
            $lock->release();
        }
    }

    /**
     * Send one request's worth of corrections and save what comes back.
     *
     * The attempt is counted before the provider is asked, so a run that is killed while it
     * waits for an answer still uses up one of the rows' attempts. The rules and the processed
     * mark are written in one transaction right after the answer, and no transaction is open
     * while a provider is asked.
     *
     * @param   object[]   $rows            The feedback rows, all for one target language.
     * @param   string     $sourceLanguage  The source language code.
     * @param   RunResult  $result          The run's result, updated in place.
     *
     * @return  boolean  True when the provider answered and the answer was saved.
     *
     * @since   1.1.0
     */
    private function distilRequest(array $rows, string $sourceLanguage, RunResult $result): bool
    {
        $targetLanguage = (string) $rows[0]->target_language;
        $corrections    = [];
        $rowIds         = [];
        $origins        = [];

        foreach ($rows as $row) {
            $feedbackId = (int) $row->id;
            $diff       = $this->diff((string) $row->machine_draft, (string) $row->human_correction);

            $rowIds[]             = $feedbackId;
            $origins[$feedbackId] = (string) $row->source_origin;

            $this->storeDiff($feedbackId, $diff);

            [$sourceText, $machineDraft, $humanCorrection] = self::excerpts(
                (string) $row->source_text,
                (string) $row->machine_draft,
                (string) $row->human_correction
            );

            $corrections[] = [
                'id'               => $feedbackId,
                'source_text'      => $sourceText,
                'machine_draft'    => $machineDraft,
                'human_correction' => $humanCorrection,
                'diff'             => $diff,
                'occurrences'      => max(1, (int) ($row->occurrences ?? 1)),
            ];
        }

        $this->countAttempt($rowIds);

        try {
            $candidates = $this->requestCandidates(
                $corrections,
                $this->contextRules($corrections, $sourceLanguage, $targetLanguage),
                $sourceLanguage,
                $targetLanguage
            );

            // Resolved here, before the transaction, because resolving a word may ask a provider.
            $standardForms = $this->candidateStandardForms($candidates, $sourceLanguage);
        } catch (\Throwable $e) {
            $this->recordFailure($rowIds, $e->getMessage(), $result);

            return false;
        }

        $db = $this->getDatabase();
        $db->transactionStart();

        try {
            $this->persistRules($candidates, $targetLanguage, $origins, $standardForms);
            $this->markProcessed($rowIds);
            $db->transactionCommit();
        } catch (\Throwable $e) {
            $db->transactionRollback();
            $this->recordFailure($rowIds, $e->getMessage(), $result);

            return false;
        }

        $result->processed += \count($rowIds);

        return true;
    }

    /**
     * Load the pending feedback rows the next request is chosen from.
     *
     * Rows that failed most come first, then one target language at a time, oldest first. A row
     * already sent in this run is left out, so a row that failed waits for a later run rather than
     * using up its attempts within a minute.
     *
     * @param   integer  $requestSize  The most corrections in one request.
     * @param   int[]    $tried        The ids of the rows already sent in this run.
     *
     * @return  object[]  The candidate rows, in the order they should be sent.
     *
     * @since   1.2.0
     */
    private function pendingCandidates(int $requestSize, array $tried): array
    {
        $status      = 'pending';
        $maxAttempts = RunResult::MAX_ATTEMPTS;
        $db          = $this->getDatabase();
        $query       = $db->getQuery(true)
            ->select('*')
            ->from($db->quoteName('#__translations_feedback'))
            ->where($db->quoteName('status') . ' = :status')
            ->where($db->quoteName('attempts') . ' < :maxAttempts')
            ->order(
                [
                    $db->quoteName('attempts') . ' DESC',
                    $db->quoteName('target_language') . ' ASC',
                    $db->quoteName('created') . ' ASC',
                    $db->quoteName('id') . ' ASC',
                ]
            )
            ->bind(':status', $status, ParameterType::STRING)
            ->bind(':maxAttempts', $maxAttempts, ParameterType::INTEGER);

        if ($tried !== []) {
            $query->whereNotIn($db->quoteName('id'), $tried);
        }

        $db->setQuery($query, 0, $requestSize);

        return $db->loadObjectList() ?: [];
    }

    /**
     * Choose the rows of the next request from the candidates.
     *
     * A request holds rows of one target language that have had the same number of attempts:
     * as many as the request size allows - half of it for a second attempt, one for the last -
     * and as long as their estimated size fits the input budget. The first row always goes, so a
     * correction larger than the budget is sent on its own rather than never.
     *
     * @param   object[]  $candidates   The candidate rows, in the order they should be sent.
     * @param   integer   $requestSize  The most corrections in one request.
     *
     * @return  object[]  The rows of the request, none when there are no candidates.
     *
     * @since   1.2.0
     */
    private static function selectRequest(array $candidates, int $requestSize): array
    {
        if ($candidates === []) {
            return [];
        }

        $attempts = (int) ($candidates[0]->attempts ?? 0);
        $language = (string) $candidates[0]->target_language;
        $limit    = RunResult::requestLimit($requestSize, $attempts);
        $budget   = (self::INPUT_TOKEN_BUDGET - self::CONTEXT_RESERVE_TOKENS) * self::CHARS_PER_TOKEN;
        $selected = [];
        $used     = 0;

        foreach ($candidates as $row) {
            if ((int) ($row->attempts ?? 0) !== $attempts || (string) $row->target_language !== $language) {
                continue;
            }

            $size = self::estimatedSize($row);

            if ($selected !== [] && (\count($selected) >= $limit || $used + $size > $budget)) {
                break;
            }

            $selected[] = $row;
            $used      += $size;
        }

        return $selected;
    }

    /**
     * Estimate how many characters a feedback row adds to a request.
     *
     * The texts are counted as they will be sent, excerpts included, with a quarter on top for the
     * diff and the JSON around them.
     *
     * @param   object  $row  The feedback row.
     *
     * @return  integer  The estimated characters.
     *
     * @since   1.2.0
     */
    private static function estimatedSize(object $row): int
    {
        $texts = self::excerpts(
            (string) ($row->source_text ?? ''),
            (string) ($row->machine_draft ?? ''),
            (string) ($row->human_correction ?? '')
        );

        return (int) ceil(array_sum(array_map('mb_strlen', $texts)) * 1.25);
    }

    /**
     * Cut a long correction down to the parts around its changes.
     *
     * An article is stored as one feedback row holding the whole text three times, while what
     * the distiller learns from is the change. When any of the three texts is long, all three
     * are split on their block elements; when they have the same blocks, only the blocks where
     * the correction differs from the draft are sent, with the source block in the same place.
     * Otherwise, and for short texts such as language strings, the texts are sent whole.
     *
     * @param   string  $source      The source text.
     * @param   string  $draft       The machine draft.
     * @param   string  $correction  The human correction.
     *
     * @return  string[]  The source, draft and correction to send.
     *
     * @since   1.2.0
     */
    private static function excerpts(string $source, string $draft, string $correction): array
    {
        $whole = [$source, $draft, $correction];

        if (max(array_map('mb_strlen', $whole)) <= self::EXCERPT_THRESHOLD) {
            return $whole;
        }

        $split = static fn(string $text): array => preg_split(
            '/(?=<(?:p|li|h[1-6]|td|blockquote|div)\b)/i',
            $text,
            -1,
            PREG_SPLIT_NO_EMPTY
        ) ?: [$text];

        $sourceBlocks     = $split($source);
        $draftBlocks      = $split($draft);
        $correctionBlocks = $split($correction);
        $count            = \count($draftBlocks);

        if ($count < 2 || \count($sourceBlocks) !== $count || \count($correctionBlocks) !== $count) {
            return $whole;
        }

        $changed = [];

        foreach ($draftBlocks as $index => $block) {
            if (trim($block) !== trim($correctionBlocks[$index])) {
                $changed[] = $index;
            }
        }

        if ($changed === []) {
            return $whole;
        }

        $pick = static fn(array $blocks): string => implode(
            "\n…\n",
            array_map(static fn(int $index): string => trim($blocks[$index]), $changed)
        );

        return [$pick($sourceBlocks), $pick($draftBlocks), $pick($correctionBlocks)];
    }

    /**
     * Count the feedback rows still waiting to be distilled.
     *
     * @return  integer  The number of pending rows that have attempts left.
     *
     * @since   1.1.0
     */
    private function countPendingFeedback(): int
    {
        $status      = 'pending';
        $maxAttempts = RunResult::MAX_ATTEMPTS;
        $db          = $this->getDatabase();
        $query       = $db->getQuery(true)
            ->select('COUNT(*)')
            ->from($db->quoteName('#__translations_feedback'))
            ->where($db->quoteName('status') . ' = :status')
            ->where($db->quoteName('attempts') . ' < :maxAttempts')
            ->bind(':status', $status, ParameterType::STRING)
            ->bind(':maxAttempts', $maxAttempts, ParameterType::INTEGER);
        $db->setQuery($query);

        return (int) $db->loadResult();
    }

    /**
     * Count an attempt for the given feedback rows, before their request is sent.
     *
     * @param   int[]  $feedbackIds  The feedback row ids.
     *
     * @return  void
     *
     * @since   1.1.0
     */
    private function countAttempt(array $feedbackIds): void
    {
        $db    = $this->getDatabase();
        $query = $db->getQuery(true)
            ->update($db->quoteName('#__translations_feedback'))
            ->set($db->quoteName('attempts') . ' = ' . $db->quoteName('attempts') . ' + 1')
            ->whereIn($db->quoteName('id'), $feedbackIds);
        $db->setQuery($query)->execute();
    }

    /**
     * Record a failed request: keep the error on its rows and set aside the ones whose attempts
     * are used up, so no later run pays for them again.
     *
     * @param   int[]      $feedbackIds  The feedback row ids of the request.
     * @param   string     $message      The error the request ended with.
     * @param   RunResult  $result       The run's result, updated in place.
     *
     * @return  void
     *
     * @since   1.1.0
     */
    private function recordFailure(array $feedbackIds, string $message, RunResult $result): void
    {
        $db        = $this->getDatabase();
        $lastError = RunResult::errorText($message);
        $query     = $db->getQuery(true)
            ->update($db->quoteName('#__translations_feedback'))
            ->set($db->quoteName('last_error') . ' = :lastError')
            ->whereIn($db->quoteName('id'), $feedbackIds)
            ->bind(':lastError', $lastError, ParameterType::STRING);
        $db->setQuery($query)->execute();

        $failed      = 'failed';
        $maxAttempts = RunResult::MAX_ATTEMPTS;
        $query       = $db->getQuery(true)
            ->update($db->quoteName('#__translations_feedback'))
            ->set($db->quoteName('status') . ' = :failed')
            ->whereIn($db->quoteName('id'), $feedbackIds)
            ->where($db->quoteName('attempts') . ' >= :maxAttempts')
            ->bind(':failed', $failed, ParameterType::STRING)
            ->bind(':maxAttempts', $maxAttempts, ParameterType::INTEGER);
        $db->setQuery($query)->execute();

        $result->failed      += \count($feedbackIds);
        $result->quarantined += $db->getAffectedRows();
        $result->lastError    = $lastError;

        Log::add(
            \sprintf('Could not distil feedback %s: %s', implode(', ', $feedbackIds), $lastError),
            Log::WARNING,
            'translations'
        );
    }

    /**
     * Resolve the standard form of the single-word source terms among the candidates, in one go.
     *
     * @param   array   $candidates      The rule candidates.
     * @param   string  $sourceLanguage  The source language code.
     *
     * @return  array  Standard form keyed by the lower-cased term, for the terms resolved.
     *
     * @since   1.1.0
     */
    private function candidateStandardForms(array $candidates, string $sourceLanguage): array
    {
        $terms = [];

        foreach ($candidates as $candidate) {
            $term = \is_array($candidate) ? $this->nullableTerm($candidate['source_term'] ?? null) : null;

            if ($term !== null && WordNormaliser::isSingleWord($term)) {
                $terms[] = $term;
            }
        }

        if ($terms === []) {
            return [];
        }

        return WordNormaliser::standardForms($this->getDatabase(), $this->getDispatcher(), $terms, $sourceLanguage);
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

            // A token carries the whitespace after it, so re-wrapping a line changes the tokens
            // without changing a word. What is left once that whitespace is trimmed is the same
            // text on both sides, and there is no rule to be learned from it.
            if ($before === $after) {
                continue;
            }

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
     * Select the rules a provider is shown alongside one language's corrections, so it merges its
     * candidates into them rather than duplicating.
     *
     * The rules are matched against the corrections' source text the same way they are matched
     * against an item's source strings when it is translated: a rule's term and a correction's
     * source text are both in the source language, so it is the same operation.
     *
     * @param   array   $corrections     The corrections being distilled.
     * @param   string  $sourceLanguage  The source language code.
     * @param   string  $targetLanguage  The target language code.
     *
     * @return  array  The rules to send as context.
     *
     * @since   1.0.0
     */
    private function contextRules(array $corrections, string $sourceLanguage, string $targetLanguage): array
    {
        $rules = $this->loadExistingRules($targetLanguage);

        if ($rules === []) {
            return [];
        }

        $text = RuleRetriever::plainText(array_column($corrections, 'source_text'));

        // A language whose rules carry no standard form gains nothing from reducing the text.
        $standardForms = RuleRetriever::hasStandardForm($rules)
            ? WordNormaliser::standardForms(
                $this->getDatabase(),
                $this->getDispatcher(),
                WordNormaliser::tokenise($text),
                $sourceLanguage
            )
            : [];

        return $this->selectContextRules($rules, $text, $standardForms);
    }

    /**
     * Load the rules learned for a target language, drafts first and by confidence within each
     * state, so a cap keeps the ones likeliest to be duplicated and safest to refine.
     *
     * @param   string  $targetLanguage  The target language code.
     *
     * @return  array  The rule rows, carrying the columns rules are matched on.
     *
     * @since   0.4.0
     */
    private function loadExistingRules(string $targetLanguage): array
    {
        $db    = $this->getDatabase();
        $query = $db->getQuery(true)
            ->select(
                $db->quoteName(
                    [
                        'id', 'rule_type', 'rule_name', 'rule_text',
                        'source_term', 'source_term_standard', 'target_term', 'search_keywords',
                    ]
                )
            )
            ->from($db->quoteName('#__translations_rules'))
            ->where($db->quoteName('target_language') . ' = :lang')
            ->whereIn($db->quoteName('state'), self::CONTEXT_STATES)
            ->order(
                [
                    $db->quoteName('state') . ' ASC',
                    $db->quoteName('confidence') . ' DESC',
                    $db->quoteName('id') . ' DESC',
                ]
            )
            ->bind(':lang', $targetLanguage, ParameterType::STRING);
        $db->setQuery($query);

        return $db->loadAssocList() ?: [];
    }

    /**
     * Keep the rules that apply to the corrections, capped, and reduce each to the fields a
     * provider is given.
     *
     * Style rules apply to the whole language rather than to a term, so they are not narrowed by
     * the text and carry their own cap. The columns rules are matched on are dropped here, so
     * they never reach the request.
     *
     * @param   array   $rules          The candidate rules, ordered.
     * @param   string  $text           The corrections' readable source text.
     * @param   array   $standardForms  Standard form keyed by the text's words, where known.
     *
     * @return  array  The rules to send as context.
     *
     * @since   1.0.0
     */
    private function selectContextRules(array $rules, string $text, array $standardForms): array
    {
        $selected = [];
        $style    = 0;

        foreach ($rules as $rule) {
            if (\count($selected) >= self::MAX_CONTEXT_RULES) {
                break;
            }

            if ($rule['rule_type'] === 'style') {
                if ($style >= self::MAX_CONTEXT_STYLE_RULES) {
                    continue;
                }

                $style++;
            } elseif (!RuleRetriever::appliesToText($rule, $text, $standardForms)) {
                continue;
            }

            $selected[] = array_intersect_key($rule, array_flip(self::CONTEXT_RULE_FIELDS));
        }

        return $selected;
    }

    /**
     * Ask the "rag" plugin group to distil rule candidates from a batch of corrections.
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
        PluginHelper::importPlugin('rag', null, true, $dispatcher);

        $event = new DistilEvent('onDistil', [
            'corrections'    => $corrections,
            'existingRules'  => $existingRules,
            'sourceLanguage' => $sourceLanguage,
            'targetLanguage' => $targetLanguage,
        ]);
        $started = microtime(true);
        $answer  = null;
        $failure = '';

        try {
            $dispatcher->dispatch('onDistil', $event);

            // The first provider that answered wins, even when it distilled nothing.
            foreach ((array) $event->getArgument('result', []) as $providerResult) {
                if (\is_array($providerResult)) {
                    return $answer = $providerResult;
                }
            }

            throw new \RuntimeException('No distillation provider is enabled. Enable a RAG plugin to distil rules.');
        } catch (\Throwable $e) {
            $failure = $e->getMessage();

            throw $e;
        } finally {
            // A long answer can outlast the server's patience with an idle connection, and the
            // rules distilled from it are stored next.
            DatabaseConnection::reconnectIfDropped($this->getDatabase());

            if (RequestLog::enabled()) {
                RequestLog::write($this->getDatabase(), [
                    'kind'            => RequestLog::DISTIL,
                    'source_language' => $sourceLanguage,
                    'target_language' => $targetLanguage,
                    'strings'         => \count($corrections),
                    'characters'      => (int) mb_strlen((string) json_encode($corrections, JSON_UNESCAPED_UNICODE)),
                    'duration_ms'     => (int) round((microtime(true) - $started) * 1000),
                    'outcome'         => $answer === null ? RequestLog::ERROR : RequestLog::OK,
                    'message'         => $failure,
                    'settings'        => [
                        'max_context_rules'       => self::MAX_CONTEXT_RULES,
                        'max_context_style_rules' => self::MAX_CONTEXT_STYLE_RULES,
                    ],
                    'request' => [
                        'corrections'   => $corrections,
                        'existingRules' => $existingRules,
                    ],
                    'response' => $answer,
                ] + RequestLog::ruleCounts(self::byType($existingRules)));
            }
        }
    }

    /**
     * Rules grouped by their type, as the request log counts them.
     *
     * @param   array  $rules  The rules, each with its rule_type.
     *
     * @return  array<string, array>
     *
     * @since   3.0.0
     */
    private static function byType(array $rules): array
    {
        $grouped = [];

        foreach ($rules as $rule) {
            $grouped[(string) (((array) $rule)['rule_type'] ?? '')][] = $rule;
        }

        return $grouped;
    }

    /**
     * Persist rule candidates as draft rules, skipping any that fail validation.
     *
     * @param   array   $candidates      The rule candidates.
     * @param   string  $targetLanguage  The target language code.
     * @param   array   $origins         The origin of each feedback row, keyed by row id.
     * @param   array   $standardForms   Standard form keyed by lower-cased source term, where known.
     *
     * @return  void
     *
     * @since   0.4.0
     */
    private function persistRules(
        array $candidates,
        string $targetLanguage,
        array $origins,
        array $standardForms
    ): void {
        foreach ($candidates as $candidate) {
            if (!\is_array($candidate)) {
                continue;
            }

            try {
                $this->saveRule($candidate, $targetLanguage, $origins, $standardForms);
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
     * Save one rule candidate: refine an existing rule when the candidate carries its id, else
     * insert a new one, held back for review unless the option publishes it on creation.
     *
     * @param   array   $candidate       The rule candidate.
     * @param   string  $targetLanguage  The target language code.
     * @param   array   $origins         The origin of each feedback row, keyed by row id.
     * @param   array   $standardForms   Standard form keyed by lower-cased source term, where known.
     *
     * @return  void
     *
     * @throws  \RuntimeException  When the rule fails validation or cannot be stored.
     *
     * @since   0.4.0
     */
    private function saveRule(
        array $candidate,
        string $targetLanguage,
        array $origins,
        array $standardForms
    ): void {
        /** @var RuleTable $table */
        $table       = $this->getTable('Rule', 'Administrator');
        $feedbackIds = array_values(array_unique(array_map('intval', (array) ($candidate['source_feedback_ids'] ?? []))));
        $existingId  = (int) ($candidate['id'] ?? 0);

        if (!self::hasEvidence($candidate, array_keys($origins))) {
            throw new \RuntimeException('The rule has no confidence or no correction of this request behind it.');
        }

        // The rule fields the provider (re)states each time. Bind them (rather than set them
        // directly) so the table's _jsonEncode encodes source_feedback_ids and the array is
        // not dropped by the driver on store.
        $sourceTerm = $this->nullableTerm($candidate['source_term'] ?? null);

        $data = [
            'rule_name'            => (string) ($candidate['rule_name'] ?? ''),
            'rule_type'            => (string) ($candidate['rule_type'] ?? ''),
            'target_language'      => $targetLanguage,
            'rule_text'            => (string) ($candidate['rule_text'] ?? ''),
            'source_term'          => $sourceTerm,
            'source_term_standard' => $sourceTerm !== null && WordNormaliser::isSingleWord($sourceTerm)
                ? ($standardForms[mb_strtolower($sourceTerm)] ?? null)
                : null,
            'target_term'          => $this->nullableTerm($candidate['target_term'] ?? null),
            'search_keywords'      => (string) ($candidate['search_keywords'] ?? ''),
        ];

        // An id refines only a live rule of the same language; any other id the provider gives
        // (a rule it misremembers, or a trashed one) is treated as no id at all.
        if ($existingId > 0 && !$this->isLiveRule($table, $existingId, $targetLanguage)) {
            $table->reset();
            $table->id  = null;
            $existingId = 0;
        }

        // A provider sees only the rules that fit its corrections, so it can offer as new a rule
        // that already exists. Such a rule refines the existing one instead of duplicating it.
        if ($existingId === 0) {
            $existingId = RuleMerger::findSame($this->getDatabase(), $data);
        }

        if ($existingId > 0 && $table->load($existingId)) {
            // Refine in place: overlay the provider's improved wording, accumulate the evidence,
            // and never let confidence drop; the original state and origin are kept.
            $data['confidence']          = max((float) $table->confidence, (float) ($candidate['confidence'] ?? 0));
            $data['source_feedback_ids'] = $this->mergeFeedbackIds($table->source_feedback_ids, $feedbackIds);
        } else {
            // A new rule is held back for review, unless the option publishes it on creation.
            $publishOnCreation = (bool) ComponentHelper::getParams('com_translations')->get('auto_publish_rules', 0);

            $data['confidence']          = (float) ($candidate['confidence'] ?? 0);
            $data['source_feedback_ids'] = $feedbackIds;
            $data['source_origin']       = $this->originOf($feedbackIds, $origins);
            $data['state']               = $publishOnCreation ? 1 : 0;
        }

        if (!$table->bind($data) || !$table->check() || !$table->store()) {
            throw new \RuntimeException('The distilled rule could not be stored.');
        }
    }

    /**
     * Work out the origin to record on a rule from the feedback that produced it.
     *
     * A rule is only as attributable as its evidence: when every correction behind it came from
     * the same place, the rule carries that origin, so a set imported from elsewhere can later be
     * reviewed, exported or withdrawn as a set. Mixed evidence belongs to no one source, and a
     * rule built from it is distilled like any other.
     *
     * @param   int[]  $feedbackIds  The feedback rows the rule was built from.
     * @param   array  $origins      The origin of each feedback row, keyed by row id.
     *
     * @return  string  The origin to record.
     *
     * @since   1.0.0
     */
    private function originOf(array $feedbackIds, array $origins): string
    {
        $found = [];

        foreach ($feedbackIds as $feedbackId) {
            if (isset($origins[$feedbackId])) {
                $found[$origins[$feedbackId]] = true;
            }
        }

        return \count($found) === 1 ? (string) array_key_first($found) : self::SOURCE_ORIGIN;
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
     * Whether a rule candidate is backed by this request: a confidence above zero, and at least
     * one of the corrections it was sent. A rule without that is something the provider made up.
     *
     * @param   array  $candidate   The rule candidate.
     * @param   int[]  $requestIds  The ids of the feedback rows in the request.
     *
     * @return  boolean
     *
     * @since   1.2.2
     */
    private static function hasEvidence(array $candidate, array $requestIds): bool
    {
        if ((float) ($candidate['confidence'] ?? 0) <= 0) {
            return false;
        }

        $feedbackIds = array_map('intval', (array) ($candidate['source_feedback_ids'] ?? []));

        return array_intersect($feedbackIds, array_map('intval', $requestIds)) !== [];
    }

    /**
     * Load a rule into the table and tell whether it is live (unpublished or published) and in
     * the given language, so a candidate may refine it.
     *
     * @param   RuleTable  $table           The rule table.
     * @param   integer    $id              The rule id the candidate gave.
     * @param   string     $targetLanguage  The language of the request.
     *
     * @return  boolean
     *
     * @since   1.2.2
     */
    private function isLiveRule(RuleTable $table, int $id, string $targetLanguage): bool
    {
        return $table->load($id)
            && \in_array((int) $table->state, self::CONTEXT_STATES, true)
            && (string) $table->target_language === $targetLanguage;
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
