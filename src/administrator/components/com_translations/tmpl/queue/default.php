<?php

/**
 * @package     Joomla.Administrator
 * @subpackage  com_translations
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

defined('_JEXEC') or die;

/** @var \Joomla\Component\Translations\Administrator\View\Queue\HtmlView $this */

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Layout\LayoutHelper;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Session\Session;

$listDirn  = $this->escape($this->state->get('list.direction'));
$listOrder = $this->escape($this->state->get('list.ordering'));

$contentType = (string) $this->state->get('filter.contenttype');

// Icon, colour and title per translation state; the cell shows the icon alone, so the title carries
// the state as a tooltip and to a screen reader. No state is told apart by its colour alone.
// The two states the editor leads to name the action that reached them, which the short labels the
// status filter shares do not.
// Atum mutes bg-info to a translucent grey inside a table body, so it is not used here.
$stateIcons = [
    ''            => ['icon' => 'icon-circle', 'class' => 'bg-secondary', 'title' => 'COM_TRANSLATIONS_STATUS_NONE'],
    'pending'     => ['icon' => 'icon-clock', 'class' => 'bg-warning', 'title' => 'COM_TRANSLATIONS_STATUS_PENDING'],
    'translating' => ['icon' => 'icon-cogs', 'class' => 'bg-warning', 'title' => 'COM_TRANSLATIONS_STATUS_TRANSLATING'],
    'review'      => ['icon' => 'icon-pencil', 'class' => 'bg-primary', 'title' => 'COM_TRANSLATIONS_STATUS_REVIEW'],
    'approved'    => ['icon' => 'icon-check', 'class' => 'bg-primary', 'title' => 'COM_TRANSLATIONS_STATUS_APPROVED_DESC'],
    'published'   => ['icon' => 'icon-check-circle', 'class' => 'bg-success', 'title' => 'COM_TRANSLATIONS_STATUS_PUBLISHED_DESC'],
];
?>

