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
 * Event dispatched to the "translation" plugin group to distil rules from feedback.
 *
 * It carries a batch of translator corrections for one target language, the rules
 * already learned for that language, and the source and target languages; a provider
 * plugin returns the distilled rule candidates through addResult().
 *
 * @since  0.4.0
 */
class DistilEvent extends AbstractEvent implements ResultAwareInterface
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

        foreach (['corrections', 'existingRules', 'sourceLanguage', 'targetLanguage'] as $argument) {
            if (!\array_key_exists($argument, $this->arguments)) {
                throw new \BadMethodCallException(
                    \sprintf("Argument '%s' of event %s is required but has not been provided", $argument, $name)
                );
            }
        }
    }

    /**
     * Getter for the corrections to distil, each a source/machine/human/diff set.
     *
     * @return  array
     *
     * @since   0.4.0
     */
    public function getCorrections(): array
    {
        return $this->arguments['corrections'];
    }

    /**
     * Getter for the rules already learned for the target language, so a provider merges
     * rather than duplicates.
     *
     * @return  array
     *
     * @since   0.4.0
     */
    public function getExistingRules(): array
    {
        return $this->arguments['existingRules'];
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
}
