<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  Task.TranslationsSeed
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Plugin\Task\TranslationsSeed\Extension;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Component\Scheduler\Administrator\Event\ExecuteTaskEvent;
use Joomla\Component\Scheduler\Administrator\Task\Status;
use Joomla\Component\Scheduler\Administrator\Traits\TaskPluginTrait;
use Joomla\Database\DatabaseAwareTrait;
use Joomla\Event\SubscriberInterface;
use Joomla\Plugin\Task\TranslationsSeed\Helper\Seeder;

/**
 * Task plugin that seeds translation rules from an installed language pack.
 *
 * A language pack is a body of translations its language team has already agreed on, so it can
 * teach the Translations component that team's terminology without anyone correcting a draft
 * by hand. One batch of strings is seeded per execution, resuming until the pack is drained.
 *
 * @since  1.0.0
 */
final class TranslationsSeed extends CMSPlugin implements SubscriberInterface
{
    use TaskPluginTrait;
    use DatabaseAwareTrait;

    /**
     * The task routines this plugin offers.
     *
     * @var    string[][]
     * @since  1.0.0
     */
    protected const TASKS_MAP = [
        'translationsseed.seed' => [
            'langConstPrefix' => 'PLG_TASK_TRANSLATIONSSEED',
            'form'            => 'seed',
            'method'          => 'seed',
        ],
    ];

    /**
     * Load the plugin language files automatically.
     *
     * @var    boolean
     * @since  1.0.0
     */
    protected $autoloadLanguage = true;

    /**
     * Returns the events this subscriber listens to.
     *
     * @return  array
     *
     * @since   1.0.0
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
     * Seed feedback from one batch of a language pack.
     *
     * @param   ExecuteTaskEvent  $event  The onExecuteTask event.
     *
     * @return  integer  The task exit status.
     *
     * @since   1.0.0
     */
    protected function seed(ExecuteTaskEvent $event): int
    {
        $params         = $event->getArgument('params');
        $batchSize      = max(1, (int) ($params->batch ?? 50));
        $targetLanguage = (string) ($params->target_language ?? '');
        $fileNames      = array_filter(array_map('trim', explode(',', (string) ($params->files ?? ''))));
        $language       = $this->getApplication()->getLanguage();

        // This plugin is installed on its own, so the component it feeds may not be there at all.
        if (!ComponentHelper::isEnabled('com_translations')) {
            $this->logTask($language->_('PLG_TASK_TRANSLATIONSSEED_LOG_NO_COMPONENT'), 'error');

            return Status::KNOCKOUT;
        }

        if ($targetLanguage === '') {
            $this->logTask($language->_('PLG_TASK_TRANSLATIONSSEED_LOG_NO_LANGUAGE'), 'error');

            return Status::KNOCKOUT;
        }

        $sourceLanguage = (string) ComponentHelper::getParams('com_translations')->get('source_language', 'en-GB');

        // The provider answers on the application's dispatcher, which is where importPlugin registers it.
        $seeder = new Seeder($this->getDatabase(), $this->getApplication()->getDispatcher());

        try {
            $seeded = $seeder->seed($sourceLanguage, $targetLanguage, $batchSize, $fileNames);
        } catch (\Throwable $e) {
            $this->logTask($e->getMessage(), 'error');

            return Status::KNOCKOUT;
        }

        if ($seeded === 0) {
            $this->logTask(\sprintf($language->_('PLG_TASK_TRANSLATIONSSEED_LOG_NONE'), $targetLanguage));

            return Status::OK;
        }

        $this->logTask(\sprintf($language->_('PLG_TASK_TRANSLATIONSSEED_LOG_SEEDED'), $seeded, $targetLanguage));

        // A full batch usually means the pack has more to give; resume until a run comes up short.
        return $seeded === $batchSize ? Status::WILL_RESUME : Status::OK;
    }
}
