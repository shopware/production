<?php declare(strict_types=1);

namespace Shopware\CI\Command;

use Aws\S3\S3MultiRegionClient;
use Composer\Semver\VersionParser;
use GuzzleHttp\Client;
use League\Flysystem\Adapter\Local;
use League\Flysystem\AwsS3v3\AwsS3Adapter;
use League\Flysystem\Filesystem;
use Shopware\CI\Service\ChangelogService;
use Shopware\CI\Service\CredentialService;
use Shopware\CI\Service\ProcessBuilder;
use Shopware\CI\Service\ReleasePrepareService;
use Shopware\CI\Service\ReleaseService;
use Shopware\CI\Service\SbpClient;
use Shopware\CI\Service\TaggingService;
use Shopware\CI\Service\UpdateApiService;
use Shopware\CI\Service\VersioningService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

abstract class ReleaseCommand extends Command
{
    /**
     * @var array|null
     */
    private $config;

    /**
     * @var Filesystem|null
     */
    private $deployFilesystem;

    protected function getConfig(InputInterface $input, OutputInterface $output): array
    {
        if ($this->config !== null) {
            return $this->config;
        }

        $config = [
            'minimumVersion' => $_SERVER['MINIMUM_VERSION'] ?? '6.2.2',
            'projectId' => $_SERVER['CI_PROJECT_ID'] ?? '184',
            'gitlabRemoteUrl' => $_SERVER['CI_REPOSITORY_URL'] ?? 'git@gitlab.shopware.com:/shopware/6/product/production',
            'manyReposBaseUrl' => $_SERVER['MANY_REPO_BASE_URL'] ?? 'git@gitlab.shopware.com:shopware/6/product/many-repositories',
            'projectRoot' => $_SERVER['PROJECT_ROOT'] ?? \dirname(__DIR__, 4),
            'jira' => [
                'api_base_uri' => rtrim($_SERVER['JIRA_API_V2_URL'] ?? 'https://jira.shopware.com/rest/api/2/', '/') . '/',
            ],
            'sbp' => [
                'apiBaseUri' => rtrim($_SERVER['SBP_API_BASE_URI'] ?? '', '/') . '/',
                'apiUser' => $_SERVER['SBP_API_USER'] ?? '',
                'apiPassword' => $_SERVER['SBP_API_PASSWORD'] ?? '',
            ],
            'platform' => [
                'remote' => $_SERVER['PLATFORM_REPO_URL'] ?? 'git@gitlab.shopware.com:shopware/6/product/platform',
            ],
            // theses config values need to be provided by the environment
            'targetBranch' => $_SERVER['TARGET_BRANCH'] ?? $_SERVER['CI_COMMIT_BRANCH'] ?? '',
            'deployFilesystem' => [
                'key' => $_SERVER['AWS_ACCESS_KEY_ID'] ?? '',
                'secret' => $_SERVER['AWS_SECRET_ACCESS_KEY'] ?? '',
                'bucket' => 'releases.s3.shopware.com',
                'publicDomain' => 'https://releases.shopware.com',
            ],
            'updateApiHost' => $_SERVER['UPDATE_API_HOST'] ?? '',
            'gitlabApiToken' => $_SERVER['BOT_API_TOKEN'] ?? '',
            'isMinorRelease' => $_SERVER['MINOR_RELEASE'] ?? false,
            'platformBranch' => $_SERVER['PLATFORM_BRANCH'] ?? null,
            'platformRemoteUrl' => $_SERVER['PLATFORM_REMOTE_URL'] ?? '',
            'developmentRemoteUrl' => $_SERVER['DEVELOPMENT_REMOTE_URL'] ?? '',
            'sshPrivateKeyFile' => $_SERVER['SSH_PRIVATE_KEY_FILE'] ?? '',
        ];

        if ($config['sshPrivateKeyFile'] !== '') {
            ProcessBuilder::loadSshKey($config['sshPrivateKeyFile']);
        }

        if (isset($_SERVER['CI_API_V4_URL'])) {
            $config['gitlabBaseUri'] = rtrim($_SERVER['CI_API_V4_URL'] ?? '', '/') . '/'; // guzzle needs the slash
        } else {
            $config['gitlabBaseUri'] = 'https://gitlab.shopware.com/api/v4/';
        }

        $credentialService = new CredentialService();
        $jiraCredentials = $credentialService->getCredentials($input, $output);
        $config['jira'] = array_merge($config['jira'], $jiraCredentials);

        $stability = $input->hasOption('stability') ? $input->getOption('stability') : null;
        $stability = $input->hasOption('minimum-stability') ? $input->getOption('minimum-stability') : $stability;
        $stability = $stability ?? $_SERVER['STABILITY'] ?? $_SERVER['MINIMUM_STABILITY'] ?? 'stable';

        $config['isMinorRelease'] = ($input->hasOption('minor-release') && $input->getOption('minor-release'))
            || $config['isMinorRelease'];

        if ($input->hasArgument('tag')) {
            $tag = $input->getArgument('tag');
            if (!\is_string($tag)) {
                throw new \RuntimeException('Invalid tag given');
            }
            $config['tag'] = $tag;
            $stability = $stability ?? VersionParser::parseStability($config['tag']);
        }
        $config['stability'] = $stability;

        $repos = ['core', 'administration', 'storefront', 'elasticsearch', 'recovery'];
        $config['repos'] = [];
        foreach ($repos as $repo) {
            $config['repos'][$repo] = [
                'path' => $config['projectRoot'] . '/repos/' . $repo,
                'remoteUrl' => $config['manyReposBaseUrl'] . '/' . $repo,
            ];
        }

        return $this->config = $config;
    }

