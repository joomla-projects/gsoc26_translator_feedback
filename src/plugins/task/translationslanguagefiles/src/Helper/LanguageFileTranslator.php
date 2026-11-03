<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  Task.TranslationsLanguageFiles
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Plugin\Task\TranslationsLanguageFiles\Helper;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use Joomla\CMS\Factory;
use Joomla\Component\Translations\Administrator\Helper\RuleRetriever;
use Joomla\Component\Translations\Administrator\Helper\RunLock;
use Joomla\Component\Translations\Administrator\Helper\RunResult;
use Joomla\Component\Translations\Administrator\Helper\StringTranslator;
use Joomla\Component\Translations\Administrator\Helper\TimeBudget;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use Joomla\Event\DispatcherInterface;

/**
 * Translates Translator Feedback's own language files into the site's content languages.
 *
 * The strings go to the translation provider the same way an item's fields do, with the
 * published rules for the target language that apply to them, so the interface uses the words
 * the site's language teams settled on: on guide.joomla.org those are mostly learned from the
 * core language packs. Each answer is stored per language, file and key with a fingerprint of
 * its English text, so a run only sends what is new or has changed since, and a string the
 * provider keeps getting wrong is set aside after its third attempt, as the other tasks do.
 *
 * @since  1.0.0
 */
final class LanguageFileTranslator
{
    /**
     * The texts sent in one request by default.
     *
     * @var    integer
     * @since  1.0.0
     */
    public const DEFAULT_REQUEST_SIZE = 25;

    /**
     * A translation stored and checked.
     *
     * @var    string
     * @since  1.0.0
     */
    public const TRANSLATED = 'translated';

    /**
     * A string that failed and has attempts left.
     *
     * @var    string
     * @since  1.0.0
     */
    public const PENDING = 'pending';

    /**
     * A string set aside after its last attempt.
     *
     * @var    string
     * @since  1.0.0
     */
    public const FAILED = 'failed';

    /**
     * The failed requests in a row after which the provider is taken to be unreachable.
     *
     * @var    integer
     * @since  1.0.0
     */
    private const FAILURES_IN_A_ROW = 2;

    /**
     * Matches a printf conversion, including the positional form and a literal percent sign.
     *
     * The space flag is left out: the language strings use none, and with it a percentage in the
     * text, as in "75% of", reads as the conversion "% o".
     *
     * @var    string
     * @since  1.0.0
     */
    private const PLACEHOLDER = '/%(?:\d+\$)?[-+0#]*\d*(?:\.\d+)?[bcdeEfFgGosuxX%]/';

    /**
     * Matches the name of an HTML tag, opening or closing.
     *
     * @var    string
     * @since  1.0.0
     */
    private const TAG = '/<\s*(\/?)\s*([a-zA-Z][a-zA-Z0-9]*)/';

    /**
     * The database table the translations are kept in.
     *
     * @var    string
     * @since  1.0.0
     */
    public const TABLE = '#__translations_languagefiles';

    /**
     * Constructor.
     *
     * @param   DatabaseInterface    $db          The site's database.
     * @param   DispatcherInterface  $dispatcher  The dispatcher the translation and RAG plugins answer on.
     *
     * @since   1.0.0
     */
    public function __construct(private readonly DatabaseInterface $db, private readonly DispatcherInterface $dispatcher)
    {
    }

