<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Feature\Fbo\CabinetState;
use App\Feature\Fbo\Command\ControlCabinet\ControlCabinetHandler;
use App\Feature\Fbo\LabelService;
use App\Feature\Fbo\SellerApiException;
use App\Feature\Fbs\FbsConfig;
use App\Feature\Fbs\FbsExemplarService;
use App\Feature\Fbs\FbsLabelService;
use App\Feature\Fbs\FbsPostingGenerator;
use App\Feature\Fbs\FbsReadService;
use App\Feature\Fbs\FbsRequirements;
use App\Feature\Fbs\FbsShipService;
use App\Tests\Support\FboTestCase;
use PHPUnit\Framework\Attributes\{CoversClass, CoversMethod, Test};

use function array_column;
use function array_intersect;
use function array_keys;
use function array_map;
use function array_values;
use function dirname;
use function explode;
use function file_get_contents;
use function is_string;
use function json_decode;
use function parse_str;
use function parse_url;
use function str_repeat;
use function str_starts_with;
use function substr;

use const JSON_THROW_ON_ERROR;
use const PHP_URL_QUERY;

#[CoversClass(FbsExemplarService::class)]
#[CoversClass(FbsShipService::class)]
#[CoversClass(FbsLabelService::class)]
#[CoversClass(FbsRequirements::class)]
#[CoversMethod(FbsReadService::class, 'read')]
#[CoversMethod(FbsPostingGenerator::class, 'actions')]
#[CoversMethod(LabelService::class, 'download')]
#[CoversMethod(ControlCabinetHandler::class, 'handle')]
final class FbsAssemblyTest extends FboTestCase
{
    private const string NUMBER = '10000001-0001-1';

    protected function setUp(): void
    {
        parent::setUp();
        $this->configuration                       = json_decode(file_get_contents(dirname(__DIR__, 2) . '/fixtures/fbs-basic.json'), true, flags: JSON_THROW_ON_ERROR);
        $this->configuration['fbs']['countrySkus'] = [910002];
        $this->configuration['fbs']['weightSkus']  = [910004];
        $this->configure();
    }

    /** Экземпляры создаются на каждую единицу один раз; признаки требований — по SKU отправления.
     * @see FbsExemplarService::handle()
     */
    #[Test]
    public function createsExemplarsOncePerUnit(): void
    {
        $this->posting([910003 => 2, 910004 => 1]);

        $first  = $this->call('/v6/fbs/posting/product/exemplar/create-or-get', ['posting_number' => self::NUMBER]);
        $second = $this->call('/v6/fbs/posting/product/exemplar/create-or-get', ['posting_number' => self::NUMBER]);

        self::assertSame($first, $second);
        $products = array_column($first['products'], null, 'product_id');
        self::assertCount(2, $products[910003]['exemplars']);
        self::assertTrue($products[910003]['is_mandatory_mark_needed']);
        self::assertFalse($products[910004]['is_mandatory_mark_needed']);
        self::assertTrue($products[910004]['is_weight_needed']);
        self::assertSame([0.168, 0.252], [$products[910004]['weight_min'], $products[910004]['weight_max']]);
        $posting = $this->get();
        self::assertSame(['910003'], $posting['requirements']['products_requiring_mandatory_mark']);
        self::assertSame(['910004'], $posting['requirements']['products_requiring_weight']);
        self::assertSame(['ship_with_additional_info', 'cancel', 'product_cancel'], $posting['available_actions']);
    }

