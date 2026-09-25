<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Feature\Token\TokenAuthenticator;
use App\Feature\Token\TokenIdentity;
use PhpSoftBox\Api\Description\ApiDescription;
use PhpSoftBox\Api\Error\CommonApiErrorEnum;
use PhpSoftBox\Application\Exception\CodedHttpException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class ApiTokenMiddleware implements MiddlewareInterface
{
    public function __construct(
        private TokenAuthenticator $tokens,
        private ApiDescription $api,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $identity = $this->tokens->authenticate(
            $request->getHeaderLine('Client-Id'),
            $request->getHeaderLine('Api-Key'),
        );
        if ($identity === null) {
            $error = $this->api->errors->require(CommonApiErrorEnum::UNAUTHENTICATED);

            throw new CodedHttpException($error->status, $error->code, $error->description, title: $error->title);
        }

        return $handler->handle($request->withAttribute(TokenIdentity::class, $identity));
    }
}
