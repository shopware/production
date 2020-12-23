<?php declare(strict_types=1);

namespace Shopware\CI\Test\Service\Xml;

use PHPUnit\Framework\TestCase;
use Shopware\CI\Service\Xml\Release;

class ReleaseTest extends TestCase
{
    private const TEST_XML = __DIR__ . '/../fixtures/shopware6.xml';

    public function validVersionProvider(): array
    {
        return [
            ['v6.1.3', '6.1.3', 0, ''],
            ['6.1.3', '6.1.3', 0, ''],
            ['  6.1.3 ', '6.1.3', 0, ''],
            ['v6.2.0-RC1 ', '6.2.0', 1, 'RC1'],
            ['v6.2.0-RC5 ', '6.2.0', 5, 'RC5'],
            ['6.2.0-rc1 ', '6.2.0', 1, 'rc1'],
            ['6.2.0 rc1 ', '6.2.0', 1, 'rc1'],
            ['6.2.0 rc3 ', '6.2.0', 3, 'rc3'],
            ['v6.2.1 ', '6.2.1', 0, ''],
            ['v6.3.0.1-RC3 ', '6.3.0.1', 3, 'RC3'],
            ['v7.3.0.1 ', '7.3.0.1', 0, ''],
        ];
    }

    /**
     * @dataProvider validVersionProvider
     */
    public function testParseVersion(string $tag, string $version, int $rc, string $versionText): void
    {
        $parsed = Release::parseVersion($tag);

        static::assertSame($version, $parsed['version']);
        static::assertSame($rc, $parsed['rc']);
        static::assertSame($versionText, $parsed['version_text']);
    }

    public function invalidVersionsProvider(): array
    {
        return [
            [''],
            ['   '],
            ['foobar'],
            ['6.1'],
            ['6.1-RC1'],
            ['6.1.3_RC1'],
        ];
    }

    /**
     * @dataProvider invalidVersionsProvider
     */
    public function testParseInvalidVersionThrowsException(string $version): void
    {
        $this->expectException(\RuntimeException::class);
        Release::parseVersion($version);
    }

    public function getReleaseDataProvider(): array
    {
        return [
            ['v6.1.3', '6.1.3', 0, true],
            ['6.1.3', '6.1.3', 0, true],
            ['v6.2.0-RC1 ', '6.2.0', 1, true],
            ['v6.2.1 ', '6.2.1', 0, false],
        ];
    }

    /**
     * @dataProvider getReleaseDataProvider
     */
    public function testGetRelease(string $tag, string $version, int $rc, bool $public): void
    {
        /** @var Release $releaseRoot */
        $releaseRoot = simplexml_load_string(file_get_contents(self::TEST_XML), Release::class);

        $release = $releaseRoot->getRelease($tag);
        static::assertInstanceOf(Release::class, $release);
        static::assertSame($version, $release->getVersion());
        static::assertSame($rc, $release->getRc());
        static::assertSame($public, $release->isPublic());
    }

    public function testChangesTakeEffectInRootDoc(): void
    {
        /** @var Release $releaseRoot */
        $releaseRoot = simplexml_load_string(file_get_contents(self::TEST_XML), Release::class);

        $first = $releaseRoot->getRelease('v6.2.1');
        static::assertInstanceOf(Release::class, $first);

        static::assertFalse($first->isPublic());
        static::assertSame('', $first->getDownloadLinkInstall());

        // changes should be reflected in objects returned a second time
        $first->download_link_install = 'https://example.com/install.zip';

        $second = $releaseRoot->getRelease('v6.2.1');
        static::assertInstanceOf(Release::class, $second);

        $first->makePublic();

        static::assertTrue($first->isPublic());
        static::assertSame('https://example.com/install.zip', $first->getDownloadLinkInstall());

        static::assertTrue($second->isPublic());
        static::assertSame('https://example.com/install.zip', $second->getDownloadLinkInstall());
    }

    public function testNonExistingRelease(): void
    {
        /** @var Release $releaseRoot */
        $releaseRoot = simplexml_load_string(file_get_contents(self::TEST_XML), Release::class);

        $release = $releaseRoot->getRelease('v6.2.2');
        static::assertNull($release);

        $release = $releaseRoot->getRelease('v6.2.0-RC2');
        static::assertNull($release);
    }

    public function testAddReleasePrepends(): void
    {
        /** @var Release $releaseRoot */
        $releaseRoot = simplexml_load_string(file_get_contents(self::TEST_XML), Release::class);

        static::assertNull($releaseRoot->getRelease('v6.2.2'));

        $releases = $releaseRoot->release;
        static::assertNotNull($releases);
        $count = \count($releases);

        $newRelease = $releaseRoot->addRelease('v6.2.2');
        static::assertInstanceOf(Release::class, $newRelease);
        $releases = $releaseRoot->release;
        static::assertNotNull($releases);
        static::assertCount($count + 1, $releases);

        $foundRelease = $releaseRoot->getRelease('v6.2.2');
        static::assertEquals($newRelease, $foundRelease);

        static::assertFalse($newRelease->isPublic());
        static::assertSame('6.2.2', $newRelease->getVersion());
        static::assertSame(0, $newRelease->getRc());

        $newRelease->makePublic();

        $releases = $releaseRoot->release;
        static::assertNotNull($releases);
        static::assertEquals($newRelease, $releases[0]);
    }
}
