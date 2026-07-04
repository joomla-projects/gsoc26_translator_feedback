<?php

/**
 * @package     Joomla.Administrator
 * @subpackage  com_translations
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Component\Translations\Administrator\Model;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use Joomla\CMS\Factory;
use Joomla\CMS\MVC\Model\AdminModel;

/**
 * Model for a single translation rule.
 *
 * @since  0.4.0
 */
class RuleModel extends AdminModel
{
    /**
     * The type alias for this content type.
     *
     * @var    string
     * @since  0.4.0
     */
    public $typeAlias = 'com_translations.rule';

    /**
     * The prefix to use with controller messages.
     *
     * @var    string
     * @since  0.4.0
     */
    protected $text_prefix = 'COM_TRANSLATIONS';

    /**
     * Get the edit form.
     *
     * @param   array    $data      Data for the form.
     * @param   boolean  $loadData  True to load the form's own data.
     *
     * @return  \Joomla\CMS\Form\Form  The edit form.
     *
     * @since   0.4.0
     */
    public function getForm($data = [], $loadData = true)
    {
        $form = $this->loadForm('com_translations.rule', 'rule', ['control' => 'jform', 'load_data' => $loadData]);

        // Lock the state field for users who cannot change the state.
        if (!$this->canEditState((object) $data)) {
            $form->setFieldAttribute('state', 'disabled', 'true');
            $form->setFieldAttribute('state', 'filter', 'unset');
        }

        return $form;
    }

    /**
     * Get the data that should be injected in the form.
     *
     * @return  array|object  The form data.
     *
     * @since   0.4.0
     */
    protected function loadFormData()
    {
        /** @var \Joomla\CMS\Application\CMSApplication $app */
        $app  = Factory::getApplication(); // No DI because models are not application aware.
        $data = $app->getUserState('com_translations.edit.rule.data', []);

        if (empty($data)) {
            $data = $this->getItem();
        }

        $this->preprocessData('com_translations.rule', $data);

        return $data;
    }

    /**
     * Prepare and sanitise the table data prior to saving.
     *
     * @param   \Joomla\CMS\Table\Table  $table  A reference to a Table object.
     *
     * @return  void
     *
     * @since   0.4.0
     */
    protected function prepareTable($table)
    {
        // Place new rules at the end of the ordering.
        if (empty($table->id) && (int) $table->ordering === 0) {
            $db    = $this->getDatabase();
            $query = $db->getQuery(true)
                ->select('MAX(' . $db->quoteName('ordering') . ')')
                ->from($db->quoteName('#__translations_rules'));

            $db->setQuery($query);

            $table->ordering = (int) $db->loadResult() + 1;
        }
    }
}
