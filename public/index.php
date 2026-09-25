<?php

declare(strict_types=1);

use Psr\Container\ContainerInterface;

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../config/bootstrap.php';

/** @var ContainerInterface $container */
$container = require __DIR__ . '/../config/container.php';

$app = (require __DIR__ . '/../config/app.php')($container);
$app->run();
