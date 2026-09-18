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

use Joomla\Component\Translations\Administrator\Helper\ContentTypesHelper;
use Joomla\Component\Translations\Administrator\Helper\TranslatableValuesHelper;
use Joomla\Component\Translations\Administrator\Model\TranslatorfeedbackModel;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Reading an item's translatable values, and writing a translation back over them.
 *
 * These are two halves of one thing: the producer reads the values it sends for
 * translation, and the editor writes the corrected values back. They key a field the same
 * way or the feedback pairs line up wrongly, and a value the writer forgets is a
 * translation the translator loses. A round trip says both at once.
 *
 * @since  1.0.1
 */
final class TranslatableValuesHelperTest extends TestCase
{
    /**
     * An article row, as the pipeline reads it from #__content.
     *
     * @var    array<string, string>
     * @since  1.0.1
     */
    private const ARTICLE_ROW = [
        'id'        => '42',
        'title'     => 'The module position',
        'introtext' => '<p>An introduction</p>',
        'fulltext'  => '',
        'metadesc'  => 'About modules',
        'metakey'   => 'module, position',
        'note'      => '',
        'images'    => '{"image_intro":"images/intro.jpg","image_intro_alt":"A module",'
            . '"image_intro_caption":"","float_intro":"right","image_fulltext":"",'
            . '"image_fulltext_alt":"","image_fulltext_caption":""}',
    ];

    /**
     * Both plain columns and the sub-fields inside a JSON column are read as fields.
     *
     * @return  void
     *
     * @since   1.0.1
     */
    public function testJsonSubFieldsAreReadAsFieldsOfTheirOwn(): void
    {
        $values = TranslatableValuesHelper::flattenFields(self::ARTICLE_ROW, self::articleFields());

        $this->assertSame('The module position', $values['title']);
        $this->assertSame('A module', $values['image_intro_alt']);
        $this->assertArrayNotHasKey('images', $values, 'The JSON column itself is not a translatable value');
        $this->assertArrayNotHasKey(
            'image_intro',
            $values,
            'A sub-field the map does not list is not offered for translation'
        );
    }

    /**
     * A field the item has not filled in is still a field, and an absent column reads as empty.
     *
     * The editor renders what it is given, so a field left out here is a field a translator
     * cannot fill in at all.
     *
     * @return  void
     *
     * @since   1.0.1
     */
    public function testEmptyAndMissingValuesAreStillFields(): void
    {
        $row = self::ARTICLE_ROW;
        unset($row['metakey'], $row['images']);

        $values = TranslatableValuesHelper::flattenFields($row, self::articleFields());

        $this->assertSame('', $values['fulltext'], 'An empty column is kept');
        $this->assertSame('', $values['metakey'], 'A column the row does not carry reads as empty');
        $this->assertSame('', $values['image_intro_alt'], 'A missing JSON column leaves its sub-fields empty');
    }

    /**
     * A field list entry that is neither a column nor a JSON column is passed over.
     *
     * The list comes from a data file, so it can hold anything; the alternative to ignoring
     * a malformed entry is a fatal while reading an article.
     *
     * @return  void
     *
     * @since   1.0.1
     */
    public function testAMalformedFieldEntryIsIgnored(): void
    {
        $values = TranslatableValuesHelper::flattenFields(self::ARTICLE_ROW, ['title', 42, null]);

        $this->assertSame(['title' => 'The module position'], $values);
    }

    /**
     * A translation written back reads out as the translation, field for field.
     *
     * @return  void
     *
     * @since   1.0.1
     */
    public function testATranslationWrittenBackReadsOutAsItself(): void
    {
        $fields   = self::articleFields();
        $original = TranslatableValuesHelper::flattenFields(self::ARTICLE_ROW, $fields);

        // What the editor posts: every value translated, under the key the form gives it.
        $submitted = [];

        foreach ($original as $field => $value) {
            $submitted['translation_' . $field] = $value === '' ? '' : 'NL: ' . $value;
        }

        $translatedRow = self::applyTranslation(self::ARTICLE_ROW, $fields, $submitted);

        $this->assertSame(
            array_map(static fn(string $value): string => $value === '' ? '' : 'NL: ' . $value, $original),
            TranslatableValuesHelper::flattenFields($translatedRow, $fields)
        );
    }

    /**
     * Writing a JSON column's translated sub-fields leaves the rest of that column alone.
     *
     * The images column holds the image paths next to their alt texts. Rewriting the column
     * from the translatable keys alone would drop the pictures off the translated article.
     *
     * @return  void
     *
     * @since   1.0.1
     */
    public function testTheUntranslatedKeysOfAJsonColumnSurvive(): void
    {
        $row = self::applyTranslation(
            self::ARTICLE_ROW,
            self::articleFields(),
            ['translation_image_intro_alt' => 'Een module']
        );

        $images = json_decode($row['images'], true);

        $this->assertSame('Een module', $images['image_intro_alt']);
        $this->assertSame('images/intro.jpg', $images['image_intro'], 'The picture itself is not a translatable value');
        $this->assertSame('right', $images['float_intro']);
    }

    /**
     * A field the editor did not post keeps the value the draft already had.
     *
     * A form that renders part of an item - a custom field tab that failed to load, say -
     * must not empty the fields it did not show.
     *
     * @return  void
     *
     * @since   1.0.1
     */
    public function testAFieldThatWasNotPostedKeepsItsValue(): void
    {
        $row = self::applyTranslation(self::ARTICLE_ROW, self::articleFields(), ['translation_title' => 'De module']);

        $this->assertSame('De module', $row['title']);
        $this->assertSame('<p>An introduction</p>', $row['introtext']);
        $this->assertSame('A module', json_decode($row['images'], true)['image_intro_alt']);
    }

    /**
     * The field list of an article, read from the map that ships.
     *
     * @return  array  The content type's translatable field list.
     *
     * @since   1.0.1
     */
    private static function articleFields(): array
    {
        return (array) ContentTypesHelper::getProperties('com_content.article')['translatableFields'];
    }

    /**
     * Write submitted values over an item's row, the way saving a correction does.
     *
     * The model needs an application, a database and a form to do anything else, so the one
     * method under test is reached on an instance that was never constructed. It reads only
     * its arguments, which is what makes that safe - and what makes it worth testing here
     * rather than through the editor.
     *
     * @param   array  $row     The item's column values.
     * @param   array  $fields  The content type's translatable field list.
     * @param   array  $data    The submitted values, keyed translation_<field>.
     *
     * @return  array  The row with the translation applied.
     *
     * @since   1.0.1
     */
    private static function applyTranslation(array $row, array $fields, array $data): array
    {
        $model  = (new ReflectionClass(TranslatorfeedbackModel::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(TranslatorfeedbackModel::class, 'applyTranslation');
        $method->setAccessible(true);

        return $method->invoke($model, $row, $fields, $data);
    }
}
