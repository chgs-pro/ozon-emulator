<?php

declare(strict_types=1);

namespace App\Feature\Fbs;

use App\Feature\Fbo\CabinetState;
use App\Feature\Fbo\SellerApiException;

use function array_column;
use function array_filter;
use function array_key_first;
use function array_map;
use function array_slice;
use function array_values;
use function count;
use function ctype_digit;
use function gmdate;
use function in_array;
use function sprintf;
use function strtotime;
use function usort;

use const DATE_ATOM;

/** Read side of FBS: warehouses v2, postings v4 (cursor) and a single posting v3. Shapes follow the pinned contract. */
final readonly class FbsReadService
{
    public const array PATHS = ['/v2/warehouse/list', '/v4/posting/fbs/unfulfilled/list', '/v4/posting/fbs/list', '/v3/posting/fbs/get', '/v2/posting/fbs/get-by-barcode'];

    /** Statuses after which a posting no longer appears in the unfulfilled list. */
    private const array FINISHED = ['delivered', 'cancelled', 'cancelled_from_split_pending'];

    public function supports(string $path): bool
    {
        return in_array($path, self::PATHS, true);
    }

    public function read(CabinetState $state, string $path, array $input, int $now): array
    {
        $rows       = FbsConfig::warehouses($state);
        $warehouses = array_column($rows, null, 'id');

        return match ($path) {
            '/v2/warehouse/list'               => $this->warehouses($rows, $input),
            '/v4/posting/fbs/unfulfilled/list' => $this->unfulfilled($state, $warehouses, $input),
            '/v4/posting/fbs/list'             => $this->list($state, $warehouses, $input),
            '/v2/posting/fbs/get-by-barcode'   => $this->byBarcode($state, (string) $input['barcode']),
            default                            => $this->get($state, $warehouses, $input),
        };
    }

    private function warehouses(array $rows, array $input): array
    {
        $ids                    = array_map('intval', $input['warehouse_ids'] ?? []);
        $rows                   = array_values(array_filter($rows, static fn (array $w): bool => $ids === [] || in_array($w['id'], $ids, true)));
        [$page, $cursor, $next] = $this->page($rows, $input, 200);

        return ['warehouses' => array_map($this->warehouse(...), $page), 'cursor' => $cursor, 'has_next' => $next];
    }

    /**
     * Like Ozon, every warehouse returns its first mile: the drop-off point and the timeslot of DROP_OFF, the courier
     * timeslot of PICK_UP (`dropoff_point_id` is empty). Configured warehouses take the first timeslot of the point and
     * a test address; created ones also return what was passed to `/v1/warehouse/fbs/create`.
     */
    private function warehouse(array $w): array
    {
        $row = [
            'warehouse_id' => $w['id'], 'name' => $w['name'], 'status' => $w['status'], 'warehouse_type' => 'FBS',
            'is_rfbs'      => false, 'is_express' => false, 'is_kgt' => $w['is_kgt'] ?? false, 'has_entrusted_acceptance' => false,
            'first_mile'   => ['type' => $w['firstMileType'], 'first_mile_is_changing' => false],
            'working_days' => $w['working_days'] ?? ['MONDAY', 'TUESDAY', 'WEDNESDAY', 'THURSDAY', 'FRIDAY'], 'has_postings_limit' => false, 'postings_limit' => -1,
        ];
        if (!isset($w['created_at'])) {
            $pointId                = (string) ($w['dropOffPointId'] ?? '');
            [$slotId, [$from, $to]] = $pointId !== ''
                ? [(int) ($pointId . '1'), FbsWarehouseService::DROP_OFF_SLOTS[1]]
                : [array_key_first(FbsWarehouseService::PICK_UP_SLOTS), FbsWarehouseService::PICK_UP_SLOTS[array_key_first(FbsWarehouseService::PICK_UP_SLOTS)]];
            $row['first_mile'] += ['dropoff_point_id' => $pointId, 'timeslot_id' => $slotId, 'timeslot_from' => $from, 'timeslot_to' => $to];

            [$latitude, $longitude] = FbsConfig::coordinates($w);

            return $row + ['address_info' => ['address' => $w['address'] ?? 'Тестовый адрес склада ' . $w['id'], 'latitude' => $latitude, 'longitude' => $longitude, 'utc' => 'UTC+03:00']];
        }
        $row['first_mile'] += ['dropoff_point_id' => $w['dropoff_point_id'], 'timeslot_id' => $w['timeslot_id'], 'timeslot_from' => $w['timeslot_from'], 'timeslot_to' => $w['timeslot_to']];

        return $row + [
            'phone'            => $w['phone'], 'cut_in_time' => $w['cut_in_time'], 'courier_comment' => $w['options']['comment'], 'courier_phones' => $w['options']['courier_phones'],
            'is_auto_assembly' => $w['options']['is_auto_assembly'], 'is_waybill_enabled' => $w['options']['is_waybill_enabled'],
            'address_info'     => ['address' => sprintf('Тестовый адрес %.6F, %.6F', $w['latitude'], $w['longitude']), 'latitude' => $w['latitude'], 'longitude' => $w['longitude'], 'utc' => 'UTC+03:00'],
            'created_at'       => gmdate(DATE_ATOM, $w['created_at']), 'updated_at' => gmdate(DATE_ATOM, $w['updated_at']),
        ];
    }

    private function unfulfilled(CabinetState $state, array $warehouses, array $input): array
    {
        $filter = $input['filter'] ?? [];
        if ((isset($filter['cutoff_from']) || isset($filter['cutoff_to'])) && (isset($filter['delivering_date_from']) || isset($filter['delivering_date_to']))) {
            throw new SellerApiException('Use either cutoff or delivering_date period', 400, 3);
        }
        $rows = $this->filtered($state, $filter, static fn (array $p): bool => !in_array($p['status'], self::FINISHED, true)
            && (!isset($filter['cutoff_from']) || $p['shipment_date'] >= strtotime($filter['cutoff_from']))
            && (!isset($filter['cutoff_to']) || $p['shipment_date'] <= strtotime($filter['cutoff_to'])));
        [$page, $cursor, $next] = $this->page($this->sorted($rows, $input), $input, 100);

        return ['count' => count($rows), 'cursor' => $cursor, 'has_next' => $next, 'postings' => array_map(fn (array $p): array => $this->posting($p, $warehouses), $page)];
    }

    private function list(CabinetState $state, array $warehouses, array $input): array
    {
        $filter = $input['filter'];
        $since  = strtotime($filter['since']);
        $to     = strtotime($filter['to']);
        $rows   = $this->filtered($state, $filter, static fn (array $p): bool => $p['in_process_at'] >= $since && $p['in_process_at'] <= $to
            && (!isset($filter['order_id']) || (string) $p['order_id'] === (string) $filter['order_id']));
        [$page, $cursor, $next] = $this->page($this->sorted($rows, $input), $input, 100);

        return ['cursor' => $cursor, 'has_next' => $next, 'postings' => array_map(fn (array $p): array => $this->posting($p, $warehouses), $page)];
    }

    /**
     * v3 posting: confirmed marks in `products[].mandatory_mark`; `with.product_exemplars` — confirmed exemplars,
     * `with.related_postings` — the other postings of the order (split parts), `with.barcodes` — label barcodes.
     */
    private function get(CabinetState $state, array $warehouses, array $input): array
    {
        $p    = $state->data['fbs']['postings'][(string) $input['posting_number']] ?? throw new SellerApiException('Posting not found', 404, 5);
        $with = $input['with'] ?? [];
        $v4   = $this->posting($p, $warehouses);
        unset($v4['delivery_schema'], $v4['integration_type_flow']);
        $v4['products'] = array_map(static function (array $line) use ($state, $p): array {
            [$min, $max] = FbsExemplarService::weightRange($state, $line['sku']);

            return [
                'sku'              => $line['sku'], 'offer_id' => $line['offer_id'], 'name' => $line['name'], 'quantity' => $line['quantity'],
                'price'            => $line['price'], 'currency_code' => 'RUB', 'mandatory_mark' => self::marks($p['exemplars'][$line['sku']] ?? [], 'mandatory_mark'),
                'is_blr_traceable' => false, 'is_marketplace_buyout' => false, 'has_imei' => FbsRequirements::needs($p, 'imei', $line['sku']),
                'is_weight_needed' => FbsRequirements::needs($p, 'weight', $line['sku']), 'weight_min' => $min, 'weight_max' => $max,
            ];
        }, $p['products']);
        if (($with['product_exemplars'] ?? false) === true) {
            $v4['product_exemplars'] = ['products' => array_map(static fn (array $line): array => ['sku' => $line['sku'], 'exemplars' => array_map(static fn (array $e): array => [
                'exemplar_id' => $e['exemplar_id'], 'mandatory_mark' => self::marks([$e], 'mandatory_mark')[0] ?? '', 'gtd' => $e['gtd'], 'is_gtd_absent' => $e['is_gtd_absent'],
                'rnpt'        => $e['rnpt'], 'is_rnpt_absent' => $e['is_rnpt_absent'], 'weight' => $e['weight'], 'imei' => self::marks([$e], 'imei'),
            ], $p['exemplars'][$line['sku']] ?? [])], $p['products'])];
        }
        if (($with['related_postings'] ?? false) === true) {
            $v4['related_postings'] = ['related_posting_numbers' => array_values(array_map(static fn (array $o): string => $o['posting_number'], array_filter(
                $state->data['fbs']['postings'],
                static fn (array $o): bool => $o['order_id'] === $p['order_id'] && $o['posting_number'] !== $p['posting_number'],
            )))];
        }
        if (($with['barcodes'] ?? false) === true) {
            $v4['barcodes'] = FbsPostingGenerator::barcodes($p['posting_number']);
        }

        return ['result' => $v4];
    }

    /** A posting by the upper or lower barcode of its label. */
    private function byBarcode(CabinetState $state, string $barcode): array
    {
        foreach ($state->data['fbs']['postings'] ?? [] as $p) {
            $barcodes = FbsPostingGenerator::barcodes($p['posting_number']);
            if (in_array($barcode, $barcodes, true)) {
                return ['result' => [
                    'barcodes'       => $barcodes, 'cancel_reason_id' => $p['cancellation']['cancel_reason_id'] ?? 0, 'created_at' => gmdate(DATE_ATOM, $p['in_process_at']),
                    'in_process_at'  => gmdate(DATE_ATOM, $p['in_process_at']), 'order_id' => $p['order_id'], 'order_number' => $p['order_number'],
                    'posting_number' => $p['posting_number'], 'shipment_date' => gmdate(DATE_ATOM, $p['shipment_date']), 'status' => $p['status'],
                    'products'       => array_map(static fn (array $line): array => ['name' => $line['name'], 'offer_id' => $line['offer_id'], 'price' => $line['price'],
                        'quantity'                                                          => $line['quantity'], 'sku' => $line['sku']], $p['products']),
                ]];
            }
        }

        throw new SellerApiException('Posting not found', 404, 5);
    }

    /** @return list<string> codes of the given type from exemplars */
    private static function marks(array $exemplars, string $type): array
    {
        $codes = [];
        foreach ($exemplars as $exemplar) {
            foreach ($exemplar['marks'] as $mark) {
                if ($mark['mark_type'] === $type) {
                    $codes[] = $mark['mark'];
                }
            }
        }

        return $codes;
    }

    /** @return list<array> postings matching the common v4 filters and the endpoint-specific predicate */
    private function filtered(CabinetState $state, array $filter, callable $predicate): array
    {
        $statuses = $filter['statuses'] ?? [];
        $stores   = array_map('intval', $filter['warehouse_ids'] ?? []);
        $methods  = array_map('intval', $filter['delivery_method_ids'] ?? []);

        return array_values(array_filter($state->data['fbs']['postings'] ?? [], static fn (array $p): bool => $predicate($p)
            && ($statuses === [] || in_array($p['status'], $statuses, true))
            && ($stores === [] || in_array($p['warehouse_id'], $stores, true))
            && ($methods === [] || in_array($p['delivery_method_id'], $methods, true))));
    }

    private function sorted(array $rows, array $input): array
    {
        $desc = ($input['sort_dir'] ?? 'ASC') === 'DESC';
        usort($rows, static fn (array $a, array $b): int => ($desc ? -1 : 1) * ([$a['in_process_at'], $a['posting_number']] <=> [$b['in_process_at'], $b['posting_number']]));

        return $rows;
    }

    /** Cursor is the offset of the next page as a decimal string; an unknown cursor is rejected. */
    private function page(array $rows, array $input, int $max): array
    {
        $limit  = (int) ($input['limit'] ?? $max);
        $cursor = (string) ($input['cursor'] ?? '');
        if ($limit < 1 || $limit > $max || ($cursor !== '' && !ctype_digit($cursor))) {
            throw new SellerApiException('Invalid limit or cursor', 400, 3);
        }
        $offset = (int) $cursor;
        $next   = $offset + $limit < count($rows);

        return [array_slice($rows, $offset, $limit), $next ? (string) ($offset + $limit) : '', $next];
    }

    private function posting(array $p, array $warehouses): array
    {
        $warehouse = $warehouses[$p['warehouse_id']] ?? ['name' => '', 'deliveryMethods' => []];
        $method    = array_column($warehouse['deliveryMethods'], null, 'id')[$p['delivery_method_id']] ?? ['name' => ''];

        return [
            'posting_number'  => $p['posting_number'], 'order_id' => $p['order_id'], 'order_number' => $p['order_number'], 'parent_posting_number' => $p['parent_posting_number'] ?? '',
            'status'          => $p['status'], 'substatus' => $p['substatus'], 'available_actions' => $p['available_actions'],
            'delivery_method' => ['id' => $p['delivery_method_id'], 'name' => $method['name'], 'warehouse_id' => $p['warehouse_id'], 'warehouse' => $warehouse['name'],
                'tpl_provider'         => 'Ozon Логистика', 'tpl_provider_id' => 24],
            'in_process_at'               => gmdate(DATE_ATOM, $p['in_process_at']), 'shipment_date' => gmdate(DATE_ATOM, $p['shipment_date']),
            'shipment_date_without_delay' => gmdate(DATE_ATOM, $p['shipment_date']),
            'products'                    => array_map(static fn (array $line): array => ['sku' => $line['sku'], 'offer_id' => $line['offer_id'], 'name' => $line['name'],
                'quantity'                                                                      => $line['quantity'], 'price' => ['amount' => $line['price'], 'currency' => 'RUB'], 'imei' => [], 'is_blr_traceable' => false,
                'is_marketplace_buyout'                                                         => false], $p['products']),
            ...FbsRequirements::v4($p),
            'is_multibox'          => ($p['multi_box_qty'] ?? 1) > 1, 'multi_box_qty' => $p['multi_box_qty'] ?? 1, 'is_express' => false, 'require_blr_traceable_attrs' => false,
            'tpl_integration_type' => 'ozon', 'integration_type_flow' => 'ozon', 'delivery_schema' => 'FBS',
            ...(isset($p['cancellation']) ? ['cancellation' => $p['cancellation']] : []),
        ];
    }
}
