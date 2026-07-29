<?php

/**
 * @package     Joomla.Administrator
 * @subpackage  com_translations
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Component\Translations\Administrator\View\Rule;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use Joomla\CMS\Factory;
use Joomla\CMS\Helper\ContentHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Toolbar\ToolbarHelper;
use Joomla\Component\Translations\Administrator\Model\RuleModel;

/**
 * View to edit a single translation rule.
 *
 * @since  0.4.0
 */
class HtmlView extends BaseHtmlView
{
    /**
     * The Form object.
     *
     * @var    \Joomla\CMS\Form\Form
     * @since  0.4.0
     */
    public $form;

    /**
     * The active item.
     *
     * @var    object
     * @since  0.4.0
     */
    public $item;

    /**
     * The model state.
     *
     * @var    \Joomla\Registry\Registry
     * @since  0.4.0
     */
    public $state;

    /**
     * Whether the view is rendered in the site, where there is no toolbar to carry the actions.
     *
     * @var    boolean
     * @since  0.7.0
     */
    public $isSite = false;

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
        /** @var RuleModel $model */
        $model = $this->getModel();

        $this->form  = $model->getForm();
        $this->item  = $model->getItem();
        $this->state = $model->getState();

        $this->isSite = Factory::getApplication()->isClient('site');

        // The editing form needs the validator so the Save button can submit, plus keepalive.
        $webAssetManager = $this->getDocument()->getWebAssetManager();
        $webAssetManager->useScript('keepalive')
            ->useScript('form.validate');

        // The site renders no toolbar, so the actions sit in the form as toolbar buttons of their own.
        if ($this->isSite) {
            $webAssetManager->useScript('webcomponent.toolbar-button');
        }

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
        Factory::getApplication()->getInput()->set('hidemainmenu', true);

        $canDo      = ContentHelper::getActions('com_translations');
        $user       = $this->getCurrentUser();
        $isNew      = ($this->item->id == 0);
        $checkedOut = $this->item->checked_out && $this->item->checked_out != $user->id;

        ToolbarHelper::title($isNew ? Text::_('COM_TRANSLATIONS_RULE_NEW') : Text::_('COM_TRANSLATIONS_RULE_EDIT'), 'book');

        if ($isNew) {
            if ($canDo->get('core.create')) {
                ToolbarHelper::apply('rule.apply');
                ToolbarHelper::saveGroup(
                    [
                        ['save', 'rule.save'],
                        ['save2new', 'rule.save2new'],
                    ],
                    'btn-success'
                );
            }

            ToolbarHelper::cancel('rule.cancel');
        } else {
            $toolbarButtons = [];

            if (!$checkedOut && $canDo->get('core.edit')) {
                ToolbarHelper::apply('rule.apply');

                $toolbarButtons[] = ['save', 'rule.save'];

                if ($canDo->get('core.create')) {
                    $toolbarButtons[] = ['save2new', 'rule.save2new'];
                    $toolbarButtons[] = ['save2copy', 'rule.save2copy'];
                }
            }

            ToolbarHelper::saveGroup($toolbarButtons, 'btn-success');

            ToolbarHelper::cancel('rule.cancel', 'JTOOLBAR_CLOSE');
        }
    }
}
