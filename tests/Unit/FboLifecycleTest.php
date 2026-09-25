<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Feature\Fbo\Command\AdvanceSupply\AdvanceSupplyHandler;
use App\Feature\Fbo\Command\ConfigureCabinet\ConfigureCabinetHandler;
use App\Feature\Fbo\Command\CreateSupply\CreateSupplyHandler;
use App\Feature\Fbo\Contract;
use App\Feature\Fbo\DraftPlanner;
use App\Feature\Fbo\FboReadService;
use App\Feature\Fbo\SellerApiException;
use App\Feature\Fbo\SellerApiService;
use App\Feature\Fbo\TimeslotService;
use App\Feature\Token\TokenIdentity;
use App\Tests\Support\FboTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\Test;

use function array_column;
use function array_map;
use function array_sum;
use function gmdate;

#[CoversClass(SellerApiService::class)]
#[CoversClass(DraftPlanner::class)]
#[CoversClass(FboReadService::class)]
#[CoversClass(Contract::class)]
#[CoversClass(TimeslotService::class)]
#[CoversClass(ConfigureCabinetHandler::class)]
#[CoversClass(CreateSupplyHandler::class)]
#[CoversClass(AdvanceSupplyHandler::class)]
#[CoversMethod(SellerApiService::class, 'execute')]
#[CoversMethod(DraftPlanner::class, 'calculate')]
#[CoversMethod(CreateSupplyHandler::class, 'handle')]
#[CoversMethod(AdvanceSupplyHandler::class, 'handle')]
final class FboLifecycleTest extends FboTestCase
{
    /** Creation publishes consistent IDs and contents, without physical acceptance.
     * @see SellerApiService::execute()
     */
    #[Test]
    public function createsConsistentSupplyAndBundle(): void
    {
        $order = $this->createOrder();
        self::assertSame('DATA_FILLING', $order['state']);
        $bundle = $this->call('/v1/supply-order/bundle', ['bundle_ids' => [$order['supplies'][0]['bundle_id']],'limit' => 100]);
        self::assertSame([10,6], array_column($bundle['items'], 'quantity'));
        self::assertSame(16, array_sum(array_column($bundle['items'], 'quantity')));
        $details = $this->call('/v1/supply-order/details', ['order_id' => $order['order_id']]);
        self::assertSame($order['supplies'][0]['bundle_id'], $details['supplies'][0]['content']['bundle_id']);
        self::assertSame([$order['order_id']], array_map('intval', $this->call('/v3/supply-order/list', ['filter' => ['states' => ['DATA_FILLING']],'limit' => 100,'sort_by' => 'ORDER_CREATION'])['order_ids']));
    }
    /** Delayed operations expose progress before readiness and survive draft expiration after creation.
     * @see SellerApiService::execute()
     */
    #[Test]
    public function exposesAsyncProgressAndPreservesCreatedResult(): void
    {
        $id = $this->draft();
        self::assertSame('IN_PROGRESS', $this->call('/v2/draft/create/info', ['draft_id' => $id])['status']);
        $input = $this->supplyInput($id);
        self::assertSame('SUCCESS', $this->call('/v2/draft/create/info', ['draft_id' => $id])['status']);
        $this->call('/v2/draft/supply/create', $input);
        self::assertSame('IN_PROGRESS', $this->call('/v2/draft/supply/create/status', ['draft_id' => $id])['status']);
        self::assertSame(['ORDER_CREATION_IN_PROGRESS'], $this->call('/v2/draft/supply/create', $input)['error_reasons']);
        $this->clock->timestamp += 2000;
        self::assertSame('SUCCESS', $this->call('/v2/draft/supply/create/status', ['draft_id' => $id])['status']);
        self::assertSame(['ORDER_ALREADY_CREATED'], $this->call('/v2/draft/supply/create', $input)['error_reasons']);
    }
    /** FULL rejects the entire SKU while PARTIAL preserves its permitted units.
     * @see DraftPlanner::calculate()
     */
    #[Test]
    public function respectsFullAndPartialRejection(): void
    {
        $this->configuration['products'][0]['maxQuantity'] = 4;
        $this->configure();
        foreach (['FULL' => [6,10],'PARTIAL' => [10,6]] as $mode => [$accepted,$rejected]) {
            $id = $this->draft($mode);
            $this->clock->timestamp += 3;
            $w = $this->call('/v2/draft/create/info', ['draft_id' => $id])['clusters'][0]['warehouses'][0];
            $a = $this->call('/v1/supply-order/bundle', ['bundle_ids' => [$w['bundle_id']],'limit' => 100]);
            $r = $this->call('/v1/supply-order/bundle', ['bundle_ids' => [$w['restricted_bundle_id']],'limit' => 100]);
            self::assertSame($accepted, array_sum(array_column($a['items'], 'quantity')));
            self::assertSame($rejected, array_sum(array_column($r['items'], 'quantity')));
        }
    }
    /** Multi-cluster creation preserves individual destinations and combined quantities.
     * @see DraftPlanner::calculate()
     * @see AdvanceSupplyHandler::handle()
     */
    #[Test]
    public function createsMultiClusterSupplyWithoutMixingDropoffAndStorage(): void
    {
        $id = $this->call('/v1/draft/multi-cluster/create', ['clusters_info' => [['macrolocal_cluster_id' => 510001,'items' => [['sku' => 910001,'quantity' => 10]]],['macrolocal_cluster_id' => 510002,'items' => [['sku' => 910002,'quantity' => 6]]]],'deletion_sku_mode' => 'PARTIAL','delivery_info' => ['type' => 'DROPOFF','drop_off_warehouse' => ['warehouse_id' => 710003,'warehouse_type' => 'SORTING_CENTER']]])['draft_id'];
        $this->clock->timestamp += 3;
        $input = ['draft_id' => $id,'supply_type' => 'MULTI_CLUSTER','selected_cluster_warehouses' => [['macrolocal_cluster_id' => 510001],['macrolocal_cluster_id' => 510002]]];
        $slots = $this->call('/v2/draft/timeslot/info', $input + ['date_from' => gmdate('Y-m-d', $this->clock->timestamp),'date_to' => gmdate('Y-m-d', $this->clock->timestamp + 86400)]);
        $this->call('/v2/draft/supply/create', $input + ['timeslot' => $slots['result']['drop_off_warehouse_timeslots']['days'][0]['timeslots'][0]]);
        $this->clock->timestamp += 3;
        $orderId = $this->call('/v2/draft/supply/create/status', ['draft_id' => $id])['order_id'];
        $order   = $this->call('/v3/supply-order/get', ['order_ids' => [$orderId]])['orders'][0];
        self::assertCount(2, $order['supplies']);
        self::assertSame(710003, $order['dropoff_warehouse']['warehouse_id']);
        self::assertSame([710001,710002], array_column(array_column($order['supplies'], 'storage_warehouse'), 'warehouse_id'));
    }
    /** A reserved slot cannot be acquired by a competing pending creation.
     * @see TimeslotService::slots()
     */
    #[Test]
    public function preventsDoubleBookingPendingSlot(): void
    {
        $this->configuration['slots']['capacity'] = 1;
        $this->configure();
        $a      = $this->draft();
        $b      = $this->draft();
        $first  = $this->supplyInput($a);
        $second = $this->selection($b) + ['timeslot' => $first['timeslot']];
        $this->call('/v2/draft/supply/create', $first);
        self::assertSame(['TIMESLOT_NOT_AVAILABLE'], $this->call('/v2/draft/supply/create', $second)['error_reasons']);
    }
    /** Reconfiguring a cabinet preserves accepted operations and is stable for unchanged input.
     * @see ConfigureCabinetHandler::handle()
     */
    #[Test]
    public function preservesSuppliesWhenConfigurationChanges(): void
    {
        $order = $this->createOrder();
        self::assertSame(1, $this->configure());
        $this->configuration['slots']['capacity'] = 3;
        self::assertSame(2, $this->configure());
        self::assertSame($order, $this->call('/v3/supply-order/get', ['order_ids' => [$order['order_id']]])['orders'][0]);
    }
    /** A transport failure after commit remains discoverable by polling.
     * @see SellerApiService::execute()
     */
    #[Test]
    public function reconcilesFailureAfterWriteWithoutDuplicate(): void
    {
        $id                           = $this->draft();
        $input                        = $this->supplyInput($id);
        $this->configuration['fault'] = ['path' => '/v2/draft/supply/create','phase' => 'after','status' => 504,'remaining' => 1];
        $this->configure();
        try {
            $this->call('/v2/draft/supply/create', $input);
            self::fail('Expected failure');
        } catch (SellerApiException $e) {
            self::assertSame(504, $e->status);
        }
        $this->clock->timestamp += 3;
        $status = $this->call('/v2/draft/supply/create/status', ['draft_id' => $id]);
        self::assertSame('SUCCESS', $status['status']);
        self::assertSame(['ORDER_ALREADY_CREATED'], $this->call('/v2/draft/supply/create', $input)['error_reasons']);
    }
    /** A failure before commit must leave no accepted creation to discover.
     * @see SellerApiService::execute()
     */
    #[Test]
    public function keepsNoCreationAfterFailureBeforeWrite(): void
    {
        $id                           = $this->draft();
        $input                        = $this->supplyInput($id);
        $this->configuration['fault'] = ['path' => '/v2/draft/supply/create','phase' => 'before','status' => 503,'remaining' => 1];
        $this->configure();
        try {
            $this->call('/v2/draft/supply/create', $input);
            self::fail('Expected failure');
        } catch (SellerApiException $e) {
            self::assertSame(503, $e->status);
        }
        self::assertSame('FAILED', $this->call('/v2/draft/supply/create/status', ['draft_id' => $id])['status']);
        self::assertSame([], $this->call('/v2/draft/supply/create', $input)['error_reasons']);
    }
    /** Knowledge of a foreign ID never grants access to its bundle or order.
     * @see FboReadService::read()
     */
    #[Test]
    public function isolatesCabinetsOnRead(): void
    {
        $order = $this->createOrder();
        $this->configure('1002');
        $this->identity = new TokenIdentity('1002', 'ozon-seller');

        $this->expectException(SellerApiException::class);
        $this->call('/v3/supply-order/get', ['order_ids' => [$order['order_id']]]);
    }
    /** Revocation of write access is enforced in the domain boundary.
     * @see SellerApiService::execute()
     */
    #[Test]
    public function deniesReadOnlyWrite(): void
    {
        $this->configuration['writeEnabled'] = false;
        $this->configure();
        $this->expectException(SellerApiException::class);
        $this->draft();
    }
    /** Pagination covers complete bundle contents with no duplicated SKU.
     * @see FboReadService::read()
     */
    #[Test]
    public function paginatesBundleCompletely(): void
    {
        $order = $this->createOrder();
        $input = ['bundle_ids' => [$order['supplies'][0]['bundle_id']],'limit' => 1];
        $first = $this->call('/v1/supply-order/bundle', $input);
        self::assertTrue($first['has_next']);
        $second = $this->call('/v1/supply-order/bundle', $input + ['last_id' => $first['last_id']]);
        self::assertFalse($second['has_next']);
        self::assertSame([910001,910002], [$first['items'][0]['sku'],$second['items'][0]['sku']]);
    }
    /** An expired unconsumed draft cannot create a supply.
     * @see CreateSupplyHandler::handle()
     */
    #[Test]
    public function rejectsExpiredDraftCreation(): void
    {
        $id    = $this->draft();
        $input = $this->supplyInput($id);
        $this->clock->timestamp += 2000;
        self::assertSame(['DRAFT_DOES_NOT_EXIST'], $this->call('/v2/draft/supply/create', $input)['error_reasons']);
    }
    /** A changed route cannot silently ship a SKU accepted by an older calculation.
     * @see CreateSupplyHandler::handle()
     */
    #[Test]
    public function rechecksProductEligibilityAfterDraft(): void
    {
        $id                                                = $this->draft();
        $input                                             = $this->supplyInput($id);
        $this->configuration['products'][0]['maxQuantity'] = 1;
        $this->configure();
        self::assertSame(['INVALID_SUPPLY_CONTENT'], $this->call('/v2/draft/supply/create', $input)['error_reasons']);
    }

