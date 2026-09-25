<?php

declare(strict_types=1);

namespace App\Feature\Fbo\Command\AdvanceOperations;

use App\Feature\Fbo\ActService;
use App\Feature\Fbo\CabinetState;
use App\Feature\Fbo\CargoService;
use App\Feature\Fbo\LabelService;
use App\Feature\Fbo\OperationFailure;
use App\Feature\Fbo\OrderMutationService;
use App\Feature\Fbo\ReadinessService;
use App\Feature\Fbo\SellerApiException;

use function array_slice;
use function count;
use function str_starts_with;
use function strtolower;

final readonly class AdvanceOperationsHandler
{
    public function __construct(
        private CargoService $cargo,
        private OrderMutationService $orders,
        private LabelService $labels,
        private ActService $acts,
        private ReadinessService $readiness,
    ) {
    }
    public function handle(CabinetState $state, AdvanceOperationsCommand $command): void
    {
        foreach ($state->data['operations'] ?? [] as $id => $op) {
            if ($op['status'] !== 'IN_PROGRESS' || $op['ready_at'] > $command->now || ($op['behavior']['hold'] ?? false)) {
                continue;
            }
            $candidate = new CabinetState($state->data);
            try {
                if ($op['error'] !== null) {
                    throw new OperationFailure($op['error']);
                }
                if (($op['behavior']['fail'] ?? false)) {
                    throw new OperationFailure('FAILED');
                }
                if (($state->data['order_versions'][$op['order_id']] ?? 0) !== $op['order_version']) {
                    throw new OperationFailure('INVALID_STATE');
                }
                $input   = $op['input'];
                $partial = $op['kind'] === 'cargo.create' && isset($op['behavior']['partialCargoCount']) && $op['behavior']['partialCargoCount'] < count($input['cargoes']);
                if ($partial) {
                    $input['cargoes'] = array_slice($input['cargoes'], 0, $op['behavior']['partialCargoCount']);
                }
                $result = match (true) {
                    str_starts_with($op['kind'], 'cargo.'), str_starts_with($op['kind'], 'transport.') => $this->cargo->apply($candidate, $op['kind'], $input, $command->now, (bool)($op['behavior']['reset_cargo'] ?? false)),
                    str_starts_with($op['kind'], 'label.')                                             => $this->labels->create($candidate, $command->clientId, $op, $command->now),
                    $op['kind'] === 'act.accept'                                                       => $this->acts->accept($candidate, (int) $input['act_id'], $command->now),
                    default                                                                            => $this->orders->apply($candidate, $op['kind'], $input, $command->now),
                };
                $state->data  = $candidate->data;
                $op['result'] = $result;
                $op['status'] = $partial ? 'FAILED' : 'SUCCESS';
                $op['error']  = $partial ? 'FAILED' : null;
            } catch (OperationFailure $failure) {
                $op['status']  = 'FAILED';
                $op['error']   = $failure->reason;
                $op['details'] = $failure->details;
                if (isset($failure->details['new_bundle_id'])) {
                    // Keep the rejected proposal for diagnostics, without publishing it as supply content.
                    $bundleId                              = $failure->details['new_bundle_id'];
                    $state->data['sequence']               = $candidate->data['sequence'];
                    $state->data['bundles'][$bundleId]     = $candidate->data['bundles'][$bundleId];
                    $state->data['validations'][$bundleId] = $candidate->data['validations'][$bundleId];
                    $op['result']                          = ['new_bundle_id' => $bundleId];
                }
            } catch (SellerApiException $failure) {
                $op['status']     = 'FAILED';
                $op['error']      = $op['kind'] === 'cargo.create' ? 'VALIDATION_FAILED' : 'FAILED';
                $op['diagnostic'] = $failure->getMessage();
            }
            unset($op['document_capability']);
            $op['completed_at']             = $command->now;
            $state->data['operations'][$id] = $op;
            $state->event('operation.' . strtolower($op['status']), $command->now, ['operation_id' => $id, 'order_id' => $op['order_id'], 'reason' => $op['error']]);
        }
        $this->readiness->refresh($state, $command->now);
    }
}
