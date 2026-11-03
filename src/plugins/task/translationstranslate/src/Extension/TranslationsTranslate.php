<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  Task.TranslationsTranslate
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Plugin\Task\TranslationsTranslate\Extension;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use Joomla\CMS\Extension\ComponentInterface;
use Joomla\CMS\MVC\Factory\MVCFactoryServiceInterface;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Component\Scheduler\Administrator\Event\ExecuteTaskEvent;
use Joomla\Component\Scheduler\Administrator\Task\Status;
use Joomla\Component\Scheduler\Administrator\Traits\TaskPluginTrait;
use Joomla\Component\Translations\Administrator\Association\LostTranslations;
use Joomla\Component\Translations\Administrator\Helper\RunResult;
use Joomla\Component\Translations\Administrator\Helper\TimeBudget;
use Joomla\Component\Translations\Administrator\Model\TranslationModel;
use Joomla\Event\SubscriberInterface;

/**
 * Task plugin that works through the translation queue on a schedule.
 *
 * A thin trigger: it boots the Translations component and translates a batch of the items
 * waiting on a translation per execution, for as long as the run's time budget allows,
 * resuming until the queue is drained. The translating itself lives in the component.
 *
 * @since  0.11.0
 */
final class TranslationsTranslate extends CMSPlugin implements SubscriberInterface
{
    use TaskPluginTrait;

    /**
     * The task routines this plugin offers.
     *
     * @var    string[][]
     * @since  0.11.0
     */
    protected const TASKS_MAP = [
        'translationstranslate.translate' => [
            'langConstPrefix' => 'PLG_TASK_TRANSLATIONSTRANSLATE',
            'form'            => 'translate',
            'method'          => 'translate',
        ],
        'translationstranslate.resetfailed' => [
            'langConstPrefix' => 'PLG_TASK_TRANSLATIONSTRANSLATE_RESETFAILED',
            'method'          => 'resetFailed',
        ],
        'translationstranslate.align' => [
            'langConstPrefix' => 'PLG_TASK_TRANSLATIONSTRANSLATE_ALIGN',
            'form'            => 'align',
            'method'          => 'align',
        ],
        'translationstranslate.reassociate' => [
            'langConstPrefix' => 'PLG_TASK_TRANSLATIONSTRANSLATE_REASSOCIATE',
            'form'            => 'reassociate',
            'method'          => 'reassociate',
        ],
    ];

    /**
     * The parts of bringing translated menus in line with their source, by their switch in the form.
     *
     * @var    string[]
     * @since  2.0.0
     */
    private const ALIGN_PARTS = ['order', 'homes', 'params', 'links', 'modules'];

    /**
     * Load the plugin language files automatically.
     *
     * @var    boolean
     * @since  0.11.0
     */
    protected $autoloadLanguage = true;

