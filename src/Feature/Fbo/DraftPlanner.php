<?php

declare(strict_types=1);

namespace App\Feature\Fbo;

use function array_column;
use function array_filter;
use function array_unique;
use function array_values;
use function count;
use function in_array;
use function min;

/** Deterministic calculation against the configuration captured by the draft. */
final readonly class DraftPlanner
{
    public function calculate(CabinetState $state, string $type, array $input, int $now): array
    {
        $config = $state->config();
        $groups = $type === 'MULTI_CLUSTER' ? $input['clusters_info'] : [$input['cluster_info']];
        if ($groups === [] || count($groups) > 20) {
            throw new SellerApiException('Invalid clusters count');
        }
        $delivery = $input['delivery_info'] ?? [];
        if ($type !== 'DIRECT' && ($delivery['type'] ?? '') !== 'DROPOFF') {
            throw new SellerApiException('PICKUP is not enabled in this local contract profile', 400, 3);
        }
        $dropoff    = (int) ($delivery['drop_off_warehouse']['warehouse_id'] ?? 0);
        $products   = array_column($config['products'], null, 'sku');
        $clusters   = array_column($config['clusters'], null, 'macrolocalId');
        $warehouses = array_column($config['warehouses'], null, 'id');
        if ($type !== 'DIRECT' && (!isset($warehouses[$dropoff]) || ($delivery['drop_off_warehouse']['warehouse_type'] ?? '') !== $warehouses[$dropoff]['type'])) {
            throw new SellerApiException('Invalid drop-off warehouse');
        }
        $draftId          = $state->id();
        $bundles          = $result = $seen = [];
        $validationErrors = [];
        foreach ($groups as $group) {
            $clusterId = (int) $group['macrolocal_cluster_id'];
            if (!isset($clusters[$clusterId]) || isset($seen[$clusterId])) {
                throw new SellerApiException('Unknown or duplicate cluster ID');
            }
            $seen[$clusterId] = true;
            if ($group['items'] === []) {
                throw new SellerApiException('Empty items list');
            }
            $options = [];
            foreach ($clusters[$clusterId]['warehouseIds'] as $warehouseId) {
                $route = null;
                foreach ($config['routes'] as $candidate) {
                    if ($candidate['type'] === $type && in_array($clusterId, $candidate['clusterIds'], true) && $candidate['dropoffWarehouseId'] === ($type === 'DIRECT' ? $warehouseId : $dropoff)) {
                        $route = $candidate;
                        break;
                    }
                }
                if ($route === null) {
                    continue;
                }
                $accepted      = $rejected = $skuSeen = $tags = [];
                $rejectedItems = [];
                foreach ($group['items'] as $line) {
                    $sku      = (int) $line['sku'];
                    $quantity = $line['quantity'];
                    if ($sku <= 0 || $quantity <= 0 || isset($skuSeen[$sku])) {
                        throw new SellerApiException('Invalid quantity or duplicate SKU');
                    }
                    $skuSeen[$sku] = true;
                    $product       = $products[$sku] ?? null;
                    $allowed       = $product !== null && in_array($sku, $route['allowedSkus'], true) ? min($quantity, $product['maxQuantity']) : 0;
                    if ($product !== null) {
                        $allowed -= $allowed % $product['quant'];
                    }
                    if ($input['deletion_sku_mode'] === 'FULL' && $allowed < $quantity) {
                        $allowed = 0;
                    }
                    if ($allowed > 0) {
                        $accepted[] = $this->item($product, $allowed);
                        $tags       = array_values(array_unique([...$tags, ...$product['tags'], ...($product['supplyTags'] ?? [])]));
                    }
                    if ($allowed < $quantity) {
                        $rejected[] = $product === null ? ['sku' => $sku, 'quantity' => $quantity] : $this->item($product, $quantity - $allowed);
                        $reason     = match (true) {
                            $product === null                            => 'OUT_OF_ASSORTMENT',
                            !in_array($sku, $route['allowedSkus'], true) => 'INCOMPATIBLE_WAREHOUSE',
                            $quantity > $product['maxQuantity']          => 'INVALID_ITEM_COUNT_MAX',
                            default                                      => 'MULTIPLICITY',
                        };
                        $rejectedItems[] = ['sku' => $sku, 'reasons' => [$reason]];
                    }
                }
                if ($rejectedItems !== []) {
                    $validationErrors[] = ['error_message' => 'ITEMS_VALIDATION', 'items_validation' => [['macrolocal_cluster_id' => $clusterId, 'rejected_items' => $rejectedItems]]];
                }
                $bundleId               = 'bundle-' . $state->id();
                $restrictedId           = 'bundle-' . $state->id();
                $bundles[$bundleId]     = $accepted;
                $bundles[$restrictedId] = $rejected;
                $options[]              = ['availability_status' => ['state' => $accepted === [] ? 'NOT_AVAILABLE' : ($rejected === [] ? 'FULL_AVAILABLE' : 'PARTIAL_AVAILABLE')], 'bundle_id' => $bundleId, 'restricted_bundle_id' => $restrictedId, 'storage_warehouse' => $this->warehouse($warehouses[$warehouseId]), 'supply_tags' => $tags];
            }
            $result[] = ['cluster_name' => $clusters[$clusterId]['name'], 'macrolocal_cluster_id' => $clusterId, 'supply_type' => $type, 'warehouses' => $options];
        }
        $failed = $config['draftFailure'] || !$config['contractActive'];
        foreach ($result as $cluster) {
            if (count(array_filter($cluster['warehouses'], static fn (array $w): bool => $w['availability_status']['state'] !== 'NOT_AVAILABLE')) === 0) {
                $failed = true;
            }
        }

        return ['id' => $draftId, 'type' => $type, 'dropoff_id' => $dropoff, 'input' => $input, 'config_version' => $state->data['config_version'], 'created_at' => $now, 'expires_at' => $now + $config['draftLifetimeSeconds'], 'ready_at' => $now + $config['operationDelaySeconds'], 'failed' => $failed, 'clusters' => $result, 'bundles' => $bundles, 'errors' => $validationErrors, 'supply_operation' => null];
    }

    public function item(array $p, int $quantity): array
    {
        return ['sku' => $p['sku'], 'product_id' => $p['productId'], 'offer_id' => $p['offerId'], 'name' => $p['name'], 'quantity' => $quantity, 'barcode' => $p['barcodes'][0], 'quant' => $p['quant'], 'volume_in_litres' => (float) $p['volumeLitres'], 'total_volume_in_litres' => $quantity * $p['volumeLitres'], 'tags' => $p['tags'], 'placement_zone' => $p['placementZone'] ?? 'UNSPECIFIED'];
    }

    public function warehouse(array $w): array
    {
        return ['warehouse_id' => $w['id'], 'name' => $w['name'], 'address' => $w['address']];
    }

    public function selection(array $draft, array $input, int $now): array
    {
        if ($draft['expires_at'] <= $now || $draft['ready_at'] > $now || $draft['failed']) {
            throw new SellerApiException('Draft is expired or not ready');
        }
        if ($input['supply_type'] !== $draft['type']) {
            throw new SellerApiException('Invalid supply type');
        }
        $selected = $input['selected_cluster_warehouses'];
        if (count($selected) !== count($draft['clusters'])) {
            throw new SellerApiException('All calculated clusters must be selected');
        }
        $result = [];
        foreach ($selected as $item) {
            $id = (int) $item['macrolocal_cluster_id'];
            if (isset($result[$id])) {
                throw new SellerApiException('Duplicate selected cluster');
            }
            $cluster = array_values(array_filter($draft['clusters'], static fn (array $c): bool => $c['macrolocal_cluster_id'] === $id))[0] ?? null;
            if ($cluster === null) {
                throw new SellerApiException('Unknown selected cluster');
            }
            $options = array_values(array_filter($cluster['warehouses'], static fn (array $w): bool => $w['availability_status']['state'] !== 'NOT_AVAILABLE'));
            if ($draft['type'] === 'DIRECT') {
                $options = array_values(array_filter($options, static fn (array $w): bool => $w['storage_warehouse']['warehouse_id'] === (int) ($item['storage_warehouse_id'] ?? 0)));
            } elseif (isset($item['storage_warehouse_id'])) {
                throw new SellerApiException('storage_warehouse_id is DIRECT-only in this contract profile');
            }
            if ($options === []) {
                throw new SellerApiException('Invalid storage warehouse');
            }
            $result[$id] = $options[0];
        }

        return $result;
    }
}
