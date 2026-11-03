<?php

/**
 * @package     Joomla.Tests
 * @subpackage  plg_task_translationsseed
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Joomla\Component\Translations\Tests\Unit;

use Joomla\Plugin\Task\TranslationsSeed\Helper\LanguagePackReader;
use Joomla\Plugin\Task\TranslationsSeed\Helper\Seeder;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Which of an installed language pack's strings are learned from, and how they are batched.
 *
 * Seeding pays a provider for a translation of every string it takes, and then asks the
 * distiller to find rules in the difference. A pair that differs for a reason other than a
 * translation choice is paid for twice: once to translate, once to distil a rule from
 * nothing. Both halves below are about not doing that.
 *
 * @since  1.0.1
 */
final class LanguagePackSeedTest extends TestCase
{
    /**
     * A pack's own wording for a string is what there is to learn from.
     *
     * @return  void
     *
     * @since   1.0.1
     */
    public function testATranslatedStringIsWorthLearningFrom(): void
    {
        $this->assertTrue(LanguagePackReader::isSeedable('Save & Close', 'Opslaan & sluiten'));
    }

    /**
     * An outdated pack's "_QQ_" is read as the double quote it stands for.
     *
     * @return  void
     *
     * @since   3.0.0
     */
    public function testAnOutdatedQuoteIsReadAsADoubleQuote(): void
    {
        $this->assertSame('Clique em "Salvar" para manter.', LanguagePackReader::withQuotes('Clique em "_QQ_"Salvar"_QQ_" para manter.'));
        $this->assertSame('Termina com "', LanguagePackReader::withQuotes('Termina com "_QQ_"'));
        $this->assertSame('Sem aspas', LanguagePackReader::withQuotes('Sem aspas'));
    }

    /**
     * A pair that carries no translation choice is passed over.
     *
     * @param   string  $source    The string as written in the source language.
     * @param   string  $approved  The pack's translation of it.
     * @param   string  $why       Why the pair holds nothing to learn from.
     *
     * @return  void
     *
     * @dataProvider  unusablePairs
     *
     * @since   1.0.1
     */
    public function testAPairWithNoTranslationChoiceIsPassedOver(string $source, string $approved, string $why): void
    {
        $this->assertFalse(LanguagePackReader::isSeedable($source, $approved), $why);
    }

    /**
     * Pairs that must not be seeded, and why not.
     *
     * @return  array<string, array{0: string, 1: string, 2: string}>
     *
     * @since   1.0.1
     */
    public static function unusablePairs(): array
    {
        return [
            'not translated yet'   => [
                'Save & Close',
                'Save & Close',
                'A pack falls back to the source string, which is not a translation of it',
            ],
            'whitespace apart'     => ['Save & Close', "  Save & Close\n", 'Padding is not a correction'],
            'nothing on one side'  => ['Save & Close', '   ', 'There is no wording to compare against'],
            'nothing on the other' => ['', 'Opslaan', 'There is nothing that was translated'],
            'placeholder'          => [
                '%s items were deleted',
                'Er zijn %s items verwijderd',
                'A placeholder moves to suit the grammar, which is not a choice about wording',
            ],
            'positional argument'  => ['%1$s of %2$s', '%2$s: %1$s', 'Same, when the order is spelled out'],
            'numeric placeholder'  => ['Deleted %d items', '%d items verwijderd', 'Same, for a count'],
            'markup'               => [
                'Read the <a href="https://docs.joomla.org">documentation</a>',
                'Lees de <a href="https://docs.joomla.org/nl">documentatie</a>',
                'A pack may point markup somewhere else entirely, so the diff would be over markup',
            ],
            'entity'               => ['Save &amp; Close', 'Opslaan &amp; sluiten', 'Same, for an entity'],
            'numeric entity'       => ['Don&#39;t', 'Niet', 'Same, for a numeric entity'],
        ];
    }

    /**
     * A request carries the language key of each string, because the key says what it is for.
     *
     * "Save" as a toolbar button and "Save" as a message are the same word with different
     * weight, and the key is the only thing that tells a provider which one it has.
     *
     * @return  void
     *
     * @since   1.0.1
     */
    public function testAChunkIsKeyedByLanguageKey(): void
    {
        $chunks = self::requestChunks(
            [
                'administrator/com_content.ini#JSAVE'  => self::pair('administrator/com_content.ini', 'JSAVE', 'Save'),
                'administrator/com_content.ini#JCLOSE' => self::pair('administrator/com_content.ini', 'JCLOSE', 'Close'),
            ]
        );

        $this->assertCount(1, $chunks);
        $this->assertSame(['JSAVE', 'JCLOSE'], array_keys($chunks[0]));
    }

