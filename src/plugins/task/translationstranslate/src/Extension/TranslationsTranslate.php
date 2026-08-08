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
use Joomla\Component\Translations\Administrator\Model\TranslationModel;
use Joomla\Event\SubscriberInterface;

/**
 * Task plugin that works through the translation queue on a schedule.
 *
 * A thin trigger: it boots the Translations component and translates one batch of the
 * items waiting on a translation per execution, resuming until the queue is drained.
 * The translating itself lives in the component.
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
    ];

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
     * Translate one batch of the items waiting on a translation.
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
        $application = $this->getApplication();
        $language    = $application->getLanguage();

        /** @var ComponentInterface&MVCFactoryServiceInterface $component */
        $component = $application->bootComponent('com_translations');

        /** @var TranslationModel $model */
        $model = $component->getMVCFactory()->createModel('Translation', 'Administrator', ['ignore_request' => true]);

        try {
            $translated = $model->translateBatch($batchSize, $application);
        } catch (\Throwable $e) {
            $this->logTask($e->getMessage(), 'error');

            return Status::KNOCKOUT;
        }

        if ($translated === 0) {
            $this->logTask($language->_('PLG_TASK_TRANSLATIONSTRANSLATE_LOG_NONE'));

            return Status::OK;
        }

        $this->logTask(\sprintf($language->_('PLG_TASK_TRANSLATIONSTRANSLATE_LOG_TRANSLATED'), $translated));

        // A full batch usually means more is waiting; resume until a run comes up short.
        return $translated === $batchSize ? Status::WILL_RESUME : Status::OK;
    }
}
