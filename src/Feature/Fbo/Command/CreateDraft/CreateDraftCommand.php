<?php

declare(strict_types=1);

namespace App\Feature\Fbo\Command\CreateDraft;

final readonly class CreateDraftCommand
{
    public function __construct(
        public string $type,
        public array $input,
        public int $now,
    ) {
    }
}
