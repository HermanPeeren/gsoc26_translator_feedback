<?php

/**
 * @package     Joomla.Administrator
 * @subpackage  com_translations
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Component\Translations\Administrator\Model;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use Jfcherng\Diff\SequenceMatcher;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Log\Log;
use Joomla\CMS\MVC\Model\BaseDatabaseModel;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\Component\Translations\Administrator\Event\DistilEvent;
use Joomla\Component\Translations\Administrator\Helper\RuleRetriever;
use Joomla\Component\Translations\Administrator\Helper\RunResult;
use Joomla\Component\Translations\Administrator\Helper\WordNormaliser;
use Joomla\Component\Translations\Administrator\Table\RuleTable;
use Joomla\Database\ParameterType;

/**
 * Distiller model: turns translator feedback into translation rules.
 *
 * Reads a batch of pending feedback, focuses each correction with a diff, asks the
 * "rag" plugin group to distil rules from it, and writes the results for review in the
 * Rules view, unpublished unless the option publishes them on creation. All
 * provider-agnostic work lives here; only the LLM call belongs to a plugin, behind the
 * onDistil event.
 *
 * @since  0.4.0
 */
class DistillerModel extends BaseDatabaseModel
{
    /**
     * The origin recorded on a rule whose evidence carries no single origin of its own.
     *
     * @var    string
     * @since  0.4.0
     */
    private const SOURCE_ORIGIN = 'distilled';

    /**
     * The rule states offered as context: draft and published. A trashed rule is left out, so a
     * provider cannot refine one back into use.
     *
     * @var    int[]
     * @since  1.0.0
     */
    private const CONTEXT_STATES = [0, 1];

    /**
     * The most rules sent as context in one request, so the request stays bounded as the rule
     * base grows.
     *
     * @var    integer
     * @since  1.0.0
     */
    private const MAX_CONTEXT_RULES = 50;

    /**
     * The most style rules sent as context, since they apply to the whole language rather than
     * to a term and so are never narrowed by the corrections.
     *
     * @var    integer
     * @since  1.0.0
     */
    private const MAX_CONTEXT_STYLE_RULES = 15;

    /**
     * The rule fields a provider is given, so the columns read only for matching stay out of the
     * request.
     *
     * @var    string[]
     * @since  1.0.0
     */
    private const CONTEXT_RULE_FIELDS = ['id', 'rule_type', 'rule_name', 'rule_text', 'source_term', 'target_term'];

    /**
     * Distil draft rules from one batch of pending feedback.
     *
     * Feedback is sent one target language at a time so its terminology stays coherent. Each
     * request is saved as soon as it is answered, so a later failure does not lose earlier work,
     * and a row that failed before is sent in a smaller request, so it cannot hold back the rows
     * it was batched with. A row that fails for the third time is set aside as failed.
     *
     * @param   integer  $batchSize  The most feedback rows to process in one run.
     *
     * @return  RunResult  What the run did, and whether it should run again.
     *
     * @throws  \RuntimeException  When no distillation provider is enabled.
     *
     * @since   0.4.0
     */
    public function distill(int $batchSize = 10): RunResult
    {
        // Without a provider every request would fail and use up an attempt of rows that are fine.
        if (PluginHelper::getPlugin('rag') === []) {
            throw new \RuntimeException('No distillation provider is enabled. Enable a RAG plugin to distil rules.');
        }

        $result   = new RunResult();
        $feedback = $this->loadPendingFeedback($batchSize);

        if ($feedback !== []) {
            $sourceLanguage = (string) ComponentHelper::getParams('com_translations')->get('source_language', 'en-GB');

            foreach ($this->requestBatches($feedback, $batchSize) as $rows) {
                $this->distilRequest($rows, $sourceLanguage, $result);
            }
        }

        $result->remaining = $this->countPendingFeedback();

        return $result;
    }

