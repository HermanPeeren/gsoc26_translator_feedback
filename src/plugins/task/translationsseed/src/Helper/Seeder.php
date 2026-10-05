<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  Task.TranslationsSeed
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Plugin\Task\TranslationsSeed\Helper;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use Joomla\CMS\Plugin\PluginHelper;
use Joomla\Component\Translations\Administrator\Helper\RunResult;
use Joomla\Component\Translations\Administrator\Helper\StringTranslator;
use Joomla\Component\Translations\Administrator\Helper\TimeBudget;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use Joomla\Event\DispatcherInterface;

/**
 * Turns an installed language pack into translator feedback for the Translations component.
 *
 * A pack holds translations a language team has agreed on, so pairing one against an unaided
 * machine translation of the same string produces the same material a translator produces by
 * correcting a draft. The pairs are written as feedback and the component's distiller learns
 * rules from them, which gives a site rules before anyone has corrected anything on it.
 *
 * The machine translation is asked for without rules, so what the pack disagrees with is the
 * provider's own wording rather than something already learned on this site.
 *
 * Every request is paid for, so each string's attempts are counted before its request is sent,
 * and a string that fails for the third time is set aside rather than sent again run after run.
 *
 * @since  1.0.0
 */
class Seeder
{
    /**
     * The most distinct texts sent in one request when the task sets none.
     *
     * A provider is asked for every key it is given, so an oversized request is lost as a whole
     * rather than in part. This keeps a request short enough that the reply has room to grow for
     * a language whose wording runs longer than the source.
     *
     * @var    integer
     * @since  1.2.0
     */
    public const DEFAULT_REQUEST_SIZE = 25;

    /**
     * Failures in a row that end a run.
     *
     * One request can fail on its own account, but a run of them means the provider is
     * unreachable, and every further attempt is another paid call for the same answer.
     *
     * @var    integer
     * @since  1.0.0
     */
    private const MAX_CONSECUTIVE_FAILURES = 2;

    /**
     * The origin recorded on the feedback this writes, and so on the rules distilled from it.
     *
     * A seeded set is attributable to the pack it came from rather than to a translator, which is
     * what lets it be reviewed, exported or withdrawn as a set.
     *
     * @var    string
     * @since  1.0.0
     */
    private const SOURCE_ORIGIN = 'ini_import';

    /**
     * The status of a string that is done: its feedback, if any, is written.
     *
     * @var    string
     * @since  1.1.0
     */
    private const STATUS_SEEDED = 'seeded';

    /**
     * The status of a string whose request was sent but not answered usefully yet.
     *
     * @var    string
     * @since  1.1.0
     */
    private const STATUS_RETRY = 'retry';

    /**
     * The status of a string that failed for the third time and is not sent again.
     *
     * @var    string
     * @since  1.1.0
     */
    private const STATUS_FAILED = 'failed';

    /**
     * The database driver.
     *
     * @var    DatabaseInterface
     * @since  1.0.0
     */
    private $db;

    /**
     * The dispatcher the translation provider answers on.
     *
     * @var    DispatcherInterface
     * @since  1.0.0
     */
    private $dispatcher;

    /**
     * Constructor.
     *
     * @param   DatabaseInterface    $db          The database driver.
     * @param   DispatcherInterface  $dispatcher  The dispatcher the translation provider answers on.
     *
     * @since   1.0.0
     */
    public function __construct(DatabaseInterface $db, DispatcherInterface $dispatcher)
    {
        $this->db         = $db;
        $this->dispatcher = $dispatcher;
    }

