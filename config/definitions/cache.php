<?php

declare(strict_types=1);

use App\Path;
use PhpSoftBox\Cache\Cache;
use PhpSoftBox\Cache\Configurator\BuiltInDriverFactory;
use PhpSoftBox\Cache\Configurator\CacheConfig;
use PhpSoftBox\Cache\Configurator\CacheStoreFactory;
use PhpSoftBox\Cache\Configurator\CacheStoreFactoryInterface;
use PhpSoftBox\Cache\Configurator\DriverFactoryInterface;
use PhpSoftBox\Cache\Contracts\CacheServiceInterface;
use Psr\Container\ContainerInterface;
use Psr\SimpleCache\CacheInterface;

use function PhpSoftBox\Container\create;
use function PhpSoftBox\Container\factory;
use function PhpSoftBox\Container\get;

return [
    BuiltInDriverFactory::class => create(BuiltInDriverFactory::class),

    CacheStoreFactoryInterface::class => factory(static function (ContainerInterface $container): CacheStoreFactoryInterface {
        $path = $container->get(Path::class);

        $stores = [
            'default' => new CacheConfig(
                driver: 'file',
                namespace: 'app',
                options: [
                    'directory' => $path->cachePath(),
                ],
            ),
        ];

        /** @var DriverFactoryInterface[] $driverFactories */
        $driverFactories = [
            $container->get(BuiltInDriverFactory::class),
        ];

        return new CacheStoreFactory(
            stores: $stores,
            driverFactories: $driverFactories,
        );
    }),

    Cache::class => factory(static function (ContainerInterface $container): Cache {
        return new Cache(
            storeFactory: $container->get(CacheStoreFactoryInterface::class),
            defaultStore: 'default',
        );
    }),

    CacheServiceInterface::class => get(Cache::class),
    CacheInterface::class        => get(Cache::class),
];
