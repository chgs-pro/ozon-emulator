<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use JsonException;
use PhpSoftBox\Application\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use stdClass;

use function json_decode;
use function preg_match;
use function strlen;

use const JSON_THROW_ON_ERROR;

/** Decode Seller bodies before the generic application's body parser. */
final readonly class SellerJsonMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (preg_match('~^/v[0-9]+/~', $request->getUri()->getPath()) !== 1) {
            return $handler->handle($request);
        }
        $raw = (string) $request->getBody();
        if (strlen($raw) > 2000000) {
            return new JsonResponse(['code' => 8, 'message' => 'Request body too large', 'details' => []], 413);
        }
        try {
            $object = json_decode($raw === '' ? '{}' : $raw, flags: JSON_THROW_ON_ERROR);
            if (!$object instanceof stdClass) {
                throw new JsonException('Object required');
            }
            $body = json_decode($raw === '' ? '{}' : $raw, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return new JsonResponse(['code' => 3, 'message' => 'JSON object required', 'details' => []], 400);
        }

        return $handler->handle($request->withParsedBody($body));
    }
}
