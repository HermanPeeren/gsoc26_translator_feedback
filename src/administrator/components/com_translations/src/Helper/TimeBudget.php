<?php

/**
 * @package     Joomla.Administrator
 * @subpackage  com_translations
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Component\Translations\Administrator\Helper;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * How long one run of a resumable step may keep sending requests.
 *
 * A run sends requests one after another until the next one would probably not fit in its
 * budget, judged by how long the requests so far took. The first request always goes, so every
 * run makes progress. A run from the command line, where PHP has no time limit, gets a few
 * minutes - less than the five minutes after which the scheduler would start a second copy of a
 * task that still holds its lock. A run over the web gets less than the time a web server or
 * proxy commonly allows a request.
 *
 * @since  1.2.0
 */
final class TimeBudget
{
    /**
     * Seconds a command-line run may take when no budget is set.
     *
     * @var    integer
     * @since  1.2.0
     */
    public const CLI_SECONDS = 240;

    /**
     * Seconds a web run may take when no budget is set.
     *
     * @var    integer
     * @since  1.2.0
     */
    public const WEB_SECONDS = 25;

    /**
     * The seconds this run may take.
     *
     * @var    integer
     * @since  1.2.0
     */
    private $seconds;

    /**
     * Returns the current time in seconds.
     *
     * @var    callable
     * @since  1.2.0
     */
    private $clock;

    /**
     * When the run started.
     *
     * @var    float
     * @since  1.2.0
     */
    private $startedAt;

    /**
     * When the request now under way started, or null when none is.
     *
     * @var    float|null
     * @since  1.2.0
     */
    private $requestStartedAt;

    /**
     * How long each finished request took, in seconds.
     *
     * @var    float[]
     * @since  1.2.0
     */
    private $durations = [];

    /**
     * Constructor.
     *
     * @param   integer        $seconds  The seconds the run may take, 0 for the default of where it runs.
     * @param   callable|null  $clock    Returns the current time in seconds; microtime when null.
     *
     * @since   1.2.0
     */
    public function __construct(int $seconds = 0, ?callable $clock = null)
    {
        $this->seconds   = $seconds > 0 ? $seconds : (PHP_SAPI === 'cli' ? self::CLI_SECONDS : self::WEB_SECONDS);
        $this->clock     = $clock ?? static fn(): float => microtime(true);
        $this->startedAt = ($this->clock)();
    }

    /**
     * The seconds this run may take.
     *
     * @return  integer
     *
     * @since   1.2.0
     */
    public function seconds(): int
    {
        return $this->seconds;
    }

    /**
     * Whether another request is likely to finish within the budget.
     *
     * @return  boolean  True for the first request, and afterwards while the time left is at least
     *                   the average time a request took.
     *
     * @since   1.2.0
     */
    public function allowsAnother(): bool
    {
        if ($this->durations === []) {
            return true;
        }

        $left    = $this->seconds - (($this->clock)() - $this->startedAt);
        $average = array_sum($this->durations) / \count($this->durations);

        return $left >= $average;
    }

    /**
     * Mark the start of a request.
     *
     * @return  void
     *
     * @since   1.2.0
     */
    public function startRequest(): void
    {
        $this->requestStartedAt = ($this->clock)();
    }

    /**
     * Mark the end of the request under way, answered or not.
     *
     * @return  void
     *
     * @since   1.2.0
     */
    public function endRequest(): void
    {
        if ($this->requestStartedAt !== null) {
            $this->durations[]      = ($this->clock)() - $this->requestStartedAt;
            $this->requestStartedAt = null;
        }
    }
}
