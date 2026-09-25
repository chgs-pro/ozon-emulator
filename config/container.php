<?php

declare(strict_types=1);

use App\Path;
use App\Runtime\Environment;
use PhpSoftBox\Container\ContainerBuilder;

require_once __DIR__ . '/bootstrap.php';

$builder = new ContainerBuilder();

$builder->useAutowiring(true);
$builder->useAttributes(false);
$builder->addDefinitions(require __DIR__ . '/dependencies.php');
if (Environment::detect() === Environment::PROD) {
    $builder->enableCompilation(new Path(dirname(__DIR__))->cachePath('di'));
}

return $builder->build();
