<?php

/**
 * @package     Joomla.Tests
 * @subpackage  plg_translation_claude
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Joomla\Component\Translations\Tests\Unit;

use Joomla\Plugin\Translation\Claude\Extension\Claude;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;

/**
 * What the translation plugin asks the API for, and what it accepts back.
 *
 * Everything on this boundary is text from a language model: it is not typed, not
 * guaranteed, and it is written straight into a translated article. The tests below drive
 * the two ends of the call directly rather than over the network, so the replies that must
 * be refused can actually be tried.
 *
 * @since  1.0.1
 */
final class TranslationProviderTest extends TestCase
{
    /**
     * The rules reach the model as guidance, each type under its own heading.
     *
     * The distiller writes rules for the site to read; this is where they become something
     * a translation model is told. A rule that is dropped here was learned for nothing.
     *
     * @return  void
     *
     * @since   1.0.1
     */
    public function testEachKindOfRuleReachesThePromptUnderItsOwnHeading(): void
    {
        $guidance = self::call(
            'ruleGuidance',
            [
                [
                    'terminology'  => [
                        ['source_term' => 'module', 'target_term' => 'onderdeel', 'rule_text' => ''],
                        ['source_term' => '', 'target_term' => '', 'rule_text' => 'Translate headings as questions'],
                    ],
                    'style'        => [['rule_text' => 'Address the reader as "je"']],
                    'preservation' => [['source_term' => 'Joomla', 'rule_text' => '']],
                ],
            ]
        );

        $this->assertStringContainsString('TERMINOLOGY', $guidance);
        $this->assertStringContainsString('- "module" -> "onderdeel"', $guidance);
        $this->assertStringContainsString('- Translate headings as questions', $guidance);
        $this->assertStringContainsString("STYLE:\n- Address the reader as \"je\"", $guidance);
        $this->assertStringContainsString("PRESERVE (keep these unchanged):\n- Joomla", $guidance);
    }

    /**
     * A site with nothing learned yet sends no guidance at all.
     *
     * An empty heading would be an instruction to follow no conventions, which is not what
     * having none means.
     *
     * @return  void
     *
     * @since   1.0.1
     */
    public function testNoRulesMeansNoGuidance(): void
    {
        $this->assertSame('', self::call('ruleGuidance', [[]]));
        $this->assertSame('', self::call('ruleGuidance', [['terminology' => [], 'style' => [], 'preservation' => []]]));
        $this->assertSame(
            '',
            self::call('ruleGuidance', [['terminology' => [['source_term' => 'module', 'target_term' => '']]]]),
            'A terminology rule with neither a translation nor a text says nothing'
        );
    }

    /**
     * The system prompt names both languages and carries the guidance when there is any.
     *
     * @return  void
     *
     * @since   1.0.1
     */
    public function testTheSystemPromptNamesTheLanguagesAndCarriesTheGuidance(): void
    {
        $prompt = self::call('systemPrompt', ['en-GB', 'nl-NL', ['style' => [['rule_text' => 'Be brief']]]]);

        $this->assertStringContainsString('en-GB', $prompt);
        $this->assertStringContainsString('nl-NL', $prompt);
        $this->assertStringContainsString('- Be brief', $prompt);
    }

    /**
     * The reply is pinned to the fields that were sent, as strings.
     *
     * A field the model invents has nowhere to go, and a field it drops would be saved as the
     * string "null" if the schema let it through.
     *
     * @return  void
     *
     * @since   1.0.1
     */
    public function testTheReplyIsPinnedToTheFieldsThatWereSent(): void
    {
        $format = self::call('responseFormat', [['title', 'introtext']]);

        $this->assertSame('json_schema', $format['type']);
        $this->assertSame(['title', 'introtext'], $format['schema']['required']);
        $this->assertSame(
            ['title' => ['type' => 'string'], 'introtext' => ['type' => 'string']],
            $format['schema']['properties']
        );
        $this->assertFalse($format['schema']['additionalProperties']);
    }

    /**
     * Strings go out numbered, each with its key alongside as context.
     *
     * The reply then repeats only the numbers, not a long language constant per string, and the
     * reply is the part of a request that costs most.
     *
     * @return  void
     *
     * @since   1.2.0
     */
    public function testStringsAreSentNumberedWithTheirKeyAsContext(): void
    {
        $this->assertSame(
            [
                '1' => ['key' => 'COM_CONTENT_SAVE_SUCCESS', 'text' => 'Article saved.'],
                '2' => ['key' => 'title', 'text' => 'Welcome'],
            ],
            self::call('numberedStrings', [['COM_CONTENT_SAVE_SUCCESS' => 'Article saved.', 'title' => 'Welcome']])
        );
    }

