<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  Task.TranslationsSeed
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Plugin\Task\TranslationsSeed\Helper;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use Joomla\Component\Translations\Administrator\Helper\StringTranslator;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use Joomla\Event\DispatcherInterface;

/**
 * Turns an installed language pack into translator feedback for the Translations component.
 *
 * A pack holds translations a language team has agreed on, so pairing one against an unaided
 * machine translation of the same string produces the same material a translator produces by
 * correcting a draft. The pairs are written as feedback and the component's distiller learns
 * rules from them, which gives a site rules before anyone has corrected anything on it.
 *
 * The machine translation is asked for without rules, so what the pack disagrees with is the
 * provider's own wording rather than something already learned on this site.
 *
 * @since  1.0.0
 */
class Seeder
{
    /**
     * The most strings sent in one request.
     *
     * A provider is asked for every key it is given, so an oversized request is lost as a whole
     * rather than in part. This keeps a request short enough that the reply has room to grow for
     * a language whose wording runs longer than the source.
     *
     * @var    integer
     * @since  1.0.0
     */
    private const STRINGS_PER_REQUEST = 25;

    /**
     * Failures in a row that end a run.
     *
     * One request can fail on its own account, but a run of them means the provider is
     * unreachable, and every further attempt is another paid call for the same answer.
     *
     * @var    integer
     * @since  1.0.0
     */
    private const MAX_CONSECUTIVE_FAILURES = 2;

    /**
     * The origin recorded on the feedback this writes, and so on the rules distilled from it.
     *
     * A seeded set is attributable to the pack it came from rather than to a translator, which is
     * what lets it be reviewed, exported or withdrawn as a set.
     *
     * @var    string
     * @since  1.0.0
     */
    private const SOURCE_ORIGIN = 'ini_import';

    /**
     * The database driver.
     *
     * @var    DatabaseInterface
     * @since  1.0.0
     */
    private $db;

    /**
     * The dispatcher the translation provider answers on.
     *
     * @var    DispatcherInterface
     * @since  1.0.0
     */
    private $dispatcher;

    /**
     * Constructor.
     *
     * @param   DatabaseInterface    $db          The database driver.
     * @param   DispatcherInterface  $dispatcher  The dispatcher the translation provider answers on.
     *
     * @since   1.0.0
     */
    public function __construct(DatabaseInterface $db, DispatcherInterface $dispatcher)
    {
        $this->db         = $db;
        $this->dispatcher = $dispatcher;
    }

    /**
     * Seed feedback from one batch of a language pack's translated strings.
     *
     * A string already seeded for the language is skipped, so a run resumes where the last one
     * stopped rather than paying for the same translations again.
     *
     * @param   string    $sourceLanguage  The language tag the strings are written in.
     * @param   string    $targetLanguage  The language tag of the pack to learn from.
     * @param   integer   $batchSize       The most strings to seed in one run.
     * @param   string[]  $fileNames       The language file names to read, all of them when empty.
     *
     * @return  integer  The number of strings seeded.
     *
     * @throws  \RuntimeException  When the language is the source language, or the provider is unreachable.
     *
     * @since   1.0.0
     */
    public function seed(string $sourceLanguage, string $targetLanguage, int $batchSize, array $fileNames = []): int
    {
        if ($targetLanguage === $sourceLanguage) {
            throw new \RuntimeException(\sprintf('%s is the source language, so there is nothing to learn from.', $targetLanguage));
        }

        $pending = $this->pendingPairs($sourceLanguage, $targetLanguage, $fileNames, $batchSize);

        if ($pending === []) {
            return 0;
        }

        $seeded   = 0;
        $failures = 0;

        foreach ($this->requestChunks($pending) as $chunk) {
            try {
                $translated = StringTranslator::translate(
                    $this->dispatcher,
                    array_map(static fn(array $pair): string => $pair['source'], $chunk),
                    $sourceLanguage,
                    $targetLanguage,
                    []
                );

                $failures = 0;
            } catch (\Throwable $e) {
                $failures++;

                // Nothing is recorded for the chunk, so the strings stay pending for a later run.
                if ($failures === self::MAX_CONSECUTIVE_FAILURES) {
                    throw new \RuntimeException(
                        \sprintf('Stopped after %d failures in a row, the last being: %s', $failures, $e->getMessage()),
                        0,
                        $e
                    );
                }

                continue;
            }

            $seeded += $this->recordChunk($chunk, $translated, $targetLanguage);
        }

        return $seeded;
    }

