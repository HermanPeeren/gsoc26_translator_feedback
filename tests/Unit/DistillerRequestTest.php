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

use Joomla\Component\Translations\Administrator\Helper\RuleMerger;
use Joomla\Component\Translations\Administrator\Model\DistillerModel;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * What the distiller puts in one request, and which rules it treats as the same.
 *
 * Every request carries about ten thousand tokens of context, so a request with few
 * corrections pays mostly for that; one with too many, or with whole articles in it, runs
 * slow and long. A rule written twice spends prompt on every later translation.
 *
 * @since  1.2.0
 */
final class DistillerRequestTest extends TestCase
{
    /**
     * Untried rows fill a request up to the request size.
     *
     * @return  void
     *
     * @since   1.2.0
     */
    public function testUntriedRowsFillARequest(): void
    {
        $rows = [];

        for ($id = 1; $id <= 60; $id++) {
            $rows[] = self::row($id, 'nl-NL', 0);
        }

        $this->assertCount(50, self::selectRequest($rows, 50));
    }

    /**
     * A request holds one target language and one attempt level.
     *
     * @return  void
     *
     * @since   1.2.0
     */
    public function testARequestHoldsOneLanguageAndOneAttemptLevel(): void
    {
        $rows = [
            self::row(1, 'de-DE', 1),
            self::row(2, 'de-DE', 0),
            self::row(3, 'nl-NL', 1),
            self::row(4, 'de-DE', 1),
        ];

        $this->assertSame([1, 4], self::ids(self::selectRequest($rows, 50)));
    }

    /**
     * A retry goes in half the request size, and a last attempt alone.
     *
     * @return  void
     *
     * @since   1.2.0
     */
    public function testARetryGoesInASmallerRequest(): void
    {
        $second = [];
        $last   = [];

        for ($id = 1; $id <= 40; $id++) {
            $second[] = self::row($id, 'nl-NL', 1);
            $last[]   = self::row($id, 'nl-NL', 2);
        }

        $this->assertCount(25, self::selectRequest($second, 50));
        $this->assertCount(1, self::selectRequest($last, 50));
    }

    /**
     * Long rows fill a request by size before they fill it by count, and a row larger than the
     * whole budget still goes, on its own.
     *
     * @return  void
     *
     * @since   1.2.0
     */
    public function testLongRowsGoInFewerPerRequest(): void
    {
        $text = str_repeat('Lorem ipsum dolor sit amet. ', 50);
        $rows = [];

        for ($id = 1; $id <= 10; $id++) {
            $rows[] = self::row($id, 'nl-NL', 0, $text);
        }

        $this->assertLessThan(10, \count(self::selectRequest($rows, 50)));

        $huge = [self::row(1, 'nl-NL', 0, str_repeat('Lorem ipsum. ', 5000)), self::row(2, 'nl-NL', 0)];

        $this->assertSame([1], self::ids(self::selectRequest($huge, 50)));
    }

    /**
     * Nothing pending is no request.
     *
     * @return  void
     *
     * @since   1.2.0
     */
    public function testNothingPendingIsNoRequest(): void
    {
        $this->assertSame([], self::selectRequest([], 50));
    }

    /**
     * A long text is cut down to the blocks that changed, with the source block in the same place.
     *
     * @return  void
     *
     * @since   1.2.0
     */
    public function testALongTextIsSentAsTheBlocksThatChanged(): void
    {
        $filler     = str_repeat('Lorem ipsum dolor sit amet. ', 30);
        $source     = '<p>' . $filler . '</p><p>Save the article.</p><p>' . $filler . '</p>';
        $draft      = '<p>' . $filler . '</p><p>Sla het bericht op.</p><p>' . $filler . '</p>';
        $correction = '<p>' . $filler . '</p><p>Sla het artikel op.</p><p>' . $filler . '</p>';

        $this->assertSame(
            ['<p>Save the article.</p>', '<p>Sla het bericht op.</p>', '<p>Sla het artikel op.</p>'],
            self::excerpts($source, $draft, $correction)
        );
    }

    /**
     * A short text, or one whose blocks do not line up, is sent whole.
     *
     * @return  void
     *
     * @since   1.2.0
     */
    public function testAShortOrUnalignedTextIsSentWhole(): void
    {
        $this->assertSame(['Save', 'Opslaan', 'Bewaren'], self::excerpts('Save', 'Opslaan', 'Bewaren'));

        $filler = str_repeat('Lorem ipsum dolor sit amet. ', 60);
        $whole  = ['<p>' . $filler . '</p><p>A</p>', '<p>' . $filler . '</p>', '<p>' . $filler . '</p><p>B</p>'];

        $this->assertSame($whole, self::excerpts(...$whole));
    }

