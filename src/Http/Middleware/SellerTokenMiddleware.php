<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Feature\Fbo\SellerApiException;
use App\Feature\Token\TokenAuthenticator;
use App\Feature\Token\TokenIdentity;
use PhpSoftBox\Application\Response\JsonResponse;
use PhpSoftBox\Validator\Exception\ValidationException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class SellerTokenMiddleware implements MiddlewareInterface
{
    public function __construct(
        private TokenAuthenticator
    $tokens,
    ) {
    }
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            $identity = $this->tokens->authenticate($request->getHeaderLine('Client-Id'), $request->getHeaderLine('Api-Key'));
            if ($identity === null) {
                throw new SellerApiException('Invalid Client-Id or Api-Key', 401, 16);
            }

            return $handler->handle($request->withAttribute(TokenIdentity::class, $identity));
        } catch (SellerApiException $exception) {
            $response = new JsonResponse(['code' => $exception->rpcCode, 'message' => $exception->getMessage(), 'details' => []], $exception->status);

            return $exception->status === 429 ? $response->withHeader('Retry-After', '1') : $response;
        } catch (ValidationException) {
            return new JsonResponse(['code' => 3, 'message' => 'Invalid request', 'details' => []], 400);
        }
    }
}