    /**
     * Translations come back under the keys the strings were sent with, and an unanswered
     * number is left out rather than shifting the others.
     *
     * @return  void
     *
     * @since   1.2.0
     */
    public function testANumberedReplyIsPutBackUnderTheCallersKeys(): void
    {
        $this->assertSame(
            ['COM_A' => 'Eerste', 'COM_C' => 'Derde'],
            self::call('keyedTranslation', [[1 => 'Eerste', 3 => 'Derde'], ['COM_A', 'COM_B', 'COM_C']])
        );
    }

    /**
     * A numbered reply round-trips: the numbers the schema asks for are the ones read back.
     *
     * @return  void
     *
     * @since   1.2.0
     */
    public function testANumberedReplyIsReadThroughTheSchemaItWasAskedFor(): void
    {
        $format = self::call('responseFormat', [['1', '2']]);
        $read   = self::parseTranslation(self::reply(['1' => 'Opgeslagen', '2' => 'Welkom']), ['1', '2']);

        $this->assertSame(['1', '2'], $format['schema']['required']);
        $this->assertSame(['T1' => 'Opgeslagen', 'T2' => 'Welkom'], self::call('keyedTranslation', [$read, ['T1', 'T2']]));
    }

    /**
     * The effort level goes into the request, except for a model that does not take one.
     *
     * @return  void
     *
     * @since   1.2.0
     */
    public function testTheEffortLevelIsSentToModelsThatTakeIt(): void
    {
        $format = ['type' => 'json_schema'];

        $this->assertSame(['format' => $format, 'effort' => 'low'], self::call('outputConfigFor', [$format, 'claude-sonnet-5', 'low']));
        $this->assertSame(['format' => $format], self::call('outputConfigFor', [$format, 'claude-haiku-4-5', 'low']));
        $this->assertSame(['format' => $format], self::call('outputConfigFor', [$format, 'claude-sonnet-5', 'extreme']));
    }

    /**
     * A translation is read out of the reply and kept to the fields that were asked about.
     *
     * @return  void
     *
     * @since   1.0.1
     */
    public function testATranslationIsReadOutOfTheReply(): void
    {
        $translated = self::parseTranslation(
            self::reply(['title' => 'De module', 'introtext' => '<p>Inleiding</p>', 'unasked' => 'x']),
            ['title', 'introtext']
        );

        $this->assertSame(['title' => 'De module', 'introtext' => '<p>Inleiding</p>'], $translated);
    }

    /**
     * A field the model answered with something other than text is left out.
     *
     * The draft keeps the value it already had, which is the untranslated one - visibly
     * wrong, where a null written into the article would be silently empty.
     *
     * @return  void
     *
     * @since   1.0.1
     */
    public function testAFieldAnsweredWithSomethingOtherThanTextIsLeftOut(): void
    {
        $translated = self::parseTranslation(
            self::reply(['title' => 'De module', 'introtext' => null, 'metakey' => ['a', 'b']]),
            ['title', 'introtext', 'metakey']
        );

        $this->assertSame(['title' => 'De module'], $translated);
    }

    /**
     * Text the model wrote before its answer does not stop the answer being found.
     *
     * @return  void
     *
     * @since   1.0.1
     */
    public function testAReplyThatOpensWithAnotherBlockIsStillRead(): void
    {
        $body = json_encode(
            [
                'content' => [
                    ['type' => 'thinking', 'thinking' => 'Considering the terminology'],
                    ['type' => 'text', 'text' => '{"title": "De module"}'],
                ],
            ]
        );

        $this->assertSame(['title' => 'De module'], self::parseTranslation($body, ['title']));
    }

    /**
     * A request carrying one part of a long item is told so, and what the item is about.
     *
     * Without the first of these the model rounds off the fragment it was given: it closes tags
     * it found open and finishes the sentence the part ends in, and both land in the article
     * once the parts are joined.
     *
     * @return  void
     *
     * @since   1.4.0
     */
    public function testAPartOfALongItemIsToldWhatItIsPartOf(): void
    {
        $prompt = self::call(
            'systemPrompt',
            ['en-GB', 'nl-NL', [], "Title: Security HTTP Headers\n\nOutline:\n- Referrer-Policy", []]
        );

        $this->assertStringContainsString('one part of a longer document', $prompt);
        $this->assertStringContainsString('do not open, close', $prompt);
        $this->assertStringContainsString('Title: Security HTTP Headers', $prompt);
        $this->assertStringContainsString('- Referrer-Policy', $prompt);
    }

