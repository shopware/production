<?php declare(strict_types=1);

use DG\BypassFinals;
use Shopware\Core\Framework\Test\TestCaseBase\KernelLifecycleManager;
use Shopware\Core\Kernel;
use Symfony\Component\Dotenv\Dotenv;

$_ENV['PROJECT_ROOT'] = $_SERVER['PROJECT_ROOT'] = $_SERVER['PROJECT_ROOT'] ?? dirname(__DIR__);
define('TEST_PROJECT_DIR', $_SERVER['PROJECT_ROOT']);

$testEnv = [
    'APP_ENV' => 'test',
    'APP_DEBUG' => 1,
    'APP_SECRET' => 's$cretf0rt3st',
    'KERNEL_CLASS' => Kernel::class,
    'SHOPWARE_ES_ENABLED' => '',
    'BLUE_GREEN_DEPLOYMENT' => 1,
    'SHOPWARE_ES_INDEXING_ENABLED' => '',
    'JWT_PRIVATE_KEY_PASSPHRASE' => 'shopware',
    'VERSION' => $_SERVER['VERSION'] ?? $_SERVER['BUILD_VERSION'] ?? 'v6.1.0',
];

foreach ($testEnv as $key => $value) {
    $_ENV[$key] = $_SERVER[$key] = $value;
}

$jwtDir = TEST_PROJECT_DIR . '/var/test/jwt';

if (!file_exists($jwtDir) && !mkdir($jwtDir, 0770, true) && !is_dir($jwtDir)) {
    throw new \RuntimeException(sprintf('Directory "%s" was not created', $jwtDir));
}

// generate jwt pk
$key = openssl_pkey_new([
    'digest_alg' => 'aes256',
    'private_key_type' => \OPENSSL_KEYTYPE_RSA,
    'encrypt_key_cipher' => \OPENSSL_CIPHER_AES_256_CBC,
    'encrypt_key' => $_SERVER['JWT_PRIVATE_KEY_PASSPHRASE'],
]);

// export private key
$result = openssl_pkey_export_to_file($key, $jwtDir . '/private.pem');
if ($result === false) {
    throw new RuntimeException('Could not export private key to file');
}

// export public key
$keyData = openssl_pkey_get_details($key);
file_put_contents($jwtDir . '/public.pem', $keyData['key']);

chmod($jwtDir . '/private.pem', 0660);
chmod($jwtDir . '/public.pem', 0660);

$loader = require TEST_PROJECT_DIR . '/vendor/autoload.php';

if (class_exists(BypassFinals::class)) {
    BypassFinals::enable();
}

KernelLifecycleManager::prepare($loader);

if (file_exists(TEST_PROJECT_DIR . '/.env.test')) {
    if (!class_exists(Dotenv::class)) {
        throw new RuntimeException('APP_ENV environment variable is not defined. You need to define environment variables for configuration or add "symfony/dotenv" as a Composer dependency to load variables from a .env.test file.');
    }
    (new Dotenv())
        ->usePutenv(true)
        ->load(TEST_PROJECT_DIR . '/.env.test');
}
