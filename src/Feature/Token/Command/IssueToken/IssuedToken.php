<?php

declare(strict_types=1);

namespace App\Feature\Token\Command\IssueToken;

final readonly class IssuedToken
{
    public function __construct(
        public string $clientId,
        public string $apiKey,
        public int $expiresAt,
    ) {
    }
}
