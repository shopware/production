<?php

namespace Shopware\CI\Test\Service;

use Composer\Semver\VersionParser;
use GuzzleHttp\Client;
use PHPUnit\Framework\TestCase;
use Shopware\CI\Service\TaggingService;

class TaggingServiceTest extends TestCase
{

    public function testTagAndPushPlatform(): void
    {
        $config['stability'] = 'alpha';
        $gitlabClient = $this->createMock(Client::class);
        $taggingService = new TaggingService(new VersionParser(), $config, $gitlabClient);

        $remotePath = sys_get_temp_dir() . '/platform_' . bin2hex(random_bytes(16));
        mkdir($remotePath);

        $remotePath = escapeshellarg($remotePath);

        $commitMsg = 'Test commit';
        $shellCode = <<<CODE
    cd $remotePath
    git init .
    touch test
    git add test
    git commit -m "Test commit"
    touch not_included_in_tag
    git add not_included_in_tag
    git commit -m "Not included"
CODE;

        system($shellCode, $retCode);

        $commitSha = exec('git -C ' . $remotePath . ' rev-parse ' . escapeshellarg('@^1'));

        $taggingService->tagAndPushPlatform(
            'v6.3.0.1-dev',
            $commitSha,
            $remotePath
        );

        $tagSha = exec('git -C '  . $remotePath . ' rev-parse ' . escapeshellarg('v6.3.0.1-dev^{}'));

        static::assertSame($commitSha, $tagSha, 'Test repo: ' . $remotePath);

        system('rm -Rf ' . $remotePath);
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
        $config['stability'] = $stability;
        $gitlabClient = $this->createMock(Client::class);
        $taggingService = new TaggingService(new VersionParser(), $config, $gitlabClient);

        static::assertSame($expected, $taggingService->getNextTag($constraint));
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
        ];
    }

    /**
     * @dataProvider nextTagProvider
     */
    public function testNextTag(string $expected, string $constraint, string $lastVersion, string $stability): void
    {
        $config['stability'] = $stability;
        $gitlabClient = $this->createMock(Client::class);
        $taggingService = new TaggingService(new VersionParser(), $config, $gitlabClient);

        static::assertSame($expected, $taggingService->getNextTag($constraint, $lastVersion));
    }


    public function getMatchingVersionsProvider()
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
                ['v6.1.0-rc1','v6.1.0-rc2','v6.1.0','v6.1.1'],
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
        $config['stability'] = $stability;
        $gitlabClient = $this->createMock(Client::class);
        $taggingService = new TaggingService(new VersionParser(), $config, $gitlabClient);
        $randomVersions = $this->mt_shuffle_array($versions);

        static::assertSame(
            $expected,
            $taggingService->getMatchingVersions($versions, $constraint),
            print_r(['versions' => $randomVersions], true)
        );
    }

    private function mt_shuffle_array($array) {
        $shuffled_array = [];
        $arr_length = count($array);

        if($arr_length < 2) {
            return $array;
        }

        while($arr_length) {
            --$arr_length;
            $rand_key = array_keys($array)[mt_rand(0, $arr_length)];

            $shuffled_array[$rand_key] = $array[$rand_key];
            unset($array[$rand_key]);
        }

        return $shuffled_array;
    }
}
