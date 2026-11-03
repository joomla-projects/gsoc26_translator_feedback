<?php

/**
 * @package     Joomla.Tests
 * @subpackage  pkg_translations
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Joomla\Component\Translations\Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once \dirname(__DIR__, 2) . '/build/languages.php';

/**
 * Every translated language file in src is listed in the manifest that ships it.
 *
 * @since  4.0.0
 */
final class LanguageEntriesTest extends TestCase
{
    /**
     * A folder with a plugin in it, made for a test.
     *
     * @var    string
     * @since  4.0.0
     */
    private string $fixture = '';

    /**
     * Remove the folder a test made.
     *
     * @return  void
     *
     * @since   4.0.0
     */
    protected function tearDown(): void
    {
        if ($this->fixture !== '' && is_dir($this->fixture)) {
            $tree = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->fixture, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );

            foreach ($tree as $item) {
                $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
            }

            rmdir($this->fixture);
        }
    }

    /**
     * No translated file in src is left out of its manifest, and every listed file is there.
     *
     * @return  void
     *
     * @since   4.0.0
     */
    public function testEveryTranslatedFileIsListed(): void
    {
        $manifests = manifests(TRANSLATIONS_TEST_SRC);

        $this->assertGreaterThanOrEqual(9, \count($manifests));

        foreach ($manifests as $manifest) {
            $entries = languageEntries($manifest, TRANSLATIONS_TEST_SRC);

            $this->assertSame([], $entries['missing'], $manifest . ': run php build/language-entries.php');
            $this->assertArrayHasKey('en-GB', $entries['listed'], $manifest);
        }
    }

    /**
     * The translations next to en-GB are found, added after the last entry with its indent and line
     * ending, and found again as listed.
     *
     * @return  void
     *
     * @since   4.0.0
     */
    public function testTranslationsAreAddedOnce(): void
    {
        $this->fixture = sys_get_temp_dir() . '/tf-language-entries-' . bin2hex(random_bytes(4));
        $plugin        = $this->fixture . '/plugins/task/demo';

        foreach (['en-GB', 'nl-NL', 'pt-BR', 'notalanguage'] as $tag) {
            mkdir($plugin . '/language/' . $tag, 0777, true);
        }

        foreach (['en-GB', 'notalanguage'] as $tag) {
            touch($plugin . '/language/' . $tag . '/plg_task_demo.ini');
            touch($plugin . '/language/' . $tag . '/plg_task_demo.sys.ini');
        }

        touch($plugin . '/language/nl-NL/plg_task_demo.ini');
        touch($plugin . '/language/nl-NL/plg_task_demo.sys.ini');
        touch($plugin . '/language/pt-BR/plg_task_demo.sys.ini');

        $manifest = $plugin . '/demo.xml';
        file_put_contents($manifest, "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\r\n<extension type=\"plugin\" group=\"task\">\r\n"
            . "\t<languages>\r\n\t\t<language tag=\"en-GB\">language/en-GB/plg_task_demo.ini</language>\r\n"
            . "\t\t<language tag=\"en-GB\">language/en-GB/plg_task_demo.sys.ini</language>\r\n\t</languages>\r\n</extension>\r\n");

        $missing = languageEntries($manifest, $this->fixture)['missing'];

        $this->assertSame(
            ['nl-NL' => ['language/nl-NL/plg_task_demo.ini', 'language/nl-NL/plg_task_demo.sys.ini'], 'pt-BR' => ['language/pt-BR/plg_task_demo.sys.ini']],
            $missing
        );

        file_put_contents($manifest, withLanguageEntries((string) file_get_contents($manifest), $missing));

        $this->assertStringContainsString(
            "plg_task_demo.sys.ini</language>\r\n\t\t<language tag=\"nl-NL\">language/nl-NL/plg_task_demo.ini</language>\r\n"
            . "\t\t<language tag=\"nl-NL\">language/nl-NL/plg_task_demo.sys.ini</language>\r\n"
            . "\t\t<language tag=\"pt-BR\">language/pt-BR/plg_task_demo.sys.ini</language>\r\n\t</languages>",
            (string) file_get_contents($manifest)
        );
        $this->assertSame([], languageEntries($manifest, $this->fixture)['missing']);
    }

    /**
     * A languages folder relative to src, as the package and the component have it.
     *
     * @return  void
     *
     * @since   4.0.0
     */
    public function testALanguagesFolderRelativeToSrc(): void
    {
        $this->fixture = sys_get_temp_dir() . '/tf-language-entries-' . bin2hex(random_bytes(4));

        mkdir($this->fixture . '/language/en-GB', 0777, true);
        mkdir($this->fixture . '/language/de-DE', 0777, true);
        mkdir($this->fixture . '/administrator/manifests/packages', 0777, true);
        touch($this->fixture . '/language/en-GB/pkg_demo.sys.ini');
        touch($this->fixture . '/language/de-DE/pkg_demo.sys.ini');

        $manifest = $this->fixture . '/administrator/manifests/packages/pkg_demo.xml';
        file_put_contents($manifest, "<extension type=\"package\">\n\t<languages folder=\"language\">\n"
            . "\t\t<language tag=\"en-GB\">en-GB/pkg_demo.sys.ini</language>\n\t</languages>\n</extension>\n");

        $this->assertSame(['de-DE' => ['de-DE/pkg_demo.sys.ini']], languageEntries($manifest, $this->fixture)['missing']);
    }
}
