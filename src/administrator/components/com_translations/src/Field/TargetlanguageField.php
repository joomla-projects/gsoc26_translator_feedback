<?php

/**
 * @package     Joomla.Administrator
 * @subpackage  com_translations
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Component\Translations\Administrator\Field;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Form\Field\ContentlanguageField;

/**
 * Content language field listing only the translation target languages, ie every
 * installed content language except the configured source language and "All" ('*').
 *
 * @since  0.1.0
 */
class TargetlanguageField extends ContentlanguageField
{
    /**
     * The form field type.
     *
     * @var    string
     * @since  0.1.0
     */
    public $type = 'TargetLanguage';

    /**
     * Drop the configured source language and '*' from the content-language options.
     *
     * @return  object[]  The options the field is going to show.
     *
     * @since   0.1.0
     */
    protected function getOptions()
    {
        $sourceLanguage = (string) ComponentHelper::getParams('com_translations')->get('source_language', 'en-GB');
        $excluded       = ['', '*', $sourceLanguage];

        return array_values(
            array_filter(
                parent::getOptions(),
                static fn($option) => !\in_array($option->value, $excluded, true)
            )
        );
    }
}
