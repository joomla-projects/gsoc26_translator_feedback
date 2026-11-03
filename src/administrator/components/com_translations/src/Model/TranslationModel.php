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

use Joomla\CMS\Application\ApplicationHelper;
use Joomla\CMS\Application\CMSApplicationInterface;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Extension\ComponentInterface;
use Joomla\CMS\MVC\Factory\MVCFactoryServiceInterface;
use Joomla\CMS\MVC\Model\AdminModel;
use Joomla\CMS\MVC\Model\BaseDatabaseModel;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\Component\Fields\Administrator\Helper\FieldsHelper;
use Joomla\Component\Fields\Administrator\Model\FieldModel;
use Joomla\Component\Translations\Administrator\Helper\ContentTypesHelper;
use Joomla\Component\Translations\Administrator\Helper\DatabaseConnection;
use Joomla\Component\Translations\Administrator\Helper\DraftAlias;
use Joomla\Component\Translations\Administrator\Helper\LanguageShare;
use Joomla\Component\Translations\Administrator\Helper\RuleRetriever;
use Joomla\Component\Translations\Administrator\Helper\RunLock;
use Joomla\Component\Translations\Administrator\Helper\RunResult;
use Joomla\Component\Translations\Administrator\Helper\SourceState;
use Joomla\Component\Translations\Administrator\Helper\StringTranslator;
use Joomla\Component\Translations\Administrator\Helper\TextChunker;
use Joomla\Component\Translations\Administrator\Helper\TimeBudget;
use Joomla\Component\Translations\Administrator\Helper\TranslatableValuesHelper;
use Joomla\Component\Translations\Administrator\Link\DatabaseLinkLookup;
use Joomla\Component\Translations\Administrator\Link\LinkTranslator;
use Joomla\Component\Translations\Administrator\Link\TranslatedTexts;
use Joomla\Database\ParameterType;
use Joomla\Database\QueryInterface;
use Joomla\Filesystem\Folder;
use Joomla\Registry\Registry;

/**
 * Producer model: turns a source item and a target language into a translated
 * draft, linked back to the source via #__associations, with its per-language
 * queue state set to "review" (ready for a translator to correct). The draft is
 * unpublished unless the component is set to publish new translations.
 *
 * @since  0.3.0
 */
class TranslationModel extends BaseDatabaseModel
{
    /**
     * Failures in a row that end a batch run.
     *
     * One item can fail on its own account, but a run of them means the provider is unreachable,
     * and every further attempt is another paid call for the same answer.
     *
     * @var    integer
     * @since  0.11.0
     */
    private const MAX_CONSECUTIVE_FAILURES = 2;

    /**
     * The error a translation holds while its request is under way, so an attempt that is cut
     * off before the provider answers still says why it did not finish.
     *
     * @var    string
     * @since  1.3.0
     */
    private const NO_ANSWER = 'No answer came back from the provider: the run stopped while it waited, most likely at the server\'s time limit.';

    /**
     * The most terms carried from one part of a long item to the next.
     *
     * The terms are there to keep a word translated the same way throughout an item, and the ones
     * that matter are the recurring ones. A cap keeps every later request's prompt bounded however
     * many parts an item has.
     *
     * @var    integer
     * @since  1.4.0
     */
    private const MAX_GLOSSARY_TERMS = 60;

    /**
     * Translate a source item into one target language.
     *
     * Creates the draft with its association to the source and sets the
     * per-language queue state to "review", ready for translator feedback.
     *
     * An item whose strings are too long for one request is translated in several, as many as
     * the run's time budget allows. The parts translated are stored as they are answered, so a
     * run that ends before the item does has paid for nothing twice: it reports that the item is
     * unfinished, and the next run carries on where it left off.
     *
     * @param   integer                  $sourceItemId     The source item id.
     * @param   string                   $targetLanguage   The target language code, e.g. 'fr-FR'.
     * @param   string                   $contentType      The content type key, e.g. 'com_content.article'.
     * @param   CMSApplicationInterface  $application      The application, used to boot the component.
     * @param   TimeBudget|null          $budget           How long this run may keep sending requests for the
     *                                                     item; the default for where it runs when null.
     * @param   RunResult|null           $result           The run's result, to count the parts translated in.
     *
     * @return  boolean  True when the item is translated and its draft saved, false when parts of it are
     *                   still to be translated by a later run.
     *
     * @throws  \RuntimeException  If the item is missing or not translatable, or the draft cannot be created.
     *
     * @since   0.3.0
     */
    public function translate(
        int $sourceItemId,
        string $targetLanguage,
        string $contentType,
        CMSApplicationInterface $application,
        ?TimeBudget $budget = null,
        ?RunResult $result = null
    ): bool {
        $properties = ContentTypesHelper::getProperties($contentType);
        $sourceItem = $this->getSourceItem($sourceItemId, (string) ($properties['table'] ?? ''));

        // Some tables hold several extensions' items; only translate the mapped extension.
        if (isset($properties['limitToExtension'])
            && ($sourceItem['extension'] ?? null) !== $properties['limitToExtension']) {
            throw new \RuntimeException(\sprintf('Item %d is outside the %s extension.', $sourceItemId, $properties['limitToExtension']));
        }

        // An all-languages item is shown for every language, so there is nothing to translate.
        if ($sourceItem['language'] === '*') {
            throw new \RuntimeException(\sprintf('Item %d applies to all languages.', $sourceItemId));
        }

        if ($sourceItem['language'] === $targetLanguage) {
            throw new \RuntimeException(\sprintf('Item %d is already in %s.', $sourceItemId, $targetLanguage));
        }

        if ($this->isDoNotTranslate($sourceItemId, $contentType)) {
            throw new \RuntimeException(\sprintf('Item %d is marked as not to be translated.', $sourceItemId));
        }

        // Hand all translatable strings over together: the item's columns and its translatable custom fields.
        $translatableFields = (array) ($properties['translatableFields'] ?? []);
        $customFields       = $this->collectCustomFields($sourceItem, $properties);
        $strings            = $this->collectTranslatableStrings($sourceItem, $translatableFields);

        foreach ($customFields as $name => $customField) {
            if ($customField['translatable']) {
                $strings[TranslatableValuesHelper::CUSTOM_FIELD_PREFIX . $name] = $customField['value'];
            }
        }

        $stateId    = $this->getOrCreateStateId($sourceItemId, $contentType, $targetLanguage);
        $translated = $this->translateInChunks(
            $strings,
            (string) $sourceItem['language'],
            $targetLanguage,
            $stateId,
            $budget ?? new TimeBudget(),
            $result
        );

        // The run's time went before every part was translated; what is done is stored and waits.
        if ($translated === null) {
            return false;
        }

        $machineDraft = $this->createDraft($sourceItem, $translated, $customFields, $targetLanguage, $contentType, $application, $properties);
        $this->markReadyForReview($sourceItemId, $targetLanguage, $contentType, $machineDraft, $properties);

        // The draft holds the translation now, so the parts it was built from are no longer needed.
        $this->clearPartial($stateId);

        return true;
    }

    /**
     * Translate a batch of the items waiting on a translation, for as long as the run's time
     * budget allows.
     *
     * Work is a source item and a target language whose state row is absent, meaning the item has
     * never been translated into that language, or holds "pending", meaning the source has changed
     * since it was or an earlier attempt failed. Each item is one request, saved as soon as it is
     * answered. Its attempt is counted before the request is sent, so a run that is killed while it
     * waits still uses one up; after its third failed attempt the item is set aside as failed.
     *
     * @param   integer                  $batchSize    The most items translated in one run.
     * @param   CMSApplicationInterface  $application  The application, used to boot the component.
     * @param   TimeBudget|null          $budget       How long the run may keep sending requests; the
     *                                                 default for where it runs when null.
     *
     * @return  RunResult  What the run did, and whether it should run again.
     *
     * @throws  \RuntimeException  When no translation provider is enabled.
     *
     * @since   0.11.0
     */
    public function translateBatch(int $batchSize, CMSApplicationInterface $application, ?TimeBudget $budget = null): RunResult
    {
        // Without a provider every request would fail and use up an attempt of items that are fine.
        if (PluginHelper::getPlugin('translation') === []) {
            throw new \RuntimeException('No translation provider is enabled. Enable a translation plugin to translate content.');
        }

        $budget = $budget ?? new TimeBudget();
        $result = new RunResult();
        $lock   = new RunLock($this->getDatabase(), 'translate');

        // Another run would pick the same items, pay for them a second time and save a second draft.
        if (!$lock->acquire()) {
            $result->busy      = true;
            $result->remaining = $this->countPendingWork();

            return $result;
        }

        try {
            $this->setAsideUnanswered($result);
            $this->translateWithinBudget($batchSize, $application, $budget, $result);
        } finally {
            $lock->release();
        }

        $result->remaining = $this->countPendingWork();

        return $result;
    }

    /**
     * Give the translations that were set aside as failed a new set of attempts.
     *
     * For use once the cause of the failures is fixed, such as an invalid model or an empty
     * credit balance.
     *
     * @return  integer  The number of translations made pending again.
     *
     * @since   1.3.0
     */
    public function resetFailed(): int
    {
        $failed    = 'failed';
        $pending   = 'pending';
        $lastError = '';
        $db        = $this->getDatabase();
        $query     = $db->getQuery(true)
            ->update($db->quoteName('#__translations_queue_states'))
            ->set($db->quoteName('translation_state') . ' = :pending')
            ->set($db->quoteName('attempts') . ' = 0')
            ->set($db->quoteName('last_error') . ' = :lastError')
            ->where($db->quoteName('translation_state') . ' = :failed')
            ->bind(':pending', $pending, ParameterType::STRING)
            ->bind(':lastError', $lastError, ParameterType::STRING)
            ->bind(':failed', $failed, ParameterType::STRING);
        $db->setQuery($query)->execute();

        return $db->getAffectedRows();
    }

    /**
     * Send requests until the batch is done, the budget is used up, or the provider keeps failing.
     *
     * The work is chosen once, at the start, so an item that fails is not tried again in the same
     * run but waits for a later one.
     *
     * @param   integer                  $batchSize    The most items translated in one run.
     * @param   CMSApplicationInterface  $application  The application, used to boot the component.
     * @param   TimeBudget               $budget       How long the run may keep sending requests.
     * @param   RunResult                $result       The run's result, updated in place.
     *
     * @return  void
     *
     * @since   1.3.0
     */
    private function translateWithinBudget(int $batchSize, CMSApplicationInterface $application, TimeBudget $budget, RunResult $result): void
    {
        $failures = 0;

        foreach ($this->pendingWork($batchSize) as $work) {
            if (!$budget->allowsAnother()) {
                break;
            }

            $stateId = $this->countAttempt($work);

            try {
                $finished = $this->translate(
                    $work['sourceItemId'],
                    $work['targetLanguage'],
                    $work['contentType'],
                    $application,
                    $budget,
                    $result
                );
            } catch (\Throwable $e) {
                $this->recordFailure($stateId, $e->getMessage(), $result);

                if (++$failures === self::MAX_CONSECUTIVE_FAILURES) {
                    $result->aborted   = true;
                    $result->lastError = \sprintf(
                        'Stopped after %d failures in a row, the last being: %s',
                        $failures,
                        $result->lastError
                    );

                    break;
                }

                continue;
            }

            // An item left unfinished is still pending, so a later run picks up its remaining parts.
            if ($finished) {
                $result->processed++;
            }

            $failures = 0;
        }
    }

    /**
     * Count an attempt for one item of work, before its request is sent.
     *
     * An item never translated into the language gets its queue and state rows here, so the attempt
     * has somewhere to be counted. The error is set to say no answer came back, and stays so when the
     * run is killed while it waits; an answer, or a failure that is caught, replaces it.
     *
     * @param   array{contentType: string, sourceItemId: int, targetLanguage: string, stateId: int|null}  $work  The item of work.
     *
     * @return  integer  The id of the item's state row.
     *
     * @since   1.3.0
     */
    private function countAttempt(array $work): int
    {
        $db        = $this->getDatabase();
        $lastError = self::NO_ANSWER;
        $stateId   = $work['stateId'] ?? null;

        if ($stateId === null) {
            $stateId = $this->getOrCreateStateId($work['sourceItemId'], $work['contentType'], $work['targetLanguage']);
        }

        $query = $db->getQuery(true)
            ->update($db->quoteName('#__translations_queue_states'))
            ->set($db->quoteName('attempts') . ' = ' . $db->quoteName('attempts') . ' + 1')
            ->set($db->quoteName('last_error') . ' = :lastError')
            ->where($db->quoteName('id') . ' = :stateId')
            ->bind(':lastError', $lastError, ParameterType::STRING)
            ->bind(':stateId', $stateId, ParameterType::INTEGER);
        $db->setQuery($query)->execute();

        return $stateId;
    }

