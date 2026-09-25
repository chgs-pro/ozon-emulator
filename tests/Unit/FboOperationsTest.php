<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Feature\Fbo\{ActService, CargoService, FboOperationsReader, LabelService, OperationCatalog, OrderMutationService, SellerApiException, SupplyContext};
use App\Feature\Fbo\CabinetState;
use App\Feature\Fbo\Command\AdvanceOperations\AdvanceOperationsHandler;
use App\Feature\Fbo\Command\ControlCabinet\ControlCabinetHandler;
use App\Feature\Fbo\Command\ExecuteOperation\ExecuteOperationHandler;
use App\Tests\Support\FboTestCase;
use PHPUnit\Framework\Attributes\{CoversClass, CoversMethod, Test};

use function array_column;
use function array_pop;
use function array_sum;
use function gmdate;
use function parse_str;
use function parse_url;
use function strtotime;

use const DATE_ATOM;
use const PHP_URL_QUERY;

#[CoversClass(ReadinessService::class)]
#[CoversMethod(ReadinessService::class, 'refresh')]
#[CoversClass(CargoService::class)]
#[CoversClass(LabelService::class)]
#[CoversClass(ActService::class)]
#[CoversClass(SupplyContext::class)]
#[CoversClass(OrderMutationService::class)]
#[CoversClass(OperationCatalog::class)]
#[CoversClass(FboOperationsReader::class)]
#[CoversClass(ExecuteOperationHandler::class)]
#[CoversClass(AdvanceOperationsHandler::class)]
#[CoversClass(ControlCabinetHandler::class)]
#[CoversMethod(ExecuteOperationHandler::class, 'handle')]
#[CoversMethod(AdvanceOperationsHandler::class, 'handle')]
#[CoversMethod(ControlCabinetHandler::class, 'handle')]
final class FboOperationsTest extends FboTestCase
{
    /** Per-request dev failure does not change cargo and does not leak to the next request.
     * @see ExecuteOperationHandler::handle()
     * @see AdvanceOperationsHandler::handle()
     */
    #[Test]
    public function acceptsPerRequestCargoScenarioWithoutChangingCabinetDefaults(): void
    {
        $supply = $this->createOrder()['supplies'][0]['supply_id'];
        $input  = $this->cargoInput($supply);
        try {
            $this->service->execute($this->identity, '/v1/cargoes/create', $input, 'error');
            self::fail('Expected an explicit rejection');
        } catch (SellerApiException $error) {
            self::assertSame(400, $error->status);
        }
        self::assertSame([], $this->call('/v1/cargoes/get', ['supply_ids' => [$supply]])['supply'][0]['cargoes']);
        $success = $this->service->execute($this->identity, '/v1/cargoes/create', $input, 'success');
        $this->clock->timestamp += 3;
        self::assertSame('SUCCESS', $this->call('/v2/cargoes/create/info', ['operation_id' => $success['operation_id']])['status']);
        self::assertCount(2, $this->call('/v1/cargoes/get', ['supply_ids' => [$supply]])['supply'][0]['cargoes']);
    }

    /** Batches preserve IDs, cargo quantities and rule readiness.
     * @see CargoService::apply()
     */
    #[Test]
    public function createsCargoInBatchesAndRejectsOverAllocation(): void
    {
        $supply = $this->createOrder()['supplies'][0]['supply_id'];
        $input  = $this->cargoInput($supply);
        $second = array_pop($input['cargoes']);
        $first  = $this->complete('/v1/cargoes/create', $input);
        self::assertSame('SUCCESS', $first['status']);
        self::assertFalse($this->call('/v1/cargoes/rules/get', ['supply_ids' => [$supply]])['supply_check_lists'][0]['is_valid_distribution_rule']['satisfied']);
        self::assertSame('SUCCESS', $this->complete('/v1/cargoes/create', ['supply_id' => $supply, 'cargoes' => [$second]])['status']);
        $rows = $this->call('/v2/cargoes/get', ['supplies' => [['supply_id' => $supply, 'cargo_ids' => []]]])['supplies'][0];
        self::assertCount(2, $rows['cargoes']);
        self::assertSame($first['result']['cargoes'][0]['value']['cargo_id'], $rows['cargoes'][0]['cargo_id']);
        self::assertTrue($this->call('/v1/cargoes/rules/get', ['supply_ids' => [$supply]])['supply_check_lists'][0]['is_valid_distribution_rule']['satisfied']);
        $input['cargoes'][0]['key'] = 'too-many';
        self::assertSame('FAILED', $this->complete('/v1/cargoes/create', $input)['status']);
        self::assertCount(2, $this->call('/v1/cargoes/get', ['supply_ids' => [$supply]])['supply'][0]['cargoes']);
    }

