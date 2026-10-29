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

use Joomla\Component\Translations\Administrator\Helper\DraftAlias;
use PHPUnit\Framework\TestCase;

/**
 * The alias a translated draft is saved under.
 *
 * An alias a neighbour already holds is refused by the managing component, and that refusal
 * throws away a translation that has been paid for. Languages run into each other here: Joomla
 * scopes an alias by an item's neighbours and not by its language, so the more languages a site
 * has, the more often two of them come down to the same alias.
 *
 * @since  1.4.2
 */
final class DraftAliasTest extends TestCase
{
    /**
     * An alias no neighbour holds is the one the title gave it.
     *
     * @return  void
     *
     * @since   1.4.2
     */
    public function testAFreeAliasIsKept(): void
    {
        $this->assertSame('referencia', DraftAlias::pick('referencia', 'pt-PT', ['tutorial', 'resumo']));
    }

    /**
     * Nothing to clash with leaves the alias alone.
     *
     * @return  void
     *
     * @since   1.4.2
     */
    public function testNoNeighboursLeavesTheAliasAlone(): void
    {
        $this->assertSame('referencia', DraftAlias::pick('referencia', 'pt-PT', []));
    }

    /**
     * The language is appended where another language gets to the same alias first.
     *
     * Spanish "Referencia" and Portuguese "Referência" both come down to `referencia`, and the
     * one translated second is the one that has to move.
     *
     * @return  void
     *
     * @since   1.4.2
     */
    public function testTheLanguageIsAppendedToAHeldAlias(): void
    {
        $this->assertSame('referencia-pt-pt', DraftAlias::pick('referencia', 'pt-PT', ['referencia']));
    }

    /**
     * The alias is numbered where the language is not enough to tell it apart.
     *
     * @return  void
     *
     * @since   1.4.2
     */
    public function testANumberFollowsWhenTheLanguageIsHeldToo(): void
    {
        $this->assertSame(
            'referencia-pt-pt-2',
            DraftAlias::pick('referencia', 'pt-PT', ['referencia', 'referencia-pt-pt'])
        );

        $this->assertSame(
            'referencia-pt-pt-3',
            DraftAlias::pick('referencia', 'pt-PT', ['referencia', 'referencia-pt-pt', 'referencia-pt-pt-2'])
        );
    }

    /**
     * A free alias is reached however many neighbours hold one.
     *
     * The numbering walks up from the aliases that are held, so a long run of them cannot end
     * in an alias that is taken after all.
     *
     * @return  void
     *
     * @since   1.4.2
     */
    public function testAFreeAliasIsReachedPastALongRun(): void
    {
        $taken = ['referencia', 'referencia-pt-pt'];

        for ($number = 2; $number <= 50; $number++) {
            $taken[] = 'referencia-pt-pt-' . $number;
        }

        $this->assertSame('referencia-pt-pt-51', DraftAlias::pick('referencia', 'pt-PT', $taken));
    }

    /**
     * Case does not decide whether an alias is free.
     *
     * Joomla compares an alias in the database, where the collation ignores case, so an alias
     * that differs only in case is not free.
     *
     * @return  void
     *
     * @since   1.4.2
     */
    public function testCaseDoesNotMakeAnAliasFree(): void
    {
        $this->assertSame('referencia-pt-pt', DraftAlias::pick('referencia', 'pt-PT', ['Referencia']));
    }

    /**
     * The appended language is written the way an alias is.
     *
     * @return  void
     *
     * @since   1.4.2
     */
    public function testTheAppendedLanguageIsLowercase(): void
    {
        $this->assertSame('tutorial-fa-ir', DraftAlias::pick('tutorial', 'fa-IR', ['tutorial']));
    }

    /**
     * The alias fallen back on names the item the draft was translated from.
     *
     * Two drafts reach it only when they were translated from the same item into the same
     * language, which is the one thing the queue does not ask for twice.
     *
     * @return  void
     *
     * @since   1.4.3
     */
    public function testTheAliasSteppedAsideToNamesTheSourceItem(): void
    {
        $this->assertSame('referencia-pt-pt-587', DraftAlias::stepAside('referencia', 'pt-PT', 587));
    }

