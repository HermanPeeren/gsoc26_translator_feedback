/**
 * Cypress configuration for the end-to-end tests.
 *
 * Copy this file to cypress.config.js and fill in the site you want to test against. That
 * copy is git-ignored, because it holds the address and the Super User of somebody's own
 * test site and neither belongs in the repository.
 *
 * The site has to have the package installed - build/build.php produces it - with a
 * content language for at least one target language, so there is something to write a rule
 * for.
 *
 * @package     Joomla.Tests
 * @subpackage  com_translations
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

const { defineConfig } = require('cypress');

module.exports = defineConfig({
  e2e: {
    baseUrl: 'http://localhost/gsoc26_translator_feedback/joomla',
    specPattern: 'tests/cypress/e2e/**/*.cy.js',
    supportFile: 'tests/cypress/support/index.js',
    screenshotsFolder: 'tests/cypress/output/screenshots',
    videosFolder: 'tests/cypress/output/videos',
    video: false,
    // A Joomla administrator page does a fair amount before it settles, and a test site is
    // rarely a fast one.
    defaultCommandTimeout: 10000,
  },
  env: {
    username: 'admin',
    password: '',
    // The language a rule is written for in the specs. It has to be an installed content
    // language on the test site, and not the component's source language.
    targetLanguage: 'nl-NL',
  },
});
