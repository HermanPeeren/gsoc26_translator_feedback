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

use Joomla\Component\Translations\Administrator\Helper\RelativeUrlResolver;
use PHPUnit\Framework\TestCase;

/**
 * Relative links and images in the original shown beside a translation are resolved against the
 * site root, so they load on an administrator page the way they do on the site.
 *
 * @since  1.2.3
 */
final class RelativeUrlResolverTest extends TestCase
{
    /**
     * A relative image, as the editor stores it, is resolved against the base.
     *
     * @return  void
     *
     * @since   1.2.3
     */
    public function testRelativeImageIsResolved(): void
    {
        $html = '<p><img src="images/general/TutorialKing.png" alt="A diagram" data-path="local-images:/general/TutorialKing.png"></p>';

        $this->assertSame(
            '<p><img src="/images/general/TutorialKing.png" alt="A diagram" data-path="local-images:/general/TutorialKing.png"></p>',
            RelativeUrlResolver::resolve($html, '/')
        );
    }

    /**
     * A relative link is resolved against the base.
     *
     * @return  void
     *
     * @since   1.2.3
     */
    public function testRelativeLinkIsResolved(): void
    {
        $html = '<a href="index.php?option=com_content&amp;view=article&amp;id=1">Article</a>';

        $this->assertSame(
            '<a href="/index.php?option=com_content&amp;view=article&amp;id=1">Article</a>',
            RelativeUrlResolver::resolve($html, '/')
        );
    }

    /**
     * A URL that starts with a slash, a protocol or a hash is left alone.
     *
     * @return  void
     *
     * @since   1.2.3
     */
    public function testAbsoluteUrlsAreUnchanged(): void
    {
        $html = '<a href="https://magazine.joomla.org/">Magazine</a>'
            . '<a href="mailto:docs@example.org">Mail</a>'
            . '<a href="#steps">Steps</a>'
            . '<img src="/images/logo.png" alt="">'
            . '<img src="//cdn.example.org/logo.png" alt="">';

        $this->assertSame($html, RelativeUrlResolver::resolve($html, '/'));
    }

    /**
     * An attribute that only ends in a URL attribute's name, such as data-src, is left alone.
     *
     * @return  void
     *
     * @since   1.2.3
     */
    public function testDataAttributesAreUnchanged(): void
    {
        $html = '<img src="images/a.png" data-src="images/a-large.png" alt="">';

        $this->assertSame(
            '<img src="/images/a.png" data-src="images/a-large.png" alt="">',
            RelativeUrlResolver::resolve($html, '/')
        );
    }

    /**
     * A site installed in a subfolder resolves against that folder.
     *
     * @return  void
     *
     * @since   1.2.3
     */
    public function testSubfolderBase(): void
    {
        $this->assertSame(
            '<img src="/joomla/images/a.png" alt="">',
            RelativeUrlResolver::resolve('<img src="images/a.png" alt="">', '/joomla/')
        );
    }

    /**
     * Content without links or images comes back as it was.
     *
     * @return  void
     *
     * @since   1.2.3
     */
    public function testContentWithoutUrlsIsUnchanged(): void
    {
        $html = '<p>Before you publish an article, read it through once more.</p>';

        $this->assertSame($html, RelativeUrlResolver::resolve($html, '/'));
    }
}
