<?php

declare(strict_types=1);

namespace App\Feature\Token;

use PhpSoftBox\MongoDb\Connection\MongoConnectionManagerInterface;

final readonly class MongoTokenRepository implements TokenRepositoryInterface
{
    public function __construct(
        private MongoConnectionManagerInterface
    $mongo,
    ) {
    }

    public function insert(TokenRecord $token): void
    {
        // MongoDB's unique _id is the credential selector; plaintext keys are never stored.
        $this->mongo->collection('api_tokens')->insertOne([
            '_id'         => $token->selector,
            'client_id'   => $token->clientId,
            'secret_hash' => $token->secretHash,
            'audience'    => $token->audience,
            'issued_at'   => $token->issuedAt,
            'expires_at'  => $token->expiresAt,
        ]);
    }

    public function find(string $selector): ?TokenRecord
    {
        $document = $this->mongo->collection('api_tokens')->findOne(['_id' => $selector]);
        if ($document === null) {
            return null;
        }

        return new TokenRecord(
            (string) $document['_id'],
            (string) $document['client_id'],
            (string) $document['secret_hash'],
            (string) $document['audience'],
            (int) $document['issued_at'],
            (int) $document['expires_at'],
        );
    }
}
