<?php declare(strict_types=1);

namespace Shopware\CI\Service;

use League\Flysystem\FilesystemInterface;
use Shopware\CI\Service\Xml\Release;
use Symfony\Component\Console\Output\OutputInterface;

class ReleasePrepareService
{
    public const SHOPWARE_XML_PATH = '_meta/shopware6.xml';

    private array $config;

    private FilesystemInterface $deployFilesystem;

    private ChangelogService $changelogService;

    private FilesystemInterface $artifactsFilesystem;

    private UpdateApiService $updateApiService;

    private SbpClient $sbpClient;

    private OutputInterface $stdout;

    public function __construct(
        array $config,
        FilesystemInterface $deployFilesystem,
        FilesystemInterface $artifactsFilesystem,
        ChangelogService $changelogService,
        UpdateApiService $updateApiService,
        SbpClient $sbpClient,
        OutputInterface $stdout
    ) {
        $this->config = $config;
        $this->deployFilesystem = $deployFilesystem;
        $this->changelogService = $changelogService;
        $this->artifactsFilesystem = $artifactsFilesystem;
        $this->updateApiService = $updateApiService;
        $this->sbpClient = $sbpClient;
        $this->stdout = $stdout;
    }

    public function prepareRelease(string $tag): void
    {
        $releaseList = $this->getReleaseList();

        $release = $releaseList->getRelease($tag);
        if ($release === null) {
            $release = $releaseList->addRelease($tag);
        }
        if ($release === null) {
            throw new \RuntimeException('Still no release. Something went really wrong there');
        }

        if ($release->isPublic()) {
            throw new \RuntimeException('Release ' . $tag . ' is already public');
        }

        $this->setReleaseProperties($tag, $release);

        $this->uploadArchives($release);

        if ($this->mayAlterChangelog($release)) {
            try {
                $changelog = $this->changelogService->getChangeLog($tag);
                $release->setLocales($changelog);
            } catch (\Throwable $e) {
                $this->stdout->writeln('Failed to write changelog: ' . $e->getMessage());
            }
        } else {
            $this->stdout->writeln('May not alter changelog');
        }

        $this->storeReleaseList($releaseList);

        $this->registerUpdate($tag, $release);

        try {
            $this->upsertSbpVersion($tag);
        } catch (\Throwable $e) {
            $this->stdout->writeln('Failed to upsertSbpVersion for tag ' . $tag . ' error: ' . $e->getMessage());
        }
    }

    public function upsertSbpVersion(string $tag): void
    {
        $parsed = VersioningService::parseTag($tag);
        $version = $parsed['major'] . '.' . $parsed['minor'] . '.' . $parsed['patch'] . '.' . $parsed['build'];

        $nextParentVersions = [
            $parsed['major'] . '.' . $parsed['minor'] . '.' . $parsed['patch'],
            $parsed['major'] . '.' . $parsed['minor'],
            $parsed['major'],
        ];

        $parent = null;
        foreach ($nextParentVersions as $nextParentVersion) {
            $parent = $this->sbpClient->getVersionByName((string) $nextParentVersion);
            if ($parent !== null) {
                $this->stdout->writeln('Found parent ' . $parent['name'] . ' for ' . $version);

                break;
            }
        }

        if ($parent === null) {
            throw new \RuntimeException('failed to get sbp version. parent not found');
        }

        $current = $this->sbpClient->getVersionByName($version);
        if (isset($current['releaseDate'])) {
            $releaseDate = new \DateTimeImmutable(isset($current['releaseDate']['date']) ? $current['releaseDate']['date'] : $current['releaseDate']);
        } else {
            $releaseDate = new \DateTime();
            $releaseDate->setTimestamp(strtotime('first monday of next month'));
        }

        $this->stdout->writeln('Upserting sbp version ' . $version . ' with release date ' . $releaseDate->format('Y-m-d'));
        $this->sbpClient->upsertVersion($version, $parent['id'], $releaseDate->format('Y-m-d'), null);
    }

    public function uploadArchives(Release $release): void
    {
        $releaseTag = $release->getTag();
        $installUpload = $this->hashAndUpload($releaseTag, 'install.zip');
        $release->download_link_install = $installUpload['url'];
        $release->sha1_install = $installUpload['sha1'];
        $release->sha256_install = $installUpload['sha256'];

        $updateUpload = $this->hashAndUpload($releaseTag, 'update.zip');
        $release->download_link_update = $updateUpload['url'];
        $release->sha1_update = $updateUpload['sha1'];
        $release->sha256_update = $updateUpload['sha256'];

        $this->hashAndUpload($releaseTag, 'install.tar.xz');
        $minorBranch = VersioningService::getMinorBranch($releaseTag);
        $this->hashAndUpload(
            $releaseTag,
            'install.tar.xz',
            'sw6/install_' . $minorBranch . '_next.tar.xz' // 6.2_next.tar.xz, 6.3.0_next.tar.xz, 6.3.1_next.tar.xz
        );
    }

