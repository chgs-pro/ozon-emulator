<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Feature\Fbo\CabinetState;
use App\Feature\Fbo\SellerApiException;
use App\Feature\Fbs\FbsPostingGenerator;
use App\Feature\Fbs\FbsReadService;
use App\Tests\Support\FboTestCase;
use PHPUnit\Framework\Attributes\{CoversClass, CoversMethod, Test};
use Random\Engine\Mt19937;
use Random\Randomizer;

use function array_column;
use function array_merge;
use function array_sum;
use function array_unique;
use function count;
use function dirname;
use function file_get_contents;
use function gmdate;
use function in_array;
use function json_decode;

use const DATE_ATOM;
use const JSON_THROW_ON_ERROR;

#[CoversClass(FbsPostingGenerator::class)]
#[CoversClass(FbsReadService::class)]
#[CoversMethod(FbsPostingGenerator::class, 'generate')]
#[CoversMethod(FbsReadService::class, 'read')]
final class FbsPostingsTest extends FboTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->configuration = json_decode(file_get_contents(dirname(__DIR__, 2) . '/fixtures/fbs-basic.json'), true, flags: JSON_THROW_ON_ERROR);
        $this->configure();
        $this->publish();
    }

    /** Сгенерированные отправления видны в unfulfilled/list v4 с курсором; ответы соответствуют контракту Seller API.
     * @see FbsPostingGenerator::generate()
     * @see FbsReadService::read()
     */
    #[Test]
    public function generatedPostingsArePagedByCursor(): void
    {
        $created = $this->generate(5);

        $first  = $this->call('/v4/posting/fbs/unfulfilled/list', ['limit' => 3]);
        $second = $this->call('/v4/posting/fbs/unfulfilled/list', ['limit' => 3, 'cursor' => $first['cursor']]);

        self::assertSame(5, $first['count']);
        self::assertTrue($first['has_next']);
        self::assertFalse($second['has_next']);
        self::assertSame('', $second['cursor']);
        $numbers = array_merge(array_column($first['postings'], 'posting_number'), array_column($second['postings'], 'posting_number'));
        self::assertEqualsCanonicalizing($created, $numbers);
        self::assertCount(5, array_unique($numbers));
    }

    /** Отправление содержит 1–3 строки из ассортимента FBS, склад и метод доставки кабинета; маркированный товар попадает в requirements.
     * @see FbsPostingGenerator::generate()
     */
    #[Test]
    public function postingsUseTheConfiguredFbsAssortmentAndDeliveryMethods(): void
    {
        $this->generate(30);
        $methods = [];
        foreach ($this->configuration['fbs']['warehouses'] as $warehouse) {
            foreach ($warehouse['deliveryMethods'] as $method) {
                $methods[$method['id']] = $warehouse['id'];
            }
        }
        $marked = false;
        foreach ($this->all() as $posting) {
            self::assertSame('awaiting_packaging', $posting['status']);
            self::assertGreaterThanOrEqual(1, count($posting['products']));
            self::assertLessThanOrEqual(3, count($posting['products']));
            self::assertSame($methods[$posting['delivery_method']['id']], $posting['delivery_method']['warehouse_id']);
            foreach ($posting['products'] as $line) {
                self::assertContains($line['sku'], $this->configuration['fbs']['productSkus']);
            }
            $hasMarked = in_array(910003, array_column($posting['products'], 'sku'), true);
            self::assertSame($hasMarked ? ['910003'] : [], $posting['requirements']['products_requiring_mandatory_mark']);
            $manyUnits = array_sum(array_column($posting['products'], 'quantity')) > 1;
            self::assertSame([$hasMarked ? 'ship_with_additional_info' : 'ship', 'cancel', ...($manyUnits ? ['product_cancel'] : [])], $posting['available_actions']);
            $marked = $marked || $hasMarked;
        }
        self::assertTrue($marked, 'The seeded run contains a posting with a marked product');
    }

    /** Фильтры по складу и методу доставки, list v4 по периоду и get v3 с ценой строкой.
     * @see FbsReadService::read()
     */
    #[Test]
    public function filtersPostingsAndReadsOneByNumber(): void
    {
        $this->generate(10);
        $kazan = $this->call('/v4/posting/fbs/unfulfilled/list', ['limit' => 100, 'filter' => ['warehouse_ids' => ['1020002']]])['postings'];
        foreach ($kazan as $posting) {
            self::assertSame(1020002, $posting['delivery_method']['warehouse_id']);
        }
        $period = $this->call('/v4/posting/fbs/list', ['limit' => 100, 'filter' => ['since' => gmdate(DATE_ATOM, $this->clock->timestamp - 60), 'to' => gmdate(DATE_ATOM, $this->clock->timestamp + 60)]]);
        self::assertCount(10, $period['postings']);

        $posting = $period['postings'][0];
        $one     = $this->call('/v3/posting/fbs/get', ['posting_number' => $posting['posting_number']])['result'];
        self::assertSame($posting['order_number'], $one['order_number']);
        self::assertSame($posting['products'][0]['price']['amount'], $one['products'][0]['price']);
        self::assertSame('RUB', $one['products'][0]['currency_code']);
    }

    /** Неизвестное отправление и выключенный FBS дают ошибки Seller API, а не пустой ответ.
     * @see FbsReadService::read()
     */
    #[Test]
    public function rejectsUnknownPostingAndDisabledFbs(): void
    {
        try {
            $this->call('/v3/posting/fbs/get', ['posting_number' => '00000000-0000-1']);
            self::fail('Unknown posting must be rejected');
        } catch (SellerApiException $error) {
            self::assertSame(404, $error->status);
        }
        $this->configuration['fbs']['enabled'] = false;
        $this->configure();
        try {
            $this->call('/v4/posting/fbs/unfulfilled/list', ['limit' => 10]);
            self::fail('FBS methods require the FBS section');
        } catch (SellerApiException $error) {
            self::assertSame(403, $error->status);
        }
        self::assertNotContains('/v4/posting/fbs/unfulfilled/list', $this->call('/v1/roles')['roles'][0]['methods']);
    }

    /** Publishes free stock for the whole FBS assortment in both configured warehouses: postings are created only from it. */
    private function publish(): void
    {
        $stocks = [];
        foreach ($this->configuration['fbs']['warehouses'] as $warehouse) {
            foreach ($this->configuration['fbs']['productSkus'] as $sku) {
                $stocks[] = ['product_id' => $sku - 100000, 'warehouse_id' => $warehouse['id'], 'stock' => 100];
            }
        }
        self::assertNotContains(false, array_column($this->call('/v2/products/stocks', ['stocks' => $stocks])['result'], 'updated'));
    }

    /** @return list<string> */
    private function generate(int $count): array
    {
        $random = new Randomizer(new Mt19937(42));

        return $this->repository->change('1001', fn (CabinetState $state): array => $this->container->get(FbsPostingGenerator::class)->generate($state, $count, $this->clock->timestamp, $random));
    }

    private function all(): array
    {
        return $this->call('/v4/posting/fbs/unfulfilled/list', ['limit' => 100])['postings'];
    }
}
