<?php

declare(strict_types=1);

namespace App\Feature\Fbo;

use function gmdate;
use function in_array;
use function is_array;

use const DATE_ATOM;

/** Local profile readiness; physical handover still requires an external warehouse event. */
final readonly class ReadinessService
{
    public function __construct(
        private CargoService $cargo,
        private SupplyContext $context,
    ) {
    }
    public function ready(CabinetState $state, array $order, int $now): bool
    {
        if (!$state->config()['contractActive']) {
            return false;
        }
        if (($state->config()['vehicleRequired'] ?? false) && empty($state->data['vehicles'][$order['order_id']])) {
            return false;
        }
        foreach ($order['supplies'] as $supply) {
            foreach ($this->cargo->rules($state, $supply['supply_id'], $now) as $rule) {
                if (is_array($rule) && ($rule['is_required'] ?? $rule['is_applicable'] ?? true) && isset($rule['satisfied']) && !$rule['satisfied']) {
                    return false;
                }
            }
            foreach ($this->context->bundle($state, $supply['bundle_id']) as $item) {
                if (in_array('UNDEFINED', $item['tags'] ?? [], true)) {
                    return false;
                }
            }
            $documents = $state->data['requirements'][$supply['supply_id']] ?? [];
            foreach (['is_utd' => 'utdUploaded', 'is_ettn_required' => 'ettnUploaded', 'is_evsd_required' => 'evsdUploaded'] as $tag => $field) {
                if (($supply['supply_tags'][$tag] ?? false) && !($documents[$field] ?? false)) {
                    return false;
                }
            }
        }

        return true;
    }
    public function refresh(CabinetState $state, int $now): void
    {
        foreach ($state->data['orders'] as $orderId => $order) {
            if (!in_array($order['state'], ['DATA_FILLING', 'READY_TO_SUPPLY'], true)) {
                continue;
            }
            $status = $this->ready($state, $order, $now) ? 'READY_TO_SUPPLY' : 'DATA_FILLING';
            if ($status === $order['state']) {
                continue;
            }
            $state->data['orders'][$orderId]['state'] = $status;
            foreach ($state->data['orders'][$orderId]['supplies'] as &$supply) {
                $supply['state'] = $status;
            }
            unset($supply);
            $state->data['orders'][$orderId]['state_updated_date'] = gmdate(DATE_ATOM, $now);
            $state->event('order.readiness', $now, ['order_id' => (int) $orderId, 'state' => $status]);
        }
    }
}
