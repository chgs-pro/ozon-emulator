<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Feature\Fbo\CabinetState;
use App\Feature\Fbo\Command\ControlCabinet\ControlCabinetHandler;
use App\Feature\Fbo\SellerApiException;
use App\Feature\Fbs\FbsCarriageService;
use App\Feature\Fbs\FbsConfig;
use App\Feature\Fbs\FbsPostingGenerator;
use App\Feature\Fbs\FbsReadService;
use App\Feature\Fbs\FbsRequirements;
use App\Feature\Fbs\FbsStockService;
use App\Tests\Support\FboTestCase;
use PHPUnit\Framework\Attributes\{CoversClass, CoversMethod, Test};

use function array_column;
use function array_map;
use function base64_decode;
use function dirname;
use function file_get_contents;
use function gmdate;
use function json_decode;
use function str_pad;
use function substr;

use const DATE_ATOM;
use const JSON_THROW_ON_ERROR;
use const STR_PAD_LEFT;

#[CoversClass(FbsCarriageService::class)]
#[CoversMethod(FbsReadService::class, 'read')]
#[CoversMethod(ControlCabinetHandler::class, 'handle')]
final class FbsCarriageTest extends FboTestCase
{
    private const int METHOD = 1030001;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configuration = json_decode(file_get_contents(dirname(__DIR__, 2) . '/fixtures/fbs-basic.json'), true, flags: JSON_THROW_ON_ERROR);
        $this->configure();
    }

    /** Метод доставки на дату: собранные отправления вне отгрузки дают «можно создать»; несобранные — предупреждение.
     * Создание забирает все собранные отправления метода и даты, повторное создание не допускается.
     * @see FbsCarriageService::handle()
     */
    #[Test]
    public function createsCarriageFromAssembledPostings(): void
    {
        $this->posting('10000001-0001-1', 'awaiting_deliver');
        $this->posting('10000002-0001-1', 'awaiting_deliver');
        $this->posting('10000003-0001-1', 'awaiting_packaging');
        $this->posting('10000004-0001-1', 'awaiting_deliver', 2);

        $method = $this->methods()[0];
        self::assertSame([self::METHOD, 3, 2, 0], [$method['delivery_method_id'], $method['mandatory_postings_count'], $method['mandatory_packaged_count'], $method['carriage_postings_count']]);
        self::assertSame([['id' => 0, 'actions' => ['create'], 'count' => 2]], $this->carriages($method));
        self::assertSame('NOT_ALL_POSTINGS_PACKAGED', $method['errors'][0]['code']);
        self::assertSame([], $this->methods(self::METHOD, gmdate('Y-m-d', $this->clock->timestamp)));
        self::assertCount(1, $this->methods(1020001), 'FBS: фильтр по ID склада.');

        $id = $this->create();

        self::assertSame(['10000001-0001-1', '10000002-0001-1'], array_column($this->call('/v2/posting/fbs/act/get-postings', ['id' => $id])['result'], 'posting_number'));
        self::assertSame('posting_in_carriage', $this->get('10000001-0001-1')['substatus']);
        self::assertSame([['id' => $id, 'actions' => [], 'count' => 2]], $this->carriages($this->methods()[0]));
        self::assertSame(2, $this->methods()[0]['carriage_postings_count']);
        $this->expectFailure(fn () => $this->create(), 'already exists');
    }

    /** Состав меняется только до подтверждения: чужие и несобранные отправления отклоняются построчно, убранные ждут следующую отгрузку.
     * @see FbsCarriageService::handle()
     */
    #[Test]
    public function replacesCompositionOnlyInStatusNew(): void
    {
        $this->posting('10000001-0001-1', 'awaiting_deliver');
        $this->posting('10000002-0001-1', 'awaiting_deliver');
        $this->posting('10000003-0001-1', 'awaiting_packaging');
        $id = $this->create();

        $result = $this->call('/v1/carriage/set-postings', ['carriage_id' => $id, 'posting_numbers' => ['10000001-0001-1', '10000003-0001-1', '99999999-0001-1']])['result'];

        self::assertSame([true, false, false], array_column($result, 'result'));
        self::assertSame(['', 'POSTING_NOT_ASSEMBLED', 'POSTING_NOT_FOUND'], array_column($result, 'error'));
        self::assertSame('posting_not_in_carriage', $this->get('10000002-0001-1')['substatus']);
        $this->call('/v1/carriage/approve', ['carriage_id' => $id]);
        $this->expectFailure(fn () => $this->call('/v1/carriage/set-postings', ['carriage_id' => $id, 'posting_numbers' => ['10000002-0001-1']]), 'only in status new');
    }

    /** Подтверждённая отгрузка формирует документы по часам кабинета: сначала `in_process`, затем `ready` и действия с документами.
     * @see FbsCarriageService::handle()
     */
    #[Test]
    public function formsDocumentsAfterApproval(): void
    {
        $this->posting('10000001-0001-1', 'awaiting_deliver');
        $id = $this->create();
        $this->expectFailure(fn () => $this->call('/v2/posting/fbs/act/check-status', ['id' => $id]), 'not approved');

        $this->call('/v1/carriage/approve', ['carriage_id' => $id]);

        $carriage = $this->call('/v1/carriage/get', ['carriage_id' => $id]);
        self::assertSame(['formed', [], true], [$carriage['status'], $carriage['available_actions'], $carriage['cancel_availability']['is_cancel_available']]);
        self::assertSame('in_process', $this->call('/v2/posting/fbs/act/check-status', ['id' => $id])['result']['status']);
        $this->expectFailure(fn () => $this->call('/v2/posting/fbs/act/get-pdf', ['id' => $id]), 'not ready');
        $this->clock->timestamp += FbsCarriageService::DOCUMENTS_SECONDS;
        $status = $this->call('/v2/posting/fbs/act/check-status', ['id' => $id])['result'];
        self::assertSame(['ready', ['10000001-0001-1']], [$status['status'], $status['added_to_act']]);
        self::assertSame(['get_shipping_list', 'get_act_of_acceptance'], $this->call('/v1/carriage/get', ['carriage_id' => $id])['available_actions']);
        self::assertSame('OZN' . str_pad((string) $id, 10, '0', STR_PAD_LEFT), $this->call('/v2/posting/fbs/act/get-barcode/text', ['id' => $id])['result']);
        $file = $this->call('/v2/posting/fbs/act/get-pdf', ['id' => $id]);
        self::assertSame(['carriage-' . $id . '.pdf', 'application/pdf'], [$file['file_name'], $file['content_type']]);
        self::assertStringStartsWith('%PDF', (string) base64_decode($file['file_content'], true));
    }

    /** Пункт приёма со сценарием пропуска требует его у отгрузки: `set_arrival_passes`, затем пропуск добавляется к перевозке.
     * @see FbsCarriageService::handle()
     */
    #[Test]
    public function requiresAnArrivalPassWhenTheScenarioAsks(): void
    {
        $this->control(['type' => 'fbsScenario', 'carriagePassRequired' => true]);
        $this->posting('10000001-0001-1', 'awaiting_deliver');
        $id = $this->create();
        $this->call('/v1/carriage/approve', ['carriage_id' => $id]);
        self::assertContains('set_arrival_passes', $this->call('/v1/carriage/get', ['carriage_id' => $id])['available_actions']);

        $pass = ['driver_name' => 'Иванов Иван', 'driver_phone' => '+79990000000', 'vehicle_license_plate' => 'А123БВ777', 'vehicle_model' => 'ГАЗель', 'with_returns' => true];
        $ids  = $this->call('/v1/carriage/pass/create', ['carriage_id' => $id, 'arrival_passes' => [$pass]])['arrival_pass_ids'];

        self::assertSame($ids, $this->call('/v1/carriage/get', ['carriage_id' => $id])['arrival_pass_ids']);
        $this->expectFailure(fn () => $this->call('/v1/carriage/pass/create', ['carriage_id' => $id, 'arrival_passes' => [['driver_name' => 'Без телефона']]]), 'driver_phone');
    }

    /** Отмена до передачи возвращает отправления вне отгрузки; закрытую отгрузку отменить нельзя.
     * @see FbsCarriageService::handle()
     */
    #[Test]
    public function cancelsCarriageBeforeHandover(): void
    {
        $this->posting('10000001-0001-1', 'awaiting_deliver');
        $id = $this->create();
        $this->call('/v1/carriage/approve', ['carriage_id' => $id]);

        self::assertSame(['carriage_status' => 'cancelled', 'error' => ''], $this->call('/v1/carriage/cancel', ['carriage_id' => $id]));

        self::assertSame('posting_not_in_carriage', $this->get('10000001-0001-1')['substatus']);
        self::assertSame([['id' => $id, 'actions' => [], 'count' => 1], ['id' => 0, 'actions' => ['create'], 'count' => 1]], $this->carriages($this->methods()[0]));
        $again = $this->create();
        $this->call('/v1/carriage/approve', ['carriage_id' => $again]);
        $this->control(['type' => 'fbsHandover', 'carriageId' => $again]);
        self::assertSame('Carriage is closed', $this->call('/v1/carriage/cancel', ['carriage_id' => $again])['error']);
    }

    /** Передача водителю (управляющее событие): принятые отправления в доставке и уходят из несобранных, резерв снят;
     * непринятые возвращаются вне отгрузки.
     * @see FbsCarriageService::handover()
     * @see FbsReadService::read()
     */
    #[Test]
    public function handsOverAcceptedPostings(): void
    {
        $this->posting('10000001-0001-1', 'awaiting_deliver');
        $this->posting('10000002-0001-1', 'awaiting_deliver');
        $this->posting('10000003-0001-1', 'awaiting_deliver');
        $id = $this->create();
        $this->expectFailure(fn () => $this->control(['type' => 'fbsHandover', 'carriageId' => $id]), 'Only a formed carriage');
        $this->call('/v1/carriage/approve', ['carriage_id' => $id]);

        $this->repository->change('1001', static function (CabinetState $state): void {
            $state->data['fbs']['postings']['10000003-0001-1']['status'] = 'cancelled';
        });
        $this->control(['type' => 'fbsHandover', 'carriageId' => $id, 'missingPostings' => ['10000002-0001-1']]);
        self::assertSame('cancelled', $this->get('10000003-0001-1')['status'], 'Отменённое после подтверждения отправление не доставляется.');

        self::assertSame(['delivering', 'posting_transferred_to_courier_service'], [$this->get('10000001-0001-1')['status'], $this->get('10000001-0001-1')['substatus']]);
        self::assertSame(['awaiting_deliver', 'posting_not_in_carriage'], [$this->get('10000002-0001-1')['status'], $this->get('10000002-0001-1')['substatus']]);
        $carriage = $this->call('/v1/carriage/get', ['carriage_id' => $id]);
        self::assertSame(['closed', true], [$carriage['status'], $carriage['has_postings_for_next_carriage']]);
        $unfulfilled = $this->call('/v4/posting/fbs/unfulfilled/list', ['limit' => 100, 'filter' => [
            'cutoff_from' => gmdate(DATE_ATOM, $this->clock->timestamp - 86400), 'cutoff_to' => gmdate(DATE_ATOM, $this->clock->timestamp + 7 * 86400)]]);
        self::assertSame(['10000002-0001-1'], array_column($unfulfilled['postings'], 'posting_number'));
        self::assertSame(['910001:1020001' => 1], $this->repository->change('1001', static fn (CabinetState $state): array => FbsStockService::reserved($state)), 'Резерв держит только непринятое отправление.');
    }

    private function create(): int
    {
        return $this->call('/v1/carriage/create', ['delivery_method_id' => self::METHOD, 'departure_date' => gmdate('Y-m-d', $this->clock->timestamp + 86400) . 'T00:00:00Z'])['carriage_id'];
    }

    /** @return list<array> */
    private function methods(?int $methodId = null, ?string $date = null): array
    {
        return $this->call('/v2/carriage/delivery/list', ['limit' => 100, 'filter' => ['departure_date' => $date ?? gmdate('Y-m-d', $this->clock->timestamp + 86400),
            ...($methodId === null ? [] : ['delivery_method_id' => $methodId])]])['methods'];
    }

    /** @return list<array{id: int, actions: list<string>, count: int}> */
    private function carriages(array $method): array
    {
        return array_map(static fn (array $c): array => ['id' => $c['id'], 'actions' => $c['available_actions'], 'count' => $c['postings_count']], $method['carriages']);
    }

    private function get(string $number): array
    {
        return $this->call('/v3/posting/fbs/get', ['posting_number' => $number])['result'];
    }

    /** An FBS posting of SKU 910001 × 1 with the given status; `$days` — days until the shipment date. */
    private function posting(string $number, string $status, int $days = 1): void
    {
        $this->repository->change('1001', function (CabinetState $state) use ($number, $status, $days): void {
            FbsConfig::of($state);
            $posting = [
                'posting_number'     => $number, 'order_id' => (int) $number, 'order_number' => substr($number, 0, -2), 'status' => $status,
                'substatus'          => $status === 'awaiting_deliver' ? 'posting_not_in_carriage' : 'posting_created', 'warehouse_id' => 1020001,
                'delivery_method_id' => self::METHOD, 'in_process_at' => $this->clock->timestamp, 'shipment_date' => $this->clock->timestamp + $days * 86400,
                'products'           => [['sku' => 910001, 'offer_id' => 'FBO-TEST-A', 'name' => 'Тестовый товар А', 'quantity' => 1, 'price' => '500.00']],
                'requirements'       => FbsRequirements::fromConfig(FbsConfig::of($state), [910001]),
            ];
            $posting['available_actions']            = FbsPostingGenerator::actions($posting);
            $state->data['fbs']['postings'][$number] = $posting;
        });
    }

    private function expectFailure(callable $call, string $message): void
    {
        try {
            $call();
            self::fail('Expected failure: ' . $message);
        } catch (SellerApiException $error) {
            self::assertStringContainsString($message, $error->getMessage());
        }
    }
}
