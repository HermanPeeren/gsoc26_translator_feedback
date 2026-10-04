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

use Joomla\Component\Translations\Administrator\Helper\RunResult;
use Joomla\Component\Translations\Administrator\Model\DistillerModel;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Whether a run of the seed or distil task asks to run again, and which feedback goes together.
 *
 * A task that resumes runs again within the minute, and every run pays a provider. A run that
 * resumes without having got anything done is how one failing batch was retried, and paid for,
 * for three days. These tests pin down that it no longer can.
 *
 * @since  1.1.0
 */
final class RunResultTest extends TestCase
{
    /**
     * A run that made progress, with work left, resumes.
     *
     * @return  void
     *
     * @since   1.1.0
     */
    public function testProgressWithWorkLeftResumes(): void
    {
        $this->assertSame(RunResult::RESUME, self::result(10, 0, 50)->outcome());
    }

    /**
     * A run that made progress resumes even when some of its items failed.
     *
     * The failed items have attempts left or are set aside, so resuming cannot loop on them.
     *
     * @return  void
     *
     * @since   1.1.0
     */
    public function testProgressWithSomeFailuresStillResumes(): void
    {
        $this->assertSame(RunResult::RESUME, self::result(8, 2, 50)->outcome());
    }

    /**
     * A run that got nothing done because everything it tried failed is an error, not a resume.
     *
     * @return  void
     *
     * @since   1.1.0
     */
    public function testFailureWithoutProgressIsAnError(): void
    {
        $this->assertSame(RunResult::ERROR, self::result(0, 5, 50)->outcome());
    }

    /**
     * A run that gave up on an unreachable provider is an error, even after some progress.
     *
     * @return  void
     *
     * @since   1.1.0
     */
    public function testAnAbortedRunIsAnError(): void
    {
        $result          = self::result(25, 50, 100);
        $result->aborted = true;

        $this->assertSame(RunResult::ERROR, $result->outcome());
    }

    /**
     * A run that finished the last of the work is done.
     *
     * @return  void
     *
     * @since   1.1.0
     */
    public function testProgressWithNothingLeftIsDone(): void
    {
        $this->assertSame(RunResult::DONE, self::result(7, 0, 0)->outcome());
    }

    /**
     * A run with nothing to do is done.
     *
     * @return  void
     *
     * @since   1.1.0
     */
    public function testNothingToDoIsDone(): void
    {
        $this->assertSame(RunResult::DONE, self::result(0, 0, 0)->outcome());
    }

    /**
     * An error message is cut to what the last_error columns hold.
     *
     * @return  void
     *
     * @since   1.1.0
     */
    public function testAnErrorMessageFitsItsColumn(): void
    {
        $this->assertSame(500, mb_strlen(RunResult::errorText(str_repeat('é', 800))));
    }

    /**
     * A request is full size at first, half the size for a second attempt, one item for the last.
     *
     * @return  void
     *
     * @since   1.1.0
     */
    public function testARetryGoesInASmallerRequest(): void
    {
        $this->assertSame(
            [50, 25, 1, 1],
            [
                RunResult::requestLimit(50, 0),
                RunResult::requestLimit(50, 1),
                RunResult::requestLimit(50, 2),
                RunResult::requestLimit(1, 1),
            ]
        );
    }

    /**
     * A feedback row on its last attempt goes alone; the others go together per language.
     *
     * @return  void
     *
     * @since   1.1.0
     */
    public function testAFeedbackRowOnItsLastAttemptIsSentAlone(): void
    {
        $batches = self::requestBatches(
            [
                self::row(1, 'nl-NL', 0),
                self::row(2, 'de-DE', 0),
                self::row(3, 'nl-NL', 2),
                self::row(4, 'nl-NL', 0),
            ],
            10
        );

        $this->assertSame([[3], [1, 4], [2]], self::ids($batches));
    }

    /**
     * Feedback rows on their second attempt go in requests of half the batch size.
     *
     * @return  void
     *
     * @since   1.1.0
     */
    public function testFeedbackRowsOnASecondAttemptGoInHalfSizeRequests(): void
    {
        $rows = [];

        for ($id = 1; $id <= 6; $id++) {
            $rows[] = self::row($id, 'nl-NL', 1);
        }

        $this->assertSame([[1, 2, 3, 4, 5], [6]], self::ids(self::requestBatches($rows, 10)));
    }

    /**
     * A result with the given counts.
     *
     * @param   integer  $processed  The items completed.
     * @param   integer  $failed     The items that failed.
     * @param   integer  $remaining  The items left.
     *
     * @return  RunResult  The result.
     *
     * @since   1.1.0
     */
    private static function result(int $processed, int $failed, int $remaining): RunResult
    {
        $result            = new RunResult();
        $result->processed = $processed;
        $result->failed    = $failed;
        $result->remaining = $remaining;

        return $result;
    }

    /**
     * A feedback row as the distiller loads it, with only the columns the split reads.
     *
     * @param   integer  $id        The row id.
     * @param   string   $language  The target language.
     * @param   integer  $attempts  The attempts made so far.
     *
     * @return  object  The row.
     *
     * @since   1.1.0
     */
    private static function row(int $id, string $language, int $attempts): object
    {
        return (object) ['id' => $id, 'target_language' => $language, 'attempts' => $attempts];
    }

    /**
     * Split feedback rows into the requests a distil run would send.
     *
     * The model needs a database to run, but the split reads only the rows it is given, so it
     * is reached on an instance that was never constructed.
     *
     * @param   object[]  $rows       The feedback rows.
     * @param   integer   $batchSize  The size of a request of untried rows.
     *
     * @return  array  The requests, each a list of rows.
     *
     * @since   1.1.0
     */
    private static function requestBatches(array $rows, int $batchSize): array
    {
        $model  = (new ReflectionClass(DistillerModel::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(DistillerModel::class, 'requestBatches');
        $method->setAccessible(true);

        return $method->invoke($model, $rows, $batchSize);
    }

    /**
     * The row ids of each request.
     *
     * @param   array  $batches  The requests, each a list of rows.
     *
     * @return  array  The row ids, per request.
     *
     * @since   1.1.0
     */
    private static function ids(array $batches): array
    {
        return array_map(static fn(array $rows): array => array_map(static fn(object $row): int => $row->id, $rows), $batches);
    }
}
