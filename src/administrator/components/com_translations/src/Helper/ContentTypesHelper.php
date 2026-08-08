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
     * @param   string  $contentType  The content type key, e.g. 'com_content.article'.
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
     * List every mapped content type key.
     *
     * @return  string[]  The content type keys, e.g. 'com_content.article'.
     *
     * @since   0.4.0
     */
    public static function getContentTypes(): array
    {
        return array_keys(self::getMap());
    }

    /**
     * Read the content type key versioned under a Joomla type alias.
     *
     * Joomla keys a version by the type alias the item's model is versioned under, which is not
     * always our key for that content type: a category is versioned under the extension owning it.
     * A content type Joomla does not version carries no version type alias, so nothing matches it.
     *
     * @param   string  $versionTypeAlias  The type alias a version is stored under.
     *
     * @return  string|null  The content type key, or null when no mapped type is versioned under the alias.
     *
     * @since   0.9.0
     */
    public static function getContentTypeForVersionTypeAlias(string $versionTypeAlias): ?string
    {
        foreach (self::getMap() as $contentType => $properties) {
            if (($properties['versionTypeAlias'] ?? null) === $versionTypeAlias) {
                return (string) $contentType;
            }
        }

        return null;
    }

    /**
     * Read the content type edited on a form that carries the "no need for translation" toggle.
     *
     * The form name is not always our key for the content type, because a category's form is named
     * after the extension that owns it. A type that is not offered the toggle names no form, so
     * nothing matches it.
     *
     * @param   string  $formName  The name of the form being prepared.
     *
     * @return  string|null  The content type key, or null when no mapped type is edited on the form.
     *
     * @since   0.11.0
     */
    public static function getContentTypeForOptOutForm(string $formName): ?string
    {
        foreach (self::getMap() as $contentType => $properties) {
            if (($properties['optOutForm'] ?? null) === $formName) {
                return (string) $contentType;
            }
        }

        return null;
    }

    /**
     * Load the content type map once from contenttypes.json.
     *
     * @return  array  The map keyed by content type key.
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
