<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  Task.TranslationsSeed
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Plugin\Task\TranslationsSeed\Extension;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Component\Translations\Administrator\Helper\RunResult;
use Joomla\Component\Translations\Administrator\Helper\TimeBudget;
use Joomla\Component\Scheduler\Administrator\Event\ExecuteTaskEvent;
use Joomla\Component\Scheduler\Administrator\Task\Status;
use Joomla\Component\Scheduler\Administrator\Traits\TaskPluginTrait;
use Joomla\Database\DatabaseAwareTrait;
use Joomla\Event\SubscriberInterface;
use Joomla\Plugin\Task\TranslationsSeed\Helper\Seeder;

/**
 * Task plugin that seeds translation rules from an installed language pack.
 *
 * A language pack is a body of translations its language team has already agreed on, so it can
 * teach the Translations component that team's terminology without anyone correcting a draft
 * by hand. One batch of strings is seeded per execution, resuming until the pack is drained.
 *
 * @since  1.0.0
 */
final class TranslationsSeed extends CMSPlugin implements SubscriberInterface
{
    use TaskPluginTrait;
    use DatabaseAwareTrait;

    /**
     * The task routines this plugin offers.
     *
     * @var    string[][]
     * @since  1.0.0
     */
    protected const TASKS_MAP = [
        'translationsseed.seed' => [
            'langConstPrefix' => 'PLG_TASK_TRANSLATIONSSEED',
            'form'            => 'seed',
            'method'          => 'seed',
        ],
        'translationsseed.resetfailed' => [
            'langConstPrefix' => 'PLG_TASK_TRANSLATIONSSEED_RESETFAILED',
            'method'          => 'resetFailed',
        ],
        'translationsseed.forget' => [
            'langConstPrefix' => 'PLG_TASK_TRANSLATIONSSEED_FORGET',
            'form'            => 'forget',
            'method'          => 'forget',
        ],
    ];

    /**
     * Load the plugin language files automatically.
     *
     * @var    boolean
     * @since  1.0.0
     */
    protected $autoloadLanguage = true;

    /**
     * Returns the events this subscriber listens to.
     *
     * @return  array
     *
     * @since   1.0.0
     */
    public static function getSubscribedEvents(): array
    {
        return [
            'onTaskOptionsList'    => 'advertiseRoutines',
            'onExecuteTask'        => 'standardRoutineHandler',
            'onContentPrepareForm' => 'enhanceTaskItemForm',
        ];
    }

