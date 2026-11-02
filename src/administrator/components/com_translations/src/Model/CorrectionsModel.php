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
 * passed over, so running a file again changes nothing.
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
     *                failed: string[], remaining: int, feedback: int, lastError: string}
     *
     * @throws  \RuntimeException  When the file cannot be read or is not a corrections file.
     *
     * @since   1.5.0
     */
    public function import(string $path, TimeBudget $budget, CMSApplicationInterface $application): array
    {
        $result = [
            'busy' => false, 'applied' => 0, 'done' => 0, 'changed' => [], 'missing' => [], 'locked' => [],
            'failed' => [], 'remaining' => 0, 'feedback' => 0, 'lastError' => '',
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
            foreach ($file['items'] as $item) {
                if ($result['applied'] > 0 && !$budget->allowsAnother()) {
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
            if ($result['remaining'] === 0) {
                $result['feedback'] = $this->recordFeedback($file['feedback'], $file['origin']);
            }
        } finally {
            $lock->release();
        }

        return $result;
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

        foreach ($feedback as $entry) {
            $key     = self::feedbackKey($entry['language'], $entry['source_text'], $entry['machine_draft'], $entry['human_correction']);
            $queueId = isset($known[$key]) ? null : $this->queueId($entry['type'], $entry['source_id']);

            if ($queueId === null) {
                continue;
            }

            $known[$key] = true;

            $row = (object) [
                'queue_id'         => $queueId,
                'source_text'      => $entry['source_text'],
                'machine_draft'    => $entry['machine_draft'],
                'human_correction' => $entry['human_correction'],
                'target_language'  => $entry['language'],
                'source_origin'    => $origin,
                'translator_id'    => $translator,
                'occurrences'      => $entry['occurrences'],
            ];

            $db->insertObject('#__translations_feedback', $row);
            $recorded++;
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
     * The queue row of a source item.
     *
     * @param   string   $contentType  The content type key.
     * @param   integer  $sourceId     The source item id.
     *
     * @return  integer|null  The queue row id, or null when the item is not in the queue.
     *
     * @since   1.5.0
     */
    private function queueId(string $contentType, int $sourceId): ?int
    {
        $db    = $this->getDatabase();
        $query = $db->getQuery(true)
            ->select($db->quoteName('id'))
            ->from($db->quoteName('#__translations_queue'))
            ->where($db->quoteName('content_type') . ' = :contentType')
            ->where($db->quoteName('content_id') . ' = :contentId')
            ->bind(':contentType', $contentType, ParameterType::STRING)
            ->bind(':contentId', $sourceId, ParameterType::INTEGER);
        $db->setQuery($query);

        $id = $db->loadResult();

        return $id === null ? null : (int) $id;
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
