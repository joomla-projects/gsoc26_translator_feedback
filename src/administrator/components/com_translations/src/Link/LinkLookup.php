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
 * What LinkTranslator needs to know about the site's items and their translations.
 *
 * Items are named by content type key, as in contenttypes.json: 'com_content.article',
 * 'com_categories.category', 'com_tags.tag', 'com_menus.item'.
 *
 * @since  2.0.0
 */
interface LinkLookup
{
    /**
     * A site menu item that is not trashed, or null.
     *
     * @param   integer  $id  The menu item id.
     *
     * @return  MenuItem|null
     *
     * @since   2.0.0
     */
    public function menuItem(int $id): ?MenuItem;

    /**
     * The best menu item for a language that opens an item: one in that language before one for
     * all languages, a published one before an unpublished one. Null when there is none.
     *
     * @param   string   $opens     What it opens, as "option.view".
     * @param   integer  $id        The id of the item it opens.
     * @param   string   $language  The language.
     *
     * @return  MenuItem|null
     *
     * @since   2.0.0
     */
    public function menuItemOpening(string $opens, int $id, string $language): ?MenuItem;

    /**
     * An item's language, or null when it does not exist.
     *
     * @param   string   $contentType  The content type key.
     * @param   integer  $id           The item id.
     *
     * @return  string|null
     *
     * @since   2.0.0
     */
    public function language(string $contentType, int $id): ?string;

    /**
     * The id of an item's translation into a language, or null when it has none.
     *
     * @param   string   $contentType  The content type key.
     * @param   integer  $id           The item id.
     * @param   string   $language     The language.
     *
     * @return  integer|null
     *
     * @since   2.0.0
     */
    public function translation(string $contentType, int $id, string $language): ?int;
}