    /**
     * Send one request's worth of corrections and save what comes back.
     *
     * The attempt is counted before the provider is asked, so a run that is killed while it
     * waits for an answer still uses up one of the rows' attempts. The rules and the processed
     * mark are written in one transaction right after the answer, and no transaction is open
     * while a provider is asked.
     *
     * @param   object[]   $rows            The feedback rows, all for one target language.
     * @param   string     $sourceLanguage  The source language code.
     * @param   RunResult  $result          The run's result, updated in place.
     *
     * @return  void
     *
     * @since   1.1.0
     */
    private function distilRequest(array $rows, string $sourceLanguage, RunResult $result): void
    {
        $targetLanguage = (string) $rows[0]->target_language;
        $corrections    = [];
        $rowIds         = [];
        $origins        = [];

        foreach ($rows as $row) {
            $feedbackId = (int) $row->id;
            $diff       = $this->diff((string) $row->machine_draft, (string) $row->human_correction);

            $rowIds[]             = $feedbackId;
            $origins[$feedbackId] = (string) $row->source_origin;

            $this->storeDiff($feedbackId, $diff);

            $corrections[] = [
                'id'               => $feedbackId,
                'source_text'      => (string) $row->source_text,
                'machine_draft'    => (string) $row->machine_draft,
                'human_correction' => (string) $row->human_correction,
                'diff'             => $diff,
            ];
        }

        $this->countAttempt($rowIds);

        try {
            $candidates = $this->requestCandidates(
                $corrections,
                $this->contextRules($corrections, $sourceLanguage, $targetLanguage),
                $sourceLanguage,
                $targetLanguage
            );

            // Resolved here, before the transaction, because resolving a word may ask a provider.
            $standardForms = $this->candidateStandardForms($candidates, $sourceLanguage);
        } catch (\Throwable $e) {
            $this->recordFailure($rowIds, $e->getMessage(), $result);

            return;
        }

        $db = $this->getDatabase();
        $db->transactionStart();

        try {
            $this->persistRules($candidates, $targetLanguage, $origins, $standardForms);
            $this->markProcessed($rowIds);
            $db->transactionCommit();
        } catch (\Throwable $e) {
            $db->transactionRollback();
            $this->recordFailure($rowIds, $e->getMessage(), $result);

            return;
        }

        $result->processed += \count($rowIds);
    }

    /**
     * Load the pending feedback rows for one run, up to the batch size.
     *
     * Rows that failed before come first: a run that has any of them works only on those, so a
     * row that keeps failing is found out without taking healthy rows down with it.
     *
     * @param   integer  $batchSize  The most rows to return.
     *
     * @return  object[]  The feedback rows, oldest first.
     *
     * @since   0.4.0
     */
    private function loadPendingFeedback(int $batchSize): array
    {
        $retried = $this->queryPendingFeedback($batchSize, true);

        return $retried !== [] ? $retried : $this->queryPendingFeedback($batchSize, false);
    }

    /**
     * Query pending feedback rows that have, or have not, been attempted before.
     *
     * @param   integer  $batchSize  The most rows to return.
     * @param   boolean  $retried    True for rows that failed before, false for untried rows.
     *
     * @return  object[]  The feedback rows, oldest first.
     *
     * @since   1.1.0
     */
    private function queryPendingFeedback(int $batchSize, bool $retried): array
    {
        $status      = 'pending';
        $maxAttempts = RunResult::MAX_ATTEMPTS;
        $db          = $this->getDatabase();
        $query       = $db->getQuery(true)
            ->select('*')
            ->from($db->quoteName('#__translations_feedback'))
            ->where($db->quoteName('status') . ' = :status')
            ->where($db->quoteName('attempts') . ' < :maxAttempts')
            ->where($db->quoteName('attempts') . ($retried ? ' > 0' : ' = 0'))
            ->order([$db->quoteName('created') . ' ASC', $db->quoteName('id') . ' ASC'])
            ->bind(':status', $status, ParameterType::STRING)
            ->bind(':maxAttempts', $maxAttempts, ParameterType::INTEGER);
        $db->setQuery($query, 0, $batchSize);

        return $db->loadObjectList() ?: [];
    }

