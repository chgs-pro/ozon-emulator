<?php

declare(strict_types=1);

namespace App\Feature\Token\Command\IssueToken;

final readonly class IssueTokenCommand
{
    public function __construct(
        public string $clientId,
        public int $ttlDays = 30,
    ) {
    }
}
