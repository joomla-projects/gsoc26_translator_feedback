<?php

/**
 * @package     Joomla.Administrator
 * @subpackage  com_translations
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;

/** @var \Joomla\Component\Translations\Administrator\View\Rule\HtmlView $this */

$isNew = ((int) $this->item->id === 0);

$user    = Factory::getApplication()->getIdentity();
$canSave = $user->authorise($isNew ? 'core.create' : 'core.edit', 'com_translations');
?>

<form action="<?php echo Route::_('index.php?option=com_translations&layout=edit&id=' . (int) $this->item->id); ?>"
    method="post" name="adminForm" id="rule-form"
    aria-label="<?php echo Text::_('COM_TRANSLATIONS_RULE_' . ($isNew ? 'NEW' : 'EDIT'), true); ?>" class="form-validate">

    <?php // The administrator carries these actions in its toolbar, which the site does not render. ?>
    <?php if ($this->isSite) : ?>
        <div class="d-grid gap-2 d-sm-flex mb-3">
            <?php if ($canSave) : ?>
                <joomla-toolbar-button task="rule.apply" form="rule-form" form-validation>
                    <button type="button" class="btn btn-primary">
                        <span class="icon-check" aria-hidden="true"></span>
                        <?php echo Text::_('COM_TRANSLATIONS_RULE_SAVE'); ?>
                    </button>
                </joomla-toolbar-button>
                <joomla-toolbar-button task="rule.save" form="rule-form" form-validation>
                    <button type="button" class="btn btn-primary">
                        <span class="icon-check" aria-hidden="true"></span>
                        <?php echo Text::_('COM_TRANSLATIONS_RULE_SAVE_CLOSE'); ?>
                    </button>
                </joomla-toolbar-button>
            <?php endif; ?>
            <joomla-toolbar-button task="rule.cancel" form="rule-form">
                <button type="button" class="btn btn-danger">
                    <span class="icon-times" aria-hidden="true"></span>
                    <?php echo Text::_('COM_TRANSLATIONS_RULE_CANCEL'); ?>
                </button>
            </joomla-toolbar-button>
        </div>
    <?php endif; ?>

    <div class="row">
        <div class="col-lg-9">
            <div class="form-vertical">
                <?php echo $this->form->renderFieldset('details'); ?>
            </div>
        </div>
        <div class="col-lg-3">
            <div class="form-vertical">
                <?php echo $this->form->renderFieldset('publishing'); ?>
            </div>
        </div>
    </div>

    <input type="hidden" name="task" value="">
    <?php echo $this->form->renderControlFields(); ?>
</form>
