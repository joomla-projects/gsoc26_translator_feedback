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

$form           = $this->form;
$sourceItem     = $this->item->source_item;
$sourceValues   = $this->item->source_values;
$hasTranslation = $this->item->translation_item !== null;

$contentId      = (int) $this->item->content_id;
$contentType    = (string) $this->item->content_type;
$targetLanguage = (string) $this->item->target_language;
$sourceLanguage = (string) $this->item->source_language;

// Render a read-only original body, falling back to a muted placeholder when the source field is empty.
$originalBody = function ($html) {
    if (trim((string) $html) === '') {
        return '<span class="text-muted fst-italic">' . Text::_('COM_TRANSLATIONS_TRANSLATOR_FEEDBACK_FIELD_EMPTY') . '</span>';
    }

    return $html;
};

// Render a read-only original plaintext value, falling back to a muted placeholder when empty.
$originalText = function ($value) {
    if (trim((string) $value) === '') {
        return '<span class="text-muted fst-italic">' . Text::_('COM_TRANSLATIONS_TRANSLATOR_FEEDBACK_FIELD_EMPTY') . '</span>';
    }

    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};

// Render one paired field row: the read-only source value beside its editable translation.
// An editor field shows its body as a card; a textarea gets a taller source box to match its input.
$fieldRow = function ($field, $sourceValue, $input, $fill = false) use ($originalBody, $originalText, $hasTranslation) {
    $type  = strtolower((string) $field->getAttribute('type'));
    $label = Text::_((string) $field->getAttribute('label'));

    if ($type === 'editor') {
        ?>
        <div class="mb-4">
            <div class="row">
                <div class="col-lg-6">
                    <?php // Original shown as a card so Atum paints its surface and header label in both light and dark; the header doubles as the field label and aligns the body with the editor. ?>
                    <div class="card border translations-readonly<?php echo $fill ? ' h-100 d-flex flex-column' : ''; ?>">
                        <div class="card-header fw-bold">
                            <span class="icon-lock opacity-75 me-2" aria-hidden="true"></span><?php echo $label; ?>
                        </div>
                        <?php // Body is trusted, author-supplied HTML (rendered as the managing component does). ?>
                        <div class="card-body bg-body-tertiary <?php echo $fill ? 'translations-readonly-fill' : 'translations-readonly-body'; ?><?php echo trim((string) $sourceValue) === '' ? ' translations-readonly-empty' : ''; ?>"><?php echo $originalBody($sourceValue); ?></div>
                    </div>
                </div>
                <div class="col-lg-6">
                    <?php if ($hasTranslation) : ?>
                        <?php echo $input; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php

        return;
    }

    $sourceClass = $type === 'textarea' ? 'translations-readonly-multiline' : '';
    ?>
    <div class="mb-4">
        <label class="fw-bold d-block mb-2"><?php echo $label; ?></label>
        <div class="row">
            <div class="col-lg-6">
                <div class="form-control-plaintext border rounded bg-body-tertiary px-3 py-2<?php echo $sourceClass !== '' ? ' ' . $sourceClass : ''; ?>"><?php echo $originalText($sourceValue); ?></div>
            </div>
            <div class="col-lg-6">
                <?php if ($hasTranslation) : ?>
                    <?php echo $input; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php
};

$action = 'index.php?option=com_translations&view=translatorfeedback&layout=edit&id=' . $contentId
    . '&target=' . urlencode($targetLanguage) . '&contentType=' . urlencode($contentType);
?>

<form action="<?php echo Route::_($action); ?>" method="post" name="adminForm" id="adminForm" class="form-validate">

    <div class="mb-3">
        <a class="btn btn-secondary" href="<?php echo Route::_('index.php?option=com_translations&view=queue'); ?>">
            <span class="icon-arrow-left" aria-hidden="true"></span>
            <?php echo Text::_('COM_TRANSLATIONS_TRANSLATOR_FEEDBACK_BACK_TO_QUEUE'); ?>
        </a>
    </div>

    <?php if ($sourceItem === null) : ?>
        <div class="alert alert-warning">
            <span class="icon-warning" aria-hidden="true"></span>
            <?php echo Text::_('COM_TRANSLATIONS_TRANSLATOR_FEEDBACK_NO_SOURCE'); ?>
        </div>
    <?php else : ?>
        <?php // Column headers: left = original (read-only), right = translation (editable). ?>
        <div class="row mb-3">
            <div class="col-lg-6">
                <span class="fw-bold"><?php echo Text::sprintf('COM_TRANSLATIONS_TRANSLATOR_FEEDBACK_SOURCE_HEADING', $this->escape($sourceLanguage)); ?></span>
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

        <?php // Each content type's form lists the fields and the sections (fieldsets) to show. ?>
        <?php foreach ($form->getFieldsets() as $fieldset) : ?>
            <h2 class="translations-section-heading"><?php echo Text::_($fieldset->label); ?></h2>
            <?php foreach ($form->getFieldset($fieldset->name) as $field) : ?>
                <?php
                $key         = substr((string) $field->getAttribute('name'), \strlen('translation_'));
                $sourceValue = $sourceValues[$key] ?? '';
                ?>
                <?php $fieldRow($field, $sourceValue, $form->getInput($field->getAttribute('name'))); ?>
            <?php endforeach; ?>
        <?php endforeach; ?>

        <?php // Custom fields are injected into the com_fields group by the model; show the translatable ones beside the source. ?>
        <?php $customFields = $form->getGroup('com_fields'); ?>
        <?php if ($customFields !== []) : ?>
            <h2 class="translations-section-heading"><?php echo Text::_('COM_TRANSLATIONS_TRANSLATOR_FEEDBACK_CUSTOM_FIELDS'); ?></h2>
            <?php foreach ($customFields as $field) : ?>
                <?php
                $name        = (string) $field->getAttribute('name');
                $sourceValue = $this->item->source_custom_fields[$name]['value'] ?? '';
                ?>
                <?php $fieldRow($field, $sourceValue, $form->getInput($name, 'com_fields'), true); ?>
            <?php endforeach; ?>
        <?php endif; ?>
    <?php endif; ?>

    <input type="hidden" name="task" value="">
    <input type="hidden" name="id" value="<?php echo $contentId; ?>">
    <input type="hidden" name="target" value="<?php echo $this->escape($targetLanguage); ?>">
    <input type="hidden" name="contentType" value="<?php echo $this->escape($contentType); ?>">
    <?php echo HTMLHelper::_('form.token'); ?>
</form>
