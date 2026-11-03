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

use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use Joomla\Event\DispatcherInterface;

/**
 * Retrieves the distilled rules relevant to an item, to steer its translation.
 *
 * Reads the published rules for the target language, then keeps the ones whose term or search
 * keyword appears in the item's source strings; style rules apply to the whole language. The
 * result is capped so the prompt stays bounded.
 *
 * @since  0.7.0
 */
class RuleRetriever
{
    /**
     * The rule state that marks a rule as published and usable for translation.
     *
     * @var    integer
     * @since  0.7.0
     */
    private const STATE_PUBLISHED = 1;

    /**
     * The most rules returned in total, so an item's prompt stays bounded.
     *
     * @var    integer
     * @since  0.7.0
     */
    public const MAX_RULES = 30;

    /**
     * The most style rules returned, since they apply to the whole language rather than a term.
     *
     * @var    integer
     * @since  0.7.0
     */
    public const MAX_STYLE_RULES = 10;

    /**
     * The shortest search keyword worth matching, so noise words do not pull in rules.
     *
     * @var    integer
     * @since  0.7.0
     */
    private const MIN_KEYWORD_LENGTH = 3;

    /**
     * A rule whose term is in the item's title.
     *
     * @var    integer
     * @since  3.0.0
     */
    private const IN_TITLE = 0;

    /**
     * A rule whose term is in the text.
     *
     * @var    integer
     * @since  3.0.0
     */
    private const IN_TEXT = 1;

    /**
     * A rule that applies only by one of its search keywords.
     *
     * @var    integer
     * @since  3.0.0
     */
    private const BY_KEYWORD = 2;

    /**
     * Retrieve the published rules relevant to an item's source strings, grouped by rule type.
     *
     * @param   DatabaseInterface    $db              The database driver.
     * @param   DispatcherInterface  $dispatcher      The dispatcher used to reduce words to their standard form.
     * @param   array                $sourceStrings   The item's source strings, keyed by field.
     * @param   string               $sourceLanguage  The source language code.
     * @param   string               $targetLanguage  The target language code.
     * @param   string               $title           The title of the item the strings belong to, which a
     *                                                part of a long article does not hold; empty for none.
     *
     * @return  array  Selected rules keyed by rule type ('terminology', 'style', 'preservation').
     *
     * @since   0.7.0
     */
    public static function retrieve(
        DatabaseInterface $db,
        DispatcherInterface $dispatcher,
        array $sourceStrings,
        string $sourceLanguage,
        string $targetLanguage,
        string $title = ''
    ): array {
        $rules = self::loadPublishedRules($db, $targetLanguage);
        $text  = self::plainText($sourceStrings);
        $title = self::plainText([$title]);

        // Reducing the text only changes which rules match when a rule has a standard form to
        // match against, so a language whose rules have none is spared the work entirely.
        $standardForms = self::hasStandardForm($rules)
            ? WordNormaliser::standardForms($db, $dispatcher, WordNormaliser::tokenise($text . ' ' . $title), $sourceLanguage)
            : [];
        $titleForms = array_intersect_key($standardForms, array_flip(WordNormaliser::tokenise($title)));

        return self::selectRelevant($rules, $text, $standardForms, $title, $titleForms);
    }

