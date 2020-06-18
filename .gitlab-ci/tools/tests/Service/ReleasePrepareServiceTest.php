<?php


namespace Shopware\CI\Test\Service;


use League\Flysystem\Filesystem;
use League\Flysystem\Memory\MemoryAdapter;
use PHPUnit\Framework\TestCase;
use PHPUnit\Util\Exception;
use Shopware\CI\Service\ChangelogService;
use Shopware\CI\Service\ReleasePrepareService;
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

    private function getReleasePrepareService(array $config = null, ChangelogService $changeLogService = null, UpdateApiService $updateApiService = null)
    {
        $changelogService = $changeLogService ?? $this->createMock(ChangelogService::class);
        $updateApiService = $updateApiService ?? $this->createMock(UpdateApiService::class);
        $config = $config ??
            [
                'minimumVersion' => '6.2.0',
                'deployFilesystem' => [
                    'publicDomain' => 'https://releases.example.com/'
                ]
            ];

        return new ReleasePrepareService($config, $this->deployFilesystem, $this->artifactsFilesystem, $changelogService, $updateApiService);
    }

    public function testStoreReleaseListShouldChangeXmlWithoutChanges(): void
    {
        $releasePrepareService = $this->getReleasePrepareService();
        $expectedHash = sha1($this->deployFilesystem->read(ReleasePrepareService::SHOPWARE_XML_PATH));

        $release = $releasePrepareService->getReleaseList();
        $releasePrepareService->storeReleaseList($release);

        $actualHash = sha1($this->deployFilesystem->read(ReleasePrepareService::SHOPWARE_XML_PATH));
        static::assertSame($expectedHash, $actualHash);
    }

    public function testUpdatedXml(): void
    {
        $releasePrepareService = $this->getReleasePrepareService();

        $listFirstRead = $releasePrepareService->getReleaseList();

        $releaseFirstRead = $listFirstRead->getRelease('v6.2.2');
        static::assertNull($releaseFirstRead);

        $releaseFirstRead = $listFirstRead->addRelease('v6.2.2');
        $releaseFirstRead->makePublic();
        $releaseFirstRead->download_link_install = 'https://example.com/install.zip';

        $releasePrepareService->storeReleaseList($listFirstRead);

        // recreate service and load xml again
        $releasePrepareService = $this->getReleasePrepareService();

        $listSecondRead = $releasePrepareService->getReleaseList();
        static::assertEquals($listFirstRead, $listSecondRead);

        $releaseSecondRead = $listSecondRead->getRelease('v6.2.2');
        static::assertEquals($releaseFirstRead, $releaseSecondRead);
        static::assertEquals($releaseSecondRead, $listSecondRead->release[0], 'Should be first');

        static::assertTrue($releaseSecondRead->isPublic());
        static::assertSame('https://example.com/install.zip', $releaseSecondRead->getDownloadLinkInstall());
    }

    public function testPrepareReleaseOfExistingVersion(): void
    {
        $changelogService = $this->createMock(ChangelogService::class);

        $expectedHash = sha1($this->deployFilesystem->read(ReleasePrepareService::SHOPWARE_XML_PATH));

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


        $actualHash = sha1($this->deployFilesystem->read(ReleasePrepareService::SHOPWARE_XML_PATH));
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

        static::assertSame(
            '
NEXT-1234 - DE Foo
NEXT-1235 - DE Bar
',
            (string)$release->locales->de->changelog
        );

        static::assertSame(
            '
NEXT-1234 - EN Foo
NEXT-1235 - EN bar
',
            (string)$release->locales->en->changelog
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

        static::assertEmpty($release->locales);

        $releasePrepareService->storeReleaseList($releaseList);

        $releasePrepareService->prepareRelease('6.2.2');

        $releaseList = $releasePrepareService->getReleaseList();

        $release = $releaseList->getRelease('v6.2.2');

        static::assertSame(
            '
NEXT-1234 - DE Foo
NEXT-1235 - DE Bar
',
            (string)$release->locales->de->changelog
        );

        static::assertSame(
            '
NEXT-1234 - EN Foo
NEXT-1235 - EN bar
',
            (string)$release->locales->en->changelog
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
        $release->manual = true;
        $release->locales->de->changelog = '<![CDATA[
NEXT-1234 - DE Foo
NEXT-1235 - DE Bar
]]>';

        $releasePrepareService->storeReleaseList($releaseList);

        $releasePrepareService->prepareRelease('6.2.2-RC1');

        $releaseList = $releasePrepareService->getReleaseList();

        $release = $releaseList->getRelease('v6.2.2-RC1');

        static::assertSame(
            (string)$release->locales->de->changelog,
            '<![CDATA[
NEXT-1234 - DE Foo
NEXT-1235 - DE Bar
]]>'
        );

        static::assertEmpty((string)$release->locales->en->changelog);
    }
}
