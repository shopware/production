<?php declare(strict_types=1);

namespace Shopware\CI\Command;

use Shopware\CI\Service\ChangelogService;
use Symfony\Component\Console\Exception\InvalidOptionException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Style\SymfonyStyle;

class GenerateChangelogCommand extends ReleaseCommand
{
    public static $defaultName = 'release:generate-changelog';

    /**
     * @var ChangelogService
     */
    private $changeLogService;

    protected function configure(): void
    {
        $this->setDescription('Fetch tickets from JIRA API and generate the changelog.')
            ->addArgument('tag', InputArgument::OPTIONAL, 'Release tag')
            ->addOption('deploy', null, InputOption::VALUE_NONE, 'Deploy to s3');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Changelog Generator');

        if ($input->getOption('deploy')) {
            $io->note('The automatic deploy is not implemented yet.');
        }

        $this->changeLogService = $this->getChangelogService($input, $output);

        $jiraVersion = $this->getJiraVersion($input, $output);
        $this->renderChangelog($io, $jiraVersion);

        return 0;
    }

    private function getJiraVersion(InputInterface $input, OutputInterface $output): string
    {
        $io = new SymfonyStyle($input, $output);

        $version = $input->getArgument('tag');
        if (empty($version)) {
            $unreleasedVersions = $this->changeLogService->getVersions(true);
            $question = new ChoiceQuestion('Jira version', $unreleasedVersions, $unreleasedVersions[0]);
            $question->setValidator(static function (string $value): string {
                if (trim($value) === '') {
                    throw new InvalidOptionException('The release-version cannot be empty');
                }

                return $value;
            });

            $version = $io->askQuestion($question);
        }
        $version = \is_array($version) ? $version[0] : $version;
        $version = $this->changeLogService->findVersion($version);

        return $version;
    }

    private function renderChangelog(SymfonyStyle $io, string $jiraVersion): void
    {
        $io->comment('Fetching changelog from API');
        $changelog = $this->changeLogService->getChangeLog($jiraVersion);

        foreach ($changelog as $locale => $data) {
            $io->section(sprintf('Changelogs for "%s"', $locale));

            foreach ($data['changelog'] ?? [] as $line) {
                $io->writeln($line);
            }
        }
    }
}
