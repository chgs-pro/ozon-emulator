<?php

declare(strict_types=1);

namespace App\Feature\Token;

final readonly class TokenIdentity
{
    public function __construct(
        public string $clientId,
        public string $audience,
        public int $expiresAt = 0,
    ) {
    }
}
