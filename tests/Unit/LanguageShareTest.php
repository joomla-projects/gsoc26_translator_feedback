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

use Joomla\Component\Translations\Administrator\Helper\LanguageShare;
use PHPUnit\Framework\TestCase;

/**
 * How a translate run's batch is shared between the target languages.
 *
 * When the first language filled the whole batch, guide.joomla.org took about ten hours per
 * language, and the tenth language waited five days for its first article.
 *
 * @since  1.6.1
 */
final class LanguageShareTest extends TestCase
{
    /**
     * The languages take turns, item by item.
     *
     * @return  void
     *
     * @since   1.6.1
     */
    public function testTheLanguagesTakeTurns(): void
    {
        $turns = LanguageShare::interleave(['de-DE' => [1, 2, 3], 'fr-FR' => [1, 2, 3], 'nl-NL' => [1, 2, 3]], 5);

        $this->assertSame([['de-DE', 1], ['fr-FR', 1], ['nl-NL', 1], ['de-DE', 2], ['fr-FR', 2]], $turns);
    }

    /**
     * A language with nothing left gives its turns to the others, so the batch is still filled.
     *
     * @return  void
     *
     * @since   1.6.1
     */
    public function testALanguageWithNothingLeftGivesItsTurnsAway(): void
    {
        $turns = LanguageShare::interleave(['de-DE' => [7], 'fr-FR' => [], 'nl-NL' => [1, 2, 3]], 4);

        $this->assertSame([['de-DE', 7], ['nl-NL', 1], ['nl-NL', 2], ['nl-NL', 3]], $turns);
    }

    /**
     * Less waiting than the batch holds gives what there is.
     *
     * @return  void
     *
     * @since   1.6.1
     */
    public function testLessWaitingThanTheBatchGivesWhatThereIs(): void
    {
        $this->assertSame([['de-DE', 1]], LanguageShare::interleave(['de-DE' => [1], 'fr-FR' => []], 10));
        $this->assertSame([], LanguageShare::interleave([], 10));
        $this->assertSame([], LanguageShare::interleave(['de-DE' => [1]], 0));
    }

    /**
     * The language translated into least recently goes first, a never tried one before all.
     *
     * @return  void
     *
     * @since   1.6.1
     */
    public function testTheLeastRecentLanguageGoesFirst(): void
    {
        $order = LanguageShare::leastRecentFirst(
            ['de-DE', 'fr-FR', 'nl-NL', 'it-IT'],
            ['de-DE' => '2026-11-03 10:05:00', 'fr-FR' => '2026-11-03 10:01:00', 'nl-NL' => '2026-11-03 10:03:00']
        );

        $this->assertSame(['it-IT', 'fr-FR', 'nl-NL', 'de-DE'], $order);
    }

    /**
     * Languages tried at the same moment keep the site's order.
     *
     * @return  void
     *
     * @since   1.6.1
     */
    public function testATieKeepsTheSitesOrder(): void
    {
        $same  = '2026-11-03 10:00:00';
        $order = LanguageShare::leastRecentFirst(['nl-NL', 'de-DE', 'fr-FR'], ['nl-NL' => $same, 'de-DE' => $same, 'fr-FR' => $same]);

        $this->assertSame(['nl-NL', 'de-DE', 'fr-FR'], $order);
    }

    /**
     * Runs cut short after one item each still go round all the languages.
     *
     * @return  void
     *
     * @since   1.6.1
     */
    public function testShortRunsStillGoRoundTheLanguages(): void
    {
        $languages    = ['de-DE', 'fr-FR', 'nl-NL'];
        $lastAttempts = [];
        $served       = [];

        for ($run = 1; $run <= 6; $run++) {
            $first                = LanguageShare::leastRecentFirst($languages, $lastAttempts)[0];
            $served[]             = $first;
            $lastAttempts[$first] = \sprintf('2026-11-03 10:%02d:00', $run);
        }

        $this->assertSame(['de-DE', 'fr-FR', 'nl-NL', 'de-DE', 'fr-FR', 'nl-NL'], $served);
    }
}
