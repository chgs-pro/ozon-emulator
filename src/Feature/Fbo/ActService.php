<?php

declare(strict_types=1);

namespace App\Feature\Fbo;

use function array_column;
use function array_filter;
use function array_keys;
use function array_merge;
use function array_sum;
use function array_unique;
use function array_values;
use function count;
use function gmdate;
use function in_array;
use function max;

use const DATE_ATOM;

final readonly class ActService
{
    public function __construct(
        private SupplyContext
    $context,
    ) {
    }
    public function summary(CabinetState $state, int $orderId): array
    {
        $order = $this->context->order($state, $orderId);
        $rows  = [];
        foreach ($order['supplies'] as $supply) {
            $acts   = array_filter($state->data['acts'], static fn (array $act): bool => $act['supply_id'] === $supply['supply_id']);
            $rows[] = ['supply_id' => $supply['supply_id'], 'is_agreement_completed' => $acts !== [] && count(array_filter($acts, static fn (array $act): bool => $act['summary']['act_state'] !== 'ACCEPTED')) === 0, 'supply_acts' => array_values(array_column($acts, 'summary'))];
        }

        return ['supplies_acts' => $rows];
    }
    public function products(CabinetState $state, int $supplyId): array
    {
        $this->context->locate($state, $supplyId);
        $acts    = array_filter($state->data['acts'], static fn (array $act): bool => $act['supply_id'] === $supplyId);
        $defects = [];
        foreach ($acts as $act) {
            if ($act['product']['type'] === 'DEFECT') {
                foreach ($act['product']['items'] as $item) {
                    $defects[] = ['sku' => $item['sku_info']['sku'], 'defect_reasons' => ['LOCAL_TEST_DAMAGED']];
                }
            }
        }

        return ['supply_id' => $supplyId, 'skus_defects' => $defects, 'supply_acts' => array_values(array_column($acts, 'product'))];
    }
    public function accept(CabinetState $state, int $actId, int $now): array
    {
        $act   = $state->data['acts'][$actId] ?? throw new SellerApiException('Act not found', 404, 5);
        $order = $this->context->order($state, $act['order_id']);
        if (!in_array($order['state'], ['REPORTS_CONFIRMATION_AWAITING', 'REPORT_REJECTED'], true) || $act['summary']['act_state'] !== 'AWAITING_APPROVAL_BY_SELLER') {
            throw new OperationFailure('INVALID_STATE');
        }
        if ($state->data['requirements'][$act['supply_id']]['utdUploaded'] ?? false) {
            throw new OperationFailure('SUPPLY_WITH_UTD');
        }
        $state->data['acts'][$actId]['summary']['act_state'] = 'ACCEPTED';
        $this->context->touch($state, $act['order_id'], $now);

        return [];
    }

    /** External warehouse facts: only the test-control command calls this, never Seller create APIs. */
    public function receive(CabinetState $state, int $supplyId, array $facts, int $now): void
    {
        [$orderId, $index] = $this->context->locate($state, $supplyId);
        $order             = $state->data['orders'][$orderId];
        if (!in_array($order['state'], ['ACCEPTED_AT_SUPPLY_WAREHOUSE', 'IN_TRANSIT', 'ACCEPTANCE_AT_STORAGE_WAREHOUSE', 'REPORTS_CONFIRMATION_AWAITING'], true)) {
            throw new SellerApiException('Acceptance requires external warehouse handover first');
        }
        $declared   = array_column($this->context->bundle($state, $order['supplies'][$index]['bundle_id']), null, 'sku');
        $factsBySku = array_column($facts, null, 'sku');
        $groups     = ['ACCEPTANCE' => [], 'SHORTCOMING' => [], 'SURPLUS' => [], 'DEFECT' => []];
        $products   = array_column($state->config()['products'], null, 'sku');
        foreach (array_unique(array_merge(array_keys($declared), array_keys($factsBySku))) as $sku) {
            $p        = $products[$sku] ?? throw new SellerApiException('Unknown acceptance SKU');
            $quantity = $declared[$sku]['quantity'] ?? 0;
            $fact     = $factsBySku[$sku]['factQuantity'] ?? $quantity;
            $defect   = $factsBySku[$sku]['defectQuantity'] ?? 0;
            if ($fact < 0 || $defect < 0 || $defect > $fact) {
                throw new SellerApiException('Invalid acceptance quantities');
            }
            $row                    = ['sku_info' => ['sku' => (int) $sku, 'barcode' => $p['barcodes'][0], 'name' => $p['name'], 'offer_id' => $p['offerId']], 'declared_quantity' => $quantity, 'fact_quantity' => $fact, 'approved_quantity' => $fact - $defect];
            $groups['ACCEPTANCE'][] = $row;
            if ($fact < $quantity) {
                $short                      = $row;
                $short['declared_quantity'] = $quantity - $fact;
                $short['fact_quantity']     = 0;
                $short['approved_quantity'] = 0;
                $groups['SHORTCOMING'][]    = $short;
            }
            if ($fact > $quantity) {
                $surplus                      = $row;
                $surplus['declared_quantity'] = 0;
                $surplus['fact_quantity']     = $fact - $quantity;
                $surplus['approved_quantity'] = max(0, $fact - $quantity - $defect);
                $groups['SURPLUS'][]          = $surplus;
            }
            if ($defect > 0) {
                $damaged                      = $row;
                $damaged['declared_quantity'] = 0;
                $damaged['fact_quantity']     = $defect;
                $damaged['approved_quantity'] = 0;
                $groups['DEFECT'][]           = $damaged;
            }
        }
        // A receipt is a one-time fact. Replays must use the control event's idempotency key.
        foreach ($state->data['acts'] as $act) {
            if ($act['supply_id'] === $supplyId) {
                throw new SellerApiException('Acceptance already recorded', 409, 10);
            }
        }
        foreach ($groups as $type => $items) {
            if ($items === []) {
                continue;
            }
            $id                       = $state->id();
            $state->data['acts'][$id] = ['order_id' => (int) $orderId, 'supply_id' => $supplyId, 'summary' => ['act_id' => $id, 'act_number' => 'LOCAL-ACT-' . $id, 'act_state' => 'AWAITING_APPROVAL_BY_SELLER', 'created_date' => gmdate('Y-m-d', $now), 'deadline_utc' => gmdate(DATE_ATOM, $now + 3 * 86400), 'type' => $type, 'summary' => ['declared_quantity' => array_sum(array_column($items, 'declared_quantity')), 'fact_quantity' => array_sum(array_column($items, 'fact_quantity')), 'approved_quantity' => array_sum(array_column($items, 'approved_quantity')), 'sku_quantity' => count($items), 'unidentified_quantity' => 0]], 'product' => ['act_id' => $id, 'type' => $type, 'items' => $items, 'unidentified_quantity' => 0]];
        }
        $state->data['orders'][$orderId]['supplies'][$index]['state'] = 'REPORTS_CONFIRMATION_AWAITING';
        $state->data['orders'][$orderId]['state']                     = 'REPORTS_CONFIRMATION_AWAITING';
        $this->context->touch($state, (int) $orderId, $now);
    }
}
