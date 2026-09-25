<?php

declare(strict_types=1);

namespace App\Feature\Token;

interface TokenRepositoryInterface
{
    public function insert(TokenRecord $token): void;

    public function find(string $selector): ?TokenRecord;
}
