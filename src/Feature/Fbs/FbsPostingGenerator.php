<?php

declare(strict_types=1);

namespace App\Feature\Fbs;

use App\Feature\Fbo\CabinetState;
use App\Feature\Fbo\SellerApiException;
use Random\Randomizer;

use function array_column;
use function array_filter;
use function array_slice;
use function array_sum;
use function array_values;
use function count;
use function min;
use function sprintf;
use function str_replace;

/**
 * Creates random unfulfilled FBS postings in a test cabinet from published free stock only: a random `created` FBS warehouse
 * that has stock, 1–3 of its stocked products of the FBS assortment, quantity 1–3 but not above the free stock, and a random
 * delivery method of the warehouse. The ordered quantity moves from free stock to reserved. Postings stay in the cabinet state.
 */
final readonly class FbsPostingGenerator
{
    public const int SHIPMENT_DELAY_SECONDS = 86400;

    public function __construct(
        private FbsWarehouseService $warehouses,
    ) {
    }

    /**
     * Actions by status. An unassembled posting is shipped with additional info when some product needs metadata; a product
     * cancellation needs more than one unit — cancelling the only unit is cancelling the posting (an emulator choice, the
     * snapshot does not say when Ozon offers `product_cancel`). An assembled posting offers its labels and, with marked
     * products, the mark update.
     *
     * @return list<string>
     */
    public static function actions(array $posting): array
    {
        $requirements = FbsRequirements::of($posting);

        return match ($posting['status']) {
            'awaiting_packaging' => [
                FbsRequirements::needsExemplars($posting) || $requirements['country'] !== [] ? 'ship_with_additional_info' : 'ship', 'cancel',
                ...(array_sum(array_column($posting['products'], 'quantity')) > 1 ? ['product_cancel'] : []),
            ],
            'awaiting_deliver' => ['label_download_big', 'label_download_small', ...($requirements['mandatory_mark'] !== [] ? ['update_cis'] : [])],
            default            => [],
        };
    }

    /**
     * Barcodes of the posting label: the lower one is the digits of the posting number, the upper one adds the `%101%` prefix.
     * An emulator choice — the snapshot gives only the field names; `/v2/posting/fbs/get-by-barcode` finds a posting by either.
     *
     * @return array{lower_barcode: string, upper_barcode: string}
     */
    public static function barcodes(string $number): array
    {
        $lower = str_replace('-', '', $number);

        return ['lower_barcode' => $lower, 'upper_barcode' => '%101%' . $lower];
    }

    /** @return list<string> created posting numbers; fewer than `$count` (possibly none) when free stock runs out */
    public function generate(CabinetState $state, int $count, int $now, Randomizer $random): array
    {
        $fbs      = FbsConfig::of($state);
        $products = FbsConfig::products($state);
        if ($products === []) {
            throw new SellerApiException('FBS assortment is empty', 400, 3);
        }
        $this->warehouses->advance($state, $now);
        $created = [];
        for ($i = 0; $i < $count; ++$i) {
            $available = [];
            foreach (FbsConfig::warehouses($state) as $warehouse) {
                $stocked = array_values(array_filter($products, static fn (array $product): bool => FbsStockService::stock($state, $product['sku'], $warehouse['id']) > 0));
                if ($warehouse['status'] === 'created' && $stocked !== []) {
                    $available[] = [$warehouse, $stocked];
                }
            }
            if ($available === []) {
                break;
            }
            [$warehouse, $stocked] = $available[$random->getInt(0, count($available) - 1)];
            $method                = $warehouse['deliveryMethods'][$random->getInt(0, count($warehouse['deliveryMethods']) - 1)];
            $orderId               = $state->id();
            $number                = sprintf('%08d-%04d', $orderId % 100000000, $random->getInt(1, 9999));
            $lines                 = [];
            foreach (array_slice($random->shuffleArray($stocked), 0, $random->getInt(1, min(3, count($stocked)))) as $product) {
                $quantity = $random->getInt(1, min(3, FbsStockService::stock($state, $product['sku'], $warehouse['id'])));
                FbsStockService::reserve($state, $product['sku'], $warehouse['id'], $quantity);
                $lines[] = ['sku' => $product['sku'], 'offer_id' => $product['offerId'], 'name' => $product['name'], 'quantity' => $quantity,
                    'price'       => sprintf('%d.00', $random->getInt(100, 5000))];
            }
            $posting = [
                'posting_number' => $number . '-1', 'order_id' => $orderId, 'order_number' => $number,
                'status'         => 'awaiting_packaging', 'substatus' => 'posting_created',
                'warehouse_id'   => $warehouse['id'], 'delivery_method_id' => $method['id'],
                'in_process_at'  => $now, 'shipment_date' => $now + self::SHIPMENT_DELAY_SECONDS,
                'products'       => $lines, 'requirements' => FbsRequirements::fromConfig($fbs, array_column($lines, 'sku')),
            ];
            $posting['available_actions']                               = self::actions($posting);
            $state->data['fbs']['postings'][$posting['posting_number']] = $posting;
            $state->event('fbs.posting.created', $now, ['posting_number' => $posting['posting_number']]);
            $created[] = $posting['posting_number'];
        }

        return $created;
    }
}
