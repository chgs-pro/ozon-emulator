<?php

declare(strict_types=1);

namespace App\Feature\Fbo;

use DateTimeImmutable;
use DateTimeZone;

use function array_column;
use function array_merge;
use function array_sum;
use function array_unique;
use function count;
use function gmdate;
use function in_array;
use function strtotime;
use function trim;

use const DATE_ATOM;

final readonly class OrderMutationService
{
    public function __construct(
        private SupplyContext $context,
        private TimeslotService $slots,
        private CargoService $cargo,
    ) {
    }

    public function apply(CabinetState $state, string $kind, array $input, int $now): array
    {
        $orderId = (int) ($input['order_id'] ?? $input['supply_order_id']);
        $order   = $this->context->order($state, $orderId);
        $this->context->editable($order, $now);
        if (!$state->config()['contractActive']) {
            throw new OperationFailure($kind === 'content' ? 'INACTIVE_CONTRACT' : 'INVALID_STATE');
        }
        $result = [];
        if ($kind === 'content') {
            [$ownerId, $index] = $this->context->locate($state, (int) $input['supply_id']);
            if ($ownerId !== $orderId) {
                throw new SellerApiException('Supply does not belong to order');
            }
            if ($state->data['requirements'][$input['supply_id']]['utdUploaded'] ?? false) {
                throw new OperationFailure('UTD_IS_UPLOADED');
            }
            $items = $input['items'];
            if ($items === []) {
                throw new OperationFailure('EMPTY_CONTENT');
            }
            if (count(array_unique(array_column($items, 'sku'))) !== count($items)) {
                throw new OperationFailure('SAME_SKU');
            }
            $products   = array_column($state->config()['products'], null, 'sku');
            $approved   = [];
            $rejected   = [];
            $allowed    = [];
            $supplyType = null;
            foreach ($state->data['drafts'] as $draft) {
                if (($draft['supply_operation']['order_id'] ?? null) === $orderId) {
                    $supplyType = $draft['type'];
                    break;
                }
            }
            foreach ($state->config()['routes'] as $route) {
                if ($route['type'] === $supplyType && in_array($order['supplies'][$index]['macrolocal_cluster_id'], $route['clusterIds'], true) && $route['dropoffWarehouseId'] === $order['dropoff_warehouse']['warehouse_id']) {
                    $allowed = array_merge($allowed, $route['allowedSkus']);
                }
            }
            foreach ($items as $item) {
                $p      = $products[$item['sku']] ?? null;
                $reason = match (true) {
                    $p === null                                   => 'OUT_OF_ASSORTMENT',
                    !in_array((int) $item['sku'], $allowed, true) => 'INCOMPATIBLE_WAREHOUSE',
                    $item['quantity'] < 1                         => 'INVALID_ITEM_COUNT_ZERO',
                    $item['quantity'] > $p['maxQuantity']         => 'INVALID_ITEM_COUNT_MAX',
                    $item['quant'] !== $p['quant']                => 'INVALID_QUANT_VALUE',
                    $item['quantity'] % $p['quant'] !== 0         => 'QUANTITY_NOT_MULTIPLE_BY_QUANT',
                    default                                       => null,
                };
                if ($reason !== null) {
                    $rejected[] = ['sku' => (int) $item['sku'], 'quantity' => $item['quantity'], 'rejection_reason' => [$reason]];
                    continue;
                }
                $approved[] = ['sku' => $p['sku'], 'product_id' => $p['productId'], 'offer_id' => $p['offerId'], 'name' => $p['name'], 'barcode' => $p['barcodes'][0], 'quantity' => $item['quantity'], 'quant' => $item['quant'], 'volume_in_litres' => $p['volumeLitres'], 'total_volume_in_litres' => $item['quantity'] * $p['volumeLitres'], 'tags' => $p['tags'], 'placement_zone' => $p['placementZone'] ?? 'UNSPECIFIED'];
            }
            $newBundleId                              = $this->context->storeBundle($state, $approved);
            $state->data['validations'][$newBundleId] = ['supply_id' => (int) $input['supply_id'], 'response' => ['editing_errors' => [], 'validated_assortment' => ['approved_items' => $approved, 'rejected_items' => $rejected, 'total_approved_item_count' => count($approved), 'total_approved_quantity' => array_sum(array_column($approved, 'quantity')), 'total_approved_volume_in_litres' => array_sum(array_column($approved, 'total_volume_in_litres')), 'total_rejected_item_count' => count($rejected), 'total_restricted_item_count' => count($rejected)]]];
            if ($rejected !== []) {
                throw new OperationFailure('SUPPLY_CONTENT_NOT_VALID', ['new_bundle_id' => $newBundleId]);
            }
            $allocated  = $this->cargo->totals($this->context->cargo($state, (int) $input['supply_id'])['cargoes']);
            $quantities = array_column($approved, 'quantity', 'sku');
            foreach ($allocated as $sku => $quantity) {
                if ($quantity > ($quantities[$sku] ?? 0)) {
                    throw new OperationFailure('SUPPLY_LOCKED');
                }
            }
            $order['supplies'][$index]['bundle_id'] = $newBundleId;
            $tags                                   = [];
            foreach ($approved as $row) {
                $tags = array_merge($tags, $products[$row['sku']]['tags'], $products[$row['sku']]['supplyTags'] ?? []);
            }
            foreach (['ETTN_REQUIRED' => 'is_ettn_required', 'EVSD_REQUIRED' => 'is_evsd_required', 'JEWELRY' => 'is_jewelry', 'MARKING_REQUIRED' => 'is_marking_required', 'MARKING_POSSIBLE' => 'is_marking_possible', 'UTD_REQUIRED' => 'is_utd'] as $tag => $field) {
                $order['supplies'][$index]['supply_tags'][$field] = in_array($tag, $tags, true);
            }
            // Cargo IDs survive a compatible content change; their label generation becomes stale.
            if (isset($state->data['cargo'][$input['supply_id']])) {
                ++$state->data['cargo'][$input['supply_id']]['version'];
            }
            $result = ['new_bundle_id' => $newBundleId];
        } elseif ($kind === 'timeslot') {
            $available = $this->timeslots($state, $orderId, $now);
            if (isset($available['limit_exceeded'])) {
                throw new OperationFailure('LIMIT');
            }
            $slot = ['from' => gmdate(DATE_ATOM, strtotime($input['timeslot']['from'])), 'to' => gmdate(DATE_ATOM, strtotime($input['timeslot']['to']))];
            if (!in_array($slot, $available['timeslots_info']['timeslots'] ?? [], true)) {
                throw new OperationFailure('SLOT');
            }
            $order['timeslot']['timeslot']         = $slot;
            $order['data_filling_deadline_utc']    = gmdate(DATE_ATOM, strtotime($slot['from']) - 3600);
            $state->data['slot_changes'][$orderId] = ($state->data['slot_changes'][$orderId] ?? 0) + 1;
        } elseif ($kind === 'pass') {
            foreach ($input['vehicle'] as $value) {
                if (trim($value) === '') {
                    throw new SellerApiException('Vehicle fields cannot be empty');
                }
            }
            $state->data['vehicles'][$orderId] = $input['vehicle'];
        } elseif ($kind === 'cancel') {
            foreach ($order['supplies'] as $supply) {
                if ($state->data['requirements'][$supply['supply_id']]['utdUploaded'] ?? false) {
                    throw new OperationFailure('INVALID_STATE');
                }
            }
            $order['state'] = 'CANCELLED';
            $result         = ['is_order_cancelled' => true, 'supplies' => []];
            foreach ($order['supplies'] as &$supply) {
                $supply['state']      = 'CANCELLED';
                $result['supplies'][] = ['supply_id' => $supply['supply_id'], 'is_supply_cancelled' => true, 'error_reasons' => []];
            }
            unset($supply);
        }
        $state->data['orders'][$orderId] = $order;
        $this->context->touch($state, $orderId, $now);

        return $result;
    }

    public function timeslots(CabinetState $state, int $orderId, int $now): array
    {
        $order = $this->context->order($state, $orderId);
        if (!in_array($order['state'], ['DATA_FILLING', 'READY_TO_SUPPLY'], true) || strtotime($order['data_filling_deadline_utc'] ?? '9999-01-01') <= $now) {
            return ['timeslot_change_forbidden' => ['error_reasons' => ['INVALID_ORDER_STATE']]];
        }
        $limit = $state->config()['timeslotChangesLimit'] ?? 5;
        $count = $state->data['slot_changes'][$orderId] ?? 0;
        if ($count >= $limit) {
            return ['limit_exceeded' => ['changes_limit' => $limit]];
        }
        $zone = new DateTimeZone($order['timeslot']['timezone_info']['iana_name']);

        $from = new DateTimeImmutable('@' . $now)->setTimezone($zone);

        $days  = $this->slots->slots($state, $order['dropoff_warehouse']['warehouse_id'], $now, $from->format('Y-m-d'), $from->modify('+28 days')->format('Y-m-d'))['drop_off_warehouse_timeslots']['days'];
        $slots = [];
        foreach ($days as $day) {
            foreach ($day['timeslots'] as $slot) {
                $slots[] = ['from' => gmdate(DATE_ATOM, new DateTimeImmutable($slot['from_in_timezone'], $zone)->getTimestamp()), 'to' => gmdate(DATE_ATOM, new DateTimeImmutable($slot['to_in_timezone'], $zone)->getTimestamp())];
            }
        }

        return ['timeslots_info' => ['limitations' => ['changes_count' => $count, 'changes_limit' => $limit], 'timeslots' => $slots, 'timezone' => ['iana_name' => $zone->getName(), 'offset' => $zone->getOffset($from)]]];
    }
}
