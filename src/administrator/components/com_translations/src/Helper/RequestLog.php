<?php

/**
 * @package     Joomla.Administrator
 * @subpackage  com_translations
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Component\Translations\Administrator\Helper;

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * The log of the requests sent to a translation or distillation provider.
 *
 * The consistency analysis of guide.joomla.org could not prove why titles stayed English or why
 * an article got unrelated rules, because what was sent was not kept. While the Log Requests
 * option is on, every request is stored with what was sent, what came back, and the numbers the
 * settings are tuned by: how much text, how many rules of each type, under which caps, how long
 * it took and how it ended. Rows older than the days the option keeps them are removed.
 *
 * @since  3.0.0
 */
final class RequestLog
{
    /**
     * A request translated.
     *
     * @var    string
     * @since  3.0.0
     */
    public const TRANSLATE = 'translate';

    /**
     * A request distilled rules from corrections.
     *
     * @var    string
     * @since  3.0.0
     */
    public const DISTIL = 'distil';

    /**
     * Every string sent came back.
     *
     * @var    string
     * @since  3.0.0
     */
    public const OK = 'ok';

    /**
     * An answer came back, but some strings were missing from it or refused.
     *
     * @var    string
     * @since  3.0.0
     */
    public const PARTIAL = 'partial';

    /**
     * The request failed.
     *
     * @var    string
     * @since  3.0.0
     */
    public const ERROR = 'error';

    /**
     * The longest message kept, the size of its column.
     *
     * @var    integer
     * @since  3.0.0
     */
    private const MESSAGE_LENGTH = 1000;

    /**
     * Whether old rows were removed in this PHP request already.
     *
     * @var    boolean
     * @since  3.0.0
     */
    private static bool $purged = false;

    /**
     * Whether requests are logged, by the component's option.
     *
     * @return  boolean
     *
     * @since   3.0.0
     */
    public static function enabled(): bool
    {
        return (int) ComponentHelper::getParams('com_translations')->get('log_requests', 0) === 1;
    }

    /**
     * Store one request, and remove the rows older than the option keeps, once per PHP request.
     *
     * A failure to log never stops the translation it describes.
     *
     * @param   DatabaseInterface     $db   The database.
     * @param   array<string, mixed>  $row  The columns: kind, outcome, and any of content_type, content_id,
     *                                      source_language, target_language, strings, characters,
     *                                      terminology_rules, style_rules, preservation_rules, settings,
     *                                      duration_ms, message, request and response; settings, request
     *                                      and response are stored as JSON.
     *
     * @return  void
     *
     * @since   3.0.0
     */
    public static function write(DatabaseInterface $db, array $row): void
    {
        try {
            if (!self::$purged) {
                self::$purged = true;
                self::purge($db, (int) ComponentHelper::getParams('com_translations')->get('log_days', 30));
            }

            foreach (['settings', 'request', 'response'] as $json) {
                if (\array_key_exists($json, $row)) {
                    $row[$json] = json_encode($row[$json], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
                }
            }

            $row['message'] = mb_substr((string) ($row['message'] ?? ''), 0, self::MESSAGE_LENGTH);
            $row['created'] = Factory::getDate()->toSql();

            $object = (object) $row;
            $db->insertObject('#__translations_log', $object);
        } catch (\Throwable $e) {
            // The log is there to learn from; a translation is not lost for want of it.
        }
    }

    /**
     * Remove the rows older than a number of days.
     *
     * @param   DatabaseInterface  $db    The database.
     * @param   integer            $days  The days a row is kept; nothing is removed when below one.
     *
     * @return  void
     *
     * @since   3.0.0
     */
    public static function purge(DatabaseInterface $db, int $days): void
    {
        if ($days < 1) {
            return;
        }

        $before = Factory::getDate('now - ' . $days . ' days')->toSql();
        $query  = $db->getQuery(true)
            ->delete($db->quoteName('#__translations_log'))
            ->where($db->quoteName('created') . ' < :before')
            ->bind(':before', $before, ParameterType::STRING);

        $db->setQuery($query)->execute();
    }

    /**
     * The number of rules of each type, as the retriever groups them.
     *
     * @param   array  $rules  The rules keyed by type.
     *
     * @return  array{terminology_rules: int, style_rules: int, preservation_rules: int}
     *
     * @since   3.0.0
     */
    public static function ruleCounts(array $rules): array
    {
        return [
            'terminology_rules'  => \count((array) ($rules['terminology'] ?? [])),
            'style_rules'        => \count((array) ($rules['style'] ?? [])),
            'preservation_rules' => \count((array) ($rules['preservation'] ?? [])),
        ];
    }

    /**
     * The characters in a set of strings.
     *
     * @param   array  $strings  The strings.
     *
     * @return  integer
     *
     * @since   3.0.0
     */
    public static function characters(array $strings): int
    {
        return array_sum(array_map(static fn ($text): int => mb_strlen((string) $text), $strings));
    }
}
