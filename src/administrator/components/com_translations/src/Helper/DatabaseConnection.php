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
 * Gets the database connection back after a provider kept the run waiting.
 *
 * The connection sits idle while a provider answers, and a database server closes a connection
 * that has been idle for longer than its wait_timeout. A request carrying a long part of an
 * article - a table, say - can take more than a minute to answer, which is longer than many
 * hosts allow, and the next query then fails with "MySQL server has gone away". Joomla's driver
 * does not recover from that: it prepares every query, and a prepare on a closed connection is
 * not retried. The translation that was just paid for would be lost with the run.
 *
 * A dropped connection also releases a RunLock taken on it, since that is a lock of the
 * connection. The run carries on without it; another run starting in that moment is the price,
 * against losing every answer that is still to be stored.
 *
 * @since  1.4.8
 */
final class DatabaseConnection
{
    /**
     * Reconnect when the server has closed the connection.
     *
     * @param   DatabaseInterface  $db  The database driver.
     *
     * @return  void
     *
     * @since   1.4.8
     */
    public static function reconnectIfDropped(DatabaseInterface $db): void
    {
        // Asking a closed connection whether it is still there can throw rather than answer no.
        try {
            if ($db->connected()) {
                return;
            }
        } catch (\Throwable $e) {
            // Dropped: reconnect below.
        }

        // Closing a connection the server already closed can throw too; the driver forgets it either way.
        try {
            $db->disconnect();
        } catch (\Throwable $e) {
            // Nothing left to close.
        }

        $db->connect();
    }
}
