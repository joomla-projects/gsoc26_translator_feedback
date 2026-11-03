<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  Task.TranslationsLanguageFiles
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Plugin\Task\TranslationsLanguageFiles\Helper;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use Joomla\CMS\Language\LanguageHelper;
use Joomla\Database\DatabaseInterface;

/**
 * Finds the language files of Translator Feedback as this site installed them.
 *
 * The package is the source of truth for what its extensions are: every extension its installed
 * manifest lists, the package itself, and the seed task, which is a download of its own
 * but lives in the same repository. Each file is described both where it is read on this site and
 * where its translation belongs in the repository's src folder, with the manifest that has to
 * name it, so the files that come out of this plugin can be put straight into the package.
 *
 * @since  1.0.0
 */
final class PackageLanguageFiles
{
    /**
     * The package whose language files are translated.
     *
     * @var    string
     * @since  1.0.0
     */
    public const PACKAGE = 'pkg_translations';

    /**
     * The language the package's strings are written in.
     *
     * @var    string
     * @since  1.0.0
     */
    public const SOURCE_LANGUAGE = 'en-GB';

    /**
     * The plugins released on their own from the same repository, as type, group and element.
     *
     * @var    string[][]
     * @since  1.0.0
     */
    private const SEPARATE_DOWNLOADS = [['plugin', 'task', 'translationsseed']];

    /**
     * Every language file of the package that this site has in the source language.
     *
     * @param   DatabaseInterface  $db  The site's database.
     *
     * @return  array<string, array>  Keyed by file name: the extension and its version, the path the
     *                                file is read from, and, with {tag} for the language, the path its
     *                                translation has in the repository, the manifest that lists it and
     *                                the entry that manifest needs.
     *
     * @since   1.0.0
     */
    public static function discover(DatabaseInterface $db): array
    {
        $files = [];

        foreach (self::extensions($db) as $extension) {
            foreach (self::describe($extension) as $file) {
                $source = self::sourcePath($file['name'], $file['ownFolder']);

                if ($source !== null) {
                    $file['source']       = $source;
                    $files[$file['name']] = $file;
                }
            }
        }

        ksort($files);

        return $files;
    }

    /**
     * Read the strings of one language file in the source language.
     *
     * @param   array  $file  A file as discover() describes it.
     *
     * @return  array<string, string>  The strings, keyed by language constant.
     *
     * @since   1.0.0
     */
    public static function strings(array $file): array
    {
        return LanguageHelper::parseIniFile($file['source']);
    }