    /**
     * A key that turns up twice starts a new request rather than overwriting the first.
     *
     * The same key is used for different text in different files. Keying a request by it is
     * what gives a provider the context, so the collision has to end the request instead.
     *
     * @return  void
     *
     * @since   1.0.1
     */
    public function testARepeatedKeyStartsANewRequest(): void
    {
        $chunks = self::requestChunks(
            [
                'administrator/com_content.ini#JSAVE' => self::pair('administrator/com_content.ini', 'JSAVE', 'Save'),
                'site/com_users.ini#JSAVE'            => self::pair('site/com_users.ini', 'JSAVE', 'Save changes'),
            ]
        );

        $this->assertCount(2, $chunks, 'Neither string is lost to the other');
        $this->assertSame('Save', $chunks[0]['JSAVE']['source']);
        $this->assertSame('Save changes', $chunks[1]['JSAVE']['source']);
    }

    /**
     * A run longer than one request is split, and every string is in exactly one of them.
     *
     * A provider is asked for every key it is given, so an oversized request is lost whole.
     *
     * @return  void
     *
     * @since   1.0.1
     */
    public function testALongRunIsSplitWithoutLosingAString(): void
    {
        $pairs = [];

        for ($i = 1; $i <= 60; $i++) {
            $pairs['administrator/com_content.ini#KEY_' . $i] = self::pair(
                'administrator/com_content.ini',
                'KEY_' . $i,
                'String ' . $i
            );
        }

        $chunks = self::requestChunks($pairs);

        $this->assertSame([25, 25, 10], array_map('\count', $chunks));
        $this->assertCount(60, array_merge(...array_map('array_values', $chunks)));
    }

    /**
     * Strings that failed before go first, in smaller requests: half the size on a second
     * attempt, alone on the last.
     *
     * A string that keeps failing would otherwise take the strings sent with it down again on
     * every attempt, and use up their attempts as well as its own.
     *
     * @return  void
     *
     * @since   1.1.0
     */
    public function testRetriedStringsGoFirstInSmallerRequests(): void
    {
        $pairs = [];

        foreach (['A', 'B'] as $key) {
            $pairs['administrator/com_content.ini#' . $key] = self::pair('administrator/com_content.ini', $key, $key);
        }

        for ($i = 1; $i <= 14; $i++) {
            $pair                                          = self::pair('administrator/com_content.ini', 'SECOND_' . $i, 'S' . $i);
            $pair['attempts']                              = 1;
            $pairs['administrator/com_content.ini#SECOND_' . $i] = $pair;
        }

        foreach (['LAST_1', 'LAST_2'] as $key) {
            $pair                                         = self::pair('administrator/com_content.ini', $key, $key);
            $pair['attempts']                             = 2;
            $pairs['administrator/com_content.ini#' . $key] = $pair;
        }

        $chunks = self::requestChunks($pairs);

        $this->assertSame([1, 1, 12, 2, 2], array_map('\count', $chunks), 'Last attempts alone, second attempts by 12, then the rest');
        $this->assertSame(['LAST_1'], array_keys($chunks[0]));
        $this->assertSame(['A', 'B'], array_keys($chunks[4]));
    }

    /**
     * A text that occurs in several language files is translated once, for all of them.
     *
     * The site, administrator and API files repeat many strings, and a pack translates every
     * repeat the same way; in the nl-NL pack 38% of the strings are such repeats.
     *
     * @return  void
     *
     * @since   1.2.0
     */
    public function testARepeatedTextIsTranslatedOnce(): void
    {
        $units = self::units(
            [
                'site/joomla.ini#JSAVE'          => self::pair('site/joomla.ini', 'JSAVE', 'Save'),
                'administrator/joomla.ini#JSAVE' => self::pair('administrator/joomla.ini', 'JSAVE', 'Save'),
                'api/joomla.ini#JAPPLY'          => self::pair('api/joomla.ini', 'JAPPLY', 'Save'),
                'site/joomla.ini#JCANCEL'        => self::pair('site/joomla.ini', 'JCANCEL', 'Cancel'),
            ]
        );

        $this->assertSame(['Save', 'Cancel'], array_column($units, 'source'));
        $this->assertCount(3, $units[0]['pairs'], 'The one translation stands for all three strings');
        $this->assertSame('JSAVE', $units[0]['key'], 'The first string gives the key that goes with the text');
    }

    /**
     * A repeated text writes one feedback row per distinct pack translation, counting its strings.
     *
     * @return  void
     *
     * @since   1.2.0
     */
    public function testEachDistinctPackTranslationIsCountedOnce(): void
    {
        $unit = [
            'pairs' => [
                ['approved' => 'Opslaan'],
                ['approved' => 'Opslaan '],
                ['approved' => 'Bewaren'],
            ],
        ];

        $method = new ReflectionMethod(Seeder::class, 'approvedTranslations');

        $this->assertSame(['Opslaan' => 2, 'Bewaren' => 1], $method->invoke(null, $unit));
    }

