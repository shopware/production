<?php declare(strict_types=1);

namespace Shopware\CI\Command;

use Composer\Semver\Semver;
use Composer\Semver\VersionParser;
use Shopware\CI\Service\ReleaseService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class CreateReleaseBranchCommand extends Command
{
    public static $defaultName = 'create-release-branch';

    /**
     * @var ReleaseService
     */
    private $releaseService;

    public function __construct(string $name = null)
    {
        parent::__construct($name);
        $this->releaseService = new ReleaseService();
    }

    protected function configure(): void
    {
        $this->setDescription('Create release branch');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->releaseService->release();

        return 0;
    }
}
