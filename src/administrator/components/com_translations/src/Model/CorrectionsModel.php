<?php

/**
 * @package     Joomla.Administrator
 * @subpackage  com_translations
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Component\Translations\Administrator\Model;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use Joomla\CMS\Application\CMSApplicationInterface;
use Joomla\CMS\MVC\Factory\MVCFactoryAwareTrait;
use Joomla\CMS\MVC\Model\BaseDatabaseModel;
use Joomla\Component\Translations\Administrator\Helper\ContentTypesHelper;
use Joomla\Component\Translations\Administrator\Helper\CorrectionsFile;
use Joomla\Component\Translations\Administrator\Helper\RunLock;
use Joomla\Component\Translations\Administrator\Helper\TimeBudget;
use Joomla\Database\ParameterType;

/**
 * Applies a file of corrections to translations, and records them as translator feedback.
 *
 * Each translation is saved the way the translator feedback view saves it: through the managing
 * component's own model, so versions, the workflow and the other extensions' save events run as
 * for a correction made by hand. Its queue state is left as it is: nobody reviewed the rest of the
 * translation, so it is neither approved nor published by this.
 *
 * The feedback is recorded once every translation in the file has been handled, as the file lists
 * it: one row per distinct correction, with the number of places it was made, so the distiller
 * learns from the correction itself rather than from whole articles that differ in it. A run that
 * runs out of time leaves the rest to the next run; a field that holds its correction already is
 * passed over, so running a file again changes nothing. Runs are sized for the web as well as the
 * command line: what is done is recognised from a few bulk queries, and only saving takes time.
 *
 * @since  1.5.0
 */
class CorrectionsModel extends BaseDatabaseModel
{
    use MVCFactoryAwareTrait;

    /**
     * Apply the corrections in a file, for as long as the time budget allows.
     *
     * @param   string                   $path         The file, relative to the site root.
     * @param   TimeBudget               $budget       How long this run may keep saving.
     * @param   CMSApplicationInterface  $application  The application, used to boot the managing components.
     *
     * @return  array{busy: bool, applied: int, done: int, changed: string[], missing: string[], locked: string[],
     *                failed: string[], remaining: int, feedback: int, feedbackPending: bool, lastError: string}
     *
     * @throws  \RuntimeException  When the file cannot be read or is not a corrections file.
     *
     * @since   1.5.0
     */
    public function import(string $path, TimeBudget $budget, CMSApplicationInterface $application): array
    {
        $result = [
            'busy' => false, 'applied' => 0, 'done' => 0, 'changed' => [], 'missing' => [], 'locked' => [],
            'failed' => [], 'remaining' => 0, 'feedback' => 0, 'feedbackPending' => false, 'lastError' => '',
        ];

        try {
            $file = CorrectionsFile::parse($this->read($path));
        } catch (\InvalidArgumentException $e) {
            throw new \RuntimeException($e->getMessage(), 0, $e);
        }

        $lock = new RunLock($this->getDatabase(), 'corrections');

        // Two runs on the same file would save every translation twice and record its feedback twice.
        if (!$lock->acquire()) {
            $result['busy'] = true;

            return $result;
        }

        try {
            // Read in bulk what the translations hold now, so a run that resumes passes over the
            // ones already handled at once and spends its time on the ones still to correct.
            $current = $this->currentValues($file['items']);

            foreach ($file['items'] as $item) {
                if (!$this->needsSaving($item, $current, $result)) {
                    continue;
                }

                if ($result['applied'] + \count($result['failed']) > 0 && !$budget->allowsAnother()) {
                    $result['remaining']++;

                    continue;
                }

                $budget->startRequest();

                try {
                    $this->applyItem($item, $application, $result);
                } finally {
                    $budget->endRequest();
                }
            }

            // Recorded once the translations are all handled, so a file run in several goes is learned from once.
            // A run that used its time on saving leaves it to the next run, so a web request is not overrun.
            if ($result['remaining'] === 0) {
                if ($result['applied'] === 0 || $budget->allowsAnother()) {
                    $result['feedback'] = $this->recordFeedback($file['feedback'], $file['origin']);
                } else {
                    $result['feedbackPending'] = true;
                }
            }
        } finally {
            $lock->release();
        }

        return $result;
    }

