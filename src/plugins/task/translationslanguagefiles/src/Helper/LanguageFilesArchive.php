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
use Joomla\CMS\Uri\Uri;
use Joomla\Database\DatabaseInterface;

/**
 * Packs the translated language files into one archive, laid out like the package's src folder.
 *
 * Every path in the archive is the path the file has in the repository, so the archive is
 * unpacked over src as it is. The manifests cannot be changed that way without overwriting work
 * done on them since, so the README in the archive lists the entries each manifest needs, and how
 * complete each language is.
 *
 * @since  1.0.0
 */
final class LanguageFilesArchive
{
    /**
     * Constructor.
     *
     * @param   DatabaseInterface       $db          The site's database.
     * @param   LanguageFileTranslator  $translator  Where the translations are kept.
     *
     * @since   1.0.0
     */
    public function __construct(private readonly DatabaseInterface $db, private readonly LanguageFileTranslator $translator)
    {
    }

    /**
     * Write the archive.
     *
     * @param   string[]  $languages  The languages to include, every language with translations when empty.
     *
     * @return  string  The path of the archive, a temporary file the caller removes.
     *
     * @throws  \RuntimeException  When there is nothing to pack, or the archive cannot be written.
     *
     * @since   1.0.0
     */
    public function build(array $languages = []): string
    {
        $languages = $languages ?: $this->translator->languagesWithTranslations();
        $files     = PackageLanguageFiles::discover($this->db);

        if ($languages === [] || $files === []) {
            throw new \RuntimeException('There are no translated language files to download yet.');
        }

        $path = tempnam(Factory::getApplication()->get('tmp_path', sys_get_temp_dir()), 'tfl');
        $zip  = new \ZipArchive();

        if ($path === false || $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('The archive could not be written.');
        }

        $status = $this->translator->status($languages);

        foreach ($languages as $language) {
            $translations = $this->translator->translations($language);

            foreach ($files as $name => $file) {
                $contents = IniWriter::write((string) file_get_contents($file['source']), $translations[$name] ?? []);
                $zip->addFromString(str_replace('{tag}', $language, $file['packagePath']), $contents);
            }
        }

        $zip->addFromString('README.md', $this->readme($files, $languages, $status));
        $zip->close();

        return $path;
    }

    /**
     * The file name to offer the archive under.
     *
     * @return  string
     *
     * @since   1.0.0
     */
    public static function fileName(): string
    {
        return 'translator-feedback-language-files-' . Factory::getDate()->format('Y-m-d') . '.zip';
    }

    /**
     * Say where the files go, what each manifest needs, and how complete each language is.
     *
     * @param   array     $files      The package's language files.
     * @param   string[]  $languages  The languages in the archive.
     * @param   array     $status     The progress of each language.
     *
     * @return  string  The README, in Markdown.
     *
     * @since   1.0.0
     */
    private function readme(array $files, array $languages, array $status): string
    {
        $versions = [];
        $entries  = [];

        foreach ($files as $file) {
            $versions[$file['extension']] = $file['extension'] . ' ' . $file['version'];

            foreach ($languages as $language) {
                $entries[$file['manifest']][] = \sprintf(
                    '<language tag="%s">%s</language>',
                    $language,
                    str_replace('{tag}', $language, $file['manifestEntry'])
                );
            }
        }

        $lines = [
            '# Translator Feedback language files',
            '',
            \sprintf(
                'Translated on %s on %s from the en-GB language files of %s.',
                Uri::root(),
                Factory::getDate()->format('Y-m-d H:i'),
                implode(', ', $versions)
            ),
            'The strings were translated with the published translation rules of that site.',
            '',
            '## Where the files go',
            '',
            'Every path in this archive is the path of the file in the `src` folder of',
            '[joomla-projects/gsoc26_translator_feedback](https://github.com/joomla-projects/gsoc26_translator_feedback).',
            'Unpack the archive into `src`, except for this README.',
            '',
            '## Manifest entries',
            '',
            'Joomla only installs the language files a manifest lists. Run `php build/language-entries.php`',
            'in the repository: it adds these lines to the `<languages>` element of each manifest, next to',
            'its en-GB entries.',
            '',
        ];

        foreach ($entries as $manifest => $manifestEntries) {
            $lines[] = '`src/' . $manifest . '`:';
            $lines[] = '';
            $lines[] = '```xml';
            array_push($lines, ...$manifestEntries);
            $lines[] = '```';
            $lines[] = '';
        }

        $lines[] = '## How complete each language is';
        $lines[] = '';
        $lines[] = 'A string that is not translated yet is in the files in English, so no screen shows a bare';
        $lines[] = 'language constant. Run the task again to translate it; a string set aside after three failed';
        $lines[] = 'attempts needs the task "Retry Failed Language File Strings" first.';
        $lines[] = '';
        $lines[] = '| Language | Translated | Still in English | Of which set aside |';
        $lines[] = '| --- | ---: | ---: | ---: |';

        foreach ($status as $language => $counts) {
            $lines[] = \sprintf(
                '| %s | %d | %d | %d |',
                $language,
                $counts['translated'],
                $counts['total'] - $counts['translated'],
                $counts['failed']
            );
        }

        return implode("\n", $lines) . "\n";
    }
}
