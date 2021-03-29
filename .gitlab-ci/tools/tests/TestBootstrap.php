<?php declare(strict_types=1);

include __DIR__ . '/../vendor/autoload.php';

use Symfony\Component\Dotenv\Dotenv;

if (is_readable(__DIR__ . '/../.env.test')) {
    (new Dotenv())->load(__DIR__ . '/../.env.test');
}

if (!isset($_SERVER['PROJECT_ROOT'])) {
    $_SERVER['PROJECT_ROOT'] = dirname(__DIR__, 3);
}