    /**
     * Seed feedback from a language pack's translated strings, for as long as the run's time
     * budget allows.
     *
     * A string already seeded for the language is skipped, so a run resumes where the last one
     * stopped rather than paying for the same translations again. A text that occurs in several
     * places in the pack is translated once. Each request is saved as soon as it is answered,
     * and a string that failed before is sent in a smaller request.
     *
     * @param   string      $sourceLanguage  The language tag the strings are written in.
     * @param   string      $targetLanguage  The language tag of the pack to learn from.
     * @param   integer     $requestSize     The most distinct texts sent in one request.
     * @param   TimeBudget  $budget          How long the run may keep sending requests.
     * @param   string[]    $fileNames       The language file names to read, all of them when empty.
     *
     * @return  RunResult  What the run did, and whether it should run again.
     *
     * @throws  \RuntimeException  When the language is the source language, or no provider is enabled.
     *
     * @since   1.0.0
     */
    public function seed(
        string $sourceLanguage,
        string $targetLanguage,
        int $requestSize,
        TimeBudget $budget,
        array $fileNames = []
    ): RunResult {
        if ($targetLanguage === $sourceLanguage) {
            throw new \RuntimeException(\sprintf('%s is the source language, so there is nothing to learn from.', $targetLanguage));
        }

        // Without a provider every request would fail and use up an attempt of strings that are fine.
        if (PluginHelper::getPlugin('translation') === []) {
            throw new \RuntimeException(
                'No translation provider is enabled. Enable a translation plugin to translate content.'
            );
        }

        $result   = new RunResult();
        $pending  = $this->pendingPairs($sourceLanguage, $targetLanguage, $fileNames);
        $failures = 0;

        foreach ($this->requestChunks(self::units($pending), $requestSize) as $chunk) {
            if (!$budget->allowsAnother()) {
                break;
            }

            $this->countAttempt($chunk, $targetLanguage);
            $budget->startRequest();

            try {
                $translated = StringTranslator::translate(
                    $this->dispatcher,
                    array_map(static fn(array $unit): string => $unit['source'], $chunk),
                    $sourceLanguage,
                    $targetLanguage,
                    []
                );

                $failures = 0;
            } catch (\Throwable $e) {
                $budget->endRequest();
                $this->recordFailure(self::stringIds($chunk), $targetLanguage, $e->getMessage(), $result);

                if (++$failures === self::MAX_CONSECUTIVE_FAILURES) {
                    $result->aborted   = true;
                    $result->lastError = \sprintf(
                        'Stopped after %d failures in a row, the last being: %s',
                        $failures,
                        $result->lastError
                    );

                    break;
                }

                continue;
            }

            $budget->endRequest();
            $this->recordChunk($chunk, $translated, $targetLanguage, $result);
        }

        $result->remaining = max(0, \count($pending) - $result->processed - $result->quarantined);

        return $result;
    }

    /**
     * Give the strings that were set aside as failed a new set of attempts.
     *
     * A failed string's record is removed, so the next run sees it as untried. For use once the
     * cause of the failures is fixed, such as an invalid model or an empty credit balance.
     *
     * @return  integer  The number of strings made pending again.
     *
     * @since   1.2.0
     */
    public function resetFailed(): int
    {
        $failed = self::STATUS_FAILED;
        $query  = $this->db->getQuery(true)
            ->delete($this->db->quoteName('#__translations_seeded_strings'))
            ->where($this->db->quoteName('status') . ' = :failed')
            ->bind(':failed', $failed, ParameterType::STRING);
        $this->db->setQuery($query)->execute();

        return $this->db->getAffectedRows();
    }

