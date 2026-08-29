<?php

/**
 * @package     Joomla.Administrator
 * @subpackage  com_translations
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Component\Translations\Administrator\Controller;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use Joomla\CMS\Access\Exception\NotAllowed;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Router\Route;

/**
 * Controller for rule sets.
 *
 * The export task writes the rules a translator has selected, or everything the Rules list is
 * filtered to, as a file they can carry to another site; the import task reads such a file back.
 * Both sets are handled by RulesetModel.
 *
 * @since  1.0.0
 */
class RulesetController extends BaseController
{
    /**
     * The state context the Rules list keeps its filters under.
     *
     * @var    string
     * @since  1.0.0
     */
    private const LIST_CONTEXT = 'com_translations.rules';

    /**
     * The Rules list filters an export follows.
     *
     * @var    string[]
     * @since  1.0.0
     */
    private const LIST_FILTERS = ['search', 'published', 'rule_type', 'target_language', 'source_origin'];

    /**
     * How many rules an import names before it reports the rest as a count.
     *
     * @var    integer
     * @since  1.0.0
     */
    private const NAMED_LIMIT = 5;

    /**
     * Send the selected rules, or the filtered ones, as a rule set file.
     *
     * @return  void
     *
     * @since   1.0.0
     */
    public function export()
    {
        $this->checkToken();

        /** @var \Joomla\CMS\Application\CMSApplication $app */
        $app     = $this->app;
        $filters = [];

        foreach (self::LIST_FILTERS as $filter) {
            $filters[$filter] = $app->getUserState(self::LIST_CONTEXT . '.filter.' . $filter);
        }

        // The selection arrives from the export form as one comma separated field, not as cid[].
        $ruleIds = array_filter(array_map('intval', explode(',', $this->input->post->getString('cids', ''))));

        /** @var \Joomla\Component\Translations\Administrator\Model\RulesetModel $model */
        $model = $this->getModel();
        $set   = $model->export($ruleIds, $filters);

        if ($set['rules'] === []) {
            $app->enqueueMessage(Text::_('COM_TRANSLATIONS_RULES_EXPORT_NONE'), 'warning');
            $this->setRedirect(Route::_('index.php?option=com_translations&view=rules', false));

            return;
        }

        $app->setHeader('Content-Type', 'application/json', true)
            ->setHeader('Content-Disposition', 'attachment; filename="' . $this->fileName($set['rules']) . '"', true)
            ->setHeader('Cache-Control', 'must-revalidate', true)
            ->sendHeaders();

        echo json_encode($set, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $app->close();
    }

    /**
     * Read an uploaded rule set into the site.
     *
     * @return  void
     *
     * @throws  NotAllowed  When the translator may not create rules here.
     *
     * @since   1.0.0
     */
    public function import()
    {
        $this->checkToken();

        $app      = $this->app;
        $rulesUrl = Route::_('index.php?option=com_translations&view=rules', false);

        // An import writes rules, so it asks for the permission the New button asks for.
        if (!$app->getIdentity()->authorise('core.create', 'com_translations')) {
            throw new NotAllowed($app->getLanguage()->_('JERROR_ALERTNOAUTHOR'), 403);
        }

        /** @var array $file */
        $file = $this->input->files->get('rules_file', [], 'raw');

        /** @var \Joomla\Component\Translations\Administrator\Model\RulesetModel $model */
        $model = $this->getModel();

        try {
            $report = $model->import($file, $this->input->getInt('state'));
        } catch (\RuntimeException $e) {
            $app->enqueueMessage($e->getMessage(), 'error');
            $this->setRedirect($rulesUrl);

            return;
        }

        foreach ($this->reportMessages($report) as [$message, $type]) {
            $app->enqueueMessage($message, $type);
        }

        $this->setRedirect($rulesUrl);
    }

    /**
     * Say what an import did.
     *
     * Rules that were passed over as duplicates are reported as a count, since that is the dedup
     * working; the ones a translator can act on are named.
     *
     * @param   array  $report  What the import wrote, passed over and refused.
     *
     * @return  array  Each message with the type to show it as.
     *
     * @since   1.0.0
     */
    private function reportMessages(array $report): array
    {
        $messages = [];

        if ($report['imported'] > 0) {
            $messages[] = [
                Text::plural('COM_TRANSLATIONS_RULES_IMPORT_N_IMPORTED', $report['imported']),
                'message',
            ];
        }

        if ($report['duplicates'] > 0) {
            $messages[] = [
                Text::plural('COM_TRANSLATIONS_RULES_IMPORT_N_DUPLICATES', $report['duplicates']),
                'warning',
            ];
        }

        if ($report['trashed'] !== []) {
            $messages[] = [
                Text::sprintf('COM_TRANSLATIONS_RULES_IMPORT_TRASHED', $this->nameList($report['trashed'])),
                'warning',
            ];
        }

        if ($report['rejected'] !== []) {
            $refused = [];

            foreach ($report['rejected'] as $rule) {
                $refused[] = $rule['name'] === ''
                    ? $rule['reason']
                    : Text::sprintf('COM_TRANSLATIONS_RULES_IMPORT_REJECTED_RULE', $rule['name'], $rule['reason']);
            }

            $messages[] = [
                Text::sprintf('COM_TRANSLATIONS_RULES_IMPORT_REJECTED', $this->nameList($refused)),
                'error',
            ];
        }

        return $messages;
    }

    /**
     * List what an import names, keeping a long list readable.
     *
     * @param   string[]  $names  What to list.
     *
     * @return  string  The list.
     *
     * @since   1.0.0
     */
    private function nameList(array $names): string
    {
        $shown = \array_slice($names, 0, self::NAMED_LIMIT);
        $rest  = \count($names) - \count($shown);
        $list  = implode('; ', $shown);

        return $rest > 0 ? Text::sprintf('COM_TRANSLATIONS_RULES_IMPORT_AND_MORE', $list, $rest) : $list;
    }

    /**
     * Name the file a set is sent as.
     *
     * A set holding one language is named after it, so two downloads are told apart by more than
     * their timestamp.
     *
     * @param   array  $rules  The rules being sent.
     *
     * @return  string  The file name.
     *
     * @since   1.0.0
     */
    private function fileName(array $rules): string
    {
        $languages = array_unique(array_column($rules, 'target_language'));
        $language  = \count($languages) === 1 ? '_' . reset($languages) : '';

        return 'translation-rules' . $language . '_' . Factory::getDate()->format('Y-m-d') . '.json';
    }

    /**
     * Proxy for getModel.
     *
     * The prefix is set because a model is otherwise looked for under the name of the running
     * application, and the models all live in the administrator.
     *
     * @param   string  $name    The model name. Optional.
     * @param   string  $prefix  The class prefix. Optional.
     * @param   array   $config  Configuration array for the model. Optional.
     *
     * @return  \Joomla\CMS\MVC\Model\BaseDatabaseModel  The model.
     *
     * @since   1.0.0
     */
    public function getModel($name = 'Ruleset', $prefix = 'Administrator', $config = [])
    {
        return parent::getModel($name, $prefix, $config);
    }
}
