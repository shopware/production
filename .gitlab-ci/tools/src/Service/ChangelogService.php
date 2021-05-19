<?php declare(strict_types=1);

namespace Shopware\CI\Service;

use GuzzleHttp\Client;

class ChangelogService
{
    private const TEMPLATE_PATH = __DIR__ . '/../Template/Changelog.tpl';
    private const ISSUE_URL = 'https://issues.shopware.com/issues/';
    private const GITHUB_FIELD_ID = 12101;

    private const CHANGE_LOG_LINE_MIN_LENGTH = 5;

    /**
     * Key: language
     * Value: custom field ID
     *
     * @var array
     */
    private $localeMapping = [
        'en' => 11901,
        'de' => 11900,
    ];

    /**
     * @var Client
     */
    private $client;

    public function __construct(Client $client)
    {
        $this->client = $client;
    }

    public function getVersions(bool $onlyUnreleased = false): array
    {
        $response = $this->client->request('GET', 'project/NEXT/versions');
        $versions = json_decode($response->getBody()->getContents(), true);
        if (json_last_error() !== \JSON_ERROR_NONE) {
            throw new \RuntimeException('Failed to decode json');
        }

        $filtered = array_filter($versions, static function (array $version) use ($onlyUnreleased) {
            return preg_match('/^6\./', $version['name'])
                && (!$onlyUnreleased || !$version['released']);
        });

        $versions = array_column($filtered, 'name');
        usort($versions, 'version_compare');

        return $versions;
    }

    /**
     * Normalizes the version and tries to find it
     */
    public function findVersion(string $version): string
    {
        $versions = $this->getVersions();
        if (\in_array($version, $versions, true)) {
            return $version;
        }

        $version = str_replace('-', ' ', ltrim(trim($version), 'v'));
        if (\in_array($version, $versions, true)) {
            return $version;
        }

        throw new \RuntimeException('Version "' . $version . '" not found');
    }

    public function fetchFixedIssues(string $version): array
    {
        $version = $this->findVersion($version);
        $response = $this->client->request('GET', 'search', [
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ],
            'query' => [
                'jql' => sprintf('project=\'NEXT\' AND status=Resolved AND resolution=done AND fixVersion=\'%s\' AND cf[10202]=Yes ORDER BY key ASC', $version),
                'fields' => 'id,key,customfield_11901,customfield_11900,customfield_12101,customfield_12100',
                'maxResults' => 1000,
            ],
        ]);

        $items = json_decode($response->getBody()->getContents(), true);
        if (json_last_error() !== \JSON_ERROR_NONE) {
            throw new \RuntimeException('Failed to decode json');
        }

        return $items;
    }

    public function getChangeLog(string $version): array
    {
        $version = $this->findVersion($version);
        $template = file_get_contents(self::TEMPLATE_PATH);
        $result = $this->fetchFixedIssues($version);

        $changeLog = [];

        foreach ($this->localeMapping as $locale => $_changelogFieldId) {
            $result[$locale] = [];
            foreach ($result['issues'] as $issue) {
                $changelogText = trim($issue['fields']['customfield_' . $this->localeMapping[$locale]] ?? '');
                $githubAuthor = trim($issue['fields']['customfield_' . self::GITHUB_FIELD_ID] ?? '');
                $githubAnnotation = '';

                if (\strlen($changelogText) < self::CHANGE_LOG_LINE_MIN_LENGTH) {
                    continue;
                }

                if (!empty($githubAuthor)) {
                    $githubAnnotation = sprintf('<a href="https://github.com/%s" target="_blank">(%s)</a>', $githubAuthor, $githubAuthor);
                }

                $replaces = [
                    '{{ISSUE_KEY}}' => $issue['key'],
                    '{{ISSUE_URL}}' => self::ISSUE_URL . $issue['key'],
                    '{{DESCRIPTION}}' => $changelogText,
                    '{{GITHUB_ANNOTATION}}' => $githubAnnotation,
                ];

                $line = str_replace(array_keys($replaces), array_values($replaces), $template);
                $line = preg_replace('/\r?\n|\r/', ' ', $line);

                $changeLog[$locale]['changelog'][] = $line;
            }
        }

        return $changeLog;
    }
}
