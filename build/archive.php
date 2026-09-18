<?php

/**
 * What a release archive is called.
 *
 * Three things have to agree on this: the build that writes the archive, the update file
 * that points a site at it, and the release it is uploaded to. When they disagree the
 * result is an update offer that downloads a 404, which nothing on the releasing side
 * notices. So the rule is written once, here, and the other two ask for it.
 *
 * @package     Joomla.Build
 * @subpackage  com_translations
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

/**
 * The file name a release archive is published under.
 *
 * @param   string  $extension  The extension name, as Joomla stores it (pkg_translations).
 * @param   string  $version    The version from that extension's manifest.
 *
 * @return  string  The archive file name.
 */
function archiveName(string $extension, string $version): string
{
    return $extension . '-' . $version . '.zip';
}
