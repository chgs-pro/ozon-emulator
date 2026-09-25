<?php

declare(strict_types=1);

use App\Feature\Fbo\CabinetRepositoryInterface;
use App\Feature\Fbo\MongoCabinetRepository;
use App\Feature\Token\MongoTokenRepository;
use App\Feature\Token\TokenRepositoryInterface;
use PhpSoftBox\Api\Description\ApiDescription;
use PhpSoftBox\Api\Error\CommonApiErrorEnum;
use PhpSoftBox\Clock\SystemClock;
use PhpSoftBox\Config\Config;
use PhpSoftBox\MongoDb\Configurator\MongoFactory;
use PhpSoftBox\MongoDb\Connection\MongoConnectionManager;
use PhpSoftBox\MongoDb\Connection\MongoConnectionManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Container\ContainerInterface;

use function PhpSoftBox\Container\factory;
use function PhpSoftBox\Container\get;

return [
    ApiDescription::class => factory(static fn (): ApiDescription => ApiDescription::create('ozon-seller')
        ->withCommonErrors(CommonApiErrorEnum::cases())->build()),
    ClockInterface::class                  => get(SystemClock::class),
    MongoConnectionManagerInterface::class => factory(static fn (ContainerInterface $container): MongoConnectionManagerInterface => new MongoConnectionManager(
        new MongoFactory((array) $container->get(Config::class)->get('mongo', [])),
    )),
    TokenRepositoryInterface::class   => get(MongoTokenRepository::class),
    CabinetRepositoryInterface::class => get(MongoCabinetRepository::class),
];
