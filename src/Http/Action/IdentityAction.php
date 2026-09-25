<?php

declare(strict_types=1);

namespace App\Http\Action;

use App\Feature\Token\TokenIdentity;
use PhpSoftBox\Application\Response\JsonResponse;
use Psr\Http\Message\ServerRequestInterface;

final readonly class IdentityAction
{
    public function __invoke(ServerRequestInterface $request): JsonResponse
    {
        /** @var TokenIdentity $identity */
        $identity = $request->getAttribute(TokenIdentity::class);

        return new JsonResponse(['client_id' => $identity->clientId, 'audience' => $identity->audience]);
    }
}
