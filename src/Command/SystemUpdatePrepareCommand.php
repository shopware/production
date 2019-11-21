<?php

declare(strict_types=1);

namespace Shopware\Production\Command;

use Shopware\Core\Framework\Console\ShopwareStyle;
use Shopware\Production\Kernel;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class SystemUpdatePrepareCommand extends Command
{
    static public $defaultName = 'system:update:prepare';

    public function __construct()
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('maintenance', null, InputOption::VALUE_NONE, 'Put shop into maintenance mode.')
            ->addOption('deactivate-plugins', null, InputOption::VALUE_NONE, 'Deactivate all plugins.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output = new ShopwareStyle($input, $output);

        $params = parse_url($_SERVER['DATABASE_URL']);

        if ($params['host'] === Kernel::PLACEHOLDER_DATABASE_URL) {
            $output->error("Environment variable 'DATABASE_URL' not defined. \nPlease create an .env by running 'bin/console system:setup' or pass it manually");
            return 1;
        }

        $output->writeln('Run Update preparations');

        $commands = [
            ['command' =>'system:check']
        ];

        if ($input->getOption('maintenance')) {
            $commands[] = [
                'command' => 'system:maintenance',
                'action' => 'start',
            ];
        }

        if ($input->getOption('deactivate-plugins')) {
            // TODO: implement
//            $commands[] = [
//                'command' => 'system:maintenance',
//                'action' => 'start',
//            ];
        }

        $result = $this->runCommands($commands, $output);

        try {
            //$updateEvent = new UpdatePreparationEvent(Context::createDefaultContext());
            //$this->eventDispatcher->dispatch($updateEvent);
        } catch(\Throwable $e) {
            $result = 1;
        }

        return $result;
    }

    private function runCommands(array $commands, OutputInterface $output): int
    {
        foreach($commands as $parameters) {
            $output->writeln('');

            $command = $this->getApplication()->find($parameters['command']);

            unset($parameters['command']);
            $returnCode = $command->run(new ArrayInput($parameters, $command->getDefinition()), $output);
            if ($returnCode !== 0) {
                return $returnCode;
            }
        }

        return 0;
    }

    private function checkRequirements(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('Check requirements');

        return 1;
    }

    private function enableMaintenanceMode(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('Enable maintenance mode');

        return 1;
    }

    private function deactivatePlugins(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('Deactivate plugins');

        return 1;
    }
}
