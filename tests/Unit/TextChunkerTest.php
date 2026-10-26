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

use Joomla\Component\Translations\Administrator\Helper\TextChunker;
use PHPUnit\Framework\TestCase;

/**
 * How an item is divided into the requests needed to translate it.
 *
 * Everything cut here is put back together and saved as an article, so the cutting has to be
 * exact: a part that loses a character, splits a tag or splits a word would be written into the
 * site's content, and a plan that comes out differently on the next run would make the parts a
 * previous run paid for unusable.
 *
 * @since  1.4.0
 */
final class TextChunkerTest extends TestCase
{
    /**
     * An item that fits in one request is sent whole, under its own field names.
     *
     * Almost every article is this case, and it has to stay exactly what it was before an item
     * could be cut up at all: one request, no part numbers, nothing stored between runs.
     *
     * @return  void
     *
     * @since   1.4.0
     */
    public function testAnItemThatFitsIsOneRequestUnderItsOwnKeys(): void
    {
        $strings = ['title' => 'Security headers', 'introtext' => '<p>A short introduction.</p>'];

        $this->assertSame([$strings], TextChunker::plan($strings, 1000));
    }

    /**
     * Nothing to translate asks for no request at all.
     *
     * @return  void
     *
     * @since   1.4.0
     */
    public function testNothingToTranslateIsNoRequest(): void
    {
        $this->assertSame([], TextChunker::plan([], 1000));
    }

    /**
     * The parts of a cut value put back together are the value, character for character.
     *
     * @return  void
     *
     * @since   1.4.0
     */
    public function testThePartsOfACutValueRebuildItExactly(): void
    {
        $html  = self::article(60);
        $parts = TextChunker::split($html, 1000);

        $this->assertGreaterThan(1, \count($parts), 'A value well over the size asked for is cut');
        $this->assertSame($html, TextChunker::join($parts));
    }

    /**
     * Every cut lands between two tags, so no part ends inside a tag or inside a word.
     *
     * A part cut inside a tag would reach the model as broken markup and come back as broken
     * markup; a part cut inside a word would be translated as two half words.
     *
     * @return  void
     *
     * @since   1.4.0
     */
    public function testEveryCutLandsBetweenTags(): void
    {
        $parts = TextChunker::split(self::article(60), 1000);

        $this->assertGreaterThan(1, \count($parts), 'There is more than one part, so there is a cut to check');

        // The last part ends where the value does, so only the joins between parts are cuts.
        foreach (\array_slice($parts, 0, -1) as $index => $part) {
            $this->assertStringEndsWith('>', $part, \sprintf('Part %d ends at a tag', $index + 1));
        }

        foreach (\array_slice($parts, 1) as $index => $part) {
            $this->assertStringStartsWith('<', $part, \sprintf('Part %d starts at a tag', $index + 2));
        }
    }

    /**
     * A stretch with nowhere safe to cut is kept whole rather than cut through.
     *
     * A code sample or a long table can run for thousands of characters with no boundary in it.
     * Overshooting the size asked for costs a slower request; cutting inside it would corrupt it.
     *
     * @return  void
     *
     * @since   1.4.0
     */
    public function testAStretchWithNoBoundaryIsKeptWhole(): void
    {
        $block = '<pre>' . str_repeat('some_setting = value; ', 300) . '</pre>';
        $parts = TextChunker::split('<p>Before.</p>' . $block . '<p>After.</p>', 1000);

        $this->assertContains($block, $parts, 'The block that cannot be cut is one part of its own');
    }

    /**
     * Text with no markup at all is cut at the end of a sentence.
     *
     * @return  void
     *
     * @since   1.4.0
     */
    public function testPlainTextIsCutAtASentenceEnd(): void
    {
        $plain = str_repeat('This is a sentence of a reasonable length. ', 60);
        $parts = TextChunker::split($plain, 1000);

        $this->assertGreaterThan(1, \count($parts));
        $this->assertSame($plain, TextChunker::join($parts));

        foreach (\array_slice($parts, 1) as $part) {
            $this->assertStringStartsWith('This is', $part, 'A part starts where a sentence does');
        }
    }

