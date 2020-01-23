<?php


namespace Shopware\CI\Service;


use Composer\Semver\VersionParser;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\RequestOptions;

class ReleaseService
{
    /**
     * @var ClientInterface
     */
    private $gitlabApiClient;

    /**
     * @var array
     */
    private $config;

    public function __construct(array $config, ClientInterface $gitlabApiClient)
    {
        $this->config = $config ?? self::getDefaultConfig();
        $this->gitlabApiClient = $gitlabApiClient;
    }

    /**
     * Copy new composer.lock into the projectRoot before calling this function
     */
    public function release(): void
    {
        $tag = $this->config['tag'];

        copy(
            $this->config['repos']['core']['path'] . '/PLATFORM_COMMIT_SHA',
            $this->config['projectRoot'] . '/PLATFORM_COMMIT_SHA'
        );

        $this->tagAndPushRepos($tag, $this->config['repos']);

        $this->updateStability(
            $this->config['projectRoot'] . '/composer.json',
            $this->config['stability']
        );

        $this->updateComposerLock(
            $this->config['projectRoot'] . '/composer.lock',
            $tag,
            $this->config['repos']
        );

        $this->createReleaseBranch(
            $this->config['projectRoot'],
            $tag,
            $this->config['gitlabRemoteUrl']
        );

        $this->openMergeRequest(
            $this->config['projectId'],
            'release/' . $tag,
            $this->config['targetBranch'],
            'Release ' . $tag
        );
    }

    private function tagAndPushRepos(string $tag, array $repos): void
    {
        $ref = escapeshellarg("refs/tags/$tag");
        $tag = escapeshellarg($tag);
        $commitMsg = escapeshellarg('Release ' . $tag);

        foreach ($repos as $repo => $repoData) {
            $path = escapeshellarg($repoData['path']);
            $remote = escapeshellarg($repoData['remoteUrl']);

            $shellCode = <<<CODE
    git -C $path tag $tag -a -m $commitMsg || true
    git -C $path remote add release  $remote
    git -C $path push release $ref
CODE;

            echo 'exec: ' . $shellCode . PHP_EOL;

            system($shellCode, $retCode);

            if ($retCode !== 0) {
                throw new \RuntimeException('Failed to push tag for ' . $repoData['remoteUrl'] . '. Please delete the tags that where already pushed');
            }
        }
    }

    private function updateStability(string $composerJsonPath, string $stability): void
    {
        $composerJson = json_decode(file_get_contents($composerJsonPath), true);
        $composerJson['minimum-stability'] = VersionParser::normalizeStability($stability);

        file_put_contents($composerJsonPath, \json_encode($composerJson, JSON_PRETTY_PRINT));
    }

    private function updateComposerLock(string $composerLockPath, string $tag, array $repos): void
    {
        $dir = escapeshellarg($this->config['projectRoot']);

        $max = 10;
        for($i = 0; $i < $max; ++$i) {
            sleep(15);

            $cmd = 'composer update --working-dir=' . $dir . ' shopware/* --ignore-platform-reqs --no-interaction --no-scripts';
            system($cmd);

            $composerLock = json_decode(file_get_contents($composerLockPath), true);

            foreach ($repos as $repo => $repoData) {
                $package = $this->getPackageFromComposerLock($composerLock, 'shopware/' . $repo);

                $repoData['reference'] = exec('git -C ' . escapeshellarg($repoData['path']) . ' rev-parse HEAD');

                if (!$this->validatePackage($package, $tag, $repoData)) {
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

    public function validatePackage(array $packageData, string $tag, array $repoData): bool
    {
        $packageName = $packageData['name'];

        if ($packageData['version'] !== $tag) {
            return false;
        }

        $repoPath = $repoData['path'];
        $commitSha = $repoData['reference'];

        if (isset($packageData['dist'])) {
            if ($packageData['dist']['type'] === 'path') {
                throw new \LogicException('dist type path should not be possible for ' . $packageName);
            }

            $distReference = $packageData['dist']['reference'];
            if (strtolower($distReference) !== $commitSha) {
                throw new \LogicException("commit sha of $repoPath $commitSha should be the sames as $packageName.dist.reference $distReference");
            }
        }

        $sourceRef = $packageData['source']['reference'];
        if (strtolower($sourceRef) !== $commitSha) {
            throw new \LogicException("commit sha of $repoPath $commitSha should be the sames as $packageName.source.reference $sourceRef");
        }

        return true;
    }

    private function createReleaseBranch(string $repository, string $tag, string $gitRemoteUrl): void
    {
        $repository = escapeshellarg($repository);
        $commitMsg = escapeshellarg('Release ' . $tag);
        $tag = escapeshellarg($tag);
        $gitRemoteUrl = escapeshellarg($gitRemoteUrl);

        $shellCode = <<<CODE
            set -e
            git -C $repository add PLATFORM_COMMIT_SHA composer.json composer.lock
            git -C $repository commit -m $commitMsg
            git -C $repository tag $tag -a -m $commitMsg
            git -C $repository remote add release $gitRemoteUrl
            git -C $repository push release --tags 
CODE;

        system($shellCode, $returnCode);

        if ($returnCode !== 0) {
            throw new \RuntimeException('Failed to create release branch');
        }
    }

    private function openMergeRequest(string $projectId, string $sourceBranch, string $targetBranch, string $title)
    {
        $requestOptions = [
            RequestOptions::JSON => [
                'id' => $projectId,
                'source_branch' => $sourceBranch,
                'target_branch' => $targetBranch,
                'title' => $title
            ]
        ];

        $this->gitlabApiClient->request('POST', '/projects/' . $projectId . '/merge_requests', $requestOptions);
    }
}