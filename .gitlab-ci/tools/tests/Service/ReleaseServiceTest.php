<?php declare(strict_types=1);

namespace Shopware\CI\Test\Service;

use Composer\Semver\VersionParser;
use GuzzleHttp\Client;
use PHPUnit\Framework\MockObject\Builder\InvocationStubber;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shopware\CI\Service\ProcessBuilder as Builder;
use Shopware\CI\Service\ReleasePrepareService;
use Shopware\CI\Service\ReleaseService;
use Shopware\CI\Service\SbpClient;
use Shopware\CI\Service\TaggingService;
use Shopware\CI\Service\Xml\Release;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\NullOutput;

class ReleaseServiceTest extends TestCase
{
    public const FAKE_PLATFORM_COMMIT_SHA = '01c209a305adaabaf894e6929290c69cdeadbeef';

    private ?int $daemonPid = null;

    private ?string $fakeProd = null;

    private ?string $fakeRemoteRepos = null;

    /**
     * @var array
     */
    private $tmpDirs = [];

    public function tearDown(): void
    {
        $this->stopGitServer();

        foreach ($this->tmpDirs as $tmpDir) {
            (new Builder())
                ->with('dir', $tmpDir)
                ->run('rm -Rf {{ $dir }}');
        }

        if ($this->fakeProd !== null && trim($this->fakeProd) !== '/') {
            exec('rm -Rf ' . escapeshellarg($this->fakeProd));
        }

        if ($this->fakeRemoteRepos !== null && trim($this->fakeRemoteRepos) !== '/') {
            exec('rm -Rf ' . escapeshellarg($this->fakeRemoteRepos) . '/*');
        }
    }

    public function validatePackageProvider(): array
    {
        $validSha = '36f9ca136c87f4d3f860cd6b2516f02f2516f02a';
        $invalidSha = '36f9ca136c87f4d3f860cd6b2516f02bdeadbeef';

        return [
            [
                false,
                [
                    'name' => 'shopware/core',
                    'version' => 'v6.1.0',
                    'source' => [
                        'reference' => $validSha,
                    ],
                ],
                'v6.1.1',
            ],
            [
                true,
                [
                    'name' => 'shopware/core',
                    'version' => 'v6.1.1',
                    'source' => [
                        'reference' => $validSha,
                    ],
                    'dist' => [
                        'type' => 'zip',
                        'reference' => $validSha,
                    ],
                ],
                'v6.1.1',
            ],
            [
                true,
                [
                    'name' => 'shopware/core',
                    'version' => 'v6.2.0',
                    'source' => [
                        'reference' => $validSha,
                    ],
                    'dist' => [
                        'type' => 'zip',
                        'reference' => $validSha,
                    ],
                ],
                'v6.2.0',
            ],
            [
                false,
                [
                    'name' => 'shopware/core',
                    'version' => 'v6.2.0',
                    'source' => [
                        'reference' => $invalidSha,
                    ],
                    'dist' => [
                        'type' => 'path',
                        'reference' => $validSha,
                    ],
                ],
                'v6.2.0',
            ],
        ];
    }

    /**
     * @dataProvider validatePackageProvider
     */
    public function testValidatePackage(bool $expected, array $packageData, string $tag): void
    {
        $releaseService = new ReleaseService(
            [],
            $this->createMock(ReleasePrepareService::class),
            $this->createMock(TaggingService::class),
            $this->createMock(SbpClient::class),
            new NullOutput()
        );

        $actual = $releaseService->validatePackage($packageData, $tag);
        static::assertSame($expected, $actual);
    }

    public function testReleasePackage(): void
    {
        $releasePrepareService = $this->createMock(ReleasePrepareService::class);
        $taggingService = $this->createMock(TaggingService::class);
        $releaseService = new ReleaseService(
            [],
            $releasePrepareService,
            $taggingService,
            $this->createMock(SbpClient::class),
            new NullOutput()
        );
        $content = file_get_contents(__DIR__ . '/fixtures/shopware6.xml');
        /** @var Release $list */
        $list = simplexml_load_string($content, Release::class);

        $tag = 'v6.2.1';

        $releasePrepareService->method('getReleaseList')->willReturn($list);

        $before = $list->getRelease($tag);
        static::assertNotNull($before);

        $releasePrepareService->expects(static::once())->method('uploadArchives')->with($before);
        $releasePrepareService->expects(static::once())->method('storeReleaseList')->with($list);
        $releasePrepareService->expects(static::once())->method('registerUpdate')->with($tag, $before);

        static::assertFalse($before->isPublic());

        $releaseService->releasePackage($tag);

        static::assertTrue($before->isPublic());
    }

