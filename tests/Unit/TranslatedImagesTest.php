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

use Joomla\Component\Translations\Administrator\Image\TranslatedImages;
use Joomla\Registry\Registry;
use PHPUnit\Framework\TestCase;

/**
 * A translation shows the translated image where one is on the site, and the original otherwise.
 *
 * @since  4.0.0
 */
final class TranslatedImagesTest extends TestCase
{
    /**
     * The folders of guide.joomla.org.
     *
     * @var    array<string, string>
     * @since  4.0.0
     */
    private const GUIDE = [
        'images/manuals/user/en/images' => 'images/manuals/user/{lang}/images',
        'images/help-pages'             => 'images/help-pages-{lang}',
    ];

    /**
     * The images there are on the site in a test.
     *
     * @param   string[]  $files  Paths from the site root.
     *
     * @return  callable(string): bool
     *
     * @since   4.0.0
     */
    private static function site(array $files): callable
    {
        return static fn (string $path): bool => \in_array($path, $files, true);
    }

    /**
     * An image with a translation is pointed at it; one without keeps the original.
     *
     * @return  void
     *
     * @since   4.0.0
     */
    public function testAnImageWithATranslationIsPointedAtIt(): void
    {
        $images = new TranslatedImages(self::GUIDE, [], self::site(['images/manuals/user/nl/images/articles/edit.png']));

        $this->assertSame(
            '<p><img src="images/manuals/user/nl/images/articles/edit.png" alt="Edit">'
            . '<img src="images/manuals/user/en/images/articles/save.png" alt="Save"></p>',
            $images->translateHtml(
                '<p><img src="images/manuals/user/en/images/articles/edit.png" alt="Edit">'
                . '<img src="images/manuals/user/en/images/articles/save.png" alt="Save"></p>',
                'nl-NL'
            )
        );
    }

    /**
     * A link to the full size, a lazy source and a leading slash are pointed too, in either quotes.
     *
     * @return  void
     *
     * @since   4.0.0
     */
    public function testLinksLazySourcesAndALeadingSlashArePointedToo(): void
    {
        $images = new TranslatedImages(self::GUIDE, [], self::site(['images/help-pages-de/menus/new.png']));

        $this->assertSame(
            "<a href='/images/help-pages-de/menus/new.png'><img data-src=\"images/help-pages-de/menus/new.png\"></a>",
            $images->translateHtml("<a href='/images/help-pages/menus/new.png'><img data-src=\"images/help-pages/menus/new.png\"></a>", 'de-DE')
        );
    }

    /**
     * The media field's hash repeats the path below images/, and is pointed along with it.
     *
     * @return  void
     *
     * @since   4.0.0
     */
    public function testTheMediaFieldHashIsPointedAlong(): void
    {
        $images = new TranslatedImages(self::GUIDE, [], self::site(['images/help-pages-fr/a.png']));

        $this->assertSame(
            'images/help-pages-fr/a.png#joomlaImage://local-images/help-pages-fr/a.png?width=800&height=600',
            $images->translateValue('images/help-pages/a.png#joomlaImage://local-images/help-pages/a.png?width=800&height=600', 'fr-FR')
        );
    }

    /**
     * A language whose code is not its tag's first part is given its code.
     *
     * @return  void
     *
     * @since   4.0.0
     */
    public function testALanguageCanBeGivenItsOwnCode(): void
    {
        $images = new TranslatedImages(
            self::GUIDE,
            ['pt-BR' => 'ptbr'],
            self::site(['images/help-pages-ptbr/a.png', 'images/help-pages-pt/a.png'])
        );

        $this->assertSame('images/help-pages-ptbr/a.png', $images->translateValue('images/help-pages/a.png', 'pt-BR'));
        $this->assertSame('images/help-pages-pt/a.png', $images->translateValue('images/help-pages/a.png', 'pt-PT'));
    }

    /**
     * The default folders: the same path below images/<language tag>; the most specific folder decides.
     *
     * @return  void
     *
     * @since   4.0.0
     */
    public function testTheDefaultAndTheMostSpecificFolder(): void
    {
        $images = new TranslatedImages(
            ['images' => 'images/{language}', 'images/special' => 'images/special-{lang}'],
            [],
            self::site(['images/nl-NL/banners/a.png', 'images/nl-NL/special/b.png'])
        );

        $this->assertSame('images/nl-NL/banners/a.png', $images->translateValue('images/banners/a.png', 'nl-NL'));
        $this->assertSame('images/special/b.png', $images->translateValue('images/special/b.png', 'nl-NL'));
    }

    /**
     * Doing it twice changes nothing; an address elsewhere, or one that climbs out, stays as it is.
     *
     * @return  void
     *
     * @since   4.0.0
     */
    public function testOtherAddressesStayAsTheyAre(): void
    {
        $images = new TranslatedImages(['images' => 'images/{language}'], [], static fn (): bool => true);
        $once   = $images->translateHtml('<img src="images/a.png">', 'nl-NL');

        $this->assertSame('<img src="images/nl-NL/a.png">', $once);
        $this->assertSame('<img src="images/nl-NL/nl-NL/a.png">', $images->translateHtml($once, 'nl-NL'), 'The default folder nests; the file has to be there.');

        $checked = new TranslatedImages(['images' => 'images/{language}'], [], self::site(['images/nl-NL/a.png']));

        $this->assertSame('<img src="images/nl-NL/a.png">', $checked->translateHtml($once, 'nl-NL'));
        $this->assertSame('https://example.org/images/a.png', $checked->translateValue('https://example.org/images/a.png', 'nl-NL'));
        $this->assertSame('images/../configuration.php', $images->translateValue('images/../configuration.php', 'nl-NL'));
        $this->assertSame('<p title="images/a.png">', $checked->translateHtml('<p title="images/a.png">', 'nl-NL'));
    }

    /**
     * The option is off by default; its lines are read with slashes and empty lines dropped.
     *
     * @return  void
     *
     * @since   4.0.0
     */
    public function testTheOptions(): void
    {
        $this->assertNull(TranslatedImages::fromParams(new Registry([]), '/site'));
        $this->assertInstanceOf(TranslatedImages::class, TranslatedImages::fromParams(new Registry(['translated_images' => 1]), '/site'));
        $this->assertNull(TranslatedImages::fromParams(new Registry(['translated_images' => 1, 'image_folders' => " \n"]), '/site'));
        $this->assertSame(
            ['images/help-pages' => 'images/help-pages-{lang}', 'pt-BR' => 'ptbr'],
            TranslatedImages::parseLines("/images/help-pages/ = /images/help-pages-{lang}/\r\n\nno pair here\n = right\npt-BR=ptbr")
        );
    }
}
