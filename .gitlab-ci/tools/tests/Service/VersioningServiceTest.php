<?php declare(strict_types=1);

namespace Shopware\CI\Test\Service;

use Composer\Semver\VersionParser;
use PHPUnit\Framework\TestCase;
use Shopware\CI\Service\Exception\InvalidTagException;
use Shopware\CI\Service\VersioningService;

class VersioningServiceTest extends TestCase
{
    private ?string $localTestRepoPath = null;

    private ?string $remoteTestRepoPath = null;

    public function tearDown(): void
    {
        if ($this->localTestRepoPath !== null) {
            system('rm -Rf ' . escapeshellarg($this->localTestRepoPath));
        }
        if ($this->remoteTestRepoPath !== null) {
            system('rm -Rf ' . escapeshellarg($this->remoteTestRepoPath));
        }
    }

    public function invalidTagsProvider(): array
    {
        return [
            ['6.1'],
            ['5.1.0'],
            ['7.1.0'],
            ['6.4.0-ea'],
            ['test'],
            [''],
        ];
    }

    /**
     * @dataProvider invalidTagsProvider
     */
    public function testParseInvalidTag(string $tag): void
    {
        $this->expectException(InvalidTagException::class);
        $this->expectExceptionMessage('Invalid tag: ' . $tag);
        VersioningService::parseTag($tag);
    }

    public function testInvalidConstraintThrows(): void
    {
        $versioningService = new VersioningService(new VersionParser(), 'stable');
        $this->expectExceptionMessage('constrain should be a range like >= 6.1.0 && < 6.2.0');
        $versioningService->getNextTag('<6.3');
    }

    public function nextTagNoPriorVersionProvider(): array
    {
        return [
            ['v6.1.0', '6.1.*', 'stable'],
            ['v6.1.0-RC1', '6.1.*', 'rc'],
            ['v6.1.0-beta1', '6.1.*', 'beta'],
            ['v6.1.0-alpha1', '6.1.*', 'alpha'],

            ['v6.2.0', '~6.2.0', 'stable'],
            ['v6.2.0-RC1', '~6.2.0', 'rc'],
            ['v6.2.0-beta1', '~6.2.0', 'beta'],
            ['v6.2.0-alpha1', '~6.2.0', 'alpha'],

            ['v6.2.0', '6.2.x', 'stable'],
            ['v6.2.0-RC1', '6.2.x', 'rc'],
            ['v6.2.0-beta1', '6.2.x', 'beta'],
            ['v6.2.0-alpha1', '6.2.x', 'alpha'],

            ['v6.3.0.0', '6.3.0.x', 'stable'],
            ['v6.3.0.0-RC1', '6.3.0.x', 'rc'],
            ['v6.3.0.0-beta1', '6.3.0.x', 'beta'],
            ['v6.3.0.0-alpha1', '6.3.0.x', 'alpha'],
        ];
    }

    /**
     * @dataProvider nextTagNoPriorVersionProvider
     */
    public function testNextTagNoPriorVersion(string $expected, string $constraint, string $stability): void
    {
        $versioningService = new VersioningService(new VersionParser(), $stability);

        static::assertSame($expected, $versioningService->getNextTag($constraint));
    }

