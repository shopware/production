<?php declare(strict_types=1);

namespace Shopware\CI\Test\Service;

use League\Flysystem\Filesystem;
use League\Flysystem\Memory\MemoryAdapter;
use PHPUnit\Framework\TestCase;
use Shopware\CI\Service\ChangelogService;
use Shopware\CI\Service\ReleasePrepareService;
use Shopware\CI\Service\SbpClient;
use Shopware\CI\Service\UpdateApiService;

class ReleasePrepareServiceTest extends TestCase
{
    /**
     * @var Filesystem
     */
    private $deployFilesystem;

    /**
     * @var Filesystem
     */
    private $artifactsFilesystem;

    public function setUp(): void
    {
        $this->artifactsFilesystem = new Filesystem(new MemoryAdapter());

        $this->deployFilesystem = new Filesystem(new MemoryAdapter());
        $this->deployFilesystem->putStream(ReleasePrepareService::SHOPWARE_XML_PATH, fopen(__DIR__ . '/fixtures/shopware6.xml', 'rb'));

        $this->artifactsFilesystem->put('install.zip', random_bytes(1024 * 1024 * 2 + 11));
        $this->artifactsFilesystem->put('install.tar.xz', random_bytes(1024 + 11));
        $this->artifactsFilesystem->put('update.zip', random_bytes(1024 * 1024 + 13));
    }

    public function testStoreReleaseListShouldChangeXmlWithoutChanges(): void
    {
        $releasePrepareService = $this->getReleasePrepareService();
        $shopwareXml = $this->deployFilesystem->read(ReleasePrepareService::SHOPWARE_XML_PATH);
        static::assertNotFalse($shopwareXml);
        $expectedHash = sha1($shopwareXml);

        $release = $releasePrepareService->getReleaseList();
        $releasePrepareService->storeReleaseList($release);

        $shopwareXml = $this->deployFilesystem->read(ReleasePrepareService::SHOPWARE_XML_PATH);
        static::assertNotFalse($shopwareXml);
        $actualHash = sha1($shopwareXml);
        static::assertSame($expectedHash, $actualHash);
    }

    public function testUpdatedXml(): void
    {
        $releasePrepareService = $this->getReleasePrepareService();

        $listFirstRead = $releasePrepareService->getReleaseList();

        $releaseFirstRead = $listFirstRead->getRelease('v6.2.2');
        static::assertNull($releaseFirstRead);

        $releaseFirstRead = $listFirstRead->addRelease('v6.2.2');
        static::assertNotNull($releaseFirstRead);
        $releaseFirstRead->makePublic();
        $releaseFirstRead->download_link_install = 'https://example.com/install.zip';

        $releasePrepareService->storeReleaseList($listFirstRead);

        // recreate service and load xml again
        $releasePrepareService = $this->getReleasePrepareService();

        $listSecondRead = $releasePrepareService->getReleaseList();
        static::assertEquals($listFirstRead, $listSecondRead);

        $releaseSecondRead = $listSecondRead->getRelease('v6.2.2');
        static::assertNotNull($releaseSecondRead);
        static::assertEquals($releaseFirstRead, $releaseSecondRead);
        $secondReadReleases = $listSecondRead->releases;
        static::assertNotNull($secondReadReleases);
        static::assertEquals($releaseSecondRead, $secondReadReleases[0], 'Should be first');

        static::assertTrue($releaseSecondRead->isPublic());
        static::assertSame('https://example.com/install.zip', $releaseSecondRead->getDownloadLinkInstall());
    }

