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

use Joomla\Component\Translations\Administrator\Helper\RunLock;
use PHPUnit\Framework\TestCase;

/**
 * The name of the database lock that keeps two runs of one job apart.
 *
 * The lock is held by the database server, which may serve several sites, and MySQL refuses a
 * lock name longer than 64 characters. A name that differed between two runs of the same job
 * would let both run and pay for the same requests twice.
 *
 * @since  1.2.1
 */
final class RunLockTest extends TestCase
{
    /**
     * Every run of a job on a site uses the same lock.
     *
     * @return  void
     *
     * @since   1.2.1
     */
    public function testRunsOfOneJobShareTheirLock(): void
    {
        $this->assertSame(RunLock::name('jos_', 'distil'), RunLock::name('jos_', 'distil'));
    }

    /**
     * Different jobs, and the same job on different sites, have different locks.
     *
     * @return  void
     *
     * @since   1.2.1
     */
    public function testJobsAndSitesHaveTheirOwnLocks(): void
    {
        $this->assertNotSame(RunLock::name('jos_', 'seed nl-NL'), RunLock::name('jos_', 'seed de-DE'));
        $this->assertNotSame(RunLock::name('jos_', 'distil'), RunLock::name('abc_', 'distil'));
    }

    /**
     * A lock name fits MySQL's limit of 64 characters, whatever the prefix and the job.
     *
     * @return  void
     *
     * @since   1.2.1
     */
    public function testALockNameFitsTheServersLimit(): void
    {
        $this->assertLessThanOrEqual(64, \strlen(RunLock::name(str_repeat('p', 100), str_repeat('job ', 50))));
    }
}
