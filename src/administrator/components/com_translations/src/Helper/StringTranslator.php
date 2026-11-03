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
     * The length up to which a translation is never judged by how much longer it is than its source.
     *
     * A short string is where translation stretches most - a word becomes a phrase, an English
     * label becomes a German compound sentence - and the whole of that happens well inside this.
     *
     * @var    integer
     * @since  1.4.4
     */
    public const SHORT_ENOUGH = 200;

    /**
     * How many times its source a longer translation may be before it is not one.
     *
     * @var    integer
     * @since  1.4.4
     */
    public const FACTOR = 4;

    /**
     * The elements whose text is never translated: code, keyboard input, program output.
     *
     * In the user guide these hold what a reader types or reads on the screen exactly as it is - a
     * mail template's {USERNAME} tag, a file name, a command - and a translated one is wrong.
     *
     * @var    string
     * @since  1.6.0
     */
    private const UNTRANSLATED = '#(<(code|kbd|samp|pre)\b[^>]*>)(.*?)(</\2\s*>)#is';

    /**
     * What a placeholder for held-back text looks like.
     *
     * @var    string
     * @since  1.6.0
     */
    private const PLACEHOLDER = '/\{\{keep:\d+\}\}/';

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
        return self::translateInDocument($dispatcher, $strings, $sourceLanguage, $targetLanguage, $rules, '', [])['strings'];
    }

    /**
     * Translate strings that are one part of a longer document.
     *
     * An item too long for one request is translated in several, and each of those needs to know
     * what it is part of: what the document is about, and the terms its earlier parts were
     * already translated with. What the provider reports back about the terms it chose comes
     * with the translation, to be carried into the request after it.
     *
     * @param   DispatcherInterface    $dispatcher       The dispatcher to raise the event on.
     * @param   array                  $strings          The source strings, keyed by the caller.
     * @param   string                 $sourceLanguage   The source language code.
     * @param   string                 $targetLanguage   The target language code.
     * @param   array                  $rules            The rules to steer the provider with, empty for an unaided translation.
     * @param   string                 $documentContext  What the document is about, empty when the strings are all of it.
     * @param   array<string, string>  $terminology      The terms earlier parts were translated with.
     *
     * @return  array{strings: array, glossary: array<string, string>}  The translations, and the terms the provider chose.
     *
     * @throws  \RuntimeException  When no translation provider returns a translation.
     *
     * @since   1.4.0
     */
    public static function translateInDocument(
        DispatcherInterface $dispatcher,
        array $strings,
        string $sourceLanguage,
        string $targetLanguage,
        array $rules,
        string $documentContext,
        array $terminology
    ): array {
        // No strings means nothing for a provider to translate.
        if ($strings === []) {
            return ['strings' => $strings, 'glossary' => []];
        }

        PluginHelper::importPlugin('translation', null, true, $dispatcher);

        [$sent, $held] = self::holdUntranslated($strings);

        $event = new TranslateEvent('onTranslate', [
            'sourceStrings'   => $sent,
            'sourceLanguage'  => $sourceLanguage,
            'targetLanguage'  => $targetLanguage,
            'rules'           => $rules,
            'documentContext' => $documentContext,
            'terminology'     => $terminology,
        ]);
        $dispatcher->dispatch('onTranslate', $event);

        // Use the first provider that returned translations.
        foreach ((array) $event->getArgument('result', []) as $providerResult) {
            if (\is_array($providerResult) && $providerResult !== []) {
                return [
                    'strings'  => self::restoreUntranslated($sent, self::ofTheirSources($sent, $providerResult), $held),
                    'glossary' => $event->getGlossary(),
                ];
            }
        }

        throw new \RuntimeException('No translation provider is enabled. Enable a translation plugin to translate content.');
    }

    /**
     * Keep only the translations that can be translations of the strings they came back under.
     *
     * A provider answers in a keyed object, and a key says which source string an answer belongs
     * to. On guide.joomla.org a request carrying an article's title beside the first fragment of
     * its body came back with the fragment under the title's key, and nothing noticed: the draft
     * took a body for its title and Joomla was asked to build an alias out of it.
     *
     * A translation is not the same length as its source, and for a short string it can be several
     * times as long, so this only refuses what no language stretches to: an answer that differs
     * from its source by more than FACTOR either way, once either of them is past SHORT_ENOUGH.
     * Both halves of a swap go: the body that came back as a title, and the title that came back
     * as the body. An answer that is refused is simply absent, which the callers already handle -
     * a field keeps its source text, and a fragment that never arrived stops the item, so it is
     * tried again rather than saved wrong.
     *
     * @param   array  $strings       The source strings, keyed by the caller.
     * @param   array  $translations  What the provider answered, keyed the same.
     *
     * Parts held from an earlier run go through this as well, and matter more: a fresh answer that
     * cannot belong is asked for again, while a stored one is reused on every attempt, so an item
     * that was once answered wrong fails the same way for as long as it is kept.
     *
     * @return  array  The answers that can belong to their key.
     *
     * @since   1.4.4
     */
    public static function ofTheirSources(array $strings, array $translations): array
    {
        $kept = [];

        foreach ($translations as $key => $translation) {
            $source    = mb_strlen((string) ($strings[$key] ?? ''));
            $translated = mb_strlen((string) $translation);

            // Both ends of a swap are refused: the body that came back as a title is too long for
            // it, and the title that came back as the body is as far too short.
            if (max($source, $translated) > self::SHORT_ENOUGH
                && ($translated > $source * self::FACTOR || $source > $translated * self::FACTOR)) {
                continue;
            }

            $kept[$key] = $translation;
        }

        return $kept;
    }

    /**
     * Take the text out of the elements that are not translated, leaving a numbered placeholder.
     *
     * Asking a provider to leave the text alone is not enough: a column of mail template tags -
     * NAME, DATE, MESSAGE - reads like words, and a provider translates words. What it never sees
     * it cannot translate. The element itself stays, so the provider still knows something is
     * there and keeps it in its place in the sentence.
     *
     * @param   array  $strings  The source strings, keyed by the caller.
     *
     * @return  array{0: array, 1: array<string, string>}  The strings to send, and the text held back by placeholder.
     *
     * @since   1.6.0
     */
    public static function holdUntranslated(array $strings): array
    {
        $held = [];

        foreach ($strings as $key => $string) {
            if (!\is_string($string) || stripos($string, '<') === false) {
                continue;
            }

            $strings[$key] = preg_replace_callback(
                self::UNTRANSLATED,
                static function (array $m) use (&$held): string {
                    if (trim($m[3]) === '') {
                        return $m[0];
                    }

                    $placeholder        = '{{keep:' . \count($held) . '}}';
                    $held[$placeholder] = $m[3];

                    return $m[1] . $placeholder . $m[4];
                },
                $string
            ) ?? $string;
        }

        return [$strings, $held];
    }

    /**
     * Put the held-back text in place of its placeholders.
     *
     * An answer has to come back with exactly the placeholders its string was sent with, each
     * once. One that lost a placeholder, doubled one or took another string's is left out: the
     * callers treat a missing answer as one still to be asked for, which is better than a
     * translation with a hole where the code was.
     *
     * @param   array                  $sent          The strings as they were sent, with their placeholders.
     * @param   array                  $translations  What the provider answered, keyed the same.
     * @param   array<string, string>  $held          The text held back, by placeholder.
     *
     * @return  array  The answers with their held-back text back in place.
     *
     * @since   1.6.0
     */
    public static function restoreUntranslated(array $sent, array $translations, array $held): array
    {
        if ($held === []) {
            return $translations;
        }

        $placeholders = static function (string $text): array {
            preg_match_all(self::PLACEHOLDER, $text, $found);
            sort($found[0]);

            return $found[0];
        };

        $restored = [];

        foreach ($translations as $key => $translation) {
            $translation = (string) $translation;

            if ($placeholders($translation) !== $placeholders((string) ($sent[$key] ?? ''))) {
                continue;
            }

            $restored[$key] = strtr($translation, $held);
        }

        return $restored;
    }
}