    /**
     * Translate what is left, language by language, until the time is used up.
     *
     * @param   string[]    $languages    The target languages, in the order to work through them.
     * @param   integer     $requestSize  The most texts in one request.
     * @param   TimeBudget  $budget       How long the run may keep sending requests.
     *
     * @return  RunResult  What the run did, counted in language strings.
     *
     * @since   1.0.0
     */
    public function translate(array $languages, int $requestSize, TimeBudget $budget): RunResult
    {
        $result = new RunResult();
        $lock   = new RunLock($this->db, 'languagefiles');

        if (!$lock->acquire()) {
            $result->busy = true;

            return $result;
        }

        try {
            $sources  = $this->sources();
            $failures = 0;

            foreach ($languages as $language) {
                $units = $this->pendingUnits($sources, $language);

                while ($units !== [] && $budget->allowsAnother()) {
                    $batch   = $this->nextBatch($units, $requestSize);
                    $strings = [];

                    foreach ($batch as $id => $unit) {
                        $strings[$id] = $unit['text'];
                    }

                    $budget->startRequest();

                    try {
                        $rules        = RuleRetriever::retrieve($this->db, $this->dispatcher, $strings, PackageLanguageFiles::SOURCE_LANGUAGE, $language);
                        $translations = StringTranslator::translate($this->dispatcher, $strings, PackageLanguageFiles::SOURCE_LANGUAGE, $language, $rules);
                        $error        = '';
                    } catch (\Throwable $e) {
                        $translations = [];
                        $error        = $e->getMessage();
                    } finally {
                        $budget->endRequest();
                    }

                    $failures = $error === '' ? 0 : $failures + 1;

                    foreach ($batch as $id => $unit) {
                        $fault = $error !== '' ? $error : self::fault($unit['text'], $translations[$id] ?? null);

                        if ($fault === null) {
                            $this->store($language, $unit, self::TRANSLATED, (string) $translations[$id], 0, '');
                            $result->processed += \count($unit['rows']);

                            continue;
                        }

                        $attempts = $unit['attempts'] + 1;
                        $state    = $attempts >= RunResult::MAX_ATTEMPTS ? self::FAILED : self::PENDING;
                        $this->store($language, $unit, $state, '', $attempts, RunResult::errorText($fault));
                        $result->failed += \count($unit['rows']);
                        $result->lastError = $fault;

                        if ($state === self::FAILED) {
                            $result->quarantined += \count($unit['rows']);
                        }
                    }

                    if ($failures >= self::FAILURES_IN_A_ROW) {
                        $result->aborted = true;
                        break 2;
                    }
                }

                if (!$budget->allowsAnother()) {
                    break;
                }
            }

            foreach ($languages as $language) {
                foreach ($this->pendingUnits($sources, $language) as $unit) {
                    $result->remaining += \count($unit['rows']);
                }
            }
        } finally {
            $lock->release();
        }

        return $result;
    }

    /**
     * How far each language has got.
     *
     * @param   string[]  $languages  The languages to report on.
     *
     * @return  array<string, array{total: int, translated: int, failed: int}>  Counted in language strings.
     *
     * @since   1.0.0
     */
    public function status(array $languages): array
    {
        $sources = $this->sources();
        $filled  = static fn(array $strings): int => \count(array_filter($strings, static fn($text): bool => (string) $text !== ''));
        $total   = array_sum(array_map($filled, $sources));
        $status  = [];

        foreach ($languages as $language) {
            $status[$language] = ['total' => $total, 'translated' => 0, 'failed' => 0];

            foreach ($this->storedRows($language) as $identifier => $row) {
                [$file, $key] = explode('#', $identifier, 2);

                if (!isset($sources[$file][$key]) || $row['source_hash'] !== self::fingerprint($sources[$file][$key])) {
                    continue;
                }

                if ($row['state'] === self::TRANSLATED) {
                    $status[$language]['translated']++;
                } elseif ($row['state'] === self::FAILED) {
                    $status[$language]['failed']++;
                }
            }
        }

        return $status;
    }

    /**
     * The checked translations of a language that still match their English text.
     *
     * @param   string  $language  The language.
     *
     * @return  array<string, array<string, string>>  Keyed by file name, then by language constant.
     *
     * @since   1.0.0
     */
    public function translations(string $language): array
    {
        $sources      = $this->sources();
        $translations = [];

        foreach ($this->storedRows($language) as $identifier => $row) {
            [$file, $key] = explode('#', $identifier, 2);

            if ($row['state'] === self::TRANSLATED
                && isset($sources[$file][$key])
                && $row['source_hash'] === self::fingerprint($sources[$file][$key])) {
                $translations[$file][$key] = (string) $row['translation'];
            }
        }

        return $translations;
    }

    /**
     * The languages that have stored translations.
     *
     * @return  string[]
     *
     * @since   1.0.0
     */
    public function languagesWithTranslations(): array
    {
        $state = self::TRANSLATED;
        $query = $this->db->getQuery(true)
            ->select('DISTINCT ' . $this->db->quoteName('language'))
            ->from($this->db->quoteName(self::TABLE))
            ->where($this->db->quoteName('state') . ' = :state')
            ->order($this->db->quoteName('language'))
            ->bind(':state', $state);

        return $this->db->setQuery($query)->loadColumn() ?: [];
    }