    public function testReleasePackageAlreadyReleased(): void
    {
        $releasePrepareService = $this->createMock(ReleasePrepareService::class);
        $taggingService = $this->createMock(TaggingService::class);
        $releaseService = new ReleaseService(
            [],
            $releasePrepareService,
            $taggingService,
            $this->createMock(SbpClient::class),
            new NullOutput()
        );
        $content = file_get_contents(__DIR__ . '/fixtures/shopware6.xml');
        /** @var Release $list */
        $list = simplexml_load_string($content, Release::class);

        $tag = 'v6.2.1';

        $releasePrepareService->method('getReleaseList')->willReturn($list);

        $before = $list->getRelease($tag);
        static::assertNotNull($before);
        $before->makePublic();

        $releasePrepareService->expects(static::never())->method('uploadArchives');
        $releasePrepareService->expects(static::never())->method('storeReleaseList');
        $releasePrepareService->expects(static::never())->method('registerUpdate');

        static::assertTrue($before->isPublic());

        $this->expectErrorMessage('Release ' . $tag . ' is already public');
        $releaseService->releasePackage($tag);
    }

    public function testReleasePackageUnknownTag(): void
    {
        $releasePrepareService = $this->createMock(ReleasePrepareService::class);
        $taggingService = $this->createMock(TaggingService::class);
        $releaseService = new ReleaseService(
            [],
            $releasePrepareService,
            $taggingService,
            $this->createMock(SbpClient::class),
            new NullOutput()
        );
        $releasePrepareService->method('getReleaseList')->willReturn(new Release('<Release/>'));

        $tag = 'v6.2.5';
        $this->expectException(\RuntimeException::class);
        $this->expectErrorMessage('Tag ' . $tag . ' not found');
        $releaseService->releasePackage($tag);
    }

    public function testReleaseTags(): void
    {
        if (!isset($_SERVER['SSH_PRIVATE_KEY_FILE'])) {
            static::markTestSkipped('Define env var SSH_PRIVATE_KEY_FILE');
        } else {
            Builder::loadSshKey($_SERVER['SSH_PRIVATE_KEY_FILE']);
        }

        $this->setUpRepos();

        $config = $this->getBaseConfig();
        $tag = $config['tag'] = 'v6.1.999';

        $this->makeFakeRelease($config, 'v6.1.998');

        $output = new ConsoleOutput();
        $taggingService = new TaggingService($config, $this->createMock(Client::class), $output, false);
        $releasePrepareService = $this->createMock(ReleasePrepareService::class);

        $releaseService = new ReleaseService(
            $config,
            $releasePrepareService,
            $taggingService,
            $this->createMock(SbpClient::class),
            new NullOutput()
        );

        $this->startGitServer();
        $releaseService->releaseTags($tag);

        static::assertFileExists($this->fakeProd . '/composer.json');
        $composerJson = json_decode(file_get_contents($this->fakeProd . '/composer.json'), true);

        static::assertSame($composerJson['minimum-stability'], 'stable');

        static::assertFileExists($config['projectRoot'] . '/public/recovery/install/data/version');
        static::assertSame($tag, file_get_contents($config['projectRoot'] . '/public/recovery/install/data/version'));

        $localProdSha = $this->execGit(['rev-parse', $tag], $this->fakeProd);
        $prodSha = $this->execGit(['rev-parse', $tag], $this->fakeRemoteRepos . '/prod');
        static::assertSame($localProdSha, $prodSha);

        $localProdSha = $this->execGit(['rev-parse', $tag], $this->fakeProd . '/repos/core');
        $prodSha = $this->execGit(['rev-parse', $tag], $this->fakeRemoteRepos . '/core');
        static::assertSame($localProdSha, $prodSha);

        $localProdSha = $this->execGit(['rev-parse', $tag], $this->fakeProd . '/repos/storefront');
        $prodSha = $this->execGit(['rev-parse', $tag], $this->fakeRemoteRepos . '/storefront');
        static::assertSame($localProdSha, $prodSha);

        // check that the tags were pushed to the remote repos and that the PLATFORM_COMMIT_SHA matches
        $repos = ['prod', 'core', 'storefront'];
        foreach ($repos as $repo) {
            $cmd
                = 'git -C '
                . escapeshellarg($this->fakeRemoteRepos . '/' . $repo)
                . ' archive ' . escapeshellarg($tag) . ' PLATFORM_COMMIT_SHA'
                . '| tar -xO PLATFORM_COMMIT_SHA';

            $platformSha = exec($cmd, $output, $returnCode);
            static::assertSame(0, $returnCode, 'Cmd: `' . $cmd . '` ' . print_r($output, true));
            static::assertSame(self::FAKE_PLATFORM_COMMIT_SHA, $platformSha, 'Cmd: `' . $cmd . '` ' . print_r($output, true));
        }

        $this->stopGitServer();
    }

