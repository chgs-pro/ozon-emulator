<?php

declare(strict_types=1);

namespace App\Feature\Fbo\Command\CreateSupply;

use App\Feature\Fbo\CabinetState;
use App\Feature\Fbo\DraftPlanner;
use App\Feature\Fbo\SellerApiException;
use App\Feature\Fbo\TimeslotService;

use function array_column;
use function array_filter;
use function in_array;
use function is_array;
use function reset;
use function substr;

final readonly class CreateSupplyHandler
{
    public function __construct(
        private DraftPlanner $planner,
        private TimeslotService $timeslots,
    ) {
    }
    public function handle(CabinetState $state, CreateSupplyCommand $command): array
    {
        $state->authorize(true);
        $input = $command->input;
        $id    = (int) $input['draft_id'];
        $now   = $command->now;
        $error = static fn (string $reason): array => ['draft_id' => $id, 'error_reasons' => [$reason]];
        $draft = $state->data['drafts'][(string) $id] ?? null;
        if ($draft === null) {
            return $error('DRAFT_DOES_NOT_EXIST');
        }
        $previous = $draft['supply_operation'];
        if ($previous !== null && !$previous['failed']) {
            return $error(isset($state->data['orders'][(string) $previous['order_id']]) ? 'ORDER_ALREADY_CREATED' : 'ORDER_CREATION_IN_PROGRESS');
        }
        if (!$state->config()['contractActive']) {
            return $error('INACTIVE_CONTRACT');
        }
        if ($draft['expires_at'] <= $now) {
            return $error('DRAFT_DOES_NOT_EXIST');
        }
        if ($draft['ready_at'] > $now || $draft['failed']) {
            return $error('DRAFT_INCORRECT_STATE');
        }
        $selected = $this->planner->selection($draft, $input, $now);
        $dropoff  = $draft['type'] === 'DIRECT' ? reset($selected)['storage_warehouse']['warehouse_id'] : $draft['dropoff_id'];
        foreach ($selected as $clusterId => $option) {
            $routes = array_filter($state->config()['routes'], static fn (array $r): bool => $r['type'] === $draft['type'] && $r['dropoffWarehouseId'] === $dropoff && in_array($clusterId, $r['clusterIds'], true));
            if ($routes === []) {
                return $error('INVALID_ROUTE');
            }
            $currentProducts = array_column($state->config()['products'], null, 'sku');
            $allowedSkus     = [];
            foreach ($routes as $route) {
                $allowedSkus = [...$allowedSkus, ...$route['allowedSkus']];
            }
            foreach ($draft['bundles'][$option['bundle_id']] as $item) {
                $product = $currentProducts[$item['sku']] ?? null;
                if ($product === null || !in_array($item['sku'], $allowedSkus, true)
                    || $item['quantity'] > $product['maxQuantity'] || $item['quantity'] % $product['quant'] !== 0) {
                    return $error('INVALID_SUPPLY_CONTENT');
                }
            }
        }
        $slot = $input['timeslot'] ?? null;
        if (!is_array($slot) || !isset($slot['from_in_timezone'], $slot['to_in_timezone'])) {
            return $error('TIMESLOT_NOT_AVAILABLE');
        }
        $date = substr($slot['from_in_timezone'], 0, 10);
        try {
            $available = $this->timeslots->slots($state, $dropoff, $now, $date, $date);
        } catch (SellerApiException) {
            return $error('TIMESLOT_NOT_AVAILABLE');
        }
        $found = false;
        foreach ($available['drop_off_warehouse_timeslots']['days'] as $day) {
            foreach ($day['timeslots'] as $candidate) {
                if ($candidate === $slot) {
                    $found = true;
                }
            }
        }
        if (!$found) {
            return $error('TIMESLOT_NOT_AVAILABLE');
        }
        $c                                                       = $state->config();
        $warehouses                                              = array_column($c['warehouses'], null, 'id');
        $operation                                               = ['order_id' => $state->id(), 'created_at' => $now, 'ready_at' => $now + $c['operationDelaySeconds'], 'failed' => $c['supplyFailure'], 'selected' => $selected, 'dropoff_id' => $dropoff, 'warehouse' => $warehouses[$dropoff], 'slot' => $slot];
        $state->data['drafts'][(string) $id]['supply_operation'] = $operation;
        $state->event('supply.create', $now, ['draft_id' => $id]);

        return ['draft_id' => $id, 'error_reasons' => []];
    }
}
