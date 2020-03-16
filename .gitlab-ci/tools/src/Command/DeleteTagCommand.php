<?php declare(strict_types=1);

namespace Shopware\CI\Command;

use Composer\Semver\Semver;
use Composer\Semver\VersionParser;
use GuzzleHttp\Client;
use Shopware\CI\Service\ReleaseService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class DeleteTagCommand extends Command
{
    public static $defaultName = 'delete-tag';

    public static function getConfig(string $tag): array
    {
        $config = [
            'projectId' => $_SERVER['CI_PROJECT_ID'],
            'gitlabBaseUri' => $_SERVER['CI_API_V4_URL'] . '/api/v4',
            'gitlabRemoteUrl' => $_SERVER['CI_REPOSITORY_URL'],
            'gitlabApiToken' => $_SERVER['BOT_API_TOKEN'],
            'tag' => $tag,
            'targetBranch' => $_SERVER['TARGET_BRANCH'],
            'manyReposBaseUrl' => $_SERVER['MANY_REPO_BASE_URL'],
            'manyReposGithubUrl' => $_SERVER['MANY_REPO_GITHUB_URL'],
            'projectRoot' => $_SERVER['PROJECT_ROOT'],
        ];

        $repos = ['core', 'administration', 'storefront', 'elasticsearch', 'recovery'];
        $config['repos'] = [];
        foreach ($repos as $repo) {
            $config['repos'][$repo] = [
                'path' => $config['projectRoot'] . '/repos/' . $repo,
                'remoteUrl' => $config['manyReposBaseUrl'] . '/' .  $repo,
                'githubUrl' => $config['manyReposGithubUrl'] . '/' . $repo,
            ];
        }

        return $config;
    }

    protected function configure(): void
    {
        $this->setDescription('Create release branch')
            ->addArgument('tag', InputArgument::REQUIRED, 'Release tag')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $config = self::getConfig($input->getArgument('tag'));

        $client = new Client([
            'base_uri' => $config['gitlabBaseUri'],
            'headers' => [
                'Private-Token' => $config['gitlabApiToken'],
                'Content-TYpe' => 'application/json'
            ]
        ]);

        $releaseService = new ReleaseService($config, $client);
        $releaseService->deleteTag($config['tag'], $config['repos']);

        return 0;
    }
}