    /**
     * Returns the events this subscriber listens to.
     *
     * @return  array
     *
     * @since   0.11.0
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
     * Translate a batch of the items waiting on a translation, until the run's time budget is used up.
     *
     * @param   ExecuteTaskEvent  $event  The onExecuteTask event.
     *
     * @return  integer  The task exit status.
     *
     * @since   0.11.0
     */
    protected function translate(ExecuteTaskEvent $event): int
    {
        $params      = $event->getArgument('params');
        $batchSize   = max(1, (int) ($params->batch ?? 5));
        $budget      = new TimeBudget((int) ($params->time_budget ?? 0));
        $application = $this->getApplication();
        $language    = $application->getLanguage();

        try {
            $result = $this->translationModel()->translateBatch($batchSize, $application, $budget);
        } catch (\Throwable $e) {
            return $this->knockout($e->getMessage());
        }

        if ($result->busy) {
            $this->logTask($language->_('PLG_TASK_TRANSLATIONSTRANSLATE_LOG_BUSY'));

            return Status::OK;
        }

        if ($result->processed === 0 && $result->failed === 0 && $result->advanced === 0) {
            $this->logTask($language->_('PLG_TASK_TRANSLATIONSTRANSLATE_LOG_NONE'));

            return Status::OK;
        }

        // A long item takes several runs, so say what was got through even when no item finished.
        if ($result->advanced > 0) {
            $this->logTask(
                \sprintf($language->_('PLG_TASK_TRANSLATIONSTRANSLATE_LOG_ADVANCED'), $result->advanced)
            );
        }

        if ($result->processed > 0) {
            $this->logTask(
                \sprintf(
                    $language->_('PLG_TASK_TRANSLATIONSTRANSLATE_LOG_TRANSLATED'),
                    $result->processed,
                    $result->remaining
                )
            );
        }

        if ($result->failed > 0) {
            $this->logTask(
                \sprintf(
                    $language->_('PLG_TASK_TRANSLATIONSTRANSLATE_LOG_FAILED'),
                    $result->failed,
                    $result->quarantined,
                    $result->lastError
                ),
                'warning'
            );
        }

        // Resume only after progress: an item that fails every time must not be retried run after run.
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
     * Give the translations that were set aside as failed a new set of attempts.
     *
     * @param   ExecuteTaskEvent  $event  The onExecuteTask event.
     *
     * @return  integer  The task exit status.
     *
     * @since   1.3.0
     */
    protected function resetFailed(ExecuteTaskEvent $event): int
    {
        try {
            $reset = $this->translationModel()->resetFailed();
        } catch (\Throwable $e) {
            return $this->knockout($e->getMessage());
        }

        $this->logTask(\sprintf($this->getApplication()->getLanguage()->_('PLG_TASK_TRANSLATIONSTRANSLATE_RESETFAILED_LOG'), $reset));

        return Status::OK;
    }

    /**
     * Bring the translated menus of a site in line with their source, once.
     *
     * For a site whose translations were made before Translator Feedback was used: it does for
     * them what a translation made here gets when it is made. A part switched off in the task's
     * form is left alone.
     *
     * @param   ExecuteTaskEvent  $event  The onExecuteTask event.
     *
     * @return  integer  The task exit status.
     *
     * @since   2.0.0
     */
    protected function align(ExecuteTaskEvent $event): int
    {
        $params = $event->getArgument('params');
        $parts  = array_values(array_filter(self::ALIGN_PARTS, static fn (string $part): bool => (int) ($params->$part ?? 1) === 1));

        try {
            $done = $this->translationModel()->alignWithSource($parts, $this->getApplication());
        } catch (\Throwable $e) {
            return $this->knockout($e->getMessage());
        }

        $language = $this->getApplication()->getLanguage();

        foreach ($done as $part => $count) {
            $this->logTask(\sprintf($language->_('PLG_TASK_TRANSLATIONSTRANSLATE_ALIGN_LOG_' . strtoupper($part)), $count));
        }

        return Status::OK;
    }

    /**
     * Find the translations that lost their association with their source, and associate them again.
     *
     * A dry run, the default, only reports what it would associate. A translation that cannot be
     * told for sure - no item or several carry its title - is reported, with the ids to choose
     * from, for a person to associate.
     *
     * @param   ExecuteTaskEvent  $event  The onExecuteTask event.
     *
     * @return  integer  The task exit status.
     *
     * @since   4.0.0
     */
    protected function reassociate(ExecuteTaskEvent $event): int
    {
        $dryRun   = (int) ($event->getArgument('params')->dry_run ?? 1) === 1;
        $language = $this->getApplication()->getLanguage();
        $prefix   = $dryRun ? $language->_('PLG_TASK_TRANSLATIONSTRANSLATE_REASSOCIATE_LOG_DRY_RUN') : '';

        try {
            $found = $this->translationModel()->restoreLostTranslations($dryRun);
        } catch (\Throwable $e) {
            return $this->knockout($e->getMessage());
        }

        $associated = 0;

        foreach ($found as $lost) {
            $associated += $lost['outcome'] === LostTranslations::ASSOCIATED ? 1 : 0;

            $this->logTask(
                $prefix . \sprintf(
                    $language->_('PLG_TASK_TRANSLATIONSTRANSLATE_REASSOCIATE_LOG_' . strtoupper($lost['outcome'])),
                    $lost['type'],
                    $lost['source'],
                    $lost['title'],
                    $lost['language'],
                    $lost['draftTitle'],
                    implode(', ', $lost['ids'])
                ),
                $lost['outcome'] === LostTranslations::ASSOCIATED ? 'info' : 'warning'
            );
        }

        $this->logTask($prefix . \sprintf($language->_('PLG_TASK_TRANSLATIONSTRANSLATE_REASSOCIATE_LOG_DONE'), $associated, \count($found) - $associated));

        if (!$dryRun && $associated > 0) {
            $this->logTask($language->_('PLG_TASK_TRANSLATIONSTRANSLATE_REASSOCIATE_LOG_NEXT'));
        }

        return Status::OK;
    }

    /**
     * Boot the Translations component and create its translation model.
     *
     * @return  TranslationModel
     *
     * @since   1.3.0
     */
    private function translationModel(): TranslationModel
    {
        /** @var ComponentInterface&MVCFactoryServiceInterface $component */
        $component = $this->getApplication()->bootComponent('com_translations');

        /** @var TranslationModel $model */
        $model = $component->getMVCFactory()->createModel('Translation', 'Administrator', ['ignore_request' => true]);

        return $model;
    }

    /**
     * Log an error as the outcome of the run.
     *
     * @param   string  $message  The error message.
     *
     * @return  integer  The KNOCKOUT exit status.
     *
     * @since   1.3.0
     */
    private function knockout(string $message): int
    {
        $this->logTask($message, 'error');
        $this->snapshot['output']      = $message;
        $this->snapshot['output_body'] = $message;

        return Status::KNOCKOUT;
    }
}
