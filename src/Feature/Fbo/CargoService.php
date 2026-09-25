<?php

declare(strict_types=1);

namespace App\Feature\Fbo;

use function array_column;
use function array_count_values;
use function array_filter;
use function array_map;
use function array_push;
use function array_sum;
use function array_unique;
use function array_values;
use function count;
use function floor;
use function in_array;
use function reset;
use function str_starts_with;
use function strtotime;
use function trim;

final readonly class CargoService
{
    public function __construct(
        private SupplyContext
    $context,
    ) {
    }

    public function apply(CabinetState $state, string $kind, array $input, int $now, bool $developmentReset = false): array
    {
        $supplyId          = (int) $input['supply_id'];
        [$orderId, $index] = $this->context->locate($state, $supplyId);
        $order             = $state->data['orders'][$orderId];
        $this->context->editable($order, $now);
        $supply = $order['supplies'][$index];
        if (($state->data['requirements'][$supplyId]['ettnUploaded'] ?? false) || ($state->data['requirements'][$supplyId]['utdUploaded'] ?? false)) {
            throw new OperationFailure('INVALID_STATE');
        }
        $cargo  = $this->context->cargo($state, $supplyId);
        $limits = $this->context->limits($state);
        $result = [];
        if ($kind === 'cargo.create') {
            if (empty($input['cargoes'])) {
                throw new SellerApiException('At least one cargo required');
            }
            if ($input['delete_current_version'] ?? false) {
                $cargo['cargoes'] = [];
                // Existing transport pallets remain, their contained boxes are replaced.
            }
            $expected    = array_column($this->context->bundle($state, $supply['bundle_id']), null, 'sku');
            $keys        = array_column($cargo['cargoes'], 'key');
            $batchCounts = [];
            foreach ($input['cargoes'] as $row) {
                $key = trim($row['key']);
                if ($key === '' || in_array($key, $keys, true)) {
                    throw new OperationFailure('VALIDATION_FAILED');
                }
                $keys[]             = $key;
                $type               = $row['value']['type'];
                $batchCounts[$type] = ($batchCounts[$type] ?? 0) + 1;
                if ($batchCounts[$type] > ($type === 'BOX' ? 30 : 40)) {
                    throw new OperationFailure('WAREHOUSE_LIMITS_EXCEED');
                }
                if ($cargo['is_transport'] && $type !== 'BOX') {
                    throw new OperationFailure('VALIDATION_FAILED');
                }
                $items = [];
                foreach ($row['value']['items'] ?? [] as $item) {
                    $catalog  = array_column($state->config()['products'], null, 'sku');
                    $matches  = array_filter($expected, static fn (array $p): bool => (!isset($item['offer_id']) || $item['offer_id'] === $p['offer_id']) && (!isset($item['barcode']) || in_array($item['barcode'], $catalog[$p['sku']]['barcodes'] ?? [], true)));
                    $product  = count($matches) === 1 && (isset($item['offer_id']) || isset($item['barcode'])) ? reset($matches) : null;
                    $quantity = $item['quantity'] ?? 0;
                    $quant    = $item['quant'] ?? 1;
                    if ($product === null || $quantity <= 0 || $quant <= 0 || $quant !== ($product['quant'] ?? 1) || $quantity % $quant !== 0) {
                        throw new OperationFailure('VALIDATION_FAILED');
                    }
                    $configProduct = array_column($state->config()['products'], null, 'sku')[$product['sku']];
                    if (($configProduct['expirationRequired'] ?? false) && !isset($item['expires_at'])) {
                        throw new OperationFailure('VALIDATION_FAILED');
                    }
                    if (isset($item['expires_at']) && strtotime($item['expires_at']) <= $now) {
                        throw new OperationFailure('VALIDATION_FAILED');
                    }
                    $product['quantity']               = $quantity;
                    $product['total_volume_in_litres'] = $quantity * ($product['volume_in_litres'] ?? 0);
                    if (isset($item['expires_at'])) {
                        $product['expires_at'] = $item['expires_at'];
                    }
                    $items[] = $product;
                }
                if ($items === [] || ($type === 'BOX' && count(array_unique(array_column($items, 'sku'))) > $limits['max_box_sku_count'])) {
                    throw new OperationFailure('VALIDATION_FAILED');
                }
                $id                    = $state->id();
                $cargo['cargoes'][$id] = ['cargo_id' => $id, 'key' => $key, 'type' => $type, 'bundle_id' => $this->context->storeBundle($state, $items), 'items' => $items, 'transport_cargo_id' => 0];
                $result['cargoes'][]   = ['key' => $key, 'value' => ['cargo_id' => $id]];
            }
            $totals = $this->totals($cargo['cargoes']);
            foreach ($totals as $sku => $quantity) {
                if ($quantity > ($expected[$sku]['quantity'] ?? 0)) {
                    throw new OperationFailure('VALIDATION_FAILED');
                }
            }
            $counts = array_count_values(array_column($cargo['cargoes'], 'type'));
            if (($counts['BOX'] ?? 0) > $limits['max_box_count'] || ($counts['PALLET'] ?? 0) > $limits['max_pallet_count']) {
                throw new OperationFailure('WAREHOUSE_LIMITS_EXCEED');
            }
        } elseif (str_starts_with($kind, 'cargo.delete')) {
            $ids          = array_map('intval', $input['cargo_ids'] ?? []);
            $transportIds = array_map('intval', $input['transport_cargo_ids'] ?? []);
            if ($ids === [] && $transportIds === []) {
                throw new SellerApiException('Cargo identifiers required');
            }
            foreach ($transportIds as $id) {
                if (!isset($cargo['transport'][$id])) {
                    throw new OperationFailure('SUPPLY_CARGOES_LOCKED', ['transport_cargo_error_reasons' => [['transport_cargo_id' => $id, 'error_reasons' => ['CARGO_NOT_FOUND']]]]);
                }
                foreach ($cargo['cargoes'] as &$box) {
                    if ($box['transport_cargo_id'] === $id) {
                        if ($input['transport_cargo_deletion_type'] === 'DELETE_CONTAINED_CARGOES') {
                            $ids[] = $box['cargo_id'];
                        }
                        $box['transport_cargo_id'] = 0;
                    }
                }
                unset($box, $cargo['transport'][$id]);
            }
            foreach (array_unique($ids) as $id) {
                if (!isset($cargo['cargoes'][$id])) {
                    throw new OperationFailure('SUPPLY_CARGOES_LOCKED', ['cargo_error_reasons' => [['cargo_id' => $id, 'error_reasons' => ['CARGO_NOT_FOUND']]]]);
                }
                unset($cargo['cargoes'][$id]);
            }
            if ($cargo['cargoes'] === [] && !$developmentReset) {
                throw new OperationFailure('CANT_DELETE_ALL_CARGOES');
            }
            if ($transportIds !== [] && $cargo['transport'] === [] && !$developmentReset) {
                throw new OperationFailure('CANT_DELETE_ALL_TRANSPORT_CARGOES');
            }
        } elseif ($kind === 'transport.activate') {
            if ($cargo['is_transport'] !== $input['is_transport'] && ($cargo['cargoes'] !== [] || $cargo['transport'] !== [])) {
                throw new OperationFailure('CAN_NOT_EDIT_TAG');
            }
            $cargo['is_transport'] = $input['is_transport'];
        } elseif ($kind === 'transport.create') {
            if (!$cargo['is_transport']) {
                throw new OperationFailure('INVALID_STATE');
            }
            $count = array_sum(array_column($input['transport_cargoes'], 'count'));
            if ($count < 1 || count($cargo['transport']) + $count > $limits['max_transport_pallet_count']) {
                throw new OperationFailure('WAREHOUSE_LIMITS_EXCEED');
            }
            foreach ($input['transport_cargoes'] as $row) {
                if ($row['count'] <= 0) {
                    throw new OperationFailure('WAREHOUSE_LIMITS_EXCEED');
                }
                for ($n = 0; $n < $row['count']; ++$n) {
                    $id                            = $state->id();
                    $cargo['transport'][$id]       = ['transport_cargo_id' => $id, 'type' => 'PALLET'];
                    $result['transport_cargoes'][] = ['id' => $id, 'type' => 'PALLET'];
                }
            }
        } elseif ($kind === 'transport.bind') {
            if (!$cargo['is_transport']) {
                throw new OperationFailure('TRANSPORT_CARGOES_NOT_ENABLED_FOR_SUPPLY');
            }
            if (isset($input['transport_cargo_bind']) === isset($input['cargoes_unbind_transport_cargoes'])) {
                throw new SellerApiException('Exactly one bind or unbind operation required');
            }
            $seen = [];
            foreach ($input['transport_cargo_bind'] ?? [] as $binding) {
                $id = (int) $binding['transport_cargo_id'];
                if (!isset($cargo['transport'][$id])) {
                    throw new OperationFailure('TRANSPORT_CARGO_IDS_NOT_FOUND');
                }
                foreach ($binding['cargo_ids'] as $boxId) {
                    if (!isset($cargo['cargoes'][$boxId]) || isset($seen[$boxId]) || $cargo['cargoes'][$boxId]['type'] !== 'BOX') {
                        throw new OperationFailure('CARGO_IDS_NOT_FOUND');
                    }
                    $seen[$boxId]                                   = true;
                    $cargo['cargoes'][$boxId]['transport_cargo_id'] = $id;
                }
            }
            foreach ($input['cargoes_unbind_transport_cargoes'] ?? [] as $id) {
                if (!isset($cargo['transport'][$id])) {
                    throw new OperationFailure('TRANSPORT_CARGO_IDS_NOT_FOUND');
                }
                foreach ($cargo['cargoes'] as &$box) {
                    if ($box['transport_cargo_id'] === (int) $id) {
                        $box['transport_cargo_id'] = 0;
                    }
                }
                unset($box);
            }
        }
        ++$cargo['version'];
        // Publish a fresh aggregate bundle for polling clients after every confirmed cargo change.
        $items = [];
        foreach ($cargo['cargoes'] as $box) {
            array_push($items, ...$box['items']);
        }
        $cargo['bundle_id'] = $this->context->storeBundle($state, $items);
        foreach ($cargo['transport'] as $id => &$transport) {
            $bound                  = array_filter($cargo['cargoes'], static fn (array $box): bool => $box['transport_cargo_id'] === $id);
            $transport['box_count'] = count($bound);
            $items                  = [];
            foreach ($bound as $box) {
                array_push($items, ...$box['items']);
            }
            $transport['summary_bundle_id'] = $this->context->storeBundle($state, $items);
        }
        unset($transport);
        $state->data['cargo'][$supplyId] = $cargo;
        $this->context->touch($state, (int) $orderId, $now);

        return $result;
    }

    public function totals(array $cargoes): array
    {
        $totals = [];
        foreach ($cargoes as $cargo) {
            foreach ($cargo['items'] as $item) {
                $totals[$item['sku']] = ($totals[$item['sku']] ?? 0) + $item['quantity'];
            }
        }

        return $totals;
    }

    public function get(CabinetState $state, array $input, bool $v2): array
    {
        $rows   = $v2 ? $input['supplies'] : array_map(static fn ($id): array => ['supply_id' => $id, 'cargo_ids' => []], $input['supply_ids']);
        $result = [];
        foreach ($rows as $row) {
            $id                = (int) $row['supply_id'];
            [$orderId, $index] = $this->context->locate($state, $id);
            $supply            = $state->data['orders'][$orderId]['supplies'][$index];
            $cargo             = $this->context->cargo($state, $id);
            $boxes             = [];
            foreach ($cargo['cargoes'] as $box) {
                if (!empty($row['cargo_ids']) && !in_array($box['cargo_id'], array_map('intval', $row['cargo_ids']), true)) {
                    continue;
                }
                $dto = ['cargo_id' => $box['cargo_id'], 'bundle_id' => $box['bundle_id'], 'type' => $box['type'], 'content_type' => count(array_unique(array_column($box['items'], 'sku'))) > 1 ? 'MIX' : 'MONO', 'placement_zone_type' => $v2 ? 'TYPE_SINGLE' : 'SINGLE', 'tracking_info' => ['status' => $cargo['tracking_status'] ?? 'CREATED', 'type' => isset($cargo['arrival_at']) ? 'ACTUAL_ARRIVAL' : 'EXPECTED_ARRIVAL']];
                if ($v2) {
                    $dto['transport_cargo_id'] = $box['transport_cargo_id'];
                }
                $boxes[] = $dto;
            }
            $dto = ['supply_id' => $id, 'bundle_id' => $supply['bundle_id'], 'cargoes' => $boxes];
            if ($v2) {
                $dto += ['cargoes_bundle_id' => $cargo['bundle_id'] ?? $supply['bundle_id'], 'limits' => $this->context->limits($state), 'transport_cargoes' => array_values($cargo['transport'])];
            }
            $result[] = $dto;
        }

        return [$v2 ? 'supplies' : 'supply' => $result];
    }

    public function rules(CabinetState $state, int $id, int $now): array
    {
        [$orderId, $index] = $this->context->locate($state, $id);
        $supply            = $state->data['orders'][$orderId]['supplies'][$index];
        $cargo             = $this->context->cargo($state, $id);
        $expected          = array_column($this->context->bundle($state, $supply['bundle_id']), 'quantity', 'sku');
        $totals            = $this->totals($cargo['cargoes']);
        $distributed       = 0;
        foreach ($expected as $sku => $quantity) {
            if (($totals[$sku] ?? 0) === $quantity) {
                ++$distributed;
            }
        }
        $count  = count($cargo['cargoes']);
        $counts = [];
        foreach (array_count_values(array_column($cargo['cargoes'], 'type')) as $type => $quantity) {
            $counts[] = ['type' => $type, 'count' => $quantity];
        }
        $bound        = count(array_filter($cargo['cargoes'], static fn (array $box): bool => $box['transport_cargo_id'] > 0));
        $editable     = in_array($state->data['orders'][$orderId]['state'], ['DATA_FILLING', 'READY_TO_SUPPLY'], true) && strtotime($state->data['orders'][$orderId]['data_filling_deadline_utc'] ?? '9999-01-01') > $now;
        $expirySkus   = [];
        $expiryFilled = [];
        $catalog      = array_column($state->config()['products'], null, 'sku');
        foreach ($expected as $sku => $quantity) {
            if ($catalog[$sku]['expirationRequired'] ?? false) {
                $expirySkus[$sku]   = true;
                $expiryFilled[$sku] = ($totals[$sku] ?? 0) === $quantity;
            }
        }
        foreach ($cargo['cargoes'] as $box) {
            foreach ($box['items'] as $item) {
                if (isset($expirySkus[$item['sku']]) && (!isset($item['expires_at']) || strtotime($item['expires_at']) <= $now)) {
                    $expiryFilled[$item['sku']] = false;
                }
            }
        }
        $expiryCount = count(array_filter($expiryFilled));
        // A cargo is mono-zone when its products share one known placement zone.
        $monoZone = count(array_filter($cargo['cargoes'], static function (array $box) use ($catalog): bool {
            $zones = [];
            foreach ($box['items'] as $item) {
                $zone = $catalog[$item['sku']]['placementZone'] ?? 'UNSPECIFIED';
                if ($zone !== 'UNSPECIFIED') {
                    $zones[$zone] = true;
                }
            }

            return count($zones) <= 1;
        }));

        return ['supply_id'                        => $id,
            'cargoes_presents_rule'                => ['cargo_count_per_type' => $counts, 'count' => $count, 'satisfied' => $count > 0],
            'edit_deadline_expire_rule'            => ['is_applicable' => true, 'is_required' => true, 'satisfied' => $editable],
            'expire_dates_presented_rule'          => ['count_sku_with_expiration' => $expiryCount, 'count_sku_with_expiration_filled' => count($expirySkus), 'is_applicable' => $expirySkus !== [], 'is_required' => $expirySkus !== [], 'satisfied' => $expiryCount === count($expirySkus)],
            'is_valid_distribution_rule'           => ['count_distributed_sku' => $distributed, 'count_sku_total' => count($expected), 'is_applicable' => true, 'percents_int' => count($expected) ? (int) floor(100 * $distributed / count($expected)) : 0, 'satisfied' => $distributed === count($expected) && $count > 0],
            'package_units_with_distribution_rule' => ['count_all' => $count, 'count_with_distribution' => $cargo['is_transport'] ? $bound : $count, 'is_applicable' => $cargo['is_transport'], 'is_required' => $cargo['is_transport'], 'satisfied' => !$cargo['is_transport'] || ($bound === $count && $count > 0)],
            'placement_zones_rule'                 => ['count_cargoes_all' => $count, 'count_cargoes_with_mono_placement_zone' => $monoZone, 'is_applicable' => true, 'satisfied' => $count > 0 && $monoZone === $count],
        ];
    }
}
