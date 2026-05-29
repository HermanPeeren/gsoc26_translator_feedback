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

use Joomla\CMS\Factory;
use Joomla\CMS\MVC\Factory\MVCFactoryInterface;
use Joomla\CMS\MVC\Model\ListModel;
use Joomla\Database\ParameterType;

/**
 * List model for the translation queue grid.
 *
 * One row per source-language article, target languages as columns.
 * getItems() then attaches each article's per language translation state
 * map via a single follow-up query.
 *
 * @since  0.1.0
 */
class QueueModel extends ListModel
{
    /**
     * Content type this queue handles (articles only, for now).
     *
     * @var    string
     * @since  0.1.0
     */
    private const CONTENT_TYPE = 'com_content.article'; // for now

    /**
     * Constructor.
     *
     * @param   array                     $config   An optional associative array of configuration settings.
     * @param   MVCFactoryInterface|null  $factory  The factory.
     *
     * @since   0.1.0
     */
    public function __construct($config = [], ?MVCFactoryInterface $factory = null)
    {
        if (empty($config['filter_fields'])) {
            $config['filter_fields'] = [
                'id', 'a.id',
                'title', 'a.title',
                'status',
                'languages',
            ];
        }

        parent::__construct($config, $factory);
    }

    /**
     * Method to auto-populate the model state.
     *
     * @param   string  $ordering   An optional ordering field.
     * @param   string  $direction  An optional direction (asc|desc).
     *
     * @return  void
     *
     * @since   0.1.0
     */
    protected function populateState($ordering = 'a.title', $direction = 'ASC')
    {
        /** @var \Joomla\CMS\Application\CMSApplication $app */
        $app   = Factory::getApplication(); // No DI because models are not application aware.
        $input = $app->getInput();

        $search = $this->getUserStateFromRequest($this->context . '.filter.search', 'filter_search');
        $this->setState('filter.search', $search);

        // Status and languages are multi-selects. Emptied multi-selects submit nothing,
        // so the helper can't clear them. Read the submitted 'filter' array directly;
        // fall back to stored state otherwise.
        $submittedFilter = $input->get('filter', null, 'array');

        $this->setState('filter.status', $this->getMultiFilterState('status', $submittedFilter));
        $this->setState('filter.languages', $this->getMultiFilterState('languages', $submittedFilter));

        parent::populateState($ordering, $direction);
    }

    /**
     * Multi-select filter value, with workaround for emptied-select-submits-nothing.
     *
     * @param   string      $name             Filter field name (e.g. 'status', 'languages').
     * @param   array|null  $submittedFilter  Submitted 'filter' array, or null on plain page load.
     *
     * @return  array  Selected values (empty = no filter).
     *
     * @since   0.1.0
     */
    private function getMultiFilterState(string $name, ?array $submittedFilter): array
    {
        if (is_array($submittedFilter)) {
            return (array) ($submittedFilter[$name] ?? []);
        }

        /** @var \Joomla\CMS\Application\CMSApplication $app */
        $app    = Factory::getApplication();
        $stored = (array) $app->getUserState($this->context . '.filter', []);

        return (array) ($stored[$name] ?? []);
    }

    /**
     * Method to get a store id based on model configuration state.
     *
     * @param   string  $id  A prefix for the store id.
     *
     * @return  string  A store id.
     *
     * @since   0.1.0
     */
    protected function getStoreId($id = '')
    {
        $id .= ':' . $this->getState('filter.search');
        $id .= ':' . implode(',', (array) $this->getState('filter.status'));
        $id .= ':' . implode(',', (array) $this->getState('filter.languages'));

        return parent::getStoreId($id);
    }

