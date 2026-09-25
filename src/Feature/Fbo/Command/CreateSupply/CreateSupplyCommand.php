<?php

declare(strict_types=1);

namespace App\Feature\Fbo\Command\CreateSupply;

final readonly class CreateSupplyCommand
{
    public function __construct(
        public array $input,
        public int $now,
    ) {
    }
}
