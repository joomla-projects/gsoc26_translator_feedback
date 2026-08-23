<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  Task.TranslationsSeed
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Plugin\Task\TranslationsSeed\Helper;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use Joomla\CMS\Language\LanguageHelper;

/**
 * Reads the strings an installed language pack has already translated, paired with their
 * source-language originals.
 *
 * A pack holds a translation the community has agreed on, so a pair of these is the same
 * material a translator produces by correcting a draft: the string as written, and the
 * wording the language team settled on. Reading them gives the distiller something to learn
 * from before a site has any feedback of its own.
 *
 * Pairs are aligned by file name and key across all three clients, which a pack keeps
 * identical to the source language. A key the pack does not carry has no agreed translation
 * and is skipped.
 *
 * @since  1.0.0
 */
class LanguagePackReader
{
    /**
     * Matches a printf conversion, including the positional form.
     *
     * A value holding one of these is assembled at runtime, so a translation moves the
     * placeholder to suit the target grammar. That difference is word order forced by the
     * sentence rather than a choice the language team made, and it is not a rule.
     *
     * @var    string
     * @since  1.0.0
     */
    private const PLACEHOLDER_PATTERN = '/%(?:\d+\$)?[sduf]/';

    /**
     * Matches an HTML tag or a character entity.
     *
     * Some values are markup rather than prose, and a pack is free to point them somewhere
     * else entirely: the English "Captcha" is translated as a whole anchor element linking to
     * the target language's encyclopaedia article. A diff over those compares markup.
     *
     * @var    string
     * @since  1.0.0
     */
    private const MARKUP_PATTERN = '/<[a-zA-Z\/!][^>]*>|&[a-zA-Z]+;|&#\d+;/';

    /**
     * Read the translated string pairs the two languages share.
     *
     * @param   string    $sourceLanguage  The language tag the strings are written in.
     * @param   string    $targetLanguage  The language tag of the pack to pair them against.
     * @param   string[]  $fileNames       The language file names to read, all of them when empty.
     *
     * @return  array  One entry per pair, carrying its file, key, source string and approved translation.
     *
     * @since   1.0.0
     */
    public static function read(string $sourceLanguage, string $targetLanguage, array $fileNames = []): array
    {
        $sourceStrings   = self::readLanguage($sourceLanguage, $fileNames);
        $approvedStrings = self::readLanguage($targetLanguage, $fileNames);
        $pairs           = [];

        foreach ($sourceStrings as $identifier => $sourceString) {
            $approvedString = $approvedStrings[$identifier] ?? null;

            if ($approvedString === null || !self::isSeedable($sourceString, $approvedString)) {
                continue;
            }

            [$file, $key] = explode('#', $identifier, 2);

            $pairs[] = [
                'file'     => $file,
                'key'      => $key,
                'source'   => $sourceString,
                'approved' => $approvedString,
            ];
        }

        return $pairs;
    }

    /**
     * Whether a pair carries a translation choice worth learning from.
     *
     * @param   string  $sourceString    The string as written in the source language.
     * @param   string  $approvedString  The pack's translation of it.
     *
     * @return  boolean  True when the pair is worth seeding.
     *
     * @since   1.0.0
     */
    public static function isSeedable(string $sourceString, string $approvedString): bool
    {
        $sourceString   = trim($sourceString);
        $approvedString = trim($approvedString);

        if ($sourceString === '' || $approvedString === '') {
            return false;
        }

        // A pack falls back to the source string for anything it has not translated yet.
        if ($sourceString === $approvedString) {
            return false;
        }

        foreach ([$sourceString, $approvedString] as $value) {
            if (preg_match(self::PLACEHOLDER_PATTERN, $value) || preg_match(self::MARKUP_PATTERN, $value)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Read one language's strings from every client, keyed so the two languages line up.
     *
     * A file name is repeated across the clients, so the client is part of the identifier and
     * an administrator string is never paired with the site string of the same key.
     *
     * @param   string    $language   The language tag to read.
     * @param   string[]  $fileNames  The language file names to read, all of them when empty.
     *
     * @return  string[]  The strings, keyed by client-qualified file name and language key.
     *
     * @since   1.0.0
     */
    private static function readLanguage(string $language, array $fileNames): array
    {
        $strings = [];

        $clientPaths = [
            'site'          => JPATH_SITE,
            'administrator' => JPATH_ADMINISTRATOR,
            'api'           => JPATH_API,
        ];

        foreach ($clientPaths as $client => $basePath) {
            $path = LanguageHelper::getLanguagePath($basePath, $language);

            foreach (glob($path . '/*.ini', GLOB_NOSORT) ?: [] as $file) {
                $fileName = basename($file);

                if ($fileNames !== [] && !\in_array($fileName, $fileNames, true)) {
                    continue;
                }

                foreach (LanguageHelper::parseIniFile($file) as $key => $value) {
                    $strings[$client . '/' . $fileName . '#' . $key] = $value;
                }
            }
        }

        return $strings;
    }
}
