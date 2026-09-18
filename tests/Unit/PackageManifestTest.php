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
use SimpleXMLElement;

/**
 * What the release is made of, read from the manifests the build reads.
 *
 * build/build.php takes the package manifest as its instructions: every extension listed
 * there is built from the folder its type, group and id point at. A manifest that names a
 * folder which is not there stops the build, which is the good case. The bad case is the
 * other way round - an extension in the tree that no manifest lists is simply not
 * released, and nothing anywhere says so.
 *
 * @since  1.0.1
 */
final class PackageManifestTest extends TestCase
{
    /**
     * The seed plugin, which is released on its own rather than inside the package.
     *
     * @var    string
     * @since  1.0.1
     */
    private const SEED_PLUGIN = 'task/translationsseed';

    /**
     * Every extension the package lists is there to be built, under the name Joomla stores it.
     *
     * @return  void
     *
     * @since   1.0.1
     */
    public function testEveryListedExtensionExists(): void
    {
        foreach (self::packagedExtensions() as $name => $path) {
            $this->assertDirectoryExists(self::src() . '/' . $path, $name . ' is listed in the package manifest');
            $this->assertCount(
                1,
                self::manifestsIn($path),
                $name . ' has exactly one manifest, which is the one Joomla will install it by'
            );
        }
    }

    /**
     * Every extension in the tree is released, either in the package or on its own.
     *
     * A plugin that is written, committed and then left out of the manifest installs
     * nowhere. The pipeline goes on working without it, quietly missing whatever it did.
     *
     * @return  void
     *
     * @since   1.0.1
     */
    public function testEveryExtensionInTheTreeIsReleased(): void
    {
        $packaged = array_values(self::packagedExtensions());

        foreach (self::pluginsInTheTree() as $plugin) {
            if ($plugin === self::SEED_PLUGIN) {
                continue;
            }

            $this->assertContains(
                'plugins/' . $plugin,
                $packaged,
                \sprintf('The %s plugin is in the tree, so it belongs in the package manifest', $plugin)
            );
        }

        $this->assertDirectoryExists(
            self::src() . '/plugins/' . self::SEED_PLUGIN,
            'The seed plugin is released on its own, and build/build.php seed builds it from here'
        );
    }

    /**
     * Each manifest carries a version a release can be tagged with.
     *
     * The build names an archive after the version and the release workflow compares the tag
     * against it, so anything the version pattern does not allow stops a release rather than
     * producing one nobody can identify.
     *
     * @return  void
     *
     * @since   1.0.1
     */
    public function testEveryManifestCarriesATaggableVersion(): void
    {
        $manifests = [self::packageManifest()];

        foreach (array_merge(array_values(self::packagedExtensions()), ['plugins/' . self::SEED_PLUGIN]) as $path) {
            $manifests = array_merge($manifests, self::manifestsIn($path));
        }

        foreach ($manifests as $manifest) {
            $this->assertMatchesRegularExpression(
                '/^\d+\.\d+\.\d+(-[A-Za-z0-9.]+)?$/',
                trim((string) simplexml_load_file($manifest)->version),
                basename($manifest) . ' carries a version of the form 1.2.3'
            );
        }
    }

    /**
     * The files the package carries alongside its extensions are there.
     *
     * The package manifest's own language file is the one thing in the package archive that
     * is not an extension, and a missing one leaves the package unnamed in the installer.
     *
     * @return  void
     *
     * @since   1.0.1
     */
    public function testThePackageCarriesItsOwnLanguageFile(): void
    {
        $manifest = simplexml_load_file(self::packageManifest());
        $folder   = (string) $manifest->languages['folder'];

        foreach ($manifest->languages->language as $language) {
            $this->assertFileExists(self::src() . '/' . $folder . '/' . (string) $language);
        }
    }

    /**
     * Where each extension of the package is built from, keyed by its archive name.
     *
     * @return  array<string, string>  The path under src/, keyed by archive name.
     *
     * @since   1.0.1
     */
    private static function packagedExtensions(): array
    {
        $extensions = [];

        foreach (simplexml_load_file(self::packageManifest())->files->file as $file) {
            $extensions[(string) $file] = self::pathOf($file);
        }

        return $extensions;
    }

    /**
     * Where one listed extension is built from, the way build/build.php works it out.
     *
     * @param   SimpleXMLElement  $file  One <file> entry of the package manifest.
     *
     * @return  string  The path under src/.
     *
     * @since   1.0.1
     */
    private static function pathOf(SimpleXMLElement $file): string
    {
        if ((string) $file['type'] === 'component') {
            return 'administrator/components/' . (string) $file['id'];
        }

        return 'plugins/' . (string) $file['group'] . '/' . (string) $file['id'];
    }

    /**
     * The manifests in an extension's folder.
     *
     * An extension folder holds more XML than its manifest - a component keeps its access
     * rules and its options there too - and what tells them apart is the root element the
     * installer reads.
     *
     * @param   string  $path  The extension's path under src/.
     *
     * @return  string[]  The manifest files found.
     *
     * @since   1.0.1
     */
    private static function manifestsIn(string $path): array
    {
        $manifests = [];

        foreach (glob(self::src() . '/' . $path . '/*.xml') ?: [] as $file) {
            if (simplexml_load_file($file)->getName() === 'extension') {
                $manifests[] = $file;
            }
        }

        return $manifests;
    }

    /**
     * Every plugin in the source tree, as group/element.
     *
     * @return  string[]  The plugins.
     *
     * @since   1.0.1
     */
    private static function pluginsInTheTree(): array
    {
        $plugins = [];

        foreach (glob(self::src() . '/plugins/*/*', GLOB_ONLYDIR) ?: [] as $path) {
            $plugins[] = basename(\dirname($path)) . '/' . basename($path);
        }

        return $plugins;
    }

    /**
     * The source tree the build reads.
     *
     * @return  string
     *
     * @since   1.0.1
     */
    private static function src(): string
    {
        return TRANSLATIONS_TEST_SRC;
    }

    /**
     * The package manifest.
     *
     * @return  string
     *
     * @since   1.0.1
     */
    private static function packageManifest(): string
    {
        return self::src() . '/administrator/manifests/packages/pkg_translations.xml';
    }
}
