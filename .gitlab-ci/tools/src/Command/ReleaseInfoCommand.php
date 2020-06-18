<?php


namespace Shopware\CI\Command;



use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ReleaseInfoCommand extends ReleaseCommand
{
    public static $defaultName = 'release:info';

    protected function configure(): void
    {
        $this->setDescription('Release information')
            ->addArgument('tag', InputArgument::REQUIRED, 'Release tag')
            ->addOption('deploy', null, InputOption::VALUE_NONE, 'asdfds');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $releaseService = $this->getReleasePrepareService($input, $output);

        $list = $releaseService->getReleaseList();

        $release = $list->getRelease($input->getArgument('tag'));

        echo \json_encode($release);

        return 0;
    }
}