    /**
     * The id of an item's state row for one target language, created when it has none.
     *
     * An item never translated into the language has no row yet, and both an attempt and the
     * parts of a long item need somewhere to be recorded before its first request is sent.
     *
     * @param   integer  $sourceItemId    The source item id.
     * @param   string   $contentType     The content type key, e.g. 'com_content.article'.
     * @param   string   $targetLanguage  The target language code.
     *
     * @return  integer  The id of the item's state row.
     *
     * @since   1.4.0
     */
    private function getOrCreateStateId(int $sourceItemId, string $contentType, string $targetLanguage): int
    {
        $db      = $this->getDatabase();
        $queueId = $this->getOrCreateQueueId($sourceItemId, $contentType);
        $query   = $db->getQuery(true)
            ->select($db->quoteName('id'))
            ->from($db->quoteName('#__translations_queue_states'))
            ->where($db->quoteName('queue_id') . ' = :queueId')
            ->where($db->quoteName('target_language') . ' = :targetLanguage')
            ->bind(':queueId', $queueId, ParameterType::INTEGER)
            ->bind(':targetLanguage', $targetLanguage, ParameterType::STRING);
        $db->setQuery($query);

        $stateId = (int) $db->loadResult();

        if ($stateId !== 0) {
            return $stateId;
        }

        $stateRow = (object) [
            'queue_id'          => $queueId,
            'target_language'   => $targetLanguage,
            'translation_state' => 'pending',
            'attempts'          => 0,
            'last_error'        => '',
        ];

        $db->insertObject('#__translations_queue_states', $stateRow, 'id');

        return (int) $stateRow->id;
    }

    /**
     * Record a failed attempt: keep the error on the item and set it aside when its attempts are
     * used up, so no later run pays for it again.
     *
     * @param   integer    $stateId  The id of the item's state row.
     * @param   string     $message  The error the attempt ended with.
     * @param   RunResult  $result   The run's result, updated in place.
     *
     * @return  void
     *
     * @since   1.3.0
     */
    private function recordFailure(int $stateId, string $message, RunResult $result): void
    {
        $db        = $this->getDatabase();
        $lastError = RunResult::errorText($message);
        $query     = $db->getQuery(true)
            ->update($db->quoteName('#__translations_queue_states'))
            ->set($db->quoteName('last_error') . ' = :lastError')
            ->where($db->quoteName('id') . ' = :stateId')
            ->bind(':lastError', $lastError, ParameterType::STRING)
            ->bind(':stateId', $stateId, ParameterType::INTEGER);
        $db->setQuery($query)->execute();

        $failed      = 'failed';
        $maxAttempts = RunResult::MAX_ATTEMPTS;
        $query       = $db->getQuery(true)
            ->update($db->quoteName('#__translations_queue_states'))
            ->set($db->quoteName('translation_state') . ' = :failed')
            ->where($db->quoteName('id') . ' = :stateId')
            ->where($db->quoteName('attempts') . ' >= :maxAttempts')
            ->bind(':failed', $failed, ParameterType::STRING)
            ->bind(':stateId', $stateId, ParameterType::INTEGER)
            ->bind(':maxAttempts', $maxAttempts, ParameterType::INTEGER);
        $db->setQuery($query)->execute();

        $result->failed++;
        $result->quarantined += $db->getAffectedRows();
        $result->lastError    = $lastError;
    }

    /**
     * Set aside the translations whose last attempt never came back.
     *
     * A run killed while it waits on the provider records nothing after its request, so an item on
     * its last attempt would stay pending with no attempts left: never picked up again, and never
     * shown as failed. Such an item is counted as failed by the run that finds it.
     *
     * @param   RunResult  $result  The run's result, updated in place.
     *
     * @return  void
     *
     * @since   1.3.0
     */
    private function setAsideUnanswered(RunResult $result): void
    {
        $failed      = 'failed';
        $pending     = 'pending';
        $maxAttempts = RunResult::MAX_ATTEMPTS;
        $db          = $this->getDatabase();
        $query       = $db->getQuery(true)
            ->update($db->quoteName('#__translations_queue_states'))
            ->set($db->quoteName('translation_state') . ' = :failed')
            ->where($db->quoteName('translation_state') . ' = :pending')
            ->where($db->quoteName('attempts') . ' >= :maxAttempts')
            ->bind(':failed', $failed, ParameterType::STRING)
            ->bind(':pending', $pending, ParameterType::STRING)
            ->bind(':maxAttempts', $maxAttempts, ParameterType::INTEGER);
        $db->setQuery($query)->execute();

        $setAside = $db->getAffectedRows();

        if ($setAside > 0) {
            $result->failed      += $setAside;
            $result->quarantined += $setAside;
            $result->lastError    = self::NO_ANSWER;
        }
    }

    /**
     * Clear the "no need for translation" flag on a source item's queue row.
     *
     * @param   integer  $sourceItemId  The source item id.
     * @param   string   $contentType   The content type key, e.g. 'com_content.article'.
     *
     * @return  void
     *
     * @since   0.3.0
     */
    public function allowTranslation(int $sourceItemId, string $contentType): void
    {
        $db    = $this->getDatabase();
        $query = $db->getQuery(true)
            ->update($db->quoteName('#__translations_queue'))
            ->set($db->quoteName('do_not_translate') . ' = 0')
            ->where($db->quoteName('content_type') . ' = :contentType')
            ->where($db->quoteName('content_id') . ' = :contentId')
            ->bind(':contentType', $contentType, ParameterType::STRING)
            ->bind(':contentId', $sourceItemId, ParameterType::INTEGER);
        $db->setQuery($query);
        $db->execute();
    }

    /**
     * Collect the items waiting on a translation, up to a batch.
     *
     * Each content type is queried on its own, because each has its own table and publish-state
     * column, and the walk stops as soon as the batch is full. Types are walked in dependency
     * order, so an item's related items are translated before the item that points at them.
     * Within a type the target languages take turns (see LanguageShare), so one language does not
     * fill the batch while the others wait.
     *
     * @param   integer  $batchSize  The most items to collect.
     *
     * @return  array<int, array{contentType: string, sourceItemId: int, targetLanguage: string, stateId: int|null}>
     *
     * @since   0.11.0
     */
    private function pendingWork(int $batchSize): array
    {
        $sourceLanguage  = (string) ComponentHelper::getParams('com_translations')->get('source_language', 'en-GB');
        $targetLanguages = $this->targetLanguages($sourceLanguage);

        if ($targetLanguages === []) {
            return [];
        }

        $targetLanguages = LanguageShare::leastRecentFirst($targetLanguages, $this->lastAttempts());
        $work            = [];

        foreach (ContentTypesHelper::getContentTypesInDependencyOrder() as $contentType) {
            $remaining = $batchSize - \count($work);

            if ($remaining < 1) {
                break;
            }

            $properties = ContentTypesHelper::getProperties($contentType);
            $waiting    = [];

            foreach ($targetLanguages as $targetLanguage) {
                $waiting[$targetLanguage] = $this->untranslatedItems($contentType, $properties, $sourceLanguage, $targetLanguage, $remaining);
            }

            foreach (LanguageShare::interleave($waiting, $remaining) as [$targetLanguage, $item]) {
                $work[] = [
                    'contentType'    => $contentType,
                    'sourceItemId'   => (int) $item->id,
                    'targetLanguage' => $targetLanguage,
                    'stateId'        => $item->stateId === null ? null : (int) $item->stateId,
                ];
            }
        }

        return $work;
    }

    /**
     * When an item was last attempted in each target language, as far as the queue states tell.
     *
     * A state row is touched whenever an attempt is counted, so its latest modification is the
     * latest attempt in that language, give or take a translator's review.
     *
     * @return  array<string, string>  The latest modification per language code.
     *
     * @since   1.6.1
     */
    private function lastAttempts(): array
    {
        $db    = $this->getDatabase();
        $query = $db->getQuery(true)
            ->select([$db->quoteName('target_language'), 'MAX(' . $db->quoteName('modified') . ') AS ' . $db->quoteName('lastAttempt')])
            ->from($db->quoteName('#__translations_queue_states'))
            ->group($db->quoteName('target_language'));
        $db->setQuery($query);

        return array_map('strval', $db->loadAssocList('target_language', 'lastAttempt') ?: []);
    }

    /**
     * Count the items still waiting on a translation, with attempts left.
     *
     * @return  integer  The number of source item and target language pairs a run would translate.
     *
     * @since   1.3.0
     */
    private function countPendingWork(): int
    {
        $sourceLanguage = (string) ComponentHelper::getParams('com_translations')->get('source_language', 'en-GB');
        $db             = $this->getDatabase();
        $count          = 0;

        foreach ($this->targetLanguages($sourceLanguage) as $targetLanguage) {
            foreach (ContentTypesHelper::getContentTypes() as $contentType) {
                $query = $this->untranslatedQuery($contentType, ContentTypesHelper::getProperties($contentType), $sourceLanguage, $targetLanguage)
                    ->select('COUNT(*)');
                $db->setQuery($query);

                $count += (int) $db->loadResult();
            }
        }

        return $count;
    }

    /**
     * The languages translated into: every published content language but the source and "all".
     *
     * @param   string  $sourceLanguage  The configured source language code.
     *
     * @return  string[]  The language codes, in the order the languages are ordered.
     *
     * @since   0.11.0
     */
    private function targetLanguages(string $sourceLanguage): array
    {
        $db    = $this->getDatabase();
        $query = $db->getQuery(true)
            ->select($db->quoteName('lang_code'))
            ->from($db->quoteName('#__languages'))
            ->where($db->quoteName('published') . ' = 1')
            ->where($db->quoteName('lang_code') . ' <> ' . $db->quote('*'))
            ->where($db->quoteName('lang_code') . ' <> :sourceLanguage')
            ->bind(':sourceLanguage', $sourceLanguage, ParameterType::STRING)
            ->order($db->quoteName('ordering') . ' ASC');
        $db->setQuery($query);

        return $db->loadColumn();
    }

    /**
     * Source items of one content type with no usable translation in a language.
     *
     * An item that failed before comes after the untried ones, so an item that keeps failing, such
     * as an article too long to translate within the server's time limit, never holds up the rest.
     *
     * @param   string   $contentType     The content type key, e.g. 'com_content.article'.
     * @param   array    $properties      The content type's properties from the map.
     * @param   string   $sourceLanguage  The configured source language code.
     * @param   string   $targetLanguage  The target language code.
     * @param   integer  $limit           The most rows to return.
     *
     * @return  object[]  The source item id and the id of its state row, null when it has none.
     *
     * @since   0.11.0
     */
    private function untranslatedItems(string $contentType, array $properties, string $sourceLanguage, string $targetLanguage, int $limit): array
    {
        $db    = $this->getDatabase();
        $query = $this->untranslatedQuery($contentType, $properties, $sourceLanguage, $targetLanguage)
            ->select($db->quoteName(['item.id', 'queueState.id'], ['id', 'stateId']))
            ->order(
                [
                    'COALESCE(' . $db->quoteName('queueState.attempts') . ', 0) ASC',
                    $db->quoteName('item.id') . ' ASC',
                ]
            );
        $db->setQuery($query, 0, $limit);

        return $db->loadObjectList();
    }