    public function testReleaseSbpVersionNew(): void
    {
        $versions = [
            'parentParentVersion' => [
                'id' => 1,
                'name' => '6.3',
                'public' => false,
                'releaseDate' => null,
            ],
            'parentVersion' => [
                'id' => 2,
                'name' => '6.3.0',
                'parent' => 1,
                'public' => false,
                'releaseDate' => null,
            ],
        ];

        $sbpClient = $this->createMock(SbpClient::class);
        $this->mockSbpClientVersions($sbpClient, $versions);
        $releaseService = new ReleaseService(
            [],
            $this->createMock(ReleasePrepareService::class),
            $this->createMock(TaggingService::class),
            $sbpClient,
            new NullOutput()
        );

        $releaseDate = new \DateTime();
        $sbpClient->expects(static::once())
            ->method('upsertVersion')
            ->with('v6.3.0.0', null, $releaseDate->format('Y-m-d'), true);

        $releaseService->releaseSbpVersion('v6.3.0.0');
    }

    public function testReleaseSbpVersionExisting(): void
    {
        $versions = [
            'parentParentVersion' => [
                'id' => 1,
                'name' => '6.3',
                'public' => false,
                'releaseDate' => null,
            ],
            'parentVersion' => [
                'id' => 2,
                'name' => '6.3.0',
                'parent' => 1,
                'public' => false,
                'releaseDate' => null,
            ],
            'version' => [
                'id' => 23,
                'name' => '6.3.0.0',
                'parent' => 2,
                'public' => false,
                'releaseDate' => '2020-03-03',
            ],
        ];

        $sbpClient = $this->createMock(SbpClient::class);
        $this->mockSbpClientVersions($sbpClient, $versions);
        $releaseService = new ReleaseService(
            [],
            $this->createMock(ReleasePrepareService::class),
            $this->createMock(TaggingService::class),
            $sbpClient,
            new NullOutput()
        );

        $releaseDate = new \DateTime();
        $sbpClient->expects(static::once())
            ->method('upsertVersion')
            ->with('v6.3.0.0', null, $releaseDate->format('Y-m-d'), true);

        $releaseService->releaseSbpVersion('v6.3.0.0');
    }

    public function testTagPlatform(): void
    {
        $expectedRemoteUrl = 'http://example.com/remote';
        $taggingService = $this->createMock(TaggingService::class);
        $releaseService = new ReleaseService(
            [
                'platformRemoteUrl' => $expectedRemoteUrl,
            ],
            $this->createMock(ReleasePrepareService::class),
            $taggingService,
            $this->createMock(SbpClient::class),
            new NullOutput()
        );

        $expectedTag = 'v6.3.9.0';
        $expectedCommitSha = 'deadbeefdeadbeef';
        $expectedMessage = 'My test release';
        $taggingService->expects(static::once())
            ->method('fetchTagPush')
            ->with($expectedTag, $expectedCommitSha, 'release', null, $expectedRemoteUrl, $expectedMessage);

        $releaseService->tagAndPushPlatform(
            $expectedTag,
            $expectedCommitSha,
            $expectedMessage
        );
    }

