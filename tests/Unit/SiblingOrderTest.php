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

use Joomla\Component\Translations\Administrator\Order\SiblingOrder;
use PHPUnit\Framework\TestCase;

/**
 * A parent can have its children's translations sorted by translated title, some kept at either end.
 *
 * @since  2.0.0
 */
final class SiblingOrderTest extends TestCase
{
    /**
     * The source's order is kept unless the parent asks for titles.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testFromParamsReadsTheSetting(): void
    {
        $this->assertNull(SiblingOrder::fromParams(''));
        $this->assertNull(SiblingOrder::fromParams('{"translations_children_order":"source","translations_children_first":"2"}'));
        $this->assertSame(['first' => 0, 'last' => 0], SiblingOrder::fromParams('{"translations_children_order":"title"}'));
        $this->assertSame(
            ['first' => 1, 'last' => 0],
            SiblingOrder::fromParams('{"translations_children_order":"title","translations_children_first":"1","translations_children_last":"-3"}')
        );
    }

    /**
     * The translations are sorted by their own titles, not the source's.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testSortsByTranslatedTitle(): void
    {
        // Source: Calendar, Checkboxes, Editor; in Dutch: Kalender, Selectievakjes, Editor.
        $titles = [11 => 'Kalender', 12 => 'Selectievakjes', 13 => 'Editor'];

        $this->assertSame([13, 11, 12], SiblingOrder::arrange([11, 12, 13], $titles, 0, 0, 'nl-NL'));
    }

    /**
     * The children kept at either end keep the source's order, whatever their titles.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testKeepsTheEndsInPlace(): void
    {
        $titles = [1 => 'Zoverzicht', 2 => 'C', 3 => 'A', 4 => 'B', 5 => 'Zie ook', 6 => 'Aanhangsel'];

        $this->assertSame([1, 3, 4, 2, 5, 6], SiblingOrder::arrange([1, 2, 3, 4, 5, 6], $titles, 1, 2, 'nl-NL'));
    }

    /**
     * More children kept in place than there are leaves nothing to sort.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testMoreKeptThanChildrenKeepsTheSourceOrder(): void
    {
        $this->assertSame([2, 1], SiblingOrder::arrange([2, 1], [1 => 'A', 2 => 'B'], 5, 5, 'en-GB'));
    }

    /**
     * Children not translated yet come after the sorted ones.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testUntranslatedChildrenComeAfterTheSortedOnes(): void
    {
        $this->assertSame([1, 3, 4, 2, 9], SiblingOrder::arrange([1, 2, 3, 4, 9], [3 => 'B', 4 => 'Z'], 1, 1, 'nl-NL'));
    }

    /**
     * Numbers sort by value, and case and accents do not put a title at the end.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testNumbersAndAccentsSortNaturally(): void
    {
        $titles = [1 => 'Stap 10', 2 => 'Stap 9', 3 => 'élan', 4 => 'Zebra', 5 => 'appel'];

        $this->assertSame([5, 3, 2, 1, 4], SiblingOrder::arrange([1, 2, 3, 4, 5], $titles, 0, 0, 'nl-NL'));
    }
}