    /**
     * The query for the source items of one content type with no usable translation in a language,
     * without its select list, so it serves both to choose the work and to count it.
     *
     * @param   string  $contentType     The content type key.
     * @param   array   $properties      The content type's properties from the map.
     * @param   string  $sourceLanguage  The configured source language code.
     * @param   string  $targetLanguage  The target language code.
     *
     * @return  QueryInterface
     *
     * @since   1.3.0
     */
    private function untranslatedQuery(string $contentType, array $properties, string $sourceLanguage, string $targetLanguage): QueryInterface
    {
        $table       = (string) ($properties['table'] ?? '');
        $stateField  = (string) ($properties['stateField'] ?? '');
        $pending     = 'pending';
        $maxAttempts = RunResult::MAX_ATTEMPTS;

        $db    = $this->getDatabase();
        $query = $db->getQuery(true)
            ->from($db->quoteName($table, 'item'))
            // LEFT joins keep items that have no rows of ours at all, which is what an item nothing
            // has translated yet looks like.
            ->join(
                'LEFT',
                $db->quoteName('#__translations_queue', 'queue')
                . ' ON ' . $db->quoteName('queue.content_id') . ' = ' . $db->quoteName('item.id')
                . ' AND ' . $db->quoteName('queue.content_type') . ' = ' . $db->quote($contentType)
            )
            ->join(
                'LEFT',
                $db->quoteName('#__translations_queue_states', 'queueState')
                . ' ON ' . $db->quoteName('queueState.queue_id') . ' = ' . $db->quoteName('queue.id')
                . ' AND ' . $db->quoteName('queueState.target_language') . ' = ' . $db->quote($targetLanguage)
            )
            ->where($db->quoteName('item.language') . ' = :sourceLanguage')
            ->where($db->quoteName('item.' . $stateField) . ' <> -2')
            ->where(
                '(' . $db->quoteName('queue.do_not_translate') . ' IS NULL OR '
                . $db->quoteName('queue.do_not_translate') . ' = 0)'
            )
            // No state row means never translated; pending means the source has moved on since, or
            // an earlier attempt failed, and is waited on only while attempts are left.
            ->where(
                '(' . $db->quoteName('queueState.id') . ' IS NULL OR ('
                . $db->quoteName('queueState.translation_state') . ' = :pending AND '
                . $db->quoteName('queueState.attempts') . ' < :maxAttempts))'
            )
            ->bind(':sourceLanguage', $sourceLanguage, ParameterType::STRING)
            ->bind(':pending', $pending, ParameterType::STRING)
            ->bind(':maxAttempts', $maxAttempts, ParameterType::INTEGER);

        // The same narrowing the queue applies, so the batch never picks an item translate() refuses.
        if (isset($properties['limitToExtension'])) {
            $extension = (string) $properties['limitToExtension'];

            $query->where($db->quoteName('item.extension') . ' = :extension')
                ->bind(':extension', $extension, ParameterType::STRING);
        }

        if (isset($properties['limitToClient'])) {
            $clientId = (int) $properties['limitToClient'];

            $query->where($db->quoteName('item.client_id') . ' = :clientId')
                ->bind(':clientId', $clientId, ParameterType::INTEGER);
        }

        return $query;
    }

    /**
     * Load the source item's raw column values.
     *
     * The row is read directly rather than via the component's getItem(), whose
     * computed objects break a re-save.
     *
     * @param   integer  $sourceItemId  The source item id.
     * @param   string   $table         The content type's database table.
     *
     * @return  array  The item's column values.
     *
     * @throws  \RuntimeException  If the item does not exist.
     *
     * @since   0.3.0
     */
    private function getSourceItem(int $sourceItemId, string $table): array
    {
        $db    = $this->getDatabase();
        $query = $db->getQuery(true)
            ->select('*')
            ->from($db->quoteName($table))
            ->where($db->quoteName('id') . ' = :id')
            ->bind(':id', $sourceItemId, ParameterType::INTEGER);
        $db->setQuery($query);

        $sourceItem = $db->loadAssoc();

        if ($sourceItem === null) {
            throw new \RuntimeException(\sprintf('Source item %d not found.', $sourceItemId));
        }

        return $sourceItem;
    }

    /**
     * Check whether the source item is flagged as not to be translated.
     *
     * The flag lives on the item's queue row; an item without a queue row
     * is translatable.
     *
     * @param   integer  $sourceItemId  The source item id.
     * @param   string   $contentType   The content type key, e.g. 'com_content.article'.
     *
     * @return  boolean  True when the item must not be translated.
     *
     * @since   0.3.0
     */
    private function isDoNotTranslate(int $sourceItemId, string $contentType): bool
    {
        $db    = $this->getDatabase();
        $query = $db->getQuery(true)
            ->select($db->quoteName('do_not_translate'))
            ->from($db->quoteName('#__translations_queue'))
            ->where($db->quoteName('content_type') . ' = :contentType')
            ->where($db->quoteName('content_id') . ' = :contentId')
            ->bind(':contentType', $contentType, ParameterType::STRING)
            ->bind(':contentId', $sourceItemId, ParameterType::INTEGER);
        $db->setQuery($query);

        return (bool) $db->loadResult();
    }

    /**
     * Gather an item's translatable strings into one collection, keyed by field.
     *
     * All of an item's strings are handed over together so a provider keeps the context
     * between them. Empty fields are left out so nothing is translated needlessly. The
     * field list comes from the content type map: a plain name is a column, an array maps
     * a JSON column to its translatable sub-keys (gathered under a dotted path).
     *
     * @param   array  $sourceItem          The source item's column values.
     * @param   array  $translatableFields  The content type's translatable field list.
     *
     * @return  array  The translatable strings keyed by field name.
     *
     * @since   0.4.0
     */
    private function collectTranslatableStrings(array $sourceItem, array $translatableFields): array
    {
        $strings = [];

        foreach ($translatableFields as $field) {
            if (\is_string($field)) {
                $value = (string) ($sourceItem[$field] ?? '');

                if (trim($value) !== '') {
                    $strings[$field] = $value;
                }

                continue;
            }

            if (!\is_array($field)) {
                continue;
            }

            foreach ($field as $jsonColumn => $subKeys) {
                $registry = new Registry($sourceItem[$jsonColumn] ?? '');

                foreach ((array) $subKeys as $subKey) {
                    $subKey = (string) $subKey;
                    $value  = (string) $registry->get($subKey, '');

                    if (trim($value) !== '') {
                        $strings[$jsonColumn . '.' . $subKey] = $value;
                    }
                }
            }
        }

        return $strings;
    }

    /**
     * Gather an item's custom-field values, keyed by field name.
     *
     * Read with FieldsHelper directly (not the display-preparing onContentPrepare), so the raw
     * stored values are returned rather than their rendered HTML. Every non-empty field is
     * collected, each flagged whether its type (text, textarea, editor, note) is translatable, so
     * the caller can translate those and copy the rest unchanged. A content type with no
     * custom-field context returns nothing.
     *
     * @param   array  $sourceItem  The source item's column values.
     * @param   array  $properties  The content type's properties from the map.
     *
     * @return  array  Per field name, ['id' => field id, 'value' => raw value, 'translatable' => bool].
     *
     * @since   0.4.0
     */
    private function collectCustomFields(array $sourceItem, array $properties): array
    {
        $context = (string) ($properties['context_custom_fields'] ?? '');

        if ($context === '') {
            return [];
        }

        $customFields = [];

        foreach (FieldsHelper::getFields($context, $sourceItem) as $field) {
            $value = (string) $field->rawvalue;

            if (trim($value) === '') {
                continue;
            }

            $customFields[$field->name] = [
                'id'           => (int) $field->id,
                'value'        => $value,
                'translatable' => \in_array($field->type, TranslatableValuesHelper::TRANSLATABLE_FIELD_TYPES, true),
            ];
        }

        return $customFields;
    }

    /**
     * Give a translated category the source category's directly assigned custom fields.
     *
     * A category's custom fields are scoped to the category id, so a field assigned only to the source
     * category is not assigned to the new draft and so is not stored when the draft is saved. The draft is
     * assigned those same fields here and their values written. Global and ancestor-assigned fields already
     * reach the draft through the save, so they are skipped.
     *
     * @param   integer                                                          $sourceId           The source category id.
     * @param   integer                                                          $draftId            The translated draft category id.
     * @param   array<string, array{id: int, value: string, translatable: bool}> $customFields       The collected custom fields.
     * @param   array<string, string>                                            $draftCustomFields  The values to store, keyed by field name.
     * @param   CMSApplicationInterface                                          $application        The application, used to boot com_fields.
     *
     * @return  void
     *
     * @since   0.4.0
     */
    private function copyDirectCustomFields(int $sourceId, int $draftId, array $customFields, array $draftCustomFields, CMSApplicationInterface $application): void
    {
        $db    = $this->getDatabase();
        $query = $db->getQuery(true)
            ->select($db->quoteName('field_id'))
            ->from($db->quoteName('#__fields_categories'))
            ->where($db->quoteName('category_id') . ' = :sourceId')
            ->bind(':sourceId', $sourceId, ParameterType::INTEGER);
        $db->setQuery($query);

        $directFieldIds = array_map('intval', $db->loadColumn());

        if ($directFieldIds === []) {
            return;
        }

        /** @var ComponentInterface&MVCFactoryServiceInterface $component */
        $component = $application->bootComponent('com_fields');

        /** @var FieldModel $fieldModel */
        $fieldModel = $component->getMVCFactory()->createModel('Field', 'Administrator', ['ignore_request' => true]);

        foreach ($customFields as $name => $customField) {
            $fieldId = (int) $customField['id'];

            // Global and ancestor-assigned fields are already on the draft; only direct ones need copying.
            if (!\in_array($fieldId, $directFieldIds, true)) {
                continue;
            }

            // Assign the draft to the field, guarding the composite key against a re-translation.
            if (!$this->categoryHasField($fieldId, $draftId)) {
                $assignment = (object) ['field_id' => $fieldId, 'category_id' => $draftId];
                $db->insertObject('#__fields_categories', $assignment);
            }

            $fieldModel->setFieldValue((string) $fieldId, (string) $draftId, $draftCustomFields[$name]);
        }
    }

    /**
     * Whether a custom field is already assigned to a category.
     *
     * @param   integer  $fieldId     The field id.
     * @param   integer  $categoryId  The category id.
     *
     * @return  boolean
     *
     * @since   0.4.0
     */
    private function categoryHasField(int $fieldId, int $categoryId): bool
    {
        $db    = $this->getDatabase();
        $query = $db->getQuery(true)
            ->select('COUNT(*)')
            ->from($db->quoteName('#__fields_categories'))
            ->where($db->quoteName('field_id') . ' = :fieldId')
            ->where($db->quoteName('category_id') . ' = :categoryId')
            ->bind(':fieldId', $fieldId, ParameterType::INTEGER)
            ->bind(':categoryId', $categoryId, ParameterType::INTEGER);
        $db->setQuery($query);

        return (bool) $db->loadResult();
    }

    /**
     * Translate the collected strings by asking the "translation" plugin group.
     *
     * The whole collection is handed over as one onTranslate event so a provider keeps
     * the context between an item's strings. The first provider that returns translations
     * wins; with no provider enabled there is nothing to translate with, so this fails.
     *
     * @param   array   $strings         The source strings keyed by field.
     * @param   string  $sourceLanguage  The source language code.
     * @param   string  $targetLanguage  The target language code.
     *
     * @return  array  The translated strings, keyed as given.
     *
     * @throws  \RuntimeException  When no translation provider returns a translation.
     *
     * @since   0.4.0
     */
    private function translateStrings(array $strings, string $sourceLanguage, string $targetLanguage): array
    {
        return $this->translateChunk($strings, $sourceLanguage, $targetLanguage, '', [])['strings'];
    }

    /**
     * Translate one request's worth of an item's strings.
     *
     * The rules are looked up for the strings in the request rather than for the whole item, so a
     * request carries the rules that bear on the text it holds and no others.
     *
     * @param   array                  $strings          The source strings of this request, keyed by part key.
     * @param   string                 $sourceLanguage   The source language code.
     * @param   string                 $targetLanguage   The target language code.
     * @param   string                 $documentContext  What the item is about, empty when this is all of it.
     * @param   array<string, string>  $glossary         The terms the item's earlier parts were translated with.
     *
     * @return  array{strings: array, glossary: array<string, string>}  The translations, and the terms chosen.
     *
     * @throws  \RuntimeException  When no translation provider returns a translation.
     *
     * @since   1.4.0
     */
    private function translateChunk(
        array $strings,
        string $sourceLanguage,
        string $targetLanguage,
        string $documentContext,
        array $glossary
    ): array {
        $dispatcher = $this->getDispatcher();

        // Distilled rules relevant to the item, so a provider can steer the translation.
        $rules = RuleRetriever::retrieve(
            $this->getDatabase(),
            $dispatcher,
            $strings,
            $sourceLanguage,
            $targetLanguage
        );

        try {
            return StringTranslator::translateInDocument(
                $dispatcher,
                $strings,
                $sourceLanguage,
                $targetLanguage,
                $rules,
                $documentContext,
                $glossary
            );
        } finally {
            // A long answer can outlast the server's patience with an idle connection, and what
            // follows - storing the answer, or the failure - needs the database.
            DatabaseConnection::reconnectIfDropped($this->getDatabase());
        }
    }

