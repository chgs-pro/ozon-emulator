<?php

declare(strict_types=1);

namespace App\Feature\Fbo\Command\AdvanceOperations;

final readonly class AdvanceOperationsCommand
{
    public function __construct(
        public string $clientId,
        public int $now,
    ) {
    }
}
