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

use Joomla\Database\DatabaseInterface;

/**
 * Makes sure only one run of a job works at a time.
 *
 * Joomla's scheduler does not guarantee that: from the command line, scheduler:run fetches a task
 * by its id and locks it without checking whether it is already locked, so a second process can
 * start a task that is still running. Two seed or distil runs at once would send the same items
 * and pay for them twice. A run therefore takes a lock of its own first, and does nothing when
 * another run holds it.
 *
 * The lock is a named lock of the database server. The server releases it when the connection
 * ends, so a run that is killed halfway can never leave a lock behind.
 *
 * @since  1.2.1
 */
final class RunLock
{
    /**
     * The database driver the lock was taken on.
     *
     * @var    DatabaseInterface
     * @since  1.2.1
     */
    private $db;

    /**
     * The name of the lock on the database server.
     *
     * @var    string
     * @since  1.2.1
     */
    private $name;

    /**
     * Whether this run holds the lock.
     *
     * @var    boolean
     * @since  1.2.1
     */
    private $held = false;

    /**
     * Constructor.
     *
     * @param   DatabaseInterface  $db   The database driver.
     * @param   string             $job  The job to lock, such as "distil" or "seed nl-NL".
     *
     * @since   1.2.1
     */
    public function __construct(DatabaseInterface $db, string $job)
    {
        $this->db = $db;

        // A database server can serve several sites, so the name includes this site's tables.
        $this->name = self::name($db->getPrefix(), $job);
    }

    /**
     * The name of a job's lock: short enough for any server, and the same for every run.
     *
     * @param   string  $prefix  The site's table prefix.
     * @param   string  $job     The job.
     *
     * @return  string  The lock name.
     *
     * @since   1.2.1
     */
    public static function name(string $prefix, string $job): string
    {
        return 'com_translations_' . substr(sha1($prefix . "\x1F" . $job), 0, 40);
    }

    /**
     * Take the lock if no other run holds it, without waiting.
     *
     * @return  boolean  True when this run now holds the lock.
     *
     * @since   1.2.1
     */
    public function acquire(): bool
    {
        switch ($this->db->getServerType()) {
            case 'mysql':
                $query = 'SELECT GET_LOCK(' . $this->db->quote($this->name) . ', 0)';
                break;

            case 'postgresql':
                $query = 'SELECT pg_try_advisory_lock(hashtext(' . $this->db->quote($this->name) . '))';
                break;

            default:
                // A server without named locks gets no guard, as before this helper existed.
                $this->held = true;

                return true;
        }

        $result     = $this->db->setQuery($query)->loadResult();
        $this->held = $result === true || $result === 1 || $result === '1' || $result === 't';

        return $this->held;
    }

    /**
     * Release the lock, if this run holds it.
     *
     * @return  void
     *
     * @since   1.2.1
     */
    public function release(): void
    {
        if (!$this->held) {
            return;
        }

        $this->held = false;

        switch ($this->db->getServerType()) {
            case 'mysql':
                $query = 'SELECT RELEASE_LOCK(' . $this->db->quote($this->name) . ')';
                break;

            case 'postgresql':
                $query = 'SELECT pg_advisory_unlock(hashtext(' . $this->db->quote($this->name) . '))';
                break;

            default:
                return;
        }

        $this->db->setQuery($query)->loadResult();
    }
}
