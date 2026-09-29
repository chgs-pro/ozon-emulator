<?php

declare(strict_types=1);

namespace App\Feature\Fbs;

use App\Feature\Fbo\CabinetState;
use App\Feature\Fbo\SellerApiException;
use FPDF;

use function array_column;
use function array_diff;
use function array_filter;
use function array_flip;
use function array_intersect_key;
use function array_map;
use function array_merge;
use function array_slice;
use function array_unique;
use function array_values;
use function base64_encode;
use function count;
use function ctype_digit;
use function gmdate;
use function in_array;
use function ksort;
use function sprintf;
use function str_starts_with;
use function strtotime;
use function substr;
use function trim;

use const DATE_ATOM;
use const PHP_INT_MAX;

/**
 * FBS carriages (отгрузки) and their documents. A carriage groups assembled postings of one delivery method and departure
 * date — the date of the posting `shipment_date`. `/v1/carriage/create` takes every assembled posting of the method and date
 * that is not in another carriage; the status is `new` and the composition may be replaced by `/v1/carriage/set-postings`.
 * `/v1/carriage/approve` forms the carriage (`formed` — an emulator name for «Сформирована», the snapshot lists only later
 * statuses) and starts the documents: they are `in_process` for {@see self::DOCUMENTS_SECONDS} of the cabinet clock, then
 * `ready`. A new or formed carriage can be cancelled; its postings return to `posting_not_in_carriage`.
 *
 * The physical handover is a control event (`fbsHandover`): accepted postings go to `delivering`, their reserve is gone,
 * the carriage is `closed`; postings that were not accepted leave the carriage and wait for the next one.
 *
 * Emulator choices where the snapshot is silent: `/v2/carriage/delivery/list` filters by the delivery method ID or by the
 * warehouse ID (the snapshot suggests the warehouse ID for FBS) and returns a carriage with ID 0 and action `create` while
 * assembled postings wait outside a carriage; the barcode text is `OZN` and the carriage ID.
 */
