<?php

declare(strict_types=1);

use PhpSoftBox\Application\ApplicationEnvironment;
use PhpSoftBox\Application\Middleware\KernelRouteMiddlewareResolver;
use PhpSoftBox\Application\Middleware\MiddlewareManager;
use PhpSoftBox\Config\Path\PathInterface;
use PhpSoftBox\Router\Cache\RouteCache;
use PhpSoftBox\Router\Dispatcher;
use PhpSoftBox\Router\Handler\ContainerHandlerResolver;
use PhpSoftBox\Router\Handler\HandlerResolverInterface;
use PhpSoftBox\Router\Middleware\RouteMiddlewareResolverInterface;
use PhpSoftBox\Router\RouteCollector;
use PhpSoftBox\Router\RouteCollectorFactory;
use PhpSoftBox\Router\RouteCollectorFactoryInterface;
use Psr\Container\ContainerInterface;
use Psr\SimpleCache\CacheInterface;

use function PhpSoftBox\Container\factory;

return [
    RouteCache::class => factory(static function (ContainerInterface $container): RouteCache {
        return new RouteCache(
            cache: $container->get(CacheInterface::class),
            ttl: 3600,
            environment: $container->get(ApplicationEnvironment::class)->value(),
        );
    }),

    MiddlewareManager::class => factory(static fn (): MiddlewareManager => new MiddlewareManager()),

    HandlerResolverInterface::class => factory(static function (ContainerInterface $container): HandlerResolverInterface {
        return new ContainerHandlerResolver($container);
    }),

    RouteMiddlewareResolverInterface::class => factory(static function (ContainerInterface $container): RouteMiddlewareResolverInterface {
        return new KernelRouteMiddlewareResolver(
            $container->get(MiddlewareManager::class),
            $container,
        );
    }),

    Dispatcher::class => factory(static function (ContainerInterface $container): Dispatcher {
        return new Dispatcher(
            $container->get(HandlerResolverInterface::class),
            $container->get(RouteMiddlewareResolverInterface::class),
        );
    }),

    RouteCollectorFactoryInterface::class => factory(static function (ContainerInterface $container): RouteCollectorFactoryInterface {
        $routesPath = $container->get(PathInterface::class)->createPath('config/routes');

        return new RouteCollectorFactory($routesPath);
    }),

    RouteCollector::class => factory(static function (ContainerInterface $container): RouteCollector {
        return $container->get(RouteCollectorFactoryInterface::class)->create();
    }),
];
