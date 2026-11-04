<?php

/**
 * Lists every translated language file in src in the manifest that ships it.
 *
 *   php build/language-entries.php
 *
 * Unpack the archive the language files task makes into src, then run this: it adds a <language>
 * line for each translation of a file a manifest lists for en-GB. Running it again adds nothing.
 *
 * @package     Joomla.Build
 * @subpackage  com_translations
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

require_once __DIR__ . '/languages.php';

$source = \dirname(__DIR__) . '/src';
$added  = 0;

foreach (manifests($source) as $manifest) {
    $missing = languageEntries($manifest, $source)['missing'];

    if ($missing === []) {
        continue;
    }

    $contents = (string) file_get_contents($manifest);
    $updated  = withLanguageEntries($contents, $missing);
    $count    = array_sum(array_map('count', $missing));

    if ($updated === $contents) {
        fwrite(STDERR, \sprintf("%s needs %d entries, but has no single <languages> element to add them to.\n", $manifest, $count));
        exit(1);
    }

    file_put_contents($manifest, $updated);
    printf("  %-70s +%d (%s)\n", substr($manifest, \strlen($source) + 1), $count, implode(', ', array_keys($missing)));
    $added += $count;
}

echo $added === 0 ? "Every translated language file is listed.\n" : "Added $added entries.\n";