    /**
     * Translate an item's strings, in as many requests as they need and the budget allows.
     *
     * An item that fits in one request is sent as one, exactly as before: there is nothing to
     * carry between requests and nothing to store between runs. An item that does not is cut
     * into parts, and each request is told what the item is about and which terms its earlier
     * parts were translated with, so a word is not translated one way in one part and another
     * way in the next. Every answered request is stored before the next is sent, so a run that
     * is cut off keeps what it has paid for.
     *
     * @param   array           $strings         The item's source strings keyed by field.
     * @param   string          $sourceLanguage  The source language code.
     * @param   string          $targetLanguage  The target language code.
     * @param   integer         $stateId         The id of the item's state row, where parts are stored.
     * @param   TimeBudget      $budget          How long this run may keep sending requests.
     * @param   RunResult|null  $result          The run's result, to count the parts translated in.
     *
     * @return  array|null  The translated strings keyed by field, or null when parts are still to be done.
     *
     * @throws  \RuntimeException  When a request fails, or a provider leaves part of the item untranslated.
     *
     * @since   1.4.0
     */
    private function translateInChunks(
        array $strings,
        string $sourceLanguage,
        string $targetLanguage,
        int $stateId,
        TimeBudget $budget,
        ?RunResult $result
    ): ?array {
        $chunks = TextChunker::plan($strings, $this->chunkTargetChars());

        // Nothing to translate: no request is made, so none is timed either.
        if ($chunks === []) {
            return [];
        }

        // One request holds the item whole, keyed by its own field names: nothing was cut, so there
        // is no context to carry and nothing to store between runs.
        if (\count($chunks) === 1) {
            $budget->startRequest();

            try {
                return $this->translateStrings($chunks[0], $sourceLanguage, $targetLanguage);
            } finally {
                $budget->endRequest();
            }
        }

        // The print is of the parts rather than of the strings: the same text cut in other places -
        // after a new Characters per Request, or a chunker that finds boundaries it did not before -
        // numbers its parts the same, and a part stored under one cut is no translation of the
        // part that has its key under the other.
        $fingerprint = TextChunker::fingerprint(array_merge(...array_values($chunks)));
        $stored = $this->loadPartial($stateId, $fingerprint);

        // What an earlier run stored was never checked against the strings it was asked for, and a
        // part that cannot belong to its source is reused on every attempt from then on: item 375
        // on guide.joomla.org held a body under its title and failed identically each time it was
        // tried. Dropping it here puts it back among the parts still to translate.
        $parts    = StringTranslator::ofTheirSources(array_merge(...array_values($chunks)), $stored['parts']);
        $glossary = $stored['glossary'];
        $context  = TextChunker::documentContext($strings);
        $sent     = false;

        foreach ($chunks as $chunk) {
            $missing = array_diff_key($chunk, $parts);

            // Translated by this run or an earlier one already.
            if ($missing === []) {
                continue;
            }

            // The first request always goes, so a run that was allowed to start the item gets
            // one part of it done however little time is left.
            if ($sent && !$budget->allowsAnother()) {
                return null;
            }

            $budget->startRequest();

            try {
                $answer = $this->translateChunk($missing, $sourceLanguage, $targetLanguage, $context, $glossary);
            } finally {
                $budget->endRequest();
            }

            $sent  = true;
            $fresh = array_diff_key($answer['strings'], $parts);
            $parts = array_merge($parts, $answer['strings']);

            // The terms of the earliest parts are kept when the glossary is full: those are the
            // ones the rest of the item has already been made to agree with.
            $glossary = \array_slice(array_merge($glossary, $answer['glossary']), 0, self::MAX_GLOSSARY_TERMS, true);

            // A request that answered nothing new is no progress, however well it went: counting
            // it would give an item that keeps coming back short an endless set of attempts.
            if ($fresh === []) {
                continue;
            }

            $this->storePartial($stateId, $fingerprint, $parts, $glossary);
            $this->resetAttempts($stateId);

            if ($result !== null) {
                $result->advanced++;
            }
        }

        foreach ($chunks as $chunk) {
            if (array_diff_key($chunk, $parts) !== []) {
                throw new \RuntimeException('The provider left part of the item untranslated.');
            }
        }

        return TextChunker::assemble($parts);
    }

    /**
     * The source characters one request aims to carry.
     *
     * @return  integer
     *
     * @since   1.4.0
     */
    private function chunkTargetChars(): int
    {
        return (int) ComponentHelper::getParams('com_translations')
            ->get('chunk_chars', TextChunker::DEFAULT_TARGET_CHARS);
    }

    /**
     * Read back the parts of an item an earlier run translated.
     *
     * A source edited since they were stored is cut into different parts, so what was stored no
     * longer lines up with what is being sent now and is dropped rather than joined onto it.
     *
     * @param   integer  $stateId      The id of the item's state row.
     * @param   string   $fingerprint  The print of the strings being translated now.
     *
     * @return  array{parts: array<string, string>, glossary: array<string, string>}
     *
     * @since   1.4.0
     */
    private function loadPartial(int $stateId, string $fingerprint): array
    {
        $empty = ['parts' => [], 'glossary' => []];
        $db    = $this->getDatabase();
        $query = $db->getQuery(true)
            ->select($db->quoteName('partial'))
            ->from($db->quoteName('#__translations_queue_states'))
            ->where($db->quoteName('id') . ' = :stateId')
            ->bind(':stateId', $stateId, ParameterType::INTEGER);
        $db->setQuery($query);

        $stored = json_decode((string) $db->loadResult(), true);

        if (!\is_array($stored) || ($stored['fingerprint'] ?? '') !== $fingerprint) {
            return $empty;
        }

        return [
            'parts'    => array_filter((array) ($stored['parts'] ?? []), '\is_string'),
            'glossary' => array_filter((array) ($stored['glossary'] ?? []), '\is_string'),
        ];
    }

    /**
     * Store the parts of an item translated so far, for a later run to carry on from.
     *
     * @param   integer                $stateId      The id of the item's state row.
     * @param   string                 $fingerprint  The print of the strings being translated.
     * @param   array<string, string>  $parts        The translated parts, keyed by part key.
     * @param   array<string, string>  $glossary     The terms the parts were translated with.
     *
     * @return  void
     *
     * @since   1.4.0
     */
    private function storePartial(int $stateId, string $fingerprint, array $parts, array $glossary): void
    {
        $db      = $this->getDatabase();
        $partial = (string) json_encode([
            'fingerprint' => $fingerprint,
            'parts'       => $parts,
            'glossary'    => $glossary,
        ]);
        $query   = $db->getQuery(true)
            ->update($db->quoteName('#__translations_queue_states'))
            ->set($db->quoteName('partial') . ' = :partial')
            ->where($db->quoteName('id') . ' = :stateId')
            ->bind(':partial', $partial, ParameterType::STRING)
            ->bind(':stateId', $stateId, ParameterType::INTEGER);
        $db->setQuery($query)->execute();
    }

    /**
     * Drop the stored parts of an item, once its draft holds the translation.
     *
     * @param   integer  $stateId  The id of the item's state row.
     *
     * @return  void
     *
     * @since   1.4.0
     */
    private function clearPartial(int $stateId): void
    {
        $db    = $this->getDatabase();
        $query = $db->getQuery(true)
            ->update($db->quoteName('#__translations_queue_states'))
            ->set($db->quoteName('partial') . ' = NULL')
            ->where($db->quoteName('id') . ' = :stateId')
            ->bind(':stateId', $stateId, ParameterType::INTEGER);
        $db->setQuery($query)->execute();
    }

    /**
     * Give an item its attempts back, because this run got part of it translated.
     *
     * An item too long for one request takes more runs than an item has attempts, so without
     * this a long article would be set aside as failed while it was being translated normally.
     * Only a part that was answered and stored resets them, so an item whose next part keeps
     * failing still runs out of attempts and is set aside.
     *
     * @param   integer  $stateId  The id of the item's state row.
     *
     * @return  void
     *
     * @since   1.4.0
     */
    private function resetAttempts(int $stateId): void
    {
        $lastError = '';
        $db        = $this->getDatabase();
        $query     = $db->getQuery(true)
            ->update($db->quoteName('#__translations_queue_states'))
            ->set($db->quoteName('attempts') . ' = 0')
            ->set($db->quoteName('last_error') . ' = :lastError')
            ->where($db->quoteName('id') . ' = :stateId')
            ->bind(':lastError', $lastError, ParameterType::STRING)
            ->bind(':stateId', $stateId, ParameterType::INTEGER);
        $db->setQuery($query)->execute();
    }

    /**
     * Map translated strings back to their fields for the draft.
     *
     * Mirrors collectTranslatableStrings: a plain field name becomes a column value (falling
     * back to the source when nothing was translated), a JSON column is rebuilt from the
     * source with its translated sub-keys overlaid so untranslated keys (like the image path) survive.
     *
     * @param   array  $sourceItem          The source item's column values.
     * @param   array  $translatableFields  The content type's translatable field list.
     * @param   array  $translated          The translated strings, keyed as collected.
     *
     * @return  array  The draft field values keyed by column.
     *
     * @since   0.4.0
     */
    private function packTranslatedFields(array $sourceItem, array $translatableFields, array $translated): array
    {
        $fields = [];

        foreach ($translatableFields as $field) {
            if (\is_string($field)) {
                $fields[$field] = $translated[$field] ?? (string) ($sourceItem[$field] ?? '');

                continue;
            }

            if (!\is_array($field)) {
                continue;
            }

            foreach ($field as $jsonColumn => $subKeys) {
                $registry = new Registry($sourceItem[$jsonColumn] ?? '');

                foreach ((array) $subKeys as $subKey) {
                    $subKey = (string) $subKey;

                    if (isset($translated[$jsonColumn . '.' . $subKey])) {
                        $registry->set($subKey, $translated[$jsonColumn . '.' . $subKey]);
                    }
                }

                $fields[$jsonColumn] = $registry->toArray();
            }
        }

        return $fields;
    }