final readonly class FbsCarriageService
{
    public const array PATHS = [
        '/v2/carriage/delivery/list', '/v1/carriage/create', '/v1/carriage/set-postings', '/v1/carriage/approve', '/v1/carriage/get',
        '/v1/carriage/cancel', '/v2/posting/fbs/act/check-status', '/v2/posting/fbs/act/get-postings', '/v2/posting/fbs/act/get-barcode/text',
        '/v2/posting/fbs/act/get-pdf', '/v1/carriage/pass/create',
    ];

    public const array WRITE_PATHS = ['/v1/carriage/create', '/v1/carriage/set-postings', '/v1/carriage/approve', '/v1/carriage/cancel', '/v1/carriage/pass/create'];

    public const int DOCUMENTS_SECONDS = 30;

    /** Carriage statuses that still hold their postings. */
    private const array ACTIVE = ['new', 'formed'];

    /** Posting statuses a delivery method still expects on its departure date. */
    private const array EXPECTED = ['awaiting_packaging', 'awaiting_deliver'];

    public function supports(string $path): bool
    {
        return in_array($path, self::PATHS, true);
    }

    public function handle(CabinetState $state, string $path, array $input, int $now): array
    {
        return match ($path) {
            '/v2/carriage/delivery/list'       => $this->deliveryList($state, $input, $now),
            '/v1/carriage/create'              => $this->create($state, $input, $now),
            '/v1/carriage/set-postings'        => $this->setPostings($state, (int) $input['carriage_id'], $input['posting_numbers'], $now),
            '/v1/carriage/approve'             => $this->approve($state, (int) $input['carriage_id'], $input, $now),
            '/v1/carriage/get'                 => $this->get($state, (int) $input['carriage_id'], $now),
            '/v1/carriage/cancel'              => $this->cancel($state, (int) $input['carriage_id'], $now),
            '/v1/carriage/pass/create'         => $this->createPass($state, (int) $input['carriage_id'], $input['arrival_passes'] ?? [], $now),
            '/v2/posting/fbs/act/check-status' => $this->documents($state, (int) $input['id'], $now),
            '/v2/posting/fbs/act/get-postings' => $this->actPostings($state, (int) $input['id']),
            '/v2/posting/fbs/act/get-pdf'      => $this->documentsFile($state, (int) $input['id'], $now),
            default                            => ['result' => self::barcode($this->carriage($state, (int) $input['id'])['id'])],
        };
    }

    /**
     * Physical handover of a formed carriage (control event): accepted postings start delivering, `$missing` postings are not
     * accepted and leave the carriage.
     *
     * @param list<string> $missing
     */
    public function handover(CabinetState $state, int $carriageId, array $missing, int $now): void
    {
        $carriage = $this->carriage($state, $carriageId);
        if ($carriage['status'] !== 'formed') {
            throw new SellerApiException('Only a formed carriage can be handed over', 409, 10);
        }
        if (array_diff($missing, $carriage['postings']) !== []) {
            throw new SellerApiException('Missing postings must belong to carriage ' . $carriageId, 400, 3);
        }
        // A posting cancelled after the approval travels with the carriage but is not delivered: it keeps its status.
        $cancelled = array_values(array_filter($carriage['postings'], static fn (string $number): bool => str_starts_with((string) $state->data['fbs']['postings'][$number]['status'], 'cancelled')));
        $accepted  = array_values(array_diff($carriage['postings'], $missing, $cancelled));
        foreach ($accepted as $number) {
            $this->posting($state, $number, 'delivering', 'posting_transferred_to_courier_service', $now);
        }
        foreach ($missing as $number) {
            $this->posting($state, $number, 'awaiting_deliver', 'posting_not_in_carriage', $now);
        }
        $carriage['postings']                                      = $accepted;
        $carriage['not_accepted']                                  = array_values($missing);
        $carriage['status']                                        = 'closed';
        $carriage['updated_at']                                    = $now;
        $state->data['fbs']['carriages'][(string) $carriage['id']] = $carriage;
        $state->event('fbs.carriage.handed_over', $now, ['carriage_id' => $carriage['id'], 'accepted' => $accepted, 'missing' => $missing]);
    }

    private function deliveryList(CabinetState $state, array $input, int $now): array
    {
        $filter   = $input['filter'] ?? [];
        $date     = (string) ($filter['departure_date'] ?? gmdate('Y-m-d', $now));
        $methodId = isset($filter['delivery_method_id']) ? (int) $filter['delivery_method_id'] : null;
        $groups   = [];
        foreach ($state->data['fbs']['postings'] ?? [] as $posting) {
            if (self::departure($posting) === $date && ($methodId === null || in_array($methodId, [$posting['delivery_method_id'], $posting['warehouse_id']], true))
                && (in_array($posting['status'], self::EXPECTED, true) || $this->carriageOf($state, $posting['posting_number']) !== null)) {
                $groups[$posting['delivery_method_id']][] = $posting;
            }
        }
        ksort($groups);
        $methods = [];
        foreach ($groups as $id => $postings) {
            $methods[] = $this->method($state, $id, $date, $postings, $now);
        }
        $offset = isset($input['cursor']) && ctype_digit((string) $input['cursor']) ? (int) $input['cursor'] : 0;
        $limit  = (int) $input['limit'];
        $page   = array_slice($methods, $offset, $limit);
        $next   = $offset + $limit < count($methods);

        return ['methods' => $page, 'cursor' => $next ? (string) ($offset + $limit) : '', 'has_next' => $next];
    }

    private function method(CabinetState $state, int $methodId, string $date, array $postings, int $now): array
    {
        $warehouse  = array_column(FbsConfig::warehouses($state), null, 'id')[$postings[0]['warehouse_id']];
        $method     = array_column($warehouse['deliveryMethods'], null, 'id')[$methodId] ?? ['name' => ''];
        $carriages  = $this->carriagesFor($state, $methodId, $date);
        $inCarriage = array_merge([], ...array_map(static fn (array $c): array => in_array($c['status'], self::ACTIVE, true) ? $c['postings'] : [], $carriages));
        $packaged   = array_values(array_filter($postings, static fn (array $p): bool => $p['status'] === 'awaiting_deliver'));
        $waiting    = array_filter($packaged, static fn (array $p): bool => !in_array($p['posting_number'], $inCarriage, true));
        $rows       = array_map(fn (array $c): array => $this->carriageRow($c, $now), $carriages);
        $hasActive  = array_filter($carriages, static fn (array $c): bool => in_array($c['status'], self::ACTIVE, true)) !== [];
        if (!$hasActive && $waiting !== []) {
            $rows[] = ['id'       => 0, 'status' => '', 'available_actions' => ['create'], 'postings_count' => count($waiting), 'all_blr_traceable' => false,
                'carriage_volume' => 0, 'quantum_count' => 0, 'pickup_fee' => ['currency_code' => 'RUB', 'value' => 0]];
        }
        $unpackaged = count(array_filter($postings, static fn (array $p): bool => $p['status'] === 'awaiting_packaging'));

        return [
            'delivery_method_id'          => $methodId, 'delivery_method_name' => $method['name'], 'delivery_method_status' => 'ACTIVE', 'departure_date' => $date,
            'warehouse_id'                => $warehouse['id'], 'warehouse_name' => $warehouse['name'], 'warehouse_city' => '', 'first_mile_type' => $warehouse['firstMileType'],
            'first_mile_changing'         => false, 'dropoff_point_id' => (int) ($warehouse['dropOffPointId'] ?? 0), 'dropoff_address' => '', 'dropoff_point_type' => '',
            'dropoff_change_availability' => '', 'cutoff_at' => gmdate(DATE_ATOM, (int) strtotime($date . 'T12:00:00Z')), 'integration_type' => 'ozon',
            'has_entrusted_acceptance'    => false, 'is_optional_carriage' => false, 'is_presort' => false, 'is_rfbs' => false,
            'mandatory_postings_count'    => count($postings), 'mandatory_packaged_count' => count($packaged), 'optional_packaged_count' => 0,
            'carriage_postings_count'     => count($inCarriage), 'carriages' => $rows,
            'errors'                      => $unpackaged > 0 ? [['code' => 'NOT_ALL_POSTINGS_PACKAGED', 'status' => 'warning', 'description' => sprintf('%d postings are not assembled', $unpackaged)]] : [],
        ];
    }

    private function create(CabinetState $state, array $input, int $now): array
    {
        $methodId = (int) ($input['delivery_method_id'] ?? 0);
        $date     = isset($input['departure_date']) ? substr((string) $input['departure_date'], 0, 10) : gmdate('Y-m-d', $now);
        foreach ($this->carriagesFor($state, $methodId, $date) as $existing) {
            if (in_array($existing['status'], self::ACTIVE, true)) {
                throw new SellerApiException(sprintf('Carriage %d already exists for the delivery method and date', $existing['id']), 400, 3);
            }
        }
        $numbers = array_values(array_map(static fn (array $p): string => $p['posting_number'], array_filter(
            $state->data['fbs']['postings'] ?? [],
            fn (array $p): bool => $p['delivery_method_id'] === $methodId && self::departure($p) === $date && $p['status'] === 'awaiting_deliver'
                && $this->carriageOf($state, $p['posting_number']) === null,
        )));
        if ($numbers === []) {
            throw new SellerApiException('No assembled postings for the delivery method and date', 400, 3);
        }
        $posting  = $state->data['fbs']['postings'][$numbers[0]];
        $carriage = ['id' => $state->id(), 'warehouse_id' => $posting['warehouse_id'], 'delivery_method_id' => $methodId, 'departure_date' => $date,
            'status'      => 'new', 'postings' => [], 'containers_count' => 0, 'created_at' => $now, 'updated_at' => $now,
            // The drop-off point of the scenario asks for an arrival pass for each carriage.
            'pass_required' => ($state->data['fbs']['scenario']['carriagePassRequired'] ?? false) === true, 'passes' => []];
        $state->data['fbs']['carriages'][(string) $carriage['id']] = $carriage;
        $this->compose($state, $carriage, $numbers, $now);
        $state->event('fbs.carriage.created', $now, ['carriage_id' => $carriage['id'], 'postings' => $numbers]);

        return ['carriage_id' => $carriage['id']];
    }

    /** @param list<string> $numbers */
    private function setPostings(CabinetState $state, int $id, array $numbers, int $now): array
    {
        $carriage = $this->carriage($state, $id);
        if ($carriage['status'] !== 'new') {
            throw new SellerApiException('The composition can be changed only in status new', 400, 3);
        }
        $result   = [];
        $accepted = [];
        foreach (array_values(array_unique(array_map('strval', $numbers))) as $number) {
            $posting = $state->data['fbs']['postings'][$number] ?? null;
            $other   = $this->carriageOf($state, $number);
            $error   = match (true) {
                $posting === null                                                                                                               => 'POSTING_NOT_FOUND',
                $posting['delivery_method_id'] !== $carriage['delivery_method_id'] || self::departure($posting) !== $carriage['departure_date'] => 'WRONG_DELIVERY_METHOD_OR_DATE',
                $posting['status'] !== 'awaiting_deliver'                                                                                       => 'POSTING_NOT_ASSEMBLED',
                $other !== null && $other['id'] !== $carriage['id']                                                                             => 'POSTING_IN_ANOTHER_CARRIAGE',
                default                                                                                                                         => '',
            };
            $result[] = ['posting_number' => $number, 'result' => $error === '', 'error' => $error];
            if ($error === '') {
                $accepted[] = $number;
            }
        }
        if ($accepted === []) {
            throw new SellerApiException('A carriage cannot be empty', 400, 3);
        }
        $this->compose($state, $carriage, $accepted, $now);
        $state->event('fbs.carriage.postings_set', $now, ['carriage_id' => $id, 'postings' => $accepted]);

        return ['result' => $result];
    }

    private function approve(CabinetState $state, int $id, array $input, int $now): array
    {
        $carriage = $this->carriage($state, $id);
        if ($carriage['status'] !== 'new') {
            throw new SellerApiException('Only a new carriage can be approved', 400, 3);
        }
        foreach ($carriage['postings'] as $number) {
            if ($state->data['fbs']['postings'][$number]['status'] !== 'awaiting_deliver') {
                throw new SellerApiException('Posting ' . $number . ' is no longer assembled', 400, 3);
            }
        }
        $carriage['status']                            = 'formed';
        $carriage['containers_count']                  = (int) ($input['containers_count'] ?? 0);
        $carriage['approved_at']                       = $now;
        $carriage['documents_ready_at']                = $now + self::DOCUMENTS_SECONDS;
        $carriage['updated_at']                        = $now;
        $state->data['fbs']['carriages'][(string) $id] = $carriage;
        $state->event('fbs.carriage.approved', $now, ['carriage_id' => $id]);

        return [];
    }

    private function get(CabinetState $state, int $id, int $now): array
    {
        $carriage = $this->carriage($state, $id);
        $active   = in_array($carriage['status'], self::ACTIVE, true);

        return [
            'carriage_id'       => $carriage['id'], 'company_id' => 0, 'status' => $carriage['status'], 'delivery_method_id' => $carriage['delivery_method_id'],
            'warehouse_id'      => $carriage['warehouse_id'], 'departure_date' => $carriage['departure_date'], 'first_mile_type' => 'DROP_OFF',
            'act_type'          => 'shipping_list', 'integration_type' => 'ozon', 'containers_count' => $carriage['containers_count'], 'retry_count' => 0,
            'available_actions' => [
                ...($this->documentsReady($carriage, $now) ? ['get_shipping_list', 'get_act_of_acceptance'] : []),
                ...(($carriage['pass_required'] ?? false) && $active ? ['set_arrival_passes'] : []),
            ],
            'cancel_availability' => ['is_cancel_available' => $active, 'reason' => $active ? '' : 'Carriage is ' . $carriage['status']],
            'arrival_pass_ids'    => array_map(static fn (array $pass): string => (string) $pass['id'], $carriage['passes'] ?? []), 'all_blr_traceable' => false, 'is_waybill_enabled' => false, 'is_econom' => false, 'is_container_label_printed' => false,
            'is_partial'          => false, 'partial_num' => 0, 'has_postings_for_next_carriage' => ($carriage['not_accepted'] ?? []) !== [], 'tpl_provider_id' => 0,
            'created_at'          => gmdate(DATE_ATOM, $carriage['created_at']), 'updated_at' => gmdate(DATE_ATOM, $carriage['updated_at']),
        ];
    }

    /**
     * An arrival pass for the car of a carriage: the driver, the phone, the plate and the model are required; the pass ID is
     * added to the carriage (`arrival_pass_ids`).
     *
     * @param list<array<string, mixed>> $passes
     */
    private function createPass(CabinetState $state, int $id, array $passes, int $now): array
    {
        $carriage = $this->carriage($state, $id);
        if (!in_array($carriage['status'], self::ACTIVE, true)) {
            throw new SellerApiException('Carriage is ' . $carriage['status'], 400, 3);
        }
        if ($passes === []) {
            throw new SellerApiException('arrival_passes required', 400, 3);
        }
        $ids = [];
        foreach ($passes as $pass) {
            foreach (['driver_name', 'driver_phone', 'vehicle_license_plate', 'vehicle_model'] as $key) {
                if (trim((string) ($pass[$key] ?? '')) === '') {
                    throw new SellerApiException($key . ' required', 400, 3);
                }
            }
            $record = ['id' => $state->id(), 'with_returns' => ($pass['with_returns'] ?? false) === true]
                + array_intersect_key($pass, array_flip(['driver_name', 'driver_phone', 'vehicle_license_plate', 'vehicle_model']));
            $carriage['passes'][] = $record;
            $ids[]                = (string) $record['id'];
        }
        $carriage['updated_at']                                    = $now;
        $state->data['fbs']['carriages'][(string) $carriage['id']] = $carriage;
        $state->event('fbs.carriage.pass_created', $now, ['carriage_id' => $carriage['id'], 'pass_ids' => $ids]);

        return ['arrival_pass_ids' => $ids];
    }

    private function cancel(CabinetState $state, int $id, int $now): array
    {
        $carriage = $this->carriage($state, $id);
        if (!in_array($carriage['status'], self::ACTIVE, true)) {
            return ['carriage_status' => $carriage['status'], 'error' => 'Carriage is ' . $carriage['status']];
        }
        foreach ($carriage['postings'] as $number) {
            $this->posting($state, $number, null, 'posting_not_in_carriage', $now);
        }
        $carriage['status']                            = 'cancelled';
        $carriage['updated_at']                        = $now;
        $state->data['fbs']['carriages'][(string) $id] = $carriage;
        $state->event('fbs.carriage.cancelled', $now, ['carriage_id' => $id]);

        return ['carriage_status' => 'cancelled', 'error' => ''];
    }

    private function documents(CabinetState $state, int $id, int $now): array
    {
        $carriage = $this->carriage($state, $id);
        if ($carriage['status'] === 'new') {
            throw new SellerApiException('Carriage ' . $id . ' is not approved', 400, 3);
        }
        $status = match (true) {
            $carriage['status'] === 'cancelled'    => 'cancelled',
            $this->documentsReady($carriage, $now) => 'ready',
            default                                => 'in_process',
        };

        return ['result' => ['status'        => $status, 'act_type' => 'shipping_list', 'added_to_act' => $carriage['postings'],
            'removed_from_act'               => $carriage['not_accepted'] ?? [], 'is_partial' => false, 'partial_num' => 0,
            'has_postings_for_next_carriage' => ($carriage['not_accepted'] ?? []) !== []]];
    }

    /** The shipping list of a carriage with ready documents: a synthetic test PDF with the carriage, date and postings. */
    private function documentsFile(CabinetState $state, int $id, int $now): array
    {
        $carriage = $this->carriage($state, $id);
        if (!$this->documentsReady($carriage, $now)) {
            throw new SellerApiException('Documents of carriage ' . $id . ' are not ready', 400, 3);
        }
        $pdf = new FPDF('P', 'mm', 'A4');

        $pdf->SetTitle('Ozon local FBS test shipping list');
        $pdf->SetCreator('ozon-seller-emulator');
        $pdf->AddPage();
        $pdf->SetFont('Helvetica', 'B', 14);
        $pdf->Cell(0, 10, 'OZON LOCAL TEST - SHIPPING LIST - NOT FOR DELIVERY', 0, 1, 'C');
        $pdf->SetFont('Helvetica', '', 11);
        foreach (['Carriage: ' . $carriage['id'], 'Departure date: ' . $carriage['departure_date'], 'Delivery method: ' . $carriage['delivery_method_id'],
            'Warehouse: ' . $carriage['warehouse_id'], 'Barcode: ' . self::barcode($carriage['id']), 'Postings: ' . count($carriage['postings'])] as $line) {
            $pdf->Cell(0, 7, $line, 0, 1);
        }
        $pdf->Ln(4);
        foreach ($carriage['postings'] as $i => $number) {
            $pdf->Cell(0, 6, ($i + 1) . '. ' . $number, 0, 1);
        }

        return ['file_content' => base64_encode($pdf->Output('S')), 'file_name' => 'carriage-' . $carriage['id'] . '.pdf', 'content_type' => 'application/pdf'];
    }

    private static function barcode(int $id): string
    {
        return 'OZN' . sprintf('%010d', $id);
    }

    private function actPostings(CabinetState $state, int $id): array
    {
        $carriage = $this->carriage($state, $id);

        return ['result' => array_map(static function (string $number) use ($state): array {
            $p = $state->data['fbs']['postings'][$number];

            return ['id'     => $p['order_id'], 'posting_number' => $number, 'status' => $p['status'], 'multi_box_qty' => $p['multi_box_qty'] ?? 1, 'seller_error' => '',
                'created_at' => gmdate(DATE_ATOM, $p['in_process_at']), 'updated_at' => gmdate(DATE_ATOM, $p['shipped_at'] ?? $p['in_process_at']),
                'products'   => array_map(static fn (array $l): array => ['sku' => $l['sku'], 'offer_id' => $l['offer_id'], 'name' => $l['name'],
                    'quantity'                                                  => $l['quantity'], 'price' => $l['price']], $p['products'])];
        }, $carriage['postings'])];
    }

    private function carriageRow(array $carriage, int $now): array
    {
        return ['id'            => $carriage['id'], 'status' => $carriage['status'], 'postings_count' => count($carriage['postings']),
            'available_actions' => $this->documentsReady($carriage, $now) ? ['get_shipping_list', 'get_act_of_acceptance'] : [],
            'all_blr_traceable' => false, 'carriage_volume' => 0, 'quantum_count' => 0, 'pickup_fee' => ['currency_code' => 'RUB', 'value' => 0]];
    }

    /** Replaces the postings of a carriage: added ones are in the carriage, removed ones wait for the next one. @param list<string> $numbers */
    private function compose(CabinetState $state, array $carriage, array $numbers, int $now): void
    {
        foreach (array_diff($carriage['postings'], $numbers) as $number) {
            $this->posting($state, $number, null, 'posting_not_in_carriage', $now);
        }
        foreach ($numbers as $number) {
            $this->posting($state, $number, null, 'posting_in_carriage', $now);
        }
        $carriage['postings']                                      = array_values($numbers);
        $carriage['updated_at']                                    = $now;
        $state->data['fbs']['carriages'][(string) $carriage['id']] = $carriage;
    }

    private function posting(CabinetState $state, string $number, ?string $status, string $substatus, int $now): void
    {
        $posting              = $state->data['fbs']['postings'][$number];
        $posting['status']    = $status ?? $posting['status'];
        $posting['substatus'] = $substatus;
        if ($status === 'delivering') {
            $posting['delivering_at'] = $now;
        }
        $posting['available_actions']            = FbsPostingGenerator::actions($posting);
        $state->data['fbs']['postings'][$number] = $posting;
    }

    private function documentsReady(array $carriage, int $now): bool
    {
        return in_array($carriage['status'], ['formed', 'closed'], true) && $now >= ($carriage['documents_ready_at'] ?? PHP_INT_MAX);
    }

    private function carriage(CabinetState $state, int $id): array
    {
        return $state->data['fbs']['carriages'][(string) $id] ?? throw new SellerApiException('Carriage not found', 404, 5);
    }

    /** @return list<array> carriages of a delivery method and departure date, oldest first */
    private function carriagesFor(CabinetState $state, int $methodId, string $date): array
    {
        return array_values(array_filter($state->data['fbs']['carriages'] ?? [], static fn (array $c): bool => $c['delivery_method_id'] === $methodId && $c['departure_date'] === $date));
    }

    /** The active carriage that holds the posting, if any. */
    private function carriageOf(CabinetState $state, string $number): ?array
    {
        foreach ($state->data['fbs']['carriages'] ?? [] as $carriage) {
            if (in_array($carriage['status'], self::ACTIVE, true) && in_array($number, $carriage['postings'], true)) {
                return $carriage;
            }
        }

        return null;
    }

    private static function departure(array $posting): string
    {
        return gmdate('Y-m-d', $posting['shipment_date']);
    }
}
