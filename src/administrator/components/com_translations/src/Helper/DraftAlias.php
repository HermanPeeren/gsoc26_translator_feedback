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
 * The alias a translated draft is saved under.
 *
 * Joomla keeps an alias unique among an item's neighbours - an article within its category, a
 * category or a tag within its parent - and the language plays no part in that. Two languages
 * whose titles lead to the same alias are therefore neighbours like any other: Spanish
 * "Referencia" and Portuguese "Referência" both become `referencia`, and the second of them is
 * refused. A draft takes the first alias its neighbours leave free: the one its own title gives
 * it, then that with its language appended, then that numbered.
 *
 * @since  1.4.2
 */
final class DraftAlias
{
    /**
     * The most characters an alias is built out of, before a language or a number is added.
     *
     * Joomla gives an alias 400 characters in the database and builds one out of the whole title
     * without looking at its length, so an item whose title holds more than a title - a body that
     * ended up in the wrong column, a question asked at length - produces an alias the database
     * refuses to store, and the translation is lost over it. A hundred is far inside that, leaves
     * room for everything added after it, and is about as much as a URL wants in one segment.
     *
     * @var    integer
     * @since  1.4.4
     */
    public const MAX_LENGTH = 100;

    /**
     * Pick the first alias the draft's neighbours leave free.
     *
     * @param   string    $base      The alias the draft's own title gives it.
     * @param   string    $language  The draft's language code, e.g. 'pt-BR'.
     * @param   string[]  $taken     The aliases the draft's neighbours hold.
     *
     * @return  string  An alias no neighbour holds.
     *
     * @since   1.4.2
     */
    public static function pick(string $base, string $language, array $taken): string
    {
        $base = self::shortened($base);
        $held = [];

        foreach ($taken as $alias) {
            $held[strtolower((string) $alias)] = true;
        }

        if (!isset($held[strtolower($base)])) {
            return $base;
        }

        // Every turn of the loop asks about an alias it has not asked about before, and there are
        // only so many held ones to run into, so a free alias is always reached.
        $suffixed  = $base . '-' . strtolower($language);
        $candidate = $suffixed;
        $number    = 1;

        while (isset($held[strtolower($candidate)])) {
            $number++;
            $candidate = $suffixed . '-' . $number;
        }

        return $candidate;
    }

    /**
     * The alias to fall back on when the managing component refuses the picked one anyway.
     *
     * Joomla decides what an alias clashes with, and it answers only by refusing a save. Where it
     * refuses one that the neighbours read beforehand left free, it is reading a neighbourhood the
     * component is not, and arguing with it costs a translation that has been paid for. This names
     * the source item the draft was made from, so two drafts can only reach the same alias if they
     * were translated from the same item into the same language, which is the one case the queue
     * does not produce twice.
     *
     * @param   string   $base          The alias the draft's own title gives it.
     * @param   string   $language      The draft's language code, e.g. 'pt-BR'.
     * @param   integer  $sourceItemId  The id of the item the draft was translated from.
     *
     * @return  string  An alias tied to the source item.
     *
     * @since   1.4.3
     */
    public static function stepAside(string $base, string $language, int $sourceItemId): string
    {
        return self::shortened($base) . '-' . strtolower($language) . '-' . $sourceItemId;
    }

    /**
     * Cut an alias back to a length the database will take, on a word.
     *
     * The cut lands on the last hyphen inside the limit, so the alias ends on a whole word rather
     * than halfway through one. A first word longer than the limit has no hyphen to land on and is
     * cut where the limit falls, which is still better than an alias that cannot be stored.
     *
     * @param   string  $base  The alias the draft's own title gives it.
     *
     * @return  string  The alias, at most MAX_LENGTH characters.
     *
     * @since   1.4.4
     */
    private static function shortened(string $base): string
    {
        if (mb_strlen($base) <= self::MAX_LENGTH) {
            return $base;
        }

        $cut = mb_substr($base, 0, self::MAX_LENGTH);

        // A hyphen is one byte and cannot sit inside a character, so the whole words are up to the
        // last one of them. Trailing hyphens go with it, as Joomla does not leave an alias on one.
        $lastWord = strrpos($cut, '-');

        return rtrim($lastWord > 0 ? substr($cut, 0, $lastWord) : $cut, '-');
    }
}
