<?php

/**
 * @package     Joomla.Administrator
 * @subpackage  com_translations
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

defined('_JEXEC') or die;

/** @var \Joomla\Component\Translations\Administrator\View\Rules\HtmlView $this */

use Joomla\CMS\Factory;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Layout\LayoutHelper;
use Joomla\CMS\Router\Route;

HTMLHelper::_('behavior.multiselect');

$user      = Factory::getApplication()->getIdentity();
$listOrder = $this->escape($this->state->get('list.ordering'));
$listDirn  = $this->escape($this->state->get('list.direction'));
$canChange = $user->authorise('core.edit.state', 'com_translations');
$canEdit   = $user->authorise('core.edit', 'com_translations');
$canCreate = $user->authorise('core.create', 'com_translations');
$canDelete = $user->authorise('core.delete', 'com_translations');
$inTrash   = (int) $this->state->get('filter.published') === -2;

// Manual ordering is not read when the rules are retrieved, so the site leaves the drag column out.
$showOrdering = !$this->isSite;
$saveOrder    = $showOrdering && $listOrder === 'a.ordering';

$saveOrderingUrl = '';

if ($saveOrder && !empty($this->items)) {
    $saveOrderingUrl = 'index.php?option=com_translations&task=rules.saveOrderAjax&tmpl=component';
    HTMLHelper::_('draggablelist.draggable');
}
?>

