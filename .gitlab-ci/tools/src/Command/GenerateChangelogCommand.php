<?php declare(strict_types=1);

namespace Shopware\CI\Command;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use Shopware\CI\Service\CredentialService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Exception\InvalidOptionException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;

class GenerateChangelogCommand extends Command
{
    private const TEMPLATE_PATH = __DIR__ . '/../Template/Changelog.tpl';
    private const ISSUE_URL = 'https://issues.shopware.com/issues/';
    private const GITHUB_FIELD_ID = 12101;

    private const CHANGE_LOG_LINE_MIN_LENGTH = 5;

    public static $defaultName = 'generate-changelog';

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

    protected function configure(): void
    {
        $this
            ->setDescription('Fetch tickets from JIRA API and generate the changes text.')
            ->addOption('release-version', 'r', InputOption::VALUE_REQUIRED, 'Target version')
            ->addOption('deploy', null, InputOption::VALUE_NONE, 'Deploy the changelogs directly into the releases.xml')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $credentialService = new CredentialService();
        $credentials = $credentialService->getCredentials($input, $output);

        $io = new SymfonyStyle($input, $output);
        $io->title('Changelog Generator');

        if ($input->getOption('deploy')) {
            $io->note('The automatic deploy is not implemented yet.');
        }

        $parameters = $this->resolveParameters($input, $output);
        $changelog = $this->fetchChangelog($io, $credentials, $parameters['release-version']);

        $this->renderChangelog($io, $changelog);
    }

    private function resolveParameters(InputInterface $input, OutputInterface $output)
    {
        $io = new SymfonyStyle($input, $output);

        $version = $input->getOption('release-version');
        if (empty($version)) {
            $question = new Question('Release version');
            $question->setValidator(function ($value) {
                if (trim($value) === '') {
                    throw new InvalidOptionException('The release-version cannot be empty');
                }

                return $value;
            });

            $version = $io->askQuestion($question);
        }

        return [
            'release-version' => $version,
        ];
    }

    private function fetchChangelog(SymfonyStyle $io, array $credentials, $version)
    {
        $io->comment('Fetching changlog from API');

        $client = new Client();

        try {
            $response = $client->request('GET', 'https://jira.shopware.com/rest/api/2/search', [
                'auth' => [$credentials['username'], $credentials['password']],
                'headers' => [
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                ],
                'query' => [
                    'jql' => sprintf('project=\'NEXT\' AND status=Resolved AND resolution=fixed AND fixVersion=\'%s\' AND cf[10202]=Freigeben ORDER BY key ASC', $version),
                    'fields' => 'id,key,customfield_11901,customfield_11900,customfield_12101,customfield_12100',
                    'maxResults' => 1000
                ],
            ]);
        } catch (ClientException $ex) {
            $errorMessages = json_decode($ex->getResponse()->getBody()->getContents(), true);
            $io->table(['Errors'], [$errorMessages['errorMessages']]);
            exit(1);
        }

        $items = json_decode($response->getBody()->getContents(), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $io->error('Unable to parse the JSON response from JIRA.');
            exit(1);
        }

        return $items;
    }

    private function renderChangelog(SymfonyStyle $io, array $changelogs): void
    {
        $template = file_get_contents(self::TEMPLATE_PATH);

        foreach ($this->localeMapping as $locale => $changelogFieldId) {
            $io->section(sprintf('Changelogs for "%s"', $locale));

            foreach ($changelogs['issues'] as $changelog) {
                $changelogText = trim($changelog['fields']['customfield_' . $this->localeMapping[$locale]] ?? '');
                $githubAuthor = trim($changelog['fields']['customfield_' . self::GITHUB_FIELD_ID] ?? '');
                $githubAnnotation = '';

                if (strlen($changelogText) < self::CHANGE_LOG_LINE_MIN_LENGTH) {
                    continue;
                }

                if (!empty($githubAuthor)) {
                    $githubAnnotation = sprintf('<a href="https://github.com/%s" target="_blank">(%s)</a>', $githubAuthor, $githubAuthor);
                }

                $replaces = [
                    '{{ISSUE_KEY}}' => $changelog['key'],
                    '{{ISSUE_URL}}' => self::ISSUE_URL . $changelog['key'],
                    '{{DESCRIPTION}}' => $changelogText,
                    '{{GITHUB_ANNOTATION}}' => $githubAnnotation,
                ];

                $line = str_replace(array_keys($replaces), array_values($replaces), $template);
                $line = preg_replace('/\r?\n|\r/', ' ', $line);

                $io->writeln($line);
            }
        }
    }
}
