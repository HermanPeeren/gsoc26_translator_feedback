<?php

/**
 * @package     Joomla.Tests
 * @subpackage  pkg_translations
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Joomla\Component\Translations\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * That an existing site and a new one end up with the same tables.
 *
 * A new site gets its tables from the install file; an existing one gets them from the update
 * files, which Joomla runs once each. A column added to one and not the other fails only on the
 * sites that took the other path. An update file that is run again, for instance after its
 * schema version was lost, must skip what is already there: Joomla skips a statement that fails
 * only when it ends in its CAN FAIL marker, and recognises the marker only when nothing stands
 * between it and the semicolon. A misplaced marker is stripped as a comment and the statement
 * then stops the update, with nothing in the file to show it.
 *
 * @since  1.3.0
 */
final class SchemaUpdateTest extends TestCase
{
    /**
     * Joomla's marker for a statement that may fail, as Installer::parseSchemaUpdates() reads it.
     *
     * @var    string
     * @since  1.3.0
     */
    private const CAN_FAIL_MARKER = '/** CAN FAIL **/';

    /**
     * The extensions that update their own tables, with their install file and update folder.
     *
     * @return  array<string, array{string, string}>
     *
     * @since   1.3.0
     */
    public static function schemas(): array
    {
        return [
            'com_translations' => [
                'administrator/components/com_translations/sql/install.mysql.utf8.sql',
                'administrator/components/com_translations/sql/updates/mysql',
            ],
            'plg_task_translationsseed' => [
                'plugins/task/translationsseed/sql/install.mysql.utf8.sql',
                'plugins/task/translationsseed/sql/updates/mysql',
            ],
        ];
    }

    /**
     * Every column or index an update adds can be added again without stopping the update.
     *
     * @param   string  $installFile   The install file, relative to the source tree.
     * @param   string  $updateFolder  The update folder, relative to the source tree.
     *
     * @return  void
     *
     * @dataProvider  schemas
     *
     * @since   1.3.0
     */
    public function testEveryAdditionCanFailWithItsMarkerAgainstTheSemicolon(string $installFile, string $updateFolder): void
    {
        foreach (self::updateStatements($updateFolder) as $file => $statements) {
            foreach ($statements as $statement) {
                if (!preg_match('/\bADD\s+(COLUMN|INDEX|KEY)\b/i', $statement)) {
                    continue;
                }

                $this->assertStringEndsWith(
                    self::CAN_FAIL_MARKER,
                    $statement,
                    \sprintf('%s: an addition ends in %s directly before its semicolon', $file, self::CAN_FAIL_MARKER)
                );
            }
        }
    }

    /**
     * Every column an update adds is declared in the install file too, in the same table.
     *
     * @param   string  $installFile   The install file, relative to the source tree.
     * @param   string  $updateFolder  The update folder, relative to the source tree.
     *
     * @return  void
     *
     * @dataProvider  schemas
     *
     * @since   1.3.0
     */
    public function testEveryAddedColumnIsInTheInstallFile(string $installFile, string $updateFolder): void
    {
        $installed = self::installedColumns($installFile);

        foreach (self::updateStatements($updateFolder) as $file => $statements) {
            foreach ($statements as $statement) {
                if (!preg_match('/ALTER\s+TABLE\s+`(#__\w+)`\s+ADD\s+COLUMN\s+`(\w+)`/i', $statement, $match)) {
                    continue;
                }

                $this->assertContains(
                    $match[2],
                    $installed[$match[1]] ?? [],
                    \sprintf('%s adds %s.%s, so a new site needs it from %s as well', $file, $match[1], $match[2], basename($installFile))
                );
            }
        }
    }

    /**
     * The statements of each update file, keyed by file name, with comment lines left out.
     *
     * Each statement keeps what stands before its semicolon exactly, so a space between a marker
     * and the semicolon is still there to be seen.
     *
     * @param   string  $updateFolder  The update folder, relative to the source tree.
     *
     * @return  array<string, string[]>
     *
     * @since   1.3.0
     */
    private static function updateStatements(string $updateFolder): array
    {
        $files = glob(TRANSLATIONS_TEST_SRC . '/' . $updateFolder . '/*.sql') ?: [];
        $found = [];

        foreach ($files as $file) {
            $lines = preg_grep('/^\s*--/', file($file, FILE_IGNORE_NEW_LINES) ?: [], PREG_GREP_INVERT);
            $parts = explode(';', implode("\n", $lines));

            // The text after the last semicolon is not a statement.
            array_pop($parts);

            $found[basename($file)] = array_map('ltrim', $parts);
        }

        return $found;
    }

    /**
     * The columns the install file declares, keyed by table.
     *
     * @param   string  $installFile  The install file, relative to the source tree.
     *
     * @return  array<string, string[]>
     *
     * @since   1.3.0
     */
    private static function installedColumns(string $installFile): array
    {
        $sql     = (string) file_get_contents(TRANSLATIONS_TEST_SRC . '/' . $installFile);
        $columns = [];

        preg_match_all('/CREATE\s+TABLE\s+IF\s+NOT\s+EXISTS\s+`(#__\w+)`\s*\((.*?)\)\s*ENGINE/is', $sql, $tables, PREG_SET_ORDER);

        foreach ($tables as [, $table, $body]) {
            preg_match_all('/^\s*`(\w+)`/m', $body, $names);
            $columns[$table] = $names[1];
        }

        return $columns;
    }
}
