<?php

/**
 * @package     Joomla.Administrator
 * @subpackage  com_translations
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Component\Translations\Administrator\Title;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * What the translated titles of an item's related items say about the translation of its own.
 *
 * A menu item and the article it opens mostly share their title, and each is translated in a
 * request of its own. On guide.joomla.org 237 to 320 of 593 such pairs per language came out
 * different, a few dozen of them with one side still in English. When the related item is
 * translated already, its title is the translation to use: the same title is taken over as it
 * is, and a title that holds the other, or is part of it, is given to the provider as a term to
 * translate the same way.
 *
 * @since  3.0.0
 */
final class RelatedTitles
{
    /**
     * The fewest characters a title needs to be looked for inside another one.
     *
     * @var    integer
     * @since  3.0.0
     */
    private const SHORTEST_PART = 3;

    /**
     * Read the item's title translation from those of its related items.
     *
     * A translated title identical to its source says nothing: it was most likely left in the
     * source language, which is the very mistake to be repaired, so it is never passed on. When
     * related items with the same title were translated differently, the translation most of them
     * share is taken, and the first one given on a tie.
     *
     * @param   string                              $title    The item's source title.
     * @param   array<array{0: string, 1: string}>  $related  Per related item its source title and its translated title.
     *
     * @return  array{title: ?string, terms: array<string, string>}  The translation for the title, or null when
     *                                                                 none is known, and the terms to keep.
     *
     * @since   3.0.0
     */
    public static function known(string $title, array $related): array
    {
        $title = self::tidy($title);
        $same  = [];
        $terms = [];

        foreach ($related as [$source, $translation]) {
            $source      = self::tidy($source);
            $translation = self::tidy($translation);

            if ($title === '' || $source === '' || $translation === '' || $translation === $source) {
                continue;
            }

            if ($source === $title) {
                $same[$translation] = ($same[$translation] ?? 0) + 1;
                continue;
            }

            if (self::overlaps($title, $source)) {
                $terms[$source] ??= $translation;
            }
        }

        if ($same === []) {
            return ['title' => null, 'terms' => $terms];
        }

        // arsort keeps the order of equal counts, so the first translation given wins a tie.
        arsort($same);
        $chosen = (string) array_key_first($same);

        // The body of the item may use the title's words too, and should use them the same way.
        return ['title' => $chosen, 'terms' => [$title => $chosen] + $terms];
    }

    /**
     * Whether one title is part of the other, ignoring case.
     *
     * @param   string  $title  One title.
     * @param   string  $other  The other title.
     *
     * @return  boolean
     *
     * @since   3.0.0
     */
    private static function overlaps(string $title, string $other): bool
    {
        [$short, $long] = mb_strlen($title) <= mb_strlen($other) ? [$title, $other] : [$other, $title];

        return mb_strlen($short) >= self::SHORTEST_PART && mb_stripos($long, $short) !== false;
    }

    /**
     * A title with its white space trimmed and runs of it made one space.
     *
     * @param   string  $title  The title.
     *
     * @return  string
     *
     * @since   3.0.0
     */
    private static function tidy(string $title): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $title));
    }
}