    /**
     * Forget what was seeded for a language, so its pack can be seeded again from scratch.
     *
     * The seeding records are removed, so the next run sends every string again; the feedback the
     * seed task wrote is removed, so old corrections are not distilled next to new ones; and the
     * unpublished rules learned only from the pack are trashed, where they can still be restored.
     * A published rule was reviewed by someone, so it is trashed too only when that is asked for
     * explicitly. Feedback and rules from translators are not the seed task's, so those are always
     * left alone.
     *
     * @param   string   $targetLanguage    The language to forget.
     * @param   boolean  $includePublished  Whether published rules learned from the pack are trashed too.
     *
     * @return  array  The numbers of strings forgotten, feedback rows deleted and rules trashed,
     *                 keyed strings, feedback and rules.
     *
     * @throws  \RuntimeException  When no language is given.
     *
     * @since   1.2.0
     */
    public function forget(string $targetLanguage, bool $includePublished = false): array
    {
        if ($targetLanguage === '') {
            throw new \RuntimeException('No language is selected, so there is nothing to forget.');
        }

        $origin = self::SOURCE_ORIGIN;
        $counts = [];

        $this->db->transactionStart();

        try {
            $query = $this->db->getQuery(true)
                ->delete($this->db->quoteName('#__translations_seeded_strings'))
                ->where($this->db->quoteName('target_language') . ' = :targetLanguage')
                ->bind(':targetLanguage', $targetLanguage, ParameterType::STRING);
            $this->db->setQuery($query)->execute();
            $counts['strings'] = $this->db->getAffectedRows();

            $query = $this->db->getQuery(true)
                ->delete($this->db->quoteName('#__translations_feedback'))
                ->where($this->db->quoteName('target_language') . ' = :targetLanguage')
                ->where($this->db->quoteName('source_origin') . ' = :origin')
                ->bind(':targetLanguage', $targetLanguage, ParameterType::STRING)
                ->bind(':origin', $origin, ParameterType::STRING);
            $this->db->setQuery($query)->execute();
            $counts['feedback'] = $this->db->getAffectedRows();

            $trashed = -2;
            $query   = $this->db->getQuery(true)
                ->update($this->db->quoteName('#__translations_rules'))
                ->set($this->db->quoteName('state') . ' = :trashed')
                ->where($this->db->quoteName('target_language') . ' = :targetLanguage')
                ->where($this->db->quoteName('source_origin') . ' = :origin')
                ->whereIn($this->db->quoteName('state'), $includePublished ? [0, 1] : [0])
                ->bind(':trashed', $trashed, ParameterType::INTEGER)
                ->bind(':targetLanguage', $targetLanguage, ParameterType::STRING)
                ->bind(':origin', $origin, ParameterType::STRING);
            $this->db->setQuery($query)->execute();
            $counts['rules'] = $this->db->getAffectedRows();

            $this->db->transactionCommit();
        } catch (\Throwable $e) {
            $this->db->transactionRollback();

            throw $e;
        }

        return $counts;
    }

    /**
     * Collect the pack's translated strings that are still to be seeded.
     *
     * Strings that failed before come first, so they are settled before new ones are taken on.
     * A string that is seeded, or set aside as failed, is left out.
     *
     * @param   string    $sourceLanguage  The source language code.
     * @param   string    $targetLanguage  The target language code.
     * @param   string[]  $fileNames       The language file names to read, all of them when empty.
     *
     * @return  array  The pairs still to seed, keyed by string id, each with its attempts so far.
     *
     * @since   1.0.0
     */
    private function pendingPairs(string $sourceLanguage, string $targetLanguage, array $fileNames): array
    {
        $pairs = [];

        foreach (LanguagePackReader::read($sourceLanguage, $targetLanguage, $fileNames) as $pair) {
            $pairs[$pair['file'] . '#' . $pair['key']] = $pair;
        }

        if ($pairs === []) {
            return [];
        }

        $plan = self::classify($pairs, $this->seedStates($targetLanguage, array_keys($pairs)));

        $this->storeFingerprints($targetLanguage, $plan['backfill'], false);
        $this->storeFingerprints($targetLanguage, $plan['reopen'], true);

        return $plan['pending'];
    }

    /**
     * Sort the pack's pairs by what a run has to do with them.
     *
     * A string that was never sent is pending. One that is seeded, or set aside as failed, is
     * done - unless its fingerprint shows that its source text or the pack's translation has
     * changed since, as a new version of the pack may do: then it is opened again with fresh
     * attempts, and seeded like a new string. A string recorded before fingerprints were kept
     * gets one now, without a request, so a change is noticed from then on.
     *
     * @param   array  $pairs   The pack's pairs, keyed by string id.
     * @param   array  $states  Status, attempts and fingerprint keyed by string id, for the strings recorded.
     *
     * @return  array  pending: the pairs to send, keyed by string id, those that failed before first;
     *                 reopen: the new fingerprint of each changed string to open again;
     *                 backfill: the fingerprint of each recorded string that had none.
     *
     * @since   1.2.0
     */
    private static function classify(array $pairs, array $states): array
    {
        $retried  = [];
        $untried  = [];
        $reopen   = [];
        $backfill = [];

        foreach ($pairs as $stringId => $pair) {
            $fingerprint         = self::fingerprint($pair);
            $state               = $states[$stringId] ?? null;
            $pair['fingerprint'] = $fingerprint;

            if ($state === null) {
                $pair['attempts']   = 0;
                $pair['recorded']   = false;
                $untried[$stringId] = $pair;

                continue;
            }

            $pair['recorded'] = true;

            if ($state['fingerprint'] === '') {
                $backfill[$stringId] = $fingerprint;
            } elseif ($state['fingerprint'] !== $fingerprint) {
                $reopen[$stringId]  = $fingerprint;
                $pair['attempts']   = 0;
                $untried[$stringId] = $pair;

                continue;
            }

            if ($state['status'] === self::STATUS_RETRY && $state['attempts'] < RunResult::MAX_ATTEMPTS) {
                $pair['attempts']   = $state['attempts'];
                $retried[$stringId] = $pair;
            }
        }

        return ['pending' => $retried + $untried, 'reopen' => $reopen, 'backfill' => $backfill];
    }

