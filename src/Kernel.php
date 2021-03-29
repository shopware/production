<?php

declare(strict_types=1);

namespace Shopware\Production;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Plugin\KernelPluginLoader\KernelPluginLoader;
use Shopware\Core\Profiling\Doctrine\DebugStack;

/**
 * @psalm-suppress MethodSignatureMismatch
 */
class Kernel extends \Shopware\Core\Kernel
{
    public const PLACEHOLDER_DATABASE_URL = 'mysql://_placeholder.test';

    public function __construct(
        string $environment,
        bool $debug,
        KernelPluginLoader $pluginLoader,
        ?string $cacheId = null,
        ?string $version = self::SHOPWARE_FALLBACK_VERSION,
        ?Connection $connection = null
    ) {
        $cacheId = $cacheId ?? $environment;
        parent::__construct($environment, $debug, $pluginLoader, $cacheId, $version, $connection);
    }

    protected function initializeDatabaseConnectionVariables(): void
    {
        $url = $_ENV['DATABASE_URL']
            ?? $_SERVER['DATABASE_URL']
            ?? getenv('DATABASE_URL');

        if (isset($_SERVER['INSTALL']) || $url === self::PLACEHOLDER_DATABASE_URL) {
            return;
        }

        if ($this->getEnvironment() === 'dev') {
            self::getConnection()->getConfiguration()->setSQLLogger(new DebugStack());
        }

        $reflection = new \ReflectionMethod(\Shopware\Core\Kernel::class, 'initializeDatabaseConnectionVariables');
        if (!$reflection->isPrivate()) {
            parent::initializeDatabaseConnectionVariables();
        }
    }
}