    public function nextTagProvider(): array
    {
        return [
            ['v6.1.1', '6.1.*', 'v6.1.0', 'stable'],
            ['v6.1.1', '6.1.*', 'v6.1.0', 'rc'],
            ['v6.1.1', '6.1.*', 'v6.1.0', 'beta'],
            ['v6.1.1', '6.1.*', 'v6.1.0', 'alpha'],

            ['v6.1.0-RC2', '6.1.*', 'v6.1.0-rc1', 'rc'],
            ['v6.1.0-RC2', '6.1.*', 'v6.1.0-rc1', 'beta'],
            ['v6.1.0-RC2', '6.1.*', 'v6.1.0-rc1', 'alpha'],

            ['v6.1.0-beta2', '6.1.*', 'v6.1.0-beta1', 'beta'],
            ['v6.1.0-beta2', '6.1.*', 'v6.1.0-beta1', 'alpha'],

            ['v6.1.0-alpha2', '6.1.*', 'v6.1.0-alpha1', 'alpha'],

            ['v6.1.0', '6.1.*', 'v6.1.0-rc1', 'stable'],
            ['v6.1.0', '6.1.*', 'v6.1.0-beta1', 'stable'],
            ['v6.1.0', '6.1.*', 'v6.1.0-alpha1', 'stable'],

            ['v6.1.0-RC1', '6.1.*', 'v6.1.0-beta1', 'rc'],
            ['v6.1.0-beta1', '6.1.*', 'v6.1.0-alpha1', 'beta'],

            ['v6.3.0.1', '6.3.0.*', 'v6.3.0.0', 'stable'],
            ['v6.3.0.1', '6.3.0.*', 'v6.3.0.0', 'rc'],
            ['v6.3.0.1', '6.3.0.*', 'v6.3.0.0', 'beta'],
            ['v6.3.0.1', '6.3.0.*', 'v6.3.0.0', 'alpha'],

            ['v6.3.0.0-RC2', '6.3.0.*', 'v6.3.0.0-rc1', 'rc'],
            ['v6.3.0.0-RC2', '6.3.0.*', 'v6.3.0.0-rc1', 'beta'],
            ['v6.3.0.0-RC2', '6.3.0.*', 'v6.3.0.0-rc1', 'alpha'],

            ['v6.3.0.0-beta2', '6.3.0.*', 'v6.3.0.0-beta1', 'beta'],
            ['v6.3.0.0-beta2', '6.3.0.*', 'v6.3.0.0-beta1', 'alpha'],

            ['v6.3.0.0-alpha2', '6.3.0.*', 'v6.3.0.0-alpha1', 'alpha'],

            ['v6.3.0.1', '~6.3.0', 'v6.3.0.0', 'stable'],
            ['v6.3.1.0', '~6.3.0', 'v6.3.0.0', 'stable', true],
            ['v6.3.3.0', '~6.3.0', 'v6.3.2.3', 'stable', true],
        ];
    }

    /**
     * @dataProvider nextTagProvider
     */
    public function testNextTag(string $expected, string $constraint, string $lastVersion, string $stability, bool $doMinor = false): void
    {
        $versioningService = new VersioningService(new VersionParser(), $stability);

        static::assertSame($expected, $versioningService->getNextTag($constraint, $lastVersion, $doMinor));
    }

    public function getMatchingVersionsProvider(): array
    {
        return [
            [
                [],
                ['v6.1.0-rc', 'v6.1.0-beta1', 'v6.1.0-alpha2', 'v6.1.0-dev'],
                '~6.1.0',
                'stable',
            ],
            [
                ['v6.1.0-rc'],
                ['v6.1.0-rc', 'v6.1.0-beta1', 'v6.1.0-alpha2', 'v6.1.0-dev'],
                '~6.1.0',
                'rc',
            ],
            [
                ['v6.1.0-beta1', 'v6.1.0-rc'],
                ['v6.1.0-rc', 'v6.1.0-beta1', 'v6.1.0-alpha2', 'v6.1.0-dev'],
                '~6.1.0',
                'beta',
            ],
            [
                ['v6.1.0-alpha2', 'v6.1.0-beta1', 'v6.1.0-rc'],
                ['v6.1.0-rc', 'v6.1.0-beta1', 'v6.1.0-alpha2', 'v6.1.0-dev'],
                '~6.1.0',
                'alpha',
            ],
            [
                ['v6.1.0', 'v6.1.1'],
                ['v6.1.0', 'v6.1.1', 'v6.1.0-rc1', 'v6.1.0-rc2', 'v6.1.0-beta1', 'v6.1.0-alpha2', 'v6.1.0-dev'],
                '~6.1.0',
                'stable',
            ],
            [
                ['v6.1.0-rc1', 'v6.1.0-rc2', 'v6.1.0', 'v6.1.1'],
                ['v6.1.0', 'v6.1.1', 'v6.1.0-rc1', 'v6.1.0-rc2', 'v6.1.0-beta1', 'v6.1.0-alpha2', 'v6.1.0-dev'],
                '~6.1.0',
                'rc',
            ],
        ];
    }

