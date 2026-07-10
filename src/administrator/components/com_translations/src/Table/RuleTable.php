<?php

/**
 * @package     Joomla.Administrator
 * @subpackage  com_translations
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Component\Translations\Administrator\Table;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Table\Table;
use Joomla\Database\DatabaseInterface;
use Joomla\Event\DispatcherInterface;

/**
 * Translation rule table.
 *
 * @since  0.4.0
 */
class RuleTable extends Table
{
    /**
     * Indicates that columns fully support the NULL value in the database.
     *
     * @var    boolean
     * @since  0.4.0
     */
    protected $_supportNullValue = true;

    /**
     * Columns stored as JSON.
     *
     * @var    array
     * @since  0.4.0
     */
    protected $_jsonEncode = ['params', 'source_feedback_ids'];

    /**
     * The allowed rule type discriminators.
     *
     * @var    string[]
     * @since  0.4.0
     */
    private const RULE_TYPES = ['terminology', 'style', 'preservation'];

    /**
     * Constructor.
     *
     * @param   DatabaseInterface     $db          Database connector object.
     * @param   DispatcherInterface   $dispatcher  Event dispatcher.
     *
     * @since   0.4.0
     */
    public function __construct(DatabaseInterface $db, ?DispatcherInterface $dispatcher = null)
    {
        $this->typeAlias = 'com_translations.rule';

        parent::__construct('#__translations_rules', 'id', $db, $dispatcher);

        // The publishing column is named state.
        $this->setColumnAlias('published', 'state');
    }

    /**
     * Overloaded store method to track dates and users.
     *
     * @param   boolean  $updateNulls  Whether null values should be stored.
     *
     * @return  boolean  True on success.
     *
     * @since   0.4.0
     */
    public function store($updateNulls = true)
    {
        $date = Factory::getDate()->toSql();
        $user = Factory::getApplication()->getIdentity();

        // A scheduled console run carries no identity; record user id 0 then.
        $userId = $user !== null ? (int) $user->id : 0;

        $this->modified    = $date;
        $this->modified_by = $userId;

        if (!$this->id) {
            if (!(int) $this->created) {
                $this->created = $date;
            }

            if (empty($this->created_by)) {
                $this->created_by = $userId;
            }
        }

        return parent::store($updateNulls);
    }

    /**
     * Overloaded check method to ensure data integrity.
     *
     * @return  boolean  True on success.
     *
     * @since   0.4.0
     */
    public function check()
    {
        if (trim($this->rule_name) === '') {
            throw new \Exception(Text::_('COM_TRANSLATIONS_RULE_ERROR_NAME'));
        }

        if (!\in_array($this->rule_type, self::RULE_TYPES, true)) {
            throw new \Exception(Text::_('COM_TRANSLATIONS_RULE_ERROR_TYPE'));
        }

        if (trim($this->rule_text) === '') {
            throw new \Exception(Text::_('COM_TRANSLATIONS_RULE_ERROR_TEXT'));
        }

        // Keep the confidence inside the stored decimal(3,2) range.
        $this->confidence = min(1, max(0, (float) $this->confidence));

        return parent::check();
    }

    /**
     * Get the type alias for the history table.
     *
     * @return  string
     *
     * @since   0.4.0
     */
    public function getTypeAlias()
    {
        return $this->typeAlias;
    }
}