    /**
     * The fingerprint of a pair: what its source text and the pack's translation say.
     *
     * @param   array  $pair  The pair.
     *
     * @return  string  A 40-character hash, the same for as long as neither text changes.
     *
     * @since   1.2.0
     */
    private static function fingerprint(array $pair): string
    {
        return sha1(trim((string) $pair['source']) . "\x1F" . trim((string) $pair['approved']));
    }

    /**
     * Store fingerprints on recorded strings, and open changed strings again when asked.
     *
     * @param   string   $targetLanguage  The target language code.
     * @param   array    $fingerprints    The fingerprint to store, keyed by string id.
     * @param   boolean  $reopen          Whether the strings are opened again with fresh attempts.
     *
     * @return  void
     *
     * @since   1.2.0
     */
    private function storeFingerprints(string $targetLanguage, array $fingerprints, bool $reopen): void
    {
        if ($fingerprints === []) {
            return;
        }

        $retry     = self::STATUS_RETRY;
        $lastError = '';

        $this->db->transactionStart();

        try {
            foreach ($fingerprints as $stringId => $fingerprint) {
                $stringId = (string) $stringId;
                $query    = $this->db->getQuery(true)
                    ->update($this->db->quoteName('#__translations_seeded_strings'))
                    ->set($this->db->quoteName('fingerprint') . ' = :fingerprint')
                    ->where($this->db->quoteName('target_language') . ' = :targetLanguage')
                    ->where($this->db->quoteName('string_id') . ' = :stringId')
                    ->bind(':fingerprint', $fingerprint, ParameterType::STRING)
                    ->bind(':targetLanguage', $targetLanguage, ParameterType::STRING)
                    ->bind(':stringId', $stringId, ParameterType::STRING);

                if ($reopen) {
                    $query->set($this->db->quoteName('status') . ' = :retry')
                        ->set($this->db->quoteName('attempts') . ' = 0')
                        ->set($this->db->quoteName('last_error') . ' = :lastError')
                        ->bind(':retry', $retry, ParameterType::STRING)
                        ->bind(':lastError', $lastError, ParameterType::STRING);
                }

                $this->db->setQuery($query)->execute();
            }

            $this->db->transactionCommit();
        } catch (\Throwable $e) {
            $this->db->transactionRollback();

            throw $e;
        }
    }

    /**
     * Read back what is recorded for the given strings in the language.
     *
     * @param   string    $targetLanguage  The target language code.
     * @param   string[]  $stringIds       The string ids to look for.
     *
     * @return  array  Status, attempts and fingerprint keyed by string id, for the strings recorded.
     *
     * @since   1.1.0
     */
    private function seedStates(string $targetLanguage, array $stringIds): array
    {
        $query = $this->db->getQuery(true)
            ->select($this->db->quoteName(['string_id', 'status', 'attempts', 'fingerprint']))
            ->from($this->db->quoteName('#__translations_seeded_strings'))
            ->where($this->db->quoteName('target_language') . ' = :targetLanguage')
            ->whereIn($this->db->quoteName('string_id'), $stringIds, ParameterType::STRING)
            ->bind(':targetLanguage', $targetLanguage, ParameterType::STRING);
        $this->db->setQuery($query);

        $states = [];

        foreach ($this->db->loadAssocList() ?: [] as $row) {
            $states[(string) $row['string_id']] = [
                'status'      => (string) $row['status'],
                'attempts'    => (int) $row['attempts'],
                'fingerprint' => (string) $row['fingerprint'],
            ];
        }

        return $states;
    }

