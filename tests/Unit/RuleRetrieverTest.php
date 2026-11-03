<?php

/**
 * @package     Joomla.Tests
 * @subpackage  com_translations
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Joomla\Component\Translations\Tests\Unit;

use Joomla\Component\Translations\Administrator\Helper\RuleRetriever;
use PHPUnit\Framework\TestCase;

/**
 * Which of a language's rules are handed to the translation provider for one item.
 *
 * This is the retrieval half of the project: a rule that is not selected here never
 * reaches the prompt, and a rule that is selected too eagerly spends prompt on an item it
 * has nothing to say about. Both failures are silent - the translation still comes back,
 * only worse - so they are worth a test.
 *
 * @since  1.0.1
 */
final class RuleRetrieverTest extends TestCase
{
    /**
     * Source strings are matched as readable text, not as the markup they are stored in.
     *
     * @return  void
     *
     * @since   1.0.1
     */
    public function testPlainTextReducesStoredMarkupToReadableText(): void
    {
        $text = RuleRetriever::plainText(
            [
                'title'     => 'Joomla   &amp; friends',
                'introtext' => "<p>A <strong>module</strong>\n  position</p>",
            ]
        );

        $this->assertSame('Joomla & friends A module position', $text);
    }

    /**
     * Fields that hold no text still have to flatten to something matchable.
     *
     * @return  void
     *
     * @since   1.0.1
     */
    public function testPlainTextOfNothingIsEmpty(): void
    {
        $this->assertSame('', RuleRetriever::plainText([]));
        $this->assertSame('', RuleRetriever::plainText(['title' => '', 'introtext' => '  ']));
    }

    /**
     * A term matches the whole word it was written for and nothing else.
     *
     * The case that matters is the negative one: matching a substring would apply a rule
     * about "article" to "particle", and nothing downstream would notice.
     *
     * @param   string   $text      The item's readable text.
     * @param   string   $needle    The term to look for.
     * @param   boolean  $expected  Whether the term should be found.
     *
     * @return  void
     *
     * @dataProvider  wordMatches
     *
     * @since   1.0.1
     */
    public function testContainsWordMatchesWholeWordsOnly(string $text, string $needle, bool $expected): void
    {
        $this->assertSame($expected, RuleRetriever::containsWord($text, $needle));
    }

    /**
     * Text, term and whether the term should be found in it.
     *
     * @return  array<string, array{0: string, 1: string, 2: boolean}>
     *
     * @since   1.0.1
     */
    public static function wordMatches(): array
    {
        return [
            'whole word'                => ['Every article has a title', 'article', true],
            'ignores case'              => ['Every Article has a title', 'article', true],
            'phrase'                    => ['Open the menu item now', 'menu item', true],
            'phrase spacing is folded'  => ['Open the menu item now', "menu   item", true],
            'not inside a longer word'  => ['Physics calls it a particle', 'article', false],
            'not a prefix'              => ['There are articles here', 'article', false],
            'punctuation is a boundary' => ['The article, and more', 'article', true],
            'accented letters bound'    => ['Een categorieën lijst', 'categorie', false],
            'absent'                    => ['Nothing to see', 'article', false],
            'empty term matches nothing' => ['Nothing to see', '   ', false],
        ];
    }

    /**
     * A rule applies when its term, or one of its keywords, is in the text.
     *
     * @return  void
     *
     * @since   1.0.1
     */
    public function testRuleAppliesOnItsTermOrAKeyword(): void
    {
        $text = 'The module position holds a banner';

        $this->assertTrue(
            RuleRetriever::appliesToText(['source_term' => 'module'], $text),
            'A rule whose term is in the text applies'
        );

        $this->assertTrue(
            RuleRetriever::appliesToText(['source_term' => 'plugin', 'search_keywords' => 'template, banner'], $text),
            'A rule applies on a search keyword when its term is absent'
        );

        $this->assertFalse(
            RuleRetriever::appliesToText(['source_term' => 'plugin', 'search_keywords' => 'template, layout'], $text),
            'A rule with neither its term nor a keyword in the text does not apply'
        );
    }