    public function testPrepareReleaseOfExistingVersion(): void
    {
        $changelogService = $this->createMock(ChangelogService::class);

        $shopwareXml = $this->deployFilesystem->read(ReleasePrepareService::SHOPWARE_XML_PATH);
        static::assertNotFalse($shopwareXml);
        $expectedHash = sha1($shopwareXml);

        $changelogService
            ->method('getChangeLog')
            ->willReturn([
                'de' => ['changelog' => ['NEXT-1234 - DE Foo', 'NEXT-1235 - DE Bar']],
                'en' => ['changelog' => ['NEXT-1234 - EN Foo', 'NEXT-1235 - EN bar']],
            ]);

        $releasePrepareService = $this->getReleasePrepareService(null, $changelogService);

        $expectedList = $releasePrepareService->getReleaseList();

        try {
            $releasePrepareService->prepareRelease('6.2.0');
        } catch (\Throwable $e) {
            static::assertSame('Release 6.2.0 is already public', $e->getMessage());
        }

        $actualList = $releasePrepareService->getReleaseList();

        static::assertEquals($expectedList, $actualList);

        $shopwareXml = $this->deployFilesystem->read(ReleasePrepareService::SHOPWARE_XML_PATH);
        static::assertNotFalse($shopwareXml);
        $actualHash = sha1($shopwareXml);
        static::assertSame($expectedHash, $actualHash);
    }

    public function testPrepareRelease(): void
    {
        $changelogService = $this->createMock(ChangelogService::class);

        $changelogService
            ->method('getChangeLog')
            ->willReturn([
                'de' => ['changelog' => ['NEXT-1234 - DE Foo', 'NEXT-1235 - DE Bar']],
                'en' => ['changelog' => ['NEXT-1234 - EN Foo', 'NEXT-1235 - EN bar']],
            ]);

        $releasePrepareService = $this->getReleasePrepareService(null, $changelogService);

        $releasePrepareService->prepareRelease('6.2.2');

        $releaseList = $releasePrepareService->getReleaseList();

        $release = $releaseList->getRelease('v6.2.2');
        static::assertNotNull($release);
        static::assertNotNull($release->locales);

        static::assertSame(
            '
NEXT-1234 - DE Foo
NEXT-1235 - DE Bar
',
            (string) $release->locales->de->changelog
        );

        static::assertSame(
            '
NEXT-1234 - EN Foo
NEXT-1235 - EN bar
',
            (string) $release->locales->en->changelog
        );
    }

    public function testPrepareReleaseWithExistingVersion(): void
    {
        $changelogService = $this->createMock(ChangelogService::class);

        $changelogService
            ->method('getChangeLog')
            ->willReturn([
                'de' => ['changelog' => ['NEXT-1234 - DE Foo', 'NEXT-1235 - DE Bar']],
                'en' => ['changelog' => ['NEXT-1234 - EN Foo', 'NEXT-1235 - EN bar']],
            ]);

        $releasePrepareService = $this->getReleasePrepareService(null, $changelogService);

        $releaseList = $releasePrepareService->getReleaseList();
        $release = $releaseList->addRelease('6.2.2');
        static::assertNotNull($release);

        static::assertEmpty($release->locales);

        $releasePrepareService->storeReleaseList($releaseList);

        $releasePrepareService->prepareRelease('6.2.2');

        $releaseList = $releasePrepareService->getReleaseList();

        $release = $releaseList->getRelease('v6.2.2');
        static::assertNotNull($release);
        static::assertNotNull($release->locales);

        static::assertSame(
            '
NEXT-1234 - DE Foo
NEXT-1235 - DE Bar
',
            (string) $release->locales->de->changelog
        );

        static::assertSame(
            '
NEXT-1234 - EN Foo
NEXT-1235 - EN bar
',
            (string) $release->locales->en->changelog
        );
    }