    /**
     * Group the pairs by their source text, so a text that occurs in several places is
     * translated once.
     *
     * A pack translates the same text the same way wherever it occurs - the site, administrator
     * and API files repeat many strings - so sending each occurrence would pay for one answer
     * several times, and give the distiller the same correction several times over. A group
     * goes out under the key of its first string, for context, and has the most attempts any
     * of its strings has had.
     *
     * @param   array  $pairs  The pairs, keyed by string id.
     *
     * @return  array  One unit per distinct source text: key, source, attempts and its pairs.
     *
     * @since   1.2.0
     */
    private static function units(array $pairs): array
    {
        $units = [];

        foreach ($pairs as $pair) {
            $source   = $pair['source'];
            $attempts = (int) ($pair['attempts'] ?? 0);

            if (!isset($units[$source])) {
                $units[$source] = ['key' => $pair['key'], 'source' => $source, 'attempts' => $attempts, 'pairs' => []];
            }

            $units[$source]['attempts'] = max($units[$source]['attempts'], $attempts);
            $units[$source]['pairs'][]  = $pair;
        }

        return array_values($units);
    }

    /**
     * Split the units into requests, each keyed by language key.
     *
     * The key tells a provider what a string is for, which a bare string does not, but a handful
     * of keys are used for different text in different files. A chunk therefore ends early rather
     * than let one of those overwrite the other.
     *
     * A text that failed before goes in a smaller request: half the size on its second attempt,
     * on its own on its last. A text that keeps failing is so narrowed down without paying for a
     * request per text as soon as one request fails. The texts that failed most come first.
     *
     * @param   array    $units        The units to send.
     * @param   integer  $requestSize  The most units in a first request.
     *
     * @return  array  The chunks, each an array of units keyed by language key.
     *
     * @since   1.0.0
     */
    private function requestChunks(array $units, int $requestSize): array
    {
        $byAttempts = [];

        foreach ($units as $unit) {
            $byAttempts[(int) $unit['attempts']][] = $unit;
        }

        krsort($byAttempts);

        $chunks = [];

        foreach ($byAttempts as $attempts => $group) {
            $limit = RunResult::requestLimit($requestSize, $attempts);
            $chunk = [];

            foreach ($group as $unit) {
                if (isset($chunk[$unit['key']]) || \count($chunk) >= $limit) {
                    $chunks[] = $chunk;
                    $chunk    = [];
                }

                $chunk[$unit['key']] = $unit;
            }

            // A group holds at least one unit, so its last chunk is never empty.
            $chunks[] = $chunk;
        }

        return $chunks;
    }

