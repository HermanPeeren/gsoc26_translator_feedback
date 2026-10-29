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
use ReflectionMethod;

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
        $method = new ReflectionMethod(StringTranslator::class, 'ofTheirSources');

        return $method->invokeArgs(null, [$strings, $translations]);
    }
}
