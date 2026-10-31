<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  Task.TranslationsLanguageFiles
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Plugin\Task\TranslationsLanguageFiles\Extension;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use Joomla\Application\WebApplicationInterface;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Event\Plugin\AjaxEvent;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Uri\Uri;
use Joomla\Component\Scheduler\Administrator\Event\ExecuteTaskEvent;
use Joomla\Component\Scheduler\Administrator\Task\Status;
use Joomla\Component\Scheduler\Administrator\Traits\TaskPluginTrait;
use Joomla\Component\Translations\Administrator\Helper\RunResult;
use Joomla\Component\Translations\Administrator\Helper\TimeBudget;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use Joomla\Event\DispatcherInterface;
use Joomla\Event\SubscriberInterface;
use Joomla\Plugin\Task\TranslationsLanguageFiles\Helper\LanguageFileTranslator;
use Joomla\Plugin\Task\TranslationsLanguageFiles\Helper\LanguageFilesArchive;
use Joomla\Plugin\Task\TranslationsLanguageFiles\Helper\LanguagePackage;
use Joomla\Plugin\Task\TranslationsLanguageFiles\Helper\PackageLanguageFiles;

/**
 * Translates Translator Feedback's own interface into the site's content languages.
 *
 * Translators use the package in their own language, and for a right-to-left language the
 * editor only works when the screens around it are in that language too, so the package needs
 * its language files in every language it is used in. This plugin makes them on a site that has
 * the rules to make them well - guide.joomla.org, whose rules are learned from the core language
 * packs. The translations are stored, so they can be delivered at any time: as one download laid
 * out like the package's src folder, as an installable package per language, or installed on
 * this site directly.
 *
 * @since  1.0.0
 */
final class TranslationsLanguageFiles extends CMSPlugin implements SubscriberInterface
{
    use TaskPluginTrait;

    /**
     * The start of the element of the installable packages, before the language tag.
     *
     * @var    string
     * @since  1.1.0
     */
    public const PACKAGE_ELEMENT = 'translatorfeedback';

    /**
     * Where the administrator downloads or installs the translated files.
     *
     * @var    string
     * @since  1.0.0
     */
    public const DOWNLOAD_URL = 'index.php?option=com_ajax&group=task&plugin=translationslanguagefiles&format=raw';

    /**
     * The task routines this plugin offers.
     *
     * @var    string[][]
     * @since  1.0.0
     */
    protected const TASKS_MAP = [
        'translationslanguagefiles.translate' => [
            'langConstPrefix' => 'PLG_TASK_TRANSLATIONSLANGUAGEFILES',
            'form'            => 'translate',
            'method'          => 'translate',
        ],
        'translationslanguagefiles.resetfailed' => [
            'langConstPrefix' => 'PLG_TASK_TRANSLATIONSLANGUAGEFILES_RESETFAILED',
            'method'          => 'resetFailed',
        ],
        'translationslanguagefiles.forget' => [
            'langConstPrefix' => 'PLG_TASK_TRANSLATIONSLANGUAGEFILES_FORGET',
            'form'            => 'forget',
            'method'          => 'forget',
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
     * Constructor.
     *
     * @param   array              $config    The plugin's configuration.
     * @param   DatabaseInterface  $database  The site's database.
     *
     * @since   1.0.0
     */
    public function __construct(array $config, private readonly DatabaseInterface $database)
    {
        parent::__construct($config);
    }

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
            'onTaskOptionsList'               => 'advertiseRoutines',
            'onExecuteTask'                   => 'standardRoutineHandler',
            'onContentPrepareForm'            => 'enhanceTaskItemForm',
            'onAjaxTranslationslanguagefiles' => 'deliver',
        ];
    }

    /**
     * The content languages there are to translate into, in the site's order.
     *
     * @param   DatabaseInterface  $db  The site's database.
     *
     * @return  string[]  Every published content language but the package's own.
     *
     * @since   1.0.0
     */
    public static function targetLanguages(DatabaseInterface $db): array
    {
        $source = PackageLanguageFiles::SOURCE_LANGUAGE;
        $query  = $db->getQuery(true)
            ->select($db->quoteName('lang_code'))
            ->from($db->quoteName('#__languages'))
            ->where($db->quoteName('published') . ' = 1')
            ->where($db->quoteName('lang_code') . ' <> ' . $db->quote('*'))
            ->where($db->quoteName('lang_code') . ' <> :source')
            ->order($db->quoteName('ordering') . ' ASC')
            ->bind(':source', $source, ParameterType::STRING);

        return $db->setQuery($query)->loadColumn() ?: [];
    }

