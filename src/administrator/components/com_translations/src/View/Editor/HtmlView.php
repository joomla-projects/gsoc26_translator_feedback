<?php

/**
 * @package     Joomla.Administrator
 * @subpackage  com_translations
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Component\Translations\Administrator\View\Editor;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Toolbar\ToolbarHelper;

/**
 * Side-by-side translation editor view.
 *
 * Displays a source article (left) and its translation in one target language (right),
 * with the translation as the editable surface.
 *
 * @since  0.2.0
 */
class HtmlView extends BaseHtmlView
{
    /**
     * The editor form (translation fields).
     *
     * @var    \Joomla\CMS\Form\Form
     * @since  0.2.0
     */
    public $form;

    /**
     * The source + translation pair.
     *
     * @var    object
     * @since  0.2.0
     */
    public $item;

    /**
     * Render the view.
     *
     * @param   string  $tpl  The template name.
     *
     * @return  void
     *
     * @since   0.2.0
     */
    public function display($tpl = null)
    {
        /** @var \Joomla\Component\Translations\Administrator\Model\EditorModel $model */
        $model      = $this->getModel();
        $this->item = $model->getItem();
        $this->form = $model->getForm();

        $this->addToolbar();

        parent::display($tpl);
    }

    /**
     * Set the page title and toolbar.
     *
     * @return  void
     *
     * @since   0.2.0
     */
    protected function addToolbar()
    {
        ToolbarHelper::title(Text::_('COM_TRANSLATIONS_EDITOR_TITLE'), 'comments');
    }
}