    /** Invalid linked configuration must not partially overwrite a usable cabinet.
     * @see ConfigureCabinetHandler::handle()
     */
    #[Test]
    public function rejectsBrokenConfigurationWithoutLosingPreviousVersion(): void
    {
        $this->configuration['clusters'][0]['warehouseIds'] = [99999];
        try {
            $this->configure();
            self::fail('Expected rejection');
        } catch (SellerApiException) {
            self::assertNotEmpty($this->call('/v2/cluster/list')['result']);
        }
        self::assertGreaterThan(0, $this->draft());
    }

    /** A configured refusal finishes as FAILED and never publishes an order.
     * @see AdvanceSupplyHandler::handle()
     */
    #[Test]
    public function doesNotPublishOrderAfterAsyncRefusal(): void
    {
        $this->configuration['supplyFailure'] = true;
        $this->configure();
        $id = $this->draft();
        $this->call('/v2/draft/supply/create', $this->supplyInput($id));
        $this->clock->timestamp += 3;
        self::assertSame('FAILED', $this->call('/v2/draft/supply/create/status', ['draft_id' => $id])['status']);
        self::assertSame([], $this->call('/v3/supply-order/list', ['filter' => ['states' => []], 'sort_by' => 'ORDER_CREATION', 'limit' => 100])['order_ids']);
    }

