<?php

declare(strict_types=1);

use PhpSoftBox\Validator\Validator;
use PhpSoftBox\Validator\ValidatorInterface;

use function PhpSoftBox\Container\factory;

return [
    ValidatorInterface::class => factory(static fn (): ValidatorInterface => new Validator()),
];