    /**
     * Count the feedback rows still waiting to be distilled.
     *
     * @return  integer  The number of pending rows that have attempts left.
     *
     * @since   1.1.0
     */
    private function countPendingFeedback(): int
    {
        $status      = 'pending';
        $maxAttempts = RunResult::MAX_ATTEMPTS;
        $db          = $this->getDatabase();
        $query       = $db->getQuery(true)
            ->select('COUNT(*)')
            ->from($db->quoteName('#__translations_feedback'))
            ->where($db->quoteName('status') . ' = :status')
            ->where($db->quoteName('attempts') . ' < :maxAttempts')
            ->bind(':status', $status, ParameterType::STRING)
            ->bind(':maxAttempts', $maxAttempts, ParameterType::INTEGER);
        $db->setQuery($query);

        return (int) $db->loadResult();
    }

    /**
     * Split a run's rows into requests, one target language per request.
     *
     * A row that failed before goes in a smaller request: half the batch size on its second
     * attempt, alone on its last. The rows that failed most come first.
     *
     * @param   object[]  $feedback   The feedback rows.
     * @param   integer   $batchSize  The size of a request of untried rows.
     *
     * @return  array  The requests, each a list of rows for one target language.
     *
     * @since   1.1.0
     */
    private function requestBatches(array $feedback, int $batchSize): array
    {
        $groups = [];

        foreach ($feedback as $row) {
            $groups[(int) ($row->attempts ?? 0)][(string) $row->target_language][] = $row;
        }

        krsort($groups);

        $requests = [];

        foreach ($groups as $attempts => $byLanguage) {
            $limit = RunResult::requestLimit($batchSize, $attempts);

            foreach ($byLanguage as $rows) {
                foreach (array_chunk($rows, $limit) as $request) {
                    $requests[] = $request;
                }
            }
        }

        return $requests;
    }

    /**
     * Count an attempt for the given feedback rows, before their request is sent.
     *
     * @param   int[]  $feedbackIds  The feedback row ids.
     *
     * @return  void
     *
     * @since   1.1.0
     */
    private function countAttempt(array $feedbackIds): void
    {
        $db    = $this->getDatabase();
        $query = $db->getQuery(true)
            ->update($db->quoteName('#__translations_feedback'))
            ->set($db->quoteName('attempts') . ' = ' . $db->quoteName('attempts') . ' + 1')
            ->whereIn($db->quoteName('id'), $feedbackIds);
        $db->setQuery($query)->execute();
    }

    /**
     * Record a failed request: keep the error on its rows and set aside the ones whose attempts
     * are used up, so no later run pays for them again.
     *
     * @param   int[]      $feedbackIds  The feedback row ids of the request.
     * @param   string     $message      The error the request ended with.
     * @param   RunResult  $result       The run's result, updated in place.
     *
     * @return  void
     *
     * @since   1.1.0
     */
    private function recordFailure(array $feedbackIds, string $message, RunResult $result): void
    {
        $db        = $this->getDatabase();
        $lastError = RunResult::errorText($message);
        $query     = $db->getQuery(true)
            ->update($db->quoteName('#__translations_feedback'))
            ->set($db->quoteName('last_error') . ' = :lastError')
            ->whereIn($db->quoteName('id'), $feedbackIds)
            ->bind(':lastError', $lastError, ParameterType::STRING);
        $db->setQuery($query)->execute();

        $failed      = 'failed';
        $maxAttempts = RunResult::MAX_ATTEMPTS;
        $query       = $db->getQuery(true)
            ->update($db->quoteName('#__translations_feedback'))
            ->set($db->quoteName('status') . ' = :failed')
            ->whereIn($db->quoteName('id'), $feedbackIds)
            ->where($db->quoteName('attempts') . ' >= :maxAttempts')
            ->bind(':failed', $failed, ParameterType::STRING)
            ->bind(':maxAttempts', $maxAttempts, ParameterType::INTEGER);
        $db->setQuery($query)->execute();

        $result->failed      += \count($feedbackIds);
        $result->quarantined += $db->getAffectedRows();
        $result->lastError    = $lastError;

        Log::add(
            \sprintf('Could not distil feedback %s: %s', implode(', ', $feedbackIds), $lastError),
            Log::WARNING,
            'translations'
        );
    }

