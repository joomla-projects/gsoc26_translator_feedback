<?php

/**
 * @package     Joomla.Administrator
 * @subpackage  com_translations
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

defined('_JEXEC') or die;

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;

/** @var \Joomla\Component\Translations\Administrator\View\Translatorfeedback\HtmlView $this */

$sourceArticle      = $this->item->source_article;
$translationArticle = $this->item->translation_article;
$hasTranslation     = $translationArticle !== null;
$contentId          = (int) $this->item->content_id;
$targetLanguage     = (string) $this->item->target_language;

// Render a read-only original body, falling back to a muted placeholder when the source field is empty.
$originalBody = function ($html) {
    if (trim((string) $html) === '') {
        return '<span class="text-muted fst-italic">' . Text::_('COM_TRANSLATIONS_TRANSLATOR_FEEDBACK_FIELD_EMPTY') . '</span>';
    }

    return $html;
};

$action = 'index.php?option=com_translations&view=translatorfeedback&layout=edit&id=' . $contentId . '&target=' . urlencode($targetLanguage);
?>

<form action="<?php echo Route::_($action); ?>" method="post" name="adminForm" id="adminForm" class="form-validate">

    <div class="mb-3">
        <a class="btn btn-secondary" href="<?php echo Route::_('index.php?option=com_translations&view=queue'); ?>">
            <span class="icon-arrow-left" aria-hidden="true"></span>
            <?php echo Text::_('COM_TRANSLATIONS_TRANSLATOR_FEEDBACK_BACK_TO_QUEUE'); ?>
        </a>
    </div>

    <?php if ($sourceArticle === null) : ?>
        <div class="alert alert-warning">
            <span class="icon-warning" aria-hidden="true"></span>
            <?php echo Text::_('COM_TRANSLATIONS_TRANSLATOR_FEEDBACK_NO_SOURCE'); ?>
        </div>
    <?php else : ?>
        <?php // Column headers: left = original (read-only), right = translation (editable). ?>
        <div class="row mb-3">
            <div class="col-lg-6">
                <span class="fw-bold"><?php echo Text::sprintf('COM_TRANSLATIONS_TRANSLATOR_FEEDBACK_SOURCE_HEADING', $this->escape($sourceArticle->language)); ?></span>
                <span class="badge bg-secondary">
                    <span class="icon-lock" aria-hidden="true"></span>
                    <?php echo Text::_('COM_TRANSLATIONS_TRANSLATOR_FEEDBACK_READ_ONLY'); ?>
                </span>
            </div>
            <div class="col-lg-6">
                <span class="fw-bold"><?php echo Text::sprintf('COM_TRANSLATIONS_TRANSLATOR_FEEDBACK_TRANSLATION_HEADING', $this->escape($targetLanguage)); ?></span>
            </div>
        </div>

        <?php if (!$hasTranslation) : ?>
            <div class="alert alert-warning">
                <span class="icon-warning" aria-hidden="true"></span>
                <?php echo Text::_('COM_TRANSLATIONS_TRANSLATOR_FEEDBACK_NO_TRANSLATION'); ?>
            </div>
        <?php endif; ?>

        <?php // Each field pairs the original (left, read-only) beside its translation (right, editable). ?>
        <div class="mb-4">
            <label class="fw-bold d-block mb-2"><?php echo Text::_('COM_TRANSLATIONS_TRANSLATOR_FEEDBACK_FIELD_TITLE'); ?></label>
            <div class="row">
                <div class="col-lg-6">
                    <div class="form-control-plaintext border rounded bg-body-tertiary px-3 py-2"><?php echo $this->escape($sourceArticle->title); ?></div>
                </div>
                <div class="col-lg-6">
                    <?php if ($hasTranslation) : ?>
                        <?php echo $this->form->getInput('translation_title'); ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="mb-4">
            <label class="fw-bold d-block mb-2"><?php echo Text::_('COM_TRANSLATIONS_TRANSLATOR_FEEDBACK_FIELD_INTROTEXT'); ?></label>
            <div class="row">
                <div class="col-lg-6">
                    <?php // Article body is trusted, author-supplied HTML (rendered as com_content does). ?>
                    <div class="border rounded bg-body-tertiary px-3 py-2"><?php echo $originalBody($sourceArticle->introtext); ?></div>
                </div>
                <div class="col-lg-6">
                    <?php if ($hasTranslation) : ?>
                        <?php echo $this->form->getInput('translation_introtext'); ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="mb-4">
            <label class="fw-bold d-block mb-2"><?php echo Text::_('COM_TRANSLATIONS_TRANSLATOR_FEEDBACK_FIELD_FULLTEXT'); ?></label>
            <div class="row">
                <div class="col-lg-6">
                    <div class="border rounded bg-body-tertiary px-3 py-2"><?php echo $originalBody($sourceArticle->fulltext); ?></div>
                </div>
                <div class="col-lg-6">
                    <?php if ($hasTranslation) : ?>
                        <?php echo $this->form->getInput('translation_fulltext'); ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <input type="hidden" name="task" value="">
    <input type="hidden" name="id" value="<?php echo $contentId; ?>">
    <input type="hidden" name="target" value="<?php echo $this->escape($targetLanguage); ?>">
    <?php echo HTMLHelper::_('form.token'); ?>
</form>
