<?php

declare(strict_types=1);

namespace App\Http\Action;

use App\Feature\Fbo\SellerApiService;
use App\Feature\Token\TokenIdentity;
use App\Http\Request\SellerRequestSchema;
use PhpSoftBox\Application\Response\JsonResponse;
use Psr\Http\Message\ServerRequestInterface;

final readonly class SellerAction
{
    public function __construct(
        private SellerApiService
    $service,
    ) {
    }
    public function __invoke(ServerRequestInterface $request, SellerRequestSchema $schema): JsonResponse
    {
        $schema->validate();

        return new JsonResponse($this->service->execute($request->getAttribute(TokenIdentity::class), $request->getUri()->getPath(), $schema->getArray('input'), $schema->cargoScenario()));
    }
}
