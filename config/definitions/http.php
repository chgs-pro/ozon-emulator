<?php

declare(strict_types=1);

use PhpSoftBox\Application\ErrorHandler\ExceptionHandlerInterface;
use PhpSoftBox\Application\ErrorHandler\JsonExceptionHandler;
use PhpSoftBox\Application\Middleware\ErrorHandlerMiddleware;
use PhpSoftBox\Http\Emitter\EmitterInterface;
use PhpSoftBox\Http\Emitter\SapiEmitter;
use PhpSoftBox\Http\Message\ResponseFactory;
use PhpSoftBox\Http\Message\ServerRequestCreator;
use PhpSoftBox\Http\Message\StreamFactory;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

use function PhpSoftBox\Container\factory;
use function PhpSoftBox\Container\get;

return [
    ResponseFactoryInterface::class  => get(ResponseFactory::class),
    StreamFactoryInterface::class    => get(StreamFactory::class),
    ServerRequestCreator::class      => factory(static fn (): ServerRequestCreator => new ServerRequestCreator()),
    EmitterInterface::class          => factory(static fn (): EmitterInterface => new SapiEmitter()),
    ExceptionHandlerInterface::class => factory(static fn (ContainerInterface $container): ExceptionHandlerInterface => new JsonExceptionHandler(
        $container->get(ResponseFactory::class),
        $container->get(StreamFactory::class),
        includeDetails: false,
    )),
    ErrorHandlerMiddleware::class => factory(static fn (ContainerInterface $container): ErrorHandlerMiddleware => new ErrorHandlerMiddleware(
        $container->get(ExceptionHandlerInterface::class),
    )),
];