    /** Проверка асинхронна: до срока — `validation_in_process`, после — подтверждённые данные и сборка с разделением на упаковки.
     * @see FbsExemplarService::handle()
     * @see FbsShipService::handle()
     */
    #[Test]
    public function validatesExemplarsAndShipsPackages(): void
    {
        $this->posting([910003 => 2, 910001 => 1]);
        $this->setExemplars([910003 => [$this->mark('A1'), $this->mark('A2')], 910001 => [[]]]);

        self::assertSame('validation_in_process', $this->exemplarStatus()['status']);
        self::assertSame('processing', $this->exemplarStatus()['products'][0]['exemplars'][0]['marks'][0]['check_status']);
        $this->clock->timestamp += 3;
        $status = $this->exemplarStatus();
        self::assertSame('ship_available', $status['status']);
        self::assertSame('passed', $status['products'][0]['exemplars'][0]['marks'][0]['check_status']);

        $result = $this->call('/v4/posting/fbs/ship', ['posting_number' => self::NUMBER, 'with' => ['additional_data' => true], 'packages' => [
            ['products' => [['product_id' => 910003, 'quantity' => 1], ['product_id' => 910001, 'quantity' => 1]]],
            ['products' => [['product_id' => 910003, 'quantity' => 1]]],
        ]]);

        self::assertSame([self::NUMBER, '10000001-0001-2'], $result['result']);
        self::assertSame([$this->mark('A2')], $result['additional_data'][1]['products'][0]['mandatory_mark']);
        $first  = $this->get(self::NUMBER, ['product_exemplars' => true, 'related_postings' => true, 'barcodes' => true]);
        $second = $this->get('10000001-0001-2');
        self::assertSame(['awaiting_deliver', 'posting_not_in_carriage'], [$first['status'], $first['substatus']]);
        self::assertSame(['label_download_big', 'label_download_small', 'update_cis'], $first['available_actions']);
        self::assertSame(['10000001-0001-2'], $first['related_postings']['related_posting_numbers']);
        self::assertSame($this->mark('A1'), $first['product_exemplars']['products'][0]['exemplars'][0]['mandatory_mark']);
        self::assertSame('1000000100011', $first['barcodes']['lower_barcode']);
        self::assertSame(self::NUMBER, $second['parent_posting_number']);
        self::assertSame([[$this->mark('A2')]], array_column($second['products'], 'mandatory_mark'));
    }

    /** Неверный формат КИЗ (текст `\u001d` вместо GS), повтор и отклонённый сценарием код не проходят проверку; сборка отклоняется.
     * @see FbsExemplarService::handle()
     */
    #[Test]
    public function rejectsInvalidMarks(): void
    {
        $this->control(['type' => 'fbsScenario', 'rejectedMarks' => [$this->mark('R1')]]);
        $this->posting([910003 => 3]);
        $this->setExemplars([910003 => ['010460000000001721ABC\u001d91EE10', $this->mark('D1'), $this->mark('D1')]]);
        $this->clock->timestamp += 3;

        $marks = array_map(static fn (array $e): array => $e['marks'][0]['error_codes'], $this->exemplarStatus()['products'][0]['exemplars']);
        self::assertSame([['INVALID_MARK_FORMAT'], ['DUPLICATE_MARK'], ['DUPLICATE_MARK']], $marks);
        self::assertSame('ship_not_available', $this->exemplarStatus()['status']);
        $this->expectFailure(400, fn (): array => $this->call('/v4/posting/fbs/ship', ['posting_number' => self::NUMBER, 'packages' => [['products' => [['product_id' => 910003, 'quantity' => 3]]]]]));

        $this->setExemplars([910003 => [$this->mark('R1'), $this->mark('D2'), $this->mark('D3')]]);
        $this->clock->timestamp += 3;
        self::assertSame(['NOT_IN_TURNOVER'], $this->exemplarStatus()['products'][0]['exemplars'][0]['marks'][0]['error_codes']);
        $this->expectFailure(400, fn (): array => $this->setExemplars([910003 => [$this->mark('X1'), $this->mark('X2')]]));
    }

