<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  Task.TranslationsDistiller
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Plugin\Task\TranslationsDistiller\Extension;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use Joomla\CMS\Extension\ComponentInterface;
use Joomla\CMS\MVC\Factory\MVCFactoryServiceInterface;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Component\Scheduler\Administrator\Event\ExecuteTaskEvent;
use Joomla\Component\Scheduler\Administrator\Task\Status;
use Joomla\Component\Scheduler\Administrator\Traits\TaskPluginTrait;
use Joomla\Component\Translations\Administrator\Helper\RunResult;
use Joomla\Component\Translations\Administrator\Helper\TimeBudget;
use Joomla\Component\Translations\Administrator\Model\CorrectionsModel;
use Joomla\Component\Translations\Administrator\Model\DistillerModel;
use Joomla\Event\SubscriberInterface;

/**
 * Task plugin that runs the rules distiller on a schedule.
 *
 * A thin trigger: it boots the Translations component and runs the distiller over pending
 * feedback for as long as the run's time budget allows, resuming until the backlog is drained.
 * The distillation itself lives in the component.
 *
 * @since  0.4.0
 */
final class TranslationsDistiller extends CMSPlugin implements SubscriberInterface
{
    use TaskPluginTrait;

    /**
     * The task routines this plugin offers.
     *
     * @var    string[][]
     * @since  0.4.0
     */
    protected const TASKS_MAP = [
        'translationsdistiller.distill' => [
            'langConstPrefix' => 'PLG_TASK_TRANSLATIONSDISTILLER',
            'form'            => 'distiller',
            'method'          => 'distill',
        ],
        'translationsdistiller.resetfailed' => [
            'langConstPrefix' => 'PLG_TASK_TRANSLATIONSDISTILLER_RESETFAILED',
            'method'          => 'resetFailed',
        ],
        'translationsdistiller.mergerules' => [
            'langConstPrefix' => 'PLG_TASK_TRANSLATIONSDISTILLER_MERGERULES',
            'form'            => 'mergerules',
            'method'          => 'mergeRules',
        ],
        'translationsdistiller.importcorrections' => [
            'langConstPrefix' => 'PLG_TASK_TRANSLATIONSDISTILLER_IMPORTCORRECTIONS',
            'form'            => 'importcorrections',
            'method'          => 'importCorrections',
        ],
    ];

    /**
     * Load the plugin language files automatically.
     *
     * @var    boolean
     * @since  0.4.0
     */
    protected $autoloadLanguage = true;

    /**
     * Returns the events this subscriber listens to.
     *
     * @return  array
     *
     * @since   0.4.0
     */
    public static function getSubscribedEvents(): array
    {
        return [
            'onTaskOptionsList'    => 'advertiseRoutines',
            'onExecuteTask'        => 'standardRoutineHandler',
            'onContentPrepareForm' => 'enhanceTaskItemForm',
        ];
    }

    /**
     * Run the rules distiller over pending feedback until the run's time budget is used up.
     *
     * @param   ExecuteTaskEvent  $event  The onExecuteTask event.
     *
     * @return  integer  The task exit status.
     *
     * @since   0.4.0
     */
    protected function distill(ExecuteTaskEvent $event): int
    {
        $params      = $event->getArgument('params');
        $requestSize = max(1, (int) ($params->request_size ?? DistillerModel::DEFAULT_REQUEST_SIZE));
        $budget      = new TimeBudget((int) ($params->time_budget ?? 0));
        $language    = $this->getApplication()->getLanguage();

        try {
            $result = $this->distillerModel()->distill($requestSize, $budget);
        } catch (\Throwable $e) {
            return $this->knockout($e->getMessage());
        }

        if ($result->busy) {
            $this->logTask($language->_('PLG_TASK_TRANSLATIONSDISTILLER_LOG_BUSY'));

            return Status::OK;
        }

        if ($result->processed === 0 && $result->failed === 0) {
            $this->logTask($language->_('PLG_TASK_TRANSLATIONSDISTILLER_LOG_NONE'));

            return Status::OK;
        }

        if ($result->processed > 0) {
            $this->logTask(
                \sprintf(
                    $language->_('PLG_TASK_TRANSLATIONSDISTILLER_LOG_PROCESSED'),
                    $result->processed,
                    $result->remaining
                )
            );
        }

        if ($result->failed > 0) {
            $this->logTask(
                \sprintf(
                    $language->_('PLG_TASK_TRANSLATIONSDISTILLER_LOG_FAILED'),
                    $result->failed,
                    $result->quarantined,
                    $result->lastError
                ),
                'warning'
            );
        }

        // Resume only after progress: a batch that fails every time must not be retried run after run.
        switch ($result->outcome()) {
            case RunResult::RESUME:
                return Status::WILL_RESUME;

            case RunResult::ERROR:
                return $this->knockout($result->lastError);

            default:
                return Status::OK;
        }
    }

    /**
     * Give the feedback rows that were set aside as failed a new set of attempts.
     *
     * @param   ExecuteTaskEvent  $event  The onExecuteTask event.
     *
     * @return  integer  The task exit status.
     *
     * @since   1.2.0
     */
    protected function resetFailed(ExecuteTaskEvent $event): int
    {
        try {
            $reset = $this->distillerModel()->resetFailed();
        } catch (\Throwable $e) {
            return $this->knockout($e->getMessage());
        }

        $this->logTask(\sprintf($this->getApplication()->getLanguage()->_('PLG_TASK_TRANSLATIONSDISTILLER_RESETFAILED_LOG'), $reset));

        return Status::OK;
    }