    /** Transport pallets group boxes without duplicating product stock.
     * @see CargoService::apply()
     */
    #[Test]
    public function createsBindsUnbindsAndDeletesTransportCargo(): void
    {
        $order = $this->createOrder();
        $id    = $order['supplies'][0]['supply_id'];
        self::assertSame('SUCCESS', $this->complete('/v1/cargoes/transport/activate', ['supply_id' => $id, 'is_transport' => true])['status']);
        $boxes    = $this->complete('/v1/cargoes/create', $this->cargoInput($id))['result']['cargoes'];
        $pallets  = $this->complete('/v1/cargoes/transport/create', ['supply_id' => $id, 'transport_cargoes' => [['type' => 'PALLET', 'count' => 2]]]);
        $palletId = $pallets['result']['transport_cargoes'][0]['id'];
        self::assertSame('SUCCESS', $pallets['status']);
        $boxIds = array_column(array_column($boxes, 'value'), 'cargo_id');
        self::assertSame('SUCCESS', $this->complete('/v1/cargoes/transport/bind', ['supply_id' => $id, 'transport_cargo_bind' => [['transport_cargo_id' => $palletId, 'cargo_ids' => $boxIds]]])['status']);
        $cargo = $this->call('/v2/cargoes/get', ['supplies' => [['supply_id' => $id, 'cargo_ids' => []]]])['supplies'][0];
        self::assertSame(2, $cargo['transport_cargoes'][0]['box_count']);
        $bundle = $this->call('/v1/supply-order/bundle', ['bundle_ids' => [$cargo['transport_cargoes'][0]['summary_bundle_id']], 'limit' => 100]);
        self::assertSame(16, array_sum(array_column($bundle['items'], 'quantity')));
        $map = $this->call('/v1/cargoes/supplies/get', ['supply_ids' => [$id, 1]]);
        self::assertSame(['1'], $map['not_found_supply_ids']);
        self::assertCount(2, $map['supplies_cargoes'][0]['transport_cargoes'][0]['cargoes']);
        self::assertSame('SUCCESS', $this->complete('/v1/cargoes/transport/bind', ['supply_id' => $id, 'cargoes_unbind_transport_cargoes' => [$palletId]])['status']);
        self::assertFalse($this->call('/v1/cargoes/rules/get', ['supply_ids' => [$id]])['supply_check_lists'][0]['package_units_with_distribution_rule']['satisfied']);
        self::assertSame('SUCCESS', $this->complete('/v2/cargoes/delete', ['supply_id' => $id, 'cargo_ids' => [], 'transport_cargo_ids' => [$palletId], 'transport_cargo_deletion_type' => 'UNBIND_CONTAINED_CARGOES'])['status']);
        self::assertSame('SUCCESS', $this->complete('/v1/cargoes/delete', ['supply_id' => $id, 'cargo_ids' => [$boxIds[0]]])['status']);
        self::assertSame('ERROR', $this->complete('/v1/cargoes/delete', ['supply_id' => $id, 'cargo_ids' => [$boxIds[1]]])['status']);
    }

    /** Failed replacement preserves the previous confirmed cargo version.
     * @see CargoService::apply()
     */
    #[Test]
    public function preservesVersionOnFailureAndPublishesPartialOutcome(): void
    {
        $id    = $this->createOrder()['supplies'][0]['supply_id'];
        $input = $this->cargoInput($id);
        $this->control(['type' => 'scenario', 'path' => '/v1/cargoes/create', 'partialCargoCount' => 1]);
        $partial = $this->complete('/v1/cargoes/create', $input);
        self::assertSame('FAILED', $partial['status']);
        self::assertCount(1, $partial['result']['cargoes']);
        self::assertCount(1, $this->call('/v1/cargoes/get', ['supply_ids' => [$id]])['supply'][0]['cargoes']);
        $input['delete_current_version']                      = true;
        $input['cargoes'][0]['value']['items'][0]['quantity'] = 9999;
        self::assertSame('FAILED', $this->complete('/v1/cargoes/create', $input)['status']);
        self::assertSame($partial['result']['cargoes'][0]['value']['cargo_id'], $this->call('/v1/cargoes/get', ['supply_ids' => [$id]])['supply'][0]['cargoes'][0]['cargo_id']);
    }