    /**
     * A string is seeded once, and again only when a new pack version changes its source text or
     * its translation.
     *
     * Without the fingerprint a changed string would count as done for good, and what the
     * language team changed in a new pack would never be learned from.
     *
     * @return  void
     *
     * @since   1.2.0
     */
    public function testOnlyNewAndChangedStringsArePending(): void
    {
        $pairs = [
            'site/a.ini#NEW'       => self::pair('site/a.ini', 'NEW', 'New'),
            'site/a.ini#SAME'      => self::pair('site/a.ini', 'SAME', 'Same'),
            'site/a.ini#CHANGED'   => self::pair('site/a.ini', 'CHANGED', 'Changed'),
            'site/a.ini#FAILED'    => self::pair('site/a.ini', 'FAILED', 'Failed'),
            'site/a.ini#OLD'       => self::pair('site/a.ini', 'OLD', 'Old'),
            'site/a.ini#OLD_RETRY' => self::pair('site/a.ini', 'OLD_RETRY', 'Old retry'),
        ];

        $states = [
            'site/a.ini#SAME'      => ['status' => 'seeded', 'attempts' => 1, 'fingerprint' => self::fingerprint($pairs['site/a.ini#SAME'])],
            'site/a.ini#CHANGED'   => ['status' => 'seeded', 'attempts' => 1, 'fingerprint' => sha1('what it said before')],
            'site/a.ini#FAILED'    => ['status' => 'failed', 'attempts' => 3, 'fingerprint' => sha1('what it said before')],
            'site/a.ini#OLD'       => ['status' => 'seeded', 'attempts' => 1, 'fingerprint' => ''],
            'site/a.ini#OLD_RETRY' => ['status' => 'retry', 'attempts' => 1, 'fingerprint' => ''],
        ];

        $method = new ReflectionMethod(Seeder::class, 'classify');
        $plan = $method->invoke(null, $pairs, $states);

        $this->assertSame(
            ['site/a.ini#OLD_RETRY', 'site/a.ini#NEW', 'site/a.ini#CHANGED', 'site/a.ini#FAILED'],
            array_keys($plan['pending']),
            'Retries first, then new and changed strings; unchanged and old seeded strings are done'
        );
        $this->assertSame(['site/a.ini#CHANGED', 'site/a.ini#FAILED'], array_keys($plan['reopen']));
        $this->assertSame(0, $plan['pending']['site/a.ini#FAILED']['attempts'], 'A changed string starts with fresh attempts');
        $this->assertTrue($plan['pending']['site/a.ini#CHANGED']['recorded'], 'A changed string already has a record');
        $this->assertFalse($plan['pending']['site/a.ini#NEW']['recorded']);
        $this->assertSame(['site/a.ini#OLD', 'site/a.ini#OLD_RETRY'], array_keys($plan['backfill']), 'Old records get a fingerprint');
    }

    /**
     * Nothing left to seed is no request at all.
     *
     * @return  void
     *
     * @since   1.0.1
     */
    public function testNothingPendingIsNoRequest(): void
    {
        $this->assertSame([], self::requestChunks([]));
    }

    /**
     * One of the pairs the reader produces.
     *
     * @param   string  $file    The client-qualified language file the string is in.
     * @param   string  $key     The language key.
     * @param   string  $source  The string as written in the source language.
     *
     * @return  array  The pair.
     *
     * @since   1.0.1
     */
    private static function pair(string $file, string $key, string $source): array
    {
        return ['file' => $file, 'key' => $key, 'source' => $source, 'approved' => 'NL: ' . $source];
    }

    /**
     * Split pending pairs into the requests a run would send.
     *
     * The seeder needs a database and a dispatcher to run, but the splitting reads only the
     * pairs it is given, so it is reached on an instance that was never constructed.
     *
     * @param   array  $pairs  The pending pairs, keyed by string id.
     *
     * @return  array  The requests.
     *
     * @since   1.0.1
     */
    private static function requestChunks(array $pairs): array
    {
        $seeder = (new ReflectionClass(Seeder::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(Seeder::class, 'requestChunks');

        return $method->invoke($seeder, self::units($pairs), Seeder::DEFAULT_REQUEST_SIZE);
    }

    /**
     * The fingerprint the seeder records for a pair.
     *
     * @param   array  $pair  The pair.
     *
     * @return  string  The fingerprint.
     *
     * @since   1.2.0
     */
    private static function fingerprint(array $pair): string
    {
        $method = new ReflectionMethod(Seeder::class, 'fingerprint');

        return $method->invoke(null, $pair);
    }

    /**
     * Group pending pairs into the units a run translates: one per distinct source text.
     *
     * @param   array  $pairs  The pending pairs, keyed by string id.
     *
     * @return  array  The units.
     *
     * @since   1.2.0
     */
    private static function units(array $pairs): array
    {
        $method = new ReflectionMethod(Seeder::class, 'units');

        return $method->invoke(null, $pairs);
    }
}