    /**
     * An item sent in one request is told none of that, so a normal article's prompt is unchanged.
     *
     * @return  void
     *
     * @since   1.4.0
     */
    public function testAnItemSentWholeIsNotToldItIsAFragment(): void
    {
        $prompt = self::call('systemPrompt', ['en-GB', 'nl-NL', [], '', []]);

        $this->assertStringNotContainsString('one part of a longer document', $prompt);
        $this->assertStringNotContainsString('THE DOCUMENT THIS IS PART OF', $prompt);
    }

    /**
     * The terms an item's earlier parts were translated with reach the prompt for the next part.
     *
     * This is what keeps a word translated the same way through an article that took several
     * requests, which is the whole reason the terms are carried at all.
     *
     * @return  void
     *
     * @since   1.4.0
     */
    public function testTheTermsEarlierPartsUsedReachTheNextPartsPrompt(): void
    {
        $prompt = self::call(
            'systemPrompt',
            ['en-GB', 'nl-NL', [], 'Title: Headers', ['header' => 'kopregel', 'policy' => 'beleid']]
        );

        $this->assertStringContainsString('ALREADY USED EARLIER IN THIS DOCUMENT OR ON THIS SITE', $prompt);
        $this->assertStringContainsString('- "header" -> "kopregel"', $prompt);
        $this->assertStringContainsString('- "policy" -> "beleid"', $prompt);
    }

    /**
     * No terms yet means no heading about them, rather than an empty one.
     *
     * @return  void
     *
     * @since   1.4.0
     */
    public function testNoTermsCarriedMeansNoHeadingAboutThem(): void
    {
        $this->assertSame('', self::call('terminologyGuidance', [[]]));
        $this->assertSame(
            '',
            self::call('terminologyGuidance', [['header' => '  ']]),
            'A term with no translation says nothing'
        );
    }

    /**
     * Only a request that carries part of a document asks for the terms used back.
     *
     * Every string asked for is paid for in the reply, so an item sent in one request - and
     * every language string the seed task sends - must not be asked for a glossary it has no
     * use for.
     *
     * @return  void
     *
     * @since   1.4.0
     */
    public function testOnlyAPartOfADocumentAsksForTheTermsUsed(): void
    {
        $whole = self::call('responseFormat', [['1', '2'], false]);
        $part  = self::call('responseFormat', [['1', '2'], true]);

        $this->assertSame(['1', '2'], $whole['schema']['required']);
        $this->assertArrayNotHasKey('glossary', $whole['schema']['properties']);

        $this->assertSame(['1', '2', 'glossary'], $part['schema']['required']);
        $this->assertSame('array', $part['schema']['properties']['glossary']['type']);
        $this->assertSame(
            ['source', 'target'],
            $part['schema']['properties']['glossary']['items']['required']
        );
    }

    /**
     * The terms the model chose are read out of the reply, and the translation is untouched by them.
     *
     * @return  void
     *
     * @since   1.4.0
     */
    public function testTheTermsTheModelChoseAreReadOutOfTheReply(): void
    {
        $answer = self::call(
            'parseTranslation',
            [
                self::reply(
                    [
                        '1'        => 'Kopregels',
                        'glossary' => [
                            ['source' => 'header', 'target' => 'kopregel'],
                            ['source' => 'policy', 'target' => 'beleid'],
                        ],
                    ]
                ),
                ['1'],
            ]
        );

        $this->assertSame(['1' => 'Kopregels'], $answer['strings'], 'The glossary is not mistaken for a translation');
        $this->assertSame(['header' => 'kopregel', 'policy' => 'beleid'], $answer['glossary']);
    }

    /**
     * A term the model only half answered is dropped rather than carried on as half a rule.
     *
     * @return  void
     *
     * @since   1.4.0
     */
    public function testAHalfAnsweredTermIsDropped(): void
    {
        $answer = self::call(
            'parseTranslation',
            [
                self::reply(
                    [
                        '1'        => 'Kopregels',
                        'glossary' => [
                            ['source' => 'header', 'target' => ''],
                            ['target' => 'beleid'],
                            'not a pair at all',
                            ['source' => 'policy', 'target' => 'beleid'],
                        ],
                    ]
                ),
                ['1'],
            ]
        );

        $this->assertSame(['policy' => 'beleid'], $answer['glossary']);
    }

