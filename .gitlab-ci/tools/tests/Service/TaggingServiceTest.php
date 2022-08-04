<?php declare(strict_types=1);

namespace Shopware\CI\Test\Service;

use GuzzleHttp\Client;
use PHPUnit\Framework\TestCase;
use Shopware\CI\Service\ProcessBuilder as Builder;
use Shopware\CI\Service\TaggingService;
use Symfony\Component\Console\Output\NullOutput;

class TaggingServiceTest extends TestCase
{
    /**
     * @var array
     */
    private $tmpDirs = [];

    public function setUp(): void
    {
        if (!isset($_SERVER['SSH_PRIVATE_KEY_FILE'])) {
            $this->configureGitIdentity();
        } else {
            Builder::loadSshKey($_SERVER['SSH_PRIVATE_KEY_FILE']);
        }
    }

    public function tearDown(): void
    {
        foreach ($this->tmpDirs as $tmpDir) {
            (new Builder())
                ->with('dir', $tmpDir)
                ->run('rm -Rf {{ $dir }}');
        }
    }

    public function testTagAndPush(): void
    {
        $config = ['stability' => 'alpha'];
        $gitlabClient = $this->createMock(Client::class);
        $taggingService = new TaggingService($config, $gitlabClient, new NullOutput());
        $upstreamRemotePath = $this->getTmpDir();

        $this->initialiseTestingRepository($upstreamRemotePath);

        $commitSha = trim((new Builder())
            ->in($upstreamRemotePath)
            ->with('rev', '@^1')
            ->run('git rev-parse {{ $rev }}')
            ->throw()
            ->output());

        $upstreamUrl = 'file://' . $upstreamRemotePath;

        $taggingService->fetchTagPush(
            'v6.3.0.1-dev',
            $commitSha,
            'upstream',
            null,
            $upstreamUrl
        );

        $tagSha = trim((new Builder())
            ->in($upstreamRemotePath)
            ->with('rev', 'v6.3.0.1-dev^{}')
            ->run('git rev-parse {{ $rev }}')
            ->throw()
            ->output());

        static::assertSame($commitSha, $tagSha, 'Test repo: ' . $upstreamRemotePath);
    }

    public function testItPushesTheReleaseCommitToTheReleaseBranch(): void
    {
        $version = '6.99.99.99';
        $tag = sprintf('v%s', $version);
        $repositories = [
            'local' => $this->getTmpDir(),
            'remote' => $this->getTmpDir(),
        ];
        $remoteUrl = sprintf('file://%s', $repositories['remote']);

        $gitlabClient = $this->createMock(Client::class);
        $taggingService = new TaggingService(
            ['stability' => 'alpha', 'targetBranch' => $version],
            $gitlabClient,
            new NullOutput()
        );

        // Prepare the remote repository and create a release branch
        $this->initialiseTestingRepository($repositories['remote']);
        $this->createBranch($version, $repositories['remote']);

        $taggingService->cloneOrFetch(
            $version,
            $repositories['local'],
            'origin',
            $remoteUrl,
            false
        );

        $this->checkoutBranch($version, $repositories['local']);

        foreach (['PLATFORM_COMMIT_SHA', 'composer.json', 'composer.lock'] as $file) {
            touch(implode(\DIRECTORY_SEPARATOR, [$repositories['local'], $file]));
        }

        $taggingService->createReleaseBranch(
            $repositories['local'],
            $tag,
            $remoteUrl
        );

        $refs = [];
        $builder = (new Builder())->with([
            'releaseBranch' => $version,
            'tag' => $tag,
        ]);

        foreach ($repositories as $repo) {
            $builder->in($repo);

            $refs['releaseBranch'][] = $builder
                ->run('git rev-parse {{ $releaseBranch }}')
                ->throw()
                ->output();

            $refs['tag'][] = $builder
                ->run('git rev-parse {{ $tag }}')
                ->throw()
                ->output();
        }

        // Assert the tags point to the same commit
        static::assertSame(...$refs['tag']);

        // Assert the release branches point to the same commit
        static::assertSame(...$refs['releaseBranch']);
    }

    private function getTmpDir(): string
    {
        $tmpDir = sys_get_temp_dir() . '/TaggingServiceTest_' . bin2hex(random_bytes(16));
        mkdir($tmpDir);

        $this->tmpDirs[] = $tmpDir;

        return $tmpDir;
    }

    private function configureGitIdentity(): void
    {
        $cmd = <<<CODE
            git config --global user.email "swag@example.com"
            git config --global user.name "shopware AG"
CODE;

        (new Builder())->run($cmd)->throw();
    }

    private function initialiseTestingRepository(string $path): string
    {
        $initCommand = <<<CODE
            git init .
            touch test
            git add test
            git commit -m "Test commit"
            touch not_included_in_tag
            git add not_included_in_tag
            git commit -m "Not included"
            git config uploadpack.allowTipSHA1InWant true
            git config uploadpack.allowReachableSHA1InWant true
            git config uploadpack.allowAnySHA1InWant true
CODE;

        return (new Builder())
            ->in($path)
            ->run($initCommand)
            ->throw()
            ->output();
    }

    private function createBranch(string $name, string $path): string
    {
        $builder = (new Builder())
            ->in($path)
            ->with('name', $name);

        $builder->run('git branch -c {{ $name }}')
            ->throw();

        return $builder->run('git rev-parse {{ $name }}')
            ->throw()
            ->output();
    }

    private function checkoutBranch(string $name, string $path): string
    {
        return (new Builder())
            ->in($path)
            ->with('name', $name)
            ->run('git checkout {{ $name }}')
            ->throw()
            ->output();
    }
}
