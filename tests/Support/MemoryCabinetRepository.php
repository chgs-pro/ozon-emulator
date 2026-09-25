<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Feature\Fbo\CabinetRepositoryInterface;
use App\Feature\Fbo\CabinetState;

final class MemoryCabinetRepository implements CabinetRepositoryInterface
{
    public array $data = [];
    public function change(string $clientId, callable $operation): mixed
    {
        $state = new CabinetState($this->data[$clientId] ?? []);

        $result                = $operation($state);
        $this->data[$clientId] = $state->data;

        return $result;
    }
}