    /**
     * @dataProvider getMatchingVersionsProvider
     */
    public function testGetMatchingVersions(array $expected, array $versions, string $constraint, string $stability): void
    {
        $versioningService = new VersioningService(new VersionParser(), $stability);
        $randomVersions = $this->mt_shuffle_array($versions);

        static::assertSame(
            $expected,
            $versioningService->getMatchingVersions($versions, $constraint),
            print_r(['versions' => $randomVersions], true)
        );
    }

    public function getMinorBranchProvider(): array
    {
        return [
            ['6.2', '6.2.0'],
            ['6.2', '6.2.1'],
            ['6.2', '6.2.0-rc1'],
            ['6.2', '6.2.0-alpha2'],

            ['6.3.0', '6.3.0.0'],
            ['6.3.0', '6.3.0.0-rc1'],
            ['6.3.0', '6.3.0.0-beta2'],
            ['6.3.0', '6.3.0.3'],

            ['6.3.1', '6.3.1.3'],
        ];
    }

    /**
     * @dataProvider getMinorBranchProvider
     */
    public function testGetMinorBranch(string $expectedBranch, string $tag): void
    {
        static::assertSame($expectedBranch, VersioningService::getMinorBranch($tag));
    }

    public function getReleaseTypeProvider(): array
    {
        return [
            ['Major', '6.2.0'],
            ['Minor', '6.2.1'],
            ['Major', '6.2.0-rc1'],
            ['Major', '6.2.0-alpha2'],

            ['Major', '6.3.0.0'],
            ['Major', '6.3.0.0-rc1'],
            ['Minor', '6.3.1.0'],
            ['Minor', '6.3.1.0'],

            ['Patch', '6.3.0.3'],
            ['Patch', '6.3.1.3'],
        ];
    }

    /**
     * @dataProvider getReleaseTypeProvider
     */
    public function testGetReleaseType(string $expectedType, string $tag): void
    {
        static::assertSame($expectedType, VersioningService::getReleaseType($tag), 'Tag: ' . $tag);
    }

    public function getUpdateChannelProvider(): array
    {
        return [
            [100, '6.2.0'],
            [100, '6.2.1'],
            [80, '6.2.0-rc1'],
            [80, '6.2.0-RC2'],
            [60, '6.2.0-beta3'],
            [40, '6.2.0-alpha2'],
            [20, '6.2.0-dev1'],

            [100, '6.3.0.0'],
            [100, '6.3.1.1'],
            [80, '6.3.0.0-rc1'],
            [80, '6.3.0.0-RC1'],
            [60, '6.3.0.0-beta3'],
            [40, '6.3.0.0-alpha2'],
            [20, '6.3.0.0-dev1'],
        ];
    }

    /**
     * @dataProvider getUpdateChannelProvider
     */
    public function testGetUpdateChannel(int $expectedChannel, string $tag): void
    {
        static::assertSame($expectedChannel, VersioningService::getUpdateChannel($tag), 'Tag: ' . $tag);
    }

    public function testNoMatchingBranch(): void
    {
        ['local' => $local] = $this->createTestRepos();

        $versioningService = new VersioningService(new VersionParser(), 'stable');

        $this->expectExceptionMessage('No matching branch found');
        $versioningService->getBestMatchingBranch('6.2.0', $local);
    }

