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

use Joomla\Component\Translations\Administrator\Helper\RequestLog;
use PHPUnit\Framework\TestCase;

/**
 * The numbers a logged request is stored with.
 *
 * @since  3.0.0
 */
final class RequestLogTest extends TestCase
{
    /**
     * The rules are counted per type, a missing type as none.
     *
     * @return  void
     *
     * @since   3.0.0
     */
    public function testTheRulesAreCountedPerType(): void
    {
        $this->assertSame(
            ['terminology_rules' => 2, 'style_rules' => 1, 'preservation_rules' => 0],
            RequestLog::ruleCounts(['terminology' => [['id' => 1], ['id' => 2]], 'style' => [['id' => 3]]])
        );
    }

    /**
     * Characters are counted, not bytes.
     *
     * @return  void
     *
     * @since   3.0.0
     */
    public function testCharactersAreCountedNotBytes(): void
    {
        $this->assertSame(9, RequestLog::characters(['title' => 'Übersicht', 'empty' => '']));
    }
}