<form action="<?php echo Route::_('index.php?option=com_translations&view=rules'); ?>" method="post" name="adminForm" id="adminForm">
    <div class="row">
        <div class="col-md-12">
            <div id="j-main-container" class="j-main-container">
                <?php // The administrator carries these actions in its toolbar, which the site does not render. ?>
                <?php if ($this->isSite) : ?>
                    <div class="d-grid gap-2 d-sm-flex mb-3">
                        <?php if ($canCreate) : ?>
                            <joomla-toolbar-button task="rule.add" form="adminForm">
                                <button type="button" class="btn btn-primary">
                                    <span class="icon-plus" aria-hidden="true"></span>
                                    <?php echo Text::_('COM_TRANSLATIONS_RULE_NEW'); ?>
                                </button>
                            </joomla-toolbar-button>
                        <?php endif; ?>

                        <?php if ($canChange) : ?>
                            <joomla-toolbar-button task="rules.publish" form="adminForm" list-selection>
                                <button type="button" class="btn btn-secondary">
                                    <span class="icon-publish" aria-hidden="true"></span>
                                    <?php echo Text::_('COM_TRANSLATIONS_RULES_PUBLISH'); ?>
                                </button>
                            </joomla-toolbar-button>
                            <joomla-toolbar-button task="rules.unpublish" form="adminForm" list-selection>
                                <button type="button" class="btn btn-secondary">
                                    <span class="icon-unpublish" aria-hidden="true"></span>
                                    <?php echo Text::_('COM_TRANSLATIONS_RULES_UNPUBLISH'); ?>
                                </button>
                            </joomla-toolbar-button>

                            <?php if (!$inTrash) : ?>
                                <joomla-toolbar-button task="rules.trash" form="adminForm" list-selection>
                                    <button type="button" class="btn btn-secondary">
                                        <span class="icon-trash" aria-hidden="true"></span>
                                        <?php echo Text::_('COM_TRANSLATIONS_RULES_TRASH'); ?>
                                    </button>
                                </joomla-toolbar-button>
                            <?php endif; ?>
                        <?php endif; ?>

                        <?php if ($inTrash && $canDelete) : ?>
                            <?php // The confirmation dialog reads these from the JavaScript language store. ?>
                            <?php Text::script('WARNING'); ?>
                            <?php Text::script('JYES'); ?>
                            <?php Text::script('JNO'); ?>
                            <joomla-toolbar-button task="rules.delete" form="adminForm" list-selection
                                confirm-message="<?php echo $this->escape(Text::_('JGLOBAL_CONFIRM_DELETE')); ?>">
                                <button type="button" class="btn btn-danger">
                                    <span class="icon-delete" aria-hidden="true"></span>
                                    <?php echo Text::_('COM_TRANSLATIONS_RULES_EMPTY_TRASH'); ?>
                                </button>
                            </joomla-toolbar-button>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <?php echo LayoutHelper::render('joomla.searchtools.default', ['view' => $this]); ?>

                <?php if (empty($this->items)) : ?>
                    <div class="alert alert-info">
                        <span class="icon-info-circle" aria-hidden="true"></span><span class="visually-hidden"><?php echo Text::_('INFO'); ?></span>
                        <?php echo Text::_('JGLOBAL_NO_MATCHING_RESULTS'); ?>
                    </div>
                <?php else : ?>
                    <table class="table" id="ruleList">
                        <caption class="visually-hidden">
                            <?php echo Text::_('COM_TRANSLATIONS_RULES_TABLE_CAPTION'); ?>,
                            <span id="orderedBy"><?php echo Text::_('JGLOBAL_SORTED_BY'); ?> </span>,
                            <span id="filteredBy"><?php echo Text::_('JGLOBAL_FILTERED_BY'); ?></span>
                        </caption>
                        <thead>
                            <tr>
                                <td class="w-1 text-center">
                                    <?php echo HTMLHelper::_('grid.checkall'); ?>
                                </td>
                                <?php if ($showOrdering) : ?>
                                    <th scope="col" class="w-1 text-center d-none d-md-table-cell">
                                        <?php echo HTMLHelper::_('searchtools.sort', '', 'a.ordering', $listDirn, $listOrder, null, 'asc', 'JGRID_HEADING_ORDERING', 'icon-sort'); ?>
                                    </th>
                                <?php endif; ?>
                                <th scope="col" class="w-1 text-center">
                                    <?php echo HTMLHelper::_('searchtools.sort', 'JSTATUS', 'a.state', $listDirn, $listOrder); ?>
                                </th>
                                <th scope="col">
                                    <?php echo HTMLHelper::_('searchtools.sort', 'COM_TRANSLATIONS_RULES_HEADING_NAME', 'a.rule_name', $listDirn, $listOrder); ?>
                                </th>
                                <th scope="col" class="w-10 d-none d-md-table-cell">
                                    <?php echo HTMLHelper::_('searchtools.sort', 'COM_TRANSLATIONS_RULES_HEADING_TYPE', 'a.rule_type', $listDirn, $listOrder); ?>
                                </th>
                                <th scope="col" class="w-10 d-none d-md-table-cell">
                                    <?php echo HTMLHelper::_('searchtools.sort', 'JGRID_HEADING_LANGUAGE', 'language_title', $listDirn, $listOrder); ?>
                                </th>
                                <th scope="col" class="w-5 text-center d-none d-md-table-cell">
                                    <?php echo HTMLHelper::_('searchtools.sort', 'COM_TRANSLATIONS_RULES_HEADING_CONFIDENCE', 'a.confidence', $listDirn, $listOrder); ?>
                                </th>
                                <th scope="col" class="w-5 d-none d-md-table-cell">
                                    <?php echo HTMLHelper::_('searchtools.sort', 'JGRID_HEADING_ID', 'a.id', $listDirn, $listOrder); ?>
                                </th>
                            </tr>
                        </thead>
                        <tbody <?php if ($saveOrder) : ?> class="js-draggable" data-url="<?php echo $saveOrderingUrl; ?>" data-direction="<?php echo strtolower($listDirn); ?>"<?php endif; ?>>
                            <?php foreach ($this->items as $i => $item) : ?>
                                <?php $canCheckin = $user->authorise('core.manage', 'com_checkin') || $item->checked_out == $user->id || !$item->checked_out; ?>
                                <?php $canReorder = $canChange && $canCheckin; ?>
                                <tr class="row<?php echo $i % 2; ?>">
                                    <td class="text-center">
                                        <?php echo HTMLHelper::_('grid.id', $i, $item->id, false, 'cid', 'cb', $item->rule_name); ?>
                                    </td>
                                    <?php if ($showOrdering) : ?>
                                        <td class="text-center d-none d-md-table-cell">
                                            <?php
                                            $iconClass = '';
                                            if (!$canReorder) {
                                                $iconClass = ' inactive';
                                            } elseif (!$saveOrder) {
                                                $iconClass = ' inactive" title="' . Text::_('JORDERINGDISABLED');
                                            }
                                            ?>
                                            <span class="sortable-handler<?php echo $iconClass; ?>">
                                                <span class="icon-ellipsis-v" aria-hidden="true"></span>
                                            </span>
                                            <?php if ($canReorder && $saveOrder) : ?>
                                                <input type="text" name="order[]" size="5" value="<?php echo (int) $item->ordering; ?>" class="width-20 text-area-order hidden">
                                            <?php endif; ?>
                                        </td>
                                    <?php endif; ?>
                                    <td class="text-center">
                                        <?php echo HTMLHelper::_('jgrid.published', $item->state, $i, 'rules.', $canChange && $canCheckin, 'cb'); ?>
                                    </td>
                                    <th scope="row" class="has-context">
                                        <div>
                                            <?php if ($item->checked_out) : ?>
                                                <?php echo HTMLHelper::_('jgrid.checkedout', $i, $item->editor, $item->checked_out_time, 'rules.', $canCheckin); ?>
                                            <?php endif; ?>
                                            <?php if ($canEdit) : ?>
                                                <a href="<?php echo Route::_('index.php?option=com_translations&task=rule.edit&id=' . (int) $item->id); ?>" title="<?php echo Text::_('JACTION_EDIT'); ?> <?php echo $this->escape($item->rule_name); ?>">
                                                    <?php echo $this->escape($item->rule_name); ?></a>
                                            <?php else : ?>
                                                <?php echo $this->escape($item->rule_name); ?>
                                            <?php endif; ?>
                                            <?php if ($item->source_term !== null && $item->source_term !== '') : ?>
                                                <div class="small">
                                                    <?php echo $this->escape($item->source_term); ?>
                                                    <?php if ((string) $item->target_term !== '') : ?>
                                                        <span aria-hidden="true">&#8594;</span>
                                                        <?php echo $this->escape((string) $item->target_term); ?>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </th>
                                    <td class="d-none d-md-table-cell">
                                        <span class="badge bg-secondary">
                                            <?php echo Text::_('COM_TRANSLATIONS_RULE_TYPE_' . strtoupper($item->rule_type)); ?>
                                        </span>
                                    </td>
                                    <td class="small d-none d-md-table-cell">
                                        <?php echo $this->escape($item->language_title ?: $item->target_language); ?>
                                    </td>
                                    <td class="text-center d-none d-md-table-cell">
                                        <?php echo number_format((float) $item->confidence, 2); ?>
                                    </td>
                                    <td class="d-none d-md-table-cell">
                                        <?php echo (int) $item->id; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>

                    <?php echo $this->pagination->getListFooter(); ?>
                <?php endif; ?>

                <input type="hidden" name="task" value="">
                <input type="hidden" name="boxchecked" value="0">
                <?php echo HTMLHelper::_('form.token'); ?>
            </div>
        </div>
    </div>
