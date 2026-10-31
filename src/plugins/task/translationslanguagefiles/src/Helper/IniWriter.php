<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  Task.TranslationsLanguageFiles
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Plugin\Task\TranslationsLanguageFiles\Helper;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Writes a translated language file in the shape of its source.
 *
 * The source file is followed line by line, so the translation keeps its header, its comments,
 * its blank lines and the order of its keys, and a diff between the two languages lines up. Only
 * the value of a key that has a translation changes; any other line is copied as it is, which
 * leaves a string not translated yet in the source language rather than missing.
 *
 * @since  1.0.0
 */
final class IniWriter
{
    /**
     * Matches a key and its quoted value on one line.
     *
     * @var    string
     * @since  1.0.0
     */
    private const LINE = '/^(\s*)([^;=\s"]+)(\s*=\s*)"(.*)"(\s*)$/';

    /**
     * Write the translated file.
     *
     * @param   string                 $source        The contents of the source language file.
     * @param   array<string, string>  $translations  The translated values, keyed by language constant.
     *
     * @return  string  The contents of the translated file.
     *
     * @throws  \RuntimeException  When the file would not read back as the strings it was given.
     *
     * @since   1.0.0
     */
    public static function write(string $source, array $translations): string
    {
        $lines = preg_split('/\r\n|\n|\r/', $source);

        foreach ($lines as $number => $line) {
            if (!preg_match(self::LINE, $line, $match) || !isset($translations[$match[2]])) {
                continue;
            }

            $lines[$number] = $match[1] . $match[2] . $match[3] . '"' . self::escape($translations[$match[2]]) . '"';
        }

        $contents = implode("\n", $lines);

        self::check($source, $contents, $translations);

        return $contents;
    }

    /**
     * Make a value safe inside the double quotes of a language file.
     *
     * Joomla reads a language file raw and only turns \" back into a quote, so that is the one
     * escape a value can have. A line break would end the value, and a backslash before the
     * closing quote would escape it, so neither is kept.
     *
     * @param   string  $value  The value.
     *
     * @return  string  The value as it is written between the quotes.
     *
     * @since   1.0.0
     */
    public static function escape(string $value): string
    {
        $value = trim((string) preg_replace('/\s*[\r\n]+\s*/', ' ', $value));
        $value = rtrim($value, '\\');

        return str_replace('"', '\"', str_replace('\"', '"', $value));
    }

    /**
     * Read the file back the way Joomla does, and compare it with what was meant.
     *
     * @param   string  $source        The source language file.
     * @param   string  $contents      The translated file.
     * @param   array   $translations  The translated values.
     *
     * @return  void
     *
     * @throws  \RuntimeException  When a value reads back differently, or the file does not read at all.
     *
     * @since   1.0.0
     */
    private static function check(string $source, string $contents, array $translations): void
    {
        $expected = self::read($source);
        $actual   = self::read($contents);

        foreach (array_intersect_key($translations, $expected) as $key => $value) {
            $expected[$key] = str_replace('\"', '"', self::escape($value));
        }

        foreach ($expected as $key => $value) {
            if (($actual[$key] ?? null) !== $value) {
                throw new \RuntimeException(\sprintf('The translation of %s does not read back as written.', $key));
            }
        }
    }

    /**
     * Read the strings of a language file's contents the way Joomla's LanguageHelper does.
     *
     * @param   string  $contents  The file's contents.
     *
     * @return  array<string, string>  The strings.
     *
     * @throws  \RuntimeException  When the contents cannot be read.
     *
     * @since   1.0.0
     */
    private static function read(string $contents): array
    {
        $strings = @parse_ini_string($contents, false, INI_SCANNER_RAW);

        if (!\is_array($strings)) {
            throw new \RuntimeException('A translated language file could not be read back.');
        }

        return str_replace('\"', '"', $strings);
    }
}
