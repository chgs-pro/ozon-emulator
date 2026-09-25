<?php

declare(strict_types=1);

use App\Path;
use PhpSoftBox\Config\Config;
use PhpSoftBox\Config\Path\PathInterface;
use Psr\Container\ContainerInterface;

use function PhpSoftBox\Container\factory;
use function PhpSoftBox\Container\get;

return [
    PathInterface::class => factory(static function (ContainerInterface $container): PathInterface {
        $baseDir = $container->get(Config::class)->baseDir() ?? dirname(__DIR__, 2);

        return new Path($baseDir);
    }),

    Path::class => get(PathInterface::class),
];
