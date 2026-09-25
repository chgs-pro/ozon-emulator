<?php

declare(strict_types=1);

namespace App\Feature\Fbo;

use function array_values;
use function gmdate;
use function in_array;
use function strtotime;

use const DATE_ATOM;

/** Locates external facts only inside the authenticated cabinet. */
final class SupplyContext
{
    public function locate(CabinetState $state, int $supplyId): array
    {
        foreach ($state->data['orders'] as $orderId => $order) {
            foreach ($order['supplies'] as $index => $supply) {
                if ($supply['supply_id'] === $supplyId) {
                    return [$orderId, $index];
                }
            }
        }

        throw new SellerApiException('Supply not found', 404, 5);
    }

    public function order(CabinetState $state, int $orderId): array
    {
        return $state->data['orders'][$orderId] ?? throw new SellerApiException('Order not found', 404, 5);
    }

    public function editable(array $order, ?int $now = null): void
    {
        if (!in_array($order['state'], ['DATA_FILLING', 'READY_TO_SUPPLY'], true) || ($now !== null && isset($order['data_filling_deadline_utc']) && strtotime($order['data_filling_deadline_utc']) <= $now)) {
            throw new OperationFailure('INVALID_STATE');
        }
    }

    public function bundle(CabinetState $state, string $id): array
    {
        if (isset($state->data['bundles'][$id])) {
            return $state->data['bundles'][$id];
        }
        foreach ($state->data['drafts'] as $draft) {
            if (isset($draft['bundles'][$id])) {
                return $draft['bundles'][$id];
            }
        }

        throw new SellerApiException('Bundle not found', 404, 5);
    }

    public function cargo(CabinetState $state, int $supplyId): array
    {
        $this->locate($state, $supplyId);

        return $state->data['cargo'][$supplyId] ?? ['version' => 0, 'is_transport' => false, 'cargoes' => [], 'transport' => []];
    }

    public function storeBundle(CabinetState $state, array $items): string
    {
        $id                          = 'bundle-' . $state->id();
        $state->data['bundles'][$id] = array_values($items);

        return $id;
    }

    public function limits(CabinetState $state): array
    {
        return $state->config()['cargoLimits'] ?? ['max_box_count' => 1500, 'max_box_sku_count' => 100, 'max_pallet_count' => 40, 'max_transport_pallet_count' => 40];
    }

    public function touch(CabinetState $state, int $orderId, int $now): void
    {
        $state->data['orders'][$orderId]['state_updated_date'] = gmdate(DATE_ATOM, $now);
        $state->data['order_versions'][$orderId]               = ($state->data['order_versions'][$orderId] ?? 0) + 1;
    }
}
