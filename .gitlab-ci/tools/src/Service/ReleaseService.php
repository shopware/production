<?php


namespace Shopware\CI\Service;


use Composer\Semver\VersionParser;
use GuzzleHttp\Client;
use GuzzleHttp\RequestOptions;

class ReleaseService
{
    /**
     * @var Client
     */
    private $gitlabApiClient;

    /**
     * @var array
     */
    private $config;

    public function __construct(array $config = null)
    {
        $this->config = $this->getConfig() ?? $config;

        $this->gitlabApiClient = new Client([
            'base_uri' => $this->config['gitlabBaseUri'],
            'headers' => [
                'Private-Token' => $this->config['gitlabApiToken'],
                'Content-TYpe' => 'application/json'
            ]
        ]);
    }

    public static function getDefaultConfig(): array
    {
        $config = [
            'projectId' => $_SERVER['CI_PROJECT_ID'] ?? 184,
            'gitlabBaseUri' => $_SERVER['CI_API_V4_URL'] ?? 'https://gitlab.shopware.com/api/v4',
            'gitlabApiToken' => $_SERVER['BOT_API_TOKEN'],
            'gitlabRemoteUrl' => $_SERVER['CI_REPOSITORY_URL'],
            'tag' => $_SERVER['TAG'] ?? 'v6.2.0-alpha1',
            'targetBranch' => $_SERVER['TARGET_BRANCH'] ?? '6.2',
            'manyReposBaseUrl' => $_SERVER['MANY_REPO_BASE_URL'] ?? 'git@gitlab.shopware.com:shopware/6/product/many-repositories',
            'projectRoot' => $_SERVER['PROJECT_ROOT'],
        ];

        $config['stability'] = $_SERVER['STABILITY'] ?? VersionParser::parseStability($config['tag']);
        $config['repos'] = [
            'core' => [
                'path' => $config['projectRoot'] . '/repos/core',
                'remoteUrl' => $config['manyReposBaseUrl'] . '/core'
            ]
        ];

        return $config;
    }

    /**
     * Copy new composer.lock into the projectRoot before calling this function
     */
    public function release(): void
    {
        $tag = $this->config['tag'];

        copy(
            $this->config['repos']['path'] . '/PLATFORM_COMMIT_SHA',
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
            $this->config['repository'],
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
        foreach ($repos as $repo => $repoData) {
            $path = escapeshellarg($repoData['path']);
            $commitMsg = escapeshellarg('Release ' . $tag);
            $remote = escapeshellarg($repoData['remoteUrl']);
            $tag = escapeshellarg($tag);

            $shellCode = <<<CODE
    git -C $path tag -a -m $commitMsg || true
    git -C $path remote add release  $remote
    git -C $path push release refs/tags/$tag
    
CODE;

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

        file_put_contents($composerJsonPath, \json_encode($composerJson));
    }

    private function updateComposerLock(string $composerLockPath, string $tag, array $repos): void
    {
        $max = 10;
        for($i = 0; $i < $max; ++$i) {
            sleep(15);

            system('composer update shopware/* --ignore-platform-reqs --no-interaction --no-scripts');

            $composerLock = json_decode(file_get_contents($composerLockPath));

            foreach ($repos as $repo => $repoData) {
                $package = $this->getPackageFromComposerLock($composerLock, 'shopware/' . $repo);

                // retry top loop
                if ($package['version'] !== $tag) {
                    continue 2;
                }

                $this->validatePackage($package, $tag, $repoData);
            }
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

    private function validatePackage(array $packageData, string $tag, array $repoData): void
    {
        $packageName = $packageData['name'];
        if ($packageData['dist']['type'] === 'path') {
            throw new \LogicException('dist type path should not be possible for ' . $packageName);
        }

        $reference = $packageData['dist']['reference'];
        $repoPath = $repoData['path'];
        $commitSha = exec('git -C ' . escapeshellarg($repoPath) . ' rev-parse HEAD');

        if (strtolower($reference) !== $commitSha) {
            throw new \LogicException("commit sha of $repoPath $commitSha should be the sames as $packageName.dist.reference $reference");
        }
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
#git -C $repository tag $tag -a -m $commitMsg
git -C $repository remote add release $gitRemoteUrl
git -C $repository push release # --tags 
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

        $this->gitlabApiClient->post('/projects/' . $projectId . '/merge_requests', $requestOptions);
    }
}