<?php

/**
 * @package     Joomla.Administrator
 * @subpackage  com_translations
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Component\Translations\Administrator\View\Queue;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use Joomla\CMS\Helper\ContentHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Toolbar\ToolbarHelper;
use Joomla\Component\Translations\Administrator\Model\QueueModel;

/**
 * View class for a list of translation queue items.
 *
 * @since  0.1.0
 */
class HtmlView extends BaseHtmlView
{
    /**
     * An array of items.
     *
     * @var    array
     * @since  0.1.0
     */
    public $items;

    /**
     * The pagination object.
     *
     * @var    \Joomla\CMS\Pagination\Pagination
     * @since  0.1.0
     */
    public $pagination;

    /**
     * The model state.
     *
     * @var    \Joomla\Registry\Registry
     * @since  0.1.0
     */
    public $state;

    /**
     * Form object for search filters.
     *
     * @var    \Joomla\CMS\Form\Form
     * @since  0.1.0
     */
    public $filterForm;

    /**
     * The active search filters.
     *
     * @var    array
     * @since  0.1.0
     */
    public $activeFilters;

    /**
     * Target-language columns for the grid, keyed by lang_code.
     *
     * @var    object[]
     * @since  0.1.0
     */
    public $targetLanguages = [];

    /**
     * Display title of the source language, shown as the source-article column heading.
     *
     * @var    string
     * @since  0.1.0
     */
    public $sourceLanguageTitle = '';

    /**
     * Content type aliases for the queue tab strip.
     *
     * @var    string[]
     * @since  0.4.0
     */
    public $contentTypes = [];

    /**
     * Display the view.
     *
     * @param   string|null  $tpl  The name of the template file to parse.
     *
     * @return  void
     *
     * @since   0.1.0
     */
    public function display($tpl = null): void
    {
        /** @var QueueModel $model */
        $model = $this->getModel();

        $this->items               = $model->getItems();
        $this->pagination          = $model->getPagination();
        $this->state               = $model->getState();
        $this->filterForm          = $model->getFilterForm();
        $this->activeFilters       = $model->getActiveFilters();
        $this->targetLanguages     = $model->getTargetLanguages();
        $this->sourceLanguageTitle = $model->getSourceLanguageTitle();
        $this->contentTypes        = $model->getContentTypes();

        $this->addToolbar();

        parent::display($tpl);
    }

    /**
     * Add the page title and toolbar.
     *
     * @return  void
     *
     * @since   0.1.0
     */
    protected function addToolbar(): void
    {
        $canDo = ContentHelper::getActions('com_translations');

        /** @var \Joomla\CMS\Document\HtmlDocument $doc */
        $doc     = $this->getDocument();
        $toolbar = $doc->getToolbar();

        ToolbarHelper::title(Text::_('COM_TRANSLATIONS_QUEUE_TITLE'), 'comments');

        if ($canDo->get('core.admin') || $canDo->get('core.options')) {
            ToolbarHelper::preferences('com_translations');
        }
    }
}