    /** Label retries preserve bytes and stale versions cannot be downloaded.
     * @see LabelService::download()
     */
    #[Test]
    public function returnsPdfAndInvalidatesOnlyAfterConfirmedChange(): void
    {
        $id    = $this->createOrder()['supplies'][0]['supply_id'];
        $input = $this->cargoInput($id);
        $this->complete('/v1/cargoes/create', $input);
        $label = $this->complete('/v1/cargoes-label/create', ['supply_id' => $id]);
        self::assertSame('SUCCESS', $label['status']);
        parse_str(parse_url($label['result']['file_url'], PHP_URL_QUERY), $query);
        $service = $this->container->get(LabelService::class);
        $pdf     = $service->download($query['client_id'], $query['document_id'], $query['token']);
        self::assertStringStartsWith('%PDF-', $pdf);
        self::assertSame($pdf, $service->download($query['client_id'], $query['document_id'], $query['token']));
        $input['delete_current_version'] = true;
        self::assertSame('SUCCESS', $this->complete('/v1/cargoes/create', $input)['status']);
        $this->expectException(SellerApiException::class);
        $this->expectExceptionMessage('superseded');
        $service->download($query['client_id'], $query['document_id'], $query['token']);
    }

    /** Separate label routes generate transport labels per supply and order.
     * @see LabelService::create()
     */
    #[Test]
    public function labelsTransportAtBothScopes(): void
    {
        $order = $this->createOrder();
        $id    = $order['supplies'][0]['supply_id'];
        $this->complete('/v1/cargoes/transport/activate', ['supply_id' => $id, 'is_transport' => true]);
        $this->complete('/v1/cargoes/transport/create', ['supply_id' => $id, 'transport_cargoes' => [['type' => 'PALLET', 'count' => 1]]]);
        self::assertSame('SUCCESS', $this->complete('/v1/cargoes/label/transport/create', ['supply_id' => $id])['status']);
        self::assertSame('SUCCESS', $this->complete('/v1/cargoes/label/transport-by-order/create', ['order_id' => $order['order_id']])['status']);
    }

    /** Invalid content has its own diagnostic bundle; live content stays confirmed.
     * @see OrderMutationService::apply()
     */
    #[Test]
    public function editsContentAndKeepsRejectedProposalSeparate(): void
    {
        $order = $this->createOrder();
        $id    = $order['supplies'][0]['supply_id'];
        $input = ['order_id' => $order['order_id'], 'supply_id' => $id, 'items' => [['sku' => 910001, 'quantity' => 8, 'quant' => 1]]];
        $ok    = $this->complete('/v1/supply-order/content/update', $input);
        self::assertSame('SUCCESS', $ok['status']);
        $input['items'][0]['quantity'] = 9999;
        $bad                           = $this->complete('/v1/supply-order/content/update', $input);
        self::assertSame(['SUPPLY_CONTENT_NOT_VALID'], $bad['errors']);
        self::assertSame('ERROR', $bad['status']);
        $validation = $this->call('/v1/supply-order/content/update/validation', ['supply_id' => $id, 'new_bundle_id' => $bad['new_bundle_id']]);
        self::assertSame(1, $validation['validated_assortment']['total_rejected_item_count']);
        self::assertSame($ok['new_bundle_id'], $this->call('/v3/supply-order/get', ['order_ids' => [$order['order_id']]])['orders'][0]['supplies'][0]['bundle_id']);
    }

