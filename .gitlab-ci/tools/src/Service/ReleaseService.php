<?php declare(strict_types=1);

namespace Shopware\CI\Service;

use Composer\Semver\VersionParser;
use Shopware\CI\Service\ProcessBuilder as Builder;
use Symfony\Component\Console\Output\OutputInterface;

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

    /**
     * @var SbpClient
     */
    private $sbpClient;

    /**
     * @var OutputInterface
     */
    private $stdout;

    public function __construct(
        array $config,
        ReleasePrepareService $releasePrepareService,
        TaggingService $taggingService,
        SbpClient $sbpClient,
        OutputInterface $stdout
    ) {
        $this->config = $config;
        $this->taggingService = $taggingService;
        $this->releasePrepareService = $releasePrepareService;
        $this->sbpClient = $sbpClient;
        $this->stdout = $stdout;
    }

    public function releasePackage(string $tag): void
    {
        $this->stdout->writeln('Fetching release list');
        $releaseList = $this->releasePrepareService->getReleaseList();

        $release = $releaseList->getRelease($tag);
        if ($release === null) {
            throw new \RuntimeException('Tag ' . $tag . ' not found');
        }

        if ($release->isPublic()) {
            throw new \RuntimeException('Release ' . $tag . ' is already public');
        }

        $this->stdout->writeln('Make release public in xml');
        $release->makePublic();
        $release->release_date = (new \DateTime())->format('Y-m-d H:i');

        $this->releasePrepareService->uploadArchives($release);

        $this->stdout->writeln('Storing release list');
        $this->releasePrepareService->storeReleaseList($releaseList);

        $this->stdout->writeln('Register update in update api');
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

        $platformCommitSha = trim(file_get_contents($this->config['projectRoot'] . '/PLATFORM_COMMIT_SHA'));

        $this->tagAndPushManyRepos($tag, $this->config['repos']);

        try {
            $this->tagAndPushPlatform($tag, $platformCommitSha);
        } catch (\Throwable $e) {
            $this->stdout->writeln('Failed to tag and push platform for tag ' . $tag . '. Error: ' . $e->getMessage());
        }

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

        // TODO: does not work, because the branch is not created anymore. maybe push branch directly
        /* $this->taggingService->openMergeRequest(
            (string) $this->config['projectId'],
            'release/' . $tag,
            $this->config['targetBranch'],
            'Release ' . $tag
        );
         */

        try {
            $this->tagAndPushDevelopment($tag);
        } catch (\Throwable $e) {
            $this->stdout->writeln('Failed to tagAndPushDevelopment for tag ' . $tag . ' error: ' . $e->getMessage());
        }

        try {
            $this->releaseSbpVersion($tag);
        } catch (\Throwable $e) {
            $this->stdout->writeln('Failed to upsertSbpVersion for tag ' . $tag . ' error: ' . $e->getMessage());
        }
    }

    public function releaseSbpVersion(string $tag): void
    {
        $current = $this->sbpClient->getVersionByName($tag);
        $releaseDate = new \DateTimeImmutable();

        $this->sbpClient->upsertVersion($tag, $current['parent'] ?? null, $releaseDate->format('Y-m-d'), true);
    }

    public function validatePackage(array $packageData, string $tag, ?string $reference = null): bool
    {
        // if the composer.json contains a version like 6.3.0.0 it's also 6.3.0.0 in the composer.lock
        // if it it does not contain a version, but is tagged in git, the version will be v6.3.0.0
        return ltrim($packageData['version'], 'v') === ltrim($tag, 'v')
            && ($packageData['dist']['type'] ?? null) !== 'path'
            && ($reference === null || ($packageData['source']['reference'] ?? null) === trim($reference));
    }

    public function tagAndPushPlatform(string $tag, string $platformCommitSha, ?string $message = null): void
    {
        $this->taggingService->fetchTagPush(
            $tag,
            $platformCommitSha,
            'release',
            null,
            $this->config['platformRemoteUrl'],
            $message
        );
    }

    public function tagAndPushDevelopment(string $tag, string $branch = 'trunk', ?string $message = null): void
    {
        $repoPath = sys_get_temp_dir() . '/repo_' . bin2hex(random_bytes(16));
        mkdir($repoPath);

        $message = $message ?? 'Release ' . $tag;

        try {
            $this->taggingService->cloneOrFetch(
                $branch,
                $repoPath,
                'release',
                $this->config['developmentRemoteUrl'],
                false
            );

            $this->retry(
                function () use ($tag, $repoPath, $message) {
                    $this->stdout->writeln('Running composer install');
                    (new Builder())
                        ->in($repoPath)
                        ->timeout(240)
                        ->output($this->stdout)
                        ->with('message', $message)
                        ->run('rm composer.lock || true; composer install --no-scripts');

                    $platformSha = file_get_contents($this->config['projectRoot'] . '/PLATFORM_COMMIT_SHA');
                    $composerLockData = json_decode(file_get_contents($repoPath . '/composer.lock'), true);
                    $package = $this->getPackageFromComposerLock($composerLockData, 'shopware/platform');

                    if (!$this->validatePackage($package, $tag, $platformSha)) {
                        throw new \RuntimeException(
                            'PLATFORM_COMMIT_SHA: "' . $platformSha . '"
                            shopware/platform package data invalid. Current package data: ' . print_r($package, true)
                        );
                    }

                    return true;
                },
                5
            );

            (new Builder())
                ->in($repoPath)
                ->with('message', $message)
                ->run(
                    <<<'CODE'
                    git reset
                    git add --force composer.lock
                    git commit -m {{ $message }}
CODE
                )
                ->throw();

            $this->taggingService->createTag($tag, $repoPath, $message, true);
            $this->taggingService->pushTag($tag, $repoPath, 'release');
        } finally {
            (new Builder())
                ->with('dir', $repoPath)
                ->run('rm -Rf {{ $dir }}');
        }
    }

    public function getPackageFromComposerLock(array $composerLock, string $packageName): array
    {
        foreach ($composerLock['packages'] as $package) {
            if ($package['name'] === $packageName) {
                return $package;
            }
        }

        throw new \RuntimeException(sprintf('Package "%s" not found', $packageName));
    }

    private function tagAndPushManyRepos(string $tag, array $repos): void
    {
        foreach ($repos as $repoData) {
            $this->stdout->writeln('Creating tag ' . $tag . ' for ' . $repoData['path']);
            $this->taggingService->createTag($tag, $repoData['path'], 'Release ' . $tag, true);

            $this->stdout->writeln('Pushing tag ' . $tag . ' for ' . $repoData['path'] . ' to ' . $repoData['remoteUrl']);
            $this->taggingService->pushTag($tag, $repoData['path'], 'release', $repoData['remoteUrl']);
        }
    }

    private function updateStability(string $composerJsonPath, string $stability): void
    {
        $composerJson = json_decode(file_get_contents($composerJsonPath), true);

        $currentStability = VersionParser::normalizeStability($composerJson['minimum-stability']);
        $newStability = VersionParser::normalizeStability($stability);

        if ($currentStability !== $newStability) {
            $this->stdout->writeln('Updating composer minimum-stability from "' . $currentStability . '" to "' . $newStability . '"');

            $composerJson['minimum-stability'] = $newStability;
            $encoded = \json_encode($composerJson, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES);
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
        $this->retry(
            function () use ($composerLockPath, $tag, $repos) {
                $dir = $this->config['projectRoot'];

                $this->stdout->writeln('Deleting vendor/shopware');
                (new Builder())
                    ->in($dir)
                    ->output($this->stdout)
                    ->run('rm -Rf vendor/shopware');

                $this->stdout->writeln('Running composer update');
                (new Builder())
                    ->in($dir)
                    ->output($this->stdout)
                    ->timeout(240)
                    ->run('composer update -vvv "shopware/*" --no-interaction --no-scripts');

                $composerLock = json_decode(file_get_contents($composerLockPath), true);

                foreach ($repos as $repo => $repoData) {
                    $package = $this->getPackageFromComposerLock($composerLock, 'shopware/' . $repo);

                    $repoData['reference'] = exec('git -C ' . escapeshellarg($repoData['path']) . ' rev-parse HEAD');

                    if (!$this->validatePackage($package, $tag)) {
                        throw new \RuntimeException('Package invalid, package data: ' . print_r($package, true));
                    }
                }

                return true;
            },
            10
        );
    }

    private function retry(callable $callback, int $maxTries): void
    {
        $firstWaitTime = $this->config['composerUpdateWaitTime'] ?? 45;
        $stepWaitTime = (int) max(1, $firstWaitTime / 3);

        $this->stdout->writeln('Waiting ' . $firstWaitTime . 's until first attempt');
        sleep($firstWaitTime);

        $lastException = null;
        for ($i = 0; $i < $maxTries; ++$i) {
            if ($lastException !== null) {
                $this->stdout->writeln('Attempt failed. Message: ' . $lastException->getMessage());
                $this->stdout->writeln('Waiting ' . $stepWaitTime . 's until next retry');
            }
            sleep($stepWaitTime);

            try {
                if ($callback() === true) {
                    break;
                }
            } catch (\Throwable $e) {
                $lastException = $e;
            }
        }

        if ($i >= $maxTries) {
            throw ($lastException ?? new \RuntimeException('Callback did not return true'));
        }
    }
}
