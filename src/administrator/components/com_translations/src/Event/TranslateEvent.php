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
 * Event dispatched to the "translation" plugin group to translate an item's strings.
 *
 * It carries the strings to translate keyed by field, plus the source and target
 * languages, and optional distilled rules to steer the translation; a provider plugin
 * returns the translated strings through addResult().
 *
 * @since  0.4.0
 */
class TranslateEvent extends AbstractEvent implements ResultAwareInterface
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
     * @since   0.4.0
     */
    public function __construct($name, array $arguments = [])
    {
        parent::__construct($name, $arguments);

        foreach (['sourceStrings', 'sourceLanguage', 'targetLanguage'] as $argument) {
            if (!\array_key_exists($argument, $this->arguments)) {
                throw new \BadMethodCallException(
                    \sprintf("Argument '%s' of event %s is required but has not been provided", $argument, $name)
                );
            }
        }
    }

    /**
     * Getter for the strings to translate, keyed by field.
     *
     * @return  array
     *
     * @since   0.4.0
     */
    public function getSourceStrings(): array
    {
        return $this->arguments['sourceStrings'];
    }

    /**
     * Getter for the source language code.
     *
     * @return  string
     *
     * @since   0.4.0
     */
    public function getSourceLanguage(): string
    {
        return $this->arguments['sourceLanguage'];
    }

    /**
     * Getter for the target language code.
     *
     * @return  string
     *
     * @since   0.4.0
     */
    public function getTargetLanguage(): string
    {
        return $this->arguments['targetLanguage'];
    }

    /**
     * Getter for the distilled rules that steer the translation, grouped by rule type.
     *
     * Optional context: an empty array when the producer passes no rules, so a provider can
     * treat rules as absent.
     *
     * @return  array
     *
     * @since   0.7.0
     */
    public function getRules(): array
    {
        return $this->arguments['rules'] ?? [];
    }
}