    /** Slot and pass status envelopes are deliberately different.
     * @see OrderMutationService::apply()
     */
    #[Test]
    public function updatesSlotPassAndCancels(): void
    {
        $order = $this->createOrder();
        $id    = $order['order_id'];
        $slot  = $this->call('/v2/supply-order/timeslot/list', ['order_id' => $id])['timeslots_info']['timeslots'][1];
        self::assertSame('STATUS_SUCCESS', $this->complete('/v1/supply-order/timeslot/update', ['supply_order_id' => $id, 'timeslot' => $slot])['status']);
        $vehicle = ['driver_name' => 'Test Driver', 'driver_phone' => '+79990000000', 'vehicle_model' => 'Test Van', 'vehicle_number' => 'TEST001'];
        self::assertSame('Success', $this->complete('/v1/supply-order/pass/create', ['supply_order_id' => $id, 'vehicle' => $vehicle])['result']);
        $details = $this->call('/v1/supply-order/details', ['order_id' => $id]);
        self::assertSame($vehicle, $details['vehicle']['value']);
        self::assertSame($slot, $details['timeslot']['value']['timeslot']);
        self::assertTrue($this->complete('/v1/supply-order/cancel', ['order_id' => $id])['result']['is_order_cancelled']);
        self::assertSame('Failed', $this->complete('/v1/supply-order/pass/create', ['supply_order_id' => $id, 'vehicle' => $vehicle])['result']);
        self::assertFalse($this->call('/v1/supply-order/details', ['order_id' => $id])['timeslot']['can_set']);
    }

    /** Receipts expose shortages, surplus and damage without undoing physical facts.
     * @see ActService::receive()
     */
    #[Test]
    public function recordsAcceptanceDifferencesAndPreventsLateCancellation(): void
    {
        $order = $this->createOrder();
        $id    = $order['supplies'][0]['supply_id'];
        $this->complete('/v1/cargoes/create', $this->cargoInput($id));
        $this->control(['type' => 'state', 'orderId' => $order['order_id'], 'state' => 'ACCEPTED_AT_SUPPLY_WAREHOUSE']);
        $event = ['eventId' => 'receipt-1', 'type' => 'acceptance', 'supplyId' => $id, 'items' => [['sku' => 910001, 'factQuantity' => 8, 'defectQuantity' => 1], ['sku' => 910002, 'factQuantity' => 7, 'defectQuantity' => 0]]];
        self::assertFalse($this->control($event)['replayed']);
        self::assertTrue($this->control($event)['replayed']);
        $summary = $this->call('/v1/supply-order/act/summary/get', ['order_id' => $order['order_id']]);
        self::assertCount(4, $summary['supplies_acts'][0]['supply_acts']);
        $products = $this->call('/v1/supply-order/act/product/get', ['supply_id' => $id]);
        self::assertSame([910001], array_column($products['skus_defects'], 'sku'));
        self::assertSame('ERROR', $this->complete('/v1/supply-order/cancel', ['order_id' => $order['order_id']])['status']);
        self::assertSame('SUCCESS', $this->complete('/v1/supply-order/act/accept', ['act_id' => $products['supply_acts'][0]['act_id']])['status']);
        $this->control(['type' => 'state', 'orderId' => $order['order_id'], 'state' => 'FUTURE_OZON_STATE']);
        self::assertSame('FAILED', $this->complete('/v1/supply-order/act/accept', ['act_id' => $products['supply_acts'][1]['act_id']])['status']);
    }

    /** Unknown states remain visible and deny writes; beta methods can be disabled.
     * @see ControlCabinetHandler::handle()
     */
    #[Test]
    public function exposesFutureStateAndBetaDenial(): void
    {
        $order = $this->createOrder();
        $this->control(['type' => 'state', 'orderId' => $order['order_id'], 'state' => 'FUTURE_OZON_STATE']);
        self::assertSame('FUTURE_OZON_STATE', $this->call('/v3/supply-order/get', ['order_ids' => [$order['order_id']]])['orders'][0]['state']);
        self::assertSame('FAILED', $this->complete('/v1/cargoes/create', $this->cargoInput($order['supplies'][0]['supply_id']))['status']);
        $this->control(['type' => 'beta', 'disabledPaths' => ['/v1/supply-order/act/summary/get']]);
        $this->expectException(SellerApiException::class);
        $this->expectExceptionMessage('unavailable');
        $this->call('/v1/supply-order/act/summary/get', ['order_id' => $order['order_id']]);
    }

