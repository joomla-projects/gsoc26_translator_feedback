<?php

/**
 * @package     Joomla.Administrator
 * @subpackage  com_translations
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Component\Translations\Administrator\Helper;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use Joomla\CMS\Plugin\PluginHelper;
use Joomla\Component\Translations\Administrator\Event\TranslateEvent;
use Joomla\Event\DispatcherInterface;

/**
 * Asks the "translation" plugin group to translate a collection of strings.
 *
 * The whole collection goes over as one onTranslate event so a provider keeps the context
 * between the strings, and the first provider to answer wins. The rules to steer it with are
 * a parameter rather than something looked up here, because the two callers want different
 * things: translating an item applies what has been learned, while measuring a translation
 * against one that already exists has to ask for an unaided one.
 *
 * @since  1.0.0
 */
class StringTranslator
{
    /**
     * Translate a collection of strings.
     *
     * @param   DispatcherInterface  $dispatcher      The dispatcher to raise the event on.
     * @param   array                $strings         The source strings, keyed by the caller.
     * @param   string               $sourceLanguage  The source language code.
     * @param   string               $targetLanguage  The target language code.
     * @param   array                $rules           The rules to steer the provider with, empty for an unaided translation.
     *
     * @return  array  The translated strings, keyed as given.
     *
     * @throws  \RuntimeException  When no translation provider returns a translation.
     *
     * @since   1.0.0
     */
    public static function translate(
        DispatcherInterface $dispatcher,
        array $strings,
        string $sourceLanguage,
        string $targetLanguage,
        array $rules
    ): array {
        // No strings means nothing for a provider to translate.
        if ($strings === []) {
            return $strings;
        }

        PluginHelper::importPlugin('translation', null, true, $dispatcher);

        $event = new TranslateEvent('onTranslate', [
            'sourceStrings'  => $strings,
            'sourceLanguage' => $sourceLanguage,
            'targetLanguage' => $targetLanguage,
            'rules'          => $rules,
        ]);
        $dispatcher->dispatch('onTranslate', $event);

        // Use the first provider that returned translations.
        foreach ((array) $event->getArgument('result', []) as $providerResult) {
            if (\is_array($providerResult) && $providerResult !== []) {
                return $providerResult;
            }
        }

        throw new \RuntimeException('No translation provider is enabled. Enable a translation plugin to translate content.');
    }
}
