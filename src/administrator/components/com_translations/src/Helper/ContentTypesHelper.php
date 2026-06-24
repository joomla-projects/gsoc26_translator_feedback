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
 * Reads the content type translation map (contenttypes.json), which lists per
 * content type the table, fields, contexts and relations the translation pipeline needs.
 *
 * @since  0.4.0
 */
class ContentTypesHelper
{
    /**
     * The content type map, loaded once from contenttypes.json.
     *
     * @var    array|null
     * @since  0.4.0
     */
    private static ?array $map = null;

    /**
     * Read one content type's translation properties.
     *
     * @param   string  $contentType  The content type alias, e.g. 'com_content.article'.
     *
     * @return  array  The content type's properties.
     *
     * @throws  \RuntimeException  If the content type is not mapped.
     *
     * @since   0.4.0
     */
    public static function getProperties(string $contentType): array
    {
        $map = self::getMap();

        if (!isset($map[$contentType])) {
            throw new \RuntimeException(\sprintf('No translation properties mapped for content type "%s".', $contentType));
        }

        return (array) $map[$contentType];
    }

    /**
     * List every mapped content type alias.
     *
     * @return  string[]  The content type aliases, e.g. 'com_content.article'.
     *
     * @since   0.4.0
     */
    public static function getContentTypes(): array
    {
        return array_keys(self::getMap());
    }

    /**
     * Load the content type map once from contenttypes.json.
     *
     * @return  array  The map keyed by content type alias.
     *
     * @throws  \RuntimeException  If the map file is missing.
     *
     * @since   0.4.0
     */
    private static function getMap(): array
    {
        if (self::$map === null) {
            $path = JPATH_ADMINISTRATOR . '/components/com_translations/contenttypes.json';

            if (!is_file($path)) {
                throw new \RuntimeException('The content type translation map (contenttypes.json) is missing.');
            }

            $decoded = json_decode((string) file_get_contents($path), true);

            self::$map = (\is_array($decoded) && isset($decoded['contentTypes']) && \is_array($decoded['contentTypes']))
                ? $decoded['contentTypes']
                : [];
        }

        return self::$map;
    }
}
