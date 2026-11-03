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

use Joomla\Component\Translations\Administrator\Helper\SourceState;
use PHPUnit\Framework\TestCase;

/**
 * Whether a translation is on the site, as far as its source decides it.
 *
 * A translation published while its source is not puts a page on the site that the source
 * language does not have; one republished after somebody took it off undoes their decision.
 *
 * @since  2.0.0
 */
final class SourceStateTest extends TestCase
{
    /**
     * A new translation is published only with Auto-Publish on and its source published.
     *
     * @param   integer  $sourceState  The source's state.
     * @param   boolean  $autoPublish  Whether Auto-Publish is on.
     * @param   array    $expected     The translation's state and whether it is held.
     *
     * @return  void
     *
     * @dataProvider  newTranslations
     *
     * @since   2.0.0
     */
    public function testANewTranslationFollowsItsSource(int $sourceState, bool $autoPublish, array $expected): void
    {
        $this->assertSame($expected, SourceState::forNewTranslation($sourceState, $autoPublish));
    }

    /**
     * New translations of sources in every state, with and without Auto-Publish.
     *
     * @return  array<string, array{0: int, 1: bool, 2: array}>
     *
     * @since   2.0.0
     */
    public static function newTranslations(): array
    {
        return [
            'published source, auto-publish'     => [1, true, ['state' => 1, 'held' => false]],
            'unpublished source, auto-publish'   => [0, true, ['state' => 0, 'held' => true]],
            'archived source, auto-publish'      => [2, true, ['state' => 2, 'held' => true]],
            'published source, no auto-publish'  => [1, false, ['state' => 0, 'held' => false]],
            'unpublished source, no auto-publish' => [0, false, ['state' => 0, 'held' => false]],
        ];
    }

    /**
     * A translation follows its source off the site and back, and only the held ones come back.
     *
     * @param   integer     $sourceState       The source's new state.
     * @param   integer     $translationState  The translation's state.
     * @param   boolean     $held              Whether the translation is held.
     * @param   array|null  $expected          The change, or null for none.
     *
     * @return  void
     *
     * @dataProvider  changes
     *
     * @since   2.0.0
     */
    public function testATranslationFollowsItsSource(int $sourceState, int $translationState, bool $held, ?array $expected): void
    {
        $this->assertSame($expected, SourceState::follow($sourceState, $translationState, $held));
    }

    /**
     * Changes of a source's state, against translations in every state.
     *
     * @return  array<string, array{0: int, 1: int, 2: bool, 3: array|null}>
     *
     * @since   2.0.0
     */
    public static function changes(): array
    {
        return [
            'source unpublished, live translation goes off'     => [0, 1, false, ['state' => 0, 'held' => true]],
            'source archived, live translation is archived'      => [2, 1, false, ['state' => 2, 'held' => true]],
            'source published, held translation comes back'      => [1, 0, true, ['state' => 1, 'held' => false]],
            'source published, translation taken off stays off'  => [1, 0, false, null],
            'source published, live translation stays'           => [1, 1, false, null],
            'source unpublished, translation off already'        => [0, 0, false, null],
            'source unpublished from archived, held follows'     => [0, 2, true, ['state' => 0, 'held' => true]],
            'source unpublished, held translation stays as is'   => [0, 0, true, null],
            'source archived, unpublished translation stays'     => [2, 0, false, null],
            'source trashed has its own cascade'                 => [-2, 1, false, null],
        ];
    }
}
