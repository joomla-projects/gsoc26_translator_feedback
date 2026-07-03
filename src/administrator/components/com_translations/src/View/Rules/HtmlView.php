<?php

/**
 * @package     Joomla.Administrator
 * @subpackage  com_translations
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Component\Translations\Administrator\View\Rules;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use Joomla\CMS\Helper\ContentHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Toolbar\ToolbarHelper;
use Joomla\Component\Translations\Administrator\Model\RulesModel;

/**
 * View class for a list of translation rules.
 *
 * @since  0.4.0
 */
class HtmlView extends BaseHtmlView
{
    /**
     * An array of items.
     *
     * @var    array
     * @since  0.4.0
     */
    public $items;

    /**
     * The pagination object.
     *
     * @var    \Joomla\CMS\Pagination\Pagination
     * @since  0.4.0
     */
    public $pagination;

    /**
     * The model state.
     *
     * @var    \Joomla\Registry\Registry
     * @since  0.4.0
     */
    public $state;

    /**
     * Form object for search filters.
     *
     * @var    \Joomla\CMS\Form\Form
     * @since  0.4.0
     */
    public $filterForm;

    /**
     * The active search filters.
     *
     * @var    array
     * @since  0.4.0
     */
    public $activeFilters;

    /**
     * Display the view.
     *
     * @param   string|null  $tpl  The name of the template file to parse.
     *
     * @return  void
     *
     * @since   0.4.0
     */
    public function display($tpl = null): void
    {
        /** @var RulesModel $model */
        $model = $this->getModel();

        $this->items         = $model->getItems();
        $this->pagination    = $model->getPagination();
        $this->state         = $model->getState();
        $this->filterForm    = $model->getFilterForm();
        $this->activeFilters = $model->getActiveFilters();

        $this->addToolbar();

        parent::display($tpl);
    }

    /**
     * Add the page title and toolbar.
     *
     * @return  void
     *
     * @since   0.4.0
     */
    protected function addToolbar(): void
    {
        $canDo = ContentHelper::getActions('com_translations');

        ToolbarHelper::title(Text::_('COM_TRANSLATIONS_RULES_TITLE'), 'book');

        if ($canDo->get('core.create')) {
            ToolbarHelper::addNew('rule.add');

            // Run the distiller over pending feedback on demand, ahead of the scheduled task.
            ToolbarHelper::custom('distiller.distill', 'refresh', '', 'COM_TRANSLATIONS_DISTILL_NOW', false);
        }

        if ($canDo->get('core.edit.state')) {
            ToolbarHelper::publishList('rules.publish');
            ToolbarHelper::unpublishList('rules.unpublish');

            if ($this->state->get('filter.published') != -2) {
                ToolbarHelper::trash('rules.trash');
            }
        }

        if ($this->state->get('filter.published') == -2 && $canDo->get('core.delete')) {
            ToolbarHelper::deleteList('JGLOBAL_CONFIRM_DELETE', 'rules.delete', 'JTOOLBAR_EMPTY_TRASH');
        }

        if ($canDo->get('core.admin') || $canDo->get('core.options')) {
            ToolbarHelper::preferences('com_translations');
        }
    }
}
