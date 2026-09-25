<?php

declare(strict_types=1);

namespace App\Feature\Token\Command\IssueToken;

use App\Feature\Token\TokenRecord;
use App\Feature\Token\TokenRepositoryInterface;
use InvalidArgumentException;
use PhpSoftBox\Api\Description\ApiDescription;
use PhpSoftBox\Auth\Token\CredentialCodec;
use Psr\Clock\ClockInterface;

use function preg_match;

final readonly class IssueTokenHandler
{
    public function __construct(
        private TokenRepositoryInterface $tokens,
        private CredentialCodec $codec,
        private ApiDescription $api,
        private ClockInterface $clock,
    ) {
    }

    public function handle(IssueTokenCommand $command): IssuedToken
    {
        if (preg_match('/^[1-9][0-9]{0,18}$/D', $command->clientId) !== 1) {
            throw new InvalidArgumentException('Client ID must be a positive decimal identifier (up to 19 digits).');
        }
        if ($command->ttlDays < 1 || $command->ttlDays > 365) {
            throw new InvalidArgumentException('Token lifetime must be between 1 and 365 days.');
        }

        $credential = $this->codec->generate();
        $issuedAt   = $this->clock->now()->getTimestamp();
        $expiresAt  = $issuedAt + $command->ttlDays * 86400;
        $this->tokens->insert(new TokenRecord(
            $credential->selector,
            $command->clientId,
            $credential->secretHash,
            $this->api->id,
            $issuedAt,
            $expiresAt,
        ));

        return new IssuedToken($command->clientId, $credential->token, $expiresAt);
    }
}
