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

use Joomla\Component\Translations\Administrator\Link\HtmlLinks;
use Joomla\Component\Translations\Administrator\Link\JoomlaLink;
use Joomla\Component\Translations\Administrator\Link\LinkLookup;
use Joomla\Component\Translations\Administrator\Link\LinkTranslator;
use Joomla\Component\Translations\Administrator\Link\MenuItem;
use PHPUnit\Framework\TestCase;

/**
 * The internal links in a translation open the pages of its own language.
 *
 * The site: an English article 10 opened by menu item 417, its Dutch translation 20 opened by
 * menu item 517, associated with each other; an English article 11 opened by menu item 418 that
 * has no translation yet; English category 2 with its Dutch translation 3.
 *
 * @since  2.0.0
 */
final class InternalLinksTest extends TestCase
{
    /**
     * The site's items.
     *
     * @var    FakeLinkLookup
     * @since  2.0.0
     */
    private FakeLinkLookup $site;

    /**
     * The translator under test.
     *
     * @var    LinkTranslator
     * @since  2.0.0
     */
    private LinkTranslator $translator;

    /**
     * Build the site.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    protected function setUp(): void
    {
        $this->site = (new FakeLinkLookup())
            ->item('com_content.article', 10, 'en-GB')
            ->item('com_content.article', 20, 'nl-NL')
            ->item('com_content.article', 11, 'en-GB')
            ->item('com_categories.category', 2, 'en-GB')
            ->item('com_categories.category', 3, 'nl-NL')
            ->associate('com_content.article', ['en-GB' => 10, 'nl-NL' => 20])
            ->associate('com_categories.category', ['en-GB' => 2, 'nl-NL' => 3])
            ->articleMenuItem(417, 'en-GB', 10)
            ->articleMenuItem(517, 'nl-NL', 20)
            ->articleMenuItem(418, 'en-GB', 11)
            ->associate('com_menus.item', ['en-GB' => 417, 'nl-NL' => 517]);

        $this->translator = new LinkTranslator($this->site);
    }

    /**
     * A link to a menu item points at the menu item associated with it.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAMenuItemLinkPointsAtTheAssociatedMenuItem(): void
    {
        $this->assertSame('index.php?Itemid=517&lang=nl-NL', $this->translator->translate('index.php?Itemid=417', 'nl-NL'));
    }

    /**
     * The anchor stays, and a lang parameter the link has is set to the language.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheAnchorStaysAndAnExistingLangIsSet(): void
    {
        $this->assertSame(
            'index.php?Itemid=517&lang=nl-NL#filters',
            $this->translator->translate('index.php?Itemid=417&lang=en-GB#filters', 'nl-NL')
        );
    }

    /**
     * A menu item nobody associated is found through the translation of the article it opens.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAMenuItemIsFoundThroughTheTranslatedArticle(): void
    {
        $this->site
            ->item('com_content.article', 21, 'nl-NL')
            ->associate('com_content.article', ['en-GB' => 11, 'nl-NL' => 21])
            ->articleMenuItem(518, 'nl-NL', 21);

        $this->assertSame('index.php?Itemid=518&lang=nl-NL', $this->translator->translate('index.php?Itemid=418', 'nl-NL'));
    }

    /**
     * A link to a menu item without an equivalent yet stays, to be repointed once there is one.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testALinkWithoutEquivalentYetStays(): void
    {
        $this->assertNull($this->translator->translate('index.php?Itemid=418', 'nl-NL'));
    }

    /**
     * A link to an alias menu item points at the equivalent of the menu item it stands for.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testAnAliasMenuItemIsFollowed(): void
    {
        $this->site->aliasMenuItem(430, 'en-GB', 417);

        $this->assertSame('index.php?Itemid=517&lang=nl-NL', $this->translator->translate('index.php?Itemid=430', 'nl-NL'));
    }

    /**
     * Links that are right already, and links that are no Joomla links, stay as they are.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testLinksThatAreRightStay(): void
    {
        $this->assertNull($this->translator->translate('index.php?Itemid=517&lang=nl-NL', 'nl-NL'));
        $this->assertNull($this->translator->translate('index.php?Itemid=417', 'en-GB'));
        $this->assertNull($this->translator->translate('https://www.joomla.org/', 'nl-NL'));
        $this->assertNull($this->translator->translate('#top', 'nl-NL'));
        $this->assertNull($this->translator->translate('index.php?Itemid=999', 'nl-NL'));
    }

    /**
     * The article and category in a com_content link point at their translations.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testTheArticleAndCategoryOfAContentLinkAreTranslated(): void
    {
        $this->assertSame(
            'index.php?option=com_content&view=article&id=20&catid=3&Itemid=517&lang=nl-NL',
            $this->translator->translate('index.php?option=com_content&view=article&id=10:articles&catid=2&Itemid=417', 'nl-NL')
        );
    }

    /**
     * What has a translation is repointed; what has none yet stays.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testWhatHasNoTranslationYetStays(): void
    {
        $this->assertSame(
            'index.php?option=com_content&view=article&id=11&catid=3',
            $this->translator->translate('index.php?option=com_content&view=article&id=11&catid=2', 'nl-NL')
        );
    }

    /**
     * Only the href values of the links in a piece of HTML change.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testOnlyTheHrefsInTheHtmlChange(): void
    {
        $html = '<p>See <a class="x" href="index.php?Itemid=417#top" data-href="index.php?Itemid=417">Articles</a>,'
            . " <a href='index.php?Itemid=418'>Menus</a> and <a href=\"https://www.joomla.org/\">Joomla</a>.</p>";

        $this->assertSame(
            '<p>See <a class="x" href="index.php?Itemid=517&amp;lang=nl-NL#top" data-href="index.php?Itemid=417">Articles</a>,'
            . " <a href='index.php?Itemid=418'>Menus</a> and <a href=\"https://www.joomla.org/\">Joomla</a>.</p>",
            $this->translator->translateHtml($html, 'nl-NL')
        );
    }

    /**
     * An HTML-encoded href is read decoded.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testEncodedHrefsAreReadDecoded(): void
    {
        $this->assertSame(
            ['index.php?option=com_content&view=article&id=10', 'https://www.joomla.org/'],
            HtmlLinks::hrefs('<a href="index.php?option=com_content&amp;view=article&amp;id=10">a</a><A HREF=https://www.joomla.org/>b</A>')
        );
    }

    /**
     * A link keeps the order of its query when one value changes.
     *
     * @return  void
     *
     * @since   2.0.0
     */
    public function testALinkKeepsItsQueryOrder(): void
    {
        $link = JoomlaLink::parse('/index.php?option=com_content&Itemid=4&view=article&id=5:slug#a');

        $this->assertNotNull($link);
        $this->assertSame('/index.php?option=com_content&Itemid=7&view=article&id=5:slug#a', $link->with('Itemid', '7')->href());
        $this->assertNull(JoomlaLink::parse('index.php?option=com_tags&id[]=1'));
        $this->assertNull(JoomlaLink::parse('https://example.org/index.php?Itemid=4'));
    }
}

