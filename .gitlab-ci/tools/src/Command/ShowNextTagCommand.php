<?php declare(strict_types=1);

namespace Shopware\CI\Command;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ShowNextTagCommand extends ReleaseCommand
{
    public static $defaultName = 'release:show-next-tag';

    public static function getTags(string $repository): array
    {
        $output = [];
        $returnCode = 0;
        exec('git -C ' . escapeshellarg($repository) . ' tag --list ', $output, $returnCode);

        if ($returnCode !== 0) {
            throw new \RuntimeException('Failed to list tags');
        }

        return $output;
    }

    public static function getRootPath(string $repository): string
    {
        $returnCode = 0;
        $rootDir = exec('git -C ' . escapeshellarg($repository) . ' rev-parse --show-toplevel', $output, $returnCode);

        if ($returnCode !== 0) {
            throw new \RuntimeException('Failed to list tags');
        }

        return $rootDir;
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Show next tag for branch')
            ->addArgument('repository', InputArgument::OPTIONAL, 'Repository path')
            ->addOption('constraint', null, InputOption::VALUE_REQUIRED, 'Version constraint')
            ->addOption('minimum-stability', null, InputOption::VALUE_REQUIRED, 'Release stability')
            ->addOption('minor-release', null, InputOption::VALUE_NONE, 'Is minor release')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $repository = $input->getArgument('repository') ?? getcwd();
        if (!\is_string($repository)) {
            throw new \RuntimeException('Invalid repository path given');
        }

        $rootPath = self::getRootPath($repository);
        $composerJson = \json_decode(file_get_contents($rootPath . '/composer.json'), true);

        $constraint = $input->getOption('constraint');
        if (!\is_string($constraint)) {
            $constraint = $composerJson['require']['shopware/core'];
        }

        $versioningService = $this->getVersioningService($input, $output);
        $tags = self::getTags($repository);
        $matchingVersions = $versioningService->getMatchingVersions($tags, $constraint);

        $lastVersion = array_pop($matchingVersions);

        $nextTag = $versioningService->getNextTag($constraint, $lastVersion, $this->isMinorRelease($input));

        $output->writeln($nextTag);

        return 0;
    }

    private function isMinorRelease(InputInterface $input): bool
    {
        return ($input->hasOption('minor-release') && $input->getOption('minor-release'))
            || (isset($_SERVER['MINOR_RELEASE']) && $_SERVER['MINOR_RELEASE'] !== 'false'
            && $_SERVER['MINOR_RELEASE'] !== 0
            && $_SERVER['MINOR_RELEASE'] !== '0'
            && trim($_SERVER['MINOR_RELEASE']) !== '');
    }
}