    /** Holding and releasing an operation preserves its identity; concurrent mutations fail.
     * @see AdvanceOperationsHandler::handle()
     */
    #[Test]
    public function holdsAndReleasesWithoutOverwritingConcurrentChanges(): void
    {
        $order = $this->createOrder();
        $input = $this->cargoInput($order['supplies'][0]['supply_id']);
        $this->control(['type' => 'scenario', 'path' => '/v1/cargoes/create', 'hold' => true]);
        $op = $this->call('/v1/cargoes/create', $input);
        self::assertSame('FAILED', $this->complete('/v1/cargoes/create', $input)['status']);
        self::assertSame('IN_PROGRESS', $this->call('/v2/cargoes/create/info', $op)['status']);
        $this->control(['type' => 'release', 'operationId' => $op['operation_id']]);
        self::assertSame('SUCCESS', $this->call('/v2/cargoes/create/info', $op)['status']);
    }

    /** SKU quantity limits and expiry requirements are tested before committing a batch.
     * @see CargoService::apply()
     */
    #[Test]
    public function enforcesExpiryAndQuant(): void
    {
        $this->configuration['products'][0]['expirationRequired'] = true;
        $this->configuration['products'][0]['quant']              = 2;
        $this->configure();
        $id    = $this->createOrder()['supplies'][0]['supply_id'];
        $input = $this->cargoInput($id);
        self::assertSame('FAILED', $this->complete('/v1/cargoes/create', $input)['status']);
        $input['cargoes'][0]['value']['items'][0] += ['expires_at' => gmdate(DATE_ATOM, $this->clock->timestamp + 864000)];
        $input['cargoes'][0]['value']['items'][0]['quant'] = 2;
        self::assertSame('SUCCESS', $this->complete('/v1/cargoes/create', $input)['status']);
    }

    /** Placement zones are returned in supply bundles and a cargo mixing zones fails the placement rule.
     * @see CargoService::rules()
     */
    #[Test]
    public function reportsPlacementZonesInBundleAndRules(): void
    {
        $this->configuration['products'][0]['placementZone'] = 'PRODUCTS';
        $this->configuration['products'][1]['placementZone'] = 'DANGEROUS_GOODS';
        $this->configure();
        $supply = $this->createOrder()['supplies'][0];
        $items  = $this->call('/v1/supply-order/bundle', ['bundle_ids' => [$supply['bundle_id']], 'limit' => 100])['items'];
        self::assertEqualsCanonicalizing(['PRODUCTS', 'DANGEROUS_GOODS'], array_column($items, 'placement_zone'));

        $separate = $this->cargoInput($supply['supply_id']);
        self::assertSame('SUCCESS', $this->complete('/v1/cargoes/create', $separate)['status']);
        $rule = $this->call('/v1/cargoes/rules/get', ['supply_ids' => [$supply['supply_id']]])['supply_check_lists'][0]['placement_zones_rule'];
        self::assertSame(['count_cargoes_all' => 2, 'count_cargoes_with_mono_placement_zone' => 2, 'is_applicable' => true, 'satisfied' => true], $rule);

        $mixed = ['supply_id' => $supply['supply_id'], 'delete_current_version' => true, 'cargoes' => [['key' => 'box-mixed', 'value' => ['type' => 'BOX', 'items' => [
            ['offer_id' => 'FBO-TEST-A', 'quantity' => 10, 'quant' => 1], ['barcode' => '2000000000022', 'quantity' => 6, 'quant' => 1],
        ]]]]];
        self::assertSame('SUCCESS', $this->complete('/v1/cargoes/create', $mixed)['status']);
        $rule = $this->call('/v1/cargoes/rules/get', ['supply_ids' => [$supply['supply_id']]])['supply_check_lists'][0]['placement_zones_rule'];
        self::assertSame(['count_cargoes_all' => 1, 'count_cargoes_with_mono_placement_zone' => 0, 'is_applicable' => true, 'satisfied' => false], $rule);
    }

