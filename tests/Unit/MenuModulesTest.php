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

use Joomla\Component\Translations\Administrator\Module\MenuModules;
use PHPUnit\Framework\TestCase;

/**
 * A copy of a menu module is on the translations of its source's pages, and shows the language's menu.
 *
 * @since  2.0.0
 */
final class MenuModulesTest extends TestCase
{
    /**
     * The translations of source menu items 10, 11 and 12; 13 has none yet.
     *
     * @var    array<int, int>
     * @since  2.0.0
     */
    private const TRANSLATED = [10 => 110, 11 => 111, 12 => 112];

    /**
     * Look up a translation.
     *
     * @param   int  $itemId  The source menu item.
     *
     * @return  int|null
     *
     * @since   2.0.0
     */
    public function translated(int $itemId): ?int
    {
        return self::TRANSLATED[$itemId] ?? null;
    }

    /**
     * All pages stays all pages.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAllPagesStaysAllPages(): void
    {
        $this->assertSame([0], MenuModules::pages([0], [$this, 'translated']));
    }

    /**
     * Only on some pages: on their translations, those not translated yet left out.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testOnlyOnPagesBecomesTheirTranslations(): void
    {
        $this->assertSame([110, 112], MenuModules::pages([10, 13, 12], [$this, 'translated']));
        $this->assertSame([], MenuModules::pages([13], [$this, 'translated']));
    }

    /**
     * On all pages except some: except their translations, and on all pages while none is translated.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAllButStaysAllBut(): void
    {
        $this->assertSame([-110, -111], MenuModules::pages([-10, -11, -13], [$this, 'translated']));
        $this->assertSame([0], MenuModules::pages([-13], [$this, 'translated']));
    }

    /**
     * On no page stays on no page.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testNoPageStaysNoPage(): void
    {
        $this->assertSame([], MenuModules::pages([], [$this, 'translated']));
    }

    /**
     * The copy shows the language's menu from the translation of its base, or no base yet.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testParamsShowTheLanguagesMenuFromTheTranslatedBase(): void
    {
        $params = '{"menutype":"mainmenu","base":"11","startLevel":"2","showAllChildren":"1"}';

        $this->assertSame(
            '{"menutype":"mainmenu-nl-nl","base":"111","startLevel":"2","showAllChildren":"1"}',
            MenuModules::params($params, 'mainmenu-nl-nl', [$this, 'translated'])
        );
        $this->assertSame(
            '{"menutype":"mainmenu-nl-nl","base":"","startLevel":"2"}',
            MenuModules::params('{"menutype":"mainmenu","base":"13","startLevel":"2"}', 'mainmenu-nl-nl', [$this, 'translated'])
        );
        $this->assertSame(
            '{"menutype":"mainmenu-nl-nl","base":""}',
            MenuModules::params('{"menutype":"mainmenu","base":""}', 'mainmenu-nl-nl', [$this, 'translated'])
        );
    }
}