    /**
     * Create the draft for one target language.
     *
     * The draft is saved through the managing component's model so versioning, events and
     * associations run as for a hand created item. A model with an associationsContext
     * writes the #__associations link itself; tags, whose model does not, get it written here.
     *
     * @param   array                    $sourceItem      The source item's column values.
     * @param   array                    $translated      The item's translated strings, keyed by field.
     * @param   array                    $customFields    The item's collected custom fields, keyed by name.
     * @param   string                   $targetLanguage  The target language code.
     * @param   string                   $contentType     The content type key, e.g. 'com_content.article'.
     * @param   CMSApplicationInterface  $application     The application, used to boot the component.
     * @param   array                    $properties      The content type's properties from the map.
     *
     * @return  array  The draft's translatable values as stored, keyed by field.
     *
     * @throws  \RuntimeException  If the draft cannot be saved.
     *
     * @since   0.3.0
     */
    private function createDraft(
        array $sourceItem,
        array $translated,
        array $customFields,
        string $targetLanguage,
        string $contentType,
        CMSApplicationInterface $application,
        array $properties
    ): array {
        // Save through the managing component's model so versioning and the content events run.
        /** @var ComponentInterface&MVCFactoryServiceInterface $component */
        $component = $application->bootComponent((string) ($properties['component'] ?? ''));

        // Ignore the request because the model gets its data from us.
        /** @var AdminModel $model */
        $model = $component->getMVCFactory()->createModel((string) ($properties['model'] ?? ''), 'Administrator', ['ignore_request' => true]);

        // Ignoring the request skips populateState, so set the state the model still derives from it;
        // a category reads its extension from state to confirm it can be associated.
        foreach ((array) ($properties['modelState'] ?? []) as $stateKey => $stateValue) {
            $model->setState($stateKey, $stateValue);
        }

        $translatableFields = (array) ($properties['translatableFields'] ?? []);
        $fields             = $this->packTranslatedFields($sourceItem, $translatableFields, $translated);

        // The internal links in the text open the source language's pages; point them at this language's.
        $links = new LinkTranslator(new DatabaseLinkLookup($this->getDatabase()));

        foreach ($fields as $field => $value) {
            if (\is_string($value)) {
                $fields[$field] = $links->translateHtml($value, $targetLanguage);
            }
        }

        $draft = array_merge($fields, [
            'id'       => 0,
            'language' => $targetLanguage,
        ]);

        // Carry every custom field onto the draft (the fields plugin's onContentAfterSave stores them, keyed by
        // name): the translatable ones translated, the rest copied unchanged so a non-translated field is not lost.
        $draftCustomFields = [];

        foreach ($customFields as $name => $customField) {
            $draftCustomFields[$name] = $customField['translatable']
                ? $links->translateHtml(
                    (string) ($translated[TranslatableValuesHelper::CUSTOM_FIELD_PREFIX . $name] ?? $customField['value']),
                    $targetLanguage
                )
                : $customField['value'];
        }

        if ($draftCustomFields !== []) {
            $draft['com_fields'] = $draftCustomFields;
        }

        // Join the draft to the source's existing association group rather than a fresh one.
        $context                 = (string) ($properties['context_associations'] ?? '');
        $modelWritesAssociations = (bool) ($properties['associationsByModel'] ?? true);
        $associations            = [];

        if ($context !== '') {
            $associations = $this->getAssociationGroup((int) $sourceItem['id'], $context, (string) ($properties['table'] ?? ''));
            $associations[$sourceItem['language']] = (int) $sourceItem['id'];

            // Update the language's existing translation in place rather than inserting a duplicate.
            if (isset($associations[$targetLanguage])) {
                $draft['id'] = (int) $associations[$targetLanguage];
            }

            // A model with an associationsContext writes the link itself on save.
            if ($modelWritesAssociations) {
                $draft['associations'] = $associations;
            }
        }

        // Carry the source's untranslated structural fields onto the draft unchanged.
        foreach ((array) ($properties['draftCopyFields'] ?? []) as $field) {
            $draft[$field] = $sourceItem[$field] ?? null;
        }

        // Repoint single foreign keys (such as catid) at the related item's translation. Where none exists yet the
        // source's value is kept, or the field's fallback taken where the source's cannot hold in the target language:
        // a menu item's parent lives in the source language menu, so the translation waits at the root instead.
        $fallbacks = (array) ($properties['associatedFieldsFallback'] ?? []);

        foreach ((array) ($properties['associatedFields'] ?? []) as $field => $relatedType) {
            $relatedItemId = (int) ($sourceItem[$field] ?? 0);

            if ($relatedItemId === 0) {
                continue;
            }

            $translatedId = $this->translatedRelatedId($relatedItemId, (string) $relatedType, $targetLanguage);

            if ($translatedId !== null) {
                $draft[$field] = $translatedId;
            } elseif (\array_key_exists($field, $fallbacks)) {
                $draft[$field] = $fallbacks[$field];
            }
        }

        // Repoint many-to-many relations (tags) at the related items' translations, keeping the source's where none exists yet.
        // Tags are keyed in the map by a type alias that can differ from our key (a category's tags use com_content.category).
        $tagTypeAlias = (string) ($properties['context_tags'] ?? $contentType);

        foreach ((array) ($properties['m2m_relation'] ?? []) as $dataKey => $relatedType) {
            $translatedIds = [];

            foreach ($this->getSourceTagIds($tagTypeAlias, (int) $sourceItem['id']) as $relatedItemId) {
                $translatedIds[] = $this->translatedRelatedId($relatedItemId, (string) $relatedType, $targetLanguage) ?? $relatedItemId;
            }

            $draft[$dataKey] = $translatedIds;
        }

        // Repoint the target inside a link (a menu item's page) at its translation, keeping the source's when none exists yet.
        // A link names its target in the query rather than in a column, so associatedFields cannot reach it.
        $linkTargets = (array) ($properties['linkTargets'] ?? []);

        if ($linkTargets !== []) {
            $draft['link'] = $this->repointLinkTargets((string) ($sourceItem['link'] ?? ''), $linkTargets, $targetLanguage);
        }

        // The same for the items named in its params, such as the menu item an alias stands for.
        $paramTargets = (array) ($properties['paramTargets'] ?? []);

        if ($paramTargets !== []) {
            $draft['params'] = $this->repointParamTargets((string) ($sourceItem['params'] ?? ''), $paramTargets, $targetLanguage);
        }

        // A new draft is held back for a translator to approve, unless the option publishes it on creation,
        // and even then it waits while its source is off the site (see SourceState). An existing
        // translation keeps the state it has, so re-translating never takes a live item off the site.
        $newState = null;

        if ($draft['id'] === 0) {
            $stateField = (string) ($properties['stateField'] ?? '');
            $newState   = SourceState::forNewTranslation(
                (int) ($sourceItem[$stateField] ?? 0),
                (bool) ComponentHelper::getParams('com_translations')->get('auto_publish', 0)
            );

            $draft[$stateField] = $newState['state'];
        }

        // Force any fields the draft must hold at a fixed value, e.g. a translated menu item is never the home item.
        foreach ((array) ($properties['draftForceFields'] ?? []) as $field => $value) {
            $draft[$field] = $value;
        }

        // A content type kept in language specific containers (a menu item lives in a menu) gets the target
        // language container, created when it does not exist yet.
        if (isset($properties['languageMenu'])) {
            $menuField         = (string) $properties['languageMenu'];
            $draft[$menuField] = $this->deriveLanguageMenu(
                $component,
                (string) ($sourceItem[$menuField] ?? ''),
                (string) $sourceItem['language'],
                $targetLanguage
            );
        }

        // com_content combines intro and full text into one body field; only relevant when those fields exist.
        if (isset($fields['introtext']) || isset($fields['fulltext'])) {
            $introtext = (string) ($fields['introtext'] ?? '');
            $fulltext  = (string) ($fields['fulltext'] ?? '');

            $draft['articletext'] = trim($fulltext) !== ''
                ? $introtext . '<hr id="system-readmore">' . $fulltext
                : $introtext;
        }

        // The draft's own title gives it its alias, and where a neighbour already holds that one the
        // language is appended. The component checks this but does not solve it: it refuses the save,
        // and that refusal costs a translation that has already been paid for. The alias is picked
        // last because the neighbours are the ones around where the draft ends up, which the fields
        // repointed above decide. A title that leaves no alias behind, as Persian does where Joomla
        // cannot transliterate it, falls back to the source's rather than to Joomla's timestamp.
        $slug        = ApplicationHelper::stringURLSafe((string) ($fields['title'] ?? ''), $targetLanguage);
        $base        = $slug !== '' ? $slug : (string) $sourceItem['alias'];
        $neighbours  = self::aliasScopeValues($draft, $sourceItem, $properties);
        $draft['alias'] = DraftAlias::pick(
            $base,
            $targetLanguage,
            array_merge(
                $this->neighbouringAliases($base, (string) ($properties['table'] ?? ''), $neighbours, (int) $draft['id']),
                self::reservedAliases($draft, $properties)
            )
        );

        if (!$model->save($draft)) {
            // Joomla decides whether an alias is free, and says so only by refusing the save. Where
            // it refuses one the neighbours read above left free, it is reading a neighbourhood we
            // are not, so the draft steps aside to an alias carrying the id of the item it was
            // translated from - which no other item's draft can reach - and asks a second time.
            // Losing a translation that has been paid for over a name is the worse outcome, and a
            // refusal for any other reason simply repeats itself, which the message then says.
            $refused        = $draft['alias'];
            $refusal        = self::saveRefusal($model);
            $draft['alias'] = DraftAlias::stepAside($base, $targetLanguage, (int) $sourceItem['id']);

            if (!$model->save($draft)) {
                throw new \RuntimeException(
                    \sprintf(
                        'Could not create the %s draft for item %d%s: %s (as "%s"), and %s (as "%s")',
                        $targetLanguage,
                        (int) $sourceItem['id'],
                        self::neighbourhoodNote($neighbours),
                        $refusal,
                        self::shortEnoughToRead($refused),
                        self::saveRefusal($model),
                        self::shortEnoughToRead((string) $draft['alias'])
                    )
                );
            }
        }

        $draftId = (int) $model->getState($model->getName() . '.id');

        if ($newState !== null) {
            $this->setHeldBySource((int) $sourceItem['id'], $contentType, $targetLanguage, $newState['held']);
        }

        // A model without an associationsContext (tags) leaves the link unwritten, so write it here.
        if ($context !== '' && !$modelWritesAssociations) {
            $associations[$targetLanguage] = $draftId;
            $this->writeAssociations($associations, $context);
        }

        // Translations made before this one could only point at its source, and wait for it: a menu
        // item linking to this article, a text linking to its page, or a menu item whose parent this
        // is. Now they can point here.
        $this->repointEarlierLinks($contentType, (int) $sourceItem['id'], $targetLanguage);
        $this->repointEarlierParams($contentType, (int) $sourceItem['id'], $targetLanguage);
        (new TranslatedTexts($this->getDatabase()))->repointLinksTo($contentType, (int) $sourceItem['id'], $targetLanguage);
        $this->nestEarlierChildren($model, (int) $sourceItem['id'], $draftId, $targetLanguage, $contentType, $properties);

        // A new translation takes its source's place among its siblings; one translated before keeps
        // the place it has, which someone may have chosen by hand.
        if ($newState !== null) {
            $this->placeLikeSource($model, (int) $sourceItem['id'], $draftId, $targetLanguage, $contentType, $properties);
        }

        // A category's custom fields are scoped to its own id, so a field assigned only to the source
        // category does not reach the new draft on save; copy those assignments and store their values.
        if (!empty($properties['copyCustomFieldAssignments']) && $customFields !== []) {
            $this->copyDirectCustomFields(
                (int) $sourceItem['id'],
                $draftId,
                $customFields,
                $draftCustomFields,
                $application
            );
        }

        // Read the draft back rather than reusing what was sent: the managing component can alter
        // values while saving, and the translator's correction is compared against what is stored.
        $draftRow     = $this->getSourceItem($draftId, (string) ($properties['table'] ?? ''));
        $machineDraft = TranslatableValuesHelper::flattenFields($draftRow, $translatableFields);

        foreach (TranslatableValuesHelper::collectCustomFields($draftRow, $properties) as $name => $customField) {
            $machineDraft[TranslatableValuesHelper::CUSTOM_FIELD_PREFIX . $name] = $customField['value'];
        }

        return $machineDraft;
    }

    /**
     * Read the aliases already held where the draft is about to be saved.
     *
     * What counts as a neighbour differs per content type, and the map names the columns that
     * decide it: an article shares its alias space with the articles in its category, a category
     * or a tag with the children of its parent. Only the aliases that could stand in the draft's
     * way are read, which are the ones its own alias is built from, and the row the draft updates
     * is left out so that a re-translation keeps the alias it already has. A content type whose
     * entry names no columns gets no aliases back and keeps the one its title gives it.
     *
     * @param   string                $base         The alias the draft's title gives it, which its neighbours are read around.
     * @param   string                $table        The content type's database table.
     * @param   array<string, mixed>  $scopeValues  The values that decide who the neighbours are, keyed by column.
     * @param   int                   $draftId      The id of the row the draft updates, or 0 for a new one.
     *
     * @return  string[]  The aliases the draft's neighbours hold.
     *
     * @since   1.4.2
     */
    private function neighbouringAliases(string $base, string $table, array $scopeValues, int $draftId): array
    {
        if ($scopeValues === [] || $table === '' || $base === '') {
            return [];
        }

        $db    = $this->getDatabase();
        $query = $db->getQuery(true)
            ->select($db->quoteName('alias'))
            ->from($db->quoteName($table))
            ->where($db->quoteName('alias') . ' LIKE ' . $db->quote($db->escape($base, true) . '%', false));

        // bind() keeps a reference to what it is given, so each value is bound where it is held.
        foreach ($scopeValues as $column => $value) {
            // A type that scopes by language is one Joomla scopes by language, and it reads an
            // item applying to all languages as a neighbour of every one of them, as this does.
            if ($column === 'language') {
                $query->where(
                    '(' . $db->quoteName('language') . ' = :language'
                    . ' OR ' . $db->quoteName('language') . ' = ' . $db->quote('*') . ')'
                )->bind(':language', $scopeValues['language'], ParameterType::STRING);

                continue;
            }

            $query->where($db->quoteName($column) . ' = :' . $column)
                ->bind(
                    ':' . $column,
                    $scopeValues[$column],
                    is_numeric($value) ? ParameterType::INTEGER : ParameterType::STRING
                );
        }

        if ($draftId > 0) {
            $query->where($db->quoteName('id') . ' != :draftId')
                ->bind(':draftId', $draftId, ParameterType::INTEGER);
        }

        $db->setQuery($query);

        return array_map('strval', (array) $db->loadColumn());
    }

