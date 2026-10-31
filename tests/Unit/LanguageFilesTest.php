<?php

/**
 * @package     Joomla.Tests
 * @subpackage  plg_task_translationslanguagefiles
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Joomla\Component\Translations\Tests\Unit;

use Joomla\Plugin\Task\TranslationsLanguageFiles\Helper\IniWriter;
use Joomla\Plugin\Task\TranslationsLanguageFiles\Helper\LanguageFileTranslator;
use PHPUnit\Framework\TestCase;

/**
 * Which translations of the package's own strings are used, and how they are written down.
 *
 * A language string is put together at runtime and read by Joomla's own parser, so a
 * translation that loses a placeholder breaks the screen it is on, and a file that does not
 * parse loses every string in it. Both are checked before a file leaves the site.
 *
 * @since  1.4.9
 */
final class LanguageFilesTest extends TestCase
{
    /**
     * A source file with a header, a comment, a blank line and a quoted value.
     *
     * @var    string
     * @since  1.4.9
     */
    private const SOURCE = "; Joomla! Project\n; License GNU General Public License version 2 or later\n\n"
        . "PLG_EXAMPLE=\"Example\"\n; A comment\nPLG_EXAMPLE_QUOTED=\"Say \\\"hello\\\"\"\nPLG_EXAMPLE_LEFT=\"Left in English\"\n";

    /**
     * The translated file keeps the source's layout, and only the translated values change.
     *
     * @return  void
     *
     * @since   1.4.9
     */
    public function testTheTranslationKeepsTheLayoutOfItsSource(): void
    {
        $written = IniWriter::write(self::SOURCE, ['PLG_EXAMPLE' => 'Voorbeeld']);

        $this->assertSame(str_replace('"Example"', '"Voorbeeld"', self::SOURCE), $written);
    }

    /**
     * A quote in a translation is escaped the way Joomla reads it back.
     *
     * @return  void
     *
     * @since   1.4.9
     */
    public function testAQuoteIsEscaped(): void
    {
        $written = IniWriter::write(self::SOURCE, ['PLG_EXAMPLE_QUOTED' => 'Zeg "hallo"']);
        $strings = str_replace('\"', '"', parse_ini_string($written, false, INI_SCANNER_RAW));

        $this->assertSame('Zeg "hallo"', $strings['PLG_EXAMPLE_QUOTED']);
        $this->assertSame('Left in English', $strings['PLG_EXAMPLE_LEFT'], 'A string not translated stays in English');
    }

    /**
     * A line break or a trailing backslash cannot end up in a value, where it would end or escape it.
     *
     * @return  void
     *
     * @since   1.4.9
     */
    public function testAValueIsKeptOnOneLineAndClosed(): void
    {
        $this->assertSame('Twee regels', IniWriter::escape("Twee\n regels"));
        $this->assertSame('Pad', IniWriter::escape('Pad\\'));
    }

    /**
     * A translation passes when it keeps what the string is put together with.
     *
     * @param   string  $source       The English text.
     * @param   string  $translation  The translation.
     *
     * @return  void
     *
     * @dataProvider  usableTranslations
     *
     * @since   1.4.9
     */
    public function testAUsableTranslationPasses(string $source, string $translation): void
    {
        $this->assertNull(LanguageFileTranslator::fault($source, $translation));
    }

    /**
     * Translations that keep their placeholders and tags.
     *
     * @return  array<string, string[]>
     *
     * @since   1.4.9
     */
    public static function usableTranslations(): array
    {
        return [
            'plain text'               => ['Save', 'Opslaan'],
            'positional placeholders'  => ['%1$d of %2$s', '%2$s: %1$d'],
            'a tag around other words' => ['<strong>Careful</strong> now', 'Nu <strong>voorzichtig</strong>'],
            'a literal percent sign'   => ['100%% done', '100%% klaar'],
        ];
    }

    /**
     * A translation that would break its screen is refused.
     *
     * @param   string       $source       The English text.
     * @param   string|null  $translation  The translation.
     *
     * @return  void
     *
     * @dataProvider  unusableTranslations
     *
     * @since   1.4.9
     */
    public function testAnUnusableTranslationIsRefused(string $source, ?string $translation): void
    {
        $this->assertNotNull(LanguageFileTranslator::fault($source, $translation));
    }

    /**
     * Translations that lose what the string is put together with.
     *
     * @return  array<string, array{0: string, 1: string|null}>
     *
     * @since   1.4.9
     */
    public static function unusableTranslations(): array
    {
        return [
            'no answer'             => ['Save', null],
            'an empty answer'       => ['Save', '  '],
            'a lost placeholder'    => ['%d items', 'items'],
            'a changed placeholder' => ['%s items', '%d items'],
            'a lost tag'            => ['<em>Note</em>', 'Let op'],
        ];
    }
}
