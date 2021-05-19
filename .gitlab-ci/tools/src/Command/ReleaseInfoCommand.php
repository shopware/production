<?php declare(strict_types=1);

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
            ->addOption('deploy', null, InputOption::VALUE_NONE, 'Deploy');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $tag = $input->getArgument('tag');

        echo \json_encode($this->getReleasePrepareService($input, $output)->getReleaseList()->getRelease($tag));

        return 0;
    }
}
