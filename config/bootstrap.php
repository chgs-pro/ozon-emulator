<?php

declare(strict_types=1);

use PhpSoftBox\Env\Environment;

$envPath = __DIR__ . '/env';
if (is_dir($envPath)) {
    Environment::create($envPath)
        ->setEnvironment($_ENV['APP_ENV'] ?? $_SERVER['APP_ENV'] ?? null)
        ->safeLoad();
}
