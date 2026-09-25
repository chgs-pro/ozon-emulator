<?php

declare(strict_types=1);

namespace App\Runtime;

use PhpSoftBox\Application\Contracts\EnvironmentEnumInterface;
use PhpSoftBox\Application\Contracts\EnvironmentPolicyInterface;

final readonly class EnvironmentPolicy implements EnvironmentPolicyInterface
{
    public function isDebugAvailableFor(EnvironmentEnumInterface $environment): bool
    {
        return $environment === Environment::DEV || $environment === Environment::DEMO;
    }

    public function isProductionLike(EnvironmentEnumInterface $environment): bool
    {
        return $environment === Environment::PROD;
    }
}
