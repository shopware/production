<?php declare(strict_types=1);

namespace Shopware\CI\Test\Service;

use League\Flysystem\Filesystem;
use League\Flysystem\Memory\MemoryAdapter;
use PHPUnit\Framework\MockObject\Builder\InvocationStubber;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shopware\CI\Service\ChangelogService;
use Shopware\CI\Service\ReleasePrepareService;
use Shopware\CI\Service\SbpClient;
use Shopware\CI\Service\UpdateApiService;
use Symfony\Component\Console\Output\NullOutput;

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
        $secondReadReleases = $listSecondRead->release;
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
        $versions = [
            'parentVersion' => [
                'id' => 1,
                'name' => '6.3',
                'public' => false,
                'releaseDate' => null,
            ],
        ];
        $sbpClient = $this->createMock(SbpClient::class);
        $this->mockSbpClientVersions($sbpClient, $versions);

        $releasePrepareService = $this->getReleasePrepareService(null, null, null, $sbpClient);
        $releaseDate = new \DateTime();
        $releaseDate->setTimestamp(strtotime('first monday of next month'));

        $sbpClient->expects(static::once())
            ->method('upsertVersion')
            ->with('6.3.0.0', 1, $releaseDate->format('Y-m-d'), false);

        $releasePrepareService->upsertSbpVersion('v6.3.0.0');
    }

    public function testUpsertSbpVersionWithExisting(): void
    {
        $versions = [
            'parentVersion' => [
                'id' => 1,
                'name' => '6.3',
                'public' => false,
                'releaseDate' => null,
            ],
            'version' => [
                'id' => 2,
                'name' => '6.3.0.0',
                'public' => false,
            ],
        ];
        $sbpClient = $this->createMock(SbpClient::class);
        $this->mockSbpClientVersions($sbpClient, $versions);
        $releasePrepareService = $this->getReleasePrepareService(null, null, null, $sbpClient);

        $releaseDate = new \DateTime();
        $releaseDate->setTimestamp(strtotime('first monday of next month'));
        $sbpClient->expects(static::once())
            ->method('upsertVersion')
            ->with('6.3.0.0', 1, $releaseDate->format('Y-m-d'), false);

        $releasePrepareService->upsertSbpVersion('v6.3.0.0');
    }

    public function testUpsertSbpVersionWithExistingAndReleaseDate(): void
    {
        $releaseDate = (new \DateTimeImmutable())->format('Y-m-d');
        $versions = [
            'parentVersion' => [
                'id' => 1,
                'name' => '6.3',
                'public' => false,
                'releaseDate' => null,
            ],
            'version' => [
                'id' => 2,
                'name' => '6.3.0.0',
                'public' => false,
                'releaseDate' => $releaseDate,
            ],
        ];
        $sbpClient = $this->createMock(SbpClient::class);
        $this->mockSbpClientVersions($sbpClient, $versions);
        $releasePrepareService = $this->getReleasePrepareService(null, null, null, $sbpClient);

        $sbpClient->expects(static::once())
            ->method('upsertVersion')
            ->with('6.3.0.0', 1, $releaseDate, false);

        $releasePrepareService->upsertSbpVersion('v6.3.0.0');
    }

    public function testUpsertSbpVersionUseMostSpecificVersion(): void
    {
        $versions = [
            'parentParentVersion' => [
                'id' => 1,
                'name' => '6.3',
                'public' => false,
                'releaseDate' => null,
            ],
            'parentVersion' => [
                'id' => 2,
                'name' => '6.3.0',
                'parent' => 1,
                'public' => false,
                'releaseDate' => null,
            ],
        ];

        $sbpClient = $this->createMock(SbpClient::class);
        $this->mockSbpClientVersions($sbpClient, $versions);
        $releasePrepareService = $this->getReleasePrepareService(null, null, null, $sbpClient);

        $releaseDate = new \DateTime();
        $releaseDate->setTimestamp(strtotime('first monday of next month'));

        $sbpClient->expects(static::once())
            ->method('upsertVersion')
            ->with('6.3.0.0', 2, $releaseDate->format('Y-m-d'), false);

        $releasePrepareService->upsertSbpVersion('v6.3.0.0');
    }

    private function mockSbpClientVersions(MockObject $mock, array $versions): void
    {
        $indexedByName = array_column($versions, null, 'name');
        $indexedById = array_column($versions, null, 'id');

        $mock->method('getVersions')->willReturn(array_values($versions));

        /** @var InvocationStubber $getVersionByName */
        $getVersionByName = $mock->method('getVersionByName');
        $getVersionByName->willReturnCallback(function (string $name) use ($indexedByName) {
            return $indexedByName[$name] ?? null;
        });

        /** @var InvocationStubber $getVersion */
        $getVersion = $mock->method('getVersion');
        $getVersion->willReturnCallback(function (int $id) use ($indexedById) {
            return $indexedById[$id] ?? null;
        });
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
        $config = $config
            ?? [
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
            $sbpClient,
            new NullOutput()
        );
    }
}