    /**
     * Build the list query: one row per source-language article (the grid rows).
     *
     * @return  \Joomla\Database\QueryInterface
     *
     * @since   0.1.0
     */
    protected function getListQuery()
    {
        $db     = $this->getDatabase();
        $query  = $db->getQuery(true);
        $source = 'en-GB';
        $ctype  = self::CONTENT_TYPE;

        $query->select(
            [
                $db->quoteName('a.id'),
                $db->quoteName('a.title'),
                $db->quoteName('a.language'),
                $db->quoteName('a.state'),
            ]
        )
            ->from($db->quoteName('#__content', 'a'))
            ->where($db->quoteName('a.language') . ' = :source')
            ->where($db->quoteName('a.state') . ' <> -2')
            ->bind(':source', $source);

        // Filter - search on article title(title LIKE), or "id:<n>" for direct lookup.
        $search = $this->getState('filter.search');

        if (!empty($search)) {
            if (stripos($search, 'id:') === 0) {
                $articleId = (int) substr($search, 3);
                $query->where($db->quoteName('a.id') . ' = :articleId')
                    ->bind(':articleId', $articleId, ParameterType::INTEGER);
            } else {
                $search = '%' . str_replace(' ', '%', $db->escape(trim($search), true)) . '%';
                $query->where($db->quoteName('a.title') . ' LIKE :search')
                    ->bind(':search', $search);
            }
        }

        // Filter - state (multi-select): show articles with a cell in ANY of the chosen states.
        // "__none__" = fully untranslated (no queue row for the article); real statuses match via EXISTS.
        // The two are combined with OR.
        $statuses = array_values(
            array_filter((array) $this->getState('filter.status'), static fn($s) => $s !== '')
        );

        if (!empty($statuses)) {
            $conditions   = [];
            $wantNone     = \in_array('__none__', $statuses, true);
            $realStatuses = array_values(array_filter($statuses, static fn($s) => $s !== '__none__'));

            if ($wantNone) {
                $subNone = $db->getQuery(true)
                    ->select('1')
                    ->from($db->quoteName('#__translations_queue', 'qn'))
                    ->innerJoin(
                        $db->quoteName('#__translations_queue_states', 'sn')
                        . ' ON ' . $db->quoteName('sn.queue_id') . ' = ' . $db->quoteName('qn.id')
                    )
                    ->where($db->quoteName('qn.content_id') . ' = ' . $db->quoteName('a.id'))
                    ->where($db->quoteName('qn.content_type') . ' = ' . $db->quote($ctype));
                $conditions[] = 'NOT EXISTS (' . $subNone . ')';
            }

            if (!empty($realStatuses)) {
                // Bind on the main query so the placeholders resolve once the
                // subquery is embedded as a string.
                $placeholders = $query->bindArray($realStatuses, ParameterType::STRING);

                $subReal = $db->getQuery(true)
                    ->select('1')
                    ->from($db->quoteName('#__translations_queue', 'qs'))
                    ->innerJoin(
                        $db->quoteName('#__translations_queue_states', 'ss')
                        . ' ON ' . $db->quoteName('ss.queue_id') . ' = ' . $db->quoteName('qs.id')
                    )
                    ->where($db->quoteName('qs.content_id') . ' = ' . $db->quoteName('a.id'))
                    ->where($db->quoteName('qs.content_type') . ' = ' . $db->quote($ctype))
                    ->where($db->quoteName('ss.translation_state') . ' IN (' . implode(',', $placeholders) . ')');
                $conditions[] = 'EXISTS (' . $subReal . ')';
            }

            if (!empty($conditions)) {
                $query->where('(' . implode(' OR ', $conditions) . ')');
            }
        }

        // Ordering (whitelisted to a.title and a.id via filter_fields).
        $orderCol  = $this->state->get('list.ordering', 'a.title');
        $orderDirn = $this->state->get('list.direction', 'ASC');
        $query->order($db->escape($orderCol) . ' ' . $db->escape($orderDirn));

        return $query;
    }

    /**
     * Target-language grid columns: enabled languages minus source and '*', narrowed by filter.
     *
     * @return  object[]  Keyed by lang_code (lang_code, title).
     *
     * @since   0.1.0
     */
    public function getTargetLanguages(): array
    {
        $db     = $this->getDatabase();
        $query  = $db->getQuery(true);
        $source = 'en-GB';

        $query->select(
            [
                $db->quoteName('lang_code'),
                $db->quoteName('title'),
            ]
        )
            ->from($db->quoteName('#__languages'))
            ->where($db->quoteName('published') . ' = 1')
            ->where($db->quoteName('lang_code') . ' <> ' . $db->quote('*'))
            ->where($db->quoteName('lang_code') . ' <> :source')
            ->bind(':source', $source)
            ->order($db->quoteName('ordering') . ' ASC');

        $selected = array_values(array_filter((array) $this->getState('filter.languages')));

        if (!empty($selected)) {
            $query->whereIn($db->quoteName('lang_code'), $selected, ParameterType::STRING);
        }

        $db->setQuery($query);

        return $db->loadObjectList('lang_code');
    }

    /**
     * Load articles and attach each one's per language state map.
     *
     * @return  object[]  Articles with ->states[langCode] => status map.
     *
     * @since   0.1.0
     */
    public function getItems()
    {
        $items = parent::getItems();

        if (empty($items)) {
            return $items;
        }

        $ids = [];

        foreach ($items as $item) {
            $ids[] = (int) $item->id;
        }

        $db    = $this->getDatabase();
        $ctype = self::CONTENT_TYPE;
        $query = $db->getQuery(true)
            ->select(
                [
                    $db->quoteName('q.content_id'),
                    $db->quoteName('s.target_language'),
                    $db->quoteName('s.translation_state', 'status'),
                ]
            )
            ->from($db->quoteName('#__translations_queue', 'q'))
            ->innerJoin(
                $db->quoteName('#__translations_queue_states', 's')
                . ' ON ' . $db->quoteName('s.queue_id') . ' = ' . $db->quoteName('q.id')
            )
            ->where($db->quoteName('q.content_type') . ' = :ctype')
            ->whereIn($db->quoteName('q.content_id'), $ids, ParameterType::INTEGER)
            ->bind(':ctype', $ctype)
            ->order($db->quoteName('s.id') . ' ASC');

        $db->setQuery($query);
        $rows = $db->loadObjectList() ?: [];

        return self::applyStates($items, $rows);
    }

    /**
     * Fold queue rows into each article's per language state map. Pure (no DB).
     * Rows must be id ASC so a later row overwrites an earlier one (latest status wins per article+language).
     *
     * @param   object[]  $items  Source articles.
     * @param   object[]  $rows   Queue rows (content_id, target_language, status), id ASC.
     *
     * @return  object[]  Articles with ->states map attached.
     *
     * @since   0.1.0
     */
    protected static function applyStates(array $items, array $rows): array
    {
        $statesByArticle = [];

        foreach ($rows as $row) {
            $statesByArticle[(int) $row->content_id][$row->target_language] = $row->status;
        }

        foreach ($items as $item) {
            $item->states = $statesByArticle[(int) $item->id] ?? [];
        }

        return $items;
    }
}