    /**
     * Give the strings that were set aside a new set of attempts.
     *
     * @return  integer  The strings given new attempts.
     *
     * @since   1.0.0
     */
    public function resetFailed(): int
    {
        $failed  = self::FAILED;
        $pending = self::PENDING;
        $query   = $this->db->getQuery(true)
            ->update($this->db->quoteName(self::TABLE))
            ->set($this->db->quoteName('state') . ' = :pending')
            ->set($this->db->quoteName('attempts') . ' = 0')
            ->where($this->db->quoteName('state') . ' = :failed')
            ->bind(':pending', $pending)
            ->bind(':failed', $failed);
        $this->db->setQuery($query)->execute();

        return $this->db->getAffectedRows();
    }

    /**
     * Forget the stored translations of some languages, so they are translated again.
     *
     * @param   string[]  $languages  The languages, all of them when empty.
     *
     * @return  integer  The strings forgotten.
     *
     * @since   1.0.0
     */
    public function forget(array $languages): int
    {
        $query = $this->db->getQuery(true)->delete($this->db->quoteName(self::TABLE));

        if ($languages !== []) {
            $query->whereIn($this->db->quoteName('language'), $languages, ParameterType::STRING);
        }

        $this->db->setQuery($query)->execute();

        return $this->db->getAffectedRows();
    }

    /**
     * What is wrong with a translation, if anything.
     *
     * A language string is put together at runtime, so a translation that loses a placeholder or a
     * tag breaks the screen it is shown on. The placeholders have to come back as they were, in any
     * order; the tags by name, so a translation may still reword the text inside them.
     *
     * @param   string       $source       The English text.
     * @param   string|null  $translation  The provider's answer, null when there was none.
     *
     * @return  string|null  Why the translation cannot be used, or null when it can.
     *
     * @since   1.0.0
     */
    public static function fault(string $source, ?string $translation): ?string
    {
        if ($translation === null || trim($translation) === '') {
            return 'The provider returned no translation for this string.';
        }

        if (self::matches(self::PLACEHOLDER, $source, 0) !== self::matches(self::PLACEHOLDER, $translation, 0)) {
            return 'The translation does not keep the placeholders of the string.';
        }

        if (self::tags($source) !== self::tags($translation)) {
            return 'The translation does not keep the HTML tags of the string.';
        }

        return null;
    }

    /**
     * The fingerprint a translation is kept with, so a changed English text is translated again.
     *
     * @param   string  $text  The English text.
     *
     * @return  string
     *
     * @since   1.0.0
     */
    public static function fingerprint(string $text): string
    {
        return sha1($text);
    }

    /**
     * The sorted matches of a pattern.
     *
     * @param   string   $pattern  The pattern.
     * @param   string   $text     The text.
     * @param   integer  $group    The group to collect.
     *
     * @return  string[]
     *
     * @since   1.0.0
     */
    private static function matches(string $pattern, string $text, int $group): array
    {
        preg_match_all($pattern, $text, $found);
        $matches = $found[$group];
        sort($matches);

        return $matches;
    }

    /**
     * The tags of a text by name, sorted.
     *
     * @param   string  $text  The text.
     *
     * @return  string[]
     *
     * @since   1.0.0
     */
    private static function tags(string $text): array
    {
        preg_match_all(self::TAG, $text, $found, PREG_SET_ORDER);
        $tags = array_map(static fn(array $match): string => $match[1] . strtolower($match[2]), $found);
        sort($tags);

        return $tags;
    }

    /**
     * The English strings of every language file of the package.
     *
     * @return  array<string, array<string, string>>  Keyed by file name, then by language constant.
     *
     * @since   1.0.0
     */
    private function sources(): array
    {
        $sources = [];

        foreach (PackageLanguageFiles::discover($this->db) as $name => $file) {
            $sources[$name] = PackageLanguageFiles::strings($file);
        }

        return $sources;
    }

