<?php

declare(strict_types=1);

namespace Shopware\Production\Command;

use Shopware\Core\Framework\Adapter\Console\ShopwareStyle;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Plugin\KernelPluginLoader\StaticKernelPluginLoader;
use Shopware\Core\Framework\Update\Api\UpdateController;
use Shopware\Core\Framework\Update\Event\UpdatePostFinishEvent;
use Shopware\Core\Framework\Update\Event\UpdatePreFinishEvent;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shopware\Production\Kernel;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\ContainerInterface;

class SystemUpdateFinishCommand extends Command
{
    static public $defaultName = 'system:update:finish';

    /**
     * @var SymfonyStyle
     */
    protected $io;

    /**
     * @var ContainerInterface
     */
    private $container;

    public function __construct(ContainerInterface $container)
    {
        parent::__construct();
        $this->container = $container;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output = new ShopwareStyle($input, $output);

        $dsn = trim((string)($_SERVER['DATABASE_URL'] ?? getenv('DATABASE_URL')));
        if ($dsn === '' || $dsn === Kernel::PLACEHOLDER_DATABASE_URL)  {
            $output->note("Environment variable 'DATABASE_URL' not defined. Skipping " . $this->getName() . '...');
            return 0;
        }

        $output->writeln('Run Post Update');
        $output->writeln('');

        $containerWithoutPlugins = $this->rebootKernelWithoutPlugins();

        $context = Context::createDefaultContext();
        $oldVersion = (string)$this->container->get(SystemConfigService::class)
            ->get(UpdateController::UPDATE_PREVIOUS_VERSION_KEY);

        $newVersion = $containerWithoutPlugins->getParameter('kernel.shopware_version');
        $containerWithoutPlugins->get('event_dispatcher')
            ->dispatch(new UpdatePreFinishEvent($context, $oldVersion, $newVersion));

        $this->runMigrations($input, $output);

        $updateEvent = new UpdatePostFinishEvent($context, $oldVersion, $newVersion);
        $this->container->get('event_dispatcher')->dispatch($updateEvent);

        $this->installAssets($input, $output);

        $output->writeln('');

        return 0;
    }

    private function runMigrations(InputInterface $input, OutputInterface $output): int
    {
        $command = $this->getApplication()->find('database:migrate');

        $arguments = [
            'identifier' => 'core',
            '--all'  => true,
        ];
        $arrayInput = new ArrayInput($arguments, $command->getDefinition());
        return $command->run($arrayInput, $output);
    }

    private function installAssets(InputInterface $input, OutputInterface $output): int
    {
        $command = $this->getApplication()->find('assets:install');
        return $command->run(new ArrayInput(['--no-cleanup' => true], $command->getDefinition()), $output);
    }

    private function rebootKernelWithoutPlugins(): ContainerInterface
    {
        /** @var Kernel $kernel */
        $kernel = $this->container->get('kernel');

        $classLoad = $kernel->getPluginLoader()->getClassLoader();
        $kernel->reboot(null, new StaticKernelPluginLoader($classLoad));

        return $kernel->getContainer();
    }
}
