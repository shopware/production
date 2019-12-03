<?php declare(strict_types=1);

$rootDir = dirname(__DIR__, 3);

if (file_exists($rootDir . '/vendor/shopware/recovery/Update/index.php')) {
    require_once $rootDir . '/vendor/shopware/recovery/Update/index.php';
} else if (file_exists($rootDir . '/vendor/shopware/platform/src/Recovery/Update/index.php')) {
    require_once $rootDir . '/vendor/shopware/platform/src/Recovery/Update/index.php';
}
