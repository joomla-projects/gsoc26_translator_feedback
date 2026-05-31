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

$listDirn  = $this->escape($this->state->get('list.direction'));
$listOrder = $this->escape($this->state->get('list.ordering'));
?>

<form action="<?php echo Route::_('index.php?option=com_translations&view=queue'); ?>" method="post" name="adminForm" id="adminForm">

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
                        <?php foreach ($this->targetLanguages as $langCode => $language) : ?>
                            <?php $status = $item->states[$langCode] ?? ''; ?>
                            <td class="text-center">
                                <?php if ($status !== '') : ?>
                                    <span class="badge bg-info"><?php echo Text::_('COM_TRANSLATIONS_STATUS_' . strtoupper($status)); ?></span>
                                <?php else : ?>
                                    <span class="badge bg-secondary"><?php echo Text::_('COM_TRANSLATIONS_STATUS_NONE'); ?></span>
                                <?php endif; ?>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <?php echo $this->pagination->getListFooter(); ?>
    <?php endif; ?>

    <input type="hidden" name="task" value="" />
    <?php echo HTMLHelper::_('form.token'); ?>
</form>