    /**
     * The current values of the corrected fields, read per table in a few queries.
     *
     * @param   array  $items  The translations from the file.
     *
     * @return  array<string, array<int, array>>  Per content type, the rows keyed by id, with the
     *                                            corrected fields, the language and the lock.
     *
     * @since   1.5.1
     */
    private function currentValues(array $items): array
    {
        $db     = $this->getDatabase();
        $wanted = [];

        foreach ($items as $item) {
            $wanted[$item['type']]['ids'][]  = $item['id'];
            $wanted[$item['type']]['fields'] = array_merge($wanted[$item['type']]['fields'] ?? [], array_keys($item['fields']));
        }

        $current = [];

        foreach ($wanted as $type => $want) {
            $table = (string) (ContentTypesHelper::getProperties($type)['table'] ?? '');

            if ($table === '') {
                continue;
            }

            // A field the table does not have counts as changed in needsSaving(), rather than failing the query.
            $columns = array_keys($db->getTableColumns($table));
            $select  = array_values(array_intersect($columns, array_unique(array_merge(['id', 'language', 'checked_out'], $want['fields']))));

            foreach (array_chunk(array_unique($want['ids']), 500) as $ids) {
                $query = $db->getQuery(true)
                    ->select($db->quoteName($select))
                    ->from($db->quoteName($table))
                    ->whereIn($db->quoteName('id'), $ids);
                $db->setQuery($query);

                foreach ($db->loadAssocList() as $row) {
                    $current[$type][(int) $row['id']] = $row;
                }
            }
        }

        return $current;
    }

    /**
     * Whether a translation still has corrections to save; counts it when it has not.
     *
     * @param   array  $item     The translation's entry from the file.
     * @param   array  $current  The current values, from currentValues().
     * @param   array  $result   The run's counts, updated.
     *
     * @return  boolean
     *
     * @since   1.5.1
     */
    private function needsSaving(array $item, array $current, array &$result): bool
    {
        $label = \sprintf('%s %d (%s)', $item['type'], $item['id'], $item['language']);
        $row   = $current[$item['type']][$item['id']] ?? null;

        if ($row === null || (string) ($row['language'] ?? '') !== $item['language']) {
            $result['missing'][] = $label;

            return false;
        }

        $statuses = [];

        foreach ($item['fields'] as $name => $correction) {
            $statuses[] = \array_key_exists($name, $row)
                ? CorrectionsFile::status((string) $row[$name], $correction)
                : CorrectionsFile::CHANGED;
        }

        if (\in_array(CorrectionsFile::CHANGED, $statuses, true)) {
            $result['changed'][] = $label;

            return false;
        }

        if (!\in_array(CorrectionsFile::APPLY, $statuses, true)) {
            $result['done']++;

            return false;
        }

        $checkedOut = (int) ($row['checked_out'] ?? 0);

        if ($checkedOut !== 0 && $checkedOut !== (int) $this->getCurrentUser()->id) {
            $result['locked'][] = $label;

            return false;
        }

        return true;
    }

    /**
     * Apply one translation's corrections, and count how it went.
     *
     * A translation of which any field was changed since the file was made is left alone as a
     * whole: its other corrections were made against a text that no longer stands.
     *
     * @param   array                    $item         The translation's entry from the file.
     * @param   CMSApplicationInterface  $application  The application, used to boot the managing component.
     * @param   array                    $result       The run's counts, updated.
     *
     * @return  void
     *
     * @since   1.5.0
     */
    private function applyItem(array $item, CMSApplicationInterface $application, array &$result): void
    {
        $label = \sprintf('%s %d (%s)', $item['type'], $item['id'], $item['language']);

        if (ContentTypesHelper::getProperties($item['type']) === []) {
            $result['missing'][] = $label;

            return;
        }

        /** @var TranslatorfeedbackModel $model */
        $model = $this->getMVCFactory()->createModel('Translatorfeedback', 'Administrator', ['ignore_request' => true]);
        $model->setState('content_id', $item['source_id']);
        $model->setState('content_type', $item['type']);
        $model->setState('target_language', $item['language']);

        $draft = $model->getItem();
        $row   = $draft->translation_item;

        if ($row === null || (int) $row['id'] !== $item['id']) {
            $result['missing'][] = $label;

            return;
        }

        if (!$draft->editable) {
            $result['locked'][] = $label;

            return;
        }

        $data     = [];
        $statuses = [];

        foreach ($item['fields'] as $name => $correction) {
            if (!\array_key_exists($name, $row) || \is_array($row[$name])) {
                $statuses[] = CorrectionsFile::CHANGED;

                continue;
            }

            $statuses[]                 = CorrectionsFile::status((string) $row[$name], $correction);
            $data['translation_' . $name] = $correction['after'];
        }

        if (\in_array(CorrectionsFile::CHANGED, $statuses, true)) {
            $result['changed'][] = $label;

            return;
        }

        if (!\in_array(CorrectionsFile::APPLY, $statuses, true)) {
            $result['done']++;

            return;
        }

        try {
            if (!$model->save($data, $application)) {
                throw new \RuntimeException('The managing component refused the save.');
            }

            $result['applied']++;
        } catch (\Throwable $e) {
            $result['failed'][]  = $label;
            $result['lastError'] = $label . ': ' . $e->getMessage();
        }
    }

