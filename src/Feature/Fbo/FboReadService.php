<?php

declare(strict_types=1);

namespace App\Feature\Fbo;

use App\Feature\Fbs\FbsApiService;
use App\Feature\Fbs\FbsConfig;
use App\Feature\Token\TokenIdentity;
use DateTimeImmutable;
use DateTimeZone;

use function array_column;
use function array_filter;
use function array_key_last;
use function array_map;
use function array_search;
use function array_slice;
use function array_unique;
use function array_values;
use function count;
use function gmdate;
use function in_array;
use function is_int;
use function mb_stripos;
use function mb_strtolower;
use function reset;
use function str_contains;
use function str_starts_with;
use function strtolower;
use function strtotime;
use function usort;

use const DATE_ATOM;

final readonly class FboReadService
{
    public function __construct(
        private Contract $contract,
        private DraftPlanner $planner,
        private TimeslotService $timeslots,
    ) {
    }

    public function read(CabinetState $state, TokenIdentity $identity, string $path, array $input, int $now): array
    {
        $state->authorize(false);
        $c          = $state->config();
        $warehouses = array_column($c['warehouses'], null, 'id');
        if ($path === '/v1/roles') {
            $methods = array_values(array_filter($this->contract->paths(), static fn (string $method): bool => ($c['writeEnabled'] || !OperationCatalog::isWrite($method)) && !in_array($method, $state->data['disabled_paths'] ?? [], true)
                && (FbsConfig::enabled($c) || !in_array($method, FbsApiService::PATHS, true))));

            return ['expires_at' => gmdate(DATE_ATOM, $identity->expiresAt), 'roles' => [['name' => $c['roleName'], 'methods' => $methods]]];
        }
        if (str_starts_with($path, '/v3/product/')) {
            return $this->products($c['products'], $path, $input);
        }
        if ($path === '/v1/cluster/list') {
            $clusters = array_filter($c['clusters'], static fn (array $row): bool => $input['cluster_type'] === 'CLUSTER_TYPE_OZON' && (empty($input['cluster_ids']) || in_array((string) $row['id'], array_map('strval', $input['cluster_ids']), true)));

            return ['clusters' => array_values(array_map(static fn (array $row): array => ['id' => $row['id'], 'macrolocal_cluster_id' => $row['macrolocalId'], 'name' => $row['name'], 'type' => 'CLUSTER_TYPE_OZON', 'logistic_clusters' => [['warehouses' => array_map(static fn (int $id): array => ['warehouse_id' => $id, 'name' => $warehouses[$id]['name'], 'type' => $warehouses[$id]['type']], $row['warehouseIds'])]]], $clusters))];
        }
        if ($path === '/v2/cluster/list') {
            return ['result' => array_map(static fn (array $row): array => ['macrolocal_cluster_id' => $row['macrolocalId'], 'data' => ['macrolocal_cluster' => ['name' => $row['name']], 'fulfillments' => array_map(static fn (int $id): array => ['warehouse_id' => $id, 'name' => $warehouses[$id]['name']], $row['warehouseIds'])]], $c['clusters'])];
        }
        if ($path === '/v1/warehouse/fbo/list') {
            $ids = [];
            foreach ($c['routes'] as $route) {
                if (in_array('CREATE_TYPE_' . ($route['type'] === 'MULTI_CLUSTER' ? 'CROSSDOCK' : $route['type']), $input['filter_by_supply_type'], true)) {
                    $ids[] = $route['dropoffWarehouseId'];
                }
            }
            $rows = array_filter($c['warehouses'], static fn (array $w): bool => in_array($w['id'], $ids, true) && mb_stripos($w['name'], $input['search']) !== false);

            return ['search' => array_values(array_map(fn (array $w): array => $this->planner->warehouse($w) + ['warehouse_type' => 'WAREHOUSE_TYPE_' . $w['type']], $rows))];
        }
        if ($path === '/v1/supply-order/bundle') {
            return $this->bundle($state, $input);
        }
        if ($path === '/v3/supply-order/list') {
            return $this->orders($state, $input);
        }
        if ($path === '/v3/supply-order/get') {
            $orders = [];
            foreach ($input['order_ids'] as $id) {
                $orders[] = $state->data['orders'][(string) $id] ?? throw new SellerApiException('Order not found', 404, 5);
            }

            return ['orders' => $orders];
        }
        if ($path === '/v1/supply-order/details') {
            $order                         = $state->data['orders'][(string) $input['order_id']] ?? throw new SellerApiException('Order not found', 404, 5);
            $order['dropoff_warehouse_id'] = $order['dropoff_warehouse']['warehouse_id'];
            unset($order['dropoff_warehouse']);
            $editable          = in_array($order['state'], ['DATA_FILLING', 'READY_TO_SUPPLY'], true) && strtotime($order['data_filling_deadline_utc'] ?? '9999-01-01') > $now;
            $order['timeslot'] = ['can_set' => $editable, 'can_not_set_reasons' => $editable ? [] : ['INVALID_ORDER_STATE'], 'value' => $order['timeslot']];
            $order['vehicle']  = ['can_set' => $editable, 'can_not_set_reasons' => $editable ? [] : ['INVALID_ORDER_STATE'], 'value' => $state->data['vehicles'][$order['order_id']] ?? []];
            $order['supplies'] = array_map(static function (array $s) use ($editable, $state): array {
                $s['supply_state']              = $s['state'];
                $utd                            = $state->data['requirements'][$s['supply_id']]['utdUploaded'] ?? false;
                $s['content']                   = ['bundle_id' => $s['bundle_id'], 'can_set' => $editable && !$utd, 'can_not_set_reasons' => !$editable ? ['INCORRECT_SUPPLY_STATE'] : ($utd ? ['UTD_IS_UPLOADED'] : [])];
                $s['cancellation_allowability'] = ['can_set' => $editable && !$utd, 'can_not_set_reasons' => !$editable ? ['INVALID_SUPPLY_STATE'] : ($utd ? ['SUPPLY_HAS_ACTIVE_UTD'] : [])];
                $s['ettn_info']                 = ['is_required' => $s['supply_tags']['is_ettn_required'], 'is_uploaded' => $state->data['requirements'][$s['supply_id']]['ettnUploaded'] ?? false, 'contains_valid' => $state->data['requirements'][$s['supply_id']]['ettnUploaded'] ?? false];
                unset($s['state'], $s['bundle_id']);

                return $s;
            }, $order['supplies']);

            return $order;
        }
        $draft = $state->data['drafts'][(string) ($input['draft_id'] ?? '')] ?? throw new SellerApiException('Draft not found', 404, 5);
        if ($path === '/v2/draft/supply/create/status') {
            $op = $draft['supply_operation'];
            if ($op === null) {
                return ['status' => 'FAILED', 'error_reasons' => ['DRAFT_INCORRECT_STATE']];
            }
            if ($op['ready_at'] > $now) {
                return ['status' => 'IN_PROGRESS', 'error_reasons' => []];
            }

            return $op['failed'] ? ['status' => 'FAILED', 'error_reasons' => ['SOME_SERVICE_ERROR']] : ['status' => 'SUCCESS', 'order_id' => $op['order_id'], 'error_reasons' => []];
        }
        if ($draft['expires_at'] <= $now) {
            throw new SellerApiException('Draft expired', 404, 5);
        }
        if ($path === '/v2/draft/create/info') {
            if ($draft['ready_at'] > $now) {
                return ['status' => 'IN_PROGRESS', 'clusters' => [], 'errors' => []];
            }

            $errors = $draft['errors'] ?? [];
            if ($draft['failed']) {
                $errors[] = ['error_message' => 'CAN_NOT_CREATE_DRAFT', 'message' => 'Configured refusal or no eligible items/routes'];
            }

            return ['status' => $draft['failed'] ? 'FAILED' : 'SUCCESS', 'clusters' => $draft['clusters'], 'errors' => $errors];
        }
        if ($path === '/v2/draft/timeslot/info') {
            $selected = $this->planner->selection($draft, $input, $now);
            $dropoff  = $draft['type'] === 'DIRECT' ? reset($selected)['storage_warehouse']['warehouse_id'] : $draft['dropoff_id'];

            return ['result' => $this->timeslots->slots($state, $dropoff, $now, $input['date_from'], $input['date_to'])];
        }

        throw new SellerApiException('Method not implemented', 404, 5);
    }

    private function products(array $products, string $path, array $input): array
    {
        $filter = $path === '/v3/product/list' ? ($input['filter'] ?? []) : $input;
        if ($path === '/v3/product/info/list' && empty($filter['product_id']) && empty($filter['offer_id']) && empty($filter['sku'])) {
            throw new SellerApiException('Product identifiers required');
        }
        foreach (['product_id' => 'productId', 'offer_id' => 'offerId', 'sku' => 'sku', 'skus' => 'sku'] as $key => $field) {
            if (!empty($filter[$key])) {
                $products = array_values(array_filter($products, static fn (array $p): bool => in_array((string) $p[$field], array_map('strval', $filter[$key]), true)));
            }
        }
        if ($path === '/v3/product/info/list') {
            return ['items' => array_map(static fn (array $p): array => ['id' => $p['productId'], 'offer_id' => $p['offerId'], 'name' => $p['name'], 'barcodes' => $p['barcodes'], 'sku' => $p['sku'], 'sources' => [['sku' => $p['sku'], 'source' => 'sds']], 'images' => [], 'primary_image' => [], 'is_archived' => false, 'is_autoarchived' => false, 'description_category_id' => $p['descriptionCategoryId'] ?? 0, 'type_id' => $p['typeId'] ?? 0], $products)];
        }
        if (isset($filter['visibility']) && !in_array($filter['visibility'], ['ALL','VISIBLE'], true)) {
            throw new SellerApiException('Visibility filter not implemented by this test profile');
        }
        usort($products, static fn (array $a, array $b): int => $a['productId'] <=> $b['productId']);
        [$page,$cursor] = $this->page($products, $input, 'productId', 1000);

        return ['result' => ['items' => array_map(static fn (array $p): array => ['product_id' => $p['productId'], 'offer_id' => $p['offerId'], 'is_fbo_visible' => true, 'is_fbs_visible' => false, 'archived' => false], $page), 'total' => count($products), 'last_id' => $cursor]];
    }

    private function bundle(CabinetState $state, array $input): array
    {
        // This profile's item tags come from the configured catalogue.
        $all = $state->data['bundles'];
        foreach ($state->data['drafts'] as $draft) {
            $all += $draft['bundles'];
        }
        $items = [];
        foreach (array_unique($input['bundle_ids']) as $id) {
            foreach ($all[$id] ?? throw new SellerApiException('Bundle not found', 404, 5) as $item) {
                $key = (string) $item['sku'];
                if (isset($items[$key])) {
                    $items[$key]['quantity'] += $item['quantity'];
                    $items[$key]['total_volume_in_litres'] = $items[$key]['quantity'] * ($items[$key]['volume_in_litres'] ?? 0);
                } else {
                    $items[$key] = $item;
                }
            }
        }
        $query     = mb_strtolower($input['query'] ?? '');
        $items     = array_values(array_filter($items, static fn (array $item): bool => $query === '' || str_contains(mb_strtolower(($item['name'] ?? '') . ' ' . ($item['offer_id'] ?? '') . ' ' . $item['sku']), $query)));
        $field     = strtolower($input['sort_field'] ?? 'SKU');
        $direction = ($input['is_asc'] ?? true) ? 1 : -1;
        usort($items, static fn (array $a, array $b): int => (($a[$field] ?? '') <=> ($b[$field] ?? '') ?: $a['sku'] <=> $b['sku']) * $direction);
        [$page,$cursor,$hasNext] = $this->page($items, $input, 'sku', 100);

        return ['items' => $page, 'total_count' => count($items), 'has_next' => $hasNext, 'last_id' => $cursor];
    }

    private function orders(CabinetState $state, array $input): array
    {
        $filter = $input['filter'];
        $orders = array_values(array_filter($state->data['orders'], static function (array $order) use ($filter): bool {
            if ($filter['states'] !== [] && !in_array($order['state'], $filter['states'], true)) {
                return false;
            }
            if (!empty($filter['dropoff_warehouse_ids']) && !in_array((string) $order['dropoff_warehouse']['warehouse_id'], array_map('strval', $filter['dropoff_warehouse_ids']), true)) {
                return false;
            }
            if (isset($filter['order_number_search']) && !str_contains($order['order_number'], $filter['order_number_search'])) {
                return false;
            }
            if (isset($filter['timeslot_from_range'])) {
                $range = $filter['timeslot_from_range'];
                $date  = $order['timeslot']['timeslot']['from'];
                $zone  = new DateTimeZone(($range['timeslot_filter_type'] ?? 'BY_UTC_TIME') === 'BY_LOCAL_TIME' ? $order['timeslot']['timezone_info']['iana_name'] : 'UTC');

                if (isset($range['from']) && strtotime($date) < new DateTimeImmutable($range['from'], $zone)->getTimestamp()) {
                    return false;
                }
                if (isset($range['to']) && strtotime($date) > new DateTimeImmutable($range['to'], $zone)->getTimestamp()) {
                    return false;
                }
            }

            return true;
        }));
        $key = static function (array $o) use ($input): string {
            return match ($input['sort_by']) {
                'ORDER_CREATION'      => $o['created_date'], 'ORDER_STATE_UPDATED_AT' => $o['state_updated_date'],
                'TIMESLOT_FROM_UTC'   => $o['timeslot']['timeslot']['from'],
                'TIMESLOT_FROM_LOCAL' => new DateTimeImmutable($o['timeslot']['timeslot']['from'])->setTimezone(new DateTimeZone($o['timeslot']['timezone_info']['iana_name']))->format('Y-m-d\TH:i:s'),
            };
        };
        $direction = ($input['sort_dir'] ?? 'ASC') === 'ASC' ? 1 : -1;
        usort($orders, static fn (array $a, array $b): int => ($key($a) <=> $key($b) ?: $a['order_id'] <=> $b['order_id']) * $direction);
        [$page,$cursor] = $this->page($orders, $input, 'order_id', 100);

        return ['order_ids' => array_map(static fn (array $o): string => (string) $o['order_id'], $page), 'last_id' => $cursor];
    }

    private function page(array $items, array $input, string $idField, int $max): array
    {
        $limit = $input['limit'] ?? $max;
        if (!is_int($limit) || $limit < 1 || $limit > $max) {
            throw new SellerApiException('Invalid limit');
        }
        $offset = 0;
        $cursor = $input['last_id'] ?? '';
        if ($cursor !== '') {
            $index = array_search($cursor, array_map(static fn (array $item): string => (string) $item[$idField], $items), true);
            if ($index === false) {
                throw new SellerApiException('Invalid cursor');
            }
            $offset = $index + 1;
        }
        $page    = array_slice($items, $offset, $limit);
        $hasNext = $offset + count($page) < count($items);

        return [$page, $hasNext ? (string) $page[array_key_last($page)][$idField] : '', $hasNext];
    }
}
