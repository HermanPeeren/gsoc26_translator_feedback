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

/**
 * The writing direction of a content language.
 *
 * A page takes its direction from the language it is shown in, and the side-by-side view is shown
 * in the language the translator works in: an Arabic translator gets a right-to-left page with
 * the English original in it. That original keeps its own direction only when its block says
 * which one, so its language is asked here rather than assumed from the page.
 *
 * A language says so itself in its metadata (`<rtl>1</rtl>`), but only where its language pack
 * is installed. A content language can exist without one, so a language without metadata is
 * judged by its tag: by its script where the tag names one, otherwise by its language.
 *
 * @since  1.4.6
 */
final class LanguageDirection
{
    /**
     * Languages written right to left, by their primary subtag.
     *
     * @var    string[]
     * @since  1.4.6
     */
    private const RTL_LANGUAGES = [
        'ar', 'arc', 'ckb', 'dv', 'fa', 'he', 'iw', 'ks', 'ps', 'sd', 'syr', 'ug', 'ur', 'yi',
    ];

    /**
     * Scripts written right to left, by their ISO 15924 subtag.
     *
     * @var    string[]
     * @since  1.4.6
     */
    private const RTL_SCRIPTS = ['arab', 'hebr', 'syrc', 'thaa', 'nkoo', 'adlm', 'rohg'];

    /**
     * The direction a language is written in.
     *
     * @param   string    $tag    The language tag, e.g. 'en-GB'.
     * @param   string[]  $roots  The installation roots whose language packs may describe it, asked
     *                            in order (Joomla keeps a site and an administrator set).
     *
     * @return  string  'rtl' or 'ltr', or '' for a tag that names no language ('' or '*').
     *
     * @since   1.4.6
     */
    public static function of(string $tag, array $roots = []): string
    {
        // A tag is also a folder name below; anything but letters, digits and dashes is not a tag.
        if (!preg_match('/^[A-Za-z]{2,3}(-[A-Za-z0-9]{1,8})*$/', $tag)) {
            return '';
        }

        foreach ($roots as $root) {
            $rtl = self::fromMetadata((string) $root, $tag);

            if ($rtl !== null) {
                return $rtl ? 'rtl' : 'ltr';
            }
        }

        return self::fromTag($tag) ? 'rtl' : 'ltr';
    }

    /**
     * What an installed language pack says about the direction.
     *
     * @param   string  $root  An installation root holding a language folder.
     * @param   string  $tag   The language tag.
     *
     * @return  boolean|null  Whether the pack says right to left, or null without a pack that says.
     *
     * @since   1.4.6
     */
    private static function fromMetadata(string $root, string $tag): ?bool
    {
        // Joomla 4+ names the file langmetadata.xml; packs made for Joomla 3 name it after the tag.
        foreach (['langmetadata.xml', $tag . '.xml'] as $name) {
            $file = $root . '/language/' . $tag . '/' . $name;

            if (!is_file($file)) {
                continue;
            }

            $xml = simplexml_load_file($file);

            if ($xml !== false && isset($xml->metadata->rtl)) {
                return trim((string) $xml->metadata->rtl) === '1';
            }
        }

        return null;
    }

    /**
     * Whether a tag points at a right-to-left language without a pack to ask.
     *
     * @param   string  $tag  The language tag.
     *
     * @return  boolean
     *
     * @since   1.4.6
     */
    private static function fromTag(string $tag): bool
    {
        $subtags = explode('-', strtolower($tag));

        // A script subtag outranks the language: Kurdish and Punjabi are written in both directions.
        foreach (\array_slice($subtags, 1) as $subtag) {
            if (\strlen($subtag) === 4 && ctype_alpha($subtag)) {
                return \in_array($subtag, self::RTL_SCRIPTS, true);
            }
        }

        return \in_array($subtags[0], self::RTL_LANGUAGES, true);
    }
}
