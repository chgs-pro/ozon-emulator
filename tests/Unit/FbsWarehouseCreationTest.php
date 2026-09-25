<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Feature\Fbo\CabinetState;
use App\Feature\Fbo\SellerApiException;
use App\Feature\Fbs\FbsPostingGenerator;
use App\Feature\Fbs\FbsReadService;
use App\Feature\Fbs\FbsWarehouseService;
use App\Tests\Support\FboTestCase;
use PHPUnit\Framework\Attributes\{CoversClass, CoversMethod, Test};
use Random\Engine\Mt19937;
use Random\Randomizer;

use function array_column;
use function dirname;
use function file_get_contents;
use function json_decode;

use const JSON_THROW_ON_ERROR;

#[CoversClass(FbsWarehouseService::class)]
#[CoversMethod(FbsWarehouseService::class, 'handle')]
#[CoversMethod(FbsWarehouseService::class, 'advance')]
#[CoversMethod(FbsReadService::class, 'handle')]
final class FbsWarehouseCreationTest extends FboTestCase
{
    private const array MOSCOW = ['latitude' => 55.7558, 'longitude' => 37.6173];

    protected function setUp(): void
    {
        parent::setUp();
        $this->configuration = json_decode(file_get_contents(dirname(__DIR__, 2) . '/fixtures/fbs-basic.json'), true, flags: JSON_THROW_ON_ERROR);
        $this->configure();
    }

    /** Как у Ozon, склад из конфигурации отдаёт первую милю целиком: DROP_OFF — пункт сдачи (по умолчанию ПВЗ) и его слот, PICK_UP — слот курьера без пункта.
     * @see FbsReadService::warehouse()
     */
    #[Test]
    public function configuredWarehousesExposeTheirFirstMile(): void
    {
        $rows = array_column($this->warehouses(), null, 'warehouse_id');

        self::assertSame(['type' => 'DROP_OFF', 'first_mile_is_changing' => false, 'dropoff_point_id' => '1040001', 'timeslot_id' => 10400011,
            'timeslot_from'      => '10:00', 'timeslot_to' => '14:00'], $rows[1020001]['first_mile']);
        self::assertSame(['type' => 'PICK_UP', 'first_mile_is_changing' => false, 'dropoff_point_id' => '', 'timeslot_id' => 1050001,
            'timeslot_from'      => '09:00', 'timeslot_to' => '13:00'], $rows[1020002]['first_mile']);
        self::assertSame(['address' => 'Тестовый адрес склада 1020001', 'latitude' => 55.7558, 'longitude' => 37.6173, 'utc' => 'UTC+03:00'], $rows[1020001]['address_info']);

        // Адрес и тип пункта сдачи — в списке пунктов для изменения склада; неизвестный склад — 404.
        $points = array_column($this->call('/v1/warehouse/fbs/update/drop-off/list', ['warehouse_id' => 1020001])['points'], null, 'id');
        self::assertSame(['PVZ', 'Пункт выдачи Ozon, Тестовая ул., 1'], [$points['1040001']['type'], $points['1040001']['address']]);
        try {
            $this->call('/v1/warehouse/fbs/update/drop-off/list', ['warehouse_id' => 1029999]);
            self::fail('Неизвестный склад должен давать 404.');
        } catch (SellerApiException $exception) {
            self::assertSame(404, $exception->status);
        }

        $this->configuration['fbs']['warehouses'][0]['dropOffPointId'] = '1040004';
        $this->configure();
        self::assertSame('1040004', array_column($this->warehouses(), null, 'warehouse_id')[1020001]['first_mile']['dropoff_point_id']);
    }

