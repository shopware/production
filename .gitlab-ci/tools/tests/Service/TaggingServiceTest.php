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
        $taggingService = new TaggingService($config, $gitlabClient);

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

}
