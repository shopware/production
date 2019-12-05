<?php

declare(strict_types=1);

namespace Shopware\Production\Command;

use Shopware\Core\Framework\Adapter\Console\ShopwareStyle;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Plugin\KernelPluginLoader\StaticKernelPluginLoader;
use Shopware\Core\Framework\Update\Event\UpdatePostPrepareEvent;
use Shopware\Core\Framework\Update\Event\UpdatePrePrepareEvent;
use Shopware\Production\Kernel;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

class SystemUpdatePrepareCommand extends Command
{
    static public $defaultName = 'system:update:prepare';

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

        $output->writeln('Run Update preparations');

        $context = Context::createDefaultContext();
        $currentVersion = $this->container->getParameter('kernel.shopware_version');
        // TODO: get new version (from composer.lock?)
        $newVersion = '';

        $eventDispatcher = $this->container->get('event_dispatcher');
        $eventDispatcher->dispatch(new UpdatePrePrepareEvent($context, $currentVersion, $newVersion));

        $containerWithoutPlugins = $this->rebootKernelWithoutPlugins();

        /** @var EventDispatcherInterface $eventDispatcher */
        $eventDispatcher = $containerWithoutPlugins->get('event_dispatcher');

        // @internal plugins are deactivated
        $eventDispatcher->dispatch(new UpdatePostPrepareEvent($context, $currentVersion, $newVersion));

        return 0;
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
