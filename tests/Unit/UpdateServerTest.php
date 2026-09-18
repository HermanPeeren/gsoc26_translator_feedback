<?php

/**
 * @package     Joomla.Tests
 * @subpackage  pkg_translations
 *
 * @copyright   (C) 2026 Open Source Matters, Inc. <https://www.joomla.org>
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

declare(strict_types=1);

namespace Joomla\Component\Translations\Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once \dirname(__DIR__, 2) . '/build/archive.php';

/**
 * What an installed site is told when it asks whether there is a newer version.
 *
 * A manifest names an update server; that URL serves a file in updates/; that file names a
 * version and a download. Nothing joins those up at release time, so they go stale one at a
 * time: a version bumped without regenerating the update file hides the release, and a
 * download URL that no longer matches what the build produces offers an update that 404s.
 * Neither is visible from the releasing side - only from a site that is quietly never
 * updated - so it is checked here, where the release already runs the suite.
 *
 * @since  1.0.1
 */
final class UpdateServerTest extends TestCase
{
    /**
     * The releases the download URLs have to point into.
     *
     * @var    string
     * @since  1.0.1
     */
    private const REPOSITORY = 'https://github.com/joomla-projects/gsoc26_translator_feedback';

    /**
     * Each released extension: its manifest, and the update file that describes it.
     *
     * @return  array<string, array{0: string, 1: string}>
     *
     * @since   1.0.1
     */
    public static function releasedExtensions(): array
    {
        return [
            'the package'     => [
                'src/administrator/manifests/packages/pkg_translations.xml',
                'updates/pkg_translations.xml',
            ],
            'the seed plugin' => [
                'src/plugins/task/translationsseed/translationsseed.xml',
                'updates/plg_task_translationsseed.xml',
            ],
        ];
    }

    /**
     * A manifest's update server points at the file that describes that extension.
     *
     * @param   string  $manifest    The extension's manifest.
     * @param   string  $updateFile  The update file that describes it.
     *
     * @return  void
     *
     * @dataProvider  releasedExtensions
     *
     * @since   1.0.1
     */
    public function testTheManifestPointsAtItsOwnUpdateFile(string $manifest, string $updateFile): void
    {
        $servers = simplexml_load_file(self::path($manifest))->updateservers;

        $this->assertNotEmpty($servers, $manifest . ' names an update server');
        $this->assertCount(1, $servers->server, 'One server, so there is one answer to which version is newest');

        $url = trim((string) $servers->server[0]);

        $this->assertStringEndsWith(
            '/' . $updateFile,
            $url,
            'The update server serves the file in this repository that describes this extension'
        );
        $this->assertStringStartsWith('https://', $url, 'A site fetches this over the open internet');
        $this->assertFileExists(self::path($updateFile), 'The file the manifest points at is committed');
    }

    /**
     * The update file offers the version the manifest carries.
     *
     * A bumped manifest with a stale update file releases a version no site is ever told
     * about.
     *
     * @param   string  $manifest    The extension's manifest.
     * @param   string  $updateFile  The update file that describes it.
     *
     * @return  void
     *
     * @dataProvider  releasedExtensions
     *
     * @since   1.0.1
     */
    public function testTheUpdateFileOffersTheVersionTheManifestCarries(string $manifest, string $updateFile): void
    {
        $this->assertSame(
            self::version($manifest),
            trim((string) simplexml_load_file(self::path($updateFile))->update->version),
            'Run php build/update-xml.php and commit the result'
        );
    }

    /**
     * The download URL points at the archive the build will actually produce, on this tag.
     *
     * Both downloads are published on one release, tagged after the package version, so the
     * seed plugin's URL carries the package's version in its path and its own in its name.
     *
     * @param   string  $manifest    The extension's manifest.
     * @param   string  $updateFile  The update file that describes it.
     *
     * @return  void
     *
     * @dataProvider  releasedExtensions
     *
     * @since   1.0.1
     */
    public function testTheDownloadPointsAtTheArchiveTheBuildProduces(string $manifest, string $updateFile): void
    {
        $update   = simplexml_load_file(self::path($updateFile))->update;
        $download = trim((string) $update->downloads->downloadurl);
        $tag      = 'v' . self::version('src/administrator/manifests/packages/pkg_translations.xml');

        $expected = \sprintf(
            '%s/releases/download/%s/%s',
            self::REPOSITORY,
            $tag,
            archiveName(basename($updateFile, '.xml'), self::version($manifest))
        );

        $this->assertSame($expected, $download, 'Run php build/update-xml.php and commit the result');
    }

    /**
     * The update file names the extension the way Joomla stores it.
     *
     * Joomla matches an update against an installed extension on the element, the type and,
     * for a plugin, its group. Get one wrong and the update is simply never offered.
     *
     * @return  void
     *
     * @since   1.0.1
     */
    public function testTheUpdateFileNamesTheExtensionTheWayJoomlaStoresIt(): void
    {
        $package = simplexml_load_file(self::path('updates/pkg_translations.xml'))->update;

        $this->assertSame('pkg_translations', trim((string) $package->element));
        $this->assertSame('package', trim((string) $package->type));

        $seed = simplexml_load_file(self::path('updates/plg_task_translationsseed.xml'))->update;

        $this->assertSame('translationsseed', trim((string) $seed->element), 'A plugin is stored under its element');
        $this->assertSame('plugin', trim((string) $seed->type));
        $this->assertSame('task', trim((string) $seed->folder), 'and its group');
    }

    /**
     * A manifest's version.
     *
     * @param   string  $manifest  The manifest, relative to the repository root.
     *
     * @return  string
     *
     * @since   1.0.1
     */
    private static function version(string $manifest): string
    {
        return trim((string) simplexml_load_file(self::path($manifest))->version);
    }

    /**
     * A path in the repository.
     *
     * @param   string  $relative  The path, relative to the repository root.
     *
     * @return  string
     *
     * @since   1.0.1
     */
    private static function path(string $relative): string
    {
        return \dirname(__DIR__, 2) . '/' . $relative;
    }
}
