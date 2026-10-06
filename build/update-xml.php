<?php

/**
 * Writes the update files an installed site reads to learn that a new version exists.
 *
 *   php build/update-xml.php
 *   php build/update-xml.php --checksum   also hash the archives in build/ (release workflow)
 *
 * Joomla asks the URL in each manifest's <updateservers> what the newest version is, and
 * downloads whatever the answer points at. That makes these two files part of the release
 * rather than documentation of it: a stale one either hides a release or offers a download
 * that 404s, and neither shows up on the site that is missing the update.
 *
 * So they are generated from the manifests rather than edited, and tests/Unit/UpdateServerTest
 * fails when what is committed no longer matches. Bumping a version stays one line in one
 * manifest, plus running this.
 *
 * @package     Joomla.Build
 * @subpackage  com_translations
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

require_once __DIR__ . '/archive.php';

const ROOT = __DIR__ . '/..';

// Where the releases live. Both downloads are published on one release, tagged after the
// package version, which is why the seed plugin's download URL below carries the package's
// version in its path and its own in its file name.
const REPOSITORY = 'https://github.com/joomla-projects/gsoc26_translator_feedback';

// Where an installed site reads the files this writes. The manifests name these URLs, and a
// site remembers the one it was installed with, so moving a file means the sites that
// already have it stop being offered updates. They are meant to stay put.
const UPDATE_BASE = 'https://raw.githubusercontent.com/joomla-projects/gsoc26_translator_feedback/main/updates';

/**
 * Read a manifest's version.
 *
 * @param   string  $manifest  Path to the manifest, relative to the repository root.
 *
 * @return  string  The version.
 */
function manifestVersion(string $manifest): string
{
    $path = ROOT . '/' . $manifest;

    if (!is_file($path)) {
        fwrite(STDERR, 'No manifest at ' . $manifest . "\n");
        exit(1);
    }

    return trim((string) simplexml_load_file($path)->version);
}

$packageVersion = manifestVersion('src/administrator/manifests/packages/pkg_translations.xml');
$seedVersion    = manifestVersion('src/plugins/task/translationsseed/translationsseed.xml');
$tag            = 'v' . $packageVersion;

/**
 * What each update file says. An update file describes one extension: the name Joomla stores
 * it under, the newest version, and where to get it.
 */
$updates = [
    'pkg_translations' => [
        'name'        => 'pkg_translations',
        'description' => 'Translator feedback for automatic translation',
        'element'     => 'pkg_translations',
        'type'        => 'package',
        'folder'      => null,
        'version'     => $packageVersion,
        'archive'     => archiveName('pkg_translations', $packageVersion),
    ],
    'plg_task_translationsseed' => [
        'name'        => 'plg_task_translationsseed',
        'description' => 'Seeds translator feedback from an installed language pack',
        'element'     => 'translationsseed',
        'type'        => 'plugin',
        'folder'      => 'task',
        'version'     => $seedVersion,
        'archive'     => archiveName('plg_task_translationsseed', $seedVersion),
    ],
];

$directory = ROOT . '/updates';

if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
    fwrite(STDERR, 'Cannot create ' . $directory . "\n");
    exit(1);
}

// The release workflow passes --checksum once it has built the archives. See checksum().
$hashArchives = \in_array('--checksum', $argv, true);

/**
 * The SHA-512 of a download, which Joomla checks the downloaded file against.
 *
 * Without one, Joomla warns after an update that the integrity of the file could not be
 * validated. Only the release workflow can know it: the archive is built there, and one built
 * on another machine differs in timestamps and line endings even when its contents do not. So
 * the workflow runs this script with --checksum after the build, which hashes the archive it
 * is about to publish, and commits the result to main, where the update files are served from.
 *
 * Run without --checksum, the checksum already committed is kept for as long as it describes
 * the same download, so regenerating changes nothing between releases. When the version moves
 * on the download URL changes with it, and the checksum is dropped: it belongs to the previous
 * archive, and a wrong checksum stops the update where a missing one only warns.
 *
 * @param   string   $target    The update file.
 * @param   string   $download  The download URL it is about to name.
 * @param   string   $archive   The archive's file name in build/.
 * @param   boolean  $hash      Whether to hash the built archive.
 *
 * @return  ?string  The checksum, or null when there is none to give.
 */
function checksum(string $target, string $download, string $archive, bool $hash): ?string
{
    if ($hash) {
        $path = ROOT . '/build/' . $archive;

        if (!is_file($path)) {
            fwrite(STDERR, '--checksum hashes the built archive, and there is none at build/' . $archive . "\n");
            exit(1);
        }

        return hash_file('sha512', $path);
    }

    $committed = is_file($target) ? simplexml_load_file($target) : false;

    if ($committed === false || trim((string) $committed->update->downloads->downloadurl) !== $download) {
        return null;
    }

    return trim((string) $committed->update->sha512) ?: null;
}

// A package and a plugin install as site extensions, and an update that does not say so is taken
// for administrator and matches nothing.
foreach ($updates as $file => $update) {
    $folder = $update['folder'] === null
        ? ''
        : \sprintf("        <folder>%s</folder>\n", $update['folder']);

    $target   = $directory . '/' . $file . '.xml';
    $download = REPOSITORY . '/releases/download/' . $tag . '/' . $update['archive'];
    $sha512   = checksum($target, $download, $update['archive'], $hashArchives);
    $checksum = $sha512 === null
        ? ''
        : \sprintf("        <sha512>%s</sha512>\n", $sha512);

    $xml = \sprintf(
        '<?xml version="1.0" encoding="utf-8"?>
<!-- Generated by build/update-xml.php. Edit a manifest and run that instead. -->
<updates>
    <update>
        <name>%1$s</name>
        <description>%2$s</description>
        <element>%3$s</element>
        <type>%4$s</type>
%5$s        <client>site</client>
        <version>%6$s</version>
        <infourl title="Translator Feedback">%7$s</infourl>
        <downloads>
            <downloadurl type="full" format="zip">%8$s</downloadurl>
        </downloads>
%9$s        <tags>
            <tag>stable</tag>
        </tags>
        <targetplatform name="joomla" version="6\.[0-9]+"/>
        <php_minimum>8.3</php_minimum>
        <maintainer>Joomla! Project</maintainer>
        <maintainerurl>%7$s</maintainerurl>
    </update>
</updates>
',
        $update['name'],
        $update['description'],
        $update['element'],
        $update['type'],
        $folder,
        $update['version'],
        REPOSITORY,
        $download,
        $checksum
    );

    file_put_contents($target, $xml);

    printf(
        "%-30s %s -> %s, %s\n",
        $file,
        $update['version'],
        $update['archive'],
        $sha512 === null ? 'without a checksum' : 'with its checksum'
    );
}

printf("\nRelease tag the downloads are expected on: %s\n", $tag);
printf("Update servers to name in the manifests:   %s/<file>.xml\n", UPDATE_BASE);
