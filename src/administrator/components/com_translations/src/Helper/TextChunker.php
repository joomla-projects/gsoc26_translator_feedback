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

/**
 * Divides an item's strings into the requests needed to translate them.
 *
 * A provider has to send the whole translation back, so a long article asks for more output
 * than a model will produce in one reply and more time than a web request is given. An item
 * whose strings do not fit is therefore translated in several requests, and a single long value
 * is cut up to make that possible: splitting by field alone does not help, because an article's
 * body is one value.
 *
 * A cut is only ever made between a '>' and a '<', so it never lands inside a tag or inside a
 * word, and the shallowest boundary near the wanted size is preferred so a chunk tends to end
 * at a paragraph or heading rather than inside one. White space between the two tags does not
 * matter, because the editor writes a line break between block elements and between table
 * rows. A sentence end serves where there is no tag, and a line break where there is no
 * sentence either, as in a code sample; only a stretch with none of these - one long line -
 * makes the wanted size overshoot to the next boundary. The parts of a value are plain
 * substrings in order, so putting them back together is concatenation and reproduces the
 * source exactly.
 *
 * @since  1.4.0
 */
final class TextChunker
{
    /**
     * The source characters one request aims to carry when the component sets no size.
     *
     * Deliberately well under what a model will write back in one reply, because the reply
     * carries the translation of every character sent and is what the time goes on.
     *
     * @var    integer
     * @since  1.4.0
     */
    public const DEFAULT_TARGET_CHARS = 4000;

    /**
     * The smallest size a request may aim for, so a misconfigured option cannot ask for a
     * request per sentence.
     *
     * @var    integer
     * @since  1.4.0
     */
    public const MIN_TARGET_CHARS = 1000;

    /**
     * Separates a field's name from the number of one of its parts, as in 'fulltext#2'.
     *
     * A field name is a column, a dotted path into a JSON column, or a prefixed custom field
     * name, and none of those can hold a '#'.
     *
     * @var    string
     * @since  1.4.0
     */
    public const PART_SEPARATOR = '#';

    /**
     * How full a chunk must be before a boundary in it counts as a candidate, as a fraction of
     * the wanted size.
     *
     * Without it the first paragraph break in a value would end the chunk, because it is the
     * shallowest boundary there is, and an item would take a request per paragraph.
     *
     * @var    float
     * @since  1.4.0
     */
    private const MIN_FILL = 0.6;

    /**
     * What a sentence end ranks as against a tag boundary: worse than any nesting a document
     * realistically reaches, so markup is always cut at markup where there is any.
     *
     * @var    integer
     * @since  1.4.0
     */
    private const SENTENCE_RANK = 100;

    /**
     * What a line break in text ranks as: below a sentence end, so it serves only where a stretch
     * holds neither markup nor sentences, as a code sample does.
     *
     * @var    integer
     * @since  1.4.10
     */
    private const LINE_RANK = 200;

    /**
     * The most headings an outline names, so the context stays small next to the text itself.
     *
     * @var    integer
     * @since  1.4.0
     */
    private const MAX_OUTLINE_HEADINGS = 40;

    /**
     * The elements that hold nothing and so never change the nesting.
     *
     * @var    string[]
     * @since  1.4.0
     */
    private const VOID_ELEMENTS = [
        'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input',
        'link', 'meta', 'param', 'source', 'track', 'wbr',
    ];

