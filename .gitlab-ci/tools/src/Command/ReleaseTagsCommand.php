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

class ReleaseTagsCommand extends ReleaseCommand
{
    public static $defaultName = 'release:tags';

    protected function configure(): void
    {
        $this->setDescription('Release tags');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $releaseService = $this->getReleaseService($input, $output);
        $releaseService->releaseTags($input->getArgument('tag'));

        return 0;
    }
}
