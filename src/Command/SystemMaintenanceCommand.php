<?php

declare(strict_types=1);

namespace Shopware\Production\Command;

use Shopware\Core\Framework\Console\ShopwareStyle;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class SystemMaintenanceCommand extends Command
{
    static public $defaultName = 'system:maintenance';

    public const MAINTENANCE_ACTION_START = 'start';
    public const MAINTENANCE_ACTION_STOP = 'stop';
    public const MAINTENANCE_ACTION_STATUS = 'status';

    /**
     * @var SymfonyStyle
     */
    protected $io;

    public function __construct()
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('action', InputArgument::OPTIONAL, 'status, start, stop', 'status');

        // specify reason
        // all sales channels
        // specific sales channel
        // admin?
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->io = $output = new ShopwareStyle($input, $output);

        $action = $input->getArgument('action');
        switch (strtolower($action)) {
            case self::MAINTENANCE_ACTION_START:
                $output->writeln('Started maintenance');
                break;
            case self::MAINTENANCE_ACTION_STOP;
                $output->writeln('Stopped maintenance');
                break;
            case self::MAINTENANCE_ACTION_STATUS:
            default:
                $output->writeln('System is currently not in maintenance mode');
        }

        return null;
    }
}
