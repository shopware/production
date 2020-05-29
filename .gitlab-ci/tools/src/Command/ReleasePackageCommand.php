<?php


namespace Shopware\CI\Command;


use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ReleasePackageCommand extends ReleaseCommand
{
    public static $defaultName = 'release:package';

    protected function configure(): void
    {
        $this->setDescription('Release package')
            ->addArgument('tag', InputArgument::REQUIRED, 'Release tag')
            ->addOption('stability', null, InputOption::VALUE_REQUIRED, 'Stability of the release')
            ->addOption('deploy', null, InputOption::VALUE_NONE, 'Deploy to s3');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $releaseService = $this->getReleaseService($input, $output);
        $releaseService->releasePackage($input->getArgument('tag'));

        return 0;
    }
}
