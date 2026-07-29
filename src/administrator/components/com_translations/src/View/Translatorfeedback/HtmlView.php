<?php

/**
 * @package     Joomla.Administrator
 * @subpackage  com_translations
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Component\Translations\Administrator\View\Translatorfeedback;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use Joomla\CMS\Document\HtmlDocument;
use Joomla\CMS\Factory;
use Joomla\CMS\Helper\ContentHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Toolbar\ToolbarHelper;

/**
 * Side-by-side translation feedback view.
 *
 * Displays a source item (left) and its translation in one target language (right),
 * with the translation as the editable surface.
 *
 * @since  0.2.0
 */
class HtmlView extends BaseHtmlView
{
    /**
     * The translation feedback form (translation fields).
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
     * Whether the view is rendered in the site, where there is no toolbar to carry the actions.
     *
     * @var    boolean
     * @since  0.7.0
     */
    public $isSite = false;

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
        /** @var \Joomla\Component\Translations\Administrator\Model\TranslatorfeedbackModel $model */
        $model      = $this->getModel();
        $this->item = $model->getItem();
        $this->form = $model->getForm();

        $this->isSite = Factory::getApplication()->isClient('site');

        // The editing form needs the validator so the Save button can submit, plus keepalive.
        // The stylesheet caps the read-only original pane so a long source scrolls beside the editor.
        $webAssetManager = $this->getDocument()->getWebAssetManager();
        $webAssetManager->useScript('keepalive')
            ->useScript('form.validate')
            ->registerAndUseStyle('com_translations.translatorfeedback', 'com_translations/translatorfeedback.css');

        // The site renders no toolbar, so the actions sit in the form as toolbar buttons of their own.
        if ($this->isSite) {
            $webAssetManager->useScript('webcomponent.toolbar-button');
        }

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
        Factory::getApplication()->getInput()->set('hidemainmenu', true);

        ToolbarHelper::title(Text::_('COM_TRANSLATIONS_TRANSLATOR_FEEDBACK_TITLE'), 'comments');

        // Nothing to save until there is a translation to edit.
        if ($this->item->translation_item === null) {
            return;
        }

        // The toolbar is provided by the HTML document (admin views always render in one).
        $document = $this->getDocument();

        if (!$document instanceof HtmlDocument) {
            return;
        }

        $toolbar = $document->getToolbar();

        if ($toolbar === null) {
            return;
        }

        $toolbar->apply('translatorfeedback.save');
        $toolbar->save('translatorfeedback.save2close');

        // Approval is the translator's signal that the translation is finished, so it is offered
        // to anyone who may edit; publishing it is a separate permission.
        $toolbar->standardButton('approve', 'COM_TRANSLATIONS_TRANSLATOR_FEEDBACK_APPROVE', 'translatorfeedback.approve')
            ->icon('icon-check');

        if (ContentHelper::getActions('com_translations')->get('core.edit.state')) {
            $toolbar->standardButton('publish', 'COM_TRANSLATIONS_TRANSLATOR_FEEDBACK_APPROVE_PUBLISH', 'translatorfeedback.approveAndPublish')
                ->icon('icon-publish');
        }

        $toolbar->cancel('translatorfeedback.cancel', 'COM_TRANSLATIONS_TRANSLATOR_FEEDBACK_CLOSE');
    }
}