    public function testTagAndPushDevelopment(): void
    {
        if (!isset($_SERVER['SSH_PRIVATE_KEY_FILE'])) {
            static::markTestSkipped('Define env var SSH_PRIVATE_KEY_FILE');
        } else {
            Builder::loadSshKey($_SERVER['SSH_PRIVATE_KEY_FILE']);
        }

        $output = new ConsoleOutput();
        $upstreamRepoPath = $this->getTmpDir();
        (new Builder())
            ->output($output)
            ->in($upstreamRepoPath)
            ->with('branch', 'trunk')
            ->run(
                '
                git clone --no-tags --depth=1 --branch={{ $branch }} git@gitlab.shopware.com:shopware/6/product/development.git .
                git remote remove origin'
            )->throw();

        $projectDir = $_SERVER['PROJECT_ROOT'];
        $config = [
            'projectRoot' => $projectDir,
            'developmentRemoteUrl' => 'file://' . $upstreamRepoPath,
            'composerUpdateWaitTime' => 1,
        ];
        $taggingService = new TaggingService($config, $this->createMock(Client::class), $output, false);
        $releaseService = new ReleaseService(
            $config,
            $this->createMock(ReleasePrepareService::class),
            $taggingService,
            $this->createMock(SbpClient::class),
            $output
        );

        $composerLockData = json_decode(file_get_contents($projectDir . '/composer.lock'), true);
        $packageData = $releaseService->getPackageFromComposerLock($composerLockData, 'shopware/core');

        $nonExistingTag = 'v6.9.9.99';
        $actualException = null;

        try {
            $releaseService->tagAndPushDevelopment(
                $nonExistingTag,
                'trunk'
            );
        } catch (\Throwable $e) {
            $actualException = $e;
        }
        static::assertNotNull($actualException);

        $currentPlatformTag = 'v' . ltrim($packageData['version'], 'v');
        $releaseService->tagAndPushDevelopment(
            $currentPlatformTag,
            'trunk'
        );

        (new Builder())
            ->in($upstreamRepoPath)
            ->with('tag', $currentPlatformTag)
            ->run('git checkout {{ $tag }}')
            ->throw()
            ->output();

        $upstreamComposerLockData = json_decode(file_get_contents($upstreamRepoPath . '/composer.lock'), true);
        $upstreamPlatformData = $releaseService->getPackageFromComposerLock($upstreamComposerLockData, 'shopware/platform');

        static::assertTrue($releaseService->validatePackage($upstreamPlatformData, $currentPlatformTag));
    }

    private function getTmpDir(): string
    {
        $tmpDir = sys_get_temp_dir() . '/TaggingServiceTest_' . bin2hex(random_bytes(16));
        mkdir($tmpDir);

        $this->tmpDirs[] = $tmpDir;

        return $tmpDir;
    }

    private function mockSbpClientVersions(MockObject $mock, array $versions): void
    {
        $indexedByName = array_column($versions, null, 'name');
        $indexedById = array_column($versions, null, 'id');

        $mock->method('getVersions')->willReturn(array_values($versions));

        /** @var InvocationStubber $getVersionByName */
        $getVersionByName = $mock->method('getVersionByName');
        $getVersionByName->willReturnCallback(function (string $name) use ($indexedByName) {
            return $indexedByName[$name] ?? null;
        });

        /** @var InvocationStubber $getVersion */
        $getVersion = $mock->method('getVersion');
        $getVersion->willReturnCallback(function (int $id) use ($indexedById) {
            return $indexedById[$id] ?? null;
        });
    }

    private function startGitServer(): void
    {
        static::assertIsString($this->fakeRemoteRepos);
        $path = escapeshellarg($this->fakeRemoteRepos);
        $daemonCmd = 'git daemon'
            . ' --base-path=' . $path
            . ' --export-all --reuseaddr --enable=receive-pack '
            . $path . '>/dev/null 2>/dev/null &'
            . \PHP_EOL . ' echo $!';

        echo $daemonCmd;

        $this->daemonPid = (int) exec($daemonCmd, $output, $returnCode);
    }

    private function stopGitServer(): void
    {
        if ($this->daemonPid) {
            posix_kill($this->daemonPid, 9);

            $pgrepPid = (int) exec('pgrep git-daemon');
            if ($pgrepPid) {
                posix_kill($pgrepPid, 9);
            }
            $this->daemonPid = null;
        }
    }

    private function makeFakeRelease(array $config, string $tag): void
    {
        foreach ($config['repos'] as $repoData) {
            file_put_contents($repoData['path'] . '/PLATFORM_COMMIT_SHA', self::FAKE_PLATFORM_COMMIT_SHA);

            $this->execGit(['remote', 'remove', 'origin'], $repoData['path']);
            $this->execGit(['add', 'PLATFORM_COMMIT_SHA'], $repoData['path']);
            $this->execGit(['commit', '--message' => 'test commit', '--no-gpg-sign'], $repoData['path']);
            $this->execGit(['tag', $tag, '-a', '--message' => 'test commit ' . $tag, '--no-sign'], $repoData['path']);
            $this->execGit(['checkout', $tag], $repoData['path']);
        }

        static::assertIsString($this->fakeProd);
        $base = $this->fakeProd;
        exec('cp ' . $base . '/composer.dev.json ' . $base . '/composer.json');
        exec('composer update --working-dir=' . escapeshellarg($this->fakeProd));
        exec('cp ' . $base . '/composer.stable.json ' . $base . '/composer.json');
    }

