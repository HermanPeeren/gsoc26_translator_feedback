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
        return $base . '-' . strtolower($language) . '-' . $sourceItemId;
    }
}
