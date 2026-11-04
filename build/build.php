<?php

/**
 * Assembles the installable archives from src/.
 *
 * There are three of them, and they are released as three downloads:
 *
 *   php build/build.php                 the package: the component and the five plugins
 *                                       that make up the translation pipeline
 *   php build/build.php seed            plg_task_translationsseed on its own
 *   php build/build.php languagefiles   plg_task_translationslanguagefiles on its own
 *
 * The two plugins are not in the package because they are not part of the pipeline. The
 * seed plugin reads an installed language pack once to give a site something to learn from,
 * and a site that does not want that should not have it installed. The language files plugin
 * translates the package's own language files, which only the site the project makes them on
 * needs to do.
 *
 * What goes into the package is read from pkg_translations.xml rather than listed here, so
 * adding an extension to the package is one line in the manifest. The version comes from
 * the manifest too, which is the single place each one is written: the archive is named
 * after it, and the release workflow refuses to publish a tag that disagrees with it.
 *
 * Entry names always use forward slashes, and each archive carries its manifest at its
 * root - a wrapper folder or a backslash separator both break the install on a Linux host.
 *
 * @package     Joomla.Build
 * @subpackage  com_translations
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

require_once __DIR__ . '/archive.php';

const ROOT   = __DIR__ . '/..';
const SOURCE = ROOT . '/src';

// The plugins released on their own, by build target: where each lives and what its archive
// is called. They are the extensions this script knows by name, because they are not in the
// package manifest.
const SEPARATE_PLUGINS = [
    'seed'          => ['src/plugins/task/translationsseed', 'plg_task_translationsseed'],
    'languagefiles' => ['src/plugins/task/translationslanguagefiles', 'plg_task_translationslanguagefiles'],
];

/**
 * Report what cannot be built and stop.
 *
 * @param   string  $message  What is wrong.
 *
 * @return  never
 */
function fail(string $message): void
{
    fwrite(STDERR, $message . "\n");
    exit(1);
}

/**
 * Read a manifest's version, insisting it is one a release can be tagged with.
 *
 * Joomla accepts looser version strings, but a release this project builds is expected to
 * be semantic: the tag, the manifest and the file name all have to agree, and that is
 * easier to check when the shape is fixed.
 *
 * @param   string  $manifest  Path to the manifest.
 *
 * @return  string  The version.
 */
function version(string $manifest): string
{
    if (!is_file($manifest)) {
        fail('No manifest at ' . $manifest);
    }

    libxml_use_internal_errors(true);
    $xml = simplexml_load_file($manifest);

    if ($xml === false) {
        fail($manifest . ' is not well-formed XML.');
    }

    $version = trim((string) $xml->version);

    if (!preg_match('/^\d+\.\d+\.\d+(-[A-Za-z0-9.]+)?$/', $version)) {
        fail(\sprintf('The version "%s" in %s is not of the form 1.2.3.', $version, basename($manifest)));
    }

    return $version;
}

/**
 * Open an archive for writing, replacing whatever was built before.
 *
 * @param   string  $path  Path to the archive.
 *
 * @return  ZipArchive
 */
function archive(string $path): ZipArchive
{
    if (is_file($path)) {
        unlink($path);
    }

    $zip = new ZipArchive();

    if ($zip->open($path, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
        fail('Cannot create ' . $path);
    }

    return $zip;
}

/**
 * Remove the archives an earlier version of an extension left in build/.
 *
 * The release workflow uploads build/<extension>-*.zip, so a leftover from a previous
 * version would be published alongside the one being released, offering a download of
 * something nobody tagged.
 *
 * @param   string  $extension  The extension name, as the archive is named after it.
 *
 * @return  void
 */
function removeOldArchives(string $extension): void
{
    foreach (glob(ROOT . '/build/' . $extension . '-*.zip') ?: [] as $archive) {
        unlink($archive);
    }
}

/**
 * Add a directory to an archive, under a prefix, keeping its layout.
 *
 * @param   ZipArchive  $zip        The archive to add to.
 * @param   string      $directory  The directory to add.
 * @param   string      $prefix     Where its contents go in the archive, '' for the root.
 *
 * @return  integer  The number of files added.
 */
function addDirectory(ZipArchive $zip, string $directory, string $prefix = ''): int
{
    if (!is_dir($directory)) {
        fail('No directory at ' . $directory);
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)
    );
    $added = 0;

    foreach ($iterator as $file) {
        // Whatever the editor, the operating system or git left behind is not part of the
        // extension, and a dot file is how all of them spell it.
        if (!$file->isFile() || str_starts_with($file->getFilename(), '.')) {
            continue;
        }

        $relative = str_replace('\\', '/', substr($file->getPathname(), \strlen($directory) + 1));

        $zip->addFile($file->getPathname(), $prefix . $relative);
        $added++;
    }

    return $added;
}

/**
 * Build one plugin's installable archive: the plugin's own folder at the archive root.
 *
 * @param   string  $directory  The plugin's folder under src/.
 * @param   string  $target     Path to the archive to write.
 *
 * @return  void
 */
function buildPlugin(string $directory, string $target): void
{
    $zip = archive($target);
    addDirectory($zip, $directory);
    $zip->close();
}

