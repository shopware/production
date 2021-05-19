<?php declare(strict_types=1);

namespace Shopware\CI\Service;

use GuzzleHttp\Client;

class SbpClient
{
    private Client $client;

    private ?string $token = null;

    public function __construct(Client $client)
    {
        $this->client = $client;
    }

    public function login(string $username, string $password): void
    {
        $response = $this->client->post('/ldaptokens', [
            'json' => [
                'name' => $username,
                'password' => $password,
            ],
        ]);

        $response = json_decode($response->getBody()->getContents(), true);

        if (json_last_error() !== \JSON_ERROR_NONE) {
            throw new \RuntimeException('Failed to decode json');
        }

        $this->token = $response['token'];
    }

    public function getVersionByName(string $name): ?array
    {
        $name = ltrim($name, 'v ');

        $versions = $this->getVersions();
        foreach ($versions as $version) {
            if ($version['name'] === $name) {
                return $version;
            }
        }

        return null;
    }

    public function getVersion(int $id): ?array
    {
        $versions = $this->getVersions();
        foreach ($versions as $version) {
            if ($version['id'] === $id) {
                return $version;
            }
        }

        return null;
    }

    public function getVersions(): array
    {
        $response = $this->client->get(
            '/pluginstatics/softwareVersions',
            [
                'headers' => $this->getHeaders(),
            ]
        );

        return array_map(function (array $v) {
            $v['parent'] = isset($v['parent']) ? ((int) $v['parent']) : null;

            return $v;
        }, json_decode($response->getBody()->getContents(), true));
    }

    public function upsertVersion(string $versionName, ?int $parentId, ?string $releaseDate, ?bool $public): void
    {
        $name = ltrim($versionName, 'v ');

        $current = $this->getVersionByName($name);

        $parent = null;
        if ($parentId === null && $current !== null) {
            $parent = $this->getVersion($current['parent']);
        } elseif ($parentId !== null) {
            $parent = $this->getVersion($parentId);
        }
        if ($parent === null) {
            throw new \RuntimeException('No parent found');
        }

        if ($current === null) {
            $this->client->post('/pluginstatics/softwareVersions', [
                'headers' => $this->getHeaders(),
                'json' => [
                    'edit' => null,
                    'id' => null,
                    'name' => $name,
                    'parent' => $parent['id'],
                    'public' => $public,
                    'releaseDate' => $releaseDate,
                ],
            ]);
        } else {
            $this->client->put('/pluginstatics/softwareVersions', [
                'headers' => $this->getHeaders(),
                'json' => [
                    'edit' => true,
                    'id' => $current['id'],
                    'name' => $name,
                    'parent' => $parent['id'],
                    'public' => $public ?? $current['public'],
                    'releaseDate' => $releaseDate,
                ],
            ]);
        }
    }

    private function getHeaders(): array
    {
        return array_merge(
            $this->client->getConfig()['headers'],
            ['X-Shopware-Token' => $this->token]
        );
    }
}
