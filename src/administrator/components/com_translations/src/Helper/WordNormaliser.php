<?php

/**
 * @package     Joomla.Administrator
 * @subpackage  com_translations
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Component\Translations\Administrator\Helper;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use Joomla\CMS\Log\Log;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\Component\Translations\Administrator\Event\NormaliseEvent;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use Joomla\Event\DispatcherInterface;

/**
 * Reduces words to the standard form rules are matched on: a noun to its singular, a verb to
 * its infinitive.
 *
 * A rule's term and the words of the source text are reduced the same way, so a rule written
 * for one form of a word also applies to the others. A word's standard form depends only on
 * its own language and never changes, so each one is asked of a provider once and then read
 * from #__translations_standard_forms.
 *
 * A word that cannot be reduced is left out of the result and the caller matches it as it was
 * written, so a missing provider or an unreachable API costs recall rather than the
 * translation itself.
 *
 * @since  0.9.0
 */
class WordNormaliser
{
    /**
     * The shortest word worth reducing; below this a word carries no inflection to remove.
     *
     * @var    integer
     * @since  0.9.0
     */
    private const MIN_WORD_LENGTH = 3;

    /**
     * Upper bound on the words sent in one request. A provider answers with a pair per word,
     * so this is really a limit on the reply: measured against the Claude plugin, 200 words
     * come back in about 40 percent of the reply it is allowed, which leaves room for a batch
     * of unusually long words to grow without being truncated.
     *
     * @var    integer
     * @since  0.9.0
     */
    private const MAX_WORDS_PER_REQUEST = 200;

    /**
     * Upper bound on the requests one article may take, so an unusually varied text cannot
     * hold up the translation it is being prepared for. Words beyond it keep the form they
     * were written in until a later run resolves them.
     *
     * @var    integer
     * @since  0.9.0
     */
    private const MAX_REQUESTS = 5;

    /**
     * How many batches in a row may come back with nothing before the rest are abandoned. A
     * provider that is unreachable answers no batch, so there is no point working through the
     * remainder; a single batch a provider declines is worth passing over.
     *
     * @var    integer
     * @since  0.9.0
     */
    private const MAX_CONSECUTIVE_FAILURES = 2;

    /**
     * Reduce words to their standard form, reading what is already stored and asking a
     * provider only about the rest.
     *
     * @param   DatabaseInterface    $db          The database driver.
     * @param   DispatcherInterface  $dispatcher  The dispatcher to raise onNormalise on.
     * @param   array                $words       The words to reduce.
     * @param   string               $language    The language the words are written in.
     *
     * @return  array  Standard form keyed by the lower-cased word, for the words resolved.
     *
     * @since   0.9.0
     */
    public static function standardForms(
        DatabaseInterface $db,
        DispatcherInterface $dispatcher,
        array $words,
        string $language
    ): array {
        $wanted = self::usableWords($words);

        if ($wanted === []) {
            return [];
        }

        $known   = self::loadStored($db, $wanted, $language);
        $missing = array_values(array_diff($wanted, array_keys($known)));

        if ($missing === []) {
            return $known;
        }

        $resolved = [];
        $failures = 0;

        foreach (\array_slice(array_chunk($missing, self::MAX_WORDS_PER_REQUEST), 0, self::MAX_REQUESTS) as $batch) {
            $forms = self::askProvider($dispatcher, $batch, $language);

            if ($forms === []) {
                if (++$failures >= self::MAX_CONSECUTIVE_FAILURES) {
                    break;
                }

                continue;
            }

            $failures = 0;

            self::store($db, $forms, $language);

            $resolved += $forms;
        }

        return $known + $resolved;
    }

    /**
     * Reduce a single term to its standard form.
     *
     * A standard form is defined for one word, so a phrase resolves to nothing and keeps being
     * matched as the phrase it is.
     *
     * @param   DatabaseInterface    $db          The database driver.
     * @param   DispatcherInterface  $dispatcher  The dispatcher to raise onNormalise on.
     * @param   string               $term        The term to reduce.
     * @param   string               $language    The language the term is written in.
     *
     * @return  string|null  The standard form, or null when the term is a phrase or unresolved.
     *
     * @since   0.9.0
     */
    public static function standardForm(
        DatabaseInterface $db,
        DispatcherInterface $dispatcher,
        string $term,
        string $language
    ): ?string {
        if (!self::isSingleWord($term)) {
            return null;
        }

        $word  = self::normaliseCase($term);
        $forms = self::standardForms($db, $dispatcher, [$word], $language);

        return $forms[$word] ?? null;
    }

    /**
     * Whether a term is the single word a standard form is defined for.
     *
     * Only whitespace separates words here, so a hyphenated or contracted term still counts as
     * one.
     *
     * @param   string  $term  The term to test.
     *
     * @return  boolean
     *
     * @since   0.9.0
     */
    public static function isSingleWord(string $term): bool
    {
        $term = trim((string) preg_replace('/\s+/u', ' ', $term));

        return $term !== '' && !str_contains($term, ' ');
    }

