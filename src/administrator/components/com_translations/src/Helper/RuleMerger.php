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
use Joomla\Database\ParameterType;

/**
 * Tells when two rules say the same thing, and merges the ones that do.
 *
 * A terminology rule says a source term is translated a certain way, and a preservation rule
 * that a term is kept as it is. Two such rules for the same language, with the same term and the
 * same translation, are one rule written twice - the distiller wrote over five hundred of them
 * per language before it looked for an existing rule first. A style rule is free text, so it is
 * never treated as a duplicate.
 *
 * @since  1.2.0
 */
final class RuleMerger
{
    /**
     * The rule types whose rules are compared by term.
     *
     * @var    string[]
     * @since  1.2.0
     */
    private const TERM_TYPES = ['terminology', 'preservation'];

    /**
     * The rule states that count: draft and published. A trashed rule is left alone.
     *
     * @var    int[]
     * @since  1.2.0
     */
    private const LIVE_STATES = [0, 1];

    /**
     * The state a merged-away rule is moved to: trashed, so it can still be restored.
     *
     * @var    integer
     * @since  1.2.0
     */
    private const TRASHED = -2;

    /**
     * What makes a rule the same as another: its language, type, term and translation.
     *
     * The term is compared in its standard form when it has one, so "article" and "articles"
     * meet, and the comparison ignores case and surrounding space.
     *
     * @param   array  $rule  The rule's columns: target_language, rule_type, source_term,
     *                        source_term_standard and target_term.
     *
     * @return  string|null  The key, or null for a rule that is never merged.
     *
     * @since   1.2.0
     */
    public static function key(array $rule): ?string
    {
        $type = (string) ($rule['rule_type'] ?? '');

        if (!\in_array($type, self::TERM_TYPES, true)) {
            return null;
        }

        $term = trim((string) ($rule['source_term_standard'] ?? ''));

        if ($term === '') {
            $term = trim((string) ($rule['source_term'] ?? ''));
        }

        if ($term === '') {
            return null;
        }

        return implode(
            "\x1F",
            [
                (string) ($rule['target_language'] ?? ''),
                $type,
                mb_strtolower($term),
                mb_strtolower(trim((string) ($rule['target_term'] ?? ''))),
            ]
        );
    }

    /**
     * Find a live rule that says the same as the given one.
     *
     * @param   DatabaseInterface  $db    The database driver.
     * @param   array              $rule  The rule's columns, as for key().
     *
     * @return  integer  The id of the oldest such rule, or 0 when there is none.
     *
     * @since   1.2.0
     */
    public static function findSame(DatabaseInterface $db, array $rule): int
    {
        $key = self::key($rule);

        if ($key === null) {
            return 0;
        }

        $language = (string) $rule['target_language'];
        $type     = (string) $rule['rule_type'];
        $term     = mb_strtolower(trim((string) ($rule['source_term'] ?? '')));
        $standard = mb_strtolower(trim((string) ($rule['source_term_standard'] ?? '')));
        $query    = $db->getQuery(true)
            ->select($db->quoteName(['id', 'target_language', 'rule_type', 'source_term', 'source_term_standard', 'target_term']))
            ->from($db->quoteName('#__translations_rules'))
            ->where($db->quoteName('target_language') . ' = :language')
            ->where($db->quoteName('rule_type') . ' = :type')
            ->whereIn($db->quoteName('state'), self::LIVE_STATES)
            ->extendWhere(
                'AND',
                [
                    'LOWER(' . $db->quoteName('source_term') . ') = :term',
                    'LOWER(' . $db->quoteName('source_term_standard') . ') = :standard',
                ],
                'OR'
            )
            ->order($db->quoteName('id') . ' ASC')
            ->bind(':language', $language, ParameterType::STRING)
            ->bind(':type', $type, ParameterType::STRING)
            ->bind(':term', $term, ParameterType::STRING)
            ->bind(':standard', $standard, ParameterType::STRING);
        $db->setQuery($query);

        foreach ($db->loadAssocList() ?: [] as $row) {
            if (self::key($row) === $key) {
                return (int) $row['id'];
            }
        }

        return 0;
    }

