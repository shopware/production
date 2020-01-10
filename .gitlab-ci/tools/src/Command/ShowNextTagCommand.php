<?php declare(strict_types=1);

namespace Shopware\CI\Command;

use Composer\Semver\Semver;
use Composer\Semver\VersionParser;
use Shopware\CI\Service\TaggingService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ShowNextTagCommand extends Command
{
    public static $defaultName = 'show-next-tag';

    protected function configure(): void
    {
        $this
            ->setDescription('Show next tag for branch')
            ->addArgument('repository', InputArgument::OPTIONAL, 'Repository path')
            ->addOption('constraint', null, InputOption::VALUE_REQUIRED, 'Version constraint')
            ->addOption('minimum-stability', null, InputOption::VALUE_REQUIRED, 'Release stability')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $repository = $input->getArgument('repository') ?? getcwd();

        $rootPath = self::getRootPath($repository);
        $composerJson = \json_decode(file_get_contents($rootPath . '/composer.json'), true);

        $constraint = $input->getOption('constraint');
        if (!$constraint) {
            $constraint = $composerJson['require']['shopware/core'];
        }

        $minimumStability = $input->getOption('minimum-stability');
        if (!$minimumStability) {
            $minimumStability = $composerJson['minimum-stability'];
        }

        $taggingService = new TaggingService(new VersionParser(), $minimumStability);
        $tags = self::getTags($repository);
        $matchingVersions = $taggingService->getMatchingVersions($tags, $constraint);

        $lastVersion = array_pop($matchingVersions);

        $output->writeln($taggingService->getNextTag($constraint, $lastVersion));

        return 0;
    }

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
        $rootDir = exec('git -C ' . escapeshellarg($repository) .' rev-parse --show-toplevel', $output, $returnCode);

        if ($returnCode !== 0) {
            throw new \RuntimeException('Failed to list tags');
        }

        return $rootDir;
    }
}