    /** Every async family preserves its own failure envelope and enum.
     * @see OperationCatalog::response()
     */
    #[Test]
    public function returnsContractualFailureForEveryOperationFamily(): void
    {
        $order  = $this->createOrder();
        $id     = $order['supplies'][0]['supply_id'];
        $supply = ['supply_id' => $id];
        $inputs = [
            '/v1/cargoes/create'                          => $this->cargoInput($id),
            '/v1/cargoes/delete'                          => $supply + ['cargo_ids' => [1]],
            '/v2/cargoes/delete'                          => $supply + ['cargo_ids' => [1], 'transport_cargo_deletion_type' => 'UNBIND_CONTAINED_CARGOES'],
            '/v1/cargoes/transport/activate'              => $supply + ['is_transport' => true],
            '/v1/cargoes/transport/create'                => $supply + ['transport_cargoes' => [['type' => 'PALLET', 'count' => 1]]],
            '/v1/cargoes/transport/bind'                  => $supply + ['cargoes_unbind_transport_cargoes' => [1]],
            '/v1/cargoes-label/create'                    => $supply,
            '/v1/cargoes/label/transport/create'          => $supply,
            '/v1/cargoes/label/transport-by-order/create' => ['order_id' => $order['order_id']],
            '/v1/supply-order/content/update'             => $supply + ['order_id' => $order['order_id'], 'items' => [['sku' => 910001, 'quantity' => 10, 'quant' => 1]]],
            '/v1/supply-order/timeslot/update'            => ['supply_order_id' => $order['order_id'], 'timeslot' => $order['timeslot']['timeslot']],
            '/v1/supply-order/pass/create'                => ['supply_order_id' => $order['order_id'], 'vehicle' => ['driver_name' => 'Test', 'driver_phone' => '123', 'vehicle_model' => 'Van', 'vehicle_number' => 'TEST']],
            '/v1/supply-order/cancel'                     => ['order_id' => $order['order_id']],
        ];
        foreach ($inputs as $path => $input) {
            $this->control(['type' => 'scenario', 'path' => $path, 'fail' => true]);
            $result = $this->complete($path, $input);
            self::assertContains($result['status'] ?? $result['result'], ['FAILED', 'ERROR', 'Failed', 'STATUS_ERROR'], $path);
        }
    }

    /** Expired URLs and another cabinet cannot reuse a document capability.
     * @see LabelService::download()
     */
    #[Test]
    public function isolatesAndExpiresDocumentLinks(): void
    {
        $id = $this->createOrder()['supplies'][0]['supply_id'];
        $this->complete('/v1/cargoes/create', $this->cargoInput($id));
        $label = $this->complete('/v1/cargoes-label/create', ['supply_id' => $id]);
        parse_str(parse_url($label['result']['file_url'], PHP_URL_QUERY), $query);
        $labels = $this->container->get(LabelService::class);
        $this->configure('2002');
        try {
            $labels->download('2002', $query['document_id'], $query['token']);
            self::fail('Foreign document must be hidden');
        } catch (SellerApiException $error) {
            self::assertSame(404, $error->status);
        }
        $this->clock->timestamp += 86401;
        try {
            $labels->download('1001', $query['document_id'], $query['token']);
            self::fail('Expired document capability must be rejected');
        } catch (SellerApiException $error) {
            self::assertSame(410, $error->status);
        }
    }

    /** A stale pending mutation cannot overwrite a later external cancellation.
     * @see AdvanceOperationsHandler::handle()
     */
    #[Test]
    public function rejectsPendingCargoAfterExternalCancellation(): void
    {
        $order = $this->createOrder();
        $op    = $this->call('/v1/cargoes/create', $this->cargoInput($order['supplies'][0]['supply_id']));
        $this->control(['type' => 'state', 'orderId' => $order['order_id'], 'state' => 'CANCELLED']);
        $this->clock->timestamp += 3;
        self::assertSame('FAILED', $this->call('/v2/cargoes/create/info', $op)['status']);
        self::assertSame([], $this->call('/v1/cargoes/get', ['supply_ids' => [$order['supplies'][0]['supply_id']]])['supply'][0]['cargoes']);
    }

    /** Reset addresses one cabinet and never reuses old external identifiers.
     * @see ControlCabinetHandler::handle()
     */
    #[Test]
    public function resetsOnlyNamedCabinetAndPreservesMonotonicIds(): void
    {
        $order = $this->createOrder();
        $this->configure('2002');
        $this->control(['eventId' => 'reset-a', 'type' => 'reset', 'clientId' => '1001']);
        self::assertSame([], $this->call('/v3/supply-order/list', ['filter' => ['states' => []], 'sort_by' => 'ORDER_CREATION', 'limit' => 100])['order_ids']);
        self::assertGreaterThan($order['order_id'], $this->createOrder()['order_id']);
        self::assertTrue($this->repository->change('2002', static fn (CabinetState $state): bool => $state->config()['fboEnabled']));
    }