    /**
     * Merge the live rules that say the same thing.
     *
     * Of each set the oldest rule is kept. It takes over the evidence of the others, the highest
     * confidence among them, and is published when any of them was; the others are trashed, so
     * an administrator can still restore one.
     *
     * @param   DatabaseInterface  $db        The database driver.
     * @param   string             $language  The target language to merge, all languages when empty.
     *
     * @return  integer  The number of rules trashed.
     *
     * @since   1.2.0
     */
    public static function mergeDuplicates(DatabaseInterface $db, string $language = ''): int
    {
        $query = $db->getQuery(true)
            ->select(
                $db->quoteName(
                    [
                        'id', 'target_language', 'rule_type', 'source_term', 'source_term_standard',
                        'target_term', 'confidence', 'source_feedback_ids', 'state',
                    ]
                )
            )
            ->from($db->quoteName('#__translations_rules'))
            ->whereIn($db->quoteName('rule_type'), self::TERM_TYPES, ParameterType::STRING)
            ->whereIn($db->quoteName('state'), self::LIVE_STATES)
            ->order($db->quoteName('id') . ' ASC');

        if ($language !== '') {
            $query->where($db->quoteName('target_language') . ' = :language')
                ->bind(':language', $language, ParameterType::STRING);
        }

        $db->setQuery($query);

        $groups = [];

        foreach ($db->loadAssocList() ?: [] as $row) {
            $key = self::key($row);

            if ($key !== null) {
                $groups[$key][] = $row;
            }
        }

        $trashed = 0;

        foreach ($groups as $rows) {
            if (\count($rows) < 2) {
                continue;
            }

            $keeper     = array_shift($rows);
            $evidence   = self::feedbackIds($keeper['source_feedback_ids']);
            $confidence = (float) $keeper['confidence'];
            $state      = (int) $keeper['state'];
            $mergedIds  = [];

            foreach ($rows as $row) {
                $evidence    = array_merge($evidence, self::feedbackIds($row['source_feedback_ids']));
                $confidence  = max($confidence, (float) $row['confidence']);
                $state       = max($state, (int) $row['state']);
                $mergedIds[] = (int) $row['id'];
            }

            $db->transactionStart();

            try {
                $keeperId     = (int) $keeper['id'];
                $evidenceJson = json_encode(array_values(array_unique($evidence)));
                $update       = $db->getQuery(true)
                    ->update($db->quoteName('#__translations_rules'))
                    ->set($db->quoteName('source_feedback_ids') . ' = :evidence')
                    ->set($db->quoteName('confidence') . ' = :confidence')
                    ->set($db->quoteName('state') . ' = :state')
                    ->where($db->quoteName('id') . ' = :id')
                    ->bind(':evidence', $evidenceJson, ParameterType::STRING)
                    ->bind(':confidence', $confidence)
                    ->bind(':state', $state, ParameterType::INTEGER)
                    ->bind(':id', $keeperId, ParameterType::INTEGER);
                $db->setQuery($update)->execute();

                $trashedState = self::TRASHED;
                $trash        = $db->getQuery(true)
                    ->update($db->quoteName('#__translations_rules'))
                    ->set($db->quoteName('state') . ' = :trashed')
                    ->whereIn($db->quoteName('id'), $mergedIds)
                    ->bind(':trashed', $trashedState, ParameterType::INTEGER);
                $db->setQuery($trash)->execute();

                $db->transactionCommit();
            } catch (\Throwable $e) {
                $db->transactionRollback();

                throw $e;
            }

            $trashed += \count($mergedIds);
        }

        return $trashed;
    }

    /**
     * Read a rule's stored evidence.
     *
     * @param   mixed  $stored  The stored source_feedback_ids: a JSON list, or empty.
     *
     * @return  int[]  The feedback ids.
     *
     * @since   1.2.0
     */
    private static function feedbackIds($stored): array
    {
        $decoded = \is_string($stored) && $stored !== '' ? json_decode($stored, true) : [];

        return \is_array($decoded) ? array_map('intval', $decoded) : [];
    }
}
