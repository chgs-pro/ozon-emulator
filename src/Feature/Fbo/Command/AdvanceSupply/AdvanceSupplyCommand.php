<?php

declare(strict_types=1);

namespace App\Feature\Fbo\Command\AdvanceSupply;

final readonly class AdvanceSupplyCommand
{
    public function __construct(
        public int
    $now)
    {
    }
}
