<?php

declare(strict_types=1);

namespace App\Feature\Token;

final readonly class TokenRecord
{
    public function __construct(
        public string $selector,
        public string $clientId,
        public string $secretHash,
        public string $audience,
        public int $issuedAt,
        public int $expiresAt,
    ) {
    }
}
