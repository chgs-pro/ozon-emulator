<?php

declare(strict_types=1);

use PhpSoftBox\Application\AppFactory;
use PhpSoftBox\Application\ApplicationEnvironment;
use PhpSoftBox\Env\Variables;
use PhpSoftBox\Router\RouteCollectorFactoryInterface;
use Psr\Container\ContainerInterface;

return static function (ContainerInterface $container) {
    if ($container->has(Variables::class)) {
        $container->get(Variables::class);
    }

    $environment   = $container->get(ApplicationEnvironment::class)->value();
    $routesFactory = $container->has(RouteCollectorFactoryInterface::class)
        ? $container->get(RouteCollectorFactoryInterface::class)
        : null;

    $app = AppFactory::createFromContainer(
        $container,
        environment: $environment,
        routesFactory: $routesFactory,
        routesPath: $routesFactory === null ? __DIR__ . '/routes' : null,
    );

    $app->registerMiddlewareFromFile(__DIR__ . '/middleware.php');
    $app->registerErrorHandlerMiddleware();

    return $app;
};
