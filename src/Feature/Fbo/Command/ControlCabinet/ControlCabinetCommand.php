<?php

declare(strict_types=1);

namespace App\Feature\Fbo\Command\ControlCabinet;

final readonly class ControlCabinetCommand
{
    public function __construct(
        public string $clientId,
        public array $event,
    ) {
    }
}