    /**
     * Divide an item's strings into the requests needed to translate them.
     *
     * Values are kept in the order they were collected, a value longer than one request is cut
     * into numbered parts, and the parts are then packed into requests. A value that is longer
     * than a whole request on its own ends up in a request of its own.
     *
     * @param   array    $strings      The source strings keyed by field.
     * @param   integer  $targetChars  The source characters one request aims to carry.
     *
     * @return  array<int, array<string, string>>  The requests, each holding its parts keyed by part key.
     *
     * @since   1.4.0
     */
    public static function plan(array $strings, int $targetChars = self::DEFAULT_TARGET_CHARS): array
    {
        $targetChars = max(self::MIN_TARGET_CHARS, $targetChars);
        $chunks      = [];
        $chunk       = [];
        $length      = 0;

        foreach (self::parts($strings, $targetChars) as $partKey => $text) {
            $size = \strlen($text);

            // A part that would take the request past the size it aims for starts the next one,
            // unless nothing is in it yet: every request holds at least one part.
            if ($chunk !== [] && $length + $size > $targetChars) {
                $chunks[] = $chunk;
                $chunk    = [];
                $length   = 0;
            }

            $chunk[$partKey] = $text;
            $length         += $size;
        }

        if ($chunk !== []) {
            $chunks[] = $chunk;
        }

        return $chunks;
    }

    /**
     * The parts every value breaks down into, keyed by part key and in the order given.
     *
     * A value that fits in one request keeps its field name as its key, so an item that needs
     * no cutting is sent exactly as it was before.
     *
     * @param   array    $strings      The source strings keyed by field.
     * @param   integer  $targetChars  The source characters one request aims to carry.
     *
     * @return  array<string, string>  The parts keyed by part key.
     *
     * @since   1.4.0
     */
    public static function parts(array $strings, int $targetChars = self::DEFAULT_TARGET_CHARS): array
    {
        $targetChars = max(self::MIN_TARGET_CHARS, $targetChars);
        $parts       = [];

        foreach ($strings as $key => $text) {
            $text  = (string) $text;
            $split = self::split($text, $targetChars);

            if (\count($split) === 1) {
                $parts[(string) $key] = $text;

                continue;
            }

            foreach ($split as $index => $part) {
                $parts[self::partKey((string) $key, $index + 1)] = $part;
            }
        }

        return $parts;
    }

    /**
     * Cut one value into parts of about the wanted size.
     *
     * @param   string   $text         The value to cut.
     * @param   integer  $targetChars  The source characters a part aims for.
     *
     * @return  string[]  The parts in order; the value itself when it needs no cutting.
     *
     * @since   1.4.0
     */
    public static function split(string $text, int $targetChars = self::DEFAULT_TARGET_CHARS): array
    {
        $targetChars = max(self::MIN_TARGET_CHARS, $targetChars);
        $length      = \strlen($text);

        if ($length <= $targetChars) {
            return [$text];
        }

        $boundaries = self::boundaries($text);
        $parts      = [];
        $start      = 0;

        while ($length - $start > $targetChars) {
            $cut = self::bestCut($boundaries, $start, $targetChars);

            // No boundary left anywhere: what is left has to stay in one piece.
            if ($cut === null) {
                break;
            }

            $parts[] = substr($text, $start, $cut - $start);
            $start   = $cut;
        }

        $rest = substr($text, $start);

        // A cut that landed on the last character leaves nothing behind, and an empty part would
        // be a request to translate nothing.
        if ($rest !== '') {
            $parts[] = $rest;
        }

        return $parts;
    }

    /**
     * Put a value's parts back together.
     *
     * @param   string[]  $parts  The parts in order.
     *
     * @return  string  The value the parts were cut from.
     *
     * @since   1.4.0
     */
    public static function join(array $parts): string
    {
        return implode('', $parts);
    }

    /**
     * Put translated parts back under the field names they were cut from.
     *
     * A field's parts are joined in the order they were numbered, whatever order they were
     * translated and stored in.
     *
     * @param   array<string, string>  $parts  The translated parts keyed by part key.
     *
     * @return  array<string, string>  The values keyed by field name.
     *
     * @since   1.4.0
     */
    public static function assemble(array $parts): array
    {
        $byField = [];

        foreach ($parts as $partKey => $text) {
            [$field, $index] = self::fieldAndIndex((string) $partKey);

            $byField[$field][$index] = (string) $text;
        }

        $assembled = [];

        foreach ($byField as $field => $indexed) {
            ksort($indexed, SORT_NUMERIC);

            $assembled[$field] = self::join($indexed);
        }

        return $assembled;
    }