    /** Страна-изготовитель обязательна до сборки; страна не РФ добавляет требование ГТД.
     * @see FbsExemplarService::handle()
     */
    #[Test]
    public function requiresCountryBeforeAssembly(): void
    {
        $this->posting([910002 => 1]);
        self::assertSame(['910002'], $this->get()['requirements']['products_requiring_country']);
        self::assertSame([['name' => 'Китай', 'country_iso_code' => 'CN']], $this->call('/v2/posting/fbs/product/country/list', ['name_search' => 'кит'])['result']);
        $this->expectFailure(400, fn (): array => $this->ship([910002 => 1]));

        $result = $this->call('/v2/posting/fbs/product/country/set', ['posting_number' => self::NUMBER, 'product_id' => 810002, 'country_iso_code' => 'CN']);

        self::assertSame(['product_id' => 810002, 'is_gtd_needed' => true], $result);
        self::assertSame([[], ['910002']], [$this->get()['requirements']['products_requiring_country'], $this->get()['requirements']['products_requiring_gtd']]);
        $this->expectFailure(400, fn (): array => $this->setExemplars([910002 => [[]]]));
        $this->setExemplars([910002 => [['gtd' => '10702070/010126/0000001']]]);
        $this->clock->timestamp += 3;
        self::assertSame([self::NUMBER], $this->ship([910002 => 1])['result']);
    }

    /** Частичная сборка отделяет переданные экземпляры в новое собранное отправление, остаток остаётся несобранным.
     * @see FbsShipService::handle()
     */
    #[Test]
    public function shipsPartOfThePosting(): void
    {
        $this->posting([910003 => 2, 910001 => 1]);
        $this->setExemplars([910003 => [$this->mark('P1'), $this->mark('P2')], 910001 => [[]]]);
        $this->clock->timestamp += 3;
        $second = $this->exemplarStatus()['products'][0]['exemplars'][1]['exemplar_id'];

        $result = $this->call('/v4/posting/fbs/ship/package', ['posting_number' => self::NUMBER, 'products' => [['product_id' => 910003, 'quantity' => 1, 'exemplarsIds' => [(string) $second]]]]);

        $part = $this->get($result['result'], ['product_exemplars' => true]);
        $rest = $this->get(self::NUMBER, ['product_exemplars' => true]);
        self::assertSame('10000001-0001-2', $result['result']);
        self::assertSame(['awaiting_deliver', [[$this->mark('P2')]]], [$part['status'], array_column($part['products'], 'mandatory_mark')]);
        self::assertSame('awaiting_packaging', $rest['status']);
        self::assertSame([[910003, 1], [910001, 1]], array_map(static fn (array $p): array => [$p['sku'], $p['quantity']], $rest['products']));
        self::assertSame($this->mark('P1'), $rest['product_exemplars']['products'][0]['exemplars'][0]['mandatory_mark']);
    }

    /** Разделение без сборки: исходное отправление отменено из-за разделения, части — новые несобранные отправления.
     * @see FbsShipService::handle()
     */
    #[Test]
    public function splitsPostingWithoutAssembly(): void
    {
        $this->posting([910003 => 2, 910001 => 1]);
        $this->setExemplars([910003 => [$this->mark('S1'), $this->mark('S2')], 910001 => [[]]]);
        $this->clock->timestamp += 3;

        $result = $this->call('/v1/posting/fbs/split', ['posting_number' => self::NUMBER, 'postings' => [
            ['products' => [['product_id' => 910003, 'quantity' => 1]]],
            ['products' => [['product_id' => 910003, 'quantity' => 1], ['product_id' => 910001, 'quantity' => 1]]],
        ]]);

        self::assertSame(self::NUMBER, $result['parent_posting']['posting_number']);
        self::assertSame(['10000001-0001-2', '10000001-0001-3'], array_column($result['postings'], 'posting_number'));
        self::assertSame([['product_id' => 910003, 'quantity' => 1], ['product_id' => 910001, 'quantity' => 1]], $result['postings'][1]['products']);
        $parent = $this->get();
        self::assertSame(['cancelled_from_split_pending', []], [$parent['status'], $parent['available_actions']]);
        $first  = $this->get('10000001-0001-2', ['product_exemplars' => true]);
        $second = $this->get('10000001-0001-3', ['product_exemplars' => true]);
        self::assertSame(['awaiting_packaging', self::NUMBER], [$first['status'], $first['parent_posting_number']]);
        self::assertSame($this->mark('S1'), $first['product_exemplars']['products'][0]['exemplars'][0]['mandatory_mark']);
        self::assertSame($this->mark('S2'), $second['product_exemplars']['products'][0]['exemplars'][0]['mandatory_mark']);
        $unfulfilled = array_column($this->call('/v4/posting/fbs/unfulfilled/list', ['limit' => 100])['postings'], 'posting_number');
        self::assertNotContains(self::NUMBER, $unfulfilled);
        self::assertContains('10000001-0001-3', $unfulfilled);
    }

