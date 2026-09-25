<?php

declare(strict_types=1);

use PhpSoftBox\Resource\ResourcePayloadTransformerRegistry;
use PhpSoftBox\Resource\ResourcePayloadTransformerRegistryInterface;
use PhpSoftBox\Resource\ResourceSerializer;
use PhpSoftBox\Resource\ResourceSerializerInterface;
use Psr\Container\ContainerInterface;

use function PhpSoftBox\Container\factory;

return [
    ResourcePayloadTransformerRegistryInterface::class => factory(
        static fn (): ResourcePayloadTransformerRegistryInterface => new ResourcePayloadTransformerRegistry(),
    ),

    ResourceSerializerInterface::class => factory(
        static fn (ContainerInterface $container): ResourceSerializerInterface => new ResourceSerializer(
            registry: $container->get(ResourcePayloadTransformerRegistryInterface::class),
        ),
    ),

];
