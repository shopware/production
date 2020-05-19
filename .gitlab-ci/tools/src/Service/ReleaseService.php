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

        $this->createInstallerVersionFile($this->config['projectRoot'], $tag);

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

    public function deleteTag(string $tag, array $repos): void
    {
        $pureTag = $tag;
        $ref = escapeshellarg("refs/tags/$tag");
        $tag = escapeshellarg($tag);
        $privateToken = $this->config['gitlabApiToken'];

        foreach ($repos as $repo => $repoData) {
            $path = escapeshellarg($repoData['path']);
            $githubUrl = $repoData['githubUrl'];

            $shellCode = <<<CODE
    git -C $path -d tag $tag || true
    git -C $path push origin :$ref
    curl -X DELETE -H "Private-Token: $privateToken" $githubUrl/git/refs/tags/$pureTag
CODE;

            echo 'exec: ' . $shellCode . PHP_EOL;

            system($shellCode, $retCode);

            if ($retCode !== 0) {
                echo 'Failed to delete tag for ' . $repoData['remoteUrl'] . '. Please delete by manual' . PHP_EOL;
            }
        }
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

        sleep(45);

        $max = 10;
        for($i = 0; $i < $max; ++$i) {
            sleep(15);

            $cmd = 'cd ' . $dir . ' && rm -Rf vendor/shopware';
            system($cmd);

            $cmd = 'composer update -vvv --working-dir=' . $dir . ' "shopware/*" --ignore-platform-reqs --no-interaction --no-scripts';
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
        return $packageData['version'] === $tag
            && isset($packageData['dist']['type'])
            && $packageData['dist']['type'] !== 'path';
    }

    private function createReleaseBranch(string $repository, string $tag, string $gitRemoteUrl): void
    {
        $repository = escapeshellarg($repository);
        $commitMsg = escapeshellarg('Release ' . $tag);
        $tag = escapeshellarg($tag);
        $gitRemoteUrl = escapeshellarg($gitRemoteUrl);

        $shellCode = <<<CODE
            set -e
            git -C $repository add PLATFORM_COMMIT_SHA composer.json composer.lock public/recovery/install/data/version
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