    /** DROP_OFF: пункт из списка рядом с координатами, его таймслот, возвратная миля не нужна; склад `new` → `created` после операции.
     * @see FbsWarehouseService::handle()
     * @see FbsWarehouseService::advance()
     */
    #[Test]
    public function createsDropOffWarehouseAsynchronously(): void
    {
        $points = $this->call('/v1/warehouse/fbs/create/drop-off/list', ['coordinates' => self::MOSCOW, 'country_code' => 'RU', 'is_kgt' => false])['points'];
        self::assertSame(['1040001', '1040002', '1040003', '1040004'], array_column($points, 'id'));
        self::assertEqualsWithDelta(55.7598, $points[0]['coordinates']['latitude'], 0.0001);
        self::assertSame(['1040004'], array_column($this->call('/v1/warehouse/fbs/create/drop-off/list', ['country_code' => 'RU', 'is_kgt' => false, 'search' => ['types' => ['SC']]])['points'], 'id'));
        self::assertSame(['1040001', '1040002'], array_column($this->call('/v1/warehouse/fbs/create/drop-off/list', ['country_code' => 'RU', 'is_kgt' => false, 'search' => ['address' => 'тестовая']])['points'], 'id'));
        self::assertSame(['1040004'], array_column($this->call('/v1/warehouse/fbs/create/drop-off/list', ['country_code' => 'RU', 'is_kgt' => true])['points'], 'id'));

        $slots = $this->call('/v1/warehouse/fbs/create/drop-off/timeslot/list', ['drop_off_point_id' => '1040001'])['timeslots'];
        self::assertSame([10400011, 10400012], array_column($slots, 'id'));
        self::assertFalse($this->call('/v1/warehouse/fbs/return-mile/check', ['country_code' => 'RU', 'first_mile_type' => 'DROP_OFF', 'is_kgt' => false])['should_set_return_mile']);

        $operation = $this->call('/v1/warehouse/fbs/create', ['timeslot_id' => $slots[1]['id']] + $this->dropOffInput())['operation_id'];
        self::assertSame(['status' => 'IN_PROGRESS', 'type' => 'CREATE_FBS_WAREHOUSE'], $this->call('/v1/warehouse/operation/status', ['operation_id' => $operation]));
        $pending = $this->warehouses()[2];
        self::assertSame('new', $pending['status']);

        $this->clock->timestamp += 2;
        $status = $this->call('/v1/warehouse/operation/status', ['operation_id' => $operation]);
        self::assertSame('SUCCESS', $status['status']);
        self::assertSame($pending['warehouse_id'], $status['result']['entity_id']);
        self::assertNotContains($pending['warehouse_id'], [1020001, 1020002, 710001, 710002, 710003]);

        $created = $this->call('/v2/warehouse/list', ['limit' => 10, 'warehouse_ids' => [(string) $pending['warehouse_id']]])['warehouses'][0];
        self::assertSame('created', $created['status']);
        self::assertSame('Склад FBS Тест', $created['name']);
        self::assertSame('+7(999)123-45-67', $created['phone']);
        self::assertSame(['type' => 'DROP_OFF', 'first_mile_is_changing' => false, 'dropoff_point_id' => '1040001', 'timeslot_id' => 10400012,
            'timeslot_from'      => '14:00', 'timeslot_to' => '18:00'], $created['first_mile']);
        self::assertSame(['MONDAY', 'WEDNESDAY', 'FRIDAY'], $created['working_days']);
        self::assertSame(1440, $created['cut_in_time']);
        self::assertTrue($created['is_auto_assembly']);
        self::assertFalse($created['is_kgt']);
    }