    /**
     * A word is never cut through, even when nothing else can be cut at.
     *
     * One unbroken run of characters - a base64 image, a long token - has no boundary of any
     * kind, so there is nothing to do but leave it in one piece.
     *
     * @return  void
     *
     * @since   1.4.0
     */
    public function testAnUnbrokenRunOfCharactersIsLeftInOnePiece(): void
    {
        $unbroken = str_repeat('a', 5000);

        $this->assertSame([$unbroken], TextChunker::split($unbroken, 1000));
    }

    /**
     * The same strings always plan into the same requests.
     *
     * A run carries on from the parts an earlier run stored, matched by part key. A plan that
     * came out differently the second time would leave those parts unusable and have them all
     * paid for again.
     *
     * @return  void
     *
     * @since   1.4.0
     */
    public function testThePlanForTheSameStringsIsAlwaysTheSame(): void
    {
        $strings = ['title' => 'Security headers', 'fulltext' => self::article(60)];

        $this->assertSame(TextChunker::plan($strings, 1000), TextChunker::plan($strings, 1000));
    }

    /**
     * Every part of an item belongs to exactly one request, and the requests hold them all.
     *
     * A run carries on by leaving out the requests whose parts it already has, matched by part
     * key. A key in two requests would be translated and paid for twice; a key in none would
     * never be translated, and the item could never finish.
     *
     * @return  void
     *
     * @since   1.4.0
     */
    public function testEveryPartBelongsToExactlyOneRequest(): void
    {
        $strings = ['title' => 'Headers', 'fulltext' => self::article(60), 'metadesc' => 'About headers.'];
        $planned = [];

        foreach (TextChunker::plan($strings, 1000) as $chunk) {
            foreach ($chunk as $partKey => $text) {
                $this->assertArrayNotHasKey($partKey, $planned, \sprintf('%s is in one request only', $partKey));

                $planned[$partKey] = $text;
            }
        }

        $this->assertSame(TextChunker::parts($strings, 1000), $planned);
        $this->assertSame($strings, TextChunker::assemble($planned), 'The planned parts are the item');
    }

    /**
     * A size below what the helper allows is raised to it, rather than asking for a request per
     * sentence.
     *
     * @return  void
     *
     * @since   1.4.0
     */
    public function testASizeBelowTheLeastIsRaisedToIt(): void
    {
        $strings = ['fulltext' => self::article(60)];

        $this->assertSame(
            TextChunker::plan($strings, TextChunker::MIN_TARGET_CHARS),
            TextChunker::plan($strings, 1),
            'A size of 1 plans as the smallest size allowed'
        );
    }

    /**
     * Short fields travel together, so a handful of them is not a handful of requests.
     *
     * @return  void
     *
     * @since   1.4.0
     */
    public function testShortFieldsShareARequest(): void
    {
        $chunks = TextChunker::plan(
            [
                'title'    => str_repeat('a', 400),
                'metadesc' => str_repeat('b', 400),
                'metakey'  => str_repeat('c', 400),
            ],
            1000
        );

        $this->assertCount(2, $chunks);
        $this->assertSame(['title', 'metadesc'], array_keys($chunks[0]));
        $this->assertSame(['metakey'], array_keys($chunks[1]));
    }

    /**
     * A cut value's parts are keyed by field and number, a value that fits by its field alone.
     *
     * @return  void
     *
     * @since   1.4.0
     */
    public function testACutValuesPartsAreNumberedUnderItsFieldName(): void
    {
        $parts = TextChunker::parts(['title' => 'Headers', 'fulltext' => self::article(60)], 1000);
        $keys  = array_keys($parts);

        $this->assertSame('title', $keys[0]);
        $this->assertSame('fulltext#1', $keys[1]);
        $this->assertSame('fulltext#2', $keys[2]);
        $this->assertSame('fulltext', TextChunker::fieldOf('fulltext#2'));
        $this->assertSame('title', TextChunker::fieldOf('title'));
    }

