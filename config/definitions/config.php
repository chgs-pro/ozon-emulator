<?php

declare(strict_types=1);

use App\Runtime\Environment;
use App\Runtime\EnvironmentPolicy;
use PhpSoftBox\Application\ApplicationEnvironment;
use PhpSoftBox\Config\Config;
use PhpSoftBox\Config\ConfigFactory;
use PhpSoftBox\Config\Provider\PhpFileDataProvider;
use PhpSoftBox\Env\EnvStorage;
use Psr\Container\ContainerInterface;

use function PhpSoftBox\Container\factory;

return [
    ApplicationEnvironment::class => factory(static function (): ApplicationEnvironment {
        return new ApplicationEnvironment(
            current: Environment::detect(),
            policy: new EnvironmentPolicy(),
            debugRequested: EnvStorage::value('APP_DEBUG')->bool(default: false) ?? false,
        );
    }),

    ConfigFactory::class => factory(static function (ContainerInterface $container): ConfigFactory {
        $environment = $container->get(ApplicationEnvironment::class);
        $baseDir     = dirname(__DIR__, 2);
        $configDir   = $baseDir . '/config/app';

        return new ConfigFactory(
            environment: $environment->value(),
            baseDir: $baseDir,
            providers: [
                new PhpFileDataProvider($configDir . '/*.php'),
                new PhpFileDataProvider($configDir . '/' . $environment->value() . '/*.php'),
            ],
        );
    }),

    Config::class => factory(static fn (ContainerInterface $container): Config => $container->get(ConfigFactory::class)->create()),
];