/**
 * A site held in memory.
 *
 * @since  2.0.0
 */
final class FakeLinkLookup implements LinkLookup
{
    /**
     * The menu items by id.
     *
     * @var    array<int, MenuItem>
     * @since  2.0.0
     */
    private array $menuItems = [];

    /**
     * The languages per "content type.id".
     *
     * @var    array<string, string>
     * @since  2.0.0
     */
    private array $languages = [];

    /**
     * The association groups per "content type.id".
     *
     * @var    array<string, array<string, int>>
     * @since  2.0.0
     */
    private array $groups = [];

    /**
     * Add a menu item that opens an article.
     *
     * @param   integer  $id         The menu item id.
     * @param   string   $language   Its language.
     * @param   integer  $articleId  The article it opens.
     *
     * @return  self
     *
     * @since   2.0.0
     */
    public function articleMenuItem(int $id, string $language, int $articleId): self
    {
        $this->menuItems[$id] = new MenuItem(
            $id,
            $language,
            'component',
            ['option' => 'com_content', 'view' => 'article', 'id' => (string) $articleId]
        );

        return $this;
    }

    /**
     * Add an alias menu item.
     *
     * @param   integer  $id        The menu item id.
     * @param   string   $language  Its language.
     * @param   integer  $target    The menu item it stands for.
     *
     * @return  self
     *
     * @since   2.0.0
     */
    public function aliasMenuItem(int $id, string $language, int $target): self
    {
        $this->menuItems[$id] = new MenuItem($id, $language, MenuItem::ALIAS, [], $target);

        return $this;
    }

    /**
     * Add an item.
     *
     * @param   string   $contentType  The content type key.
     * @param   integer  $id           The item id.
     * @param   string   $language     Its language.
     *
     * @return  self
     *
     * @since   2.0.0
     */
    public function item(string $contentType, int $id, string $language): self
    {
        $this->languages[$contentType . '.' . $id] = $language;

        return $this;
    }

    /**
     * Associate items.
     *
     * @param   string              $contentType    The content type key.
     * @param   array<string, int>  $idsByLanguage  The item ids per language.
     *
     * @return  self
     *
     * @since   2.0.0
     */
    public function associate(string $contentType, array $idsByLanguage): self
    {
        foreach ($idsByLanguage as $id) {
            $this->groups[$contentType . '.' . $id] = $idsByLanguage;
        }

        return $this;
    }

    /**
     * @inheritDoc
     *
     * @since   2.0.0
     */
    public function menuItem(int $id): ?MenuItem
    {
        return $this->menuItems[$id] ?? null;
    }

    /**
     * @inheritDoc
     *
     * @since   2.0.0
     */
    public function menuItemOpening(string $opens, int $id, string $language): ?MenuItem
    {
        $best = null;

        foreach ($this->menuItems as $item) {
            if ($item->opens() !== $opens || $item->opensId() !== $id) {
                continue;
            }

            if ($item->language === $language) {
                return $item;
            }

            if ($item->forAllLanguages()) {
                $best ??= $item;
            }
        }

        return $best;
    }

    /**
     * @inheritDoc
     *
     * @since   2.0.0
     */
    public function language(string $contentType, int $id): ?string
    {
        return $this->languages[$contentType . '.' . $id] ?? null;
    }

    /**
     * @inheritDoc
     *
     * @since   2.0.0
     */
    public function translation(string $contentType, int $id, string $language): ?int
    {
        return $this->groups[$contentType . '.' . $id][$language] ?? null;
    }
}