    /**
     * Resolve the standard form of the single-word source terms among the candidates, in one go.
     *
     * @param   array   $candidates      The rule candidates.
     * @param   string  $sourceLanguage  The source language code.
     *
     * @return  array  Standard form keyed by the lower-cased term, for the terms resolved.
     *
     * @since   1.1.0
     */
    private function candidateStandardForms(array $candidates, string $sourceLanguage): array
    {
        $terms = [];

        foreach ($candidates as $candidate) {
            $term = \is_array($candidate) ? $this->nullableTerm($candidate['source_term'] ?? null) : null;

            if ($term !== null && WordNormaliser::isSingleWord($term)) {
                $terms[] = $term;
            }
        }

        if ($terms === []) {
            return [];
        }

        return WordNormaliser::standardForms($this->getDatabase(), $this->getDispatcher(), $terms, $sourceLanguage);
    }

    /**
     * Reduce the change from the machine draft to the human correction to its changed spans,
     * as labelled "before to after" pairs. Comparing at word level isolates the actual edit,
     * so a one-word fix inside a long paragraph is captured as that word rather than the whole
     * paragraph. Each span is labelled by size: a single-word swap ([term]) points to
     * terminology, a run of words ([phrase]) points to phrasing or tone, so the language model
     * has a hint to the kind of rule while diff_data and the prompt stay small and focused.
     *
     * @param   string  $machineDraft     The machine draft.
     * @param   string  $humanCorrection  The human correction.
     *
     * @return  string  The labelled changed spans, one per line.
     *
     * @since   0.4.0
     */
    private function diff(string $machineDraft, string $humanCorrection): string
    {
        // Tokenise into words that keep their trailing whitespace, so a run of changed words
        // stays one span (a reworded phrase) rather than being split by the spaces between them.
        $oldWords = $this->tokenize($machineDraft);
        $newWords = $this->tokenize($humanCorrection);

        $matcher = new SequenceMatcher($oldWords, $newWords);
        $spans   = [];

        foreach ($matcher->getOpcodes() as [$op, $oldStart, $oldEnd, $newStart, $newEnd]) {
            if ($op === SequenceMatcher::OP_EQ) {
                continue;
            }

            $before = trim(implode('', \array_slice($oldWords, $oldStart, $oldEnd - $oldStart)));
            $after  = trim(implode('', \array_slice($newWords, $newStart, $newEnd - $newStart)));

            // A token carries the whitespace after it, so re-wrapping a line changes the tokens
            // without changing a word. What is left once that whitespace is trimmed is the same
            // text on both sides, and there is no rule to be learned from it.
            if ($before === $after) {
                continue;
            }

            if ($before === '') {
                $spans[] = '[added] ' . $after;
            } elseif ($after === '') {
                $spans[] = '[removed] ' . $before;
            } else {
                // A single-word swap points to terminology; a run of words points to phrasing or tone.
                $label   = $this->wordCount($before) > 1 || $this->wordCount($after) > 1 ? '[phrase]' : '[term]';
                $spans[] = $label . ' ' . $before . ' → ' . $after;
            }
        }

        return implode("\n", $spans);
    }

    /**
     * Count the words in a segment, so a change can be classed as a single term or a phrase.
     *
     * @param   string  $text  The segment.
     *
     * @return  integer  The word count.
     *
     * @since   0.4.0
     */
    private function wordCount(string $text): int
    {
        $text = trim($text);

        return $text === '' ? 0 : \count(preg_split('/\s+/u', $text) ?: []);
    }

    /**
     * Split text into tokens that each keep their trailing whitespace, so a run of changed
     * words stays a single span rather than being split by the spaces between them.
     *
     * @param   string  $text  The text to split.
     *
     * @return  string[]  The tokens.
     *
     * @since   0.4.0
     */
    private function tokenize(string $text): array
    {
        preg_match_all('/\S+\s*/u', $text, $matches);

        return $matches[0];
    }

    /**
     * Store a correction's diff back on its feedback row.
     *
     * @param   integer  $feedbackId  The feedback row id.
     * @param   string   $diff        The rendered diff.
     *
     * @return  void
     *
     * @since   0.4.0
     */
    private function storeDiff(int $feedbackId, string $diff): void
    {
        $db    = $this->getDatabase();
        $query = $db->getQuery(true)
            ->update($db->quoteName('#__translations_feedback'))
            ->set($db->quoteName('diff_data') . ' = :diff')
            ->where($db->quoteName('id') . ' = :id')
            ->bind(':diff', $diff, ParameterType::STRING)
            ->bind(':id', $feedbackId, ParameterType::INTEGER);
        $db->setQuery($query)->execute();
    }

