<?php

use Doctrine\DBAL\Exception\ConnectionException;
use PackageVersions\Versions;
use Shopware\Core\Framework\Event\BeforeSendResponseEvent;
use Shopware\Core\Framework\Plugin\KernelPluginLoader\DbalKernelPluginLoader;
use Shopware\Core\Framework\Routing\RequestTransformerInterface;
use Shopware\Production\Kernel;
use Shopware\Storefront\Framework\Cache\CacheStore;
use Symfony\Component\Debug\Debug;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpCache\HttpCache;

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

// resolve SEO urls
$request = Request::createFromGlobals();
$connection = Kernel::getConnection();

if ($appEnv === 'dev') {
    $connection->getConfiguration()->setSQLLogger(
        new \Shopware\Core\Profiling\Doctrine\DebugStack()
    );
}

try {
    $shopwareVersion = Versions::getVersion('shopware/core');

    $pluginLoader = new DbalKernelPluginLoader($classLoader, null, $connection);

    $kernel = new Kernel($appEnv, $debug, $pluginLoader, $_SERVER['SW_CACHE_ID'] ?? null, $shopwareVersion);
    $kernel->boot();

    $container = $kernel->getContainer();

    // resolves seo urls and detects storefront sales channels
    $request = $container
        ->get(RequestTransformerInterface::class)
        ->transform($request);

    $enabled = $container->getParameter('shopware.http.cache.enabled');
    if ($enabled) {
        $store = $container->get(CacheStore::class);

        $kernel = new HttpCache($kernel, $store, null, ['debug' => $debug]);
    }

    $response = $kernel->handle($request);

    $event = new BeforeSendResponseEvent($request, $response);
    $container->get('event_dispatcher')
        ->dispatch($event);

    $response = $event->getResponse();

} catch (ConnectionException $e) {
    throw new RuntimeException($e->getMessage());
}

$response->send();
$kernel->terminate($request, $response);
