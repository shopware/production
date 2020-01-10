<?php


namespace Shopware\CI\Service;


use GuzzleHttp\Client;
use GuzzleHttp\RequestOptions;

class GitlabApiClient
{
    /**
     * @var Client
     */
    private $client;

    /**
     * @var string
     */
    private $apiToken;

    public function __construct(Client $client, string $apiToken)
    {
        $this->client = $client;
        $this->apiToken = $apiToken;
    }

    public function openMergeRequest(string $projectId, string $sourceBranch, string $targetBranch, string $title)
    {
        $requestOptions = [
            RequestOptions::JSON => [
                'id' => $projectId,
                'source_branch' => $sourceBranch,
                'target_branch' => $targetBranch,
                'title' => $title
            ],
            RequestOptions::HEADERS => [
                'Private-Token' => $this->apiToken,
                'Content-TYpe' => 'application/json'
            ]
        ];

        $this->client->post('/projects/' . $projectId . '/merge_requests', $requestOptions);
    }
}