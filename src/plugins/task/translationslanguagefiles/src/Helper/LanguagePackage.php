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

use Joomla\CMS\Factory;
use Joomla\CMS\Installer\Installer;
use Joomla\CMS\Uri\Uri;
use Joomla\Database\DatabaseInterface;
use Joomla\Filesystem\Folder;

/**
 * Makes the translations of one language an extension Joomla can install.
 *
 * The package is a file extension whose files go where this site keeps their en-GB originals,
 * with the language tag in place of en-GB, so it works on any site that installed the extensions
 * the same way. Its element is the same for every build of a language, so installing a newer
 * build replaces the older one, and uninstalling it removes the files again. The same package is
 * offered as a download and installed on this site directly, through Joomla's installer, so the
 * site records it like any other extension.
 *
 * @since  1.1.0
 */
final class LanguagePackage
{
    /**
     * Constructor.
     *
     * @param   DatabaseInterface       $db          The site's database.
     * @param   LanguageFileTranslator  $translator  Where the translations are kept.
     * @param   array                   $files       The language files, as their discover() describes them.
     * @param   string                  $element     The start of the package's element; the language tag is added to it.
     * @param   string                  $title       Whose language files the package holds, for its name.
     *
     * @since   1.1.0
     */
    public function __construct(
        private readonly DatabaseInterface $db,
        private readonly LanguageFileTranslator $translator,
        private readonly array $files,
        private readonly string $element,
        private readonly string $title
    ) {
    }

    /**
     * The element of a language's package.
     *
     * @param   string  $language  The language.
     *
     * @return  string
     *
     * @since   1.1.0
     */
    public function element(string $language): string
    {
        return $this->element . '_' . strtolower($language);
    }

    /**
     * The file name to offer a language's package under.
     *
     * @param   string  $language  The language.
     *
     * @return  string
     *
     * @since   1.1.0
     */
    public function fileName(string $language): string
    {
        return 'files_' . $this->element($language) . '-' . Factory::getDate()->format('Y-m-d') . '.zip';
    }

    /**
     * The version of a language's package installed on this site.
     *
     * @param   string  $language  The language.
     *
     * @return  string|null  The version, or null when it is not installed.
     *
     * @since   1.1.0
     */
    public function installedVersion(string $language): ?string
    {
        $element = $this->element($language);
        $type    = 'file';
        $query   = $this->db->getQuery(true)
            ->select($this->db->quoteName('manifest_cache'))
            ->from($this->db->quoteName('#__extensions'))
            ->where($this->db->quoteName('type') . ' = :type')
            ->where($this->db->quoteName('element') . ' = :element')
            ->bind(':type', $type)
            ->bind(':element', $element);
        $cache = $this->db->setQuery($query)->loadResult();

        return $cache === null ? null : (string) (json_decode((string) $cache, true)['version'] ?? '');
    }

    /**
     * Write a language's package as an installable zip.
     *
     * @param   string  $language  The language.
     *
     * @return  string  The path of the zip, a temporary file the caller removes.
     *
     * @throws  \RuntimeException  When the language has no translations, or the zip cannot be written.
     *
     * @since   1.1.0
     */
    public function build(string $language): string
    {
        $path = tempnam(Factory::getApplication()->get('tmp_path', sys_get_temp_dir()), 'tlp');
        $zip  = new \ZipArchive();

        if ($path === false || $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('The package could not be written.');
        }

        foreach ($this->contents($language) as $name => $contents) {
            $zip->addFromString($name, $contents);
        }

        $zip->close();

        return $path;
    }