    /**
     * A keyword too short to be distinctive does not pull a rule in.
     *
     * Two-letter words are function words in most languages, so a rule keyed on one would
     * apply to nearly every item.
     *
     * @return  void
     *
     * @since   1.0.1
     */
    public function testShortKeywordsDoNotPullInARule(): void
    {
        $rule = ['source_term' => 'plugin', 'search_keywords' => 'de of'];

        $this->assertFalse(RuleRetriever::appliesToText($rule, 'Het einde of het begin de tekst'));
    }

    /**
     * A rule stored in standard form applies to the inflected forms of its term.
     *
     * That is the whole point of storing the standard form: a rule written for "artikel"
     * has to reach a text that says "artikelen", which no whole-word comparison would do.
     *
     * @return  void
     *
     * @since   1.0.1
     */
    public function testStandardFormReachesInflectedWords(): void
    {
        $rule          = ['source_term' => 'artikel', 'source_term_standard' => 'artikel'];
        $text          = 'De artikelen staan klaar';
        $standardForms = ['artikelen' => 'artikel', 'staan' => 'staan', 'klaar' => 'klaar'];

        $this->assertTrue(RuleRetriever::appliesToText($rule, $text, $standardForms));

        $this->assertFalse(
            RuleRetriever::appliesToText($rule, $text),
            'Without the reduced words, the rule falls back to matching the term as written'
        );

        $this->assertFalse(
            RuleRetriever::appliesToText(['source_term' => 'artikel'], $text, $standardForms),
            'A rule with no standard form of its own cannot match one'
        );
    }

    /**
     * Whether the words of a text need reducing at all is decided by the rules.
     *
     * Reducing words costs a provider call per batch, so a language whose rules carry no
     * standard form must not pay for it.
     *
     * @return  void
     *
     * @since   1.0.1
     */
    public function testStandardFormsAreOnlyNeededWhenARuleHasOne(): void
    {
        $this->assertFalse(RuleRetriever::hasStandardForm([]));
        $this->assertFalse(RuleRetriever::hasStandardForm([['source_term_standard' => ''], ['rule_type' => 'style']]));
        $this->assertTrue(RuleRetriever::hasStandardForm([['source_term_standard' => ''], ['source_term_standard' => 'artikel']]));
    }

    /**
     * Selection groups by type, keeps only what the provider is given, and drops the rest.
     *
     * @return  void
     *
     * @since   1.0.1
     */
    public function testSelectionGroupsByTypeAndKeepsOnlyThePromptFields(): void
    {
        $selected = RuleRetriever::selectRelevant(
            [
                [
                    'rule_type'       => 'terminology',
                    'source_term'     => 'module',
                    'target_term'     => 'module',
                    'rule_text'       => 'Keep the Joomla term',
                    'search_keywords' => 'position',
                    'confidence'      => 0.9,
                    'id'              => 7,
                ],
                ['rule_type' => 'style', 'rule_text' => 'Address the reader as "je"'],
                ['rule_type' => 'preservation', 'source_term' => 'Joomla'],
                ['rule_type' => 'terminology', 'source_term' => 'plugin', 'target_term' => 'plug-in'],
            ],
            'The module is called Joomla'
        );

        $this->assertSame(['terminology', 'style', 'preservation'], array_keys($selected));
        $this->assertCount(1, $selected['terminology'], 'The rule whose term is absent is left out');
        $this->assertCount(1, $selected['style']);
        $this->assertCount(1, $selected['preservation']);

        // Confidence and the row id steer the selection but say nothing to the provider, so
        // they are not passed on.
        $this->assertSame(
            ['source_term' => 'module', 'target_term' => 'module', 'rule_text' => 'Keep the Joomla term'],
            $selected['terminology'][0]
        );
    }

    /**
     * A style rule applies to the language rather than to a term, so the text cannot select it.
     *
     * @return  void
     *
     * @since   1.0.1
     */
    public function testStyleRulesApplyWithoutMatchingTheText(): void
    {
        $selected = RuleRetriever::selectRelevant([['rule_type' => 'style', 'rule_text' => 'Be brief']], '');

        $this->assertCount(1, $selected['style']);
    }

    /**
     * A rule type the component does not know is dropped rather than passed on.
     *
     * @return  void
     *
     * @since   1.0.1
     */
    public function testAnUnknownRuleTypeIsDropped(): void
    {
        $selected = RuleRetriever::selectRelevant(
            [['rule_type' => 'tone', 'source_term' => 'module'], ['rule_type' => '', 'source_term' => 'module']],
            'The module is here'
        );

        $this->assertSame(['terminology' => [], 'style' => [], 'preservation' => []], $selected);
    }

