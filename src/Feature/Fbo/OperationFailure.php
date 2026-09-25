<?php

declare(strict_types=1);

namespace App\Feature\Fbo;

use RuntimeException;

final class OperationFailure extends RuntimeException
{
    public function __construct(
        public readonly string $reason,
        public readonly array $details = [],
    ) {
        parent::__construct($reason);
    }
}