    /**
     * Select the rules a provider is shown alongside one language's corrections, so it merges its
     * candidates into them rather than duplicating.
     *
     * The rules are matched against the corrections' source text the same way they are matched
     * against an item's source strings when it is translated: a rule's term and a correction's
     * source text are both in the source language, so it is the same operation.
     *
     * @param   array   $corrections     The corrections being distilled.
     * @param   string  $sourceLanguage  The source language code.
     * @param   string  $targetLanguage  The target language code.
     *
     * @return  array  The rules to send as context.
     *
     * @since   1.0.0
     */
    private function contextRules(array $corrections, string $sourceLanguage, string $targetLanguage): array
    {
        $rules = $this->loadExistingRules($targetLanguage);

        if ($rules === []) {
            return [];
        }

        $text = RuleRetriever::plainText(array_column($corrections, 'source_text'));

        // A language whose rules carry no standard form gains nothing from reducing the text.
        $standardForms = RuleRetriever::hasStandardForm($rules)
            ? WordNormaliser::standardForms(
                $this->getDatabase(),
                $this->getDispatcher(),
                WordNormaliser::tokenise($text),
                $sourceLanguage
            )
            : [];

        return $this->selectContextRules($rules, $text, $standardForms);
    }

    /**
     * Load the rules learned for a target language, drafts first and by confidence within each
     * state, so a cap keeps the ones likeliest to be duplicated and safest to refine.
     *
     * @param   string  $targetLanguage  The target language code.
     *
     * @return  array  The rule rows, carrying the columns rules are matched on.
     *
     * @since   0.4.0
     */
    private function loadExistingRules(string $targetLanguage): array
    {
        $db    = $this->getDatabase();
        $query = $db->getQuery(true)
            ->select(
                $db->quoteName(
                    [
                        'id', 'rule_type', 'rule_name', 'rule_text',
                        'source_term', 'source_term_standard', 'target_term', 'search_keywords',
                    ]
                )
            )
            ->from($db->quoteName('#__translations_rules'))
            ->where($db->quoteName('target_language') . ' = :lang')
            ->whereIn($db->quoteName('state'), self::CONTEXT_STATES)
            ->order(
                [
                    $db->quoteName('state') . ' ASC',
                    $db->quoteName('confidence') . ' DESC',
                    $db->quoteName('id') . ' DESC',
                ]
            )
            ->bind(':lang', $targetLanguage, ParameterType::STRING);
        $db->setQuery($query);

        return $db->loadAssocList() ?: [];
    }

    /**
     * Keep the rules that apply to the corrections, capped, and reduce each to the fields a
     * provider is given.
     *
     * Style rules apply to the whole language rather than to a term, so they are not narrowed by
     * the text and carry their own cap. The columns rules are matched on are dropped here, so
     * they never reach the request.
     *
     * @param   array   $rules          The candidate rules, ordered.
     * @param   string  $text           The corrections' readable source text.
     * @param   array   $standardForms  Standard form keyed by the text's words, where known.
     *
     * @return  array  The rules to send as context.
     *
     * @since   1.0.0
     */
    private function selectContextRules(array $rules, string $text, array $standardForms): array
    {
        $selected = [];
        $style    = 0;

        foreach ($rules as $rule) {
            if (\count($selected) >= self::MAX_CONTEXT_RULES) {
                break;
            }

            if ($rule['rule_type'] === 'style') {
                if ($style >= self::MAX_CONTEXT_STYLE_RULES) {
                    continue;
                }

                $style++;
            } elseif (!RuleRetriever::appliesToText($rule, $text, $standardForms)) {
                continue;
            }

            $selected[] = array_intersect_key($rule, array_flip(self::CONTEXT_RULE_FIELDS));
        }

        return $selected;
    }