    /**
     * Stepping aside reaches past the aliases picking would have produced.
     *
     * It is used when the component refused an alias that was picked as free, so landing on one
     * that picking could also have produced would ask the same question twice.
     *
     * @return  void
     *
     * @since   1.4.3
     */
    public function testSteppingAsideLandsPastThePickedAliases(): void
    {
        $aside = DraftAlias::stepAside('referencia', 'pt-PT', 587);
        $taken = ['referencia', 'referencia-pt-pt'];

        for ($number = 2; $number <= 20; $number++) {
            $taken[] = 'referencia-pt-pt-' . $number;
        }

        $this->assertNotContains($aside, $taken);
        $this->assertNotSame($aside, DraftAlias::pick('referencia', 'pt-PT', $taken));
    }

    /**
     * An alias is cut back to a length the database will take.
     *
     * On guide.joomla.org an article held its body text in its title, so the alias built from it
     * ran past the 400 characters the column has and the save was refused - after the translation
     * had been paid for. Joomla builds an alias out of the whole title without looking, so this is
     * the component's to get right.
     *
     * @return  void
     *
     * @since   1.4.4
     */
    public function testALongAliasIsCutBack(): void
    {
        $base  = rtrim(str_repeat('problema-de-margem-na-imagem-', 20), '-');
        $alias = DraftAlias::pick($base, 'pt-PT', []);

        $this->assertLessThanOrEqual(DraftAlias::MAX_LENGTH, mb_strlen($alias));
        $this->assertStringStartsWith('problema-de-margem-na-imagem', $alias);
    }

    /**
     * The cut lands on a word, not halfway through one.
     *
     * @return  void
     *
     * @since   1.4.4
     */
    public function testTheCutLandsOnAWord(): void
    {
        $alias = DraftAlias::pick(str_repeat('abcdefgh-', 30), 'pt-PT', []);

        $this->assertStringEndsWith('abcdefgh', $alias, 'the alias ends where a word does');
        $this->assertStringEndsNotWith('-', $alias);
        $this->assertLessThanOrEqual(DraftAlias::MAX_LENGTH, mb_strlen($alias));
    }

    /**
     * A first word longer than the limit is cut where the limit falls.
     *
     * There is no word boundary to land on, and an alias that cannot be stored is worse than one
     * that stops mid-word.
     *
     * @return  void
     *
     * @since   1.4.4
     */
    public function testAWordLongerThanTheLimitIsStillCut(): void
    {
        $alias = DraftAlias::pick(str_repeat('a', 300), 'pt-PT', []);

        $this->assertSame(DraftAlias::MAX_LENGTH, mb_strlen($alias));
    }

    /**
     * Stepping aside is cut back too, so the second attempt is storable as well.
     *
     * @return  void
     *
     * @since   1.4.4
     */
    public function testSteppingAsideIsCutBackAsWell(): void
    {
        $aside = DraftAlias::stepAside(str_repeat('margem-', 60), 'pt-PT', 375);

        $this->assertLessThanOrEqual(DraftAlias::MAX_LENGTH + 20, mb_strlen($aside));
        $this->assertStringEndsWith('-pt-pt-375', $aside);
    }

    /**
     * A short alias is left exactly as it is.
     *
     * @return  void
     *
     * @since   1.4.4
     */
    public function testAShortAliasIsUntouched(): void
    {
        $this->assertSame('referencia', DraftAlias::pick('referencia', 'pt-PT', []));
    }

    /**
     * An alias that only starts the same is a neighbour like any other, not a clash.
     *
     * @return  void
     *
     * @since   1.4.2
     */
    public function testAnAliasThatOnlyStartsTheSameIsNotAClash(): void
    {
        $this->assertSame('tutorial', DraftAlias::pick('tutorial', 'it-IT', ['tutorial-it-it', 'tutorials']));
    }
}
