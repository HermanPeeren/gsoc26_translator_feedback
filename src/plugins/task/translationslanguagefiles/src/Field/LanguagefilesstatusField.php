<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  Task.TranslationsLanguageFiles
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Plugin\Task\TranslationsLanguageFiles\Field;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use Joomla\CMS\Factory;
use Joomla\CMS\Form\FormField;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;
use Joomla\Database\DatabaseInterface;
use Joomla\Event\DispatcherInterface;
use Joomla\Plugin\Task\TranslationsLanguageFiles\Extension\TranslationsLanguageFiles;
use Joomla\Plugin\Task\TranslationsLanguageFiles\Helper\LanguageFileTranslator;

/**
 * Shows in the task's form how far each language has got, with the button that downloads the files.
 *
 * @since  1.0.0
 */
class LanguagefilesstatusField extends FormField
{
    /**
     * The form field type.
     *
     * @var    string
     * @since  1.0.0
     */
    protected $type = 'Languagefilesstatus';

    /**
     * The field shows the whole width of the form.
     *
     * @var    boolean
     * @since  1.0.0
     */
    protected $hiddenLabel = true;

    /**
     * Render the progress table and the download button.
     *
     * @return  string
     *
     * @since   1.0.0
     */
    protected function getInput()
    {
        $db         = Factory::getContainer()->get(DatabaseInterface::class);
        $translator = new LanguageFileTranslator($db, Factory::getContainer()->get(DispatcherInterface::class));

        try {
            $status = $translator->status(TranslationsLanguageFiles::targetLanguages($db));
        } catch (\Throwable $e) {
            return '<div class="alert alert-warning">' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</div>';
        }

        $rows = '';

        foreach ($status as $language => $counts) {
            $rows .= \sprintf(
                '<tr><th scope="row">%s</th><td>%d / %d</td><td>%d</td></tr>',
                htmlspecialchars($language, ENT_QUOTES, 'UTF-8'),
                $counts['translated'],
                $counts['total'],
                $counts['failed']
            );
        }

        $url = Route::_(TranslationsLanguageFiles::DOWNLOAD_URL, false);

        return '<table class="table table-sm w-auto"><thead><tr>'
            . '<th scope="col">' . Text::_('PLG_TASK_TRANSLATIONSLANGUAGEFILES_STATUS_LANGUAGE') . '</th>'
            . '<th scope="col">' . Text::_('PLG_TASK_TRANSLATIONSLANGUAGEFILES_STATUS_TRANSLATED') . '</th>'
            . '<th scope="col">' . Text::_('PLG_TASK_TRANSLATIONSLANGUAGEFILES_STATUS_FAILED') . '</th>'
            . '</tr></thead><tbody>' . $rows . '</tbody></table>'
            . '<a class="btn btn-primary" href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">'
            . '<span class="icon-download" aria-hidden="true"></span> '
            . Text::_('PLG_TASK_TRANSLATIONSLANGUAGEFILES_DOWNLOAD') . '</a>';
    }
}
