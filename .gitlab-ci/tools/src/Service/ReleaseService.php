<?php


namespace Shopware\CI\Service;


use Composer\Semver\VersionParser;

class ReleaseService
{
    /**
     * @var array
     */
    private $config;

    /**
     * @var TaggingService
     */
    private $taggingService;

    /**
     * @var ReleasePrepareService
     */
    private $releasePrepareService;

    public function __construct(
        array $config,
        ReleasePrepareService $releasePrepareService,
        TaggingService $taggingService
    )
    {
        $this->config = $config;
        $this->taggingService = $taggingService;
        $this->releasePrepareService = $releasePrepareService;
    }

    public function releasePackage(string $tag): void
    {
        $releaseList = $this->releasePrepareService->getReleaseList();

        $release = $releaseList->getRelease($tag);
        if ($release === null) {
            throw new \RuntimeException('Tag ' . $tag . ' not found');
        }

        if ($release->isPublic()) {
            throw new \RuntimeException('Release ' . $tag . ' is already public');
        }

        $release->makePublic();

        $this->releasePrepareService->uploadArchives($release);

        $this->releasePrepareService->storeReleaseList($releaseList);

        $this->releasePrepareService->registerUpdate($tag, $release);
    }

    /**
     * Copy new composer.lock into the projectRoot before calling this function
     */
    public function releaseTags(string $tag): void
    {
        copy(
            $this->config['repos']['core']['path'] . '/PLATFORM_COMMIT_SHA',
            $this->config['projectRoot'] . '/PLATFORM_COMMIT_SHA'
        );

        $this->taggingService->tagAndPushRepos($tag, $this->config['repos']);

        $this->updateStability(
            $this->config['projectRoot'] . '/composer.json',
            $this->config['stability']
        );

        $this->updateComposerLock(
            $this->config['projectRoot'] . '/composer.lock',
            $tag,
            $this->config['repos']
        );

        $this->createInstallerVersionFile($this->config['projectRoot'], $tag);

        $this->taggingService->createReleaseBranch(
            $this->config['projectRoot'],
            $tag,
            $this->config['gitlabRemoteUrl']
        );

        $this->taggingService->openMergeRequest(
            $this->config['projectId'],
            'release/' . $tag,
            $this->config['targetBranch'],
            'Release ' . $tag
        );
    }

    private function updateStability(string $composerJsonPath, string $stability): void
    {
        $composerJson = json_decode(file_get_contents($composerJsonPath), true);

        $currentStability = VersionParser::normalizeStability($composerJson['minimum-stability']);
        $newStability = VersionParser::normalizeStability($stability);

        if ($currentStability !== $newStability) {
            $composerJson['minimum-stability'] = $newStability;
            $encoded = \json_encode($composerJson, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);
            file_put_contents($composerJsonPath, $encoded);
        }
    }

    private function createInstallerVersionFile(string $projectRoot, string $tag): void
    {
        $dir = $projectRoot . '/public/recovery/install/data';
        @mkdir($dir, 0770, true);
        file_put_contents($dir . '/version', $tag);
    }

    private function updateComposerLock(string $composerLockPath, string $tag, array $repos): void
    {
        $dir = escapeshellarg($this->config['projectRoot']);

        $composerWaitTime = $this->config['composerUpdateWaitTime'] ?? 45;
        sleep($composerWaitTime);

        $max = 10;
        for($i = 0; $i < $max; ++$i) {
            sleep($composerWaitTime / 3);

            $cmd = 'cd ' . $dir . ' && rm -Rf vendor/shopware';
            system($cmd);

            $cmd = 'composer update -vvv --working-dir=' . $dir . ' "shopware/*" --no-interaction --no-scripts';
            system($cmd);

            $composerLock = json_decode(file_get_contents($composerLockPath), true);

            foreach ($repos as $repo => $repoData) {
                $package = $this->getPackageFromComposerLock($composerLock, 'shopware/' . $repo);

                $repoData['reference'] = exec('git -C ' . escapeshellarg($repoData['path']) . ' rev-parse HEAD');

                if (!$this->validatePackage($package, $tag)) {
                    echo "retry! current packageData:" . PHP_EOL;
                    var_dump($package);
                    continue 2;
                }
            }

            break; // is valid
        }

        if ($i >= $max) {
            throw new \RuntimeException('Failed to update composer.lock');
        }
    }

    private function getPackageFromComposerLock(array $composerLock, string $packageName): ?array
    {
        foreach ($composerLock['packages'] as $package) {
            if ($package['name'] === $packageName) {
                return $package;
            }
        }

        return null;
    }

    public function validatePackage(array $packageData, string $tag): bool
    {
        // if the composer.json contains a version like 6.3.0.0 it's also 6.3.0.0 in the composer.lock
        // if it it does not contain a version, but is tagged in git, the version will be v6.3.0.0
        return ltrim($packageData['version'], 'v') === ltrim($tag, 'v')
            && ($packageData['dist']['type'] ?? null) !== 'path';
    }
}
