<?php

declare(strict_types=1);

namespace App\Feature\Fbo;

use RuntimeException;

/** External rpcStatus transport error, distinct from the framework's administrative API errors. */
final class SellerApiException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $status = 400,
        public readonly int $rpcCode = 3,
    ) {
        parent::__construct($message);
    }
}