    private function getBaseConfig(): array
    {
        $config = [
            'projectId' => 184,
            'gitlabBaseUri' => 'https://gitlab.shopware.com/api/v4',
            'gitlabApiToken' => 'token',
        ];

        $config['stability'] = 'stable';
        $config['projectRoot'] = $this->fakeProd;
        $config['gitlabRemoteUrl'] = 'git://127.0.0.1/prod';
        $config['manyReposBaseUrl'] = 'git://127.0.0.1';
        $config['targetBranch'] = '6.1';
        $config['tag'] = 'v6.1.2';
        $config['stability'] = VersionParser::parseStability($config['tag']);
        $config['repos'] = [
            'core' => [
                'path' => $config['projectRoot'] . '/repos/core',
                'remoteUrl' => $config['manyReposBaseUrl'] . '/core',
            ],
            'storefront' => [
                'path' => $config['projectRoot'] . '/repos/storefront',
                'remoteUrl' => $config['manyReposBaseUrl'] . '/storefront',
            ],
        ];
        $config['composerUpdateWaitTime'] = 0;

        return $config;
    }

    private function setUpRepos(): void
    {
        $tmpBasePath = __DIR__ . '/fixtures/tmp';

        $this->fakeProd = $tmpBasePath . '/fake-prod';
        $this->fakeRemoteRepos = $tmpBasePath . '/fake-remote-repos';

        (new Builder())
            ->in($tmpBasePath)
            ->with('fakeProd', $this->fakeProd)
            ->run('rm -Rf {{ $fakeProd }}');
        (new Builder())
            ->in($tmpBasePath)
            ->with('fakeRemoteRepos', $this->fakeRemoteRepos)
            ->run('rm -Rf {{ $fakeRemoteRepos }}/*');

        (new Builder())
            ->in(__DIR__)
            ->with('template', 'fixtures/fake-prod-template')
            ->with('prod', $this->fakeRemoteRepos . '/prod')
            ->run('cp -a {{ $template }} {{ $prod }}');

        (new Builder())
            ->in($this->fakeRemoteRepos . '/prod')
            ->run(
                '
                git init .
                git add .
                git commit --message "test commit" --no-gpg-sign
                '
            );

        (new Builder())
            ->in($tmpBasePath)
            ->with('prodRemote', $this->fakeRemoteRepos . '/prod')
            ->with('prod', $this->fakeProd)
            ->run('git clone {{ $prodRemote }} {{ $prod }}')
            ->throw();

        exec('mkdir -p ' . escapeshellarg($this->fakeProd . '/repos'));

        $baseUrl = 'git@gitlab.shopware.com:shopware/6/product/many-repositories';

        $repos = ['core', 'storefront'];
        foreach ($repos as $repo) {
            (new Builder())
                ->output(new ConsoleOutput())
                ->with('remoteUrl', $baseUrl . '/' . $repo)
                ->with('repo', $this->fakeRemoteRepos . '/' . $repo)
                ->timeout(5)
                ->run('git clone --bare --branch=v6.1.0 {{ $remoteUrl }} {{ $repo }}')
                ->throw();

            touch($this->fakeRemoteRepos . '/' . $repo . '/git-daemon-export-ok');

            (new Builder())
                ->output(new ConsoleOutput())
                ->with('remoteUrl', $this->fakeRemoteRepos . '/' . $repo)
                ->with('repo', $this->fakeProd . '/repos/' . $repo)
                ->timeout(5)
                ->run('git clone {{ $remoteUrl }} {{ $repo }}');
        }
    }

    private function execGit(array $args, ?string $repository = null): string
    {
        $arguments = $args;
        if ($repository) {
            $arguments = array_merge(['-C' => $repository], $args);
        }

        $cmd = 'git ';
        foreach ($arguments as $key => $value) {
            if (!\is_int($key)) {
                $cmd .= $key;
                $cmd .= strpos($key, '--') !== 0 ? ' ' : '=';
            }

            $cmd .= escapeshellarg($value) . ' ';
        }

        $result = exec($cmd, $output, $retCode);
        if ($retCode !== 0) {
            throw new \RuntimeException(
                sprintf('Error code: %d, Failed to execute: %s, output: %s', $retCode, $cmd, implode(\PHP_EOL, $output))
            );
        }

        return $result;
    }
}