    public function bestMatchingBranchDataProvider(): array
    {
        return [
            [
                'expectedBranch' => '6.2',
                'branches' => ['6.2'],
                'tag' => '6.2.4',
            ],
            [
                'expectedBranch' => '6.2',
                'branches' => ['6.2'],
                'tag' => '6.2.0-rc2',
            ],
            [
                'expectedBranch' => '6.2.4',
                'branches' => ['6.2', '6.2.4'],
                'tag' => '6.2.4',
            ],

            [
                'expectedBranch' => '6.3',
                'branches' => ['6.3'],
                'tag' => '6.3.0.0',
            ],
            [
                'expectedBranch' => '6.3.0',
                'branches' => ['6.3', '6.3.0'],
                'tag' => '6.3.0.0',
            ],
            [
                'expectedBranch' => '6.3.0.0',
                'branches' => ['6.3', '6.3.0', '6.3.0.0'],
                'tag' => '6.3.0.0',
            ],

            [
                'expectedBranch' => '6.3',
                'branches' => ['6.3'],
                'tag' => '6.3.2.0',
            ],
            [
                'expectedBranch' => '6.3',
                'branches' => ['6.3', '6.3.0'],
                'tag' => '6.3.2.0',
            ],
            [
                'expectedBranch' => '6.3',
                'branches' => ['6.3', '6.3.0', '6.3.0.0'],
                'tag' => '6.3.2.0',
            ],
            [
                'expectedBranch' => 'trunk',
                'branches' => ['trunk', '6.3.1', '6.3.3', '6.3.2.1'],
                'tag' => '6.3.2.0',
            ],
        ];
    }

    /**
     * @dataProvider bestMatchingBranchDataProvider
     */
    public function testBestMatchingBranch(string $expectedBranch, array $branches, string $tag): void
    {
        ['local' => $local, 'remote' => $remote] = $this->createTestRepos();

        foreach ($branches as $branch) {
            system('git -C ' . escapeshellarg($remote) . ' branch ' . escapeshellarg($branch));
        }

        $versioningService = new VersioningService(new VersionParser(), 'stable');
        static::assertSame(
            $expectedBranch,
            $versioningService->getBestMatchingBranch($tag, $local),
            'Expected ' . $expectedBranch . ' with branches ' . print_r($branches, true) . ' and tag ' . $tag
        );
    }

    /**
     * @dataProvider isSecurityUpdateDataProvider
     */
    public function testIsSecurityUpdate(string $tag, bool $expected): void
    {
        static::assertSame(VersioningService::isSecurityUpdate($tag), $expected);
    }

    public function isSecurityUpdateDataProvider(): \Generator
    {
        yield 'no detection for old style' => ['6.2.1',  false];
        yield 'minor' => ['6.4.1.0', false];
        yield 'major' => ['6.5.0.0', false];
        yield 'patch' => ['6.4.5.1', true];
    }

    private function createTestRepos(): array
    {
        $local = $this->localTestRepoPath = sys_get_temp_dir() . '/repo_' . bin2hex(random_bytes(16));
        mkdir($this->localTestRepoPath);
        $remote = $this->remoteTestRepoPath = sys_get_temp_dir() . '/repo_' . bin2hex(random_bytes(16));
        mkdir($this->remoteTestRepoPath);

        $shellCode = <<<CODE

    cd $remote
    git init .
    touch test
    git add test
    git commit -m 'Test commit'
    cd $local
    git clone $remote .
CODE;

        system($shellCode, $retCode);

        return [
            'remote' => $this->remoteTestRepoPath,
            'local' => $this->localTestRepoPath,
        ];
    }

    private function mt_shuffle_array(array $array): array
    {
        $shuffled_array = [];
        $arr_length = \count($array);

        if ($arr_length < 2) {
            return $array;
        }

        while ($arr_length) {
            --$arr_length;
            $rand_key = array_keys($array)[mt_rand(0, $arr_length)];

            $shuffled_array[$rand_key] = $array[$rand_key];
            unset($array[$rand_key]);
        }

        return $shuffled_array;
    }
}
