<?php

/**
 * @package     Joomla.Administrator
 * @subpackage  com_translations
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Component\Translations\Administrator\Image;

use Joomla\Registry\Registry;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Points the images in a translation at their translated versions, where those are on the site.
 *
 * A documentation site shows screenshots of the interface, and a translated page reads better with
 * screenshots of the translated interface. When a translated image exists, at the same path below
 * the language's own image folder, the translation shows it; otherwise it keeps the original. The
 * folders are lines of `source folder = translated folder`, the translated folder with
 * `{language}` for the language tag (nl-NL) or `{lang}` for its code (nl, or the code given for
 * that language). guide.joomla.org, whose translated screenshots come from Jdocmanual:
 *
 *     images/manuals/user/en/images = images/manuals/user/{lang}/images
 *     images/help-pages = images/help-pages-{lang}
 *
 * Only `src`, `data-src` and `href` values (a screenshot often links to its full size), written as
 * Joomla writes them: `images/...` or `/images/...`, with or without the media field's
 * `#joomlaImage://...` after it. A translated address is in no source folder, so doing it twice
 * changes nothing the second time.
 *
 * Worked out in the image-translations package, which also brings Jdocmanual's screenshots in.
 *
 * @since  4.0.0
 */
final class TranslatedImages
{
    /**
     * The placeholder for the language tag in a translated folder.
     *
     * @var    string
     * @since  4.0.0
     */
    public const LANGUAGE = '{language}';

    /**
     * The placeholder for the language code in a translated folder.
     *
     * @var    string
     * @since  4.0.0
     */
    public const CODE = '{lang}';

    /**
     * The folders when none are given: an image's translation has the same path below
     * images/<language tag>.
     *
     * @var    string
     * @since  4.0.0
     */
    public const DEFAULT_FOLDERS = 'images = images/{language}';

    /**
     * Whether a file, given as a path from the site root, is there.
     *
     * @var    \Closure(string): bool
     * @since  4.0.0
     */
    private readonly \Closure $exists;

    /**
     * The source folders, longest first, and their translated folders with placeholders.
     *
     * @var    array<string, string>
     * @since  4.0.0
     */
    private readonly array $folders;

    /**
     * Build it.
     *
     * @param   array<string, string>  $folders  Source folder => translated folder, from the site root.
     * @param   array<string, string>  $codes    Language tag => code, where the code is not the tag's first part.
     * @param   callable               $exists   Whether a file, a path from the site root, is there.
     *
     * @since   4.0.0
     */
    public function __construct(array $folders, private readonly array $codes, callable $exists)
    {
        uksort($folders, static fn (string $one, string $other): int => \strlen($other) <=> \strlen($one));

        $this->folders = $folders;
        $this->exists  = \Closure::fromCallable($exists);
    }

    /**
     * The images of the site in a folder, from the component's options; null while the option is off.
     *
     * @param   Registry  $params  The component's options.
     * @param   string    $root    The site's root folder.
     *
     * @return  self|null
     *
     * @since   4.0.0
     */
    public static function fromParams(Registry $params, string $root): ?self
    {
        if (!(int) $params->get('translated_images', 0)) {
            return null;
        }

        $folders = self::parseLines((string) $params->get('image_folders', self::DEFAULT_FOLDERS));

        if ($folders === []) {
            return null;
        }

        return new self(
            $folders,
            self::parseLines((string) $params->get('image_language_codes', '')),
            static fn (string $path): bool => is_file($root . '/' . $path)
        );
    }

    /**
     * Lines of `left = right` as an array, skipping empty lines and lines without both sides.
     * Slashes around a side are dropped.
     *
     * @param   string  $text  The lines.
     *
     * @return  array<string, string>
     *
     * @since   4.0.0
     */
    public static function parseLines(string $text): array
    {
        $pairs = [];

        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            $parts = explode('=', $line, 2);

            if (\count($parts) !== 2) {
                continue;
            }

            $left  = trim(trim($parts[0]), '/');
            $right = trim(trim($parts[1]), '/');

            if ($left !== '' && $right !== '') {
                $pairs[$left] = $right;
            }
        }

        return $pairs;
    }

    /**
     * Point the images in a piece of HTML at their translations for a language.
     *
     * @param   string  $html      The HTML.
     * @param   string  $language  The language tag of the translation.
     *
     * @return  string
     *
     * @since   4.0.0
     */
    public function translateHtml(string $html, string $language): string
    {
        if ($html === '' || stripos($html, 'images/') === false) {
            return $html;
        }

        return preg_replace_callback(
            '/(\s(?:src|data-src|href)\s*=\s*)(["\'])(.*?)\2/is',
            fn (array $m): string => $m[1] . $m[2] . $this->translateValue($m[3], $language) . $m[2],
            $html
        ) ?? $html;
    }

    /**
     * One address, pointed at its translation for a language; as it was when that is not on the site.
     *
     * @param   string  $value     The address.
     * @param   string  $language  The language tag of the translation.
     *
     * @return  string
     *
     * @since   4.0.0
     */
    public function translateValue(string $value, string $language): string
    {
        if (!preg_match('/^(\/?)([^?#]*)(.*)$/s', $value, $parts)) {
            return $value;
        }

        [, $slash, $path, $rest] = $parts;

        // An address that climbs out of its folder is left alone, rather than checked anywhere on the server.
        if (preg_match('#(^|/)\.\.(/|$)#', rawurldecode($path))) {
            return $value;
        }

        foreach ($this->folders as $source => $translated) {
            if (!str_starts_with($path, $source . '/')) {
                continue;
            }

            $target = $this->folderFor($translated, $language) . '/' . substr($path, \strlen($source) + 1);

            if ($target === $path || !($this->exists)(rawurldecode($target))) {
                return $value;
            }

            // The media field repeats the path below images/ after the hash.
            if ($rest !== '' && str_starts_with($path, 'images/') && str_starts_with($target, 'images/')) {
                $rest = str_replace('local-images/' . substr($path, 7), 'local-images/' . substr($target, 7), $rest);
            }

            return $slash . $target . $rest;
        }

        return $value;
    }

    /**
     * A translated folder for a language: its placeholders filled in.
     *
     * @param   string  $translated  The translated folder, with placeholders.
     * @param   string  $language    The language tag.
     *
     * @return  string
     *
     * @since   4.0.0
     */
    private function folderFor(string $translated, string $language): string
    {
        $code = $this->codes[$language] ?? strtolower(explode('-', $language)[0]);

        return str_replace([self::LANGUAGE, self::CODE], [$language, $code], $translated);
    }
}