    /**
     * Ask the "rag" plugin group to distil rule candidates from a batch of corrections.
     *
     * The first provider that answers wins; an enabled provider may legitimately return no
     * candidates (nothing worth learning), but with no provider at all there is nothing to
     * distil with, so that fails.
     *
     * @param   array   $corrections     The corrections to distil.
     * @param   array   $existingRules   The rules already learned for the language.
     * @param   string  $sourceLanguage  The source language code.
     * @param   string  $targetLanguage  The target language code.
     *
     * @return  array  The rule candidates.
     *
     * @throws  \RuntimeException  When no distillation provider is enabled.
     *
     * @since   0.4.0
     */
    private function requestCandidates(array $corrections, array $existingRules, string $sourceLanguage, string $targetLanguage): array
    {
        $dispatcher = $this->getDispatcher();
        PluginHelper::importPlugin('rag', null, true, $dispatcher);

        $event = new DistilEvent('onDistil', [
            'corrections'    => $corrections,
            'existingRules'  => $existingRules,
            'sourceLanguage' => $sourceLanguage,
            'targetLanguage' => $targetLanguage,
        ]);
        $dispatcher->dispatch('onDistil', $event);

        // The first provider that answered wins, even when it distilled nothing.
        foreach ((array) $event->getArgument('result', []) as $providerResult) {
            if (\is_array($providerResult)) {
                return $providerResult;
            }
        }

        throw new \RuntimeException('No distillation provider is enabled. Enable a RAG plugin to distil rules.');
    }

    /**
     * Persist rule candidates as draft rules, skipping any that fail validation.
     *
     * @param   array   $candidates      The rule candidates.
     * @param   string  $targetLanguage  The target language code.
     * @param   array   $origins         The origin of each feedback row, keyed by row id.
     * @param   array   $standardForms   Standard form keyed by lower-cased source term, where known.
     *
     * @return  void
     *
     * @since   0.4.0
     */
    private function persistRules(
        array $candidates,
        string $targetLanguage,
        array $origins,
        array $standardForms
    ): void {
        foreach ($candidates as $candidate) {
            if (!\is_array($candidate)) {
                continue;
            }

            try {
                $this->saveRule($candidate, $targetLanguage, $origins, $standardForms);
            } catch (\Throwable $e) {
                // Skip a malformed candidate rather than lose the rest of the batch.
                Log::add(
                    \sprintf('Skipped a distilled rule for %s: %s', $targetLanguage, $e->getMessage()),
                    Log::WARNING,
                    'translations'
                );
            }
        }
    }

    /**
     * Save one rule candidate: refine an existing rule when the candidate carries its id, else
     * insert a new one, held back for review unless the option publishes it on creation.
     *
     * @param   array   $candidate       The rule candidate.
     * @param   string  $targetLanguage  The target language code.
     * @param   array   $origins         The origin of each feedback row, keyed by row id.
     * @param   array   $standardForms   Standard form keyed by lower-cased source term, where known.
     *
     * @return  void
     *
     * @throws  \RuntimeException  When the rule fails validation or cannot be stored.
     *
     * @since   0.4.0
     */
    private function saveRule(
        array $candidate,
        string $targetLanguage,
        array $origins,
        array $standardForms
    ): void {
        /** @var RuleTable $table */
        $table       = $this->getTable('Rule', 'Administrator');
        $feedbackIds = array_values(array_unique(array_map('intval', (array) ($candidate['source_feedback_ids'] ?? []))));
        $existingId  = (int) ($candidate['id'] ?? 0);

        // The rule fields the provider (re)states each time. Bind them (rather than set them
        // directly) so the table's _jsonEncode encodes source_feedback_ids and the array is
        // not dropped by the driver on store.
        $sourceTerm = $this->nullableTerm($candidate['source_term'] ?? null);

        $data = [
            'rule_name'            => (string) ($candidate['rule_name'] ?? ''),
            'rule_type'            => (string) ($candidate['rule_type'] ?? ''),
            'target_language'      => $targetLanguage,
            'rule_text'            => (string) ($candidate['rule_text'] ?? ''),
            'source_term'          => $sourceTerm,
            'source_term_standard' => $sourceTerm !== null && WordNormaliser::isSingleWord($sourceTerm)
                ? ($standardForms[mb_strtolower($sourceTerm)] ?? null)
                : null,
            'target_term'          => $this->nullableTerm($candidate['target_term'] ?? null),
            'search_keywords'      => (string) ($candidate['search_keywords'] ?? ''),
        ];

        if ($existingId > 0 && $table->load($existingId)) {
            // Refine in place: overlay the provider's improved wording, accumulate the evidence,
            // and never let confidence drop; the original state and origin are kept.
            $data['confidence']          = max((float) $table->confidence, (float) ($candidate['confidence'] ?? 0));
            $data['source_feedback_ids'] = $this->mergeFeedbackIds($table->source_feedback_ids, $feedbackIds);
        } else {
            // A new rule is held back for review, unless the option publishes it on creation.
            $publishOnCreation = (bool) ComponentHelper::getParams('com_translations')->get('auto_publish_rules', 0);

            $data['confidence']          = (float) ($candidate['confidence'] ?? 0);
            $data['source_feedback_ids'] = $feedbackIds;
            $data['source_origin']       = $this->originOf($feedbackIds, $origins);
            $data['state']               = $publishOnCreation ? 1 : 0;
        }

        if (!$table->bind($data) || !$table->check() || !$table->store()) {
            throw new \RuntimeException('The distilled rule could not be stored.');
        }
    }