    /** PICK_UP: слоты курьера, обязательный пункт возврата с пагинацией по last_id; созданный склад принимает отправления генератора.
     * @see FbsWarehouseService::handle()
     */
    #[Test]
    public function createsPickUpWarehouseWithReturnPointAndUsesItForPostings(): void
    {
        $pickUp = $this->call('/v1/warehouse/fbs/create/pick-up/timeslot/list', ['address_coordinates' => self::MOSCOW, 'is_kgt' => false]);
        self::assertTrue($pickUp['is_pickup_supported']);
        self::assertSame([1050001, 1050002, 1050003], array_column($pickUp['timeslots'], 'id'));
        self::assertSame(['is_pickup_supported' => false, 'timeslots' => []], $this->call('/v1/warehouse/fbs/create/pick-up/timeslot/list', ['address_coordinates' => self::MOSCOW, 'is_kgt' => true]));
        self::assertSame(['should_set_return_mile' => true, 'unavailability_reasons' => []], $this->call('/v1/warehouse/fbs/return-mile/check', ['country_code' => 'RU', 'first_mile_type' => 'PICK_UP', 'is_kgt' => false, 'warehouse_id' => 1020001]));

        $first = $this->call('/v1/warehouse/fbs/create/return-point/list', ['coordinates' => self::MOSCOW, 'country_code' => 'RU', 'limit' => 4, 'selected_dropoff_point_id' => 1040001]);
        self::assertSame([1060001, 1060002, 1060003, 1060004], array_column($first['points'], 'id'));
        self::assertTrue($first['has_next']);
        self::assertSame(1060004, $first['last_id']);
        self::assertTrue($first['is_selected_point_available']);
        $second = $this->call('/v1/warehouse/fbs/create/return-point/list', ['coordinates' => self::MOSCOW, 'country_code' => 'RU', 'limit' => 4, 'last_id' => $first['last_id']]);
        self::assertSame([1060005, 1060006], array_column($second['points'], 'id'));
        self::assertFalse($second['has_next']);
        self::assertFalse($second['is_selected_point_available']);

        $operation = $this->call('/v1/warehouse/fbs/create', $this->pickUpInput())['operation_id'];
        $this->clock->timestamp += 2;
        $id = $this->call('/v1/warehouse/operation/status', ['operation_id' => $operation])['result']['entity_id'];
        self::assertSame(
            ['type' => 'PICK_UP', 'first_mile_is_changing' => false, 'dropoff_point_id' => '', 'timeslot_id' => 1050002, 'timeslot_from' => '13:00', 'timeslot_to' => '18:00'],
            $this->warehouses()[2]['first_mile'],
        );

        self::assertTrue($this->call('/v2/products/stocks', ['stocks' => [['offer_id' => 'FBO-TEST-A', 'warehouse_id' => $id, 'stock' => 5]]])['result'][0]['updated']);
        $random = new Randomizer(new Mt19937(7));

        $this->repository->change('1001', fn (CabinetState $state): array => $this->container->get(FbsPostingGenerator::class)->generate($state, 1, $this->clock->timestamp, $random));
        $posting = $this->call('/v4/posting/fbs/unfulfilled/list', ['limit' => 10])['postings'][0];
        self::assertSame($id, $posting['delivery_method']['warehouse_id']);
        self::assertSame('Ozon Логистика, курьер забирает, Склад курьерский', $posting['delivery_method']['name']);
        self::assertSame('Склад курьерский', $posting['delivery_method']['warehouse']);
    }

