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

use Joomla\Component\Translations\Administrator\Link\SourceChange;
use PHPUnit\Framework\TestCase;

/**
 * Only a change in the text to translate makes a translation out of date.
 *
 * When link repair changed nothing but hrefs in the guide's source articles, 2,511 translations
 * went back to pending. A save that changes only an article's state stores a version too.
 *
 * @since  2.0.0
 */
final class SourceChangeTest extends TestCase
{
    /**
     * The translatable fields of an article.
     *
     * @var    array
     * @since  2.0.0
     */
    private const FIELDS = ['title', 'introtext', 'fulltext', ['images' => ['image_intro_alt']]];

    /**
     * A source article as stored in a version.
     *
     * @var    array
     * @since  2.0.0
     */
    private const ARTICLE = [
        'title'     => 'Menus',
        'introtext' => '<p>See <a href="index.php?Itemid=417">Articles</a> and <a href="index.php?Itemid=418">Menus</a>.</p>',
        'fulltext'  => '',
        'images'    => '{"image_intro_alt":"A menu"}',
        'state'     => 1,
    ];

    /**
     * A change in something else than the text is no change.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAChangeOutsideTheTextIsNone(): void
    {
        $this->assertSame(
            ['change' => SourceChange::NONE, 'links' => []],
            SourceChange::between(self::ARTICLE, ['state' => 0, 'hits' => 2] + self::ARTICLE, self::FIELDS, ['catid', 'access'])
        );
    }

    /**
     * A change in the targets of links only is a change in links, with each old link and its new one.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAChangeInLinkTargetsOnlyIsAChangeInLinks(): void
    {
        $new = ['introtext' => '<p>See <a href="index.php?Itemid=417">Articles</a> and <a href="index.php?Itemid=420#top">Menus</a>.</p>'] + self::ARTICLE;

        $this->assertSame(
            ['change' => SourceChange::LINKS, 'links' => ['index.php?Itemid=418' => 'index.php?Itemid=420#top']],
            SourceChange::between(self::ARTICLE, $new, self::FIELDS)
        );
    }

    /**
     * A link written differently in HTML but the same is no change.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheSameLinkEncodedDifferentlyIsNoChange(): void
    {
        $old = ['introtext' => '<a href="index.php?option=com_content&view=article&id=5">A</a>'] + self::ARTICLE;
        $new = ['introtext' => '<a href="index.php?option=com_content&amp;view=article&amp;id=5">A</a>'] + self::ARTICLE;

        $this->assertSame(SourceChange::NONE, SourceChange::between($old, $new, self::FIELDS)['change']);
    }

    /**
     * A change in the text, in a link's text, or in a field inside a JSON column is a change in the text.
     *
     * @param   array  $new  The new version's changed values.
     *
     * @return  void
     *
     * @dataProvider  textChanges
     *
     * @since   2.0.0
     */
    public function testAChangeInTheTextIsAChangeInTheText(array $new): void
    {
        $this->assertSame(SourceChange::TEXT, SourceChange::between(self::ARTICLE, $new + self::ARTICLE, self::FIELDS)['change']);
    }

    /**
     * Changes in the text.
     *
     * @return  array<string, array{0: array}>
     *
     * @since   2.0.0
     */
    public static function textChanges(): array
    {
        return [
            'the title'                => [['title' => 'Menu Items']],
            'a link and the text'      => [['introtext' => '<p>See <a href="index.php?Itemid=419">Articles</a> or <a href="index.php?Itemid=418">Menus</a>.</p>']],
            'the text of a link'       => [['introtext' => '<p>See <a href="index.php?Itemid=417">Article</a> and <a href="index.php?Itemid=418">Menus</a>.</p>']],
            'a link added'             => [['introtext' => '<p>See <a href="index.php?Itemid=417">Articles</a> and <a href="index.php?Itemid=418">Menus</a>.</p><a href="#">x</a>']],
            'a field in a JSON column' => [['images' => '{"image_intro_alt":"Menus"}']],
        ];
    }

    /**
     * A link changed into two different ones cannot be followed in a translation.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testALinkChangedIntoTwoIsAChangeInTheText(): void
    {
        $old = ['introtext' => '<a href="index.php?Itemid=1">a</a> <a href="index.php?Itemid=1">b</a>'] + self::ARTICLE;
        $new = ['introtext' => '<a href="index.php?Itemid=2">a</a> <a href="index.php?Itemid=3">b</a>'] + self::ARTICLE;

        $this->assertSame(SourceChange::TEXT, SourceChange::between($old, $new, self::FIELDS)['change']);
    }

    /**
     * A change in what a translation copies from its source makes it out of date; a copied field
     * only one version holds is not compared.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testACopiedFieldCounts(): void
    {
        $copied = ['catid', 'access', 'tags'];
        $old    = ['catid' => 2, 'access' => 1] + self::ARTICLE;

        $this->assertSame(SourceChange::TEXT, SourceChange::between($old, ['catid' => 8] + $old, self::FIELDS, $copied)['change']);
        $this->assertSame(SourceChange::NONE, SourceChange::between($old, ['catid' => '2'] + $old, self::FIELDS, $copied)['change']);
        $this->assertSame(SourceChange::NONE, SourceChange::between($old, ['tags' => ['3']] + $old, self::FIELDS, $copied)['change']);
    }

    /**
     * Custom fields count when a version holds them, and a version without them against one with
     * them cannot be compared.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testCustomFieldsCount(): void
    {
        $withFields = ['com_fields' => ['summary' => 'Short']] + self::ARTICLE;

        $this->assertSame(SourceChange::NONE, SourceChange::between($withFields, $withFields, self::FIELDS)['change']);
        $this->assertSame(
            SourceChange::TEXT,
            SourceChange::between($withFields, ['com_fields' => ['summary' => 'Shorter']] + self::ARTICLE, self::FIELDS)['change']
        );
        $this->assertSame(SourceChange::TEXT, SourceChange::between(self::ARTICLE, $withFields, self::FIELDS)['change']);
    }
}