    /**
     * A field name that holds a '#' is not mistaken for a part of another field.
     *
     * @return  void
     *
     * @since   1.4.0
     */
    public function testAHashThatNumbersNothingBelongsToTheFieldName(): void
    {
        $this->assertSame('com_fields:tag#list', TextChunker::fieldOf('com_fields:tag#list'));
    }

    /**
     * Translated parts are put back in their numbered order, not the order they come in.
     *
     * The parts are read back out of storage, where their order is whatever the database and
     * JSON gave; joining them wrongly would shuffle an article's sections.
     *
     * @return  void
     *
     * @since   1.4.0
     */
    public function testPartsAreJoinedInTheirNumberedOrder(): void
    {
        $assembled = TextChunker::assemble(
            [
                'fulltext#10' => 'J',
                'fulltext#2'  => 'B',
                'title'       => 'Kopjes',
                'fulltext#1'  => 'A',
            ]
        );

        $this->assertSame(['fulltext' => 'ABJ', 'title' => 'Kopjes'], $assembled);
    }

    /**
     * The context a part is sent with names the item and the sections it is cut from.
     *
     * A request holding the middle of an article has no title and no first paragraph in it, so
     * without this it would be translating text with nothing to say what it is about.
     *
     * @return  void
     *
     * @since   1.4.0
     */
    public function testTheContextNamesTheTitleAndTheHeadings(): void
    {
        $context = TextChunker::documentContext(
            [
                'title'    => 'Security HTTP Headers',
                'fulltext' => '<h2>What are HTTP headers?</h2><p>Text.</p><h3>Referrer-Policy</h3><p>More.</p>',
            ]
        );

        $this->assertStringContainsString('Title: Security HTTP Headers', $context);
        $this->assertStringContainsString('- What are HTTP headers?', $context);
        $this->assertStringContainsString('- Referrer-Policy', $context);
    }

    /**
     * An item with nothing to say about itself sends no context rather than an empty heading.
     *
     * @return  void
     *
     * @since   1.4.0
     */
    public function testAnItemWithNoTitleAndNoHeadingsHasNoContext(): void
    {
        $this->assertSame('', TextChunker::documentContext(['introtext' => '<p>Just a paragraph.</p>']));
        $this->assertSame('', TextChunker::documentContext([]));
    }

    /**
     * The print of an item changes as soon as its text does.
     *
     * It is what tells a later run whether the parts stored for an item still belong to it. A
     * print that missed an edit would join new parts onto parts of the text before it.
     *
     * @return  void
     *
     * @since   1.4.0
     */
    public function testThePrintChangesWithTheText(): void
    {
        $strings = ['title' => 'Headers', 'introtext' => '<p>One.</p>'];

        $this->assertSame(TextChunker::fingerprint($strings), TextChunker::fingerprint($strings));
        $this->assertNotSame(
            TextChunker::fingerprint($strings),
            TextChunker::fingerprint(['title' => 'Headers', 'introtext' => '<p>Two.</p>'])
        );
        $this->assertNotSame(
            TextChunker::fingerprint($strings),
            TextChunker::fingerprint(['title' => 'Headers']),
            'A field that is no longer translatable changes the print too'
        );
    }

    /**
     * An article of the given number of sections, in the shape the guide's pages have.
     *
     * @param   integer  $sections  The number of sections.
     *
     * @return  string
     *
     * @since   1.4.0
     */
    private static function article(int $sections): string
    {
        $html = '';

        for ($i = 1; $i <= $sections; $i++) {
            $html .= \sprintf(
                '<h2>Section %1$d</h2><p>This is the <strong>text</strong> of section %1$d,'
                . ' written out at some length so the section is worth cutting at.</p>'
                . '<ul><li>First point of %1$d</li><li>Second point of %1$d</li></ul>',
                $i
            );
        }

        return $html;
    }
}
