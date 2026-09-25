<?php

declare(strict_types=1);

namespace App\Http\Action;

use App\Support\JsonResponder;
use PhpSoftBox\Application\Response\JsonResponse;

final readonly class HealthAction
{
    public function __construct(
        private JsonResponder $responses,
    ) {
    }

    public function __invoke(): JsonResponse
    {
        return $this->responses->success([
            'status' => 'ok',
        ]);
    }
}
