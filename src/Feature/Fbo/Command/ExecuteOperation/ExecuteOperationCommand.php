<?php

declare(strict_types=1);

namespace App\Feature\Fbo\Command\ExecuteOperation;

final readonly class ExecuteOperationCommand
{
    public function __construct(
        public string $clientId,
        public string $path,
        public array $input,
        public int $now,
        public ?string $cargoScenario = null,
    ) {
    }
}