</form>

<?php // Carries the export, so a download does not leave the list form pointed at it. ?>
<?php if (!$this->isSite) : ?>
    <form action="<?php echo Route::_('index.php?option=com_translations&view=rules'); ?>" method="post" name="exportForm" id="exportForm">
        <input type="hidden" name="task" value="">
        <input type="hidden" name="cids" value="">
        <?php echo HTMLHelper::_('form.token'); ?>
    </form>
<?php endif; ?>

<?php // Shown by the toolbar's Import button, in a dialog over the list rather than a page of its own. ?>
<?php if (!$this->isSite && $canCreate) : ?>
    <template id="joomla-dialog-import">
        <form
            class="p-3"
            enctype="multipart/form-data"
            action="<?php echo Route::_('index.php?option=com_translations&view=rules'); ?>"
            method="post"
            name="importForm"
            id="importForm"
        >
            <div class="mb-3">
                <label for="rules_file" class="form-label">
                    <?php echo Text::_('COM_TRANSLATIONS_RULES_IMPORT_FILE'); ?>
                </label>
                <input class="form-control" type="file" id="rules_file" name="rules_file" accept=".json">
            </div>
            <div class="mb-3">
                <label for="import_state" class="form-label">
                    <?php echo Text::_('COM_TRANSLATIONS_RULES_IMPORT_STATE'); ?>
                </label>
                <select class="form-select" id="import_state" name="state">
                    <option value="0"><?php echo Text::_('JUNPUBLISHED'); ?></option>
                    <option value="1"><?php echo Text::_('JPUBLISHED'); ?></option>
                </select>
                <small class="form-text"><?php echo Text::_('COM_TRANSLATIONS_RULES_IMPORT_STATE_DESC'); ?></small>
            </div>
            <button type="submit" class="btn btn-primary">
                <span class="icon-upload" aria-hidden="true"></span>
                <?php echo Text::_('COM_TRANSLATIONS_RULES_IMPORT_SUBMIT'); ?>
            </button>
            <input type="hidden" name="task" value="ruleset.import">
            <?php echo HTMLHelper::_('form.token'); ?>
        </form>
    </template>
<?php endif; ?>