    /**
     * Work out the origin to record on a rule from the feedback that produced it.
     *
     * A rule is only as attributable as its evidence: when every correction behind it came from
     * the same place, the rule carries that origin, so a set imported from elsewhere can later be
     * reviewed, exported or withdrawn as a set. Mixed evidence belongs to no one source, and a
     * rule built from it is distilled like any other.
     *
     * @param   int[]  $feedbackIds  The feedback rows the rule was built from.
     * @param   array  $origins      The origin of each feedback row, keyed by row id.
     *
     * @return  string  The origin to record.
     *
     * @since   1.0.0
     */
    private function originOf(array $feedbackIds, array $origins): string
    {
        $found = [];

        foreach ($feedbackIds as $feedbackId) {
            if (isset($origins[$feedbackId])) {
                $found[$origins[$feedbackId]] = true;
            }
        }

        return \count($found) === 1 ? (string) array_key_first($found) : self::SOURCE_ORIGIN;
    }

    /**
     * Merge new feedback ids into a rule's stored list, keeping each id once.
     *
     * @param   mixed  $stored  The rule's stored source_feedback_ids (a JSON string, or an array).
     * @param   int[]  $newIds  The feedback ids to add.
     *
     * @return  int[]  The merged ids.
     *
     * @since   0.4.0
     */
    private function mergeFeedbackIds($stored, array $newIds): array
    {
        $current = [];

        if (\is_string($stored) && $stored !== '') {
            $decoded = json_decode($stored, true);
            $current = \is_array($decoded) ? $decoded : [];
        } elseif (\is_array($stored)) {
            $current = $stored;
        }

        return array_values(array_unique(array_map('intval', array_merge($current, $newIds))));
    }

    /**
     * Normalise a term to a non-empty string or null, since the term columns are nullable.
     *
     * @param   mixed  $term  The candidate term.
     *
     * @return  string|null  The term, or null when empty.
     *
     * @since   0.4.0
     */
    private function nullableTerm($term): ?string
    {
        $term = trim((string) $term);

        return $term === '' ? null : $term;
    }

    /**
     * Mark feedback rows as processed so they are not distilled again.
     *
     * @param   int[]  $feedbackIds  The feedback row ids.
     *
     * @return  void
     *
     * @since   0.4.0
     */
    private function markProcessed(array $feedbackIds): void
    {
        if ($feedbackIds === []) {
            return;
        }

        $processed = 'processed';
        $db        = $this->getDatabase();
        $query     = $db->getQuery(true)
            ->update($db->quoteName('#__translations_feedback'))
            ->set($db->quoteName('status') . ' = :status')
            ->whereIn($db->quoteName('id'), $feedbackIds)
            ->bind(':status', $processed, ParameterType::STRING);
        $db->setQuery($query)->execute();
    }
}
