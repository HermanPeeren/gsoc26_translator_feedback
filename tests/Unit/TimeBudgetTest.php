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

use Joomla\Component\Translations\Administrator\Helper\TimeBudget;
use PHPUnit\Framework\TestCase;

/**
 * How long a seed or distil run keeps sending requests.
 *
 * A run that keeps going past its budget is cut off by the web server, or overlaps the next
 * run the scheduler starts; one that stops too early wastes the minute until the next run.
 *
 * @since  1.2.0
 */
final class TimeBudgetTest extends TestCase
{
    /**
     * The current time of the fake clock, in seconds.
     *
     * @var    float
     * @since  1.2.0
     */
    private $now = 1000.0;

    /**
     * The first request always goes, whatever the budget, so every run makes progress.
     *
     * @return  void
     *
     * @since   1.2.0
     */
    public function testTheFirstRequestAlwaysGoes(): void
    {
        $this->assertTrue($this->budget(1)->allowsAnother());
    }

    /**
     * Another request goes while the time left is at least what a request took on average.
     *
     * @return  void
     *
     * @since   1.2.0
     */
    public function testAnotherRequestGoesWhileItFits(): void
    {
        $budget = $this->budget(60);

        $this->request($budget, 20);
        $this->assertTrue($budget->allowsAnother(), '40 s left, requests take 20 s');

        $this->request($budget, 20);
        $this->assertTrue($budget->allowsAnother(), '20 s left, requests take 20 s');

        $this->request($budget, 20);
        $this->assertFalse($budget->allowsAnother(), 'No time left');
    }

    /**
     * A slow request stops the run early rather than letting the next one overrun.
     *
     * @return  void
     *
     * @since   1.2.0
     */
    public function testASlowRequestStopsTheRun(): void
    {
        $budget = $this->budget(25);

        $this->request($budget, 15);

        $this->assertFalse($budget->allowsAnother(), '10 s left, a request takes 15 s');
    }

    /**
     * Without a budget of its own a run gets the default for where it runs; tests run from the
     * command line.
     *
     * @return  void
     *
     * @since   1.2.0
     */
    public function testNoBudgetMeansTheDefaultForWhereItRuns(): void
    {
        $this->assertSame(TimeBudget::CLI_SECONDS, (new TimeBudget(0))->seconds());
        $this->assertSame(90, (new TimeBudget(90))->seconds());
    }

    /**
     * A budget on the fake clock.
     *
     * @param   integer  $seconds  The seconds the run may take.
     *
     * @return  TimeBudget
     *
     * @since   1.2.0
     */
    private function budget(int $seconds): TimeBudget
    {
        return new TimeBudget($seconds, fn(): float => $this->now);
    }

    /**
     * Pass a request of the given length on the fake clock.
     *
     * @param   TimeBudget  $budget   The budget.
     * @param   integer     $seconds  How long the request takes.
     *
     * @return  void
     *
     * @since   1.2.0
     */
    private function request(TimeBudget $budget, int $seconds): void
    {
        $budget->startRequest();
        $this->now += $seconds;
        $budget->endRequest();
    }
}
