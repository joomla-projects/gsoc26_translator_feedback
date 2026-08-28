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

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Router\Route;

/**
 * Controller for rule sets.
 *
 * The export task writes the rules a translator has selected, or everything the Rules list is
 * filtered to, as a file they can carry to another site; the set is gathered by RulesetModel.
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
