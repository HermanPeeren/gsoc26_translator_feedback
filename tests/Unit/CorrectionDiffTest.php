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

use Joomla\Component\Translations\Administrator\Model\DistillerModel;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * What a translator's correction is reduced to before it is distilled into rules.
 *
 * The distiller sends this text, not the paragraphs it came from, and the labels are what
 * tells the model whether it is looking at terminology or at phrasing. A diff that hands
 * back a whole paragraph turns a one-word correction into an expensive prompt about
 * nothing; a label that goes the wrong way files a terminology correction as a style rule,
 * where no later item will match it. Neither shows up as an error anywhere.
 *
 * @since  1.0.1
 */
final class CorrectionDiffTest extends TestCase
{
    /**
     * A one-word correction is reported as a term, with the paragraph around it left out.
     *
     * @return  void
     *
     * @since   1.0.1
     */
    public function testAOneWordCorrectionIsATerm(): void
    {
        $diff = self::diff(
            'Deze module staat in een positie op de pagina.',
            'Deze plugin staat in een positie op de pagina.'
        );

        $this->assertSame('[term] module → plugin', $diff);
    }

    /**
     * A correction that spans words is reported as a phrase, in one span rather than several.
     *
     * A reworded clause is one decision about tone. Splitting it at the spaces would offer
     * the distiller a handful of unrelated word swaps instead.
     *
     * @return  void
     *
     * @since   1.0.1
     */
    public function testARewordedClauseIsOnePhrase(): void
    {
        $diff = self::diff(
            'U kunt de module hier vinden.',
            'Je kunt het onderdeel hier vinden.'
        );

        $this->assertSame('[phrase] de module → het onderdeel', explode("\n", $diff)[1]);
        $this->assertSame('[term] U → Je', explode("\n", $diff)[0]);
    }

    /**
     * Text a translator added, and text a translator struck out, are reported as such.
     *
     * @return  void
     *
     * @since   1.0.1
     */
    public function testAddedAndRemovedTextAreLabelledAsSuch(): void
    {
        $this->assertSame(
            '[added] nieuwe',
            self::diff('De module staat klaar.', 'De nieuwe module staat klaar.')
        );

        $this->assertSame(
            '[removed] eigenlijk',
            self::diff('Dit is eigenlijk een module.', 'Dit is een module.')
        );
    }

    /**
     * A correction in one place is one span, not the paragraph it sits in.
     *
     * @return  void
     *
     * @since   1.0.1
     */
    public function testOnlyTheChangedSpansOfALongTextAreReported(): void
    {
        $before = str_repeat('Deze zin verandert niet. ', 20);

        $this->assertSame(
            '[term] module → onderdeel',
            self::diff($before . 'De module hier.', $before . 'De onderdeel hier.')
        );
    }

    /**
     * Several corrections in one field come back one to a line.
     *
     * @return  void
     *
     * @since   1.0.1
     */
    public function testSeveralCorrectionsComeBackOnePerLine(): void
    {
        $diff = self::diff(
            'De module is klaar. De plugin is klaar.',
            'Het onderdeel is klaar. De extensie is klaar.'
        );

        $this->assertSame(
            [
                '[phrase] De module → Het onderdeel',
                '[term] plugin → extensie',
            ],
            explode("\n", $diff)
        );
    }

    /**
     * Whitespace a translator did not mean to change is not a correction.
     *
     * An editor field comes back with its own idea of where to wrap a line. A token carries
     * the whitespace after it, so that alone makes the tokens differ - and the span it would
     * produce has the same words on both sides, which the distiller is then asked to find a
     * rule in.
     *
     * @return  void
     *
     * @since   1.0.1
     */
    public function testRewrappedWhitespaceIsNotACorrection(): void
    {
        $this->assertSame('', self::diff("De module\nstaat klaar.", 'De module   staat klaar.'));
        $this->assertSame('', self::diff('<p>Hallo</p>', "<p>Hallo</p>\n"));
    }

    /**
     * A draft a translator approved unchanged holds nothing to learn from.
     *
     * @return  void
     *
     * @since   1.0.1
     */
    public function testAnUncorrectedDraftDiffsToNothing(): void
    {
        $this->assertSame('', self::diff('De module staat klaar.', 'De module staat klaar.'));
        $this->assertSame('', self::diff('', ''));
    }

    /**
     * Reduce a correction to its labelled spans.
     *
     * The method is private because a diff is only ever made on the way to a prompt, and the
     * model around it needs a database to do anything else. It reads only its arguments, so
     * it is reached on an instance that was never constructed.
     *
     * @param   string  $machineDraft     The machine draft.
     * @param   string  $humanCorrection  The translator's correction.
     *
     * @return  string  The labelled spans, one per line.
     *
     * @since   1.0.1
     */
    private static function diff(string $machineDraft, string $humanCorrection): string
    {
        $model  = (new ReflectionClass(DistillerModel::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(DistillerModel::class, 'diff');
        $method->setAccessible(true);

        return $method->invoke($model, $machineDraft, $humanCorrection);
    }
}
