<?php

declare(strict_types=1);

namespace Shopware\Production\Command;

use Shopware\Core\Framework\Console\ShopwareStyle;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class SystemCheckCommand extends Command
{
    static public $defaultName = 'system:check';

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
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output = new ShopwareStyle($input, $output);

        $output->writeln('Everything is good :-)');

        return 0;
    }
}