    /**
     * The values that decide who a draft's neighbours are.
     *
     * The map names the columns per content type; a draft carries most of them itself, and what it
     * does not carry it shares with its source. A column neither of them holds is left out, which
     * widens the neighbourhood rather than narrowing it: reading too many aliases only makes the
     * one that is picked longer, while reading too few is what lets a save be refused.
     *
     * @param   array<string, mixed>  $draft       The draft's column values so far.
     * @param   array<string, mixed>  $sourceItem  The source item's column values.
     * @param   array<string, mixed>  $properties  The content type's properties from the map.
     *
     * @return  array<string, mixed>  The values, keyed by column.
     *
     * @since   1.4.3
     */
    private static function aliasScopeValues(array $draft, array $sourceItem, array $properties): array
    {
        $values = [];

        foreach ((array) ($properties['aliasScope'] ?? []) as $column) {
            $column = (string) $column;

            if (isset($draft[$column])) {
                $values[$column] = $draft[$column];
            } elseif (isset($sourceItem[$column])) {
                $values[$column] = $sourceItem[$column];
            }
        }

        return $values;
    }

    /**
     * The aliases a draft may not take for a reason other than a neighbour holding one.
     *
     * A menu item at the root of a site menu answers a URL directly under the site, so Joomla
     * refuses it the name of any folder in the site root, and 'component', which it routes with.
     * Every translated menu item is placed at the root, and the words are ordinary ones - a menu
     * item translated as "Media", "Plugins" or "Modules" lands on one - so without this the save
     * is refused and the draft falls back to an alias carrying the item's id. Counting them as
     * taken instead gets "media-pt-pt", which reads like the rest.
     *
     * @param   array<string, mixed>  $draft       The draft's column values so far.
     * @param   array<string, mixed>  $properties  The content type's properties from the map.
     *
     * @return  string[]  The aliases to treat as held.
     *
     * @since   1.4.5
     */
    private static function reservedAliases(array $draft, array $properties): array
    {
        // Only at the root of a site menu: deeper items sit under a path of their own, where the
        // site's folders are no longer what the first segment of the URL could collide with.
        if (empty($properties['aliasReservedAtRoot'])
            || (int) ($draft['parent_id'] ?? 0) !== 1
            || (int) ($draft['client_id'] ?? 0) !== 0) {
            return [];
        }

        return array_merge(['component'], Folder::folders(JPATH_ROOT));
    }

    /**
     * Cut a value down to what a message can carry without losing what follows it.
     *
     * The queue's last_error and the task log hold 500 characters, and the reason a save was
     * refused is the part worth having. An alias built out of a title that held a body filled all
     * 500 on its own, so five failure mails from the guide named the alias and never said why.
     *
     * @param   string  $value  The value to put in a message.
     *
     * @return  string  The value, shortened with an ellipsis where it was cut.
     *
     * @since   1.4.4
     */
    private static function shortEnoughToRead(string $value): string
    {
        return mb_strlen($value) <= 60 ? $value : mb_substr($value, 0, 60) . '…';
    }

    /**
     * Where a draft was being saved, for a message about why it could not be.
     *
     * A refused alias says little without the neighbourhood it was refused in: the same alias is
     * free in one category and taken in the next, and which category a draft lands in depends on
     * whether the source's category has been translated yet.
     *
     * @param   array<string, mixed>  $scopeValues  The values that decide who the neighbours are, keyed by column.
     *
     * @return  string  The neighbourhood in brackets, or nothing when the type has no alias scope.
     *
     * @since   1.4.3
     */
    private static function neighbourhoodNote(array $scopeValues): string
    {
        $named = [];

        foreach ($scopeValues as $column => $value) {
            $named[] = $column . ' ' . $value;
        }

        return $named === [] ? '' : ' (' . implode(', ', $named) . ')';
    }

    /**
     * Why the managing component refused to save a draft.
     *
     * Without this the queue and the task log say only that the draft could not be created, and an
     * item is set aside after three attempts with nothing to act on: a duplicate alias, a missing
     * category and a failed check all read the same. The reason matters most for the translations
     * that fail for a reason the translator can fix.
     *
     * AdminModel::save() reports every failure by calling setError() and returning false, and that
     * store is readable only through getError(), which Joomla deprecated in 3.1.4 and removes in 7.
     * Its deprecation says to catch thrown exceptions instead, which cannot be done here: save()
     * catches the exception itself and turns it into an error before returning false. So the call is
     * deliberate, phpstan.neon allows this one line by name rather than silencing it at the call
     * site, and it has to be revisited when this package follows Joomla 7.
     *
     * @param   AdminModel  $model  The managing component's model, after a save that returned false.
     *
     * @return  string  What the component said, or a note that it said nothing.
     *
     * @since   1.4.1
     */
    private static function saveRefusal(AdminModel $model): string
    {
        // A model is free to refuse without saying why, so this still has to read as a sentence.
        $refusal = trim($model->getError());

        return $refusal === '' ? 'the component gave no reason' : $refusal;
    }

    /**
     * Find a related item's translation in the target language.
     *
     * Looks up the related item's association group, the same key mechanism the item itself uses,
     * and returns its target language member, or null when the related item has no translation yet.
     *
     * @param   integer  $relatedItemId   The source related item id, e.g. a category id.
     * @param   string   $relatedType     The related content type key, e.g. 'com_categories.category'.
     * @param   string   $targetLanguage  The target language code.
     *
     * @return  integer|null  The translated related item id, or null when it has none.
     *
     * @since   0.4.0
     */
    private function translatedRelatedId(int $relatedItemId, string $relatedType, string $targetLanguage): ?int
    {
        $properties = ContentTypesHelper::getProperties($relatedType);
        $group      = $this->getAssociationGroup(
            $relatedItemId,
            (string) ($properties['context_associations'] ?? ''),
            (string) ($properties['table'] ?? '')
        );

        return isset($group[$targetLanguage]) ? (int) $group[$targetLanguage] : null;
    }

    /**
     * Repoint a link at the translations of the items it points at.
     *
     * The ids are read out of the link and written back rather than repointed as foreign keys. A
     * category listing carries a tag filter beside the category, and a tag parameter carries several
     * ids. An id whose item has no translation yet keeps pointing at the source, as a foreign key does.
     *
     * @param   string                                $link            The source item's link.
     * @param   array<string, array<string, string>>  $linkTargets     The related content type per query parameter, keyed by the link's option and view.
     * @param   string                                $targetLanguage  The target language code.
     *
     * @return  string  The link, each id repointed where a translation exists.
     *
     * @since   1.0.0
     */
    private function repointLinkTargets(string $link, array $linkTargets, string $targetLanguage): string
    {
        $arguments = [];
        parse_str((string) parse_url(htmlspecialchars_decode($link), PHP_URL_QUERY), $arguments);

        $parameters = (array) ($linkTargets[($arguments['option'] ?? '') . '.' . ($arguments['view'] ?? '')] ?? []);

        // A view the map does not name, or one whose id parameters are all unset, has nothing to repoint.
        $carried = array_intersect_key($parameters, $arguments);

        if ($carried === []) {
            return $link;
        }

        foreach ($carried as $parameter => $relatedType) {
            $value     = $arguments[$parameter];
            $isList    = \is_array($value);
            $repointed = [];

            foreach ($isList ? $value : [$value] as $key => $id) {
                // Zero is the root of a listing rather than an item, so it is never repointed.
                $repointed[$key] = (int) $id === 0
                    ? $id
                    : ($this->translatedRelatedId((int) $id, (string) $relatedType, $targetLanguage) ?? $id);
            }

            $arguments[$parameter] = $isList ? $repointed : $repointed[0];
        }

        // Rebuild it as com_menus does: the separator is named rather than taken from
        // arg_separator.output, which a site can set to an entity, and the ids keep their brackets.
        return 'index.php?' . urldecode(http_build_query($arguments, '', '&'));
    }

    /**
     * Repoint the items named in an item's params at their translations.
     *
     * A menu item names more than its page: an alias names the menu item it stands for, a login
     * form the menu items it redirects to, the featured articles view the categories it shows. A
     * param can carry one id or a list of them. An id whose item has no translation yet keeps
     * pointing at the source, as a foreign key does.
     *
     * @param   string                 $params          The source item's params, as JSON.
     * @param   array<string, string>  $paramTargets    The related content type per param name.
     * @param   string                 $targetLanguage  The target language code.
     *
     * @return  string  The params, each id repointed where a translation exists; unchanged when none is.
     *
     * @since   2.0.0
     */
    private function repointParamTargets(string $params, array $paramTargets, string $targetLanguage): string
    {
        $values = json_decode($params, true);

        if (!\is_array($values)) {
            return $params;
        }

        $repointed = $values;

        foreach (array_intersect_key($paramTargets, $values) as $name => $relatedType) {
            $value  = $values[$name];
            $isList = \is_array($value);
            $ids    = [];

            foreach ($isList ? $value : [$value] as $key => $id) {
                $translatedId = is_numeric($id) && (int) $id > 0
                    ? $this->translatedRelatedId((int) $id, (string) $relatedType, $targetLanguage)
                    : null;

                // Joomla stores most of these ids as strings, so a repointed one keeps the type it had.
                $ids[$key] = $translatedId === null ? $id : (\is_string($id) ? (string) $translatedId : $translatedId);
            }

            $repointed[$name] = $isList ? $ids : $ids[0];
        }

        return $repointed === $values ? $params : (new Registry($repointed))->toString();
    }

    /**
     * Repoint the params of earlier translations at an item that has just been translated.
     *
     * An alias menu item translated before the menu item it stands for keeps standing for the
     * source, as repointParamTargets() leaves an id without a translation. Once that has a
     * translation, the target language's translations still naming the source in their params are
     * pointed at it. A menu item keeps no versions, so its params are written directly.
     *
     * @param   string   $contentType     The content type just translated, e.g. 'com_menus.item'.
     * @param   integer  $sourceItemId    The id of the source item just translated.
     * @param   string   $targetLanguage  The language it was translated into.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function repointEarlierParams(string $contentType, int $sourceItemId, string $targetLanguage): void
    {
        foreach (ContentTypesHelper::getContentTypes() as $namingType) {
            $naming       = ContentTypesHelper::getProperties($namingType);
            $paramTargets = (array) ($naming['paramTargets'] ?? []);
            $names        = array_keys($paramTargets, $contentType, true);

            if ($names === []) {
                continue;
            }

            // Only translations are repointed: an item in an association group of the target language.
            $db      = $this->getDatabase();
            $table   = (string) ($naming['table'] ?? '');
            $context = (string) ($naming['context_associations'] ?? '');
            $query   = $db->getQuery(true)
                ->select($db->quoteName(['item.id', 'item.params']))
                ->from($db->quoteName($table, 'item'))
                ->join(
                    'INNER',
                    $db->quoteName('#__associations', 'association'),
                    $db->quoteName('association.id') . ' = ' . $db->quoteName('item.id')
                    . ' AND ' . $db->quoteName('association.context') . ' = :context'
                )
                ->where($db->quoteName('item.language') . ' = :language')
                ->bind(':context', $context, ParameterType::STRING)
                ->bind(':language', $targetLanguage, ParameterType::STRING);
            $db->setQuery($query);

            foreach ($db->loadObjectList() as $item) {
                $values  = json_decode((string) $item->params, true);
                $namesIt = false;

                foreach ($names as $name) {
                    $ids     = \is_array($values) ? array_map('intval', (array) ($values[$name] ?? [])) : [];
                    $namesIt = $namesIt || \in_array($sourceItemId, $ids, true);
                }

                if (!$namesIt) {
                    continue;
                }

                $params = $this->repointParamTargets((string) $item->params, $paramTargets, $targetLanguage);

                if ($params === (string) $item->params) {
                    continue;
                }

                $itemId = (int) $item->id;
                $update = $db->getQuery(true)
                    ->update($db->quoteName($table))
                    ->set($db->quoteName('params') . ' = :params')
                    ->where($db->quoteName('id') . ' = :id')
                    ->bind(':params', $params, ParameterType::STRING)
                    ->bind(':id', $itemId, ParameterType::INTEGER);
                $db->setQuery($update)->execute();
            }
        }
    }

    /**
     * Repoint the links of earlier translations at an item that has just been translated.
     *
     * A menu item translated before the article it opens keeps pointing at the source article, as
     * repointLinkTargets() leaves an id without a translation. Content is translated in its own time
     * per language, so the menu item regularly comes first; once the article's translation exists,
     * the target language's translated menu items still pointing at the source are pointed at it.
     * A menu item keeps no versions and its link is all that changes, so the link is written directly.
     *
     * @param   string   $contentType     The content type just translated, e.g. 'com_content.article'.
     * @param   integer  $sourceItemId    The id of the source item just translated.
     * @param   string   $targetLanguage  The language it was translated into.
     *
     * @return  void
     *
     * @since   1.4.7
     */
    private function repointEarlierLinks(string $contentType, int $sourceItemId, string $targetLanguage): void
    {
        foreach (ContentTypesHelper::getContentTypes() as $linkingType) {
            $linking     = ContentTypesHelper::getProperties($linkingType);
            $linkTargets = (array) ($linking['linkTargets'] ?? []);

            // The query parameters, per view, that can carry an id of the type just translated.
            $parametersByView = [];

            foreach ($linkTargets as $view => $parameters) {
                $carrying = array_keys(array_intersect((array) $parameters, [$contentType]));

                if ($carrying !== []) {
                    $parametersByView[$view] = $carrying;
                }
            }

            if ($parametersByView === []) {
                continue;
            }

            // Only translations are repointed: an item in an association group of the target language.
            $db      = $this->getDatabase();
            $context = (string) ($linking['context_associations'] ?? '');
            $query   = $db->getQuery(true)
                ->select($db->quoteName(['item.id', 'item.link']))
                ->from($db->quoteName((string) ($linking['table'] ?? ''), 'item'))
                ->join(
                    'INNER',
                    $db->quoteName('#__associations', 'association'),
                    $db->quoteName('association.id') . ' = ' . $db->quoteName('item.id')
                    . ' AND ' . $db->quoteName('association.context') . ' = :context'
                )
                ->where($db->quoteName('item.language') . ' = :language')
                ->bind(':context', $context, ParameterType::STRING)
                ->bind(':language', $targetLanguage, ParameterType::STRING);
            $db->setQuery($query);

            foreach ($db->loadObjectList() as $item) {
                $arguments = [];
                parse_str((string) parse_url(htmlspecialchars_decode((string) $item->link), PHP_URL_QUERY), $arguments);

                $view     = ($arguments['option'] ?? '') . '.' . ($arguments['view'] ?? '');
                $pointsAt = false;

                foreach ($parametersByView[$view] ?? [] as $parameter) {
                    $ids      = array_map('intval', (array) ($arguments[$parameter] ?? []));
                    $pointsAt = $pointsAt || \in_array($sourceItemId, $ids, true);
                }

                if (!$pointsAt) {
                    continue;
                }

                $link = $this->repointLinkTargets((string) $item->link, $linkTargets, $targetLanguage);

                if ($link === (string) $item->link) {
                    continue;
                }

                $itemId = (int) $item->id;
                $update = $db->getQuery(true)
                    ->update($db->quoteName((string) ($linking['table'] ?? '')))
                    ->set($db->quoteName('link') . ' = :link')
                    ->where($db->quoteName('id') . ' = :id')
                    ->bind(':link', $link, ParameterType::STRING)
                    ->bind(':id', $itemId, ParameterType::INTEGER);
                $db->setQuery($update)->execute();
            }
        }
    }

