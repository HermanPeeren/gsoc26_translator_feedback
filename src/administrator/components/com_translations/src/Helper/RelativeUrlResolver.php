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
 * Resolves the relative links and images in stored content against a base path.
 *
 * The editor stores links and images relative to the site root, and the SEF plugin resolves them
 * on the site only. A value that starts with a slash, a protocol, a hash or a quote is left alone,
 * as the SEF plugin does.
 *
 * @since  1.2.3
 */
final class RelativeUrlResolver
{
    /**
     * The attributes that hold a link or an image.
     *
     * @var    string[]
     * @since  1.2.3
     */
    private const URL_ATTRIBUTES = ['href=', 'src='];

    /**
     * Prefix every relative link and image in the content with the base path.
     *
     * @param   string  $html  The content.
     * @param   string  $base  The base path, ending in a slash.
     *
     * @return  string  The content with its relative links and images resolved.
     *
     * @since   1.2.3
     */
    public static function resolve(string $html, string $base): string
    {
        foreach (self::URL_ATTRIBUTES as $attribute) {
            if (str_contains($html, $attribute)) {
                $html = preg_replace(
                    '#\s' . $attribute . '"(?!/|[a-zA-Z0-9\-]+:|\#|\')([^"]*)"#m',
                    ' ' . $attribute . '"' . $base . '$1"',
                    $html
                ) ?? $html;
            }
        }

        return $html;
    }
}
