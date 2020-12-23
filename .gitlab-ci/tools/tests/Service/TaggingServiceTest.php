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
            static::markTestSkipped('Define env var SSH_PRIVATE_KEY_FILE');
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

        (new Builder())
            ->in($upstreamRemotePath)
            ->run(
                <<<CODE
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
CODE
            )->throw();

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

    private function getTmpDir(): string
    {
        $tmpDir = sys_get_temp_dir() . '/TaggingServiceTest_' . bin2hex(random_bytes(16));
        mkdir($tmpDir);

        $this->tmpDirs[] = $tmpDir;

        return $tmpDir;
    }
}