    /**
     * A reply with no glossary in it carries none, so nothing is carried to the next part.
     *
     * @return  void
     *
     * @since   1.4.0
     */
    public function testAReplyWithoutAGlossaryCarriesNone(): void
    {
        $answer = self::call('parseTranslation', [self::reply(['1' => 'Kopregels']), ['1']]);

        $this->assertSame([], $answer['glossary']);
    }

    /**
     * A reply that is not a translation is refused, and says which kind of failure it was.
     *
     * Saving any of these would put English, or half a sentence, into the translated article
     * and mark it done.
     *
     * @param   string  $body      The reply to read.
     * @param   string  $expected  Text the error has to carry.
     *
     * @return  void
     *
     * @dataProvider  unusableReplies
     *
     * @since   1.0.1
     */
    public function testAReplyThatIsNotATranslationIsRefused(string $body, string $expected): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage($expected);

        self::parseTranslation($body, ['title']);
    }

    /**
     * Replies that must not be saved, with what the translator has to be told about each.
     *
     * @return  array<string, array{0: string, 1: string}>
     *
     * @since   1.0.1
     */
    public static function unusableReplies(): array
    {
        return [
            'refused'            => ['{"stop_reason": "refusal", "content": []}', 'refused'],
            'cut off'            => [
                '{"stop_reason": "max_tokens", "content": [{"type": "text", "text": "{\"title\": \"De mod"}]}',
                'too long',
            ],
            'prose, not JSON'    => [
                '{"content": [{"type": "text", "text": "Sure! Here is your translation:"}]}',
                'unreadable',
            ],
            'no content at all'  => ['{"content": []}', 'unreadable'],
            'not even a reply'   => ['<html>502 Bad Gateway</html>', 'unreadable'],
            'none of the fields' => ['{"content": [{"type": "text", "text": "{\"other\": \"x\"}"}]}', 'no usable'],
        ];
    }

    /**
     * An API error reaches the translator as the API's own message, not as a status code.
     *
     * "Your credit balance is too low" is something the user can act on; "HTTP 400" is not.
     *
     * @return  void
     *
     * @since   1.0.1
     */
    public function testAnApiErrorKeepsTheApisOwnMessage(): void
    {
        $this->assertStringContainsString(
            'Your credit balance is too low',
            self::call('errorMessage', [400, '{"error": {"message": "Your credit balance is too low"}}'])
        );

        $this->assertStringContainsString(
            '502 Bad Gateway',
            self::call('errorMessage', [502, '502 Bad Gateway']),
            'A reply that is not the API speaking is passed on as it came'
        );
    }

    /**
     * An API reply carrying a translated JSON object.
     *
     * @param   array  $strings  The strings the model answered with.
     *
     * @return  string  The response body.
     *
     * @since   1.0.1
     */
    private static function reply(array $strings): string
    {
        return json_encode(['content' => [['type' => 'text', 'text' => json_encode($strings)]]]);
    }

    /**
     * Read a translation out of an API reply.
     *
     * @param   string  $body          The response body.
     * @param   array   $expectedKeys  The fields that were sent for translation.
     *
     * @return  array  The translated strings.
     *
     * @since   1.0.1
     */
    private static function parseTranslation(string $body, array $expectedKeys): array
    {
        return self::call('parseTranslation', [$body, $expectedKeys])['strings'];
    }

    /**
     * Call one of the plugin's own methods.
     *
     * They are private because the plugin answers an event and nothing else, and they are
     * reached here directly because the alternative is a stand-in for the CMS plugin
     * machinery - which would be the thing under test instead of the plugin. None of them
     * reads the plugin's state, so an instance that was never constructed is enough.
     *
     * @param   string  $method     The method to call.
     * @param   array   $arguments  The arguments to call it with.
     *
     * @return  mixed  Whatever the method returns.
     *
     * @since   1.0.1
     */
    private static function call(string $method, array $arguments)
    {
        $plugin     = (new ReflectionClass(Claude::class))->newInstanceWithoutConstructor();
        $reflection = new ReflectionMethod(Claude::class, $method);

        return $reflection->invokeArgs($plugin, $arguments);
    }
}