    /**
     * Two terminology rules with the same term and translation are the same rule, whatever the case.
     *
     * @return  void
     *
     * @since   1.2.0
     */
    public function testTheSameTermAndTranslationAreTheSameRule(): void
    {
        $this->assertSame(
            RuleMerger::key(self::rule('terminology', 'Article', 'article', 'artikel')),
            RuleMerger::key(self::rule('terminology', 'articles', 'article', 'Artikel '))
        );
    }

    /**
     * A different translation, language or type is a different rule, and a style rule is never merged.
     *
     * @return  void
     *
     * @since   1.2.0
     */
    public function testADifferentTranslationIsADifferentRule(): void
    {
        $key = RuleMerger::key(self::rule('terminology', 'toggle', null, 'schakelen'));

        $this->assertNotSame($key, RuleMerger::key(self::rule('terminology', 'toggle', null, 'omschakelen')));
        $this->assertNotSame($key, RuleMerger::key(self::rule('preservation', 'toggle', null, 'schakelen')));
        $this->assertNotSame($key, RuleMerger::key(['target_language' => 'de-DE'] + self::rule('terminology', 'toggle', null, 'schakelen')));
        $this->assertNull(RuleMerger::key(self::rule('style', 'toggle', null, 'schakelen')));
        $this->assertNull(RuleMerger::key(self::rule('terminology', '', null, 'x')));
    }

    /**
     * A feedback row with only the columns the selection reads.
     *
     * @param   integer  $id        The row id.
     * @param   string   $language  The target language.
     * @param   integer  $attempts  The attempts made so far.
     * @param   string   $text      The text in each of the three columns.
     *
     * @return  object  The row.
     *
     * @since   1.2.0
     */
    private static function row(int $id, string $language, int $attempts, string $text = 'Save'): object
    {
        return (object) [
            'id'               => $id,
            'target_language'  => $language,
            'attempts'         => $attempts,
            'source_text'      => $text,
            'machine_draft'    => $text,
            'human_correction' => $text,
        ];
    }

    /**
     * A rule with the columns its key is made of.
     *
     * @param   string       $type      The rule type.
     * @param   string       $term      The source term.
     * @param   string|null  $standard  The standard form of the term.
     * @param   string       $target    The target term.
     *
     * @return  array  The rule.
     *
     * @since   1.2.0
     */
    private static function rule(string $type, string $term, ?string $standard, string $target): array
    {
        return [
            'target_language'      => 'nl-NL',
            'rule_type'            => $type,
            'source_term'          => $term,
            'source_term_standard' => $standard,
            'target_term'          => $target,
        ];
    }

    /**
     * The ids of the given rows.
     *
     * @param   object[]  $rows  The rows.
     *
     * @return  int[]  The ids.
     *
     * @since   1.2.0
     */
    private static function ids(array $rows): array
    {
        return array_map(static fn(object $row): int => $row->id, $rows);
    }

    /**
     * Choose the rows of the next request, as a distil run would.
     *
     * @param   object[]  $rows         The candidate rows.
     * @param   integer   $requestSize  The most corrections in one request.
     *
     * @return  object[]  The rows of the request.
     *
     * @since   1.2.0
     */
    private static function selectRequest(array $rows, int $requestSize): array
    {
        return self::callModel('selectRequest', [$rows, $requestSize]);
    }

    /**
     * Cut a correction down to the parts around its changes, as a distil run would.
     *
     * @param   string  $source      The source text.
     * @param   string  $draft       The machine draft.
     * @param   string  $correction  The human correction.
     *
     * @return  string[]  The source, draft and correction to send.
     *
     * @since   1.2.0
     */
    private static function excerpts(string $source, string $draft, string $correction): array
    {
        return self::callModel('excerpts', [$source, $draft, $correction]);
    }

    /**
     * Call one of the model's own static methods; they read only their arguments.
     *
     * @param   string  $method     The method to call.
     * @param   array   $arguments  The arguments.
     *
     * @return  mixed  Whatever the method returns.
     *
     * @since   1.2.0
     */
    private static function callModel(string $method, array $arguments)
    {
        $reflection = new ReflectionMethod(DistillerModel::class, $method);
        $reflection->setAccessible(true);

        return $reflection->invokeArgs(null, $arguments);
    }
}
