<?php

/**
 * @package     Joomla.Tests
 * @subpackage  com_translations
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Joomla\Component\Translations\Tests\Unit;

use Joomla\Component\Translations\Administrator\Helper\StringTranslator;
use PHPUnit\Framework\TestCase;

/**
 * What a provider answers is only kept where it can belong to the string it answers.
 *
 * A provider is asked for several strings at once and answers them keyed, and nothing downstream
 * questions which answer belongs to which key. On guide.joomla.org a request carrying an article's
 * title beside the first fragment of its body came back with the two the wrong way round: the
 * draft took a body for its title, and Joomla was asked to build a URL alias out of it.
 *
 * @since  1.4.4
 */
final class StringTranslatorTest extends TestCase
{
    /**
     * A body answered under a title's key is refused.
     *
     * @return  void
     *
     * @since   1.4.4
     */
    public function testABodyAnsweredAsATitleIsRefused(): void
    {
        $strings = [
            'title'       => 'Image margin problem',
            'introtext#1' => str_repeat('<p>A paragraph of the body text.</p>', 40),
        ];

        $answered = self::keep($strings, [
            'title'       => str_repeat('<p>Um paragrafo do texto do corpo.</p>', 40),
            'introtext#1' => 'Problema de margem na imagem',
        ]);

        $this->assertSame([], $answered, 'neither half of a swap is kept');
    }

    /**
     * An ordinary translation is kept, however much the language stretches it.
     *
     * @return  void
     *
     * @since   1.4.4
     */
    public function testAnOrdinaryTranslationIsKept(): void
    {
        $strings = [
            'title'    => 'Image margin problem',
            'metadesc' => 'How to set the border colour of an image in an article.',
            'note'     => '',
        ];

        $translated = [
            // German compounds and Portuguese articles make a short string longer; neither is a swap.
            'title'    => 'Problema da margem da imagem no editor de artigos',
            'metadesc' => 'Wie die Rahmenfarbe eines Bildes in einem Artikel festgelegt wird.',
            'note'     => '',
        ];

        $this->assertSame($translated, self::keep($strings, $translated));
    }

    /**
     * A long text whose translation is a normal size is kept.
     *
     * @return  void
     *
     * @since   1.4.4
     */
    public function testALongTextTranslatedToALongTextIsKept(): void
    {
        $source = str_repeat('<p>A paragraph of the body text.</p>', 40);
        $answer = str_repeat('<p>Um paragrafo do texto do corpo, mais longo.</p>', 40);

        $this->assertSame(['introtext#1' => $answer], self::keep(['introtext#1' => $source], ['introtext#1' => $answer]));
    }

    /**
     * Short strings are never judged by length, where translation stretches most.
     *
     * @return  void
     *
     * @since   1.4.4
     */
    public function testShortStringsAreNotJudgedByLength(): void
    {
        $translated = ['title' => str_repeat('a', StringTranslator::SHORT_ENOUGH)];

        $this->assertSame($translated, self::keep(['title' => 'OK'], $translated));
    }

    /**
     * An answer under a key that was never sent is judged against nothing and goes.
     *
     * @return  void
     *
     * @since   1.4.4
     */
    public function testAnAnswerToAKeyThatWasNeverSentIsRefused(): void
    {
        $kept = self::keep(['title' => 'Image margin problem'], ['fulltext' => str_repeat('b', 900)]);

        $this->assertSame([], $kept);
    }

    /**
     * A part held from an earlier run is checked like a fresh answer.
     *
     * This is where it matters most. A fresh answer that cannot belong is asked for again, while
     * a stored one is reused without a request, so an item answered wrong once went on failing
     * the same way every time it was tried - which is what item 375 on guide.joomla.org did.
     *
     * @return  void
     *
     * @since   1.4.5
     */
    public function testAStoredPartIsCheckedLikeAFreshAnswer(): void
    {
        $sources = [
            'title'       => 'Image test',
            'introtext#1' => str_repeat('<p>A paragraph of the body text.</p>', 40),
            'introtext#2' => str_repeat('<p>Another paragraph of the body.</p>', 40),
        ];

        $stored = [
            'title'       => str_repeat('<p>Um paragrafo do texto do corpo.</p>', 40),
            'introtext#1' => 'Prueba de imagen',
            'introtext#2' => str_repeat('<p>Outro paragrafo do corpo.</p>', 40),
        ];

        $this->assertSame(
            ['introtext#2' => $stored['introtext#2']],
            self::keep($sources, $stored),
            'the swapped pair goes, the part that belongs stays'
        );
    }