    /**
     * The texts of a language that still need a translation.
     *
     * A text that occurs under several keys - the same label in a file and its .sys.ini, say - is
     * one unit, so it is paid for once and reads the same everywhere. A unit with attempts behind
     * it comes after the untried ones, so a string that fails does not hold up the rest.
     *
     * @param   array   $sources   The English strings, keyed by file and constant.
     * @param   string  $language  The language.
     *
     * @return  array<string, array{text: string, attempts: int, rows: array}>  Keyed by the first file and constant of the text.
     *
     * @since   1.0.0
     */
    private function pendingUnits(array $sources, string $language): array
    {
        $stored = $this->storedRows($language);
        $units  = [];
        $byText = [];

        foreach ($sources as $file => $strings) {
            foreach ($strings as $key => $text) {
                if (trim((string) $text) === '') {
                    continue;
                }

                $identifier  = $file . '#' . $key;
                $fingerprint = self::fingerprint((string) $text);
                $row         = $stored[$identifier] ?? null;
                $attempts    = 0;

                if ($row !== null && $row['source_hash'] === $fingerprint) {
                    if ($row['state'] !== self::PENDING) {
                        continue;
                    }

                    $attempts = (int) $row['attempts'];
                }

                $id = $byText[$text] ?? $identifier;

                $byText[$text]          = $id;
                $units[$id]['text']     = (string) $text;
                $units[$id]['attempts'] = max($units[$id]['attempts'] ?? 0, $attempts);
                $units[$id]['rows'][]   = [
                    'id'          => $row !== null ? (int) $row['id'] : 0,
                    'file'        => $file,
                    'key'         => $key,
                    'fingerprint' => $fingerprint,
                ];
            }
        }

        uasort($units, static fn(array $a, array $b): int => $a['attempts'] <=> $b['attempts']);

        return $units;
    }

    /**
     * Take the next request's units off the front of the list.
     *
     * Units that failed before go in a smaller request, as in the other tasks, so a request that
     * keeps failing is narrowed down to the string that causes it.
     *
     * @param   array    $units        The pending units, untried first; the batch is removed from it.
     * @param   integer  $requestSize  The most texts in a first request.
     *
     * @return  array  The units of the request, keyed as they are in the list.
     *
     * @since   1.0.0
     */
    private function nextBatch(array &$units, int $requestSize): array
    {
        $attempts = reset($units)['attempts'];
        $limit    = RunResult::requestLimit($requestSize, $attempts);
        $batch    = [];

        foreach ($units as $id => $unit) {
            if (\count($batch) >= $limit || $unit['attempts'] !== $attempts) {
                break;
            }

            $batch[$id] = $unit;
            unset($units[$id]);
        }

        return $batch;
    }

    /**
     * Store the outcome of a unit for every key it stands for.
     *
     * @param   string   $language     The language.
     * @param   array    $unit         The unit.
     * @param   string   $state        The state to store.
     * @param   string   $translation  The translation, empty when there is none.
     * @param   integer  $attempts     The attempts the unit has had.
     * @param   string   $error        The last error, empty when there is none.
     *
     * @return  void
     *
     * @since   1.0.0
     */
    private function store(string $language, array $unit, string $state, string $translation, int $attempts, string $error): void
    {
        $now = Factory::getDate()->toSql();

        foreach ($unit['rows'] as $row) {
            $values = (object) [
                'language'    => $language,
                'file'        => $row['file'],
                'lang_key'    => $row['key'],
                'source_hash' => $row['fingerprint'],
                'translation' => $translation,
                'state'       => $state,
                'attempts'    => $attempts,
                'last_error'  => $error,
                'modified'    => $now,
            ];

            if ($row['id'] > 0) {
                $values->id = $row['id'];
                $this->db->updateObject(self::TABLE, $values, 'id');
            } else {
                $this->db->insertObject(self::TABLE, $values);
            }
        }
    }

    /**
     * The stored rows of a language.
     *
     * @param   string  $language  The language.
     *
     * @return  array<string, array>  Keyed by file name and language constant, joined by #.
     *
     * @since   1.0.0
     */
    private function storedRows(string $language): array
    {
        $query = $this->db->getQuery(true)
            ->select($this->db->quoteName(['id', 'file', 'lang_key', 'source_hash', 'translation', 'state', 'attempts']))
            ->from($this->db->quoteName(self::TABLE))
            ->where($this->db->quoteName('language') . ' = :language')
            ->bind(':language', $language);
        $rows = [];

        foreach ($this->db->setQuery($query)->loadAssocList() ?: [] as $row) {
            $rows[$row['file'] . '#' . $row['lang_key']] = $row;
        }

        return $rows;
    }
}