    public function testManualChangelogIsNotOverwritten(): void
    {
        $changelogService = $this->createMock(ChangelogService::class);

        $changelogService
            ->method('getChangeLog')
            ->willReturn([
                'de' => ['changelog' => ['NEXT-1234 - DE Foo', 'NEXT-1235 - DE Bar']],
                'en' => ['changelog' => ['NEXT-1234 - EN Foo', 'NEXT-1235 - EN bar']],
            ]);

        $releasePrepareService = $this->getReleasePrepareService(null, $changelogService);

        $releaseList = $releasePrepareService->getReleaseList();
        $release = $releaseList->addRelease('6.2.2-RC1');
        static::assertNotNull($release);
        static::assertNotNull($release->locales);

        $release->manual = true;
        $release->locales->de->changelog = '<![CDATA[
NEXT-1234 - DE Foo
NEXT-1235 - DE Bar
]]>';

        $releasePrepareService->storeReleaseList($releaseList);

        $releasePrepareService->prepareRelease('6.2.2-RC1');

        $releaseList = $releasePrepareService->getReleaseList();

        $release = $releaseList->getRelease('v6.2.2-RC1');
        static::assertNotNull($release);
        static::assertNotNull($release->locales);

        static::assertSame(
            (string) $release->locales->de->changelog,
            '<![CDATA[
NEXT-1234 - DE Foo
NEXT-1235 - DE Bar
]]>'
        );

        static::assertEmpty((string) $release->locales->en->changelog);
    }

    public function testUpsertSbpVersion(): void
    {
        $sbpClient = $this->createMock(SbpClient::class);
        $releasePrepareService = $this->getReleasePrepareService(null, null, null, $sbpClient);

        $parentVersion = [
            'id' => 1,
            'name' => '6.3',
            'public' => false,
            'releaseDate' => null,
        ];
        $version = [
            'id' => 2,
            'name' => '6.3.0.0',
            'parent' => 1,
            'public' => false,
            'releaseDate' => null,
        ];
        $sbpClient->method('getVersion')->with(1)->willReturn($parentVersion);
        $sbpClient->method('getVersion')->with(2)->willReturn($version);
        $sbpClient->method('getVersionByName')->with('6.3')->willReturn($parentVersion);
        $sbpClient->method('getVersionByName')->with('6.3.0')->willReturn(null);
        $sbpClient->method('getVersionByName')->with('6.3.0.0')->willReturn($version);

        $sbpClient->expects($this->once())
            ->method('upsertVersion')
            ->with('6.3.0.0', 1, '2020-12-01', false);

        $releasePrepareService->upsertSbpVersion('v6.3.0.0');
    }

    public function testUpsertSbpVersionUseMostSpecificVersion(): void
    {
        $sbpClient = $this->createMock(SbpClient::class);
        $releasePrepareService = $this->getReleasePrepareService(null, null, null, $sbpClient);

        $parentParentVersion = [
            'id' => 1,
            'name' => '6.3',
            'public' => false,
            'releaseDate' => null,
        ];
        $parentVersion = [
            'id' => 2,
            'name' => '6.3.0',
            'parent' => $parentParentVersion['id'],
            'public' => false,
            'releaseDate' => null,
        ];
        $version = [
            'id' => 3,
            'name' => '6.3.0.0',
            'parent' => 1,
            'public' => false,
            'releaseDate' => null,
        ];
        $sbpClient->method('getVersion')->with($parentParentVersion['id'])->willReturn($parentParentVersion);
        $sbpClient->method('getVersion')->with($parentVersion['id'])->willReturn($parentVersion);
        $sbpClient->method('getVersion')->with($version['id'])->willReturn($version);
        $sbpClient->method('getVersionByName')->with($parentParentVersion['name'])->willReturn($parentParentVersion);
        $sbpClient->method('getVersionByName')->with($parentVersion['name'])->willReturn($parentVersion);
        $sbpClient->method('getVersionByName')->with($version['name'])->willReturn($version);

        $sbpClient->expects($this->once())
            ->method('upsertVersion')
            ->with('6.3.0.0', 1, '2020-12-01', false);

        $releasePrepareService->upsertSbpVersion('v6.3.0.0');
    }

    private function getReleasePrepareService(
        ?array $config = null,
        ?ChangelogService $changeLogService = null,
        ?UpdateApiService $updateApiService = null,
        ?SbpClient $sbpClient = null
    ): ReleasePrepareService {
        $changelogService = $changeLogService ?? $this->createMock(ChangelogService::class);
        $updateApiService = $updateApiService ?? $this->createMock(UpdateApiService::class);
        $sbpClient = $sbpClient ?? $this->createMock(SbpClient::class);
        $config = $config ??
            [
                'minimumVersion' => '6.2.0',
                'deployFilesystem' => [
                    'publicDomain' => 'https://releases.example.com/',
                ],
            ];

        return new ReleasePrepareService(
            $config,
            $this->deployFilesystem,
            $this->artifactsFilesystem,
            $changelogService,
            $updateApiService,
            $sbpClient
        );
    }
}
