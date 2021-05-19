<?php declare(strict_types=1);

namespace Shopware\CI\Command;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ReleaseTagsCommand extends ReleaseCommand
{
    public static $defaultName = 'release:tags';

    protected function configure(): void
    {
        $this->setDescription('Release tags')
            ->addArgument('tag', InputArgument::REQUIRED, 'Release tag')
            ->addOption('minimum-stability', null, InputOption::VALUE_REQUIRED, 'Stability of the release');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $tag = $input->getArgument('tag');

        $this->getReleaseService($input, $output)->releaseTags($tag);

        return 0;
    }
}