    /**
     * Split text into the distinct words worth reducing.
     *
     * @param   string  $text  The readable text to split.
     *
     * @return  array  The distinct lower-cased words.
     *
     * @since   0.9.0
     */
    public static function tokenise(string $text): array
    {
        $words = preg_split("/[^\p{L}\p{N}'-]+/u", $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return self::usableWords($words);
    }

    /**
     * Reduce a list of words to the distinct lower-cased ones long enough to carry inflection.
     *
     * @param   array  $words  The words to filter.
     *
     * @return  array  The distinct usable words.
     *
     * @since   0.9.0
     */
    private static function usableWords(array $words): array
    {
        $usable = [];

        foreach ($words as $word) {
            $word = self::normaliseCase((string) $word);

            if ($word !== '' && mb_strlen($word) >= self::MIN_WORD_LENGTH && self::isSingleWord($word)) {
                $usable[$word] = true;
            }
        }

        return array_keys($usable);
    }

    /**
     * Lower-case a word so it is stored and looked up under one spelling.
     *
     * @param   string  $word  The word to fold.
     *
     * @return  string
     *
     * @since   0.9.0
     */
    private static function normaliseCase(string $word): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $word)));
    }

    /**
     * Read the standard forms already stored for a language.
     *
     * @param   DatabaseInterface  $db        The database driver.
     * @param   array              $words     The words to look up.
     * @param   string             $language  The language the words are written in.
     *
     * @return  array  Standard form keyed by word.
     *
     * @since   0.9.0
     */
    private static function loadStored(DatabaseInterface $db, array $words, string $language): array
    {
        $query = $db->getQuery(true)
            ->select($db->quoteName(['word', 'standard_form']))
            ->from($db->quoteName('#__translations_standard_forms'))
            ->where($db->quoteName('language') . ' = :language')
            ->whereIn($db->quoteName('word'), $words, ParameterType::STRING)
            ->bind(':language', $language, ParameterType::STRING);

        $db->setQuery($query);

        $stored = [];

        foreach ($db->loadAssocList() ?: [] as $row) {
            $stored[(string) $row['word']] = (string) $row['standard_form'];
        }

        return $stored;
    }

    /**
     * Ask the "rag" plugin group to reduce the words a provider has not been asked about yet.
     *
     * The first provider that answers wins. With no provider enabled, or when the call fails,
     * this returns nothing so the caller falls back to matching the words as written.
     *
     * @param   DispatcherInterface  $dispatcher  The dispatcher to raise onNormalise on.
     * @param   array                $words       The words to reduce.
     * @param   string               $language    The language the words are written in.
     *
     * @return  array  Standard form keyed by word, for the words the provider resolved.
     *
     * @since   0.9.0
     */
    private static function askProvider(DispatcherInterface $dispatcher, array $words, string $language): array
    {
        try {
            PluginHelper::importPlugin('rag', null, true, $dispatcher);

            $event = new NormaliseEvent('onNormalise', [
                'words'    => $words,
                'language' => $language,
            ]);
            $dispatcher->dispatch('onNormalise', $event);

            foreach ((array) $event->getArgument('result', []) as $providerResult) {
                if (\is_array($providerResult)) {
                    return self::usableResult($providerResult, $words);
                }
            }
        } catch (\Throwable $e) {
            // Matching falls back to the words as written, so a failure here costs recall, not translation.
            Log::add(
                \sprintf('Could not reduce %s words to their standard form: %s', $language, $e->getMessage()),
                Log::WARNING,
                'translations'
            );
        }

        return [];
    }

    /**
     * Keep the pairs a provider returned for words that were actually asked about.
     *
     * @param   array  $result  The provider's result.
     * @param   array  $words   The words the provider was given.
     *
     * @return  array  Standard form keyed by word.
     *
     * @since   0.9.0
     */
    private static function usableResult(array $result, array $words): array
    {
        $asked  = array_flip($words);
        $usable = [];

        foreach ($result as $word => $standardForm) {
            $word         = self::normaliseCase((string) $word);
            $standardForm = self::normaliseCase((string) $standardForm);

            if ($standardForm !== '' && isset($asked[$word]) && self::isSingleWord($standardForm)) {
                $usable[$word] = $standardForm;
            }
        }

        return $usable;
    }

    /**
     * Store the standard forms a provider resolved, so the next run reads them instead.
     *
     * @param   DatabaseInterface  $db        The database driver.
     * @param   array              $forms     Standard form keyed by word.
     * @param   string             $language  The language the words are written in.
     *
     * @return  void
     *
     * @since   0.9.0
     */
    private static function store(DatabaseInterface $db, array $forms, string $language): void
    {
        $query = $db->getQuery(true)
            ->insert($db->quoteName('#__translations_standard_forms'))
            ->columns($db->quoteName(['language', 'word', 'standard_form']));

        foreach ($forms as $word => $standardForm) {
            $query->values(
                implode(
                    ',',
                    $query->bindArray(
                        [$language, (string) $word, $standardForm],
                        [ParameterType::STRING, ParameterType::STRING, ParameterType::STRING]
                    )
                )
            );
        }

        try {
            $db->setQuery($query);
            $db->execute();
        } catch (\Throwable $e) {
            // A word stored by a concurrent run trips the unique key; the forms are still usable.
            Log::add(
                \sprintf('Could not store %s standard forms: %s', $language, $e->getMessage()),
                Log::WARNING,
                'translations'
            );
        }
    }
}
