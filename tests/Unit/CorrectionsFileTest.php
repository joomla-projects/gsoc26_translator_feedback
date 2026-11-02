<?php

/**
 * @package     Joomla.Tests
 * @subpackage  com_translations
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Joomla\Component\Translations\Tests\Unit;

use InvalidArgumentException;
use Joomla\Component\Translations\Administrator\Helper\CorrectionsFile;
use PHPUnit\Framework\TestCase;

/**
 * Reading a corrections file, and deciding per field whether its correction still applies.
 *
 * The import overwrites translations on a live site, so what matters most is that a file is
 * refused when it is incomplete, and that a field edited since the file was made is recognised
 * and left alone.
 *
 * @since  1.5.0
 */
final class CorrectionsFileTest extends TestCase
{
    /**
     * A complete file with one translation and one piece of feedback.
     *
     * @return  array
     *
     * @since   1.5.0
     */
    private function file(): array
    {
        return [
            'format'        => CorrectionsFile::FORMAT,
            'source_origin' => 'repair',
            'items'         => [[
                'type'      => 'com_content.article',
                'source_id' => 157,
                'language'  => 'de-DE',
                'id'        => 2221,
                'fields'    => ['title' => ['before' => CorrectionsFile::hash('Privacy Workflow'), 'after' => 'Datenschutz-Workflow']],
            ]],
            'feedback' => [[
                'type'             => 'com_content.article',
                'source_id'        => 157,
                'language'         => 'de-DE',
                'source_text'      => 'Privacy Workflow',
                'machine_draft'    => 'Privacy Workflow',
                'human_correction' => 'Datenschutz-Workflow',
                'occurrences'      => 3,
            ]],
        ];
    }

    /**
     * A complete file is read as it is.
     *
     * @return  void
     *
     * @since   1.5.0
     */
    public function testParsesACompleteFile(): void
    {
        $parsed = CorrectionsFile::parse((string) json_encode($this->file()));

        $this->assertSame('repair', $parsed['origin']);
        $this->assertSame(2221, $parsed['items'][0]['id']);
        $this->assertSame('Datenschutz-Workflow', $parsed['items'][0]['fields']['title']['after']);
        $this->assertSame(3, $parsed['feedback'][0]['occurrences']);
    }

    /**
     * A file without an origin records its feedback under the default one.
     *
     * @return  void
     *
     * @since   1.5.0
     */
    public function testDefaultsTheOrigin(): void
    {
        $file = $this->file();
        unset($file['source_origin']);

        $this->assertSame(CorrectionsFile::DEFAULT_ORIGIN, CorrectionsFile::parse((string) json_encode($file))['origin']);
    }

    /**
     * Files that are incomplete or of another kind are refused as a whole.
     *
     * @return  array
     *
     * @since   1.5.0
     */
    public static function brokenFiles(): array
    {
        return [
            'another format'        => [static fn (array $f): array => ['format' => 'something/2'] + $f],
            'origin too long'       => [static fn (array $f): array => ['source_origin' => str_repeat('a', 21)] + $f],
            'origin with capitals'  => [static fn (array $f): array => ['source_origin' => 'Repair'] + $f],
            'item without id'       => [static function (array $f): array {
                unset($f['items'][0]['id']);

                return $f;
            }],
            'item without fields'   => [static function (array $f): array {
                $f['items'][0]['fields'] = [];

                return $f;
            }],
            'before is no hash'     => [static function (array $f): array {
                $f['items'][0]['fields']['title']['before'] = 'Privacy Workflow';

                return $f;
            }],
            'bad language'          => [static function (array $f): array {
                $f['items'][0]['language'] = 'german';

                return $f;
            }],
            'feedback corrects nothing' => [static function (array $f): array {
                $f['feedback'][0]['human_correction'] = 'Privacy Workflow ';

                return $f;
            }],
            'feedback without source' => [static function (array $f): array {
                unset($f['feedback'][0]['source_text']);

                return $f;
            }],
        ];
    }

    /**
     * An incomplete file is refused.
     *
     * @param   callable  $break  Turns the complete file into a broken one.
     *
     * @return  void
     *
     * @dataProvider  brokenFiles
     *
     * @since   1.5.0
     */
    public function testRefusesABrokenFile(callable $break): void
    {
        $this->expectException(InvalidArgumentException::class);

        CorrectionsFile::parse((string) json_encode($break($this->file())));
    }

    /**
     * Something that is not JSON is refused.
     *
     * @return  void
     *
     * @since   1.5.0
     */
    public function testRefusesSomethingElse(): void
    {
        $this->expectException(InvalidArgumentException::class);

        CorrectionsFile::parse('not json');
    }

    /**
     * A field is corrected only while it holds the value the correction was made from.
     *
     * @return  void
     *
     * @since   1.5.0
     */
    public function testDecidesPerField(): void
    {
        $correction = ['before' => CorrectionsFile::hash('Privacy Workflow'), 'after' => 'Datenschutz-Workflow'];

        $this->assertSame(CorrectionsFile::APPLY, CorrectionsFile::status('Privacy Workflow', $correction));
        $this->assertSame(CorrectionsFile::DONE, CorrectionsFile::status('Datenschutz-Workflow', $correction));
        $this->assertSame(CorrectionsFile::CHANGED, CorrectionsFile::status('Datenschutz-Ablauf', $correction));
    }
}
