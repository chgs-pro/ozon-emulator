<?php

declare(strict_types=1);

use App\Runtime\Environment;
use PhpSoftBox\Config\ConfigFactory;
use PhpSoftBox\Config\Provider\PhpFileDataProvider;

$environment = Environment::detect();

$factory = new ConfigFactory(
    environment: $environment->value,
    providers: [
        new PhpFileDataProvider(__DIR__ . '/definitions/*.php', keyByFilename: false),
        new PhpFileDataProvider(__DIR__ . '/definitions/' . $environment->value . '/*.php', keyByFilename: false),
    ],
);

return $factory->getMergedConfig();
