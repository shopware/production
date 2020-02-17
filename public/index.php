<?php

use Doctrine\DBAL\DBALException;
use PackageVersions\Versions;
use Shopware\Core\Framework\Adapter\Cache\CacheIdLoader;
use Shopware\Core\Framework\Event\BeforeSendResponseEvent;
use Shopware\Core\Framework\Plugin\KernelPluginLoader\DbalKernelPluginLoader;
use Shopware\Core\Framework\Routing\RequestTransformerInterface;
use Shopware\Production\HttpKernel;
use Shopware\Production\Kernel;
use Shopware\Storefront\Framework\Cache\CacheStore;
use Symfony\Component\Debug\Debug;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpCache\HttpCache;
use Doctrine\DBAL\Connection;

if (PHP_VERSION_ID < 70200) {
    header('Content-type: text/html; charset=utf-8', true, 503);

    echo '<h2>Error</h2>';
    echo 'Your server is running PHP version ' . PHP_VERSION . ' but Shopware 6 requires at least PHP 7.2.0';
    exit();
}

$classLoader = require __DIR__.'/../vendor/autoload.php';

if (!file_exists(dirname(__DIR__) . '/install.lock')) {
    $basePath = 'recovery/install';
    $baseURL = str_replace(basename(__FILE__), '', $_SERVER['SCRIPT_NAME']);
    $baseURL = rtrim($baseURL, '/');
    $installerURL = $baseURL . '/' . $basePath . '/index.php';
    if (strpos($_SERVER['REQUEST_URI'], $basePath) === false) {
        header('Location: ' . $installerURL);
        exit;
    }
}

if (is_file(dirname(__DIR__) . '/files/update/update.json') || is_dir(dirname(__DIR__) . '/update-assets')) {
    header('Content-type: text/html; charset=utf-8', true, 503);
    header('Status: 503 Service Temporarily Unavailable');
    header('Retry-After: 1200');
    if (file_exists(__DIR__ . '/maintenance.html')) {
        readfile(__DIR__ . '/maintenance.html');
    } else {
        readfile(__DIR__ . '/recovery/update/maintenance.html');
    }

    return;
}

// The check is to ensure we don't use .env if APP_ENV is defined
if (!isset($_SERVER['APP_ENV']) && !isset($_ENV['APP_ENV'])) {
    if (!class_exists(Dotenv::class)) {
        throw new \RuntimeException('APP_ENV environment variable is not defined. You need to define environment variables for configuration or add "symfony/dotenv" as a Composer dependency to load variables from a .env file.');
    }
    $envFile = __DIR__.'/../.env';
    if (file_exists($envFile)) {
        (new Dotenv(true))->load($envFile);
    }
}

$appEnv = $_SERVER['APP_ENV'] ?? $_ENV['APP_ENV'] ?? 'dev';
$debug = (bool) ($_SERVER['APP_DEBUG'] ?? $_ENV['APP_DEBUG'] ?? ('prod' !== $appEnv));

if ($debug) {
    umask(0000);

    Debug::enable();
}

if ($trustedProxies = $_SERVER['TRUSTED_PROXIES'] ?? $_ENV['TRUSTED_PROXIES'] ?? false) {
    Request::setTrustedProxies(explode(',', $trustedProxies), Request::HEADER_X_FORWARDED_ALL ^ Request::HEADER_X_FORWARDED_HOST);
}

if ($trustedHosts = $_SERVER['TRUSTED_HOSTS'] ?? $_ENV['TRUSTED_HOSTS'] ?? false) {
    Request::setTrustedHosts(explode(',', $trustedHosts));
}

$request = Request::createFromGlobals();

$kernel = new HttpKernel($appEnv, $debug, $classLoader);
$result = $kernel->handle($request);

$result->getResponse()->send();

$kernel->terminate($result->getRequest(), $result->getResponse());
