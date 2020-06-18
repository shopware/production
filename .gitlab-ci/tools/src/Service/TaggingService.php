<?php


namespace Shopware\CI\Service;


use Composer\Semver\Constraint\Constraint;
use Composer\Semver\Constraint\MultiConstraint;
use Composer\Semver\Semver;
use Composer\Semver\VersionParser;
use GuzzleHttp\Client;
use GuzzleHttp\RequestOptions;
use function Symfony\Component\VarDumper\Dumper\esc;

class TaggingService
{
    /**
     * @var array
     */
    private $config;

    /**
     * @var Client
     */
    private $gitlabApiClient;

    public function __construct(array $config, Client $gitlabApiClient)
    {
        $this->config = $config;
        $this->gitlabApiClient = $gitlabApiClient;
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

    public function tagAndPushRepos(string $tag, array $repos): void
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

    public function tagAndPushPlatform(string $tag, string $commitRef, string $remote): void
    {
        $path = sys_get_temp_dir() . '/platform_' . bin2hex(random_bytes(16));
        mkdir($path);

        $path = escapeshellarg($path);

        $commitMsg = 'Release ' . $tag;
        $shellCode = <<<CODE
    git -C $path init --bare
    git -C $path remote add origin $remote
    git -C $path fetch --depth=1 origin $commitRef
    git -C $path reset --soft FETCH_HEAD
    git -C $path tag $tag -a -m "$commitMsg"
    git -C $path push origin refs/tags/$tag
CODE;

        system($shellCode, $retCode);

        if ($retCode !== 0) {
            throw new \RuntimeException('Failed tag platform and push it');
        }

        system('rm -Rf ' . $path);
    }

    public function createReleaseBranch(string $repository, string $tag, string $gitRemoteUrl): void
    {
        $repository = escapeshellarg($repository);
        $commitMsg = escapeshellarg('Release ' . $tag);
        $escapedTag = escapeshellarg($tag);
        $gitRemoteUrl = escapeshellarg($gitRemoteUrl);

        $shellCode = <<<CODE
            set -e
            git -C $repository add PLATFORM_COMMIT_SHA composer.json composer.lock public/recovery/install/data/version
            git -C $repository commit -m $commitMsg
            git -C $repository tag $escapedTag -a -m $commitMsg
            git -C $repository remote add release $gitRemoteUrl
            git -C $repository push release --tags
CODE;

        system($shellCode, $returnCode);

        if ($returnCode !== 0) {
            throw new \RuntimeException('Failed to create release branch');
        }
    }

    public function openMergeRequest(string $projectId, string $sourceBranch, string $targetBranch, string $title)
    {
        $requestOptions = [
            RequestOptions::JSON => [
                'id' => $projectId,
                'source_branch' => $sourceBranch,
                'target_branch' => $targetBranch,
                'title' => $title
            ]
        ];

        $this->gitlabApiClient->request('POST', 'projects/' . $projectId . '/merge_requests', $requestOptions);
    }
}
