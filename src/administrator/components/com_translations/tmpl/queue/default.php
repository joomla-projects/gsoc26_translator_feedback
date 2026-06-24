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

// The feedback editor handles articles only for now; other types show their status without a link.
$editorSupported = $contentType === 'com_content.article';
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
                    <th scope="col">
                        <?php echo HTMLHelper::_('searchtools.sort', Text::sprintf('COM_TRANSLATIONS_HEADING_SOURCE', $this->escape($this->sourceLanguageTitle)), 'a.title', $listDirn, $listOrder); ?>
                    </th>
                    <?php foreach ($this->targetLanguages as $language) : ?>
                        <th scope="col" class="text-center">
                            <?php echo $this->escape($language->title); ?>
                        </th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($this->items as $item) : ?>
                    <tr>
                        <th scope="row">
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
                            <?php $editable    = $editorSupported && \in_array($status, ['review', 'approved'], true); ?>
                            <?php $statusLabel = $status !== '' ? Text::_('COM_TRANSLATIONS_STATUS_' . strtoupper($status)) : Text::_('COM_TRANSLATIONS_STATUS_NONE'); ?>
                            <td class="text-center">
                                <?php if ($editable) : ?>
                                    <a href="<?php echo Route::_('index.php?option=com_translations&view=translatorfeedback&layout=edit&id=' . (int) $item->id . '&target=' . urlencode($langCode)); ?>">
                                        <?php echo $this->escape($statusLabel); ?>
                                    </a>
                                <?php elseif ($status !== '') : ?>
                                    <span class="badge bg-info"><?php echo $this->escape($statusLabel); ?></span>
                                <?php else : ?>
                                    <?php // An absent state means ready for translation, so the badge triggers it. ?>
                                    <a class="badge bg-secondary text-decoration-none"
                                        href="<?php echo Route::_('index.php?option=com_translations&task=translation.translate&id=' . (int) $item->id . '&target=' . urlencode($langCode) . '&contentType=' . urlencode($contentType) . '&' . Session::getFormToken() . '=1'); ?>"
                                        title="<?php echo $this->escape(Text::_('COM_TRANSLATIONS_TRANSLATE_NOW')); ?>">
                                        <?php echo $this->escape($statusLabel); ?>
                                    </a>
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
