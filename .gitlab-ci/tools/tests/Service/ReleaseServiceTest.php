<?php


namespace Shopware\CI\Test\Service;


use Composer\Semver\VersionParser;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use PHPUnit\Framework\TestCase;
use Shopware\CI\Service\ReleaseService;

class ReleaseServiceTest extends TestCase
{
    /**
     * @var string
     */
    private $daemonPid;

    /**
     * @var string
     */
    private $fakeProd;

    /**
     * @var string
     */
    private $fakeRemoteRepos;

    public const FAKE_PLATFORM_COMMIT_SHA = '01c209a305adaabaf894e6929290c69cdeadbeef';

    public function validatePackageProvider(): array
    {
        $validSha =   '36f9ca136c87f4d3f860cd6b2516f02f2516f02a';
        $invalidSha = '36f9ca136c87f4d3f860cd6b2516f02bdeadbeef';
        return [
            [
                false,
                [
                    'name' => 'shopware/core',
                    'version' => 'v6.1.0',
                    'source' => [
                        'reference' => $validSha
                    ]
                ],
                'v6.1.1',
                [
                    'path' => 'repos/core',
                    'reference' => $validSha
                ]
            ],
            [
                true,
                [
                    'name' => 'shopware/core',
                    'version' => 'v6.1.1',
                    'source' => [
                        'reference' => $validSha
                    ]
                ],
                'v6.1.1',
                [
                    'path' => 'repos/core',
                    'reference' => $validSha
                ]
            ],
            [
                true,
                [
                    'name' => 'shopware/core',
                    'version' => 'v6.2.0',
                    'source' => [
                        'reference' => $validSha
                    ]
                ],
                'v6.2.0',
                [
                    'path' => 'repos/core',
                    'reference' => $validSha
                ]
            ],
            [
                true,
                [
                    'name' => 'shopware/core',
                    'version' => 'v6.2.0',
                    'source' => [
                        'reference' => $invalidSha
                    ]
                ],
                'v6.2.0',
                [
                    'path' => 'repos/core',
                    'reference' => $validSha
                ],
                \LogicException::class,
                'commit sha of repos/core 36f9ca136c87f4d3f860cd6b2516f02f2516f02a should be the sames as shopware/core.source.reference 36f9ca136c87f4d3f860cd6b2516f02bdeadbeef'
            ],
            [
                true,
                [
                    'name' => 'shopware/core',
                    'version' => 'v6.2.0',
                    'source' => [
                        'reference' => $invalidSha
                    ],
                    'dist' => [
                        'type' => 'path',
                        'reference' => $validSha
                    ]
                ],
                'v6.2.0',
                [
                    'path' => 'repos/core',
                    'reference' => $validSha
                ],
                \LogicException::class,
                'dist type path should not be possible for shopware/core'
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
                        'type' => 'git',
                        'reference' => $invalidSha
                    ]
                ],
                'v6.2.0',
                [
                    'path' => 'repos/core',
                    'reference' => $validSha
                ],
                \LogicException::class,
                'commit sha of repos/core 36f9ca136c87f4d3f860cd6b2516f02f2516f02a should be the sames as shopware/core.dist.reference 36f9ca136c87f4d3f860cd6b2516f02bdeadbeef'
            ]
        ];
    }

    /**
     * @dataProvider validatePackageProvider
     */
    public function testValidatePackage(bool $expected, array $packageData, string $tag, array $repoData, string $expectedException = null, string $msg = null): void
    {
        $releaseService = new ReleaseService([], $this->createMock(ClientInterface::class));
        if ($expectedException) {
            $this->expectException($expectedException);
            if ($msg) {
                $this->expectExceptionMessage($msg);
            }
        }
        $actual = $releaseService->validatePackage($packageData, $tag, $repoData);
        static::assertSame($expected, $actual);
    }

    public function testRelease(): void
    {
        $client = $this->createMock(Client::class);
        $client->expects($this->once())->method('request');

        $this->setUpRepos();

        $config = $this->getBaseConfig();
        $tag = $config['tag'] = 'v6.1.2';

        $this->makeFakeRelease($config, 'v6.1.1');

        $releaseService = new ReleaseService($config, $client);

        $this->startGitServer(60);
        $releaseService->release();

        static::assertFileExists($this->fakeProd . '/composer.json');
        $composerJson = json_decode(file_get_contents($this->fakeProd . '/composer.json'), true);

        static::assertSame($composerJson['minimum-stability'], 'stable');


        $localProdSha = $this->execGit(['rev-parse', $tag], $this->fakeProd);
        $prodSha = $this->execGit(['rev-parse', $tag], $this->fakeRemoteRepos . '/prod');
        static::assertSame($localProdSha, $prodSha);

        $localProdSha = $this->execGit(['rev-parse', $tag], $this->fakeProd. '/repos/core');
        $prodSha = $this->execGit(['rev-parse', $tag], $this->fakeRemoteRepos . '/core');
        static::assertSame($localProdSha, $prodSha);

        $localProdSha = $this->execGit(['rev-parse', $tag], $this->fakeProd. '/repos/storefront');
        $prodSha = $this->execGit(['rev-parse', $tag], $this->fakeRemoteRepos . '/storefront');
        static::assertSame($localProdSha, $prodSha);

        // check that the tags were pushed to the remote repos and that the PLATFORM_COMMIT_SHA matches
        $repos = ['prod', 'core', 'storefront'];
        foreach ($repos as $repo) {
            $cmd =
                'git -C '
                . escapeshellarg($this->fakeRemoteRepos . '/' . $repo)
                . ' archive ' . escapeshellarg($tag) . ' PLATFORM_COMMIT_SHA'
                . '| tar -xO PLATFORM_COMMIT_SHA';

            $platformSha = exec($cmd, $output, $returnCode);
            static::assertSame(0, $returnCode, 'Cmd: `' . $cmd . '` ' . print_r($output, true));
            static::assertSame(self::FAKE_PLATFORM_COMMIT_SHA, $platformSha, 'Cmd: `' . $cmd . '` ' . print_r($output, true));
        }

        $this->stopGitServer();
    }

    public function tearDown(): void
    {
        $this->stopGitServer();

        exec('rm -Rf ' . escapeshellarg($this->fakeProd));
        exec('rm -Rf ' . escapeshellarg($this->fakeRemoteRepos) . '/*');
    }

    private function startGitServer(): void
    {
        $path = escapeshellarg($this->fakeRemoteRepos);
        $daemonCmd = 'git daemon'
            . ' --base-path=' . $path
            . ' --export-all --reuseaddr --enable=receive-pack '
            . $path . '>/dev/null 2>/dev/null &'
            . PHP_EOL . ' echo $!';

        echo $daemonCmd;

        $this->daemonPid = exec($daemonCmd, $output, $returnCode);
    }

    private function stopGitServer(): void
    {
        if ($this->daemonPid) {
            posix_kill($this->daemonPid, 9);

            $pgrepPid = exec('pgrep git-daemon');
            if ($pgrepPid) {
                posix_kill($pgrepPid, 9);
            }
            $this->daemonPid = null;
        }
    }

    private function makeFakeRelease($config, string $tag): void
    {
        foreach($config['repos'] as $repo => $repoData) {

            file_put_contents($repoData['path'] . '/PLATFORM_COMMIT_SHA', self::FAKE_PLATFORM_COMMIT_SHA);

            $this->execGit(['remote', 'remove', 'origin'], $repoData['path']);
            $this->execGit(['add', 'PLATFORM_COMMIT_SHA'], $repoData['path']);
            $this->execGit(['commit', '--message' => 'test commit'], $repoData['path']);
            $this->execGit(['tag', $tag, '-a', '--message' => 'test commit ' . $tag], $repoData['path']);
            $this->execGit(['checkout', $tag], $repoData['path']);
        }

        $base = $this->fakeProd;
        exec('cp ' . $base . '/composer.dev.json ' . $base . '/composer.json');
        exec('composer update shopware/* --working-dir=' . escapeshellarg($this->fakeProd));
        exec('cp ' . $base . '/composer.stable.json ' . $base . '/composer.json');
    }

    private function getBaseConfig(): array
    {
        $config = [
            'projectId' => 184,
            'gitlabBaseUri' => 'https://gitlab.shopware.com/api/v4',
            'gitlabApiToken' => 'token'
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
                'remoteUrl' => $config['manyReposBaseUrl'] . '/core'
            ],
            'storefront' => [
                'path' => $config['projectRoot'] . '/repos/storefront',
                'remoteUrl' => $config['manyReposBaseUrl'] . '/storefront'
            ]
        ];

        return $config;
    }

    private function setUpRepos(): void
    {
        $tmpBasePath = __DIR__ . '/fixtures/tmp';

        $this->fakeProd = $tmpBasePath . '/fake-prod';
        $this->fakeRemoteRepos = $tmpBasePath . '/fake-remote-repos';

        exec('rm -Rf ' . escapeshellarg($this->fakeProd));
        exec('rm -Rf ' . escapeshellarg($this->fakeRemoteRepos) . '/*');
        exec('cp -a '
            . escapeshellarg(__DIR__ . '/fixtures/fake-prod-template') . ' '
            . escapeshellarg($this->fakeRemoteRepos . '/prod')
        );

        $this->execGit(['init'], $this->fakeRemoteRepos . '/prod');
        $this->execGit(['add', '.'], $this->fakeRemoteRepos . '/prod');
        $this->execGit(['commit', '--message' => 'initial commit'], $this->fakeRemoteRepos . '/prod');
        $this->execGit(['clone', $this->fakeRemoteRepos . '/prod', $this->fakeProd]);

        exec('mkdir ' . escapeshellarg($this->fakeProd . '/repos'));

        $baseUrl = 'git@gitlab.shopware.com:shopware/6/product/many-repositories';

        $repos = ['core', 'storefront'];
        foreach ($repos as $repo) {
            $this->execGit(['clone',
                '--branch' => 'v6.1.0', '--bare',
                $baseUrl . '/' . $repo,
                $this->fakeRemoteRepos . '/' . $repo
            ]);
            touch($this->fakeRemoteRepos . '/' . $repo . '/git-daemon-export-ok');

            $this->execGit(['clone',
                $this->fakeRemoteRepos . '/' . $repo,
                $this->fakeProd . '/repos/' . $repo
            ]);
        }
    }

    private function execGit(array $args, string $repository = null): string
    {
        $arguments = $args;
        if ($repository) {
            $arguments = array_merge(['-C' => $repository], $args);
        }

        $cmd = 'git ';
        foreach($arguments as $key => $value) {
            if(!is_int($key)) {
                $cmd .= $key;
                $cmd .= strpos($key, '--') !== 0 ? ' ' : '=';
            }

            $cmd .= escapeshellarg($value) . ' ';
        }

        $result = exec($cmd, $output, $retCode);
        if ($retCode !== 0) {
            new \RuntimeException(sprintf('Err code: %d, Failed to execute: %s, output: %s', $retCode, $cmd, implode(PHP_EOL, $output)));
        }

        return $result;
    }
}