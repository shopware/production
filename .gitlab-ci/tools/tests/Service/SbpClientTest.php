<?php declare(strict_types=1);

namespace Shopware\CI\Test\Service;

use GuzzleHttp\Client;
use PHPUnit\Framework\TestCase;
use Shopware\CI\Service\SbpClient;

class SbpClientTest extends TestCase
{
    /**
     * @var SbpClient
     */
    private $sbpClient;

    public function setUp(): void
    {
        if (!isset($_SERVER['TEST_SBP_API_BASE_URI'])) {
            static::markTestSkipped('define TEST_SBP_API_BASE_URI');
        }

        if (!isset($_SERVER['TEST_SBP_API_USER'])) {
            static::markTestSkipped('define TEST_SBP_API_USER');
        }

        if (!isset($_SERVER['TEST_SBP_API_PASSWORD'])) {
            static::markTestSkipped('define TEST_SBP_API_PASSWORD');
        }

        $this->sbpClient = new SbpClient(
            new Client([
                'base_uri' => $_SERVER['TEST_SBP_API_BASE_URI'],
                'headers' => [
                    'Content-Type' => 'application/json',
                    'User-Agent' => 'gitlab.shopware.com',
                ],
            ])
        );
        $this->sbpClient->login($_SERVER['TEST_SBP_API_USER'], $_SERVER['TEST_SBP_API_PASSWORD']);
    }

    public function testGetVersions(): void
    {
        $versions = $this->sbpClient->getVersions();

        static::assertNotEmpty($versions);
    }

    public function testGetVersion(): void
    {
        $expectedVersions = $this->sbpClient->getVersions();
        $expectedVersions = \array_slice($expectedVersions, 0, 10);
        static::assertNotEmpty($expectedVersions);

        foreach ($expectedVersions as $expectedVersion) {
            $actualVersion = $this->sbpClient->getVersion((int) $expectedVersion['id']);
            static::assertSame($expectedVersion, $actualVersion);
        }
    }

    public function testGetVersionByName(): void
    {
        $expectedVersions = $this->sbpClient->getVersions();
        $expectedVersions = \array_slice($expectedVersions, 0, 10);
        static::assertNotEmpty($expectedVersions);

        foreach ($expectedVersions as $expectedVersion) {
            $actualVersion = $this->sbpClient->getVersionByName($expectedVersion['name']);
            static::assertNotNull($actualVersion);

            static::assertArrayHasKey('id', $actualVersion);
            static::assertArrayHasKey('name', $actualVersion);
            static::assertArrayHasKey('public', $actualVersion);
            static::assertArrayHasKey('releaseDate', $actualVersion);
            static::assertArrayHasKey('parent', $actualVersion);

            static::assertSame($expectedVersion, $actualVersion);
        }
    }

    public function testUpsertVersionNew(): void
    {
        $parent = $this->sbpClient->getVersionByName('6.3');
        static::assertNotEmpty($parent);

        $date = new \DateTimeImmutable('2020-12-31');
        $this->sbpClient->upsertVersion('6.3.100.0', $parent['id'], $date->format('Y-m-d'), false);

        $actual = $this->sbpClient->getVersionByName('6.3.100.0');
        static::assertNotEmpty($actual);
        static::assertSame('6.3.100.0', $actual['name']);
        static::assertFalse($actual['public']);
        $actualDate = new \DateTimeImmutable($actual['releaseDate']['date']);
        static::assertSame($date->format('Y-m-d'), $actualDate->format('Y-m-d'));

        $this->sbpClient->upsertVersion('6.3.100.0', $parent['id'], null, false);
        $actual = $this->sbpClient->getVersionByName('6.3.100.0');
        static::assertNotEmpty($actual);
        static::assertSame('6.3.100.0', $actual['name']);
        static::assertFalse($actual['public']);
        static::assertNull($actual['releaseDate']);

        $date = new \DateTimeImmutable('2020-12-30');
        $this->sbpClient->upsertVersion('6.3.100.0', $parent['id'], $date->format('Y-m-d'), true);
        $actual = $this->sbpClient->getVersionByName('6.3.100.0');
        static::assertNotEmpty($actual);
        static::assertSame('6.3.100.0', $actual['name']);
        static::assertTrue($actual['public']);
        $actualDate = new \DateTimeImmutable($actual['releaseDate']['date']);
        static::assertSame($date->format('Y-m-d'), $actualDate->format('Y-m-d'));
    }
}