    /** Разделение требует двух и более непустых частей ровно из товаров отправления.
     * @see FbsShipService::handle()
     */
    #[Test]
    public function rejectsIncompleteSplit(): void
    {
        $this->posting([910001 => 2]);
        $this->expectFailure(400, fn (): array => $this->call('/v1/posting/fbs/split', ['posting_number' => self::NUMBER, 'postings' => [['products' => [['product_id' => 910001, 'quantity' => 2]]]]]));
        $this->expectFailure(400, fn (): array => $this->call('/v1/posting/fbs/split', ['posting_number' => self::NUMBER, 'postings' => [
            ['products' => [['product_id' => 910001, 'quantity' => 1]]], ['products' => [['product_id' => 910001, 'quantity' => 2]]],
        ]]));
        self::assertSame('awaiting_packaging', $this->get()['status']);
    }

    /** Многокоробочный товар: признак до сборки, количество коробок — в наборе экземпляров, после сборки признак снят.
     * @see FbsShipService::handle()
     * @see FbsExemplarService::handle()
     */
    #[Test]
    public function keepsBoxCountOfMultiboxPosting(): void
    {
        $this->configuration['fbs']['multiboxSkus'] = [910001];
        $this->configure();
        $this->posting([910001 => 1]);
        self::assertSame([true, 1], [$this->get()['is_multibox'], $this->get()['multi_box_qty']]);

        $known = $this->call('/v6/fbs/posting/product/exemplar/create-or-get', ['posting_number' => self::NUMBER])['products'][0]['exemplars'][0]['exemplar_id'];
        $this->call('/v6/fbs/posting/product/exemplar/set', ['posting_number' => self::NUMBER, 'multi_box_qty' => 3, 'products' => [['product_id' => 910001, 'exemplars' => [['exemplar_id' => $known]]]]]);
        $this->clock->timestamp += 3;
        $this->ship([910001 => 1]);

        self::assertSame([false, 3, 'awaiting_deliver'], [$this->get()['is_multibox'], $this->get()['multi_box_qty'], $this->get()['status']]);
    }

    /** `ship_failed` сценария: запрос принят, отправление не собрано и видно в get; повтор собирает.
     * @see FbsShipService::handle()
     */
    #[Test]
    public function reportsFailedAssemblyThroughGet(): void
    {
        $this->control(['type' => 'fbsScenario', 'shipFailures' => 1]);
        $this->posting([910001 => 1]);

        self::assertSame([self::NUMBER], $this->ship([910001 => 1])['result']);
        self::assertSame(['awaiting_packaging', 'ship_failed'], [$this->get()['status'], $this->get()['substatus']]);
        $this->ship([910001 => 1]);
        self::assertSame('awaiting_deliver', $this->get()['status']);
        $this->expectFailure(400, fn (): array => $this->ship([910001 => 1]));
    }