<form action="<?php echo Route::_('index.php?option=com_translations&view=queue'); ?>" method="post" name="adminForm" id="adminForm">

    <ul class="nav nav-tabs mb-3">
        <?php foreach ($this->contentTypes as $tabContentType) : ?>
            <?php $isActive = $tabContentType === $contentType; ?>
            <li class="nav-item">
                <a class="nav-link<?php echo $isActive ? ' active' : ''; ?>"<?php echo $isActive ? ' aria-current="page"' : ''; ?>
                    href="<?php echo Route::_('index.php?option=com_translations&view=queue&filter_contenttype=' . urlencode($tabContentType)); ?>">
                    <?php echo $this->escape(Text::_('COM_TRANSLATIONS_CONTENTTYPE_' . strtoupper(str_replace('.', '_', $tabContentType)))); ?>
                </a>
            </li>
        <?php endforeach; ?>
    </ul>

    <div class="row">
        <div class="col-md-12">
            <?php echo LayoutHelper::render('joomla.searchtools.default', ['view' => $this]); ?>
        </div>
    </div>

    <?php if (empty($this->items)) : ?>
        <div class="alert alert-info">
            <span class="icon-info-circle" aria-hidden="true"></span><span class="visually-hidden"><?php echo Text::_('INFO'); ?></span>
            <?php echo Text::_('JGLOBAL_NO_MATCHING_RESULTS'); ?>
        </div>
    <?php else : ?>
        <table class="table table-striped" id="queueList">
            <caption class="visually-hidden">
                <?php echo Text::_('COM_TRANSLATIONS_QUEUE_TABLE_CAPTION'); ?>,
                <span id="orderedBy"><?php echo Text::_('JGLOBAL_SORTED_BY'); ?> </span>,
                <span id="filteredBy"><?php echo Text::_('JGLOBAL_FILTERED_BY'); ?></span>
            </caption>
            <thead>
                <tr>
                    <th scope="col" class="border-end">
                        <?php echo HTMLHelper::_('searchtools.sort', Text::sprintf('COM_TRANSLATIONS_HEADING_SOURCE', $this->escape($this->sourceLanguageTitle)), 'a.title', $listDirn, $listOrder); ?>
                    </th>
                    <?php foreach ($this->targetLanguages as $langCode => $language) : ?>
                        <?php // The code alone keeps the column as narrow as its cell, so more languages fit before the grid scrolls. ?>
                        <th scope="col" class="text-center" title="<?php echo $this->escape($language->title); ?>">
                            <?php echo $this->escape($langCode); ?>
                        </th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($this->items as $item) : ?>
                    <tr>
                        <th scope="row" class="border-end">
                            <?php echo $this->escape($item->title); ?>
                        </th>
                        <?php if (!empty($item->do_not_translate)) : ?>
                            <td class="text-center" colspan="<?php echo \count($this->targetLanguages); ?>">
                                <span class="badge bg-dark me-2"><?php echo Text::_('COM_TRANSLATIONS_STATUS_NO_NEED'); ?></span>
                                <a class="btn btn-sm btn-outline-secondary"
                                    href="<?php echo Route::_('index.php?option=com_translations&task=translation.allowTranslation&id=' . (int) $item->id . '&contentType=' . urlencode($contentType) . '&' . Session::getFormToken() . '=1'); ?>"
                                    title="<?php echo $this->escape(Text::_('COM_TRANSLATIONS_ALLOW_TRANSLATION_DESC')); ?>">
                                    <?php echo Text::_('COM_TRANSLATIONS_ALLOW_TRANSLATION'); ?>
                                </a>
                            </td>
                        <?php else : ?>
                        <?php foreach ($this->targetLanguages as $langCode => $language) : ?>
                            <?php $status = $item->states[$langCode] ?? ''; ?>
                            <?php // Only review/approved cells open the translation feedback view (shown as a link)?>
                            <?php $editable    = \in_array($status, ['review', 'approved'], true); ?>
                            <?php // An absent state means no translation yet, pending means the source has moved on since; the same trigger serves both. ?>
                            <?php $translatable = $status === '' || $status === 'pending'; ?>
                            <?php // The state column is free text, so a value the map does not hold falls back rather than warning. ?>
                            <?php $stateIcon   = $stateIcons[$status] ?? $stateIcons['']; ?>
                            <?php $stateTitle = Text::_($stateIcon['title']); ?>
                            <?php // The cell shows no text, so its title states the status and, where the cell acts, what clicking does. ?>
                            <?php $cellTitle = $stateTitle; ?>
                            <?php if ($editable) : ?>
                                <?php $cellTitle = Text::sprintf('COM_TRANSLATIONS_QUEUE_CELL_TITLE', $stateTitle, Text::_('COM_TRANSLATIONS_OPEN_EDITOR')); ?>
                            <?php elseif ($translatable) : ?>
                                <?php $cellTitle = Text::sprintf('COM_TRANSLATIONS_QUEUE_CELL_TITLE', $stateTitle, Text::_('COM_TRANSLATIONS_TRANSLATE_NOW')); ?>
                            <?php endif; ?>
                            <?php // Visibility is the item's own publish state, marked only where it differs from the one
                                  // state that is reached by publishing from here. ?>
                            <?php $isLive          = !empty($item->publishedTranslations[$langCode]); ?>
                            <?php $marksVisibility = $isLive !== ($status === 'published'); ?>
                            <td class="text-center text-nowrap">
                                <?php if ($editable) : ?>
                                    <?php // The edit task checks the draft out before opening the editor, so the link carries the form token. ?>
                                    <a class="badge <?php echo $stateIcon['class']; ?> text-decoration-none"
                                        href="<?php echo Route::_('index.php?option=com_translations&task=translatorfeedback.edit&id=' . (int) $item->id . '&target=' . urlencode($langCode) . '&contentType=' . urlencode($contentType) . '&' . Session::getFormToken() . '=1'); ?>"
                                        title="<?php echo $this->escape($cellTitle); ?>"><span class="<?php echo $stateIcon['icon']; ?> icon-fw" aria-hidden="true"></span><span class="visually-hidden"><?php echo $this->escape($cellTitle); ?></span></a>
                                <?php elseif ($translatable) : ?>
                                    <a class="badge <?php echo $stateIcon['class']; ?> text-decoration-none"
                                        href="<?php echo Route::_('index.php?option=com_translations&task=translation.translate&id=' . (int) $item->id . '&target=' . urlencode($langCode) . '&contentType=' . urlencode($contentType) . '&' . Session::getFormToken() . '=1'); ?>"
                                        title="<?php echo $this->escape($cellTitle); ?>"><span class="<?php echo $stateIcon['icon']; ?> icon-fw" aria-hidden="true"></span><span class="visually-hidden"><?php echo $this->escape($cellTitle); ?></span></a>
                                <?php else : ?>
                                    <span class="badge <?php echo $stateIcon['class']; ?>" title="<?php echo $this->escape($cellTitle); ?>"><span class="<?php echo $stateIcon['icon']; ?> icon-fw" aria-hidden="true"></span><span class="visually-hidden"><?php echo $this->escape($cellTitle); ?></span></span>
                                <?php endif; ?>
                                <?php if ($marksVisibility) : ?>
                                    <?php $visibilityLabel = Text::_($isLive ? 'COM_TRANSLATIONS_TRANSLATION_LIVE' : 'COM_TRANSLATIONS_TRANSLATION_NOT_LIVE'); ?>
                                    <span class="<?php echo $isLive ? 'icon-eye text-success' : 'icon-eye-slash text-warning'; ?> ms-1"
                                        aria-hidden="true" title="<?php echo $this->escape($visibilityLabel); ?>"></span><span class="visually-hidden"><?php echo $this->escape($visibilityLabel); ?></span>
                                <?php endif; ?>
                            </td>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <?php echo $this->pagination->getListFooter(); ?>
    <?php endif; ?>

    <input type="hidden" name="task" value="" />
    <?php echo HTMLHelper::_('form.token'); ?>
</form>
