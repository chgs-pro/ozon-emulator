<?php

declare(strict_types=1);

namespace App\Support;

use PhpSoftBox\Application\Response\JsonResponse;
use PhpSoftBox\Resource\ApiResponse;
use PhpSoftBox\Resource\ResourceSerializerInterface;

final readonly class JsonResponder
{
    public function __construct(
        private ResourceSerializerInterface $serializer,
    ) {
    }

    /**
     * @param array<string, mixed> $meta
     */
    public function success(mixed $data = null, int $status = 200, array $meta = []): JsonResponse
    {
        return new JsonResponse(ApiResponse::success($data, $meta, $this->serializer)->toArray(), $status);
    }

    /**
     * @param array<string, list<string>|string> $fields
     * @param array<string, mixed> $meta
     */
    public function error(
        string $message,
        array $fields = [],
        int $status = 400,
        array $meta = [],
        ?string $code = null,
    ): JsonResponse {
        return new JsonResponse(ApiResponse::error(
            $message,
            $fields,
            $meta,
            $code,
            $this->serializer,
        )->toArray(), $status);
    }
}