    /** После сборки новые данные проверяются, но заменяют подтверждённые только через update.
     * @see FbsExemplarService::handle()
     */
    #[Test]
    public function updatesExemplarsAfterAssemblyOnlyOnUpdate(): void
    {
        $this->posting([910003 => 1]);
        $this->setExemplars([910003 => [$this->mark('U1')]]);
        $this->clock->timestamp += 3;
        $this->ship([910003 => 1]);
        $this->expectFailure(400, fn (): array => $this->call('/v1/fbs/posting/product/exemplar/update', ['posting_number' => self::NUMBER]));

        $this->setExemplars([910003 => [$this->mark('U2')]]);
        $this->clock->timestamp += 3;

        self::assertSame('update_available', $this->exemplarStatus()['status']);
        self::assertSame([[$this->mark('U1')]], array_column($this->get()['products'], 'mandatory_mark'));
        self::assertSame([], $this->call('/v1/fbs/posting/product/exemplar/update', ['posting_number' => self::NUMBER]));
        self::assertSame([[$this->mark('U2')]], array_column($this->get()['products'], 'mandatory_mark'));
    }

    /** Текущие методы этикеток: v3 create по `posting_numbers` без обёртки `result`, v2 get — `status.code`, `file_url`,
     * `error{code, message}` и `status.unprinted_postings[].message`.
     * @see FbsLabelService::handle()
     */
    #[Test]
    public function generatesLabelsThroughTheCurrentMethods(): void
    {
        $this->posting([910001 => 1]);
        $this->posting([910001 => 1], '10000002-0001-1');
        $this->ship([910001 => 1]);
        $tasks = $this->call('/v3/posting/fbs/package-label/create', ['posting_numbers' => [self::NUMBER, '10000002-0001-1']])['tasks'];

        self::assertSame(['big_label', 'small_label'], array_column($tasks, 'task_type'));
        self::assertSame('pending', $this->call('/v2/posting/fbs/package-label/get', ['task_id' => $tasks[0]['task_id']])['status']['code']);
        $this->clock->timestamp += 3;
        $label = $this->call('/v2/posting/fbs/package-label/get', ['task_id' => $tasks[0]['task_id']]);
        self::assertSame(['completed', 2, 1], [$label['status']['code'], $label['status']['postings_count'], $label['status']['printed_postings_count']]);
        self::assertSame([['posting_number' => '10000002-0001-1', 'message' => 'The next postings aren\'t ready']], $label['status']['unprinted_postings']);
        self::assertSame('', $label['error']['code']);
        self::assertStringContainsString('/documents/label?', $label['file_url']);

        $this->control(['type' => 'fbsScenario', 'labelFailures' => 1]);
        $failed = $this->call('/v3/posting/fbs/package-label/create', ['posting_numbers' => [self::NUMBER]])['tasks'][0]['task_id'];
        $this->clock->timestamp += 3;
        $error = $this->call('/v2/posting/fbs/package-label/get', ['task_id' => $failed]);
        self::assertSame(['error', 'LABEL_GENERATION_FAILED', ''], [$error['status']['code'], $error['error']['code'], $error['file_url']]);
    }

    /** Задачи этикеток: pending → completed с файлом; несобранное отправление — в unprinted; повторный get отдаёт тот же файл.
     * @see FbsLabelService::handle()
     * @see LabelService::download()
     */
    #[Test]
    public function generatesLabelsForAssembledPostings(): void
    {
        $this->posting([910001 => 1]);
        $this->posting([910001 => 1], '10000002-0001-1');
        $this->ship([910001 => 1]);
        $tasks = $this->call('/v2/posting/fbs/package-label/create', ['posting_number' => [self::NUMBER, '10000002-0001-1']])['result']['tasks'];

        self::assertSame(['big_label', 'small_label'], array_column($tasks, 'task_type'));
        self::assertSame('pending', $this->call('/v1/posting/fbs/package-label/get', ['task_id' => $tasks[0]['task_id']])['result']['status']);
        $this->clock->timestamp += 3;
        $label = $this->call('/v1/posting/fbs/package-label/get', ['task_id' => $tasks[0]['task_id']])['result'];
        self::assertSame(['completed', 1, 1], [$label['status'], $label['printed_postings_count'], $label['unprinted_postings_count']]);
        self::assertSame('10000002-0001-1', $label['unprinted_postings'][0]['posting_number']);
        self::assertSame($label, $this->call('/v1/posting/fbs/package-label/get', ['task_id' => $tasks[0]['task_id']])['result']);
        parse_str((string) parse_url($label['file_url'], PHP_URL_QUERY), $query);
        self::assertTrue(str_starts_with($this->container->get(LabelService::class)->download($query['client_id'], $query['document_id'], $query['token']), '%PDF'));

        $this->control(['type' => 'fbsScenario', 'labelFailures' => 1]);
        $failed = $this->call('/v2/posting/fbs/package-label/create', ['posting_number' => [self::NUMBER]])['result']['tasks'][0]['task_id'];
        $this->clock->timestamp += 3;
        self::assertSame(['error', 'LABEL_GENERATION_FAILED', ''], array_map(fn (string $key): string => $this->call('/v1/posting/fbs/package-label/get', ['task_id' => $failed])['result'][$key], ['status', 'error', 'file_url']));
    }