    /**
     * Count an attempt for the strings of a chunk, before its request is sent.
     *
     * Counting first means a run that is killed while it waits for the provider still uses up
     * one of the strings' attempts, so even a request that never returns is not repeated forever.
     *
     * @param   array   $chunk           The units about to be sent, keyed by language key.
     * @param   string  $targetLanguage  The target language code.
     *
     * @return  void
     *
     * @since   1.1.0
     */
    private function countAttempt(array $chunk, string $targetLanguage): void
    {
        // A string with a record - one that failed before, or one opened again because it changed -
        // is updated; a string sent for the first time gets its record, with its fingerprint.
        $recorded = [];
        $new      = [];

        foreach ($chunk as $unit) {
            foreach ($unit['pairs'] as $pair) {
                $stringId = $pair['file'] . '#' . $pair['key'];

                if ($pair['recorded'] ?? (($pair['attempts'] ?? 0) > 0)) {
                    $recorded[] = $stringId;
                } else {
                    $new[$stringId] = (string) ($pair['fingerprint'] ?? '');
                }
            }
        }

        if ($recorded !== []) {
            $retry = self::STATUS_RETRY;
            $query = $this->db->getQuery(true)
                ->update($this->db->quoteName('#__translations_seeded_strings'))
                ->set($this->db->quoteName('attempts') . ' = ' . $this->db->quoteName('attempts') . ' + 1')
                ->set($this->db->quoteName('status') . ' = :retry')
                ->where($this->db->quoteName('target_language') . ' = :targetLanguage')
                ->whereIn($this->db->quoteName('string_id'), $recorded, ParameterType::STRING)
                ->bind(':retry', $retry, ParameterType::STRING)
                ->bind(':targetLanguage', $targetLanguage, ParameterType::STRING);
            $this->db->setQuery($query)->execute();
        }

        if ($new !== []) {
            $query = $this->db->getQuery(true)
                ->insert($this->db->quoteName('#__translations_seeded_strings'))
                ->columns($this->db->quoteName(['target_language', 'string_id', 'status', 'attempts', 'fingerprint']));

            foreach ($new as $stringId => $fingerprint) {
                $query->values(
                    implode(
                        ',',
                        $query->bindArray(
                            [$targetLanguage, (string) $stringId, self::STATUS_RETRY, 1, $fingerprint],
                            [
                                ParameterType::STRING,
                                ParameterType::STRING,
                                ParameterType::STRING,
                                ParameterType::INTEGER,
                                ParameterType::STRING,
                            ]
                        )
                    )
                );
            }

            $this->db->setQuery($query)->execute();
        }
    }

    /**
     * Record a translated chunk: the feedback it produced, and that its strings are seeded.
     *
     * A text the provider translated exactly as the pack does carries no correction to learn
     * from, so it writes no feedback. Its strings are still marked seeded, because the call it
     * took has been paid for either way. A text the provider passed over counts as a failed
     * attempt for its strings, so a later run asks for it again, up to its last attempt.
     *
     * A text writes one feedback row per distinct way the pack translates it, with the number of
     * strings that row stands for, so the distiller weighs a correction by how often it occurs
     * without reading it several times.
     *
     * The feedback and the seeded marks are written in one transaction, so a chunk is recorded
     * whole or not at all.
     *
     * @param   array      $chunk           The units sent, keyed by language key.
     * @param   array      $translated      The provider's translations, keyed as sent.
     * @param   string     $targetLanguage  The target language code.
     * @param   RunResult  $result          The run's result, updated in place.
     *
     * @return  void
     *
     * @since   1.0.0
     */
    private function recordChunk(array $chunk, array $translated, string $targetLanguage, RunResult $result): void
    {
        $seeded  = [];
        $missing = [];

        $this->db->transactionStart();

        try {
            foreach ($chunk as $key => $unit) {
                $stringIds    = self::stringIds([$unit]);
                $machineDraft = trim((string) ($translated[$key] ?? ''));

                if ($machineDraft === '') {
                    $missing = array_merge($missing, $stringIds);

                    continue;
                }

                $seeded = array_merge($seeded, $stringIds);

                foreach (self::approvedTranslations($unit) as $approved => $occurrences) {
                    if ($machineDraft === $approved) {
                        continue;
                    }

                    $row = (object) [
                        'queue_id'         => 0,
                        'source_text'      => $unit['source'],
                        'machine_draft'    => $machineDraft,
                        'human_correction' => $approved,
                        'target_language'  => $targetLanguage,
                        'source_origin'    => self::SOURCE_ORIGIN,
                        'translator_id'    => 0,
                        'occurrences'      => $occurrences,
                    ];

                    $this->db->insertObject('#__translations_feedback', $row);
                }
            }

            $this->markSeeded($seeded, $targetLanguage);
            $this->db->transactionCommit();
        } catch (\Throwable $e) {
            $this->db->transactionRollback();
            $this->recordFailure(self::stringIds($chunk), $targetLanguage, $e->getMessage(), $result);

            return;
        }

        $result->processed += \count($seeded);

        if ($missing !== []) {
            $this->recordFailure(
                $missing,
                $targetLanguage,
                'The provider returned no translation for this string.',
                $result
            );
        }
    }

