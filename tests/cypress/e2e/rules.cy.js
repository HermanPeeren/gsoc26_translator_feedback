/**
 * End-to-end specs for the Rules view, limited to what the unit tests structurally cannot
 * reach: a browser, a database and an installed Joomla.
 *
 * Everything about how a rule is matched, retrieved and turned into a prompt is tested in
 * PHPUnit, where it runs in milliseconds and needs no site. What is left over is the part
 * of a rule that only exists once Joomla renders it: the form showing the fields that
 * belong to the rule type, the table refusing a rule it cannot use, and the list finding
 * the rule again.
 *
 * Expects a site with the package installed and a content language for the target
 * language. Configuration comes from the git-ignored cypress.config.js.
 *
 * @package     Joomla.Tests
 * @subpackage  com_translations
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

// Every rule these specs create carries this in its name, so the cleanup can find exactly
// what the suite made and nothing else.
const MARKER = '[cypress]';

const ADMIN = '/administrator/index.php';
const RULES = `${ADMIN}?option=com_translations&view=rules`;
const NEW_RULE = `${ADMIN}?option=com_translations&task=rule.add`;

/**
 * Trash every rule this suite left behind.
 *
 * Trashing rather than deleting: a trashed rule is out of the way - it is not published,
 * so no translation is steered by it, and the default list does not show it - and emptying
 * the trash is a confirmation dialog this does not need to drive.
 */
const trashOurRules = () => {
  cy.visit(RULES);
  cy.searchForItem(MARKER);

  cy.get('body').then(($body) => {
    if ($body.find('#ruleList tbody tr').length === 0) {
      return;
    }

    cy.checkAllResults();
    // The view adds its publishing buttons to the toolbar itself rather than through the
    // status dropdown a core list view uses, so Trash is a button of its own. The id is on
    // the custom element that wraps the button.
    cy.get('#toolbar-trash button').click();
  });

  cy.searchForItem();
};

/**
 * Fill in and save a rule, leaving the editor open on it.
 *
 * @param {string} name   The rule name, without the marker.
 * @param {string} type   The rule type to select.
 * @param {object} values The fields to fill in, keyed by form field id suffix.
 */
const saveRule = (name, type, values) => {
  cy.visit(NEW_RULE);

  cy.get('#jform_rule_name').clear().type(`${MARKER} ${name}`);
  cy.get('#jform_rule_type').select(type);
  cy.get('#jform_target_language').select(Cypress.env('targetLanguage'));

  Object.entries(values).forEach(([field, value]) => {
    cy.get(`#jform_${field}`).clear().type(value);
  });

  cy.get('joomla-toolbar-button[task="rule.apply"] button').click();
};

describe('Translation rules', () => {
  before(() => {
    cy.doAdministratorLogin();
    trashOurRules();
  });

  after(() => {
    cy.doAdministratorLogin();
    trashOurRules();
  });

  beforeEach(() => cy.doAdministratorLogin());

  // A rule type decides which fields mean anything: a style rule has no term, and a
  // preserved term has no translation. They share one form and showon swaps the blocks, so
  // only a browser can say whether a translator is offered the fields of the type they
  // picked.
  it('shows the fields of the chosen rule type and hides the others', () => {
    cy.visit(NEW_RULE);

    cy.get('#jform_rule_type').select('terminology');
    cy.get('#jform_source_term').should('be.visible');
    cy.get('#jform_target_term').should('be.visible');
    cy.get('#jform_rule_text').should('not.be.visible');

    // A preserved term is kept as it stands, so it has no translation to give.
    cy.get('#jform_rule_type').select('preservation');
    cy.get('#jform_source_term').should('be.visible');
    cy.get('#jform_target_term').should('not.be.visible');
    cy.get('#jform_rule_text').should('not.be.visible');

    cy.get('#jform_rule_type').select('style');
    cy.get('#jform_rule_text').should('be.visible');
    cy.get('#jform_source_term').should('not.be.visible');
    cy.get('#jform_search_keywords').should('not.be.visible');
  });

  // The field lists the languages a rule can be written for, which is every content
  // language except the one the content is written in. Offering "All" or the source
  // language would produce a rule that can never be retrieved: nothing is ever translated
  // into either.
  it('offers no rule for the source language or for all languages', () => {
    cy.visit(NEW_RULE);

    cy.get('#jform_target_language option').should('have.length.greaterThan', 0);
    cy.get('#jform_target_language option[value="*"]').should('not.exist');
    cy.get('#jform_target_language option[value="en-GB"]').should('not.exist');
    cy.get(`#jform_target_language option[value="${Cypress.env('targetLanguage')}"]`).should('exist');
  });

  // A terminology rule without a translation for its term says nothing, and the table
  // refuses it. The message comes from the component rather than from the form, so it takes
  // a real save to see it.
  it('refuses a terminology rule that does not say what to translate the term as', () => {
    saveRule('Incomplete terminology', 'terminology', { source_term: 'module' });

    cy.get('#system-message-container').should('contain', 'Please provide the target term.');
    cy.get('#jform_rule_name').should('have.value', `${MARKER} Incomplete terminology`);
  });

  // A style rule is matched against the language rather than against a term. The form still
  // posts the term fields, because showon hides them without emptying them, so the table
  // clears them on the way in - and the rule that comes back has to show that.
  it('stores a style rule without the terms the form still posted', () => {
    cy.visit(NEW_RULE);

    cy.get('#jform_rule_name').clear().type(`${MARKER} Address the reader`);
    cy.get('#jform_rule_type').select('terminology');
    cy.get('#jform_source_term').clear().type('module');
    cy.get('#jform_target_term').clear().type('onderdeel');

    // Only now is it a style rule, with the terms of the other type still in the form.
    cy.get('#jform_rule_type').select('style');
    cy.get('#jform_rule_text').clear().type('Address the reader as "je"');
    cy.get('#jform_target_language').select(Cypress.env('targetLanguage'));

    cy.get('joomla-toolbar-button[task="rule.apply"] button').click();

    cy.get('#system-message-container').should('contain', 'saved');
    cy.get('#jform_rule_text').should('have.value', 'Address the reader as "je"');

    cy.get('#jform_rule_type').select('terminology');
    cy.get('#jform_source_term').should('have.value', '');
    cy.get('#jform_target_term').should('have.value', '');
  });

  // A rule saved in the editor has to be findable in the list, by name and by type. The
  // list is what a reviewer works through after a distiller run, and a rule that cannot be
  // filtered to is a rule nobody approves.
  it('saves a terminology rule and finds it back in the list', () => {
    saveRule('Module terminology', 'terminology', { source_term: 'module', target_term: 'onderdeel' });

    cy.get('#system-message-container').should('contain', 'saved');
    cy.get('joomla-toolbar-button[task="rule.cancel"] button').click();

    cy.searchForItem(`${MARKER} Module terminology`);
    cy.get('#ruleList tbody tr').should('have.length', 1);

    cy.setFilter('rule_type', 'style');
    cy.get('#ruleList tbody tr').should('have.length', 0);

    cy.setFilter('rule_type', 'terminology');
    cy.get('#ruleList tbody tr').should('have.length', 1);

    cy.searchForItem();
  });
});
