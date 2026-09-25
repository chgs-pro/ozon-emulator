<?php

declare(strict_types=1);

namespace App\Feature\Fbo;

interface CabinetRepositoryInterface
{
    /** The callback may be retried and must have no external side effects. */
    public function change(string $clientId, callable $operation): mixed;
}