    protected function getChangelogService(InputInterface $input, OutputInterface $output): ChangelogService
    {
        $config = $this->getConfig($input, $output);

        $jiraApiClient = new Client([
            'base_uri' => $config['jira']['api_base_uri'],
            'auth' => [$config['jira']['username'], $config['jira']['password']],
        ]);

        return new ChangelogService($jiraApiClient);
    }

    protected function getDeployFilesystem(InputInterface $input, OutputInterface $output): Filesystem
    {
        if ($this->deployFilesystem) {
            return $this->deployFilesystem;
        }

        $config = $this->getConfig($input, $output);

        if (!$input->hasOption('deploy') || $input->getOption('deploy')) {
            $s3Client = new S3MultiRegionClient([
                'credentials' => [
                    'key' => $config['deployFilesystem']['key'],
                    'secret' => $config['deployFilesystem']['secret'],
                ],
                'version' => 'latest',
            ]);
            $adapter = new AwsS3Adapter($s3Client, $config['deployFilesystem']['bucket']);
            $this->deployFilesystem = new Filesystem($adapter, ['visibility' => 'public']);
        } else {
            $this->deployFilesystem = new Filesystem(new Local(\dirname(__DIR__, 2) . '/deploy'));
        }

        return $this->deployFilesystem;
    }

    protected function getReleasePrepareService(InputInterface $input, OutputInterface $output): ReleasePrepareService
    {
        $config = $this->getConfig($input, $output);

        $artifactFilesystem = new Filesystem(new Local($config['projectRoot'] . '/artifacts'));

        return new ReleasePrepareService(
            $config,
            $this->getDeployFilesystem($input, $output),
            $artifactFilesystem,
            $this->getChangelogService($input, $output),
            new UpdateApiService($config['updateApiHost'], $output),
            $this->getSbpClient($input, $output),
            $output
        );
    }

    protected function getVersioningService(InputInterface $input, OutputInterface $output): VersioningService
    {
        $config = $this->getConfig($input, $output);

        return new VersioningService(new VersionParser(), $config['stability']);
    }

    protected function getSbpClient(InputInterface $input, OutputInterface $output): SbpClient
    {
        $config = $this->getConfig($input, $output);

        $client = new SbpClient(new Client([
            'base_uri' => $config['sbp']['apiBaseUri'],
            'headers' => [
                'Content-Type' => 'application/json',
                'User-Agent' => 'gitlab.shopware.com',
            ],
        ]));

        try {
            $client->login($config['sbp']['apiUser'], $config['sbp']['apiPassword']);
        } catch (\Throwable $e) {
            $output->writeln('Failed sbp login: ' . $e->getMessage());
        }

        return $client;
    }

    protected function getTaggingService(InputInterface $input, OutputInterface $output): TaggingService
    {
        $config = $this->getConfig($input, $output);
        $gitlabApiClient = new Client([
            'base_uri' => $config['gitlabBaseUri'],
            'headers' => [
                'Private-Token' => $config['gitlabApiToken'],
                'Content-TYpe' => 'application/json',
            ],
        ]);

        return new TaggingService($config, $gitlabApiClient, $output, false);
    }

    protected function getReleaseService(InputInterface $input, OutputInterface $output): ReleaseService
    {
        $config = $this->getConfig($input, $output);

        return new ReleaseService(
            $config,
            $this->getReleasePrepareService($input, $output),
            $this->getTaggingService($input, $output),
            $this->getSbpClient($input, $output),
            $output
        );
    }
}
