<?php

/**
 * @package     Joomla.Administrator
 * @subpackage  com_translations
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Component\Translations\Administrator\Helper;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * How one run's batch is shared between the target languages.
 *
 * The languages take turns: the first item waiting in each language, then the second of each, and
 * so on until the batch is full. A language with nothing left gives its turns to the others. So a
 * new item reaches every language in the same run, and a language added later catches up at the
 * pace of the others instead of holding them up until it has caught up.
 *
 * A run is often cut short by its time budget after a few items, so the language that goes first
 * matters as much as the turns: the one translated into least recently goes first, and every
 * language gets its turn across runs, however few items each run manages.
 *
 * @since  1.6.1
 */
final class LanguageShare
{
    /**
     * Interleave the items waiting in each language.
     *
     * @param   array<string, array>  $waiting  Per language, in the order the languages go first, the
     *                                          items waiting in that language, in the order they go.
     * @param   integer               $limit    The most items to return.
     *
     * @return  array<int, array{0: string, 1: mixed}>  The language and the item, in the order they go.
     *
     * @since   1.6.1
     */
    public static function interleave(array $waiting, int $limit): array
    {
        $turns = [];

        for ($position = 0; \count($turns) < $limit; $position++) {
            $taken = false;

            foreach ($waiting as $language => $items) {
                if (!\array_key_exists($position, $items)) {
                    continue;
                }

                $turns[] = [(string) $language, $items[$position]];
                $taken   = true;

                if (\count($turns) === $limit) {
                    break;
                }
            }

            if (!$taken) {
                break;
            }
        }

        return $turns;
    }

    /**
     * Order the languages so the one translated into least recently goes first.
     *
     * @param   string[]               $languages     The languages, in the site's order.
     * @param   array<string, string>  $lastAttempts  Per language, when an item was last attempted in
     *                                                it; a language without an entry was never tried.
     *
     * @return  string[]  The languages, a never tried one first, then the least recent; a tie keeps
     *                    the site's order.
     *
     * @since   1.6.1
     */
    public static function leastRecentFirst(array $languages, array $lastAttempts): array
    {
        $position = array_flip(array_values($languages));

        usort(
            $languages,
            static fn(string $a, string $b): int => [$lastAttempts[$a] ?? '', $position[$a]]
                <=> [$lastAttempts[$b] ?? '', $position[$b]]
        );

        return $languages;
    }
}