    /** Ограничения пункта приёма по отправлению: вес, габариты и стоимость; неизвестное отправление — 404.
     * @see FbsReadService::read()
     */
    #[Test]
    public function returnsTheLimitsOfTheDropOffPoint(): void
    {
        $this->posting([910001 => 1]);

        $limits = $this->call('/v1/posting/fbs/restrictions', ['posting_number' => self::NUMBER])['result'];

        self::assertSame([self::NUMBER, 25000.0, 300000.0], [$limits['posting_number'], $limits['max_posting_weight'], $limits['max_posting_price']]);
        $this->expectFailure(404, fn (): array => $this->call('/v1/posting/fbs/restrictions', ['posting_number' => '99999999-0001-1']));
    }

    /** Продавец меняет КИЗ экземпляра собранного отправления в кабинете: Ozon хранит новый КИЗ; до сборки так нельзя.
     * @see ControlCabinetHandler::handle()
     */
    #[Test]
    public function changesMarkOfAssembledPostingByControlEvent(): void
    {
        $this->posting([910003 => 1]);
        $this->setExemplars([910003 => [$this->mark('C1')]]);
        $exemplarId = $this->call('/v6/fbs/posting/product/exemplar/create-or-get', ['posting_number' => self::NUMBER])['products'][0]['exemplars'][0]['exemplar_id'];
        $this->expectFailure(409, fn (): array => $this->control(['type' => 'fbsMarkChange', 'postingNumber' => self::NUMBER, 'exemplarId' => $exemplarId, 'mark' => $this->mark('C2')]));
        $this->clock->timestamp += 3;
        $this->ship([910003 => 1]);

        $this->control(['type' => 'fbsMarkChange', 'postingNumber' => self::NUMBER, 'exemplarId' => $exemplarId, 'mark' => $this->mark('C2')]);

        $posting = $this->get(self::NUMBER, ['product_exemplars' => true]);
        self::assertSame($this->mark('C2'), $posting['product_exemplars']['products'][0]['exemplars'][0]['mandatory_mark']);
        self::assertSame([[$this->mark('C2')]], array_column($posting['products'], 'mandatory_mark'));
        $this->expectFailure(404, fn (): array => $this->control(['type' => 'fbsMarkChange', 'postingNumber' => self::NUMBER, 'exemplarId' => 1, 'mark' => $this->mark('C3')]));
    }

    /** Изменение требований управляющим событием меняет requirements и действия несобранного отправления.
     * @see FbsRequirements::of()
     */
    #[Test]
    public function changesRequirementsByControlEvent(): void
    {
        $this->posting([910001 => 1]);
        self::assertSame('ship', $this->get()['available_actions'][0]);

        $this->control(['type' => 'fbsRequirements', 'postingNumber' => self::NUMBER, 'requirements' => ['possible_mark' => [910001]]]);

        self::assertSame(['910001'], $this->get()['optional']['products_with_possible_mandatory_mark']);
        $this->setExemplars([910001 => [$this->mark('O1')]]);
        $this->clock->timestamp += 3;
        self::assertSame('ship_available', $this->exemplarStatus()['status']);
    }

