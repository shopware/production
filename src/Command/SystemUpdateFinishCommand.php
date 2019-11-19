<?php

declare(strict_types=1);

namespace Shopware\Production\Command;

use Shopware\Core\Framework\Console\ShopwareStyle;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Update\Event\UpdateFinishedEvent;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

class SystemUpdateFinishCommand extends Command
{
    static public $defaultName = 'system:update:finish';

    /**
     * @var SymfonyStyle
     */
    protected $io;

    /**
     * @var EventDispatcherInterface
     */
    private $eventDispatcher;
    /**
     * @var string
     */
    private $projectDir;

    public function __construct(EventDispatcherInterface $eventDispatcher, string $projectDir)
    {
        parent::__construct();
        $this->eventDispatcher = $eventDispatcher;
        $this->projectDir = $projectDir;
    }

    protected function configure(): void
    {
        $this->addOption('no-stop-maintenance', null, InputOption::VALUE_NONE, 'Dont stop maintenance');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->io = new ShopwareStyle($input, $output);
        $envFile = $this->projectDir . '/.env';
        if (!is_readable($envFile) || is_dir($envFile)) {
            $this->io->error("No .env found. \nPlease create one or run 'bin/console system:setup'");
            return -1;
        }

        $this->io = new ShopwareStyle($input, $output);
        $this->io->writeln('Run Post Update');
        $this->io->writeln('');

        $this->runMigrations($input, $this->io);

        // TODO: try activating all plugins

        $updateEvent = new UpdateFinishedEvent(Context::createDefaultContext());
        $this->eventDispatcher->dispatch($updateEvent);

        $this->installAssets($input, $this->io);

        if ($input->getOption('no-stop-maintenance')) {
            return 0;
        }

        $output->writeln('');

        $command = $this->getApplication()->find('system:maintenance');
        return $command->run(new ArrayInput(['action' => 'stop'], $command->getDefinition()), $output);
    }

    private function runMigrations(InputInterface $input, OutputInterface $output): int
    {
        $command = $this->getApplication()->find('database:migrate');

        $arguments = [
            'identifier' => 'Shopware\\',
            '--all'  => true,
        ];
        $arrayInput = new ArrayInput($arguments, $command->getDefinition());
        return $command->run($arrayInput, $output);
    }

    private function installAssets(InputInterface $input, OutputInterface $output): int
    {
        $command = $this->getApplication()->find('assets:install');
        return $command->run(new ArrayInput(['no-cleanup' => true], $command->getDefinition()), $output);
    }
}