    /**
     * The key one part of a field is sent and stored under.
     *
     * @param   string   $field  The field name.
     * @param   integer  $index  The part's number, from 1.
     *
     * @return  string
     *
     * @since   1.4.0
     */
    public static function partKey(string $field, int $index): string
    {
        return $field . self::PART_SEPARATOR . $index;
    }

    /**
     * The field a part key belongs to.
     *
     * @param   string  $partKey  The part key.
     *
     * @return  string  The field name, the key itself when it names no part.
     *
     * @since   1.4.0
     */
    public static function fieldOf(string $partKey): string
    {
        return self::fieldAndIndex($partKey)[0];
    }

    /**
     * What a chunked item tells a provider about the document its chunk comes from.
     *
     * The title and the headings are already in the strings being translated, so this costs
     * nothing extra to produce: it only repeats them, so a request that holds the middle of an
     * article still knows what the article is about and where its chunk sits.
     *
     * Given in the source language, the title and the headings were taken for names: in articles
     * sent in parts on guide.joomla.org the title stayed English in 10% of them, five times as
     * often as in articles sent whole. So each one is given in its translation as soon as a part
     * that holds it has been translated.
     *
     * @param   array                  $strings       The item's source strings keyed by field.
     * @param   array<string, string>  $translations  The translation of each title or heading translated already, by its source.
     *
     * @return  string  The context, empty when the strings say nothing useful.
     *
     * @since   1.4.0
     */
    public static function documentContext(array $strings, array $translations = []): string
    {
        $lines = [];
        $title = trim((string) ($strings['title'] ?? ''));
        $as    = static fn(string $text): string => $translations[$text] ?? $text;

        if ($title !== '') {
            $lines[] = 'Title: ' . $as($title);
        }

        $headings = self::headings($strings);

        if ($headings !== []) {
            $lines[] = "Outline:\n" . implode("\n", array_map(static fn(string $h): string => '- ' . $as($h), $headings));
        }

        return implode("\n\n", $lines);
    }

    /**
     * The translation of each heading in the parts translated so far, by its source.
     *
     * The headings of a part and of its translation are paired in the order they appear. A part
     * whose translation has another number of headings is left out, as the pairs cannot be told,
     * and so is a heading that came back as it was sent.
     *
     * @param   array<string, string>  $sourceParts      The source parts keyed by part key.
     * @param   array<string, string>  $translatedParts  The translated parts keyed by part key.
     *
     * @return  array<string, string>
     *
     * @since   3.0.0
     */
    public static function headingTranslations(array $sourceParts, array $translatedParts): array
    {
        $pairs = [];

        foreach (array_intersect_key($sourceParts, $translatedParts) as $partKey => $text) {
            $sources      = self::headingsIn((string) $text);
            $translations = self::headingsIn((string) $translatedParts[$partKey]);

            if (\count($sources) !== \count($translations)) {
                continue;
            }

            foreach ($sources as $index => $source) {
                if ($translations[$index] !== $source) {
                    $pairs[$source] ??= $translations[$index];
                }
            }
        }

        return $pairs;
    }

    /**
     * A print of the strings, to tell whether a part stored by an earlier run still belongs to
     * the text being translated now.
     *
     * A source edited between two runs is cut differently, so the parts of the run before it no
     * longer line up and have to be thrown away rather than joined onto new ones. Given an item's
     * planned parts rather than its strings, the print also changes when the same text is cut in
     * other places.
     *
     * @param   array  $strings  The item's source strings keyed by field.
     *
     * @return  string
     *
     * @since   1.4.0
     */
    public static function fingerprint(array $strings): string
    {
        return hash('sha256', (string) json_encode($strings));
    }

    /**
     * The headings in an item's strings, in the order they appear.
     *
     * @param   array  $strings  The item's source strings keyed by field.
     *
     * @return  string[]
     *
     * @since   1.4.0
     */
    private static function headings(array $strings): array
    {
        $headings = [];

        foreach ($strings as $text) {
            foreach (self::headingsIn((string) $text) as $heading) {
                $headings[] = $heading;

                if (\count($headings) === self::MAX_OUTLINE_HEADINGS) {
                    return $headings;
                }
            }
        }

        return $headings;
    }

