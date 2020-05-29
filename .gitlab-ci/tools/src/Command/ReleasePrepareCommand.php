<?php declare(strict_types=1);

namespace Shopware\CI\Command;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ReleasePrepareCommand extends ReleaseCommand
{
    public static $defaultName = 'release:prepare';

    protected function configure(): void
    {
        $this->setDescription('Prepare release')
            ->addArgument('tag', InputArgument::REQUIRED, 'Release tag')
            ->addOption('stability', null, InputOption::VALUE_REQUIRED, 'Stability of the release')
            ->addOption('deploy', null, InputOption::VALUE_NONE, 'Deploy to s3');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $releaseService = $this->getReleasePrepareService($input, $output);

        $releaseService->prepareRelease($input->getArgument('tag'));

        return 0;
    }
}
