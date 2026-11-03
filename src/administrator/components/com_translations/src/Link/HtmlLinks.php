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
 * Reads and changes the hrefs of the `<a>` tags in a piece of HTML.
 *
 * Regular expressions rather than a DOM parser on purpose: only the href values may change, and a
 * DOM round trip re-formats a whole article. The tag's other attributes, the quotes around the
 * value, the link text and everything outside the tags stay byte for byte as they were.
 *
 * @since  2.0.0
 */
final class HtmlLinks
{
    /**
     * An opening `<a>` tag.
     *
     * @var    string
     * @since  2.0.0
     */
    private const TAG = '#<a\b[^>]*>#i';

    /**
     * The href attribute in a tag, double-, single- or unquoted; not an attribute such as data-href.
     *
     * @var    string
     * @since  2.0.0
     */
    private const HREF = '#((?<![\w-])href\s*=\s*)(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'>]+))#i';

    /**
     * The hrefs in the HTML, HTML-decoded, in the order they appear.
     *
     * @param   string  $html  The HTML.
     *
     * @return  string[]
     *
     * @since   2.0.0
     */
    public static function hrefs(string $html): array
    {
        if (stripos($html, '<a') === false || !preg_match_all(self::TAG, $html, $tags)) {
            return [];
        }

        $hrefs = [];

        foreach ($tags[0] as $tag) {
            $href = self::hrefOf($tag);

            if ($href !== null) {
                $hrefs[] = $href;
            }
        }

        return $hrefs;
    }

    /**
     * The HTML with each href replaced by what the callback gives for it.
     *
     * @param   string                         $html     The HTML.
     * @param   callable(string): ?string      $repoint  Gets an href, HTML-decoded; returns its new value,
     *                                                   not yet HTML-encoded, or null to leave it.
     *
     * @return  string
     *
     * @since   2.0.0
     */
    public static function rewrite(string $html, callable $repoint): string
    {
        if (stripos($html, '<a') === false) {
            return $html;
        }

        $result = preg_replace_callback(
            self::TAG,
            static function (array $tag) use ($repoint): string {
                $href = self::hrefOf($tag[0]);
                $new  = $href === null ? null : $repoint($href);

                if ($new === null || $new === $href) {
                    return $tag[0];
                }

                $encoded = htmlspecialchars($new, ENT_QUOTES | ENT_HTML5, 'UTF-8');

                return (string) preg_replace_callback(
                    self::HREF,
                    static function (array $attribute) use ($encoded): string {
                        $quote = ($attribute[3] ?? '') !== '' && ($attribute[2] ?? '') === '' ? "'" : '"';

                        return $attribute[1] . $quote . $encoded . $quote;
                    },
                    $tag[0],
                    1
                );
            },
            $html
        );

        return $result ?? $html;
    }

    /**
     * The decoded href in a tag, or null when it has none.
     *
     * @param   string  $tag  The opening tag.
     *
     * @return  string|null
     *
     * @since   2.0.0
     */
    private static function hrefOf(string $tag): ?string
    {
        if (!preg_match(self::HREF, $tag, $match)) {
            return null;
        }

        $raw = ($match[2] ?? '') . ($match[3] ?? '') . ($match[4] ?? '');

        return trim(html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }
}
