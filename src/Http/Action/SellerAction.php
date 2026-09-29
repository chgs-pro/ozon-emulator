<?php

declare(strict_types=1);

namespace App\Http\Action;

use App\Feature\Fbo\Contract;
use App\Feature\Fbo\SellerApiService;
use App\Feature\Token\TokenIdentity;
use App\Http\Request\SellerRequestSchema;
use PhpSoftBox\Application\Response\JsonResponse;
use PhpSoftBox\Http\Message\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

use function base64_decode;

final readonly class SellerAction
{
    public function __construct(
        private SellerApiService
    $service,
        private Contract $contract,
    ) {
    }
    public function __invoke(ServerRequestInterface $request, SellerRequestSchema $schema): ResponseInterface
    {
        $schema->validate();
        $path     = $request->getUri()->getPath();
        $response = $this->service->execute($request->getAttribute(TokenIdentity::class), $path, $schema->getArray('input'), $schema->cargoScenario());
        $type     = $this->contract->fileContentType($path);
        if ($type === null) {
            return new JsonResponse($response);
        }

        // A file method answers with the file itself; the service returns it as the object the SDK describes.
        return new Response(status: 200, headers: [
            'Content-Type'        => (string) ($response['content_type'] ?? $type),
            'Content-Disposition' => 'inline; filename="' . ($response['file_name'] ?? 'document.pdf') . '"',
            'Cache-Control'       => 'private, no-store',
        ], body: (string) base64_decode((string) ($response['file_content'] ?? ''), true));
    }
}
