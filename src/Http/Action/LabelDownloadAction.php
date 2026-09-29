<?php

declare(strict_types=1);

namespace App\Http\Action;

use App\Feature\Fbo\LabelService;
use App\Feature\Fbo\SellerApiException;
use PhpSoftBox\Application\Response\JsonResponse;
use PhpSoftBox\Http\Message\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

use function is_string;

final readonly class LabelDownloadAction
{
    public function __construct(
        private LabelService
    $labels,
    ) {
    }
    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $input = $request->getQueryParams();
        try {
            foreach (['client_id', 'document_id', 'token'] as $field) {
                if (!is_string($input[$field] ?? null)) {
                    throw new SellerApiException('Document not found', 404, 5);
                }
            }
            $pdf = $this->labels->download($input['client_id'], $input['document_id'], $input['token']);

            return new Response(status: 200, headers: ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="ozon-local-labels.pdf"', 'Cache-Control' => 'private, no-store', 'Referrer-Policy' => 'no-referrer'], body: $pdf);
        } catch (SellerApiException $error) {
            return new JsonResponse(['code' => $error->rpcCode, 'message' => $error->getMessage(), 'details' => []], status: $error->status);
        }
    }
}
