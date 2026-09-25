<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Feature\Fbo\CabinetState;
use App\Feature\Fbo\SellerApiException;
use App\Feature\Fbs\FbsPostingGenerator;
use App\Feature\Fbs\FbsStockService;
use App\Tests\Support\FboTestCase;
use PHPUnit\Framework\Attributes\{CoversClass, CoversMethod, Test};
use Random\Engine\Mt19937;
use Random\Randomizer;

use function array_column;
use function array_fill;
use function array_intersect_key;
use function array_map;
use function array_merge;
use function array_slice;
use function count;
use function dirname;
use function file_get_contents;
use function json_decode;
use function ksort;

use const JSON_THROW_ON_ERROR;

#[CoversClass(FbsStockService::class)]
#[CoversClass(FbsPostingGenerator::class)]
#[CoversMethod(FbsStockService::class, 'handle')]
#[CoversMethod(FbsPostingGenerator::class, 'generate')]
final class FbsStocksTest extends FboTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->configuration = json_decode(file_get_contents(dirname(__DIR__, 2) . '/fixtures/fbs-basic.json'), true, flags: JSON_THROW_ON_ERROR);
        $this->configure();
    }

    /** Остаток пары товар-склад обновляется не чаще раза в 30 секунд по часам кабинета: иначе TOO_MANY_REQUESTS в строке ответа.
     * @see FbsStockService::handle()
     */
    #[Test]
    public function updatesStockOncePerThirtySecondsPerPair(): void
    {
        $first = $this->stocks([['product_id' => 810001, 'warehouse_id' => 1020001, 'stock' => 10]])[0];
        self::assertSame(['product_id' => 810001, 'offer_id' => 'FBO-TEST-A', 'warehouse_id' => 1020001, 'updated' => true, 'errors' => []], $first);

        $this->clock->timestamp += 29;
        $again = $this->stocks([['product_id' => 810001, 'warehouse_id' => 1020001, 'stock' => 7], ['product_id' => 810001, 'warehouse_id' => 1020002, 'stock' => 3]]);
        self::assertFalse($again[0]['updated']);
        self::assertSame('TOO_MANY_REQUESTS', $again[0]['errors'][0]['code']);
        self::assertTrue($again[1]['updated'], 'Another warehouse is another pair');
        self::assertSame(10, $this->row(910001, 1020001)['free_stock']);

        $this->clock->timestamp += 1;
        self::assertTrue($this->stocks([['product_id' => 810001, 'warehouse_id' => 1020001, 'stock' => 7]])[0]['updated']);
        self::assertSame(7, $this->row(910001, 1020001)['free_stock']);
    }

    /** Ошибки отдельных строк не мешают остальным; offer_id имеет приоритет над product_id; больше 100 пар — 400.
     * @see FbsStockService::handle()
     */
    #[Test]
    public function reportsItemErrorsAndRejectsOversizedRequests(): void
    {
        $this->configuration['fbs']['warehouses'][1]['status'] = 'disabled';
        $this->configure();
        $result = $this->stocks([
            ['product_id' => 899999, 'warehouse_id' => 1020001, 'stock' => 1],
            ['offer_id' => 'NO-SUCH-OFFER', 'warehouse_id' => 1020001, 'stock' => 1],
            ['product_id' => 810001, 'warehouse_id' => 710001, 'stock' => 1],
            ['product_id' => 810001, 'warehouse_id' => 1029999, 'stock' => 1],
            ['product_id' => 810001, 'warehouse_id' => 1020002, 'stock' => 1],
            ['product_id' => 810006, 'warehouse_id' => 1020001, 'stock' => 1],
            ['product_id' => 810001, 'warehouse_id' => 1020001, 'stock' => -1],
            ['product_id' => 810001, 'offer_id' => 'FBO-TEST-B', 'warehouse_id' => 1020001, 'stock' => 4],
            ['offer_id' => 'FBO-TEST-C', 'warehouse_id' => '1020001', 'stock' => '6'],
        ]);
        self::assertSame(
            ['PRODUCT_NOT_FOUND', 'PRODUCT_NOT_FOUND', 'WAREHOUSE_NOT_FOUND', 'WAREHOUSE_NOT_FOUND', 'WAREHOUSE_NOT_ACTIVE', 'PRODUCT_NOT_IN_FBS_ASSORTMENT', 'INVALID_STOCK'],
            array_map(static fn (array $row): string => $row['errors'][0]['code'], array_slice($result, 0, 7)),
        );
        self::assertSame([false, false, false, false, false, false, false, true, true], array_column($result, 'updated'));
        self::assertSame([810002, 'FBO-TEST-B'], [$result[7]['product_id'], $result[7]['offer_id']], 'offer_id wins over product_id');
        self::assertSame(0, $this->row(910001, 1020001)['free_stock']);
        self::assertSame(4, $this->row(910002, 1020001)['free_stock']);
        self::assertSame(6, $this->row(910003, 1020001)['free_stock']);

        try {
            $this->stocks(array_fill(0, 101, ['product_id' => 810001, 'warehouse_id' => 1020001, 'stock' => 1]));
            self::fail('More than 100 pairs must be rejected');
        } catch (SellerApiException $error) {
            self::assertSame(400, $error->status);
        }
    }

    /** Чтение остатков — курсор по строкам товар × склад; present = stock + reserved, free_stock = stock; фильтр sku/offer_id обязателен.
     * @see FbsStockService::handle()
     */
    #[Test]
    public function readsStocksByCursorWithReservedQuantities(): void
    {
        $this->stocks([['product_id' => 810001, 'warehouse_id' => 1020001, 'stock' => 3]]);
        $this->generate(5);
        $reserved = $this->row(910001, 1020001);
        self::assertSame(['present' => 3, 'reserved' => 3, 'free_stock' => 0], ['present' => $reserved['present'], 'reserved' => $reserved['reserved'], 'free_stock' => $reserved['free_stock']]);
        self::assertSame('Тестовый склад FBS Москва', $reserved['warehouse_name']);

        $input = ['limit' => 4, 'sku' => ['910001', '910002', '910003'], 'offer_id' => ['FBO-TEST-D', 'FBO-TEST-J']];
        $pages = [];
        do {
            $page            = $this->call('/v2/product/info/stocks-by-warehouse/fbs', $input);
            $pages[]         = $page['products'];
            $input['cursor'] = $page['cursor'];
        } while ($page['has_next']);
        self::assertSame('', $page['cursor']);
        self::assertSame([4, 4], array_map('count', $pages), 'FBO-TEST-J is not in the FBS assortment: 4 products × 2 warehouses');
        self::assertSame([910001, 910001, 910002, 910002, 910003, 910003, 910004, 910004], array_column(array_merge(...$pages), 'sku'));

        try {
            $this->call('/v2/product/info/stocks-by-warehouse/fbs', ['limit' => 10]);
            self::fail('sku or offer_id filter is required');
        } catch (SellerApiException $error) {
            self::assertSame(400, $error->status);
        }
    }

    /** Генератор берёт только опубликованный свободный остаток, переводит его в резерв и ничего не создаёт без остатка;
     * повторная публикация перезаписывает свободный остаток (перебронирование не предотвращается).
     * @see FbsPostingGenerator::generate()
     */
    #[Test]
    public function generatorConsumesPublishedStockAndSkipsWithoutIt(): void
    {
        self::assertSame([], $this->generate(3), 'Nothing is published yet');

        $this->stocks([['product_id' => 810001, 'warehouse_id' => 1020001, 'stock' => 4], ['product_id' => 810002, 'warehouse_id' => 1020002, 'stock' => 1]]);
        $created  = $this->generate(20);
        $postings = $this->call('/v4/posting/fbs/unfulfilled/list', ['limit' => 100])['postings'];
        self::assertCount(count($created), $postings);
        $ordered = [];
        foreach ($postings as $posting) {
            foreach ($posting['products'] as $line) {
                $key           = $line['sku'] . ':' . $posting['delivery_method']['warehouse_id'];
                $ordered[$key] = ($ordered[$key] ?? 0) + $line['quantity'];
            }
        }
        ksort($ordered);
        self::assertSame(['910001:1020001' => 4, '910002:1020002' => 1], $ordered, 'Only stocked pairs are ordered, exactly up to the published quantity');
        self::assertSame(0, $this->row(910001, 1020001)['free_stock']);
        self::assertSame(4, $this->row(910001, 1020001)['reserved']);
        self::assertSame([], $this->generate(1), 'Free stock is exhausted');

        // A later scheduled publication overwrites free stock regardless of new reservations: real-world overbooking.
        $this->clock->timestamp += 30;
        $this->stocks([['product_id' => 810001, 'warehouse_id' => 1020001, 'stock' => 4]]);
        self::assertSame(['present' => 8, 'reserved' => 4, 'free_stock' => 4], array_intersect_key($this->row(910001, 1020001), ['present' => 0, 'reserved' => 0, 'free_stock' => 0]));
    }

    private function stocks(array $stocks): array
    {
        return $this->call('/v2/products/stocks', ['stocks' => $stocks])['result'];
    }

    private function row(int $sku, int $warehouseId): array
    {
        foreach ($this->call('/v2/product/info/stocks-by-warehouse/fbs', ['limit' => 100, 'sku' => [(string) $sku]])['products'] as $row) {
            if ($row['warehouse_id'] === $warehouseId) {
                return $row;
            }
        }
        self::fail('No stock row for ' . $sku . ' at ' . $warehouseId);
    }

    /** @return list<string> */
    private function generate(int $count): array
    {
        $random = new Randomizer(new Mt19937(42));

        return $this->repository->change('1001', fn (CabinetState $state): array => $this->container->get(FbsPostingGenerator::class)->generate($state, $count, $this->clock->timestamp, $random));
    }
}
