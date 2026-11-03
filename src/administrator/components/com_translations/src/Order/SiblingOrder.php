<?php

/**
 * @package     Joomla.Administrator
 * @subpackage  com_translations
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Component\Translations\Administrator\Order;

use Joomla\Registry\Registry;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * The order the translations of an item's children go in.
 *
 * By default that is the source's order. A parent can ask for its children's translations to be
 * sorted by their translated title instead, because an alphabetical list in the source is not
 * alphabetical once its titles are translated: the field types under Fields, a glossary. A number
 * of children at the start and at the end can be kept where the source has them, for an overview
 * page before the list or a "See also" after it.
 *
 * The setting is kept in the parent's params, so it travels with the item and is edited in its form.
 *
 * @since  2.0.0
 */
final class SiblingOrder
{
    /**
     * The param holding how the children's translations are ordered: 'source' or 'title'.
     *
     * @var    string
     * @since  2.0.0
     */
    public const ORDER = 'translations_children_order';

    /**
     * The param holding how many children at the start keep the source's order.
     *
     * @var    string
     * @since  2.0.0
     */
    public const FIRST = 'translations_children_first';

    /**
     * The param holding how many children at the end keep the source's order.
     *
     * @var    string
     * @since  2.0.0
     */
    public const LAST = 'translations_children_last';

    /**
     * The ORDER value that sorts by translated title.
     *
     * @var    string
     * @since  2.0.0
     */
    public const BY_TITLE = 'title';

    /**
     * Read a parent's setting from its params.
     *
     * @param   string  $params  The parent's params, as stored.
     *
     * @return  array{first: int, last: int}|null  How many children keep their place at either end, or null when the source's order is kept.
     *
     * @since   2.0.0
     */
    public static function fromParams(string $params): ?array
    {
        $registry = new Registry($params);

        if ((string) $registry->get(self::ORDER, '') !== self::BY_TITLE) {
            return null;
        }

        return [
            'first' => max(0, (int) $registry->get(self::FIRST, 0)),
            'last'  => max(0, (int) $registry->get(self::LAST, 0)),
        ];
    }

    /**
     * The order of a parent's children for their translations in one language.
     *
     * Children without a translation have no title to sort by, and come after the sorted ones; they
     * have no place to take yet, so where they stand does not matter.
     *
     * @param   int[]               $children  The source children, in the source's order.
     * @param   array<int, string>  $titles    The translated title per child that has a translation.
     * @param   int                 $first     How many children at the start keep their place.
     * @param   int                 $last      How many children at the end keep their place.
     * @param   string              $language  The language the titles are in, e.g. 'nl-NL'.
     *
     * @return  int[]  The source children, in the order their translations go.
     *
     * @since   2.0.0
     */
    public static function arrange(array $children, array $titles, int $first, int $last, string $language): array
    {
        $children = array_values($children);
        $count    = \count($children);
        $first    = min(max(0, $first), $count);
        $last     = min(max(0, $last), $count - $first);
        $middle   = \array_slice($children, $first, $count - $first - $last);

        $sorted = array_values(array_filter($middle, static fn (int $id): bool => isset($titles[$id])));
        $compare = self::comparison($language);

        // usort is stable, so two equal titles keep the source's order.
        usort($sorted, static fn (int $a, int $b): int => $compare($titles[$a], $titles[$b]));

        return array_merge(
            \array_slice($children, 0, $first),
            $sorted,
            array_values(array_diff($middle, $sorted)),
            \array_slice($children, $count - $last)
        );
    }

    /**
     * How two titles compare in a language: its own alphabet where intl is there, numbers by value.
     *
     * @param   string  $language  The language code, e.g. 'nl-NL'.
     *
     * @return  callable(string, string): int
     *
     * @since   2.0.0
     */
    private static function comparison(string $language): callable
    {
        if (class_exists(\Collator::class)) {
            $collator = new \Collator(str_replace('-', '_', $language));
            $collator->setAttribute(\Collator::NUMERIC_COLLATION, \Collator::ON);

            return static fn (string $a, string $b): int => (int) $collator->compare($a, $b);
        }

        return static fn (string $a, string $b): int => strnatcmp(mb_strtolower($a), mb_strtolower($b));
    }
}
