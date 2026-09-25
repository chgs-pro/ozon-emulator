<?php

declare(strict_types=1);

namespace App\Feature\Token;

use PhpSoftBox\Api\Description\ApiDescription;
use PhpSoftBox\Auth\Token\CredentialCodec;
use Psr\Clock\ClockInterface;
use SensitiveParameter;

final readonly class TokenAuthenticator
{
    public function __construct(
        private TokenRepositoryInterface $tokens,
        private CredentialCodec $codec,
        private ApiDescription $api,
        private ClockInterface $clock,
    ) {
    }

    public function authenticate(string $clientId, #[SensitiveParameter] string $apiKey): ?TokenIdentity
    {
        $parsed = $this->codec->parse($apiKey);
        if ($parsed === null || $clientId === '') {
            return null;
        }
        $token = $this->tokens->find($parsed->selector);
        if ($token === null || $token->clientId !== $clientId || $token->audience !== $this->api->id
            || $token->expiresAt <= $this->clock->now()->getTimestamp()
            || !$this->codec->verify($parsed->secret, $token->secretHash)) {
            return null;
        }

        return new TokenIdentity($token->clientId, $token->audience, $token->expiresAt);
    }
}
