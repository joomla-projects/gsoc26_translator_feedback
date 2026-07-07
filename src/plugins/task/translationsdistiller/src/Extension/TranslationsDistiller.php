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
use Joomla\Component\Translations\Administrator\Model\DistillerModel;
use Joomla\Event\SubscriberInterface;

/**
 * Task plugin that runs the rules distiller on a schedule.
 *
 * A thin trigger: it boots the Translations component and runs the distiller over one
 * batch of pending feedback per execution, resuming until the backlog is drained. The
 * distillation itself lives in the component.
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
     * Run the rules distiller over one batch of pending feedback.
     *
     * @param   ExecuteTaskEvent  $event  The onExecuteTask event.
     *
     * @return  integer  The task exit status.
     *
     * @since   0.4.0
     */
    protected function distill(ExecuteTaskEvent $event): int
    {
        $params    = $event->getArgument('params');
        $batchSize = max(1, (int) ($params->batch ?? 20));
        $language  = $this->getApplication()->getLanguage();

        /** @var ComponentInterface&MVCFactoryServiceInterface $component */
        $component = $this->getApplication()->bootComponent('com_translations');

        /** @var DistillerModel $model */
        $model = $component->getMVCFactory()->createModel('Distiller', 'Administrator', ['ignore_request' => true]);

        try {
            $processed = $model->distill($batchSize);
        } catch (\Throwable $e) {
            $this->logTask($e->getMessage(), 'error');

            return Status::KNOCKOUT;
        }

        if ($processed === 0) {
            $this->logTask($language->_('PLG_TASK_TRANSLATIONSDISTILLER_LOG_NONE'));

            return Status::OK;
        }

        $this->logTask(\sprintf($language->_('PLG_TASK_TRANSLATIONSDISTILLER_LOG_PROCESSED'), $processed));

        // A full batch usually means more feedback is pending; resume until a run comes up short.
        return $processed === $batchSize ? Status::WILL_RESUME : Status::OK;
    }
}