/**
 * Build the component's installable archive.
 *
 * Joomla installs the three clients from where the manifest says they are, so the archive
 * mirrors the layout of a site. The manifest and the install script are copied to the root
 * as well, because that is where the installer looks for them before it has read anything.
 *
 * @param   string  $target  Path to the archive to write.
 *
 * @return  void
 */
function buildComponent(string $target): void
{
    $administrator = SOURCE . '/administrator/components/com_translations';

    $zip = archive($target);

    addDirectory($zip, $administrator, 'administrator/components/com_translations/');
    addDirectory($zip, SOURCE . '/components/com_translations', 'components/com_translations/');
    addDirectory($zip, SOURCE . '/media/com_translations', 'media/com_translations/');

    $zip->addFile($administrator . '/translations.xml', 'translations.xml');
    $zip->addFile($administrator . '/script.php', 'script.php');

    $zip->close();
}

/**
 * Where an extension listed in the package manifest is built from.
 *
 * The manifest names an extension the way Joomla stores it - a type, an element id, and a
 * group for a plugin - which is also how the source tree is laid out.
 *
 * @param   SimpleXMLElement  $file  One <file> entry of the package manifest.
 *
 * @return  string  The extension's folder under src/.
 */
function sourceOf(SimpleXMLElement $file): string
{
    $type    = (string) $file['type'];
    $element = (string) $file['id'];
    $group   = (string) $file['group'];

    if ($type === 'component') {
        return SOURCE . '/administrator/components/' . $element;
    }

    if ($type === 'plugin') {
        return SOURCE . '/plugins/' . $group . '/' . $element;
    }

    fail(\sprintf('The package manifest lists a "%s", which this script does not know how to build.', $type));
}

/**
 * Build the package: every extension the manifest lists, wrapped in one installable archive.
 *
 * @return  void
 */
function buildPackage(): void
{
    $manifest = SOURCE . '/administrator/manifests/packages/pkg_translations.xml';
    $version  = version($manifest);

    removeOldArchives('pkg_translations');

    $target = ROOT . '/build/' . archiveName('pkg_translations', $version);

    // The extension archives are only ever read again a few lines further down, so they are
    // built where they will not be mistaken for something to publish.
    $temporary = ROOT . '/build/tmp';

    if (!is_dir($temporary) && !mkdir($temporary, 0755, true) && !is_dir($temporary)) {
        fail('Cannot create ' . $temporary);
    }

    foreach (glob($temporary . '/*.zip') ?: [] as $stale) {
        unlink($stale);
    }

    $zip = archive($target);
    $zip->addFile($manifest, 'pkg_translations.xml');

    // The package's own language file, in en-GB and every language it is translated into.
    foreach (glob(SOURCE . '/language/*/pkg_translations.sys.ini') ?: [] as $languageFile) {
        $zip->addFile($languageFile, 'language/' . basename(\dirname($languageFile)) . '/pkg_translations.sys.ini');
    }

    $extensions = simplexml_load_file($manifest)->files->file;

    foreach ($extensions as $file) {
        $name   = (string) $file;
        $source = sourceOf($file);

        if (!is_dir($source)) {
            fail(\sprintf('The package manifest lists %s, but there is nothing at %s.', $name, $source));
        }

        $built = $temporary . '/' . $name;

        if ((string) $file['type'] === 'component') {
            buildComponent($built);
        } else {
            buildPlugin($source, $built);
        }

        $zip->addFile($built, $name);

        printf("  %-40s %s\n", $name, basename($source));
    }

    // The extension archives have to stay on disk until the package is written, because
    // addFile() only reads them when the archive is closed.
    $zip->close();

    report('pkg_translations', $version, $target);
}

/**
 * Build the own release of a plugin that is not in the package.
 *
 * @param   string  $directory  The plugin's folder, relative to the repository root.
 * @param   string  $name       The plugin's name, which its archive is named after.
 *
 * @return  void
 */
function buildSeparatePlugin(string $directory, string $name): void
{
    $source  = ROOT . '/' . $directory;
    $version = version($source . '/' . basename($directory) . '.xml');

    removeOldArchives($name);

    $target = ROOT . '/build/' . archiveName($name, $version);

    buildPlugin($source, $target);

    report($name, $version, $target);
}

/**
 * Say what was built.
 *
 * @param   string  $name     The name of what was built.
 * @param   string  $version  Its version.
 * @param   string  $target   Path to the archive.
 *
 * @return  void
 */
function report(string $name, string $version, string $target): void
{
    printf("%s %s -> %s (%s bytes)\n", $name, $version, basename($target), number_format((int) filesize($target)));
}

if (!\extension_loaded('zip')) {
    fail('The zip extension is needed to build an installable archive.');
}

$target = $argv[1] ?? 'package';

if ($target === 'package') {
    buildPackage();
} elseif (isset(SEPARATE_PLUGINS[$target])) {
    buildSeparatePlugin(...SEPARATE_PLUGINS[$target]);
} else {
    fail(\sprintf('Unknown target "%s". Use "package" (the default), "%s".', $target, implode('" or "', array_keys(SEPARATE_PLUGINS))));
}
