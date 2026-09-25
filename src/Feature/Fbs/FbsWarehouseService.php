<?php

declare(strict_types=1);

namespace App\Feature\Fbs;

use App\Feature\Fbo\CabinetState;
use App\Feature\Fbo\SellerApiException;

use function abs;
use function array_column;
use function array_filter;
use function array_map;
use function array_merge;
use function array_slice;
use function array_unique;
use function array_values;
use function bin2hex;
use function count;
use function in_array;
use function is_float;
use function is_int;
use function mb_stripos;
use function preg_match;
use function random_bytes;
use function round;
use function sprintf;
use function substr;
use function trim;

/**
 * FBS warehouse creation like the real Ozon wizard: drop-off/pick-up timeslots, return mile and return points are
 * a small fixed catalog placed around the requested coordinates; `/v1/warehouse/fbs/create` stores a `new` warehouse
 * in the cabinet state and queues CREATE_FBS_WAREHOUSE, which completes after `operationDelaySeconds` (cabinet clock).
 */
final readonly class FbsWarehouseService
{
    public const array PATHS = [
        '/v1/warehouse/fbs/create/drop-off/list', '/v1/warehouse/fbs/create/drop-off/timeslot/list', '/v1/warehouse/fbs/create/pick-up/timeslot/list',
        '/v1/warehouse/fbs/return-mile/check', '/v1/warehouse/fbs/create/return-point/list', '/v1/warehouse/fbs/create', '/v1/warehouse/operation/status',
        '/v1/warehouse/fbs/update/drop-off/list',
    ];

    /** Used when the drop-off search has no coordinates: the centre of Moscow. */
    public const array DEFAULT_COORDINATES = [55.755800, 37.617300];

    /** Drop-off points; `offset` shifts latitude/longitude from the requested point. Only SC accepts oversized (KGT) goods. */
    public const array DROP_OFF_POINTS = [
        ['id' => '1040001', 'type' => 'PVZ', 'address' => 'Пункт выдачи Ozon, Тестовая ул., 1', 'offset' => [0.004, 0.006], 'discount' => 5.0, 'lastTransitHour' => 18],
        ['id' => '1040002', 'type' => 'PVZ', 'address' => 'Пункт выдачи Ozon, Тестовая ул., 15', 'offset' => [-0.007, 0.003], 'discount' => 5.0, 'lastTransitHour' => 19],
        ['id' => '1040003', 'type' => 'PPZ', 'address' => 'Пункт приёма Ozon, Проверочный пр., 7', 'offset' => [0.012, -0.009], 'discount' => 10.0, 'lastTransitHour' => 17],
        ['id' => '1040004', 'type' => 'SC', 'address' => 'Сортировочный центр Ozon, Складская ул., 3', 'offset' => [-0.03, 0.025], 'discount' => 15.0, 'lastTransitHour' => 20],
    ];

    /** Drop-off windows of every point; the timeslot ID is `<point id><n>`. */
    public const array DROP_OFF_SLOTS = [1 => ['10:00', '14:00'], 2 => ['14:00', '18:00']];

    public const array PICK_UP_SLOTS = [1050001 => ['09:00', '13:00'], 1050002 => ['13:00', '18:00'], 1050003 => ['18:00', '21:00']];

    /** Return points; the first four are at the drop-off points' addresses (`dropOff`), so a selected drop-off point can take returns. */
    private const array RETURN_POINTS = [
        ['id' => 1060001, 'dropOff' => '1040001', 'type' => 'PVZ', 'name' => 'ПВЗ Ozon Тестовая 1', 'address' => 'Тестовая ул., 1', 'offset' => [0.004, 0.006]],
        ['id' => 1060002, 'dropOff' => '1040002', 'type' => 'PVZ', 'name' => 'ПВЗ Ozon Тестовая 15', 'address' => 'Тестовая ул., 15', 'offset' => [-0.007, 0.003]],
        ['id' => 1060003, 'dropOff' => '1040003', 'type' => 'PPZ', 'name' => 'ППЗ Ozon Проверочный 7', 'address' => 'Проверочный пр., 7', 'offset' => [0.012, -0.009]],
        ['id' => 1060004, 'dropOff' => '1040004', 'type' => 'SC', 'name' => 'СЦ Ozon Складская 3', 'address' => 'Складская ул., 3', 'offset' => [-0.03, 0.025]],
        ['id' => 1060005, 'dropOff' => '', 'type' => 'PVZ', 'name' => 'ПВЗ Ozon Тестовый бульвар 21', 'address' => 'Тестовый б-р, 21', 'offset' => [0.018, 0.014]],
        ['id' => 1060006, 'dropOff' => '', 'type' => 'PPZ', 'name' => 'ППЗ Ozon Контрольная 4', 'address' => 'Контрольная ул., 4', 'offset' => [-0.015, -0.02]],
    ];

    private const array WEEKDAYS = ['MONDAY', 'TUESDAY', 'WEDNESDAY', 'THURSDAY', 'FRIDAY'];

    private const string PHONE = '/^\+7\(\d{3}\)\d{3}-\d{2}-\d{2}$/D';

    public function supports(string $path): bool
    {
        return in_array($path, self::PATHS, true);
    }

    public function handle(CabinetState $state, string $path, array $input, int $now): array
    {
        return match ($path) {
            '/v1/warehouse/fbs/create/drop-off/list'          => ['points' => $this->dropOffPoints($input)],
            '/v1/warehouse/fbs/update/drop-off/list'          => ['points' => $this->warehouseDropOffPoints($state, $input)],
            '/v1/warehouse/fbs/create/drop-off/timeslot/list' => ['timeslots' => $this->dropOffSlots($this->dropOffPoint((string) $input['drop_off_point_id'], 404))],
            '/v1/warehouse/fbs/create/pick-up/timeslot/list'  => $this->pickUpSlots($input),
            '/v1/warehouse/fbs/return-mile/check'             => $this->returnMile($state, $input),
            '/v1/warehouse/fbs/create/return-point/list'      => $this->returnPoints($input),
            '/v1/warehouse/fbs/create'                        => $this->create($state, $input, $now),
            default                                           => $this->status($state, $input),
        };
    }

    /** Completes due CREATE_FBS_WAREHOUSE operations; called before every FBS request and by the generator. */
    public function advance(CabinetState $state, int $now): void
    {
        foreach ($state->data['fbs']['operations'] ?? [] as $id => $op) {
            if ($op['status'] !== 'IN_PROGRESS' || $op['ready_at'] > $now) {
                continue;
            }
            $op['status']                                                        = $op['failure'] ? 'ERROR' : 'SUCCESS';
            $op['completed_at']                                                  = $now;
            $state->data['fbs']['operations'][$id]                               = $op;
            $state->data['fbs']['warehouses'][$op['warehouse_id']]['status']     = $op['failure'] ? 'error' : 'created';
            $state->data['fbs']['warehouses'][$op['warehouse_id']]['updated_at'] = $now;
            $state->event('fbs.warehouse.' . ($op['failure'] ? 'failed' : 'created'), $now, ['operation_id' => $id, 'warehouse_id' => $op['warehouse_id']]);
        }
    }

    private function dropOffPoints(array $input): array
    {
        [$lat, $lon] = $this->coordinates($input['coordinates'] ?? null);
        $types       = $input['search']['types'] ?? [];
        $address     = trim($input['search']['address'] ?? '');
        if ($input['country_code'] !== 'RU') {
            return [];
        }
        $points = array_filter(self::DROP_OFF_POINTS, static fn (array $p): bool => (!$input['is_kgt'] || $p['type'] === 'SC')
            && ($types === [] || in_array($p['type'], $types, true)) && ($address === '' || mb_stripos($p['address'], $address) !== false));

        return array_values(array_map(static fn (array $p): array => [
            'id'                      => $p['id'], 'address' => $p['address'], 'type' => $p['type'], 'discount_percent' => $p['discount'],
            'coordinates'             => ['latitude' => round($lat + $p['offset'][0], 6), 'longitude' => round($lon + $p['offset'][1], 6)],
            'last_transit_time_local' => ['hours' => $p['lastTransitHour'], 'minutes' => 0, 'seconds' => 0, 'nanos' => 0],
        ], $points));
    }

    /**
     * Points a warehouse can switch its first mile to, around its coordinates and by its oversize flag; the current
     * point of the warehouse is among them. An unknown warehouse — 404.
     */
    private function warehouseDropOffPoints(CabinetState $state, array $input): array
    {
        $warehouse   = array_column(FbsConfig::warehouses($state), null, 'id')[(int) $input['warehouse_id']] ?? throw new SellerApiException('Warehouse not found', 404, 5);
        [$lat, $lon] = FbsConfig::coordinates($warehouse);

        return $this->dropOffPoints([
            'coordinates' => ['latitude' => $lat, 'longitude' => $lon], 'country_code' => 'RU',
            'is_kgt'      => (bool) ($warehouse['is_kgt'] ?? false), 'search' => $input['search'] ?? [],
        ]);
    }

    private function dropOffPoint(string $id, int $status = 400): array
    {
        return array_column(self::DROP_OFF_POINTS, null, 'id')[$id] ?? throw new SellerApiException('Drop-off point not found', $status, $status === 404 ? 5 : 3);
    }

    private function dropOffSlots(array $point): array
    {
        $slots = [];
        foreach (self::DROP_OFF_SLOTS as $n => [$from, $to]) {
            $slots[] = ['id'                => (int) ($point['id'] . $n), 'from' => $from, 'to' => $to, 'acceptance_start_time_local' => '09:00',
                'acceptance_end_time_local' => sprintf('%02d:00', $point['lastTransitHour'])];
        }

        return $slots;
    }

    /** Couriers do not collect oversized goods in this profile: `is_pickup_supported=false` and no timeslots. */
    private function pickUpSlots(array $input): array
    {
        $this->coordinates($input['address_coordinates']);
        $supported = !$input['is_kgt'];
        $slots     = [];
        foreach ($supported ? self::PICK_UP_SLOTS : [] as $id => [$from, $to]) {
            $slots[] = ['id' => $id, 'from' => $from, 'to' => $to];
        }

        return ['is_pickup_supported' => $supported, 'timeslots' => $slots];
    }

    /** Return mile is required for PICK_UP (the courier does not bring returns back); DROP_OFF returns come to the drop-off point. */
    private function returnMile(CabinetState $state, array $input): array
    {
        if (isset($input['warehouse_id']) && !in_array((int) $input['warehouse_id'], array_column(FbsConfig::warehouses($state), 'id'), true)) {
            throw new SellerApiException('Warehouse not found', 404, 5);
        }

        return ['should_set_return_mile' => $input['first_mile_type'] === 'PICK_UP', 'unavailability_reasons' => []];
    }

    /** Pagination by `last_id`: points are ordered by ID, a page contains the points after `last_id`. */
    private function returnPoints(array $input): array
    {
        [$lat, $lon] = $this->coordinates($input['coordinates']);
        $types       = $input['search']['types'] ?? [];
        $address     = trim($input['search']['address'] ?? '');
        $lastId      = (int) ($input['last_id'] ?? 0);
        $limit       = $input['limit'];
        $rows        = $input['country_code'] !== 'RU' ? [] : array_values(array_filter(self::RETURN_POINTS, static fn (array $p): bool => $p['id'] > $lastId
            && ($types === [] || in_array($p['type'], $types, true))
            && ($address === '' || mb_stripos($p['name'] . ' ' . $p['address'], $address) !== false)));
        $page     = array_slice($rows, 0, $limit);
        $selected = (string) ($input['selected_dropoff_point_id'] ?? '');

        return [
            'points' => array_map(static fn (array $p): array => [
                'id'           => $p['id'], 'name' => $p['name'], 'address' => $p['address'], 'type' => $p['type'], 'utc_offset' => 180,
                'coordinates'  => ['latitude' => round($lat + $p['offset'][0], 6), 'longitude' => round($lon + $p['offset'][1], 6)],
                'working_days' => array_map(
                    static fn (string $day): array => ['day' => $day, 'from' => $p['type'] === 'SC' ? '08:00' : '09:00', 'to' => $p['type'] === 'SC' ? '20:00' : '21:00'],
                    $p['type'] === 'SC' ? self::WEEKDAYS : [...self::WEEKDAYS, 'SATURDAY', 'SUNDAY'],
                ),
            ], $page),
            'has_next'                    => count($rows) > $limit,
            'last_id'                     => $page === [] ? $lastId : $page[count($page) - 1]['id'],
            'is_selected_point_available' => $selected !== '' && in_array($selected, array_column(self::RETURN_POINTS, 'dropOff'), true),
        ];
    }

    private function create(CabinetState $state, array $input, int $now): array
    {
        $name       = trim($input['name']);
        $warehouses = FbsConfig::warehouses($state);
        $this->require($name !== '', 'name required');
        $this->require(!in_array($name, array_column($warehouses, 'name'), true), 'Warehouse with this name already exists');
        $this->require(preg_match(self::PHONE, $input['phone']) === 1, 'phone must be in the format +7(XXX)XXX-XX-XX');
        [$lat, $lon] = $this->coordinates($input['address_coordinates']);
        $this->require((int) $input['cut_in_time'] > 0, 'cut_in_time must be positive');
        $days = $input['working_days'] ?? self::WEEKDAYS;
        $this->require($days !== [] && count(array_unique($days)) === count($days), 'working_days must be non-empty and unique');
        $options = ($input['options'] ?? []) + ['comment' => '', 'courier_phones' => [], 'is_auto_assembly' => false, 'is_waybill_enabled' => false];
        foreach ($options['courier_phones'] as $phone) {
            $this->require(preg_match(self::PHONE, $phone) === 1, 'courier_phones must be in the format +7(XXX)XXX-XX-XX');
        }
        $type       = $input['first_mile_type'];
        $timeslotId = (int) $input['timeslot_id'];
        $point      = null;
        if ($type === 'DROP_OFF') {
            $this->require(isset($input['drop_off_point_id']), 'drop_off_point_id required for DROP_OFF');
            $point = $this->dropOffPoint((string) $input['drop_off_point_id']);
            $this->require(!$input['is_kgt'] || $point['type'] === 'SC', 'Drop-off point does not accept oversized goods');
            $slot = array_column($this->dropOffSlots($point), null, 'id')[$timeslotId] ?? null;
        } else {
            $this->require(!isset($input['drop_off_point_id']), 'drop_off_point_id is only for DROP_OFF');
            $slot = array_column($this->pickUpSlots($input)['timeslots'], null, 'id')[$timeslotId] ?? null;
        }
        $this->require($slot !== null, 'Unknown timeslot_id for this first mile');
        $returnMile = $this->returnMile($state, ['first_mile_type' => $type])['should_set_return_mile'];
        $this->require(!$returnMile || isset($input['return_point_id']), 'return_point_id required for PICK_UP');
        $this->require(!isset($input['return_point_id']) || in_array((int) $input['return_point_id'], array_column(self::RETURN_POINTS, 'id'), true), 'Unknown return_point_id');

        $taken                                 = array_merge(array_column($warehouses, 'id'), array_column(array_merge(...array_column($warehouses, 'deliveryMethods')), 'id'), array_column($state->config()['warehouses'], 'id'));
        $id                                    = $this->newId($state, $taken);
        $state->data['fbs']['warehouses'][$id] = [
            'id'              => $id, 'name' => $name, 'status' => 'new', 'firstMileType' => $type,
            'deliveryMethods' => [['id' => $this->newId($state, $taken), 'name' => $point === null ? 'Ozon Логистика, курьер забирает, ' . $name : 'Ozon Логистика, сдача в ' . $point['address']]],
            'phone'           => $input['phone'], 'latitude' => $lat, 'longitude' => $lon, 'is_kgt' => $input['is_kgt'], 'cut_in_time' => (int) $input['cut_in_time'],
            'working_days'    => $days, 'dropoff_point_id' => $point['id'] ?? '', 'timeslot_id' => $timeslotId, 'timeslot_from' => $slot['from'], 'timeslot_to' => $slot['to'],
            'return_point_id' => (int) ($input['return_point_id'] ?? 0), 'options' => $options, 'created_at' => $now, 'updated_at' => $now,
        ];
        $hex                                            = bin2hex(random_bytes(16));
        $operationId                                    = substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
        $state->data['fbs']['operations'][$operationId] = ['id' => $operationId, 'warehouse_id' => $id, 'status' => 'IN_PROGRESS', 'created_at' => $now,
            'ready_at'                                          => $now + $state->config()['operationDelaySeconds'], 'failure' => (bool) (FbsConfig::of($state)['warehouseCreateFailure'] ?? false)];
        $state->event('fbs.warehouse.queued', $now, ['operation_id' => $operationId, 'warehouse_id' => $id]);

        return ['operation_id' => $operationId];
    }

    private function status(CabinetState $state, array $input): array
    {
        $op       = $state->data['fbs']['operations'][$input['operation_id']] ?? throw new SellerApiException('Operation not found', 404, 5);
        $response = ['status' => $op['status'], 'type' => 'CREATE_FBS_WAREHOUSE'];

        return match ($op['status']) {
            'SUCCESS' => $response + ['result' => ['entity_id' => $op['warehouse_id']]],
            'ERROR'   => $response + ['error' => ['code' => 'CREATE_WAREHOUSE_FAILED', 'message' => 'Configured local warehouse creation failure']],
            default   => $response,
        };
    }

    /** @return array{float, float} */
    private function coordinates(?array $coordinates): array
    {
        if ($coordinates === null) {
            return self::DEFAULT_COORDINATES;
        }
        $lat = $coordinates['latitude'];
        $lon = $coordinates['longitude'];
        $this->require((is_int($lat) || is_float($lat)) && abs($lat) <= 90 && (is_int($lon) || is_float($lon)) && abs($lon) <= 180, 'Invalid coordinates');

        return [(float) $lat, (float) $lon];
    }

    /** Warehouse and delivery method IDs come from the cabinet sequence and never repeat configured IDs. */
    private function newId(CabinetState $state, array $taken): int
    {
        do {
            $id = $state->id();
        } while (in_array($id, $taken, true));

        return $id;
    }

    private function require(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new SellerApiException($message);
        }
    }
}