    /**
     * Seed feedback from one batch of a language pack.
     *
     * @param   ExecuteTaskEvent  $event  The onExecuteTask event.
     *
     * @return  integer  The task exit status.
     *
     * @since   1.0.0
     */
    protected function seed(ExecuteTaskEvent $event): int
    {
        $params         = $event->getArgument('params');
        $requestSize    = max(1, (int) ($params->request_size ?? Seeder::DEFAULT_REQUEST_SIZE));
        $budget         = new TimeBudget((int) ($params->time_budget ?? 0));
        $targetLanguage = (string) ($params->target_language ?? '');
        $fileNames      = array_filter(array_map('trim', explode(',', (string) ($params->files ?? ''))));
        $language       = $this->getApplication()->getLanguage();

        // This plugin is installed on its own, so the component it feeds may not be there at all.
        if (!ComponentHelper::isEnabled('com_translations')) {
            $message = $language->_('PLG_TASK_TRANSLATIONSSEED_LOG_NO_COMPONENT');
            $this->logTask($message, 'error');
            $this->snapshot['output']      = $message;
            $this->snapshot['output_body'] = $message;

            return Status::KNOCKOUT;
        }

        if ($targetLanguage === '') {
            $message = $language->_('PLG_TASK_TRANSLATIONSSEED_LOG_NO_LANGUAGE');
            $this->logTask($message, 'error');
            $this->snapshot['output']      = $message;
            $this->snapshot['output_body'] = $message;

            return Status::KNOCKOUT;
        }

        $sourceLanguage = (string) ComponentHelper::getParams('com_translations')->get('source_language', 'en-GB');

        // The provider answers on the application's dispatcher, which is where importPlugin registers it.
        $seeder = new Seeder($this->getDatabase(), $this->getApplication()->getDispatcher());

        try {
            $result = $seeder->seed($sourceLanguage, $targetLanguage, $requestSize, $budget, $fileNames);
        } catch (\Throwable $e) {
            return $this->knockout($e->getMessage());
        }

        if ($result->busy) {
            $this->logTask(\sprintf($language->_('PLG_TASK_TRANSLATIONSSEED_LOG_BUSY'), $targetLanguage));

            return Status::OK;
        }

        if ($result->processed === 0 && $result->failed === 0) {
            $this->logTask(\sprintf($language->_('PLG_TASK_TRANSLATIONSSEED_LOG_NONE'), $targetLanguage));

            return Status::OK;
        }

        if ($result->processed > 0) {
            $this->logTask(
                \sprintf(
                    $language->_('PLG_TASK_TRANSLATIONSSEED_LOG_SEEDED'),
                    $result->processed,
                    $targetLanguage,
                    $result->remaining
                )
            );
        }

        if ($result->failed > 0) {
            $this->logTask(
                \sprintf(
                    $language->_('PLG_TASK_TRANSLATIONSSEED_LOG_FAILED'),
                    $result->failed,
                    $result->quarantined,
                    $result->lastError
                ),
                'warning'
            );
        }

        // Resume only after progress: a batch that fails every time must not be retried run after run.
        switch ($result->outcome()) {
            case RunResult::RESUME:
                return Status::WILL_RESUME;

            case RunResult::ERROR:
                return $this->knockout($result->lastError);

            default:
                return Status::OK;
        }
    }

    /**
     * Give the strings that were set aside as failed a new set of attempts.
     *
     * @param   ExecuteTaskEvent  $event  The onExecuteTask event.
     *
     * @return  integer  The task exit status.
     *
     * @since   1.2.0
     */
    protected function resetFailed(ExecuteTaskEvent $event): int
    {
        try {
            $reset = (new Seeder($this->getDatabase(), $this->getApplication()->getDispatcher()))->resetFailed();
        } catch (\Throwable $e) {
            return $this->knockout($e->getMessage());
        }

        $this->logTask(\sprintf($this->getApplication()->getLanguage()->_('PLG_TASK_TRANSLATIONSSEED_RESETFAILED_LOG'), $reset));

        return Status::OK;
    }

    /**
     * Forget what was seeded for a language, so its pack can be seeded again from scratch.
     *
     * @param   ExecuteTaskEvent  $event  The onExecuteTask event.
     *
     * @return  integer  The task exit status.
     *
     * @since   1.2.0
     */
    protected function forget(ExecuteTaskEvent $event): int
    {
        $params           = $event->getArgument('params');
        $targetLanguage   = (string) ($params->target_language ?? '');
        $includePublished = (bool) ($params->include_published ?? false);

        try {
            $counts = (new Seeder($this->getDatabase(), $this->getApplication()->getDispatcher()))
                ->forget($targetLanguage, $includePublished);
        } catch (\Throwable $e) {
            return $this->knockout($e->getMessage());
        }

        $this->logTask(
            \sprintf(
                $this->getApplication()->getLanguage()->_('PLG_TASK_TRANSLATIONSSEED_FORGET_LOG'),
                $targetLanguage,
                $counts['strings'],
                $counts['feedback'],
                $counts['rules']
            )
        );

        return Status::OK;
    }

    /**
     * Log an error as the outcome of the run.
     *
     * @param   string  $message  The error message.
     *
     * @return  integer  The KNOCKOUT exit status.
     *
     * @since   1.1.0
     */
    private function knockout(string $message): int
    {
        $this->logTask($message, 'error');
        $this->snapshot['output']      = $message;
        $this->snapshot['output_body'] = $message;

        return Status::KNOCKOUT;
    }
}
