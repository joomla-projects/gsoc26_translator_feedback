<?php

/**
 * Which language files a manifest has to list, from the translations that are in src.
 *
 * Joomla installs only the language files a manifest lists, so a translated file that is added to
 * the repository without its <language> line is shipped and never installed. The language files
 * task gives the translations of the package as an archive to unpack into src; build/language-entries.php
 * then adds the lines, and tests/Unit/PackageManifestTest fails while one is missing.
 *
 * Every en-GB file a manifest lists is looked for in each other language folder next to en-GB.
 *
 * @package     Joomla.Build
 * @subpackage  com_translations
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

/**
 * The language files a manifest lists and the translations of them that are not listed yet.
 *
 * @param   string  $manifest  Path to the manifest.
 * @param   string  $source    Path to src, which a <languages folder> of the package and the component is relative to.
 *
 * @return  array{listed: array<string, string[]>, missing: array<string, string[]>}  Entries keyed by language tag.
 */
function languageEntries(string $manifest, string $source): array
{
    $xml     = simplexml_load_file($manifest);
    $listed  = [];
    $missing = [];

    if ($xml === false) {
        return ['listed' => [], 'missing' => []];
    }

    foreach ($xml->xpath('//languages') ?: [] as $languages) {
        $folder = trim((string) $languages['folder'], '/');

        foreach ($languages->language as $language) {
            $listed[(string) $language['tag']][] = trim((string) $language);
        }

        foreach ($languages->language as $language) {
            $entry = trim((string) $language);

            if ((string) $language['tag'] !== 'en-GB' || !str_contains($entry, 'en-GB/')) {
                continue;
            }

            $original = languageFile(\dirname($manifest), $source, $folder, $entry);

            if ($original === null) {
                continue;
            }

            foreach (glob(\dirname($original, 2) . '/*', GLOB_ONLYDIR) ?: [] as $directory) {
                $tag = basename($directory);

                if ($tag === 'en-GB' || !preg_match('/^[a-z]{2,3}-[A-Z][A-Za-z]{1,3}$/', $tag) || !is_file($directory . '/' . basename($original))) {
                    continue;
                }

                $translated = str_replace('en-GB/', $tag . '/', $entry);

                if (!\in_array($translated, $listed[$tag] ?? [], true)) {
                    $missing[$tag][] = $translated;
                }
            }
        }
    }

    ksort($missing);

    return ['listed' => $listed, 'missing' => $missing];
}

/**
 * Where a listed language file is in the repository.
 *
 * @param   string  $directory  The manifest's folder.
 * @param   string  $source     Path to src.
 * @param   string  $folder     The <languages folder>, empty for none.
 * @param   string  $entry      The listed path.
 *
 * @return  string|null  The path, or null when it is not there.
 */
function languageFile(string $directory, string $source, string $folder, string $entry): ?string
{
    $relative = ($folder === '' ? '' : $folder . '/') . $entry;

    foreach ([$directory . '/' . $relative, $source . '/' . $relative] as $candidate) {
        if (is_file($candidate)) {
            return $candidate;
        }
    }

    return null;
}

/**
 * A manifest with the missing entries added, after the last line of its <languages> element and
 * indented like it. A manifest with one <languages> element only; with more, it is returned as it is.
 *
 * @param   string                   $contents  The manifest.
 * @param   array<string, string[]>  $missing   The entries to add, keyed by language tag.
 *
 * @return  string
 */
function withLanguageEntries(string $contents, array $missing): string
{
    if ($missing === [] || substr_count($contents, '</languages>') !== 1) {
        return $contents;
    }

    if (!preg_match('/^([ \t]*)<language tag="[^"]*">[^<]*<\/language>(\r?\n)(?![\s\S]*<language tag=)/m', $contents, $last, PREG_OFFSET_CAPTURE)) {
        return $contents;
    }

    [$indent, $newline] = [$last[1][0], $last[2][0]];
    $lines              = '';

    foreach ($missing as $tag => $entries) {
        foreach ($entries as $entry) {
            $lines .= $indent . '<language tag="' . $tag . '">' . $entry . '</language>' . $newline;
        }
    }

    $at = $last[0][1] + \strlen($last[0][0]);

    return substr($contents, 0, $at) . $lines . substr($contents, $at);
}

/**
 * Every manifest in src.
 *
 * @param   string  $source  Path to src.
 *
 * @return  string[]
 */
function manifests(string $source): array
{
    $found = [];
    $tree  = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS));

    foreach ($tree as $file) {
        if ($file->getExtension() !== 'xml' || str_contains($file->getPathname(), \DIRECTORY_SEPARATOR . 'forms' . \DIRECTORY_SEPARATOR)) {
            continue;
        }

        $head = (string) file_get_contents($file->getPathname(), false, null, 0, 300);

        if (str_contains($head, '<extension')) {
            $found[] = $file->getPathname();
        }
    }

    sort($found);

    return $found;
}
