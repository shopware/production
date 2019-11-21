<?php

declare(strict_types=1);

namespace Shopware\Production;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\FetchMode;
use Shopware\Core\Framework\Migration\MigrationStep;
use Shopware\Core\Framework\Plugin\KernelPluginLoader\KernelPluginLoader;
use Symfony\Component\DependencyInjection\ContainerAwareTrait;
use Symfony\Component\HttpKernel\Bundle\Bundle;

class Kernel extends \Shopware\Core\Kernel
{
    public const PLACEHOLDER_DATABASE_URL = 'mysql://_placeholder.test';

    public function __construct(
        string $environment,
        bool $debug,
        KernelPluginLoader $pluginLoader,
        ?string $cacheId = null,
        ?string $version = self::SHOPWARE_FALLBACK_VERSION
    ) {
        $cacheId = $cacheId ?? $environment;
        parent::__construct($environment, $debug, $pluginLoader, $cacheId, $version);
    }

    // TODO: remove
    public function boot(): void
    {
        if ($this->booted === true) {
            if ($this->debug) {
                $this->startTime = microtime(true);
            }

            return;
        }

        if ($this->debug) {
            $this->startTime = microtime(true);
        }

        if ($this->debug && !isset($_ENV['SHELL_VERBOSITY']) && !isset($_SERVER['SHELL_VERBOSITY'])) {
            putenv('SHELL_VERBOSITY=3');
            $_ENV['SHELL_VERBOSITY'] = 3;
            $_SERVER['SHELL_VERBOSITY'] = 3;
        }

        $this->pluginLoader->initializePlugins($this->getProjectDir());

        // init bundles
        $this->initializeBundles();

        // init container
        $this->initializeContainer();

        /** @var Bundle|ContainerAwareTrait $bundle */
        foreach ($this->getBundles() as $bundle) {
            $bundle->setContainer($this->container);
            $bundle->boot();
        }

        $this->initializeDatabaseConnectionVariables();

        $this->booted = true;
    }

    protected function initializeDatabaseConnectionVariables(): void
    {
        if (isset($_SERVER['INSTALL']) || $_SERVER['DATABASE_URL'] === self::PLACEHOLDER_DATABASE_URL) {
            return;
        }

        $reflection = new \ReflectionMethod(\Shopware\Core\Kernel::class, 'initializeDatabaseConnectionVariables');
        if (!$reflection->isPrivate()) {
            parent::initializeDatabaseConnectionVariables();
        }

        // TODO: remove
        /** @var Connection $connection */
        $connection = self::getConnection();

        $nonDestructiveMigrations = $connection->executeQuery('
            SELECT `creation_timestamp`
            FROM `migration`
            WHERE `update` IS NOT NULL AND `update_destructive` IS NULL
        ')->fetchAll(FetchMode::COLUMN);

        $activeMigrations = $this->container->getParameter('migration.active');

        $activeNonDestructiveMigrations = array_intersect($activeMigrations, $nonDestructiveMigrations);

        $connectionVariables = [
            'SET @@group_concat_max_len = CAST(IF(@@group_concat_max_len > 320000, @@group_concat_max_len, 320000) AS UNSIGNED)',
            "SET sql_mode=(SELECT REPLACE(@@sql_mode,'ONLY_FULL_GROUP_BY',''));",
        ];
        foreach ($activeNonDestructiveMigrations as $migration) {
            $connectionVariables[] = sprintf(
                'SET %s = TRUE',
                sprintf(MigrationStep::MIGRATION_VARIABLE_FORMAT, $migration)
            );
        }

        $connection->executeQuery(implode(';', $connectionVariables));
    }
}
