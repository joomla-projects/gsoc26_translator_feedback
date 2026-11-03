<?php

/**
 * @package     Joomla.Administrator
 * @subpackage  com_translations
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Component\Translations\Administrator\Link;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Points the internal links in a translation at the pages of its own language.
 *
 * A translation keeps the links of its source: `index.php?Itemid=123` opens the source language's
 * menu item, so a reader of the translation lands on a page in the source language. This finds the
 * equivalent in the translation's language:
 *
 * - for the menu item (Itemid): the menu item associated with it in that language; failing that,
 *   for an alias menu item, the equivalent of the menu item it stands for; failing that, a menu
 *   item in that language that opens the translation of what the menu item opens.
 * - for an article, category or tag in a link (id, catid): its translation.
 *
 * A link that already points at the right language, or at an item for all languages, stays as it
 * is. So does a link whose equivalent does not exist yet: it is repointed once that is translated.
 *
 * @since  2.0.0
 */
final class LinkTranslator
{
    /**
     * How many alias menu items in a row are followed.
     *
     * @var    integer
     * @since  2.0.0
     */
    private const MAX_ALIASES = 3;

    /**
     * The menu item content type.
     *
     * @var    string
     * @since  2.0.0
     */
    private const MENU_ITEM = 'com_menus.item';

    /**
     * The ids in a link that name an item, per "option.view": the parameter and its content type.
     *
     * @var    array<string, array<string, string>>
     * @since  2.0.0
     */
    private const ITEM_IDS = [
        'com_content.article'    => ['id' => 'com_content.article', 'catid' => 'com_categories.category'],
        'com_content.category'   => ['id' => 'com_categories.category'],
        'com_content.categories' => ['id' => 'com_categories.category'],
        'com_tags.tag'           => ['id' => 'com_tags.tag'],
    ];

    /**
     * Build the translator.
     *
     * @param   LinkLookup  $lookup  The site's items and their translations.
     *
     * @since   2.0.0
     */
    public function __construct(private readonly LinkLookup $lookup)
    {
    }

    /**
     * Point every internal link in a piece of HTML at the pages of a language.
     *
     * @param   string  $html      The HTML.
     * @param   string  $language  The language of the item the HTML is in.
     *
     * @return  string
     *
     * @since   2.0.0
     */
    public function translateHtml(string $html, string $language): string
    {
        return HtmlLinks::rewrite($html, fn (string $href): ?string => $this->translate($href, $language));
    }

    /**
     * The link pointed at the pages of a language, or null when it stays as it is.
     *
     * @param   string  $href      The link, HTML-decoded.
     * @param   string  $language  The language of the item the link is in.
     *
     * @return  string|null
     *
     * @since   2.0.0
     */
    public function translate(string $href, string $language): ?string
    {
        $link = JoomlaLink::parse($href);

        if ($link === null) {
            return null;
        }

        $new       = $link;
        $repointed = false;
        $menuItem  = $this->lookup->menuItem($link->id('Itemid'));

        if ($menuItem !== null && $menuItem->language !== $language) {
            $equivalent = $this->equivalent($menuItem, $language, 0);

            if ($equivalent !== null) {
                $new       = $new->with('Itemid', (string) $equivalent->id);
                $repointed = true;
            }
        }

        foreach (self::ITEM_IDS[$link->get('option') . '.' . $link->get('view')] ?? [] as $parameter => $contentType) {
            $id = $link->id($parameter);

            if ($id <= 0) {
                continue;
            }

            $translated = $this->translateItem($contentType, $id, $language);

            if ($translated !== null) {
                $new       = $new->with($parameter, (string) $translated);
                $repointed = true;
            }
        }

        $new = $this->withLanguage($new, $language, $repointed);

        return $new->href() === $link->href() ? null : $new->href();
    }

    /**
     * The menu item in a language that stands for a menu item, or null.
     *
     * @param   MenuItem  $menuItem  The menu item.
     * @param   string    $language  The language.
     * @param   integer   $aliases   How many alias menu items were followed to get here.
     *
     * @return  MenuItem|null
     *
     * @since   2.0.0
     */
    private function equivalent(MenuItem $menuItem, string $language, int $aliases): ?MenuItem
    {
        $associated = $this->lookup->translation(self::MENU_ITEM, $menuItem->id, $language);

        if ($associated !== null && ($found = $this->lookup->menuItem($associated)) !== null) {
            return $found;
        }

        if ($menuItem->type === MenuItem::ALIAS && $menuItem->aliasTarget > 0 && $aliases < self::MAX_ALIASES) {
            $target = $this->lookup->menuItem($menuItem->aliasTarget);

            if ($target !== null) {
                return $target->language === $language ? $target : $this->equivalent($target, $language, $aliases + 1);
            }
        }

        $contentType = self::ITEM_IDS[$menuItem->opens()]['id'] ?? null;

        if ($contentType === null || $menuItem->opensId() <= 0) {
            return null;
        }

        $translated = $this->translateItem($contentType, $menuItem->opensId(), $language);

        return $translated === null ? null : $this->lookup->menuItemOpening($menuItem->opens(), $translated, $language);
    }

    /**
     * The id of an item's translation into a language, or null when it stays as it is: when it
     * is in that language or for all languages already, or has no translation yet.
     *
     * @param   string   $contentType  The content type key.
     * @param   integer  $id           The item id.
     * @param   string   $language     The language.
     *
     * @return  integer|null
     *
     * @since   2.0.0
     */
    private function translateItem(string $contentType, int $id, string $language): ?int
    {
        $itemLanguage = $this->lookup->language($contentType, $id);

        if ($itemLanguage === null || $itemLanguage === $language || $itemLanguage === '*') {
            return null;
        }

        return $this->lookup->translation($contentType, $id, $language);
    }

    /**
     * The link's lang parameter set to the language where it should be.
     *
     * That is where it has one and points at a page in the language, or, for a repointed link,
     * where its menu item is in the language: the editor's Menu button adds one for such a link.
     *
     * @param   JoomlaLink  $link       The link.
     * @param   string      $language   The language.
     * @param   boolean     $repointed  Whether the link was repointed.
     *
     * @return  JoomlaLink
     *
     * @since   2.0.0
     */
    private function withLanguage(JoomlaLink $link, string $language, bool $repointed): JoomlaLink
    {
        $menuItem = $this->lookup->menuItem($link->id('Itemid'));
        $inTarget = $menuItem !== null && $menuItem->language === $language;
        $current  = $link->get('lang');

        if ($current !== '' && $current !== $language && ($repointed || $inTarget)) {
            return $link->with('lang', $language);
        }

        if ($current === '' && $repointed && $inTarget) {
            return $link->with('lang', $language);
        }

        return $link;
    }
}
