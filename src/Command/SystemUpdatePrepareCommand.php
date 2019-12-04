<?php

declare(strict_types=1);

namespace Shopware\Production\Command;

use Shopware\Core\Framework\Adapter\Console\ShopwareStyle;
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

        if ($input->getOption('deactivate-plugins')) {
            // TODO: implement
//            $commands[] = [
//                'command' => 'system:maintenance',
//                'action' => 'start',
//            ];
        }

        try {
            //$updateEvent = new UpdatePreparationEvent(Context::createDefaultContext());
            //$this->eventDispatcher->dispatch($updateEvent);
        } catch(\Throwable $e) {
            $result = 1;
        }

        return 0;
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
}
