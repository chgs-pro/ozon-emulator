<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Feature\Fbo\CabinetState;
use App\Feature\Fbo\SellerApiException;
use App\Feature\Fbs\FbsCancellationService;
use App\Feature\Fbs\FbsPostingGenerator;
use App\Tests\Support\FboTestCase;
use PHPUnit\Framework\Attributes\{CoversClass, CoversMethod, Test};
use Random\Engine\Mt19937;
use Random\Randomizer;

use function array_column;
use function array_sum;
use function dirname;
use function file_get_contents;
use function in_array;
use function json_decode;

use const JSON_THROW_ON_ERROR;

#[CoversClass(FbsCancellationService::class)]
#[CoversMethod(FbsCancellationService::class, 'handle')]
#[CoversMethod(FbsPostingGenerator::class, 'actions')]
final class FbsCancellationTest extends FboTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->configuration = json_decode(file_get_contents(dirname(__DIR__, 2) . '/fixtures/fbs-basic.json'), true, flags: JSON_THROW_ON_ERROR);
        $this->configure();
        $stocks = [];
        foreach ($this->configuration['fbs']['warehouses'] as $warehouse) {
            foreach ($this->configuration['fbs']['productSkus'] as $sku) {
                $stocks[] = ['product_id' => $sku - 100000, 'warehouse_id' => $warehouse['id'], 'stock' => 100];
            }
        }
        $this->call('/v2/products/stocks', ['stocks' => $stocks]);
        $random = new Randomizer(new Mt19937(42));

        $this->repository->change('1001', fn (CabinetState $state): array => $this->container->get(FbsPostingGenerator::class)->generate($state, 10, $this->clock->timestamp, $random));
    }

    /** Причины для отправления — только причины продавца; общий список содержит признак доступности.
     * @see FbsCancellationService::handle()
     */
    #[Test]
    public function returnsSellerReasons(): void
    {
        $posting = $this->postings()[0];

        $reasons = $this->call('/v1/posting/fbs/cancel-reason', ['related_posting_numbers' => [$posting['posting_number']]])['result'];
        $list    = $this->call('/v2/posting/fbs/cancel-reason/list')['result'];

        self::assertSame($posting['posting_number'], $reasons[0]['posting_number']);
        self::assertSame([352, 400, 402], array_column($reasons[0]['reasons'], 'id'));
        self::assertContains(665, array_column($list, 'id'));
    }

    /** Отмена целиком: posting уходит из незавершённых, get отдаёт инициатора и причину, единицы возвращаются в свободный остаток.
     * @see FbsCancellationService::handle()
     */
    #[Test]
    public function cancelsTheWholePostingAndReleasesStock(): void
    {
        $posting = $this->postings()[0];
        $line    = $posting['products'][0];
        $before  = $this->freeStock($line['sku'], $posting['delivery_method']['warehouse_id']);

        self::assertTrue($this->call('/v2/posting/fbs/cancel', ['posting_number' => $posting['posting_number'], 'cancel_reason_id' => 352])['result']);

        $got = $this->call('/v3/posting/fbs/get', ['posting_number' => $posting['posting_number']])['result'];
        self::assertSame(['cancelled', 'posting_canceled', []], [$got['status'], $got['substatus'], $got['available_actions']]);
        self::assertSame(['seller', 352, false], [$got['cancellation']['cancellation_type'], $got['cancellation']['cancel_reason_id'], $got['cancellation']['cancelled_after_ship']]);
        self::assertNotContains($posting['posting_number'], array_column($this->postings(), 'posting_number'));
        self::assertSame($before + $line['quantity'], $this->freeStock($line['sku'], $posting['delivery_method']['warehouse_id']));
    }

    /** Частичная отмена уменьшает количество строки; сообщение обязательно; отмена всех единиц отменяет posting.
     * @see FbsCancellationService::handle()
     */
    #[Test]
    public function cancelsProductsOfThePosting(): void
    {
        $posting = null;
        foreach ($this->postings() as $candidate) {
            if (in_array('product_cancel', $candidate['available_actions'], true)) {
                $posting = $candidate;
                break;
            }
        }
        self::assertNotNull($posting, 'Генератор создаёт отправления больше чем с одной единицей.');
        $line  = $posting['products'][0];
        $total = array_sum(array_column($posting['products'], 'quantity'));
        try {
            $this->call('/v2/posting/fbs/product/cancel', ['posting_number' => $posting['posting_number'], 'cancel_reason_id' => 402, 'cancel_reason_message' => '', 'items' => [['sku' => $line['sku'], 'quantity' => 1]]]);
            self::fail('Без сообщения частичная отмена не проходит.');
        } catch (SellerApiException $exception) {
            self::assertSame(400, $exception->status);
        }

        $result = $this->call('/v2/posting/fbs/product/cancel', ['posting_number' => $posting['posting_number'], 'cancel_reason_id' => 402, 'cancel_reason_message' => 'Брак', 'items' => [['sku' => $line['sku'], 'quantity' => 1]]]);

        self::assertSame($posting['posting_number'], $result['result']);
        $got = $this->call('/v3/posting/fbs/get', ['posting_number' => $posting['posting_number']])['result'];
        self::assertSame('awaiting_packaging', $got['status']);
        self::assertSame($total - 1, array_sum(array_column($got['products'], 'quantity')));

        $items = [];
        foreach ($got['products'] as $rest) {
            $items[] = ['sku' => $rest['sku'], 'quantity' => $rest['quantity']];
        }
        if (in_array('product_cancel', $got['available_actions'], true)) {
            $this->call('/v2/posting/fbs/product/cancel', ['posting_number' => $posting['posting_number'], 'cancel_reason_id' => 402, 'cancel_reason_message' => 'Брак', 'items' => $items]);
            self::assertSame('cancelled', $this->call('/v3/posting/fbs/get', ['posting_number' => $posting['posting_number']])['result']['status']);
        }
    }

    /** Недоступная причина, 402 без сообщения и повторная отмена отклоняются.
     * @see FbsCancellationService::handle()
     */
    #[Test]
    public function rejectsInvalidCancellations(): void
    {
        $number = $this->postings()[0]['posting_number'];
        foreach ([['cancel_reason_id' => 665], ['cancel_reason_id' => 402]] as $input) {
            try {
                $this->call('/v2/posting/fbs/cancel', ['posting_number' => $number] + $input);
                self::fail('Отмена должна быть отклонена.');
            } catch (SellerApiException $exception) {
                self::assertSame(400, $exception->status);
            }
        }
        $this->call('/v2/posting/fbs/cancel', ['posting_number' => $number, 'cancel_reason_id' => 352]);

        $this->expectException(SellerApiException::class);
        $this->call('/v2/posting/fbs/cancel', ['posting_number' => $number, 'cancel_reason_id' => 352]);
    }

    private function postings(): array
    {
        return $this->call('/v4/posting/fbs/unfulfilled/list', ['limit' => 100])['postings'];
    }

    private function freeStock(int $sku, int $warehouseId): int
    {
        foreach ($this->call('/v2/product/info/stocks-by-warehouse/fbs', ['sku' => [(string) $sku], 'limit' => 100])['products'] as $row) {
            if ((int) $row['warehouse_id'] === $warehouseId) {
                return (int) $row['free_stock'];
            }
        }

        return 0;
    }
}