    /**
     * Collect the pack's translated strings that have not been seeded yet, up to a batch.
     *
     * @param   string    $sourceLanguage  The source language code.
     * @param   string    $targetLanguage  The target language code.
     * @param   string[]  $fileNames       The language file names to read, all of them when empty.
     * @param   integer   $batchSize       The most strings to collect.
     *
     * @return  array  The pairs still to seed, keyed by string id.
     *
     * @since   1.0.0
     */
    private function pendingPairs(string $sourceLanguage, string $targetLanguage, array $fileNames, int $batchSize): array
    {
        $pairs = [];

        foreach (LanguagePackReader::read($sourceLanguage, $targetLanguage, $fileNames) as $pair) {
            $pairs[$pair['file'] . '#' . $pair['key']] = $pair;
        }

        if ($pairs === []) {
            return [];
        }

        foreach ($this->seededStringIds($targetLanguage, array_keys($pairs)) as $stringId) {
            unset($pairs[$stringId]);
        }

        return \array_slice($pairs, 0, $batchSize, true);
    }

    /**
     * Read back which of the given strings are already seeded for the language.
     *
     * @param   string    $targetLanguage  The target language code.
     * @param   string[]  $stringIds       The string ids to look for.
     *
     * @return  string[]  The string ids already seeded.
     *
     * @since   1.0.0
     */
    private function seededStringIds(string $targetLanguage, array $stringIds): array
    {
        $query = $this->db->getQuery(true)
            ->select($this->db->quoteName('string_id'))
            ->from($this->db->quoteName('#__translations_seeded_strings'))
            ->where($this->db->quoteName('target_language') . ' = :targetLanguage')
            ->whereIn($this->db->quoteName('string_id'), $stringIds, ParameterType::STRING)
            ->bind(':targetLanguage', $targetLanguage, ParameterType::STRING);
        $this->db->setQuery($query);

        return $this->db->loadColumn() ?: [];
    }

    /**
     * Split the pairs into requests, each keyed by language key.
     *
     * The key tells a provider what a string is for, which a bare string does not, but a handful
     * of keys are used for different text in different files. A chunk therefore ends early rather
     * than let one of those overwrite the other.
     *
     * @param   array  $pairs  The pairs to send, keyed by string id.
     *
     * @return  array  The chunks, each an array of pairs keyed by language key.
     *
     * @since   1.0.0
     */
    private function requestChunks(array $pairs): array
    {
        $chunks = [];
        $chunk  = [];

        foreach ($pairs as $pair) {
            if (isset($chunk[$pair['key']]) || \count($chunk) >= self::STRINGS_PER_REQUEST) {
                $chunks[] = $chunk;
                $chunk    = [];
            }

            $chunk[$pair['key']] = $pair;
        }

        if ($chunk !== []) {
            $chunks[] = $chunk;
        }

        return $chunks;
    }

    /**
     * Record a translated chunk: the feedback it produced, and that its strings are seeded.
     *
     * A string the provider translated exactly as the pack does carries no correction to learn
     * from, so it writes no feedback. It is still marked seeded, because the call it took has been
     * paid for either way. A string the provider passed over is not marked, so a later run asks
     * for it again rather than losing it to a reply that came back short.
     *
     * @param   array   $chunk           The pairs sent, keyed by language key.
     * @param   array   $translated      The provider's translations, keyed as sent.
     * @param   string  $targetLanguage  The target language code.
     *
     * @return  integer  The number of strings recorded.
     *
     * @since   1.0.0
     */
    private function recordChunk(array $chunk, array $translated, string $targetLanguage): int
    {
        $stringIds = [];

        foreach ($chunk as $key => $pair) {
            $machineDraft = trim((string) ($translated[$key] ?? ''));

            if ($machineDraft === '') {
                continue;
            }

            $stringIds[] = $pair['file'] . '#' . $pair['key'];

            if ($machineDraft === trim($pair['approved'])) {
                continue;
            }

            $row = (object) [
                'queue_id'         => 0,
                'source_text'      => $pair['source'],
                'machine_draft'    => $machineDraft,
                'human_correction' => $pair['approved'],
                'target_language'  => $targetLanguage,
                'source_origin'    => self::SOURCE_ORIGIN,
                'translator_id'    => 0,
            ];

            $this->db->insertObject('#__translations_feedback', $row);
        }

        return $this->markSeeded($stringIds, $targetLanguage);
    }

    /**
     * Mark strings as seeded for a language.
     *
     * @param   string[]  $stringIds       The string ids to mark.
     * @param   string    $targetLanguage  The target language code.
     *
     * @return  integer  The number of strings marked.
     *
     * @since   1.0.0
     */
    private function markSeeded(array $stringIds, string $targetLanguage): int
    {
        if ($stringIds === []) {
            return 0;
        }

        $query = $this->db->getQuery(true)
            ->insert($this->db->quoteName('#__translations_seeded_strings'))
            ->columns($this->db->quoteName(['target_language', 'string_id']));

        foreach ($stringIds as $stringId) {
            $query->values(
                implode(
                    ',',
                    $query->bindArray([$targetLanguage, $stringId], [ParameterType::STRING, ParameterType::STRING])
                )
            );
        }

        $this->db->setQuery($query);
        $this->db->execute();

        return \count($stringIds);
    }
}
