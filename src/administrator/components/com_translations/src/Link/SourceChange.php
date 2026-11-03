<?php

/**
 * @package     Joomla.Administrator
 * @subpackage  com_translations
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Component\Translations\Administrator\Link;

use Joomla\Component\Translations\Administrator\Helper\TranslatableValuesHelper;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * What changed in a source between two of its versions, as far as its translations are concerned.
 *
 * Joomla stores a version whenever an item is saved with any change: its state, its links. Only a
 * change in the text there is to translate, or in what a translation copies from its source (its
 * category, access, tags), makes a translation out of date. A change in nothing but the targets of
 * its links is followed in the translation by repointing the same links, without translating
 * anything again.
 *
 * @since  2.0.0
 */
final class SourceChange
{
    /**
     * Nothing to translate changed.
     *
     * @var    string
     * @since  2.0.0
     */
    public const NONE = 'none';

    /**
     * Only the targets of links changed.
     *
     * @var    string
     * @since  2.0.0
     */
    public const LINKS = 'links';

    /**
     * The text to translate changed, or what a translation copies from its source.
     *
     * @var    string
     * @since  2.0.0
     */
    public const TEXT = 'text';

    /**
     * What stands in for every href when two texts are compared apart from their links.
     *
     * @var    string
     * @since  2.0.0
     */
    private const ANY_LINK = '#';

    /**
     * Compare two versions of a source item.
     *
     * Custom fields count when a version holds them, which one saved from the edit form does: a
     * difference in them, or a version without them against one with them, is a change in the text.
     * A copied field that only one of the versions holds, as tags are held by one saved from the
     * edit form only, is not compared.
     *
     * @param   array     $old                 The version the translation was made from, as stored.
     * @param   array     $new                 The version stored now.
     * @param   array     $translatableFields  The content type's translatable field list.
     * @param   string[]  $copiedFields        The fields a translation copies from its source, or
     *                                         repoints at the translation of what they name.
     *
     * @return  array{change: string, links: array<string, string>}  What changed, and for a change
     *          in links only, each old link with the link that replaced it.
     *
     * @since   2.0.0
     */
    public static function between(array $old, array $new, array $translatableFields, array $copiedFields = []): array
    {
        $text = ['change' => self::TEXT, 'links' => []];

        if (($old['com_fields'] ?? null) != ($new['com_fields'] ?? null)) {
            return $text;
        }

        foreach ($copiedFields as $field) {
            if (\array_key_exists($field, $old) && \array_key_exists($field, $new) && $old[$field] != $new[$field]) {
                return $text;
            }
        }

        $oldValues = TranslatableValuesHelper::flattenFields($old, $translatableFields);
        $newValues = TranslatableValuesHelper::flattenFields($new, $translatableFields);
        $links     = [];

        foreach ($newValues as $field => $newValue) {
            $oldValue = $oldValues[$field] ?? '';

            if ($oldValue === $newValue) {
                continue;
            }

            if (self::withoutLinks($oldValue) !== self::withoutLinks($newValue)) {
                return $text;
            }

            // The texts are the same apart from their hrefs, so they hold the same links in the same order.
            foreach (array_map(null, HtmlLinks::hrefs($oldValue), HtmlLinks::hrefs($newValue)) as [$oldHref, $newHref]) {
                if ($oldHref === $newHref) {
                    continue;
                }

                // A link changed into two different ones cannot be followed in a translation.
                if (isset($links[$oldHref]) && $links[$oldHref] !== $newHref) {
                    return $text;
                }

                $links[(string) $oldHref] = (string) $newHref;
            }
        }

        return ['change' => $links === [] ? self::NONE : self::LINKS, 'links' => $links];
    }

    /**
     * The text with every href replaced by the same placeholder.
     *
     * @param   string  $html  The text.
     *
     * @return  string
     *
     * @since   2.0.0
     */
    private static function withoutLinks(string $html): string
    {
        return HtmlLinks::rewrite($html, static fn (string $href): string => self::ANY_LINK);
    }
}