    public function getReleaseList(): Release
    {
        $content = $this->deployFilesystem->read(self::SHOPWARE_XML_PATH);
        if ($content === false) {
            throw new \RuntimeException('Could not read Shopware xml file');
        }

        /** @var Release $release */
        $release = simplexml_load_string($content, Release::class);

        return $release;
    }

    public function storeReleaseList(Release $release): void
    {
        $dom = new \DOMDocument('1.0');
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = true;
        $releaseXml = $release->asXML();
        if ($releaseXml === false) {
            throw new \RuntimeException('Release XML file is invalid');
        }
        $dom->loadXML($releaseXml);

        $this->deployFilesystem->put(self::SHOPWARE_XML_PATH, $dom->saveXML());
    }

    public function registerUpdate(string $tag, Release $release): void
    {
        $baseParams = [
            '--release-version' => $release->getVersion(),
            '--channel' => VersioningService::getUpdateChannel($tag),
        ];

        if ($release->getVersionText() !== '') {
            $baseParams['--version-text'] = $release->getVersionText();
        }

        $insertReleaseParameters = array_merge($baseParams, [
            '--min-version' => $this->config['minimumVersion'] ?? '6.2.0',
            '--install-uri' => $release->getDownloadLinkInstall(),
            '--install-size' => (string) $this->artifactsFilesystem->getSize('install.zip'),
            '--install-sha1' => $release->getSha1Install(),
            '--install-sha256' => $release->getSha256Install(),
            '--update-uri' => $release->getDownloadLinkUpdate(),
            '--update-size' => (string) $this->artifactsFilesystem->getSize('update.zip'),
            '--update-sha1' => $release->getSha1Update(),
            '--update-sha256' => $release->getSha256Update(),
        ]);

        $this->updateApiService->insertReleaseData($insertReleaseParameters);
        $this->updateApiService->updateReleaseNotes($baseParams);

        if ($release->isPublic()) {
            $this->updateApiService->publishRelease($baseParams);
        }
    }

    private function setReleaseProperties(string $tag, Release $release): void
    {
        $release->minimum_version = $this->config['minimumVersion'] ?? '6.2.0';
        $release->public = '0';
        $release->ea = 0;
        $release->revision = '';
        $release->type = VersioningService::getReleaseType($tag);
        $release->release_date = '';
        $release->tag = $tag;
        $release->github_repo = 'https://github.com/shopware/platform/tree/' . $tag;

        $release->upgrade_md = sprintf(
            'https://github.com/shopware/platform/blob/%s/UPGRADE-%s.md',
            $tag,
            VersioningService::getMajorBranch($tag)
        );
    }

    private function hashAndUpload(string $tag, string $source, ?string $targetPath = null): array
    {
        $sha1 = $this->hashFile('sha1', $source);
        $sha256 = $this->hashFile('sha256', $source);

        $basename = basename($source);
        $parts = explode('.', $basename, 2);

        $targetPath = $targetPath ?: 'sw6/' . $parts[0] . '_' . $tag . '_' . $sha1 . '.' . $parts[1];
        $sourceStream = $this->artifactsFilesystem->readStream($source);
        if ($sourceStream === false) {
            throw new \RuntimeException(sprintf('Could not read from source: "%s"', $source));
        }

        $this->stdout->writeln('Uploading ' . $basename . ' to ' . $targetPath);
        $this->stdout->writeln('sha1: ' . $sha1);
        $this->stdout->writeln('sha256: ' . $sha256);

        $this->deployFilesystem->putStream($targetPath, $sourceStream);

        return [
            'url' => $this->config['deployFilesystem']['publicDomain'] . '/' . $targetPath,
            'sha1' => $sha1,
            'sha256' => $sha256,
        ];
    }

    private function hashFile(string $alg, string $path): string
    {
        $context = hash_init($alg);
        $pathStream = $this->artifactsFilesystem->readStream($path);
        if ($pathStream === false) {
            throw new \RuntimeException(sprintf('Could not read from path: "%s"', $path));
        }
        $_bytesAdded = hash_update_stream($context, $pathStream);

        return hash_final($context);
    }

    private function mayAlterChangelog(Release $release): bool
    {
        return !$release->isPublic() && !$release->isManual();
    }
}
