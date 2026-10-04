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
 * What one run of a resumable step did, and whether it should run again straight away.
 *
 * Every request to a provider is paid for, so a run that got nothing done must not ask to be
 * resumed: resuming a batch that fails every time is what turns one bad batch into days of
 * paid retries. A run therefore asks to resume only when it made progress and work is left.
 *
 * An item that fails is tried again in a smaller request until it has had MAX_ATTEMPTS; then it
 * is set aside as failed and no run picks it up again.
 *
 * @since  1.1.0
 */
final class RunResult
{
    /**
     * The attempts an item gets before it is set aside as failed.
     *
     * @var    integer
     * @since  1.1.0
     */
    public const MAX_ATTEMPTS = 3;

    /**
     * The run made progress and work is left, so it should run again as soon as possible.
     *
     * @var    string
     * @since  1.1.0
     */
    public const RESUME = 'resume';

    /**
     * Nothing is left that this run could do.
     *
     * @var    string
     * @since  1.1.0
     */
    public const DONE = 'done';

    /**
     * The run failed without getting anything done, or gave up on an unreachable provider.
     *
     * @var    string
     * @since  1.1.0
     */
    public const ERROR = 'error';

    /**
     * The items completed in this run.
     *
     * @var    integer
     * @since  1.1.0
     */
    public $processed = 0;

    /**
     * The items whose attempt failed in this run, including the ones set aside.
     *
     * @var    integer
     * @since  1.1.0
     */
    public $failed = 0;

    /**
     * The items set aside as failed in this run, because they used their last attempt.
     *
     * @var    integer
     * @since  1.1.0
     */
    public $quarantined = 0;

    /**
     * The items still waiting to be processed after this run.
     *
     * @var    integer
     * @since  1.1.0
     */
    public $remaining = 0;

    /**
     * Whether the run stopped early because the provider failed request after request.
     *
     * @var    boolean
     * @since  1.1.0
     */
    public $aborted = false;

    /**
     * The last error a failed item gave, for the log.
     *
     * @var    string
     * @since  1.1.0
     */
    public $lastError = '';

    /**
     * Decide what the run should report: resume, done or error.
     *
     * @return  string  One of RESUME, DONE or ERROR.
     *
     * @since   1.1.0
     */
    public function outcome(): string
    {
        if ($this->aborted || ($this->processed === 0 && $this->failed > 0)) {
            return self::ERROR;
        }

        if ($this->processed > 0 && $this->remaining > 0) {
            return self::RESUME;
        }

        return self::DONE;
    }

    /**
     * The most items in one request, for items that have had the given number of attempts.
     *
     * An item that failed before goes in a smaller request: half the size on its second attempt,
     * alone on its last. A request that fails is so narrowed down to the item that causes it,
     * without paying for a request per item as soon as one request fails.
     *
     * @param   integer  $size      The size of a first request.
     * @param   integer  $attempts  The attempts the items have had.
     *
     * @return  integer  The full size for untried items, half for a second attempt, one after that.
     *
     * @since   1.1.0
     */
    public static function requestLimit(int $size, int $attempts): int
    {
        if ($attempts <= 0) {
            return max(1, $size);
        }

        return $attempts === 1 ? max(1, intdiv($size, 2)) : 1;
    }

    /**
     * Shorten an error message to what the last_error columns hold.
     *
     * @param   string  $message  The error message.
     *
     * @return  string  The message, at most 500 characters.
     *
     * @since   1.1.0
     */
    public static function errorText(string $message): string
    {
        return mb_substr(trim($message), 0, 500);
    }
}
