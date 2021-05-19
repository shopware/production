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
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

class SystemUpdatePrepareCommand extends Command
{
    public static $defaultName = 'system:update:prepare';

    private string $shopwareVersion;

    private EventDispatcherInterface $eventDispatcher;

    private ContainerInterface $container;

    /**
     * @psalm-suppress ContainerDependency
     */
    public function __construct(
        string $shopwareVersion,
        EventDispatcherInterface $eventDispatcher,
        ContainerInterface $container
    ) {
        parent::__construct();
        $this->shopwareVersion = $shopwareVersion;
        $this->eventDispatcher = $eventDispatcher;
        $this->container = $container;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output = new ShopwareStyle($input, $output);

        $dsn = trim((string) ($_SERVER['DATABASE_URL'] ?? getenv('DATABASE_URL')));
        if ($dsn === '' || $dsn === Kernel::PLACEHOLDER_DATABASE_URL) {
            $output->note("Environment variable 'DATABASE_URL' not defined. Skipping " . $this->getName() . '...');

            return 0;
        }

        $output->writeln('Run Update preparations');

        $context = Context::createDefaultContext();
        // TODO: get new version (from composer.lock?)
        $newVersion = '';

        $this->eventDispatcher->dispatch(new UpdatePrePrepareEvent($context, $this->shopwareVersion, $newVersion));

        /** @var EventDispatcherInterface $eventDispatcherWithoutPlugins */
        $eventDispatcherWithoutPlugins = $this->rebootKernelWithoutPlugins()->get('event_dispatcher');

        // @internal plugins are deactivated
        $eventDispatcherWithoutPlugins->dispatch(new UpdatePostPrepareEvent($context, $this->shopwareVersion, $newVersion));

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
