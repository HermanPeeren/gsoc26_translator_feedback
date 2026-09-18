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

use Joomla\Component\Translations\Administrator\Helper\WordNormaliser;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * The words an item's text is reduced to, and what a provider is allowed to answer.
 *
 * Asking a provider costs a call per batch, so which words are worth asking about is a
 * decision this package makes rather than something it delegates. What comes back is
 * unvalidated text from a language model, and it ends up in a table that later runs read
 * as fact, so the filter on the reply is the one place to keep it honest.
 *
 * @since  1.0.1
 */
final class WordNormaliserTest extends TestCase
{
    /**
     * Text is reduced to the distinct lower-cased words a rule could be written for.
     *
     * @return  void
     *
     * @since   1.0.1
     */
    public function testTokeniseKeepsTheDistinctWordsWorthReducing(): void
    {
        $words = WordNormaliser::tokenise('The Module, the module: a position (or two) at 4 o.k.');

        $this->assertSame(['the', 'module', 'position', 'two'], $words);
    }

    /**
     * A word is one word even when a hyphen or an apostrophe is in it.
     *
     * Splitting those would ask a provider about halves of words, which have no standard
     * form of their own.
     *
     * @return  void
     *
     * @since   1.0.1
     */
    public function testTokeniseKeepsHyphenatedAndContractedWordsWhole(): void
    {
        $this->assertSame(["e-mail", "it's"], WordNormaliser::tokenise("E-mail it's"));
    }

    /**
     * Text with nothing long enough to carry inflection yields no words.
     *
     * @return  void
     *
     * @since   1.0.1
     */
    public function testTokeniseOfNothingUsableIsEmpty(): void
    {
        $this->assertSame([], WordNormaliser::tokenise(''));
        $this->assertSame([], WordNormaliser::tokenise('... !? '));
        $this->assertSame([], WordNormaliser::tokenise('a an'));
    }

    /**
     * A standard form is defined for one word, so a phrase is not one.
     *
     * @param   string   $term      The term to test.
     * @param   boolean  $expected  Whether it counts as a single word.
     *
     * @return  void
     *
     * @dataProvider  terms
     *
     * @since   1.0.1
     */
    public function testSingleWordsAreToldFromPhrases(string $term, bool $expected): void
    {
        $this->assertSame($expected, WordNormaliser::isSingleWord($term));
    }

    /**
     * Terms and whether each is a single word.
     *
     * @return  array<string, array{0: string, 1: boolean}>
     *
     * @since   1.0.1
     */
    public static function terms(): array
    {
        return [
            'a word'                 => ['module', true],
            'padded with whitespace' => ["  module\n", true],
            'hyphenated'             => ['e-mail', true],
            'two words'              => ['menu item', false],
            'two words, much space'  => ["menu \t item", false],
            'empty'                  => ['', false],
            'whitespace only'        => ["  \n ", false],
        ];
    }

    /**
     * A provider's answer is filtered before it is stored and used for matching.
     *
     * The reply comes from a language model, so it is taken as a suggestion: only pairs for
     * words that were actually asked about are kept, and only when the answer is a single
     * word. Anything else would be written to the standard-form table, where every later run
     * reads it back as established fact.
     *
     * @return  void
     *
     * @since   1.0.1
     */
    public function testAProvidersAnswerIsKeptOnlyWhereItIsUsable(): void
    {
        $usable = self::usableResult(
            [
                'Artikelen'  => 'Artikel',
                'modules'    => 'module',
                'categorie'  => '',
                'menu-items' => 'menu item',
                'plugins'    => 'plugin',
            ],
            ['artikelen', 'modules', 'categorie', 'menu-items']
        );

        $this->assertSame(
            [
                // Both sides are folded, so the pair is stored under one spelling.
                'artikelen' => 'artikel',
                'modules'   => 'module',
            ],
            $usable
        );
    }

    /**
     * A provider that answers with nothing usable resolves nothing.
     *
     * @return  void
     *
     * @since   1.0.1
     */
    public function testAnEmptyAnswerResolvesNothing(): void
    {
        $this->assertSame([], self::usableResult([], ['artikelen']));
    }

    /**
     * Call the private filter on a provider's reply.
     *
     * It is private because nothing outside the class may ask for an unfiltered form, and
     * it is reached here directly because what it rejects is the point: driving it through
     * standardForms() would need a database and a dispatcher to say the same thing.
     *
     * @param   array  $result  The provider's reply.
     * @param   array  $words   The words the provider was given.
     *
     * @return  array  The pairs that survive the filter.
     *
     * @since   1.0.1
     */
    private static function usableResult(array $result, array $words): array
    {
        $method = new ReflectionMethod(WordNormaliser::class, 'usableResult');
        $method->setAccessible(true);

        return $method->invoke(null, $result, $words);
    }
}