    /**
     * Mark strings as seeded for a language.
     *
     * @param   string[]  $stringIds       The string ids to mark.
     * @param   string    $targetLanguage  The target language code.
     *
     * @return  void
     *
     * @since   1.0.0
     */
    private function markSeeded(array $stringIds, string $targetLanguage): void
    {
        if ($stringIds === []) {
            return;
        }

        $status    = self::STATUS_SEEDED;
        $lastError = '';
        $query     = $this->db->getQuery(true)
            ->update($this->db->quoteName('#__translations_seeded_strings'))
            ->set($this->db->quoteName('status') . ' = :status')
            ->set($this->db->quoteName('last_error') . ' = :lastError')
            ->where($this->db->quoteName('target_language') . ' = :targetLanguage')
            ->whereIn($this->db->quoteName('string_id'), $stringIds, ParameterType::STRING)
            ->bind(':status', $status, ParameterType::STRING)
            ->bind(':lastError', $lastError, ParameterType::STRING)
            ->bind(':targetLanguage', $targetLanguage, ParameterType::STRING);

        $this->db->setQuery($query)->execute();
    }

    /**
     * Record a failed attempt: keep the error on its strings and set aside the ones whose
     * attempts are used up, so no later run pays for them again.
     *
     * @param   string[]   $stringIds       The string ids that failed.
     * @param   string     $targetLanguage  The target language code.
     * @param   string     $message         The error the attempt ended with.
     * @param   RunResult  $result          The run's result, updated in place.
     *
     * @return  void
     *
     * @since   1.1.0
     */
    private function recordFailure(array $stringIds, string $targetLanguage, string $message, RunResult $result): void
    {
        $lastError = RunResult::errorText($message);
        $query     = $this->db->getQuery(true)
            ->update($this->db->quoteName('#__translations_seeded_strings'))
            ->set($this->db->quoteName('last_error') . ' = :lastError')
            ->where($this->db->quoteName('target_language') . ' = :targetLanguage')
            ->whereIn($this->db->quoteName('string_id'), $stringIds, ParameterType::STRING)
            ->bind(':lastError', $lastError, ParameterType::STRING)
            ->bind(':targetLanguage', $targetLanguage, ParameterType::STRING);
        $this->db->setQuery($query)->execute();

        $failed      = self::STATUS_FAILED;
        $maxAttempts = RunResult::MAX_ATTEMPTS;
        $query       = $this->db->getQuery(true)
            ->update($this->db->quoteName('#__translations_seeded_strings'))
            ->set($this->db->quoteName('status') . ' = :failed')
            ->where($this->db->quoteName('target_language') . ' = :targetLanguage')
            ->whereIn($this->db->quoteName('string_id'), $stringIds, ParameterType::STRING)
            ->where($this->db->quoteName('attempts') . ' >= :maxAttempts')
            ->bind(':failed', $failed, ParameterType::STRING)
            ->bind(':targetLanguage', $targetLanguage, ParameterType::STRING)
            ->bind(':maxAttempts', $maxAttempts, ParameterType::INTEGER);
        $this->db->setQuery($query)->execute();

        $result->failed      += \count($stringIds);
        $result->quarantined += $this->db->getAffectedRows();
        $result->lastError    = $lastError;
    }

    /**
     * The string ids of all the pairs in a chunk's units.
     *
     * @param   array  $chunk  The units.
     *
     * @return  string[]  The string ids.
     *
     * @since   1.1.0
     */
    private static function stringIds(array $chunk): array
    {
        $stringIds = [];

        foreach ($chunk as $unit) {
            foreach ($unit['pairs'] as $pair) {
                $stringIds[] = $pair['file'] . '#' . $pair['key'];
            }
        }

        return $stringIds;
    }

    /**
     * The distinct ways the pack translates a unit's text, each with how many strings use it.
     *
     * @param   array  $unit  The unit.
     *
     * @return  array  The number of strings keyed by the approved translation.
     *
     * @since   1.2.0
     */
    private static function approvedTranslations(array $unit): array
    {
        $approved = [];

        foreach ($unit['pairs'] as $pair) {
            $translation            = trim($pair['approved']);
            $approved[$translation] = ($approved[$translation] ?? 0) + 1;
        }

        return $approved;
    }
}
