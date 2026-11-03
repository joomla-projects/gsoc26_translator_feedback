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

use Joomla\Component\Translations\Administrator\Title\RelatedTitles;
use PHPUnit\Framework\TestCase;

/**
 * An item takes the title translation of a related item that has the same title.
 *
 * @since  3.0.0
 */
final class RelatedTitlesTest extends TestCase
{
    /**
     * The same title is taken over, and kept as a term for the rest of the item.
     *
     * @return  void
     *
     * @since   3.0.0
     */
    public function testTheSameTitleIsTakenOver(): void
    {
        $this->assertSame(
            ['title' => 'Privacy-werkstroom', 'terms' => ['Privacy Workflow' => 'Privacy-werkstroom']],
            RelatedTitles::known('Privacy Workflow', [['Privacy  Workflow ', 'Privacy-werkstroom']])
        );
    }

    /**
     * A title that holds the other, or is part of it, gives a term only.
     *
     * @return  void
     *
     * @since   3.0.0
     */
    public function testAPartOfTheTitleGivesATerm(): void
    {
        $this->assertSame(
            ['title' => null, 'terms' => ['Workflow' => 'Werkstroom', 'Privacy Workflow Options' => 'Opties voor de privacy-werkstroom']],
            RelatedTitles::known('Privacy Workflow', [
                ['Workflow', 'Werkstroom'],
                ['Privacy Workflow Options', 'Opties voor de privacy-werkstroom'],
                ['Users', 'Gebruikers'],
            ])
        );
    }

    /**
     * A title left in the source language is no translation to pass on.
     *
     * @return  void
     *
     * @since   3.0.0
     */
    public function testATitleLeftInTheSourceLanguageIsPassedOver(): void
    {
        $this->assertSame(
            ['title' => null, 'terms' => []],
            RelatedTitles::known('Privacy Workflow', [['Privacy Workflow', 'Privacy Workflow']])
        );
    }

    /**
     * Of different translations of the same title, the one most share is taken, the first on a tie.
     *
     * @return  void
     *
     * @since   3.0.0
     */
    public function testTheMostSharedTranslationWins(): void
    {
        $this->assertSame('B', RelatedTitles::known('Users', [['Users', 'A'], ['Users', 'B'], ['Users', 'B']])['title']);
        $this->assertSame('A', RelatedTitles::known('Users', [['Users', 'A'], ['Users', 'B']])['title']);
    }

    /**
     * A title differing in case only is a term, not the title, and very short titles are not looked for.
     *
     * @return  void
     *
     * @since   3.0.0
     */
    public function testCaseAndShortTitles(): void
    {
        $this->assertSame(
            ['title' => null, 'terms' => ['privacy workflow' => 'privacy-werkstroom']],
            RelatedTitles::known('Privacy Workflow', [['privacy workflow', 'privacy-werkstroom'], ['Pr', 'Pr-x']])
        );
        $this->assertSame(['title' => null, 'terms' => []], RelatedTitles::known('', [['Users', 'Gebruikers']]));
    }
}
