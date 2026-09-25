<?php

declare(strict_types=1);

namespace App\Feature\Fbo\Command\CreateDraft;

use App\Feature\Fbo\CabinetState;
use App\Feature\Fbo\DraftPlanner;

final readonly class CreateDraftHandler
{
    public function __construct(
        private DraftPlanner
    $planner)
    {
    }
    public function handle(CabinetState $state, CreateDraftCommand $command): array
    {
        $state->authorize(true);
        $draft                                        = $this->planner->calculate($state, $command->type, $command->input, $command->now);
        $state->data['drafts'][(string) $draft['id']] = $draft;
        $state->event('draft.create', $command->now, ['draft_id' => $draft['id']]);

        return ['draft_id' => $draft['id'], 'errors' => []];
    }
}