    /**
     * The headings in one text, as plain text and in the order they appear.
     *
     * @param   string  $text  The text.
     *
     * @return  string[]
     *
     * @since   3.0.0
     */
    private static function headingsIn(string $text): array
    {
        preg_match_all('#<h[1-6][^>]*>(.*?)</h[1-6]>#is', $text, $matches);

        $headings = [];

        foreach ($matches[1] as $heading) {
            $heading = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($heading), \ENT_QUOTES | \ENT_HTML5, 'UTF-8')) ?? '');

            if ($heading !== '') {
                $headings[] = $heading;
            }
        }

        return $headings;
    }

    /**
     * Split a part key into its field name and its part number.
     *
     * @param   string  $partKey  The part key.
     *
     * @return  array{0: string, 1: int}  The field name and the part's number, 1 when the key names no part.
     *
     * @since   1.4.0
     */
    private static function fieldAndIndex(string $partKey): array
    {
        $at = strrpos($partKey, self::PART_SEPARATOR);

        if ($at === false) {
            return [$partKey, 1];
        }

        $index = substr($partKey, $at + 1);

        // A '#' that is not followed by a number is part of the name rather than a part marker.
        if ($index === '' || !ctype_digit($index)) {
            return [$partKey, 1];
        }

        return [substr($partKey, 0, $at), (int) $index];
    }

    /**
     * Where the text may be cut, and how deeply nested each of those places is.
     *
     * A place between a '>' and a '<', with nothing but white space between them, can be cut
     * without breaking a tag or a word. Nesting is counted as it stands before the tag that
     * follows, so the end of a paragraph ranks above a place inside one. A sentence end is
     * offered as well, for text that carries no markup to cut at, and ranks below every tag
     * boundary; a line break in text ranks below that again.
     *
     * @param   string  $text  The text to find boundaries in.
     *
     * @return  array<int, int>  The rank of each boundary, keyed by its offset, in ascending offset order.
     *
     * @since   1.4.0
     */
    private static function boundaries(string $text): array
    {
        $boundaries = [];
        $length     = \strlen($text);
        $depth      = 0;

        for ($i = 0; $i < $length; $i++) {
            if ($text[$i] === '.' || $text[$i] === '!' || $text[$i] === '?') {
                // A sentence end counts only where the next character starts something new, so a
                // decimal point or an abbreviation is not mistaken for one; a sentence that ends
                // the text has nothing after it to cut before.
                if (\in_array($text[$i + 1] ?? '', [' ', "\n", "\r", "\t"], true)) {
                    if (isset($text[$i + 2])) {
                        self::offer($boundaries, $i + 2, self::SENTENCE_RANK + $depth);
                    }
                }

                continue;
            }

            // A line break between two lines of text, as in a code sample, cuts after the break.
            if ($text[$i] === "\n") {
                if (isset($text[$i + 1])) {
                    self::offer($boundaries, $i + 1, self::LINE_RANK + $depth);
                }

                continue;
            }

            if ($text[$i] !== '<') {
                continue;
            }

            $tag = self::tagAt($text, $i);

            // A '<' that starts no tag is text, so it says nothing about nesting and is no boundary.
            if ($tag === null) {
                continue;
            }

            [$name, $end, $closing, $selfClosing] = $tag;

            // The editor puts a line break between block elements, so white space between the
            // tags still leaves them next to each other; the cut goes before the '<', which keeps
            // the white space with the part it follows.
            $before = $i - 1;

            while ($before >= 0 && \in_array($text[$before], [' ', "\t", "\r", "\n"], true)) {
                $before--;
            }

            if ($before >= 0 && $text[$before] === '>') {
                self::offer($boundaries, $i, $depth);
            }

            if ($closing) {
                $depth = max(0, $depth - 1);
            } elseif (!$selfClosing && !\in_array($name, self::VOID_ELEMENTS, true)) {
                $depth++;
            }

            // Skip the tag itself, so a '<' or '>' inside an attribute is never read as markup.
            $i = $end;
        }

        ksort($boundaries, SORT_NUMERIC);

        return $boundaries;
    }

    /**
     * Offer a place to cut at, keeping the better rank where a place is offered twice: a line
     * break right before a tag is a boundary of both kinds.
     *
     * @param   array<int, int>  $boundaries  The boundaries found so far, keyed by offset.
     * @param   integer          $offset      Where the cut would go.
     * @param   integer          $rank        How good a place it is; lower is better.
     *
     * @return  void
     *
     * @since   1.4.10
     */
    private static function offer(array &$boundaries, int $offset, int $rank): void
    {
        $boundaries[$offset] = min($boundaries[$offset] ?? $rank, $rank);
    }

    /**
     * Read the tag that starts at an offset.
     *
     * @param   string   $text  The text.
     * @param   integer  $at    The offset of the '<'.
     *
     * @return  array{0: string, 1: int, 2: bool, 3: bool}|null  The lower-case name, the offset of the
     *                                                           closing '>', whether it closes an element
     *                                                           and whether it closes itself; null when
     *                                                           no tag starts here.
     *
     * @since   1.4.0
     */
    private static function tagAt(string $text, int $at): ?array
    {
        $next = $text[$at + 1] ?? '';

        // A comment or a doctype is markup to skip over, but holds no element.
        if ($next === '!') {
            $end = strpos($text, '>', $at);

            return $end === false ? null : ['', $end, false, true];
        }

        $closing = $next === '/';
        $nameAt  = $at + ($closing ? 2 : 1);

        if (!preg_match('/\G[a-zA-Z][a-zA-Z0-9:-]*/', $text, $match, 0, $nameAt)) {
            return null;
        }

        $name   = strtolower($match[0]);
        $i      = $nameAt + \strlen($match[0]);
        $length = \strlen($text);
        $quote  = '';

        for (; $i < $length; $i++) {
            $char = $text[$i];

            if ($quote !== '') {
                if ($char === $quote) {
                    $quote = '';
                }

                continue;
            }

            if ($char === '"' || $char === "'") {
                $quote = $char;

                continue;
            }

            if ($char === '>') {
                return [$name, $i, $closing, $text[$i - 1] === '/'];
            }
        }

        // An unclosed tag: there is nothing safe to cut after it.
        return null;
    }

    /**
     * Choose where to cut, given where the current part starts.
     *
     * The best boundary is the shallowest one in the part of the window where a chunk is already
     * reasonably full, so a chunk ends at a paragraph or a heading rather than inside one.
     * Where that window holds none, the fullest boundary before the wanted size is taken, and
     * where there is none of those either the size is overshot to the next boundary of any kind.
     *
     * @param   array<int, int>  $boundaries   The rank of each boundary, keyed by offset.
     * @param   integer          $start        Where the current part starts.
     * @param   integer          $targetChars  The source characters a part aims for.
     *
     * @return  integer|null  The offset to cut at, or null when no boundary is left.
     *
     * @since   1.4.0
     */
    private static function bestCut(array $boundaries, int $start, int $targetChars): ?int
    {
        $limit     = $start + $targetChars;
        $least     = $start + (int) ($targetChars * self::MIN_FILL);
        $preferred = null;
        $rank      = null;
        $fullest   = null;
        $beyond    = null;

        foreach ($boundaries as $offset => $boundaryRank) {
            if ($offset <= $start) {
                continue;
            }

            if ($offset > $limit) {
                $beyond = $offset;

                break;
            }

            $fullest = $offset;

            // Ranks are compared with <= so that, among equally good boundaries, the one that
            // fills the chunk most is kept.
            if ($offset >= $least && ($rank === null || $boundaryRank <= $rank)) {
                $preferred = $offset;
                $rank      = $boundaryRank;
            }
        }

        return $preferred ?? $fullest ?? $beyond;
    }
}
