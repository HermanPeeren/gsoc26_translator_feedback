<?php

/**
 * @package     Joomla.Tests
 * @subpackage  com_translations
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Joomla\Component\Translations\Tests\Unit;

use Joomla\Component\Translations\Administrator\Helper\LanguageDirection;
use PHPUnit\Framework\TestCase;

/**
 * The writing direction of a content language.
 *
 * The side-by-side view runs in the translator's language, so an original written the other way
 * round is only shown the right way when its own direction is known.
 *
 * @since  1.4.6
 */
final class LanguageDirectionTest extends TestCase
{
    /**
     * An installation root holding language packs, made per test.
     *
     * @var    string
     * @since  1.4.6
     */
    private string $root;

    /**
     * Make an empty installation root.
     *
     * @return  void
     *
     * @since   1.4.6
     */
    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/translations-direction-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/language', 0777, true);
    }

    /**
     * Remove the installation root.
     *
     * @return  void
     *
     * @since   1.4.6
     */
    protected function tearDown(): void
    {
        foreach (glob($this->root . '/language/*/*') ?: [] as $file) {
            unlink($file);
        }

        foreach (glob($this->root . '/language/*') ?: [] as $folder) {
            rmdir($folder);
        }

        rmdir($this->root . '/language');
        rmdir($this->root);
    }

    /**
     * A language pack's own word on its direction counts.
     *
     * @return  void
     *
     * @since   1.4.6
     */
    public function testTheLanguagePackDecides(): void
    {
        $this->addPack('en-GB', 'langmetadata.xml', '0');
        $this->addPack('ar-AA', 'langmetadata.xml', '1');

        $this->assertSame('ltr', LanguageDirection::of('en-GB', [$this->root]));
        $this->assertSame('rtl', LanguageDirection::of('ar-AA', [$this->root]));
    }

    /**
     * A pack overrules the tag, which is only a guess.
     *
     * @return  void
     *
     * @since   1.4.6
     */
    public function testThePackOverrulesTheTag(): void
    {
        $this->addPack('xx-XX', 'langmetadata.xml', '1');

        $this->assertSame('rtl', LanguageDirection::of('xx-XX', [$this->root]));
    }

    /**
     * A pack made for Joomla 3 names its metadata file after the tag.
     *
     * @return  void
     *
     * @since   1.4.6
     */
    public function testAJoomla3PackIsRead(): void
    {
        $this->addPack('fa-IR', 'fa-IR.xml', '1');

        $this->assertSame('rtl', LanguageDirection::of('fa-IR', [$this->root]));
    }

    /**
     * Without a pack, a right-to-left language is known by its tag.
     *
     * @return  void
     *
     * @since   1.4.6
     */
    public function testWithoutAPackTheTagDecides(): void
    {
        $this->assertSame('rtl', LanguageDirection::of('ar-AA', [$this->root]));
        $this->assertSame('rtl', LanguageDirection::of('he-IL'));
        $this->assertSame('rtl', LanguageDirection::of('fa-IR'));
        $this->assertSame('rtl', LanguageDirection::of('ur-PK'));
        $this->assertSame('ltr', LanguageDirection::of('en-GB'));
        $this->assertSame('ltr', LanguageDirection::of('nl-NL'));
        $this->assertSame('ltr', LanguageDirection::of('zh-CN'));
    }

    /**
     * A script subtag outranks the language, which may be written in more than one script.
     *
     * @return  void
     *
     * @since   1.4.6
     */
    public function testTheScriptOutranksTheLanguage(): void
    {
        $this->assertSame('rtl', LanguageDirection::of('ku-Arab-IQ'));
        $this->assertSame('ltr', LanguageDirection::of('ku-Latn-TR'));
        $this->assertSame('ltr', LanguageDirection::of('az-Latn-AZ'));
        $this->assertSame('rtl', LanguageDirection::of('pa-Arab-PK'));
    }

    /**
     * "All languages" and an empty tag name no language, so there is no direction to give.
     *
     * @return  void
     *
     * @since   1.4.6
     */
    public function testNoLanguageHasNoDirection(): void
    {
        $this->assertSame('', LanguageDirection::of('*'));
        $this->assertSame('', LanguageDirection::of(''));
    }

    /**
     * A tag is used as a folder name, so one that climbs out of the language folder is refused.
     *
     * @return  void
     *
     * @since   1.4.6
     */
    public function testATagThatIsAPathIsRefused(): void
    {
        $this->assertSame('', LanguageDirection::of('../../etc', [$this->root]));
    }

    /**
     * Put a language pack's metadata file under the installation root.
     *
     * @param   string  $tag   The language tag.
     * @param   string  $name  The metadata file's name.
     * @param   string  $rtl   The value of its rtl element.
     *
     * @return  void
     *
     * @since   1.4.6
     */
    private function addPack(string $tag, string $name, string $rtl): void
    {
        mkdir($this->root . '/language/' . $tag);
        file_put_contents(
            $this->root . '/language/' . $tag . '/' . $name,
            '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<metafile client="site"><metadata><name>' . $tag . '</name><tag>' . $tag . '</tag>'
            . '<rtl>' . $rtl . '</rtl></metadata></metafile>' . "\n"
        );
    }
}