    /** Ссылки на пункты и слоты проверяются по спискам; отсутствующий пункт drop-off или возврата — 400, неизвестные ID — 400/404.
     * @see FbsWarehouseService::handle()
     */
    #[Test]
    public function rejectsInvalidCreationRequests(): void
    {
        $withoutPoint = $this->dropOffInput();
        unset($withoutPoint['drop_off_point_id']);
        $withoutReturn = $this->pickUpInput();
        unset($withoutReturn['return_point_id']);
        $cases = [
            'drop-off without point'     => [400, '/v1/warehouse/fbs/create', $withoutPoint],
            'pick-up without return'     => [400, '/v1/warehouse/fbs/create', $withoutReturn],
            'unknown drop-off timeslot'  => [400, '/v1/warehouse/fbs/create', ['timeslot_id' => 1050001] + $this->dropOffInput()],
            'unknown pick-up timeslot'   => [400, '/v1/warehouse/fbs/create', ['timeslot_id' => 10400011] + $this->pickUpInput()],
            'unknown drop-off point'     => [400, '/v1/warehouse/fbs/create', ['drop_off_point_id' => 1049999] + $this->dropOffInput()],
            'unknown return point'       => [400, '/v1/warehouse/fbs/create', ['return_point_id' => 1069999] + $this->pickUpInput()],
            'invalid phone'              => [400, '/v1/warehouse/fbs/create', ['phone' => '89991234567'] + $this->dropOffInput()],
            'duplicate name'             => [400, '/v1/warehouse/fbs/create', ['name' => 'Тестовый склад FBS Москва'] + $this->dropOffInput()],
            'missing required field'     => [400, '/v1/warehouse/fbs/create', ['name' => 'Без слота', 'phone' => '+7(999)123-45-67', 'address_coordinates' => self::MOSCOW, 'first_mile_type' => 'PICK_UP', 'is_kgt' => false, 'cut_in_time' => 60]],
            'timeslots of unknown point' => [404, '/v1/warehouse/fbs/create/drop-off/timeslot/list', ['drop_off_point_id' => 1049999]],
            'unknown operation'          => [404, '/v1/warehouse/operation/status', ['operation_id' => 'missing']],
        ];
        foreach ($cases as $case => [$status, $path, $input]) {
            try {
                $this->call($path, $input);
                self::fail($case . ' must be rejected');
            } catch (SellerApiException $error) {
                self::assertSame($status, $error->status, $case);
            }
        }
        self::assertCount(2, $this->warehouses(), 'Rejected requests do not create warehouses');

        $this->configuration['writeEnabled'] = false;
        $this->configure();
        try {
            $this->call('/v1/warehouse/fbs/create', $this->dropOffInput());
            self::fail('Creation needs write access');
        } catch (SellerApiException $error) {
            self::assertSame(403, $error->status);
        }
        self::assertCount(2, $this->call('/v1/warehouse/fbs/create/drop-off/timeslot/list', ['drop_off_point_id' => 1040002])['timeslots'], 'Lists stay readable');
    }

    /** `fbs.warehouseCreateFailure` завершает операцию статусом ERROR, склад остаётся в статусе `error`.
     * @see FbsWarehouseService::advance()
     */
    #[Test]
    public function configuredFailureEndsOperationWithError(): void
    {
        $this->configuration['fbs']['warehouseCreateFailure'] = true;
        $this->configure();
        $operation = $this->call('/v1/warehouse/fbs/create', $this->dropOffInput())['operation_id'];
        $this->clock->timestamp += 2;

        $status = $this->call('/v1/warehouse/operation/status', ['operation_id' => $operation]);
        self::assertSame('ERROR', $status['status']);
        self::assertSame('CREATE_WAREHOUSE_FAILED', $status['error']['code']);
        self::assertSame('error', $this->warehouses()[2]['status']);
    }

    private function dropOffInput(): array
    {
        return ['name'          => 'Склад FBS Тест', 'phone' => '+7(999)123-45-67', 'address_coordinates' => self::MOSCOW, 'first_mile_type' => 'DROP_OFF',
            'drop_off_point_id' => 1040001, 'timeslot_id' => 10400011, 'is_kgt' => false, 'cut_in_time' => 1440, 'working_days' => ['MONDAY', 'WEDNESDAY', 'FRIDAY'],
            'options'           => ['is_auto_assembly' => true]];
    }

    private function pickUpInput(): array
    {
        return ['name'    => 'Склад курьерский', 'phone' => '+7(999)765-43-21', 'address_coordinates' => self::MOSCOW, 'first_mile_type' => 'PICK_UP',
            'timeslot_id' => '1050002', 'return_point_id' => 1060005, 'is_kgt' => false, 'cut_in_time' => 720,
            'options'     => ['comment' => 'Домофон 12', 'courier_phones' => ['+7(999)000-00-01']]];
    }

    private function warehouses(): array
    {
        return $this->call('/v2/warehouse/list', ['limit' => 200])['warehouses'];
    }
}