    /**
     * Translate the language files, as far as the run's time allows.
     *
     * @param   ExecuteTaskEvent  $event  The onExecuteTask event.
     *
     * @return  integer  The task exit status.
     *
     * @since   1.0.0
     */
    protected function translate(ExecuteTaskEvent $event): int
    {
        $params      = $event->getArgument('params');
        $requestSize = max(1, (int) ($params->request_size ?? LanguageFileTranslator::DEFAULT_REQUEST_SIZE));
        $budget      = new TimeBudget((int) ($params->time_budget ?? 0));
        $language    = $this->getApplication()->getLanguage();

        // This plugin is installed on its own, so the component whose rules and provider it uses may not be there.
        if (!ComponentHelper::isEnabled('com_translations')) {
            return $this->knockout($language->_('PLG_TASK_TRANSLATIONSLANGUAGEFILES_LOG_NO_COMPONENT'));
        }

        if (PackageLanguageFiles::discover($this->database) === []) {
            return $this->knockout($language->_('PLG_TASK_TRANSLATIONSLANGUAGEFILES_LOG_NO_FILES'));
        }

        $available = self::targetLanguages($this->database);
        $selected  = array_values(array_filter((array) ($params->languages ?? [])));
        $languages = $selected === [] ? $available : array_values(array_intersect($available, $selected));

        if ($languages === []) {
            return $this->knockout($language->_('PLG_TASK_TRANSLATIONSLANGUAGEFILES_LOG_NO_LANGUAGES'));
        }

        // The provider answers on the application's dispatcher, which is where importPlugin registers it.
        $translator = new LanguageFileTranslator($this->database, $this->getApplication()->getDispatcher());

        try {
            $result = $translator->translate($languages, $requestSize, $budget);
        } catch (\Throwable $e) {
            return $this->knockout($e->getMessage());
        }

        if ($result->busy) {
            $this->logTask($language->_('PLG_TASK_TRANSLATIONSLANGUAGEFILES_LOG_BUSY'));

            return Status::OK;
        }

        if ($result->processed === 0 && $result->failed === 0) {
            $this->logTask($language->_('PLG_TASK_TRANSLATIONSLANGUAGEFILES_LOG_NONE'));

            return Status::OK;
        }

        if ($result->processed > 0) {
            $this->logTask(
                \sprintf(
                    $language->_('PLG_TASK_TRANSLATIONSLANGUAGEFILES_LOG_TRANSLATED'),
                    $result->processed,
                    $result->remaining
                )
            );
        }

        if ($result->failed > 0) {
            $this->logTask(
                \sprintf(
                    $language->_('PLG_TASK_TRANSLATIONSLANGUAGEFILES_LOG_FAILED'),
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
     * Give the strings that were set aside as failed a new set of attempts.
     *
     * @param   ExecuteTaskEvent  $event  The onExecuteTask event.
     *
     * @return  integer  The task exit status.
     *
     * @since   1.0.0
     */
    protected function resetFailed(ExecuteTaskEvent $event): int
    {
        $reset = (new LanguageFileTranslator($this->database, $this->getApplication()->getDispatcher()))->resetFailed();

        $this->logTask(\sprintf($this->getApplication()->getLanguage()->_('PLG_TASK_TRANSLATIONSLANGUAGEFILES_RESETFAILED_LOG'), $reset));

        return Status::OK;
    }

    /**
     * Forget the stored translations of some languages, so the next run translates them again.
     *
     * @param   ExecuteTaskEvent  $event  The onExecuteTask event.
     *
     * @return  integer  The task exit status.
     *
     * @since   1.0.0
     */
    protected function forget(ExecuteTaskEvent $event): int
    {
        $languages = array_values(array_filter((array) ($event->getArgument('params')->languages ?? [])));
        $forgotten = (new LanguageFileTranslator($this->database, $this->getApplication()->getDispatcher()))->forget($languages);

        $this->logTask(
            \sprintf(
                $this->getApplication()->getLanguage()->_('PLG_TASK_TRANSLATIONSLANGUAGEFILES_FORGET_LOG'),
                $forgotten,
                $languages === [] ? '*' : implode(', ', $languages)
            )
        );

        return Status::OK;
    }

    /**
     * Deliver the stored translations in one of three ways.
     *
     * Reached through com_ajax in the administrator, by someone who may manage scheduled tasks:
     * deliver=archive (the default) sends one zip laid out like the package's src folder, for the
     * repository; deliver=package sends the installable package of one language; deliver=install
     * installs that package on this site, which also needs permission to manage extensions and a
     * form token, and returns to the page it came from. Each works from the stored translations, so
     * it can be asked for again at any time.
     *
     * @param   AjaxEvent  $event  The onAjaxTranslationslanguagefiles event.
     *
     * @return  void
     *
     * @since   1.1.0
     */
    public function deliver(AjaxEvent $event): void
    {
        $app = $this->getApplication();

        if (!$app instanceof WebApplicationInterface
            || !$app->isClient('administrator')
            || !$app->getIdentity()->authorise('core.manage', 'com_scheduler')) {
            throw new \RuntimeException($app->getLanguage()->_('JERROR_ALERTNOAUTHOR'), 403);
        }

        $translator = new LanguageFileTranslator($this->database, Factory::getContainer()->get(DispatcherInterface::class));
        $input      = $app->getInput();
        $deliver    = $input->getCmd('deliver', 'archive');

        if ($deliver === 'archive') {
            $this->send($app, (new LanguageFilesArchive($this->database, $translator))->build(), LanguageFilesArchive::fileName());
        }

        $language = $input->getString('language', '');

        if (!\in_array($deliver, ['package', 'install'], true) || !\in_array($language, self::targetLanguages($this->database), true)) {
            throw new \RuntimeException($app->getLanguage()->_('JERROR_LAYOUT_PAGE_NOT_FOUND'), 404);
        }

        $package = self::package($this->database, $translator);

        if ($deliver === 'package') {
            $this->send($app, $package->build($language), $package->fileName($language));
        }

        if (!Session::checkToken('get') || !$app->getIdentity()->authorise('core.manage', 'com_installer')) {
            throw new \RuntimeException($app->getLanguage()->_('JERROR_ALERTNOAUTHOR'), 403);
        }

        try {
            if ($package->install($language)) {
                $app->enqueueMessage(
                    \sprintf($app->getLanguage()->_('PLG_TASK_TRANSLATIONSLANGUAGEFILES_INSTALLED'), $language, 'files_' . $package->element($language)),
                    'success'
                );
            } else {
                $app->enqueueMessage(\sprintf($app->getLanguage()->_('PLG_TASK_TRANSLATIONSLANGUAGEFILES_INSTALL_FAILED'), $language), 'error');
            }
        } catch (\RuntimeException $e) {
            $app->enqueueMessage($e->getMessage(), 'error');
        }

        $return = (string) base64_decode($input->getBase64('return', ''), true);

        $app->redirect($return !== '' && Uri::isInternal($return) ? $return : 'index.php?option=com_scheduler&view=tasks');
    }

    /**
     * The installable packages of the translations, one per language.
     *
     * @param   DatabaseInterface       $db          The site's database.
     * @param   LanguageFileTranslator  $translator  Where the translations are kept.
     *
     * @return  LanguagePackage
     *
     * @since   1.1.0
     */
    public static function package(DatabaseInterface $db, LanguageFileTranslator $translator): LanguagePackage
    {
        return new LanguagePackage($db, $translator, PackageLanguageFiles::discover($db), self::PACKAGE_ELEMENT, 'Translator Feedback');
    }

    /**
     * Send a zip and end the request.
     *
     * @param   WebApplicationInterface  $app       The application to send it with.
     * @param   string                   $path      The zip, a temporary file that is removed once it is sent.
     * @param   string                   $fileName  The name to offer it under.
     *
     * @return  never
     *
     * @since   1.1.0
     */
    private function send(WebApplicationInterface $app, string $path, string $fileName): never
    {
        $app->setHeader('Content-Type', 'application/zip', true);
        $app->setHeader('Content-Disposition', 'attachment; filename="' . $fileName . '"', true);
        $app->setHeader('Content-Length', (string) filesize($path), true);
        $app->sendHeaders();
        readfile($path);
        unlink($path);

        $app->close();

        exit;
    }

    /**
     * Log an error as the outcome of the run.
     *
     * @param   string  $message  The error message.
     *
     * @return  integer  The KNOCKOUT exit status.
     *
     * @since   1.0.0
     */
    private function knockout(string $message): int
    {
        $this->logTask($message, 'error');
        $this->snapshot['output']      = $message;
        $this->snapshot['output_body'] = $message;

        return Status::KNOCKOUT;
    }
}