    /**
     * Move earlier translations of an item's children under its translation, just made.
     *
     * A menu item translated before its parent waits at the root of its menu, the fallback its
     * parent field takes while the parent has no translation. Once the parent is translated, the
     * children's translations still waiting there are moved under it, with an alias that is free
     * among their new neighbours. A translation someone has moved elsewhere by hand stays where it is,
     * and one that cannot be moved stays at the root, as it was.
     *
     * @param   AdminModel            $model           The managing component's model, for its table.
     * @param   integer               $sourceItemId    The id of the source item just translated.
     * @param   integer               $draftId         The id of its translation.
     * @param   string                $targetLanguage  The language it was translated into.
     * @param   string                $contentType     The content type key, e.g. 'com_menus.item'.
     * @param   array<string, mixed>  $properties      The content type's properties from the map.
     *
     * @return  void
     *
     * @since   1.4.7
     */
    private function nestEarlierChildren(
        AdminModel $model,
        int $sourceItemId,
        int $draftId,
        string $targetLanguage,
        string $contentType,
        array $properties
    ): void {
        $fallbacks = (array) ($properties['associatedFieldsFallback'] ?? []);
        $table     = (string) ($properties['table'] ?? '');

        foreach ((array) ($properties['associatedFields'] ?? []) as $field => $relatedType) {
            // Only a field pointing at the type's own items nests them, and only one with a fallback
            // has translations waiting at it.
            if ($relatedType !== $contentType || !\array_key_exists($field, $fallbacks)) {
                continue;
            }

            $db    = $this->getDatabase();
            $query = $db->getQuery(true)
                ->select($db->quoteName('id'))
                ->from($db->quoteName($table))
                ->where($db->quoteName($field) . ' = :sourceId')
                ->bind(':sourceId', $sourceItemId, ParameterType::INTEGER);
            $db->setQuery($query);

            foreach (array_map('intval', $db->loadColumn()) as $childId) {
                $translatedChildId = $this->translatedRelatedId($childId, $contentType, $targetLanguage);

                if ($translatedChildId === null) {
                    continue;
                }

                /** @var \Joomla\CMS\Table\Nested $node */
                $node = $model->getTable();

                if (!$node->load($translatedChildId) || (int) $node->{$field} !== (int) $fallbacks[$field]) {
                    continue;
                }

                $scopeValues = [];

                foreach ((array) ($properties['aliasScope'] ?? []) as $column) {
                    $scopeValues[$column] = $column === $field ? $draftId : $node->{$column};
                }

                $alias = (string) $node->alias;

                $node->alias    = DraftAlias::pick($alias, $targetLanguage, $this->neighbouringAliases($alias, $table, $scopeValues, $translatedChildId));
                $node->{$field} = $draftId;
                $node->setLocation($draftId, 'last-child');
                $node->store();

                $this->placeLikeSource($model, $childId, $translatedChildId, $targetLanguage, $contentType, $properties);
            }
        }
    }

    /**
     * Put a translation in the place its source has among its siblings.
     *
     * Joomla adds a new item as the last child of its parent, so a menu translated item by item ends
     * up in the order it was translated in rather than in the source's. A translation goes directly
     * after the translation of the nearest earlier sibling of its source that has one, or first when
     * none has. Placed that way the translated siblings keep the source's order whatever order they
     * are translated in. A translation still waiting at the root for its parent's is placed once it
     * is moved under that parent, and a sibling's translation someone moved elsewhere is passed over.
     *
     * Only a type whose items nest under items of their own type has siblings to keep in order.
     *
     * @param   AdminModel            $model           The managing component's model, for its table.
     * @param   integer               $sourceItemId    The source item.
     * @param   integer               $translationId   Its translation.
     * @param   string                $targetLanguage  The translation's language.
     * @param   string                $contentType     The content type key, e.g. 'com_menus.item'.
     * @param   array<string, mixed>  $properties      The content type's properties from the map.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function placeLikeSource(
        AdminModel $model,
        int $sourceItemId,
        int $translationId,
        string $targetLanguage,
        string $contentType,
        array $properties
    ): void {
        $parentField = array_search($contentType, (array) ($properties['associatedFields'] ?? []), true);

        if (!\is_string($parentField)) {
            return;
        }

        $table     = (string) ($properties['table'] ?? '');
        $container = isset($properties['languageMenu']) ? (string) $properties['languageMenu'] : null;
        $columns   = array_filter(['id', $parentField, $container]);
        $db        = $this->getDatabase();

        $query = $db->getQuery(true)
            ->select($db->quoteName($columns))
            ->from($db->quoteName($table))
            ->whereIn($db->quoteName('id'), [$sourceItemId, $translationId]);
        $rows = $db->setQuery($query)->loadAssocList('id');

        if (!isset($rows[$sourceItemId], $rows[$translationId])) {
            return;
        }

        $source      = $rows[$sourceItemId];
        $translation = $rows[$translationId];
        $fallback    = $properties['associatedFieldsFallback'][$parentField] ?? null;
        $sourceRoot  = $fallback !== null && (int) $source[$parentField] === (int) $fallback;

        // Under its parent's translation, or at the root when the source is at the root too: anywhere
        // else it is still waiting for its parent, and is placed when it is moved.
        $parentTranslation = $sourceRoot
            ? (int) $fallback
            : $this->translatedRelatedId((int) $source[$parentField], $contentType, $targetLanguage);

        if ($parentTranslation === null || (int) $translation[$parentField] !== $parentTranslation) {
            return;
        }

        $sourceParent = (int) $source[$parentField];
        $query        = $db->getQuery(true)
            ->select($db->quoteName('id'))
            ->from($db->quoteName($table))
            ->where($db->quoteName($parentField) . ' = :parent')
            ->bind(':parent', $sourceParent, ParameterType::INTEGER)
            ->order($db->quoteName('lft') . ' ASC');

        if ($container !== null) {
            $sourceContainer = (string) $source[$container];
            $query->where($db->quoteName($container) . ' = :container')
                ->bind(':container', $sourceContainer, ParameterType::STRING);
        }

        $siblings = array_map('intval', $db->setQuery($query)->loadColumn());
        $position = array_search($sourceItemId, $siblings, true);

        if ($position === false) {
            return;
        }

        $after = null;

        for ($i = $position - 1; $i >= 0 && $after === null; $i--) {
            $candidate = $this->translatedRelatedId($siblings[$i], $contentType, $targetLanguage);

            if ($candidate !== null && $this->isSiblingOf($candidate, $translation, $table, $parentField, $container)) {
                $after = $candidate;
            }
        }

        /** @var \Joomla\CMS\Table\Nested $node */
        $node = $model->getTable();

        if ($after !== null) {
            $node->moveByReference($after, 'after', $translationId);

            return;
        }

        // First among its siblings. At the root of a menu, the first item of that menu is the
        // reference, because the root holds the items of every menu.
        $first = $this->firstSibling($translation, $translationId, $table, $parentField, $container);

