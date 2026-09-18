/**
 * Support file for the end-to-end tests.
 *
 * joomla-cypress carries the commands for working a Joomla administrator - logging in,
 * the search tools, the toolbar - so the specs are about this package rather than about
 * how Joomla's list views are put together.
 *
 * @package     Joomla.Tests
 * @subpackage  com_translations
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

const { registerCommands } = require('joomla-cypress');

registerCommands();

// Joomla's guided tours plugin leaves a promise rejected on an administrator page it has
// no tour for, and Cypress fails the test it happens in. It is the CMS's own script and
// says nothing about this package, so it is passed over - by name, so that a script error
// coming out of com_translations still fails the spec it happens in, which is most of the
// value of running these at all.
Cypress.on('uncaught:exception', (error) => !String(error && error.stack).includes('guidedtours'));