    /** @param array<int, int> $lines quantity by SKU */
    private function posting(array $lines, string $number = self::NUMBER): void
    {
        $this->repository->change('1001', function (CabinetState $state) use ($lines, $number): void {
            $catalog  = array_column($state->config()['products'], null, 'sku');
            $products = [];
            foreach ($lines as $sku => $quantity) {
                $products[] = ['sku' => $sku, 'offer_id' => $catalog[$sku]['offerId'], 'name' => $catalog[$sku]['name'], 'quantity' => $quantity, 'price' => '500.00'];
            }
            [$order] = explode('-', $number);
            $posting = [
                'posting_number' => $number, 'order_id' => (int) $order, 'order_number' => substr($number, 0, -2), 'status' => 'awaiting_packaging',
                'substatus'      => 'posting_created', 'warehouse_id' => 1020001, 'delivery_method_id' => 1030001, 'in_process_at' => $this->clock->timestamp,
                'shipment_date'  => $this->clock->timestamp + 86400, 'products' => $products,
                'requirements'   => FbsRequirements::fromConfig(FbsConfig::of($state), array_keys($lines)),
            ];
            $posting['multibox_skus']                = array_values(array_intersect(array_keys($lines), FbsConfig::of($state)['multiboxSkus'] ?? []));
            $posting['multibox']                     = $posting['multibox_skus'] !== [];
            $posting['available_actions']            = FbsPostingGenerator::actions($posting);
            $state->data['fbs']['postings'][$number] = $posting;
        });
    }

    /**
     * Full set: exemplar ids from create-or-get, a string is a mandatory mark, an array — exemplar fields.
     *
     * @param array<int, list<string|array>> $exemplars
     */
    private function setExemplars(array $exemplars): array
    {
        $known    = array_column($this->call('/v6/fbs/posting/product/exemplar/create-or-get', ['posting_number' => self::NUMBER])['products'], null, 'product_id');
        $products = [];
        foreach ($exemplars as $sku => $rows) {
            $items = [];
            foreach ($rows as $i => $row) {
                $items[] = ['exemplar_id' => $known[$sku]['exemplars'][$i]['exemplar_id'] ?? 999]
                    + (is_string($row) ? ['marks' => [['mark' => $row, 'mark_type' => 'mandatory_mark']]] : $row);
            }
            $products[] = ['product_id' => $sku, 'exemplars' => $items];
        }

        return $this->call('/v6/fbs/posting/product/exemplar/set', ['posting_number' => self::NUMBER, 'products' => $products]);
    }

    /** @param array<int, int> $lines */
    private function ship(array $lines): array
    {
        return $this->call('/v4/posting/fbs/ship', ['posting_number' => self::NUMBER, 'packages' => [['products' => array_map(
            static fn (int $sku, int $quantity): array => ['product_id' => $sku, 'quantity' => $quantity],
            array_keys($lines),
            $lines,
        )]]]);
    }

    private function exemplarStatus(): array
    {
        return $this->call('/v5/fbs/posting/product/exemplar/status', ['posting_number' => self::NUMBER]);
    }

    private function get(string $number = self::NUMBER, array $with = []): array
    {
        return $this->call('/v3/posting/fbs/get', ['posting_number' => $number] + ($with === [] ? [] : ['with' => $with]))['result'];
    }

    /** «Честный ЗНАК» with a crypto tail after the GS character. */
    private function mark(string $serial): string
    {
        return '0104600000000017' . '21' . $serial . "\x1D" . '91EE10' . "\x1D" . '92' . str_repeat('A', 44);
    }

    private function expectFailure(int $status, callable $call): void
    {
        try {
            $call();
            self::fail('Запрос должен быть отклонён.');
        } catch (SellerApiException $exception) {
            self::assertSame($status, $exception->status);
        }
    }
}