    /**
     * The package, its extensions and the separately released ones, as installed.
     *
     * The extensions are the ones the package's installed manifest lists, not the ones whose
     * package_id points at the package: a site can have other extensions carrying that id, for
     * instance when the id of an uninstalled package was given out again, and their strings are
     * not this package's to translate.
     *
     * @param   DatabaseInterface  $db  The site's database.
     *
     * @return  array  One row per extension with its type, element, folder and version.
     *
     * @since   1.0.0
     */
    private static function extensions(DatabaseInterface $db): array
    {
        $package = self::installed($db, 'package', '', self::PACKAGE);

        if ($package === null) {
            return [];
        }

        $rows = [$package];

        foreach (array_merge(self::members(), self::SEPARATE_DOWNLOADS) as [$type, $folder, $element]) {
            $row = self::installed($db, $type, $folder, $element);

            if ($row !== null) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * The extensions the package's installed manifest lists.
     *
     * @return  string[][]  The type, folder and element of each, the folder empty for all but plugins.
     *
     * @since   1.1.1
     */
    private static function members(): array
    {
        $path     = JPATH_MANIFESTS . '/packages/' . self::PACKAGE . '.xml';
        $manifest = is_file($path) ? simplexml_load_file($path) : false;

        if ($manifest === false || !isset($manifest->files->file)) {
            return [];
        }

        $members = [];

        foreach ($manifest->files->file as $file) {
            $type      = (string) $file['type'];
            $members[] = [$type, $type === 'plugin' ? (string) $file['group'] : '', (string) $file['id']];
        }

        return $members;
    }

    /**
     * One extension as this site has it installed.
     *
     * @param   DatabaseInterface  $db       The site's database.
     * @param   string             $type     The extension type.
     * @param   string             $folder   The plugin group, empty for other types.
     * @param   string             $element  The element it is stored under.
     *
     * @return  array|null  The row with its type, element, folder and manifest cache, or null when it is not installed.
     *
     * @since   1.1.1
     */
    private static function installed(DatabaseInterface $db, string $type, string $folder, string $element): ?array
    {
        $query = $db->getQuery(true)
            ->select($db->quoteName(['type', 'element', 'folder', 'manifest_cache']))
            ->from($db->quoteName('#__extensions'))
            ->where($db->quoteName('type') . ' = :type')
            ->where($db->quoteName('element') . ' = :element')
            ->bind(':type', $type)
            ->bind(':element', $element);

        if ($type === 'plugin') {
            $query->where($db->quoteName('folder') . ' = :folder')
                ->bind(':folder', $folder);
        }

        return $db->setQuery($query)->loadAssoc() ?: null;
    }

    /**
     * The language files an extension carries, and where they belong in the repository.
     *
     * The repository keeps each extension's language folder inside the extension's own folder, and
     * its manifest lists every file per language tag. The package's own file sits in the language
     * folder at the top of src, under a manifest whose languages folder is that one.
     *
     * @param   array  $extension  The extension row.
     *
     * @return  array  The extension's files, with {tag} where the language goes.
     *
     * @since   1.0.0
     */
    private static function describe(array $extension): array
    {
        $element = (string) $extension['element'];
        $folder  = (string) $extension['folder'];
        $version = (string) (json_decode((string) $extension['manifest_cache'], true)['version'] ?? '');

        switch ($extension['type']) {
            case 'package':
                return [[
                    'name'          => $element . '.sys.ini',
                    'extension'     => $element,
                    'version'       => $version,
                    'ownFolder'     => '',
                    'packagePath'   => 'language/{tag}/' . $element . '.sys.ini',
                    'manifest'      => 'administrator/manifests/packages/' . $element . '.xml',
                    'manifestEntry' => '{tag}/' . $element . '.sys.ini',
                ]];

            case 'component':
                $base      = 'administrator/components/' . $element;
                $manifest  = $base . '/' . substr($element, 4) . '.xml';
                $ownFolder = JPATH_ADMINISTRATOR . '/components/' . $element;
                break;

            case 'plugin':
                $base      = 'plugins/' . $folder . '/' . $element;
                $manifest  = $base . '/' . $element . '.xml';
                $ownFolder = JPATH_PLUGINS . '/' . $folder . '/' . $element;
                $element   = 'plg_' . $folder . '_' . $element;
                break;

            default:
                return [];
        }

        $files = [];

        foreach (['.ini', '.sys.ini'] as $suffix) {
            $files[] = [
                'name'          => $element . $suffix,
                'extension'     => $element,
                'version'       => $version,
                'ownFolder'     => $ownFolder,
                'packagePath'   => $base . '/language/{tag}/' . $element . $suffix,
                'manifest'      => $manifest,
                'manifestEntry' => 'language/{tag}/' . $element . $suffix,
            ];
        }

        return $files;
    }

    /**
     * Where this site keeps a language file in the source language.
     *
     * Joomla installs the files a manifest lists into the administrator's language folder; a file
     * an extension carries in its own folder is the fallback Joomla itself reads.
     *
     * @param   string  $name       The file name.
     * @param   string  $ownFolder  The extension's own folder, empty for none.
     *
     * @return  string|null  The path, or null when the site does not have the file.
     *
     * @since   1.0.0
     */
    private static function sourcePath(string $name, string $ownFolder): ?string
    {
        $candidates = [
            JPATH_ADMINISTRATOR . '/language/' . self::SOURCE_LANGUAGE . '/' . $name,
            JPATH_SITE . '/language/' . self::SOURCE_LANGUAGE . '/' . $name,
        ];

        if ($ownFolder !== '') {
            $candidates[] = $ownFolder . '/language/' . self::SOURCE_LANGUAGE . '/' . $name;
        }

        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}
