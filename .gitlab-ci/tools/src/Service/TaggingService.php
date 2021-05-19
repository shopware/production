<?php declare(strict_types=1);

namespace Shopware\CI\Service;

use GuzzleHttp\Client;
use GuzzleHttp\RequestOptions;
use Shopware\CI\Service\Exception\TaggingException;
use Shopware\CI\Service\ProcessBuilder as Builder;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Exception\ProcessFailedException;

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

    /**
     * @var bool
     */
    private $sign;

    /**
     * @var OutputInterface
     */
    private $stdout;

    public function __construct(array $config, Client $gitlabApiClient, OutputInterface $stdout, bool $sign = false)
    {
        $this->config = $config;
        $this->gitlabApiClient = $gitlabApiClient;
        $this->sign = $sign;
        $this->stdout = $stdout;
    }

    public function createTag(string $tag, string $repoPath, string $message, bool $force = false): void
    {
        if ($force) {
            try {
                $this->deleteTag($tag, $repoPath);
            } catch (ProcessFailedException $e) {
                // ignore failure
            }
        }

        $sign = $this->sign ? '--sign' : '--no-sign';
        self::inRepo($repoPath)
            ->with('tag', $tag)
            ->with('message', $message)
            ->with('sign', $sign)
            ->run('git tag {{ $tag }} -a -m {{ $message }} {{ $sign }}')
            ->throw();
    }

    public function pushTag(string $tag, string $repoPath, string $remoteName, ?string $remoteUrl = null, ?string $localRef = null): void
    {
        $params = array_merge($this->config, [
            'tag' => $tag,
            'remoteName' => $remoteName,
            'remoteUrl' => $remoteUrl,
            'localRef' => $localRef ?? 'refs/tags/' . $tag,
            'remoteRef' => 'refs/tags/' . $tag,
        ]);

        if ($remoteUrl !== null) {
            self::inRepo($repoPath)
                ->with($params)
                ->run(
                    '
                    git remote remove {{ $remoteName }} || true;
                    git remote add {{ $remoteName }} {{ $remoteUrl }}'
                );
        }

        self::inRepo($repoPath)
            ->with($params)
            ->output($this->stdout)
            ->run('git push {{ $remoteName }} {{ $localRef }}:{{ $remoteRef }}')
            ->throw();
    }

    public function deleteTag(string $tag, string $repoPath): void
    {
        $builder = new Builder();
        $builder->in($repoPath)
            ->with('tag', $tag)
            ->output($this->stdout)
            ->run('git tag -d {{ $tag }}')
            ->throw();
    }

    public function fetchTagPush(string $tag, string $commitRef, string $remoteName = 'upstream', ?string $repoPath = null, ?string $remoteUrl = null, ?string $message = null): void
    {
        if ($remoteUrl !== null && filter_var($remoteUrl, \FILTER_VALIDATE_URL) === false) {
            throw new TaggingException($tag, $repoPath ?? '', 'remoteUrl is not a valid url');
        }

        $isTmp = false;
        if ($repoPath === null) {
            $repoPath = sys_get_temp_dir() . '/repo_' . bin2hex(random_bytes(16));
            $isTmp = true;
        }

        (new Builder())
            ->with('repoPath', $repoPath)
            ->run('mkdir -p {{ $repoPath }}');

        if (!file_exists($repoPath)) {
            throw new TaggingException($tag, $repoPath, 'Repository path not found');
        }

        try {
            $this->cloneOrFetch($commitRef, $repoPath, $remoteName, $remoteUrl);

            $message = $message ?? 'Release ' . $tag;
            $this->createTag($tag, $repoPath, $message, true);

            $this->pushTag($tag, $repoPath, $remoteName, $remoteUrl);
        } finally {
            if ($isTmp) {
                (new Builder())
                    ->with('dir', $repoPath)
                    ->run('rm -Rf {{ $dir }}');
            }
        }
    }

    public function cloneOrFetch(string $commitRef, string $repoPath, string $remoteName, ?string $remoteUrl = null, bool $bare = true): void
    {
        $params = array_merge($this->config, [
            'remoteUrl' => $remoteUrl,
            'remoteName' => $remoteName,
            'commitRef' => $commitRef,
        ]);

        if ($bare) {
            self::inRepo($repoPath)
                ->with($params)
                ->run('git init --bare  .')
                ->throw();
        } else {
            self::inRepo($repoPath)
                ->with($params)
                ->run('git init .')
                ->throw();
        }

        if ($remoteUrl !== null) {
            self::inRepo($repoPath)
                ->with($params)
                ->run('git remote add {{ $remoteName }} {{ $remoteUrl }}');
        }

        self::inRepo($repoPath)
            ->output($this->stdout)
            ->with($params)
            ->run('git fetch --depth=1 {{ $remoteName }} {{ $commitRef }}')
            ->throw();

        self::inRepo($repoPath)
            ->run('git reset --soft FETCH_HEAD')
            ->throw();

        if (!$bare) {
            self::inRepo($repoPath)
                ->run('git checkout HEAD')
                ->throw();
        }
    }

    public function createReleaseBranch(string $repository, string $tag, string $gitRemoteUrl): void
    {
        $repository = escapeshellarg($repository);
        $commitMsg = escapeshellarg('Release ' . $tag);
        $escapedTag = escapeshellarg($tag);
        $gitRemoteUrl = escapeshellarg($gitRemoteUrl);

        $sign = $this->sign ? '--gpg-sign' : '--no-gpg-sign';
        $signTag = $this->sign ? '--sign' : '--no-sign';

        $shellCode = <<<CODE
            set -e
            git -C $repository add PLATFORM_COMMIT_SHA composer.json composer.lock public/recovery/install/data/version
            git -C $repository commit -m $commitMsg $sign
            git -C $repository tag $escapedTag -a -m $commitMsg $signTag
            git -C $repository remote add release $gitRemoteUrl
            git -C $repository push release --tags
CODE;

        system($shellCode, $returnCode);

        if ($returnCode !== 0) {
            throw new \RuntimeException('Failed to create release branch');
        }
    }

    public function openMergeRequest(string $projectId, string $sourceBranch, string $targetBranch, string $title): void
    {
        $requestOptions = [
            RequestOptions::JSON => [
                'id' => $projectId,
                'source_branch' => $sourceBranch,
                'target_branch' => $targetBranch,
                'title' => $title,
            ],
        ];

        $this->gitlabApiClient->request('POST', 'projects/' . $projectId . '/merge_requests', $requestOptions);
    }

    private static function inRepo(string $repoPath): Builder
    {
        return (new Builder())->in($repoPath);
    }
}