    /**
     * Install a language's package on this site.
     *
     * @param   string  $language  The language.
     *
     * @return  boolean  Whether the installer succeeded; when it did not, it has said why.
     *
     * @throws  \RuntimeException  When the language has no translations, or the package cannot be written.
     *
     * @since   1.1.0
     */
    public function install(string $language): bool
    {
        $folder = Factory::getApplication()->get('tmp_path', sys_get_temp_dir()) . '/' . uniqid('tlp_', true);

        try {
            foreach ($this->contents($language) as $name => $contents) {
                $target = $folder . '/' . $name;

                if ((!is_dir(\dirname($target)) && !mkdir(\dirname($target), 0755, true)) || file_put_contents($target, $contents) === false) {
                    throw new \RuntimeException('The package could not be written.');
                }
            }

            return Installer::getInstance()->install($folder);
        } finally {
            if (is_dir($folder)) {
                Folder::delete($folder);
            }
        }
    }

    /**
     * The files of a language's package, its manifest among them.
     *
     * A language file goes in only when it has a translated string; a string not translated yet
     * stays in English in it, as in the download for the repository.
     *
     * @param   string  $language  The language.
     *
     * @return  array<string, string>  The contents, keyed by the path in the package.
     *
     * @throws  \RuntimeException  When the language has no translations.
     *
     * @since   1.1.0
     */
    private function contents(string $language): array
    {
        $translations = $this->translator->translations($language);
        $contents     = [];
        $folders      = [];

        foreach ($this->files as $name => $file) {
            if (($translations[$name] ?? []) === []) {
                continue;
            }

            $folder = self::installFolder($file['source'], $language);

            $contents[$folder . '/' . $name] = IniWriter::write((string) file_get_contents($file['source']), $translations[$name]);
            $folders[$folder][]              = $name;
        }

        if ($contents === []) {
            throw new \RuntimeException(\sprintf('There are no translations into %s yet.', $language));
        }

        $contents[$this->element($language) . '.xml'] = $this->manifest($language, $folders);

        return $contents;
    }

    /**
     * Where a translation goes on a site, relative to its root: the folder of the en-GB original
     * with the language in place of en-GB.
     *
     * @param   string  $source    The path of the en-GB original on this site.
     * @param   string  $language  The language.
     *
     * @return  string
     *
     * @throws  \RuntimeException  When the original is not in a language folder of this site.
     *
     * @since   1.1.0
     */
    public static function installFolder(string $source, string $language): string
    {
        $root   = str_replace('\\', '/', JPATH_ROOT) . '/';
        $folder = \dirname(str_replace('\\', '/', $source));

        if (!str_starts_with($folder, $root) || basename($folder) !== 'en-GB') {
            throw new \RuntimeException(\sprintf('%s is not in a language folder of this site.', $source));
        }

        return substr(\dirname($folder), \strlen($root)) . '/' . $language;
    }

    /**
     * The manifest of a language's package.
     *
     * @param   string                    $language  The language.
     * @param   array<string, string[]>   $folders   The file names, keyed by the folder they go in.
     *
     * @return  string  The manifest, in XML.
     *
     * @since   1.1.0
     */
    private function manifest(string $language, array $folders): string
    {
        $app     = Factory::getApplication();
        $escape  = static fn(string $text): string => htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $now     = Factory::getDate();
        $fileset = '';

        foreach ($folders as $folder => $names) {
            $fileset .= "\t\t<files folder=\"" . $escape($folder) . '" target="' . $escape($folder) . "\">\n";

            foreach ($names as $name) {
                $fileset .= "\t\t\t<filename>" . $escape($name) . "</filename>\n";
            }

            $fileset .= "\t\t</files>\n";
        }

        return "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
            . "<extension type=\"file\" method=\"upgrade\">\n"
            . "\t<name>" . $escape($this->title . ' language files (' . $language . ')') . "</name>\n"
            . "\t<author>" . $escape((string) $app->get('sitename')) . "</author>\n"
            . "\t<creationDate>" . $now->format('Y-m-d') . "</creationDate>\n"
            . "\t<version>" . $now->format('Y.m.d.Hi') . "</version>\n"
            . "\t<description>" . $escape(\sprintf(
                'The %s language files of %s, translated on %s with its translation rules.',
                $language,
                $this->title,
                Uri::root()
            )) . "</description>\n"
            . "\t<fileset>\n" . $fileset . "\t</fileset>\n"
            . "</extension>\n";
    }
}
