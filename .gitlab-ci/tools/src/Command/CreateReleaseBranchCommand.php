<?php declare(strict_types=1);

namespace Shopware\CI\Command;

use Composer\Semver\Semver;
use Composer\Semver\VersionParser;
use GuzzleHttp\Client;
use Shopware\CI\Service\ReleaseService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class CreateReleaseBranchCommand extends Command
{
    public static $defaultName = 'create-release-branch';

    public static function getConfig(string $tag, string $stability = null): array
    {
        $config = [
            'projectId' => $_SERVER['CI_PROJECT_ID'],
            'gitlabBaseUri' => $_SERVER['CI_API_V4_URL'],
            'gitlabRemoteUrl' => $_SERVER['CI_REPOSITORY_URL'],
            'gitlabApiToken' => $_SERVER['BOT_API_TOKEN'],
            'tag' => $tag,
            'targetBranch' => $_SERVER['TARGET_BRANCH'],
            'manyReposBaseUrl' => $_SERVER['MANY_REPO_BASE_URL'],
            'projectRoot' => $_SERVER['PROJECT_ROOT'],
        ];

        $config['stability'] = $_SERVER['STABILITY'] ?? $stability ?? VersionParser::parseStability($config['tag']);

        $repos = ['core', 'administration', 'storefront', 'elasticsearch', 'recovery'];
        $config['repos'] = [];
        foreach ($repos as $repo) {
            $config['repos'][$repo] = [
                'path' => $config['projectRoot'] . '/repos/' . $repo,
                'remoteUrl' => $config['manyReposBaseUrl'] . '/' .  $repo
            ];
        }

        return $config;
    }

    protected function configure(): void
    {
        $this->setDescription('Create release branch')
            ->addArgument('tag', InputArgument::REQUIRED, 'Release tag')
            ->addOption('stability', null, InputOption::VALUE_REQUIRED, 'Stability of the release')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $config = self::getConfig($input->getArgument('tag'), $input->getOption('stability'));

        $client = new Client([
            'base_uri' => $config['gitlabBaseUri'],
            'headers' => [
                'Private-Token' => $config['gitlabApiToken'],
                'Content-TYpe' => 'application/json'
            ]
        ]);

        $releaseService = new ReleaseService($config, $client);
        $releaseService->release();

        return 0;
    }
}
