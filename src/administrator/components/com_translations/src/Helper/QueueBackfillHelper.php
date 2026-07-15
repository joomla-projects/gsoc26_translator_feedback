<?php

/**
 * @package     Joomla.Administrator
 * @subpackage  com_translations
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Component\Translations\Administrator\Helper;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

/**
 * Seeds the translation queue from associations that already exist on the site.
 *
 * A source item translated through #__associations before the component was installed
 * has no queue rows, so the grid shows it as "No translation yet" and offers to translate
 * it again. Run once from the install script, this reflects those translations in the
 * queue so they are not re-translated.
 *
 * @since  0.7.0
 */
class QueueBackfillHelper
{
    /**
     * Content types seeded on install. Articles first; other associable types follow later.
     *
     * @var    string[]
     * @since  0.7.0
     */
    private const CONTENT_TYPES = ['com_content.article'];

    /**
     * State stamped on a translation that already exists at install time.
     *
     * @var    string
     * @since  0.7.0
     */
    private const BACKFILL_STATE = 'published';

    /**
     * Seed queue and state rows for every handled content type that already has associations.
     *
     * @param   DatabaseInterface  $db              The database driver.
     * @param   string             $sourceLanguage  The source language code.
     *
     * @return  integer  The number of state rows created.
     *
     * @since   0.7.0
     */
    public static function backfill(DatabaseInterface $db, string $sourceLanguage): int
    {
        $created = 0;

        foreach (self::CONTENT_TYPES as $contentType) {
            $properties = ContentTypesHelper::getProperties($contentType);
            $context    = (string) ($properties['context_associations'] ?? '');
            $table      = (string) ($properties['table'] ?? '');

            if ($context === '' || $table === '') {
                continue;
            }

            $groups = self::readAssociationGroups($db, $context, $table);

            foreach (self::plan($groups, $sourceLanguage) as $plan) {
                $queueId = self::getOrCreateQueueId($db, $contentType, $plan['sourceId']);

                foreach ($plan['targets'] as $targetLanguage) {
                    if (self::insertStateIfAbsent($db, $queueId, $targetLanguage)) {
                        $created++;
                    }
                }
            }
        }

        return $created;
    }

    /**
     * Decide the queue anchor and target-language state rows for each association group. Pure (no database).
     *
     * A group is a list of members, each ['id' => int, 'language' => string]. The member in the
     * source language is the queue anchor; the other members are the translations to record.
     * Groups with no source-language member, and languages that are not real targets ('*'), are skipped.
     *
     * @param   array   $groups          Association groups, each a list of ['id', 'language'] members.
     * @param   string  $sourceLanguage  The source language code.
     *
     * @return  array  List of ['sourceId' => int, 'targets' => string[]]; targets de-duplicated, source excluded.
     *
     * @since   0.7.0
     */
    public static function plan(array $groups, string $sourceLanguage): array
    {
        $plans = [];

        foreach ($groups as $members) {
            $sourceId = 0;
            $targets  = [];

            foreach ($members as $member) {
                $language = (string) ($member['language'] ?? '');
                $id       = (int) ($member['id'] ?? 0);

                if ($language === $sourceLanguage) {
                    $sourceId = $id;

                    continue;
                }

                // '*' (all languages) is not a real translation target.
                if ($language === '' || $language === '*') {
                    continue;
                }

                $targets[$language] = $language;
            }

            if ($sourceId === 0 || $targets === []) {
                continue;
            }

            $plans[] = ['sourceId' => $sourceId, 'targets' => array_values($targets)];
        }

        return $plans;
    }

    /**
     * Read every association for a context and group its members by the shared key.
     *
     * @param   DatabaseInterface  $db       The database driver.
     * @param   string             $context  The associations context, e.g. 'com_content.item'.
     * @param   string             $table    The content type's database table.
     *
     * @return  array  List of groups, each a list of ['id' => int, 'language' => string] members.
     *
     * @since   0.7.0
     */
    private static function readAssociationGroups(DatabaseInterface $db, string $context, string $table): array
    {
        $query = $db->getQuery(true)
            ->select($db->quoteName(['association.key', 'item.id', 'item.language']))
            ->from($db->quoteName('#__associations', 'association'))
            ->join(
                'INNER',
                $db->quoteName($table, 'item'),
                $db->quoteName('item.id') . ' = ' . $db->quoteName('association.id')
            )
            ->where($db->quoteName('association.context') . ' = :context')
            ->bind(':context', $context, ParameterType::STRING);
        $db->setQuery($query);

        $groups = [];

        foreach ($db->loadObjectList() ?: [] as $row) {
            $groups[$row->key][] = ['id' => (int) $row->id, 'language' => (string) $row->language];
        }

        return array_values($groups);
    }

    /**
     * Find the queue row for a source item, creating it when missing.
     *
     * @param   DatabaseInterface  $db            The database driver.
     * @param   string             $contentType   The content type key, e.g. 'com_content.article'.
     * @param   integer            $sourceItemId  The source item id.
     *
     * @return  integer  The queue row id.
     *
     * @since   0.7.0
     */
    private static function getOrCreateQueueId(DatabaseInterface $db, string $contentType, int $sourceItemId): int
    {
        $query = $db->getQuery(true)
            ->select($db->quoteName('id'))
            ->from($db->quoteName('#__translations_queue'))
            ->where($db->quoteName('content_type') . ' = :contentType')
            ->where($db->quoteName('content_id') . ' = :contentId')
            ->bind(':contentType', $contentType, ParameterType::STRING)
            ->bind(':contentId', $sourceItemId, ParameterType::INTEGER);
        $db->setQuery($query);

        $queueId = $db->loadResult();

        if ($queueId !== null) {
            return (int) $queueId;
        }

        $queueRow = (object) [
            'content_type' => $contentType,
            'content_id'   => $sourceItemId,
        ];

        $db->insertObject('#__translations_queue', $queueRow, 'id');

        return (int) $queueRow->id;
    }

    /**
     * Insert a state row for one target language when none exists yet.
     *
     * @param   DatabaseInterface  $db              The database driver.
     * @param   integer            $queueId         The queue row id.
     * @param   string             $targetLanguage  The target language code.
     *
     * @return  boolean  True when a row was created, false when one already existed.
     *
     * @since   0.7.0
     */
    private static function insertStateIfAbsent(DatabaseInterface $db, int $queueId, string $targetLanguage): bool
    {
        $query = $db->getQuery(true)
            ->select($db->quoteName('id'))
            ->from($db->quoteName('#__translations_queue_states'))
            ->where($db->quoteName('queue_id') . ' = :queueId')
            ->where($db->quoteName('target_language') . ' = :targetLanguage')
            ->bind(':queueId', $queueId, ParameterType::INTEGER)
            ->bind(':targetLanguage', $targetLanguage, ParameterType::STRING);
        $db->setQuery($query);

        if ($db->loadResult() !== null) {
            return false;
        }

        $stateRow = (object) [
            'queue_id'          => $queueId,
            'target_language'   => $targetLanguage,
            'translation_state' => self::BACKFILL_STATE,
        ];

        $db->insertObject('#__translations_queue_states', $stateRow);

        return true;
    }
}