    /**
     * Record the file's feedback, each distinct correction once.
     *
     * Feedback is anchored to the queue row of its source item, as feedback from the translator
     * feedback view is. An entry recorded by an earlier run is not recorded again, and an entry
     * whose source item is not in the queue is passed over.
     *
     * @param   array   $feedback  The feedback entries from the file.
     * @param   string  $origin    The origin to record them under.
     *
     * @return  integer  The number of rows recorded.
     *
     * @since   1.5.0
     */
    private function recordFeedback(array $feedback, string $origin): int
    {
        $db         = $this->getDatabase();
        $recorded   = 0;
        $translator = (int) $this->getCurrentUser()->id;
        $known      = $this->recordedKeys($origin);
        $queueIds   = $this->queueIds();
        $rows       = [];

        foreach ($feedback as $entry) {
            $key     = self::feedbackKey($entry['language'], $entry['source_text'], $entry['machine_draft'], $entry['human_correction']);
            $queueId = isset($known[$key]) ? null : ($queueIds[$entry['type'] . ':' . $entry['source_id']] ?? null);

            if ($queueId === null) {
                continue;
            }

            $known[$key] = true;
            $rows[]      = implode(',', [
                $queueId,
                $db->quote($entry['source_text']),
                $db->quote($entry['machine_draft']),
                $db->quote($entry['human_correction']),
                $db->quote($entry['language']),
                $db->quote($origin),
                $translator,
                $entry['occurrences'],
            ]);
        }

        // In batches: a file holds thousands of corrections, and a web request has seconds to record them.
        foreach (array_chunk($rows, 100) as $batch) {
            $query = $db->getQuery(true)
                ->insert($db->quoteName('#__translations_feedback'))
                ->columns($db->quoteName([
                    'queue_id', 'source_text', 'machine_draft', 'human_correction',
                    'target_language', 'source_origin', 'translator_id', 'occurrences',
                ]))
                ->values($batch);
            $db->setQuery($query)->execute();
            $recorded += \count($batch);
        }

        return $recorded;
    }

    /**
     * The corrections already in the feedback under this origin, so a file run again records nothing twice.
     *
     * Compared in PHP rather than in the query: the database collation takes corrections that
     * differ only in case or in trailing spaces for the same, and those are often the point.
     *
     * @param   string  $origin  The origin the feedback is recorded under.
     *
     * @return  array<string, true>  Keyed by feedbackKey().
     *
     * @since   1.5.0
     */
    private function recordedKeys(string $origin): array
    {
        $db    = $this->getDatabase();
        $query = $db->getQuery(true)
            ->select($db->quoteName(['target_language', 'source_text', 'machine_draft', 'human_correction']))
            ->from($db->quoteName('#__translations_feedback'))
            ->where($db->quoteName('source_origin') . ' = :origin')
            ->bind(':origin', $origin, ParameterType::STRING);
        $db->setQuery($query);

        $keys = [];

        foreach ($db->loadRowList() as [$language, $sourceText, $draft, $correction]) {
            $keys[self::feedbackKey((string) $language, (string) $sourceText, (string) $draft, (string) $correction)] = true;
        }

        return $keys;
    }

    /**
     * What makes a correction distinct.
     *
     * @param   string  $language    The target language.
     * @param   string  $sourceText  The source text.
     * @param   string  $draft       The text before the correction.
     * @param   string  $correction  The text after it.
     *
     * @return  string
     *
     * @since   1.5.0
     */
    private static function feedbackKey(string $language, string $sourceText, string $draft, string $correction): string
    {
        return hash('sha256', json_encode([$language, $sourceText, $draft, $correction], JSON_UNESCAPED_UNICODE) ?: '');
    }

    /**
     * The queue rows of the source items.
     *
     * @return  array<string, int>  The queue row id, keyed by content type and source id ("com_content.article:157").
     *
     * @since   1.5.1
     */
    private function queueIds(): array
    {
        $db    = $this->getDatabase();
        $query = $db->getQuery(true)
            ->select($db->quoteName(['id', 'content_type', 'content_id']))
            ->from($db->quoteName('#__translations_queue'));
        $db->setQuery($query);

        $ids = [];

        foreach ($db->loadRowList() as [$id, $contentType, $contentId]) {
            $ids[$contentType . ':' . (int) $contentId] = (int) $id;
        }

        return $ids;
    }

    /**
     * Read the corrections file, which has to lie inside the site.
     *
     * @param   string  $path  The file, relative to the site root.
     *
     * @return  string  Its contents.
     *
     * @throws  \RuntimeException  When the file is outside the site or cannot be read.
     *
     * @since   1.5.0
     */
    private function read(string $path): string
    {
        $root = realpath(JPATH_ROOT);
        $file = realpath(JPATH_ROOT . '/' . ltrim(str_replace('\\', '/', $path), '/'));

        if ($root === false || $file === false || !is_file($file) || !str_starts_with($file, $root . DIRECTORY_SEPARATOR)) {
            throw new \RuntimeException(\sprintf('The corrections file %s is not a file in the site.', $path));
        }

        $contents = file_get_contents($file);

        if ($contents === false) {
            throw new \RuntimeException(\sprintf('The corrections file %s cannot be read.', $path));
        }

        return $contents;
    }
}
