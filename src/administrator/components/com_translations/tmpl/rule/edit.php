<?php

/**
 * @package     Joomla.Administrator
 * @subpackage  com_translations
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;

/** @var \Joomla\Component\Translations\Administrator\View\Rule\HtmlView $this */

$isNew = ((int) $this->item->id === 0);
?>

<form action="<?php echo Route::_('index.php?option=com_translations&layout=edit&id=' . (int) $this->item->id); ?>"
    method="post" name="adminForm" id="rule-form"
    aria-label="<?php echo Text::_('COM_TRANSLATIONS_RULE_' . ($isNew ? 'NEW' : 'EDIT'), true); ?>" class="form-validate">

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
