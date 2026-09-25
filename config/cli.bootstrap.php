<?php

declare(strict_types=1);

use App\Cli\AppCommandProvider;
use PhpSoftBox\CliApp\CliApp;
use PhpSoftBox\CliApp\Command\InMemoryCommandRegistry;
use PhpSoftBox\CliApp\Config\PackageCommandDiscovery;
use PhpSoftBox\CliApp\Io\ConsoleIo;
use PhpSoftBox\CliApp\Loader\CommandProviderInterface;
use PhpSoftBox\CliApp\Loader\CommandScanner;
use PhpSoftBox\CliApp\Loader\SimpleCommandLoader;

$container = require __DIR__ . '/container.php';

$registry = new InMemoryCommandRegistry();

$scanner = new CommandScanner(new SimpleCommandLoader(), $registry);

$discovered = PackageCommandDiscovery::discover(__DIR__ . '/../vendor');

$paths = array_merge(
    array_filter([__DIR__ . '/../console', __DIR__ . '/../commands'], 'is_dir'),
    $discovered['paths'],
);

$files = $discovered['files'];

$scanner->register(paths: $paths, files: $files);

$providers = [
    ['class' => AppCommandProvider::class, 'priority' => 100],
    ...$discovered['providers'],
];

foreach ($providers as $provider) {
    $class    = $provider['class'] ?? '';
    $priority = (int) ($provider['priority'] ?? 0);
    if ($class === '') {
        continue;
    }

    if (method_exists($registry, 'addProvider')) {
        $registry->addProvider($class, $priority);
        continue;
    }

    if (!class_exists($class)) {
        continue;
    }

    $instance = new $class();

    if (!$instance instanceof CommandProviderInterface) {
        continue;
    }

    $instance->register($registry);
}

return new CliApp($registry, new ConsoleIo(), $container);
