<?php


namespace Shopware\CI\Command;


use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ShowPlatformBranchCommand extends ReleaseCommand
{
    public static $defaultName = 'release:show-platform-branch';

    protected function configure(): void
    {
        $this
            ->setDescription('Show matching platform branch')
            ->addArgument('tag', InputArgument::REQUIRED, 'Release tag')
            ->addArgument('repository', InputArgument::OPTIONAL, 'Repository path')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $config = $this->getConfig($input, $output);
        if ($config['platformBranch'] !== null) {
            $output->writeln($config['platformBranch']);

            return 0;
        }

        $repository = $input->getArgument('repository') ?? (getcwd() . '/platform');

        $versioningService = $this->getVersioningService($input, $output);
        $matchingBranch = $versioningService->getBestMatchingBranch($input->getArgument('tag'), $repository);

        $output->writeln($matchingBranch);

        return 0;
    }
}
