<?php declare(strict_types=1);

namespace Shopware\CI\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class DeleteTagsCommand extends ReleaseCommand
{
    public static $defaultName = 'release:delete-tags';

    protected function configure(): void
    {
        $this->setDescription('Delete tags');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $config = $this->getConfig($input, $output);

        $taggingService = $this->getTaggingService($input, $output);
        $taggingService->deleteTag($input->getArgument('tag'), $config['repos']);

        return 0;
    }
}
