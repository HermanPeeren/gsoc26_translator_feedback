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
use PHPUnit\Framework\TestCase;

/**
 * Whether a run of the seed or distil task asks to run again, and how large a retry is.
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
     * A run that translated parts of a long item, finishing none, still resumes.
     *
     * An item too long for one request takes more runs than there are items in the batch, and
     * every part of it that came back was paid for. A run that reported itself done here would
     * leave the item half translated until something else happened to start the task again.
     *
     * @return  void
     *
     * @since   1.4.0
     */
    public function testPartsTranslatedWithoutFinishingAnItemResume(): void
    {
        $result            = self::result(0, 0, 1);
        $result->advanced  = 3;

        $this->assertSame(RunResult::RESUME, $result->outcome());
    }

    /**
     * A part that came back saves a run whose item then failed from counting as a dead run.
     *
     * @return  void
     *
     * @since   1.4.0
     */
    public function testAFailureAfterPartsTranslatedIsNotAnError(): void
    {
        $result           = self::result(0, 1, 1);
        $result->advanced = 2;

        $this->assertSame(RunResult::RESUME, $result->outcome());
    }

    /**
     * A run that got no part and no item done is still an error, however it failed.
     *
     * This is the guarantee the whole class exists for: without it a provider that fails every
     * request is asked again within the minute, run after run, each one paid for.
     *
     * @return  void
     *
     * @since   1.4.0
     */
    public function testNoPartAndNoItemWithAFailureIsStillAnError(): void
    {
        $result           = self::result(0, 1, 1);
        $result->advanced = 0;

        $this->assertSame(RunResult::ERROR, $result->outcome());
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
}
