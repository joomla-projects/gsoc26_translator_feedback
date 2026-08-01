<?php

/**
 * @package     Joomla.Administrator
 * @subpackage  com_translations
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Component\Translations\Administrator\Event;

use Joomla\CMS\Event\AbstractEvent;
use Joomla\CMS\Event\Result\ResultAware;
use Joomla\CMS\Event\Result\ResultAwareInterface;
use Joomla\CMS\Event\Result\ResultTypeArrayAware;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Event dispatched to the "rag" plugin group to reduce words to their standard form.
 *
 * It carries the words to reduce and the language they are written in; a provider plugin
 * returns them keyed by the word it was given through addResult(), so a plural noun and a
 * conjugated verb both resolve to the form a rule stores. Words the provider leaves out
 * stay unresolved and are matched as they were written.
 *
 * @since  0.9.0
 */
class NormaliseEvent extends AbstractEvent implements ResultAwareInterface
{
    use ResultAware;
    use ResultTypeArrayAware;

    /**
     * Constructor.
     *
     * @param   string  $name       The event name.
     * @param   array   $arguments  The event arguments.
     *
     * @throws  \BadMethodCallException  When a required argument is missing.
     *
     * @since   0.9.0
     */
    public function __construct($name, array $arguments = [])
    {
        parent::__construct($name, $arguments);

        foreach (['words', 'language'] as $argument) {
            if (!\array_key_exists($argument, $this->arguments)) {
                throw new \BadMethodCallException(
                    \sprintf("Argument '%s' of event %s is required but has not been provided", $argument, $name)
                );
            }
        }
    }

    /**
     * Getter for the words to reduce to their standard form.
     *
     * @return  array
     *
     * @since   0.9.0
     */
    public function getWords(): array
    {
        return $this->arguments['words'];
    }

    /**
     * Getter for the language the words are written in.
     *
     * A word's standard form depends only on its own language, so this is the source
     * language of the content, not the language it is being translated into.
     *
     * @return  string
     *
     * @since   0.9.0
     */
    public function getLanguage(): string
    {
        return $this->arguments['language'];
    }
}
