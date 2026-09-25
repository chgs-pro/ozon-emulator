<?php

declare(strict_types=1);

namespace App\Feature\Fbo\Command\ConfigureCabinet;

final readonly class ConfigureCabinetCommand
{
    public function __construct(
        public string $clientId,
        public array $configuration,
    ) {
    }
}