    /**
     * The text in code elements is not sent, and comes back as it was.
     *
     * @return  void
     *
     * @since   1.6.0
     */
    public function testCodeIsNotSentAndComesBackAsItWas(): void
    {
        $strings = [
            'title'       => 'Mail Templates Tags',
            'introtext#1' => '<tr><th scope="row"><code>NAME</code></th><td>X</td></tr>'
                . '<p>Use <kbd>Ctrl</kbd> and the <code class="x">{MESSAGES}</code> tag, not <code> </code>.</p>',
        ];

        [$sent, $held] = StringTranslator::holdUntranslated($strings);

        $this->assertSame('Mail Templates Tags', $sent['title']);
        $this->assertStringNotContainsString('NAME', $sent['introtext#1']);
        $this->assertStringNotContainsString('MESSAGES', $sent['introtext#1']);
        $this->assertStringContainsString('<code class="x">{{keep:2}}</code>', $sent['introtext#1']);
        $this->assertStringContainsString('<code> </code>', $sent['introtext#1'], 'an empty element is left alone');
        $this->assertSame(['{{keep:0}}' => 'NAME', '{{keep:1}}' => 'Ctrl', '{{keep:2}}' => '{MESSAGES}'], $held);

        // A provider may move the elements around in a sentence.
        $answer = [
            'title'       => 'Schlagwörter der E-Mail-Vorlagen',
            'introtext#1' => '<tr><th scope="row"><code>{{keep:0}}</code></th><td>X</td></tr>'
                . '<p>Verwenden Sie das Tag <code class="x">{{keep:2}}</code> mit <kbd>{{keep:1}}</kbd>,'
                . ' nicht <code> </code>.</p>',
        ];

        $this->assertSame([
            'title'       => 'Schlagwörter der E-Mail-Vorlagen',
            'introtext#1' => '<tr><th scope="row"><code>NAME</code></th><td>X</td></tr>'
                . '<p>Verwenden Sie das Tag <code class="x">{MESSAGES}</code> mit <kbd>Ctrl</kbd>,'
                . ' nicht <code> </code>.</p>',
        ], StringTranslator::restoreUntranslated($sent, $answer, $held));
    }

    /**
     * An answer that lost or doubled a placeholder is left out, to be asked for again.
     *
     * @return  void
     *
     * @since   1.6.0
     */
    public function testAnAnswerWithoutItsPlaceholdersIsLeftOut(): void
    {
        $strings = [
            'introtext#1' => '<p>The <code>NAME</code> and <code>DATE</code> tags.</p>',
            'introtext#2' => '<p>The <code>EMAIL</code> tag.</p>',
            'introtext#3' => '<p>No code here.</p>',
        ];

        [$sent, $held] = StringTranslator::holdUntranslated($strings);

        $answer = [
            'introtext#1' => '<p>Les balises <code>NOM</code> et <code>{{keep:1}}</code>.</p>',
            'introtext#2' => '<p>La balise <code>{{keep:2}}</code> et <code>{{keep:2}}</code>.</p>',
            'introtext#3' => '<p>Pas de code ici.</p>',
        ];

        $this->assertSame(
            ['introtext#3' => '<p>Pas de code ici.</p>'],
            StringTranslator::restoreUntranslated($sent, $answer, $held)
        );
    }

    /**
     * Run the provider's answer through the check.
     *
     * @param   array  $strings       The source strings.
     * @param   array  $translations  What the provider answered.
     *
     * @return  array  What is kept.
     *
     * @since   1.4.4
     */
    private static function keep(array $strings, array $translations): array
    {
        return StringTranslator::ofTheirSources($strings, $translations);
    }
}