    /**
     * The caps bound the prompt, and they keep the rules the query ranked highest.
     *
     * Rules arrive ordered by confidence, so "the first ones" is what the caps have to keep.
     *
     * @return  void
     *
     * @since   1.0.1
     */
    public function testTheCapsKeepTheHighestRankedRules(): void
    {
        $rules = [];

        // 15 style rules, more than the 10 a prompt takes.
        for ($i = 1; $i <= 15; $i++) {
            $rules[] = ['rule_type' => 'style', 'rule_text' => 'style ' . $i];
        }

        // 40 terminology rules that all match, more than the 30 rules a prompt takes in total.
        for ($i = 1; $i <= 40; $i++) {
            $rules[] = ['rule_type' => 'terminology', 'source_term' => 'module', 'target_term' => 'term ' . $i];
        }

        $selected = RuleRetriever::selectRelevant($rules, 'The module is here');

        $this->assertCount(10, $selected['style'], 'Style rules are capped on their own');
        $this->assertCount(20, $selected['terminology'], 'The total cap leaves room for what is left');
        $this->assertSame('style 1', $selected['style'][0]['rule_text']);
        $this->assertSame('term 1', $selected['terminology'][0]['target_term']);
    }

    /**
     * The places go to the rules for the title first, then to terms in the text, then to keywords;
     * within a rank to the term that occurs most, then by confidence.
     *
     * The article "Privacy Workflow" got 20 rules by confidence, none of them for "Privacy".
     *
     * @return  void
     *
     * @since   3.0.0
     */
    public function testRulesAreRankedByRelevanceTitleFirst(): void
    {
        $rules = [];

        // 30 high-confidence rules matched only by a keyword.
        for ($i = 1; $i <= 30; $i++) {
            $rules[] = ['rule_type' => 'terminology', 'source_term' => 'unrelated ' . $i, 'target_term' => 'keyword ' . $i, 'search_keywords' => 'request'];
        }

        $rules[] = ['rule_type' => 'terminology', 'source_term' => 'consent', 'target_term' => 'toestemming'];
        $rules[] = ['rule_type' => 'terminology', 'source_term' => 'request', 'target_term' => 'verzoek'];
        $rules[] = ['rule_type' => 'preservation', 'source_term' => 'Workflow', 'rule_text' => 'Keep Workflow'];
        $rules[] = ['rule_type' => 'terminology', 'source_term' => 'privacy', 'target_term' => 'privacy-instellingen'];
        $rules[] = ['rule_type' => 'style', 'rule_text' => 'Be brief'];

        $selected = RuleRetriever::selectRelevant(
            $rules,
            'A request asks for consent. Each request is logged, and the request is answered.',
            [],
            'Privacy Workflow'
        );

        $this->assertCount(1, $selected['style']);
        $this->assertSame(
            ['privacy-instellingen', 'verzoek', 'toestemming', 'keyword 1'],
            \array_slice(array_column($selected['terminology'], 'target_term'), 0, 4),
            'Title term first, then the term met three times, then once, then keyword matches by confidence'
        );
        $this->assertSame('Keep Workflow', $selected['preservation'][0]['rule_text'], 'A title term in a part without the title still counts');
        $this->assertCount(29, array_merge($selected['terminology'], $selected['preservation']), 'The total cap leaves 29 places beside the style rule');
        $this->assertNotContains('keyword 27', array_column($selected['terminology'], 'target_term'), 'Keyword matches are the ones left out');
    }

    /**
     * A term in its standard form counts in the title through the title's word forms.
     *
     * @return  void
     *
     * @since   3.0.0
     */
    public function testATermInTheTitleIsFoundByItsStandardForm(): void
    {
        $selected = RuleRetriever::selectRelevant(
            [
                ['rule_type' => 'terminology', 'source_term' => 'menu', 'target_term' => 'menu'],
                ['rule_type' => 'terminology', 'source_term' => 'article', 'source_term_standard' => 'article', 'target_term' => 'artikel'],
            ],
            'The menu shows the menu.',
            ['menu' => 'menu', 'articles' => 'article'],
            'Articles',
            ['articles' => 'article']
        );

        $this->assertSame(['artikel', 'menu'], array_column($selected['terminology'], 'target_term'));
    }
}
