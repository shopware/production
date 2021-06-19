<?php declare(strict_types=1);

use Shopware\Production\HttpKernel;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\TerminableInterface;

$_SERVER['SCRIPT_FILENAME'] = __FILE__;

require_once __DIR__ . '/../vendor/autoload_runtime.php';

if (!file_exists(__DIR__ . '/../.env')) {
    $_SERVER['APP_RUNTIME_OPTIONS']['disable_dotenv'] = true;
}

return function (array $context) {
    if (\PHP_VERSION_ID < 70400) {
        header('Content-type: text/html; charset=utf-8', true, 503);

        echo '<h2>Error</h2>';
        echo 'Your server is running PHP version ' . \PHP_VERSION . ' but Shopware 6 requires at least PHP 7.4.0';
        exit(1);
    }

    $classLoader = require __DIR__ . '/../vendor/autoload.php';

    if (!file_exists(dirname(__DIR__) . '/install.lock')) {
        $basePath = 'recovery/install';
        $baseURL = str_replace(basename(__FILE__), '', $context['SCRIPT_FILENAME']);
        $baseURL = rtrim($baseURL, '/');
        $installerURL = $baseURL . '/' . $basePath . '/index.php';
        if (strpos($context['REQUEST_URI'], $basePath) === false) {
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

        exit;
    }

    $appEnv = $context['APP_ENV'] ?? 'dev';
    $debug = (bool) ($context['APP_DEBUG'] ?? ($appEnv !== 'prod'));

    $trustedProxies = $context['TRUSTED_PROXIES'] ?? false;
    if ($trustedProxies) {
        Request::setTrustedProxies(explode(',', $trustedProxies),
            Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PORT | Request::HEADER_X_FORWARDED_PROTO);
    }

    $trustedHosts = $context['TRUSTED_HOSTS'] ?? false;
    if ($trustedHosts) {
        Request::setTrustedHosts(explode(',', $trustedHosts));
    }

    $shopwareHttpKernel = new HttpKernel($appEnv, $debug, $classLoader);

    return new class ($shopwareHttpKernel) implements HttpKernelInterface, TerminableInterface
    {
        private HttpKernel $httpKernel;

        public function __construct(HttpKernel $httpKernel)
        {
            $this->httpKernel = $httpKernel;
        }

        public function handle(Request $request, int $type = self::MASTER_REQUEST, bool $catch = true)
        {
            return $this->httpKernel->handle($request, $type, $catch)->getResponse();
        }

        public function terminate(Request $request, Response $response)
        {
            $this->httpKernel->terminate($request, $response);
        }
    };
};