    /** An inactive contract is checked again before accepting external creation.
     * @see CreateSupplyHandler::handle()
     */
    #[Test]
    public function deniesCreationAfterContractDeactivation(): void
    {
        $id                                    = $this->draft();
        $input                                 = $this->supplyInput($id);
        $this->configuration['contractActive'] = false;
        $this->configure();
        self::assertSame(['INACTIVE_CONTRACT'], $this->call('/v2/draft/supply/create', $input)['error_reasons']);
    }

    /** Item marking and supply-only document requirements remain distinct in API projections.
     * @see DraftPlanner::calculate()
     * @see AdvanceSupplyHandler::handle()
     */
    #[Test]
    public function preservesMarkingAndSupplyDocumentRequirements(): void
    {
        $this->configuration['products'][0]['tags']       = ['MARKING_REQUIRED'];
        $this->configuration['products'][0]['supplyTags'] = ['UTD_REQUIRED'];
        $this->configure();
        $order = $this->createOrder();
        self::assertTrue($order['supplies'][0]['supply_tags']['is_marking_required']);
        self::assertTrue($order['supplies'][0]['supply_tags']['is_utd']);
        $items = $this->call('/v1/supply-order/bundle', ['bundle_ids' => [$order['supplies'][0]['bundle_id']], 'limit' => 100])['items'];
        self::assertSame(['MARKING_REQUIRED'], $items[0]['tags']);
    }

}