    /** Cargo cannot be removed from stock after handover or a passed editing deadline.
     * @see CargoService::apply()
     */
    #[Test]
    public function blocksCargoAtDeadlineAndHonoursWarehouseLimits(): void
    {
        $this->configuration['cargoLimits'] = ['max_box_count' => 1, 'max_box_sku_count' => 1, 'max_pallet_count' => 1, 'max_transport_pallet_count' => 1];
        $this->configure();
        $order = $this->createOrder();
        $id    = $order['supplies'][0]['supply_id'];
        self::assertSame(['WAREHOUSE_LIMITS_EXCEED'], $this->complete('/v1/cargoes/create', $this->cargoInput($id))['errors']['error_reasons']);
        $this->clock->timestamp = strtotime($order['data_filling_deadline_utc']) + 1;
        self::assertSame(['INVALID_STATE'], $this->complete('/v1/cargoes/create', $this->cargoInput($id))['errors']['error_reasons']);
    }

    /** EDI facts block content changes and appear in capabilities.
     * @see OrderMutationService::apply()
     */
    #[Test]
    public function blocksContentAfterUtdUpload(): void
    {
        $order = $this->createOrder();
        $id    = $order['supplies'][0]['supply_id'];
        $this->control(['type' => 'requirements', 'supplyId' => $id, 'utdUploaded' => true, 'ettnUploaded' => false, 'evsdUploaded' => false]);
        self::assertFalse($this->call('/v1/supply-order/details', ['order_id' => $order['order_id']])['supplies'][0]['content']['can_set']);
        self::assertSame(['HAS_UTD'], $this->complete('/v1/supply-order/content/update', ['order_id' => $order['order_id'], 'supply_id' => $id, 'items' => [['sku' => 910001, 'quantity' => 5, 'quant' => 1]]])['errors']);
    }

    /** Readiness requires configured vehicle and documents, without creating physical acceptance.
     * @see ReadinessService::refresh()
     */
    #[Test]
    public function becomesReadyOnlyWhenConfiguredRequirementsAreSatisfied(): void
    {
        $this->configuration['vehicleRequired']           = true;
        $this->configuration['products'][0]['supplyTags'] = ['UTD_REQUIRED'];
        $this->configure();
        $order = $this->createOrder();
        $id    = $order['supplies'][0]['supply_id'];
        $this->complete('/v1/cargoes/create', $this->cargoInput($id));
        self::assertSame('DATA_FILLING', $this->call('/v3/supply-order/get', ['order_ids' => [$order['order_id']]])['orders'][0]['state']);
        $this->complete('/v1/supply-order/pass/create', ['supply_order_id' => $order['order_id'], 'vehicle' => ['driver_name' => 'Test', 'driver_phone' => '123', 'vehicle_model' => 'Van', 'vehicle_number' => 'TEST']]);
        $this->control(['type' => 'requirements', 'supplyId' => $id, 'utdUploaded' => true, 'ettnUploaded' => false, 'evsdUploaded' => false]);
        self::assertSame('READY_TO_SUPPLY', $this->call('/v3/supply-order/get', ['order_ids' => [$order['order_id']]])['orders'][0]['state']);
        self::assertSame([], $this->call('/v1/supply-order/act/product/get', ['supply_id' => $id])['supply_acts']);
    }

    /** A confirmed reschedule frees the original capacity and consumes the new slot.
     * @see OrderMutationService::timeslots()
     */
    #[Test]
    public function releasesOldSlotAfterReschedule(): void
    {
        $this->configuration['slots']['capacity'] = 1;
        $this->configure();
        $order     = $this->createOrder();
        $original  = $order['timeslot']['timeslot'];
        $available = $this->call('/v2/supply-order/timeslot/list', ['order_id' => $order['order_id']])['timeslots_info']['timeslots'];
        self::assertNotContains($original, $available);
        $replacement = $available[0];
        self::assertSame('STATUS_SUCCESS', $this->complete('/v1/supply-order/timeslot/update', ['supply_order_id' => $order['order_id'], 'timeslot' => $replacement])['status']);
        $after = $this->call('/v2/supply-order/timeslot/list', ['order_id' => $order['order_id']])['timeslots_info']['timeslots'];
        self::assertContains($original, $after);
        self::assertNotContains($replacement, $after);
    }
}