    /**
     * Whether any of the rules carries a term in standard form.
     *
     * @param   array  $rules  The candidate rules.
     *
     * @return  boolean
     *
     * @since   0.7.0
     */
    public static function hasStandardForm(array $rules): bool
    {
        foreach ($rules as $rule) {
            if ((string) ($rule['source_term_standard'] ?? '') !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * Flatten source-language strings into one plain-text haystack to match rules against.
     *
     * Removes HTML, decodes entities and collapses whitespace, so a term is matched against the
     * readable text rather than markup.
     *
     * @param   array  $sourceStrings  The source strings to flatten.
     *
     * @return  string  The readable text on one line.
     *
     * @since   0.7.0
     */
    public static function plainText(array $sourceStrings): string
    {
        $joined  = implode(' ', array_map('strval', $sourceStrings));
        $decoded = html_entity_decode(strip_tags($joined), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace('/\s+/u', ' ', $decoded));
    }

    /**
     * Keep the rules that apply to the text, grouped by type and capped.
     *
     * Style rules apply to the whole language and are taken by confidence, up to their own cap.
     * Terminology and preservation rules apply only when their term or a search keyword appears
     * in the text, and fill the rest of the total cap by relevance: first the rules whose term is
     * in the item's title, then those whose term is in the text, then those matched by a keyword
     * only; within each, the rules whose term occurs more often first, and then by confidence.
     * Taken by confidence alone, the article "Privacy Workflow" on guide.joomla.org got 20 rules,
     * none of them for "Privacy", while its menu item got 9 that all were.
     *
     * @param   array   $rules          The candidate rules, ordered by confidence, descending.
     * @param   string  $text           The item's readable text.
     * @param   array   $standardForms  Standard form keyed by the text's words, where known.
     * @param   string  $title          The item's readable title, empty for none.
     * @param   array   $titleForms     Standard form keyed by the title's words, where known.
     *
     * @return  array  Selected rules keyed by rule type.
     *
     * @since   0.7.0
     */
    public static function selectRelevant(
        array $rules,
        string $text,
        array $standardForms = [],
        string $title = '',
        array $titleForms = []
    ): array {
        $selected = ['terminology' => [], 'style' => [], 'preservation' => []];
        $matched  = [];

        foreach ($rules as $order => $rule) {
            $type = (string) ($rule['rule_type'] ?? '');

            if (!isset($selected[$type])) {
                continue;
            }

            if ($type === 'style') {
                if (\count($selected['style']) < self::MAX_STYLE_RULES) {
                    $selected['style'][] = self::forPrompt($rule);
                }

                continue;
            }

            $relevance = self::relevance($rule, $text, $standardForms, $title, $titleForms);

            if ($relevance !== null) {
                $matched[] = [$relevance, -self::occurrences($rule, $text, $standardForms), $order, $rule];
            }
        }

        // Ranked by relevance, then by how often the term occurs, then in the order of confidence.
        usort($matched, static fn (array $one, array $other): int => \array_slice($one, 0, 3) <=> \array_slice($other, 0, 3));

        foreach (\array_slice($matched, 0, max(0, self::MAX_RULES - \count($selected['style']))) as $match) {
            $selected[(string) $match[3]['rule_type']][] = self::forPrompt($match[3]);
        }

        return $selected;
    }

    /**
     * Whether a terminology or preservation rule applies to the text.
     *
     * True when the rule's source term, or one of its search keywords, appears in the text as a
     * whole word. A rule whose term is stored in its standard form also matches the text's
     * inflected forms of that word, so a rule for a singular noun applies to its plural.
     *
     * @param   array   $rule           The rule row.
     * @param   string  $text           The item's readable text.
     * @param   array   $standardForms  Standard form keyed by the text's words, where known.
     *
     * @return  boolean
     *
     * @since   0.7.0
     */
    public static function appliesToText(array $rule, string $text, array $standardForms = []): bool
    {
        return self::relevance($rule, $text, $standardForms) !== null;
    }

    /**
     * How a terminology or preservation rule applies: its term in the title, its term in the
     * text, or a keyword only; null when it does not apply.
     *
     * A part of a long article does not hold the title, so a rule for a term in the title counts
     * for every part, whether its term is in that part or not.
     *
     * @param   array   $rule           The rule row.
     * @param   string  $text           The item's readable text.
     * @param   array   $standardForms  Standard form keyed by the text's words, where known.
     * @param   string  $title          The item's readable title, empty for none.
     * @param   array   $titleForms     Standard form keyed by the title's words, where known.
     *
     * @return  integer|null  IN_TITLE, IN_TEXT or BY_KEYWORD.
     *
     * @since   3.0.0
     */
    private static function relevance(array $rule, string $text, array $standardForms, string $title = '', array $titleForms = []): ?int
    {
        $term = (string) ($rule['source_term'] ?? '');

        if ($title !== '' && (self::matchesStandardForm($rule, $titleForms) || self::containsWord($title, $term))) {
            return self::IN_TITLE;
        }

        if (self::matchesStandardForm($rule, $standardForms) || self::containsWord($text, $term)) {
            return self::IN_TEXT;
        }

        foreach (preg_split('/[\s,;]+/u', (string) ($rule['search_keywords'] ?? '')) ?: [] as $keyword) {
            if (mb_strlen($keyword) >= self::MIN_KEYWORD_LENGTH && self::containsWord($text, $keyword)) {
                return self::BY_KEYWORD;
            }
        }

        return null;
    }

    /**
     * How often a rule's term occurs in the text: as a whole word, or the number of the text's
     * word forms that reduce to it, whichever is more.
     *
     * @param   array   $rule           The rule row.
     * @param   string  $text           The item's readable text.
     * @param   array   $standardForms  Standard form keyed by the text's words, where known.
     *
     * @return  integer
     *
     * @since   3.0.0
     */
    private static function occurrences(array $rule, string $text, array $standardForms): int
    {
        $term = trim((string) preg_replace('/\s+/u', ' ', (string) ($rule['source_term'] ?? '')));
        $seen = $term === '' ? 0 : (int) preg_match_all('/(?<![\p{L}\p{N}])' . preg_quote($term, '/') . '(?![\p{L}\p{N}])/iu', $text);

        $standard = (string) ($rule['source_term_standard'] ?? '');
        $forms    = $standard === '' ? 0 : \count(array_keys($standardForms, $standard, true));

        return max($seen, $forms);
    }

    /**
     * The fields of a rule a provider is given.
     *
     * @param   array  $rule  The rule row.
     *
     * @return  array{source_term: string, target_term: string, rule_text: string}
     *
     * @since   3.0.0
     */
    private static function forPrompt(array $rule): array
    {
        return [
            'source_term' => (string) ($rule['source_term'] ?? ''),
            'target_term' => (string) ($rule['target_term'] ?? ''),
            'rule_text'   => (string) ($rule['rule_text'] ?? ''),
        ];
    }

    /**
     * Whether the rule's term and one of the text's words share a standard form.
     *
     * Both sides are reduced by the same provider, which is what lets a rule apply to the
     * inflected forms of its term. A rule stored without a standard form, or a text whose
     * words could not be reduced, does not match here and falls back to the whole-word
     * comparison.
     *
     * @param   array  $rule           The rule row.
     * @param   array  $standardForms  Standard form keyed by the text's words, where known.
     *
     * @return  boolean
     *
     * @since   0.7.0
     */
    private static function matchesStandardForm(array $rule, array $standardForms): bool
    {
        $term = (string) ($rule['source_term_standard'] ?? '');

        return $term !== '' && \in_array($term, $standardForms, true);
    }

    /**
     * Whether the needle appears in the text as a whole word or phrase, case-insensitively.
     *
     * Matches on word boundaries, so a term does not match inside a longer word.
     *
     * @param   string  $text    The text to search.
     * @param   string  $needle  The word or phrase to find.
     *
     * @return  boolean
     *
     * @since   0.7.0
     */
    public static function containsWord(string $text, string $needle): bool
    {
        $needle = trim((string) preg_replace('/\s+/u', ' ', $needle));

        if ($needle === '') {
            return false;
        }

        return (bool) preg_match('/(?<![\p{L}\p{N}])' . preg_quote($needle, '/') . '(?![\p{L}\p{N}])/iu', $text);
    }

    /**
     * Load every published rule for a language, ordered by confidence, descending.
     *
     * @param   DatabaseInterface  $db              The database driver.
     * @param   string             $targetLanguage  The target language code.
     *
     * @return  array  The published rule rows for the language.
     *
     * @since   0.7.0
     */
    private static function loadPublishedRules(DatabaseInterface $db, string $targetLanguage): array
    {
        $state = self::STATE_PUBLISHED;
        $query = $db->getQuery(true)
            ->select(
                $db->quoteName(
                    ['rule_type', 'rule_text', 'source_term', 'source_term_standard', 'target_term', 'search_keywords']
                )
            )
            ->from($db->quoteName('#__translations_rules'))
            ->where($db->quoteName('target_language') . ' = :lang')
            ->where($db->quoteName('state') . ' = :state')
            ->order(
                [
                    $db->quoteName('confidence') . ' DESC',
                    $db->quoteName('id') . ' DESC',
                ]
            )
            ->bind(':lang', $targetLanguage, ParameterType::STRING)
            ->bind(':state', $state, ParameterType::INTEGER);
        $db->setQuery($query);

        return $db->loadAssocList() ?: [];
    }
}