        if ($first !== null) {
            $node->moveByReference($first, 'before', $translationId);
        }
    }

    /**
     * Whether an item sits under the same parent, in the same container, as a translation.
     *
     * @param   integer      $itemId       The item.
     * @param   array        $translation  The translation's parent and container.
     * @param   string       $table        The content type's table.
     * @param   string       $parentField  The column naming the parent.
     * @param   string|null  $container    The column naming the container, if the type has one.
     *
     * @return  boolean
     *
     * @since   2.0.0
     */
    private function isSiblingOf(int $itemId, array $translation, string $table, string $parentField, ?string $container): bool
    {
        $db    = $this->getDatabase();
        $query = $db->getQuery(true)
            ->select($db->quoteName(array_filter([$parentField, $container])))
            ->from($db->quoteName($table))
            ->where($db->quoteName('id') . ' = :id')
            ->bind(':id', $itemId, ParameterType::INTEGER);
        $row = $db->setQuery($query)->loadAssoc();

        return $row !== null
            && (int) $row[$parentField] === (int) $translation[$parentField]
            && ($container === null || (string) $row[$container] === (string) $translation[$container]);
    }

    /**
     * The first of a translation's siblings, other than itself.
     *
     * @param   array        $translation    The translation's parent and container.
     * @param   integer      $translationId  The translation.
     * @param   string       $table          The content type's table.
     * @param   string       $parentField    The column naming the parent.
     * @param   string|null  $container      The column naming the container, if the type has one.
     *
     * @return  integer|null  Null when it has no siblings.
     *
     * @since   2.0.0
     */
    private function firstSibling(array $translation, int $translationId, string $table, string $parentField, ?string $container): ?int
    {
        $parent = (int) $translation[$parentField];
        $db     = $this->getDatabase();
        $query  = $db->getQuery(true)
            ->select($db->quoteName('id'))
            ->from($db->quoteName($table))
            ->where($db->quoteName($parentField) . ' = :parent')
            ->where($db->quoteName('id') . ' <> :id')
            ->bind(':parent', $parent, ParameterType::INTEGER)
            ->bind(':id', $translationId, ParameterType::INTEGER)
            ->order($db->quoteName('lft') . ' ASC');

        if ($container !== null) {
            $value = (string) $translation[$container];
            $query->where($db->quoteName($container) . ' = :container')
                ->bind(':container', $value, ParameterType::STRING);
        }

        $first = $db->setQuery($query, 0, 1)->loadResult();

        return $first === null ? null : (int) $first;
    }

    /**
     * Load the tag ids attached to a source item.
     *
     * Tags live in the content item tag map rather than on the item's own table, so they are
     * read separately, keyed by the type alias the tags are stored under and the item id.
     *
     * @param   string   $tagTypeAlias  The type alias the item's tags are stored under, e.g. 'com_content.article'.
     * @param   integer  $sourceItemId  The source item id.
     *
     * @return  integer[]  The attached tag ids.
     *
     * @since   0.4.0
     */
    private function getSourceTagIds(string $tagTypeAlias, int $sourceItemId): array
    {
        $db    = $this->getDatabase();
        $query = $db->getQuery(true)
            ->select($db->quoteName('tag_id'))
            ->from($db->quoteName('#__contentitem_tag_map'))
            ->where($db->quoteName('type_alias') . ' = :tagTypeAlias')
            ->where($db->quoteName('content_item_id') . ' = :contentId')
            ->bind(':tagTypeAlias', $tagTypeAlias, ParameterType::STRING)
            ->bind(':contentId', $sourceItemId, ParameterType::INTEGER);
        $db->setQuery($query);

        return array_map('intval', $db->loadColumn());
    }

    /**
     * Derive a menu item's target language menu, creating that menu when it does not exist.
     *
     * The menu of a source item carries no language association, so the target language menu is named by
     * stripping any source language suffix from the source menutype and appending the target language code
     * ("mainmenu" or "mainmenu-en-gb" becomes "mainmenu-fr-fr"). The menu is created when missing so the
     * translated item has somewhere to live.
     *
     * @param   ComponentInterface&MVCFactoryServiceInterface  $component        The booted managing component.
     * @param   string                                         $sourceMenutype  The source item's menutype.
     * @param   string                                         $sourceLanguage  The source item's language code.
     * @param   string                                         $targetLanguage  The target language code.
     *
     * @return  string  The target language menutype.
     *
     * @throws  \RuntimeException  If the menu cannot be created.
     *
     * @since   0.4.0
     */
    private function deriveLanguageMenu(
        ComponentInterface&MVCFactoryServiceInterface $component,
        string $sourceMenutype,
        string $sourceLanguage,
        string $targetLanguage
    ): string {
        $sourceSuffix = '-' . strtolower($sourceLanguage);
        $base         = str_ends_with($sourceMenutype, $sourceSuffix)
            ? substr($sourceMenutype, 0, -strlen($sourceSuffix))
            : $sourceMenutype;
        $menutype     = $base . '-' . strtolower($targetLanguage);

        /** @var \Joomla\CMS\Table\MenuType $menu */
        $menu = $component->getMVCFactory()->createTable('MenuType', 'Administrator');

        // Nothing to create when the target language menu already exists.
        if ($menu->load(['menutype' => $menutype])) {
            return $menutype;
        }

        $menu->bind(['menutype' => $menutype, 'title' => $menutype, 'client_id' => 0]);

        if (!$menu->check() || !$menu->store()) {
            throw new \RuntimeException(\sprintf('Could not create the %s menu.', $menutype));
        }

        return $menutype;
    }

    /**
     * Load the item ids of the source item's association group, keyed by language.
     *
     * Joomla keeps all language versions of an item in one association group
     * under a shared key. Returns an empty array when the item has no
     * associations yet.
     *
     * @param   integer  $sourceItemId  The source item id.
     * @param   string   $context       The associations context, e.g. 'com_content.item'.
     * @param   string   $table         The content type's database table.
     *
     * @return  array  Item ids keyed by language code.
     *
     * @since   0.3.0
     */
    private function getAssociationGroup(int $sourceItemId, string $context, string $table): array
    {
        $db    = $this->getDatabase();
        $query = $db->getQuery(true)
            ->select($db->quoteName(['item.language', 'item.id']))
            ->from($db->quoteName('#__associations', 'sourceAssociation'))
            ->join(
                'INNER',
                $db->quoteName('#__associations', 'groupAssociation'),
                $db->quoteName('groupAssociation.key') . ' = ' . $db->quoteName('sourceAssociation.key')
            )
            ->join(
                'INNER',
                $db->quoteName($table, 'item'),
                $db->quoteName('item.id') . ' = ' . $db->quoteName('groupAssociation.id')
            )
            ->where($db->quoteName('sourceAssociation.context') . ' = :context')
            ->where($db->quoteName('groupAssociation.context') . ' = :groupContext')
            ->where($db->quoteName('sourceAssociation.id') . ' = :sourceId')
            ->bind(':context', $context, ParameterType::STRING)
            ->bind(':groupContext', $context, ParameterType::STRING)
            ->bind(':sourceId', $sourceItemId, ParameterType::INTEGER);
        $db->setQuery($query);

        return $db->loadAssocList('language', 'id');
    }

    /**
     * Write the source item's association group to #__associations directly.
     *
     * Mirrors the write in AdminModel::save(): the items are removed and re-inserted
     * under one shared key, so every language version stays in one group. Needed for
     * content types whose model sets no associationsContext (tags), where save() leaves
     * the link unwritten.
     *
     * @param   array   $idsByLanguage  The group's item ids keyed by language, including the new draft.
     * @param   string  $context        The associations context, e.g. 'com_tags.item'.
     *
     * @return  void
     *
     * @since   0.4.0
     */
    private function writeAssociations(array $idsByLanguage, string $context): void
    {
        $ids = array_values(array_filter(array_map('intval', $idsByLanguage)));

        // A lone item has nothing to associate with.
        if (\count($ids) < 2) {
            return;
        }

        $db = $this->getDatabase();

        // Clear the items' current rows so the whole group is re-keyed in one place.
        $query = $db->getQuery(true)
            ->delete($db->quoteName('#__associations'))
            ->where($db->quoteName('context') . ' = :context')
            ->whereIn($db->quoteName('id'), $ids, ParameterType::INTEGER)
            ->bind(':context', $context, ParameterType::STRING);
        $db->setQuery($query);
        $db->execute();

        // One shared key ties the language versions together, the way core keys them.
        $key   = md5((string) json_encode($idsByLanguage));
        $query = $db->getQuery(true)
            ->insert($db->quoteName('#__associations'))
            ->columns($db->quoteName(['id', 'context', 'key']));

        foreach ($ids as $id) {
            $query->values(
                implode(',', $query->bindArray([$id, $context, $key], [ParameterType::INTEGER, ParameterType::STRING, ParameterType::STRING]))
            );
        }

        $db->setQuery($query);
        $db->execute();
    }

    /**
     * Record whether a new translation waits for its source to be published.
     *
     * @param   integer  $sourceItemId    The source item id.
     * @param   string   $contentType     The content type key, e.g. 'com_content.article'.
     * @param   string   $targetLanguage  The target language code.
     * @param   boolean  $held            Whether the translation is held by its source.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    private function setHeldBySource(int $sourceItemId, string $contentType, string $targetLanguage, bool $held): void
    {
        $stateId = $this->getOrCreateStateId($sourceItemId, $contentType, $targetLanguage);
        $value   = $held ? 1 : 0;
        $db      = $this->getDatabase();
        $query   = $db->getQuery(true)
            ->update($db->quoteName('#__translations_queue_states'))
            ->set($db->quoteName('held_by_source') . ' = :held')
            ->where($db->quoteName('id') . ' = :stateId')
            ->bind(':held', $value, ParameterType::INTEGER)
            ->bind(':stateId', $stateId, ParameterType::INTEGER);
        $db->setQuery($query)->execute();
    }

    /**
     * Set the source item's state for one target language to "review".
     *
     * One state row exists per (queue row, target language); no row means "no
     * translation yet". The first translation inserts the row, a repeat run
     * updates it and clears the attempts and the error any failed run left on it.
     * The row's modified time is maintained by the database.
     *
     * @param   integer  $sourceItemId    The source item id.
     * @param   string   $targetLanguage  The target language code.
     * @param   string   $contentType     The content type key, e.g. 'com_content.article'.
     * @param   array    $machineDraft    The draft's translatable values as stored, keyed by field.
     * @param   array    $properties      The content type's properties from the map.
     *
     * @return  void
     *
     * @since   0.3.0
     */
    private function markReadyForReview(int $sourceItemId, string $targetLanguage, string $contentType, array $machineDraft, array $properties): void
    {
        $queueId     = $this->getOrCreateQueueId($sourceItemId, $contentType);
        $reviewState = 'review';

        // Record the version translated from, so a later edit of the source invalidates this translation.
        $versionId = $this->sourceVersionId($sourceItemId, $properties);

        // Keep the translation as produced, to pair against the translator's correction on approval.
        $machineDraftJson = (string) json_encode($machineDraft);

        // A state row may already exist from an earlier translation of this language.
        $db    = $this->getDatabase();
        $query = $db->getQuery(true)
            ->select($db->quoteName('id'))
            ->from($db->quoteName('#__translations_queue_states'))
            ->where($db->quoteName('queue_id') . ' = :queueId')
            ->where($db->quoteName('target_language') . ' = :targetLanguage')
            ->bind(':queueId', $queueId, ParameterType::INTEGER)
            ->bind(':targetLanguage', $targetLanguage, ParameterType::STRING);
        $db->setQuery($query);

        $stateId = $db->loadResult();

        if ($stateId !== null) {
            $stateId   = (int) $stateId;
            $lastError = '';

            $query = $db->getQuery(true)
                ->update($db->quoteName('#__translations_queue_states'))
                ->set($db->quoteName('translation_state') . ' = :state')
                ->set($db->quoteName('source_version_id') . ' = :versionId')
                ->set($db->quoteName('machine_draft') . ' = :machineDraft')
                ->set($db->quoteName('attempts') . ' = 0')
                ->set($db->quoteName('last_error') . ' = :lastError')
                ->where($db->quoteName('id') . ' = :stateId')
                ->bind(':state', $reviewState, ParameterType::STRING)
                ->bind(':versionId', $versionId, ParameterType::INTEGER)
                ->bind(':machineDraft', $machineDraftJson, ParameterType::STRING)
                ->bind(':lastError', $lastError, ParameterType::STRING)
                ->bind(':stateId', $stateId, ParameterType::INTEGER);
            $db->setQuery($query);
            $db->execute();

            return;
        }

        $stateRow = (object) [
            'queue_id'          => $queueId,
            'target_language'   => $targetLanguage,
            'translation_state' => $reviewState,
            'source_version_id' => $versionId,
            'machine_draft'     => $machineDraftJson,
        ];

        $db->insertObject('#__translations_queue_states', $stateRow);
    }

    /**
     * Find the queue row for a source item, creating it when missing.
     *
     * The queue holds one row per source item, keyed by content type + id.
     * A source item gets its row with its first translation.
     *
     * @param   integer  $sourceItemId  The source item id.
     * @param   string   $contentType   The content type key, e.g. 'com_content.article'.
     *
     * @return  integer  The queue row id.
     *
     * @since   0.3.0
     */
    private function getOrCreateQueueId(int $sourceItemId, string $contentType): int
    {
        $db    = $this->getDatabase();
        $query = $db->getQuery(true)
            ->select($db->quoteName('id'))
            ->from($db->quoteName('#__translations_queue'))
            ->where($db->quoteName('content_type') . ' = :contentType')
            ->where($db->quoteName('content_id') . ' = :contentId')
            ->bind(':contentType', $contentType, ParameterType::STRING)
            ->bind(':contentId', $sourceItemId, ParameterType::INTEGER);
        $db->setQuery($query);

        $queueId = $db->loadResult();

        if ($queueId !== null) {
            return (int) $queueId;
        }

        $queueRow = (object) [
            'content_type' => $contentType,
            'content_id'   => $sourceItemId,
        ];

        $db->insertObject('#__translations_queue', $queueRow, 'id');

        return (int) $queueRow->id;
    }

    /**
     * Load the newest version stored for a source item.
     *
     * There exists an is_current column flagging the current version in #__history since Joomla 6,
     * but we read the highest version_id, due to Joomla 5 backward compatibility. An item with no
     * versions gives 0, which nothing compares as newer than.
     *
     * @param   integer  $sourceItemId  The source item id.
     * @param   array    $properties    The content type's properties from the map.
     *
     * @return  integer  The version id, or 0 when the item has none.
     *
     * @since   0.7.0
     */
    private function sourceVersionId(int $sourceItemId, array $properties): int
    {
        // Joomla keys a version by the type alias the item is versioned under, which is not always
        // our key for the content type. A type Joomla does not version carries none.
        $versionTypeAlias = (string) ($properties['versionTypeAlias'] ?? '');

        if ($versionTypeAlias === '') {
            return 0;
        }

        $itemId = $versionTypeAlias . '.' . $sourceItemId;

        $db    = $this->getDatabase();
        $query = $db->getQuery(true)
            ->select('MAX(' . $db->quoteName('version_id') . ')')
            ->from($db->quoteName('#__history'))
            ->where($db->quoteName('item_id') . ' = :itemId')
            ->bind(':itemId', $itemId, ParameterType::STRING);
        $db->setQuery($query);

        return (int) $db->loadResult();
    }
}
