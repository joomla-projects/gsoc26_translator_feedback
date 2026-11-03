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
 * A link to a page of this site in Joomla's own form.
 *
 * For example `index.php?Itemid=123&lang=en-GB` or
 * `index.php?option=com_content&view=article&id=5&catid=2&Itemid=123`, with or without a leading
 * path to index.php and an #anchor. Its query keeps the order it had, so a link that changes in
 * one value is written back as it was apart from that value.
 *
 * @since  2.0.0
 */
final class JoomlaLink
{
    /**
     * A path ending in index.php, without a scheme or host, and its query and fragment.
     *
     * @var    string
     * @since  2.0.0
     */
    private const PATTERN = '#^((?:/|\./)?(?:[^?\#:]*/)?index\.php)\?([^\#]*)(\#.*)?$#i';

    /**
     * Build a link from its parts.
     *
     * @param   string                 $path      The part before the "?", for example "index.php".
     * @param   array<string, string>  $query     The query, in the order it had.
     * @param   string                 $fragment  "#anchor", or ''.
     *
     * @since   2.0.0
     */
    private function __construct(
        public readonly string $path,
        public readonly array $query,
        public readonly string $fragment
    ) {
    }

    /**
     * The link in an href, or null when it is not a Joomla link.
     *
     * A typed URL, a link to another site and an index.php link with array values are not.
     *
     * @param   string  $href  The href, HTML-decoded.
     *
     * @return  self|null
     *
     * @since   2.0.0
     */
    public static function parse(string $href): ?self
    {
        if (!preg_match(self::PATTERN, trim($href), $match)) {
            return null;
        }

        $query = [];

        foreach (explode('&', $match[2]) as $pair) {
            if ($pair === '') {
                continue;
            }

            [$name, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $name           = rawurldecode($name);

            if ($name === '' || str_contains($name, '[')) {
                return null;
            }

            $query[$name] = rawurldecode(str_replace('+', ' ', $value));
        }

        return $query === [] ? null : new self($match[1], $query, $match[3] ?? '');
    }

    /**
     * A query parameter, or '' when the link has none by that name.
     *
     * @param   string  $name  The parameter.
     *
     * @return  string
     *
     * @since   2.0.0
     */
    public function get(string $name): string
    {
        return $this->query[$name] ?? '';
    }

    /**
     * A query parameter as an id: "5" and the slug "5:my-article" are both 5.
     *
     * @param   string  $name  The parameter.
     *
     * @return  integer
     *
     * @since   2.0.0
     */
    public function id(string $name): int
    {
        return (int) $this->get($name);
    }

    /**
     * This link with one parameter set; a new parameter goes at the end.
     *
     * @param   string  $name   The parameter.
     * @param   string  $value  Its value.
     *
     * @return  self
     *
     * @since   2.0.0
     */
    public function with(string $name, string $value): self
    {
        $query        = $this->query;
        $query[$name] = $value;

        return new self($this->path, $query, $this->fragment);
    }

    /**
     * The link as it goes into an href, before HTML encoding.
     *
     * Values are written as Joomla's editor writes them: unencoded, except what would break the query.
     *
     * @return  string
     *
     * @since   2.0.0
     */
    public function href(): string
    {
        $pairs = [];

        foreach ($this->query as $name => $value) {
            $pairs[] = $name . '=' . strtr($value, ['%' => '%25', '&' => '%26', '#' => '%23', ' ' => '%20']);
        }

        return $this->path . '?' . implode('&', $pairs) . $this->fragment;
    }
}