    /**
     * Merge the existing rules that say the same thing.
     *
     * @param   ExecuteTaskEvent  $event  The onExecuteTask event.
     *
     * @return  integer  The task exit status.
     *
     * @since   1.2.0
     */
    protected function mergeRules(ExecuteTaskEvent $event): int
    {
        $language = (string) ($event->getArgument('params')->target_language ?? '');

        try {
            $merged = $this->distillerModel()->mergeDuplicateRules($language);
        } catch (\Throwable $e) {
            return $this->knockout($e->getMessage());
        }

        $this->logTask(\sprintf($this->getApplication()->getLanguage()->_('PLG_TASK_TRANSLATIONSDISTILLER_MERGERULES_LOG'), $merged));

        return Status::OK;
    }

    /**
     * Apply a file of corrections to translations and record them as translator feedback.
     *
     * @param   ExecuteTaskEvent  $event  The onExecuteTask event.
     *
     * @return  integer  The task exit status.
     *
     * @since   1.3.0
     */
    protected function importCorrections(ExecuteTaskEvent $event): int
    {
        $params   = $event->getArgument('params');
        $file     = trim((string) ($params->file ?? ''));
        $budget   = new TimeBudget((int) ($params->time_budget ?? 0));
        $language = $this->getApplication()->getLanguage();

        try {
            /** @var CorrectionsModel $model */
            $model  = $this->component()->getMVCFactory()->createModel('Corrections', 'Administrator', ['ignore_request' => true]);
            $result = $model->import($file, $budget, $this->getApplication());
        } catch (\Throwable $e) {
            return $this->knockout($e->getMessage());
        }

        // Web Cron can start a run while the previous one is still saving; this task then carries on after it.
        if ($result['busy']) {
            $this->logTask($language->_('PLG_TASK_TRANSLATIONSDISTILLER_IMPORTCORRECTIONS_LOG_BUSY'));

            return Status::WILL_RESUME;
        }

        $this->logTask(
            \sprintf(
                $language->_('PLG_TASK_TRANSLATIONSDISTILLER_IMPORTCORRECTIONS_LOG_APPLIED'),
                $result['applied'],
                $result['done'],
                $result['remaining']
            )
        );

        $skipped = [
            'PLG_TASK_TRANSLATIONSDISTILLER_IMPORTCORRECTIONS_LOG_CHANGED' => $result['changed'],
            'PLG_TASK_TRANSLATIONSDISTILLER_IMPORTCORRECTIONS_LOG_MISSING' => $result['missing'],
            'PLG_TASK_TRANSLATIONSDISTILLER_IMPORTCORRECTIONS_LOG_LOCKED'  => $result['locked'],
        ];

        foreach ($skipped as $key => $labels) {
            if ($labels !== []) {
                $this->logTask(\sprintf($language->_($key), \count($labels), implode(', ', $labels)), 'warning');
            }
        }

        if ($result['failed'] !== []) {
            $this->logTask(
                \sprintf(
                    $language->_('PLG_TASK_TRANSLATIONSDISTILLER_IMPORTCORRECTIONS_LOG_FAILED'),
                    \count($result['failed']),
                    $result['lastError']
                ),
                'warning'
            );
        }

        if ($result['remaining'] > 0) {
            return Status::WILL_RESUME;
        }

        if ($result['feedbackPending']) {
            $this->logTask($language->_('PLG_TASK_TRANSLATIONSDISTILLER_IMPORTCORRECTIONS_LOG_FEEDBACK_NEXT'));

            return Status::WILL_RESUME;
        }

        $this->logTask(\sprintf($language->_('PLG_TASK_TRANSLATIONSDISTILLER_IMPORTCORRECTIONS_LOG_FEEDBACK'), $result['feedback']));

        return Status::OK;
    }

    /**
     * Boot the Translations component.
     *
     * @return  ComponentInterface&MVCFactoryServiceInterface
     *
     * @since   1.3.0
     */
    private function component(): ComponentInterface
    {
        /** @var ComponentInterface&MVCFactoryServiceInterface $component */
        $component = $this->getApplication()->bootComponent('com_translations');

        return $component;
    }

    /**
     * Boot the Translations component and create its distiller model.
     *
     * @return  DistillerModel
     *
     * @since   1.2.0
     */
    private function distillerModel(): DistillerModel
    {
        /** @var DistillerModel $model */
        $model = $this->component()->getMVCFactory()->createModel('Distiller', 'Administrator', ['ignore_request' => true]);

        return $model;
    }

    /**
     * Log an error as the outcome of the run.
     *
     * @param   string  $message  The error message.
     *
     * @return  integer  The KNOCKOUT exit status.
     *
     * @since   1.1.0
     */
    private function knockout(string $message): int
    {
        $this->logTask($message, 'error');
        $this->snapshot['output']      = $message;
        $this->snapshot['output_body'] = $message;

        return Status::KNOCKOUT;
    }
}
