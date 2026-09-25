<?php

declare(strict_types=1);

use App\Runtime\Environment;
use PhpSoftBox\Env\EnvStorage;

return [
    'env'   => Environment::detect()->value,
    'debug' => EnvStorage::value('APP_DEBUG')->bool(default: false) ?? false,
    'url'   => env('APP_URL', 'http://localhost:8080'),
];
