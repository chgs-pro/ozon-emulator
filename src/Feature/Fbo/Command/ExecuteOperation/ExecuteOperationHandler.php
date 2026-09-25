<?php

declare(strict_types=1);

namespace App\Feature\Fbo\Command\ExecuteOperation;

use App\Feature\Fbo\CabinetState;
use App\Feature\Fbo\OperationCatalog;
use App\Feature\Fbo\SellerApiException;
use App\Feature\Fbo\SupplyContext;

use function bin2hex;
use function in_array;
use function random_bytes;
use function str_starts_with;

final readonly class ExecuteOperationHandler
{
    public function __construct(
        private SupplyContext
    $context,
    ) {
    }
    public function handle(CabinetState $state, ExecuteOperationCommand $command): array
    {
        $state->authorize(true);
        [$statusPath, $kind] = OperationCatalog::METHODS[$command->path];
        $input               = $command->input;
        $orderId             = (int) ($input['order_id'] ?? $input['supply_order_id'] ?? 0);
        if (isset($input['supply_id'])) {
            [$ownerId] = $this->context->locate($state, (int) $input['supply_id']);
            if ($orderId !== 0 && $orderId !== $ownerId) {
                throw new SellerApiException('Supply does not belong to order');
            }
            $orderId = (int) $ownerId;
        }
        if ($kind === 'act.accept') {
            $orderId = $state->data['acts'][(int) $input['act_id']]['order_id'] ?? throw new SellerApiException('Act not found', 404, 5);
        }
        $this->context->order($state, $orderId);
        $scenario = $state->data['scenario'] ?? [];
        $behavior = ($scenario['path'] ?? '') === $command->path && ($scenario['remaining'] ?? 0) > 0 ? $scenario : [];
        if ($behavior !== [] && $command->cargoScenario === null) {
            --$state->data['scenario']['remaining'];
        }
        if ($command->cargoScenario !== null) {
            if (!in_array($command->path, $command->cargoScenario === 'reset' ? ['/v1/cargoes/delete', '/v2/cargoes/delete'] : ['/v1/cargoes/create'], true) || !in_array($command->cargoScenario, ['success', 'error', 'reset'], true)) {
                throw new SellerApiException('Invalid cargo scenario');
            }
            if ($command->cargoScenario === 'error') {
                throw new SellerApiException('Configured local cargo registration rejection');
            }
            $behavior = ['fail' => false, 'reset_cargo' => $command->cargoScenario === 'reset'];
        }
        $busy = false;
        foreach ($state->data['operations'] ?? [] as $op) {
            if ($op['order_id'] === $orderId && $op['status'] === 'IN_PROGRESS') {
                $busy = true;
            }
        }
        $id                             = 'operation-' . $state->id();
        $state->data['operations'][$id] = ['id' => $id, 'kind' => $kind, 'path' => $command->path, 'status_path' => $statusPath, 'input' => $input, 'order_id' => $orderId, 'order_version' => $state->data['order_versions'][$orderId] ?? 0, 'config_version' => $state->data['config_version'], 'created_at' => $command->now, 'ready_at' => $command->now + ($behavior['delaySeconds'] ?? $state->config()['operationDelaySeconds']), 'status' => 'IN_PROGRESS', 'error' => $busy ? 'BUSY' : null, 'behavior' => $behavior, 'document_capability' => str_starts_with($kind, 'label.') ? bin2hex(random_bytes(32)) : ''];
        $state->event('operation.queued', $command->now, ['operation_id' => $id, 'order_id' => $orderId, 'kind' => $kind]);

        // Creation errors are optional; the authoritative outcome is the method-specific poll.
        return ['operation_id' => $id];
    }
}
