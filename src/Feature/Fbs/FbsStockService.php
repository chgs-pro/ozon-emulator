<?php

declare(strict_types=1);

namespace App\Feature\Fbs;

use App\Feature\Fbo\CabinetState;
use App\Feature\Fbo\SellerApiException;

use function array_column;
use function array_map;
use function array_slice;
use function count;
use function ctype_digit;
use function in_array;

/**
 * FBS stock per (product, warehouse). `stock` is the free quantity published by the seller through `/v2/products/stocks`
 * ("в наличии без учёта зарезервированных"); `reserved` is derived from postings that still hold goods.
 * `present = stock + reserved`, `free_stock = stock`. A pair that was never published has stock 0.
 */
final readonly class FbsStockService
{
    public const array PATHS = ['/v2/products/stocks', '/v2/product/info/stocks-by-warehouse/fbs'];

    /** Posting statuses in which the ordered quantity is reserved at the seller's warehouse. */
    public const array HOLDING = ['awaiting_registration', 'awaiting_packaging', 'awaiting_deliver'];

    /** One product-warehouse pair can be updated once per 30 seconds, like Ozon. */
    public const int UPDATE_INTERVAL_SECONDS = 30;

    private const int MAX_UPDATE_ITEMS = 100;

    public function supports(string $path): bool
    {
        return in_array($path, self::PATHS, true);
    }

    public function handle(CabinetState $state, string $path, array $input, int $now): array
    {
        return $path === '/v2/products/stocks' ? $this->update($state, $input['stocks'], $now) : $this->read($state, $input);
    }

    /** Free (published) quantity of the pair. */
    public static function stock(CabinetState $state, int $sku, int $warehouseId): int
    {
        return $state->data['fbs']['stocks'][self::key($sku, $warehouseId)]['stock'] ?? 0;
    }

    /** Moves the ordered quantity from free stock to reserved; the caller checked availability. */
    public static function reserve(CabinetState $state, int $sku, int $warehouseId, int $quantity): void
    {
        $key = self::key($sku, $warehouseId);
        $state->data['fbs']['stocks'][$key] ??= ['sku' => $sku, 'warehouse_id' => $warehouseId, 'stock' => 0, 'updated_at' => null];
        $state->data['fbs']['stocks'][$key]['stock'] -= $quantity;
    }

    /** Returns cancelled units from reserved to free stock. */
    public static function release(CabinetState $state, int $sku, int $warehouseId, int $quantity): void
    {
        $key = self::key($sku, $warehouseId);
        $state->data['fbs']['stocks'][$key] ??= ['sku' => $sku, 'warehouse_id' => $warehouseId, 'stock' => 0, 'updated_at' => null];
        $state->data['fbs']['stocks'][$key]['stock'] += $quantity;
    }

    /** @return array<string, int> reserved quantity by pair key */
    public static function reserved(CabinetState $state): array
    {
        $reserved = [];
        foreach ($state->data['fbs']['postings'] ?? [] as $posting) {
            if (!in_array($posting['status'], self::HOLDING, true)) {
                continue;
            }
            foreach ($posting['products'] as $line) {
                $key            = self::key($line['sku'], $posting['warehouse_id']);
                $reserved[$key] = ($reserved[$key] ?? 0) + $line['quantity'];
            }
        }

        return $reserved;
    }

    private function update(CabinetState $state, array $items, int $now): array
    {
        if (count($items) > self::MAX_UPDATE_ITEMS) {
            throw new SellerApiException('stocks: at most 100 product-warehouse pairs per request', 400, 3);
        }
        $catalog    = $state->config()['products'];
        $byOffer    = array_column($catalog, null, 'offerId');
        $byProduct  = array_column($catalog, null, 'productId');
        $assortment = array_column(FbsConfig::products($state), 'sku');
        $warehouses = array_column(FbsConfig::warehouses($state), null, 'id');
        $result     = [];
        foreach ($items as $item) {
            // Ozon: when both identifiers are present, the product with offer_id is updated.
            $offerId     = (string) ($item['offer_id'] ?? '');
            $product     = $offerId !== '' ? ($byOffer[$offerId] ?? null) : ($byProduct[(int) ($item['product_id'] ?? 0)] ?? null);
            $warehouseId = (int) $item['warehouse_id'];
            $stock       = (int) $item['stock'];
            $key         = $product === null ? '' : self::key($product['sku'], $warehouseId);
            $updatedAt   = $state->data['fbs']['stocks'][$key]['updated_at'] ?? null;
            $error       = match (true) {
                $product === null                                                        => ['PRODUCT_NOT_FOUND', 'Product not found'],
                !isset($warehouses[$warehouseId])                                        => ['WAREHOUSE_NOT_FOUND', 'Warehouse is not an FBS warehouse of this seller'],
                $warehouses[$warehouseId]['status'] !== 'created'                        => ['WAREHOUSE_NOT_ACTIVE', 'Warehouse is not in created status'],
                !in_array($product['sku'], $assortment, true)                            => ['PRODUCT_NOT_IN_FBS_ASSORTMENT', 'Product is not sold from FBS warehouses'],
                $stock < 0                                                               => ['INVALID_STOCK', 'Stock must not be negative'],
                $updatedAt !== null && $now - $updatedAt < self::UPDATE_INTERVAL_SECONDS => ['TOO_MANY_REQUESTS', 'Stock of this product-warehouse pair can be updated once per 30 seconds'],
                default                                                                  => null,
            };
            if ($error === null) {
                $state->data['fbs']['stocks'][$key] = ['sku' => $product['sku'], 'warehouse_id' => $warehouseId, 'stock' => $stock, 'updated_at' => $now];
                $state->event('fbs.stock.updated', $now, ['sku' => $product['sku'], 'warehouse_id' => $warehouseId, 'stock' => $stock]);
            }
            $result[] = [
                'product_id'   => $product['productId'] ?? (int) ($item['product_id'] ?? 0), 'offer_id' => $product['offerId'] ?? $offerId,
                'warehouse_id' => $warehouseId, 'updated' => $error === null,
                'errors'       => $error === null ? [] : [['code' => $error[0], 'message' => $error[1]]],
            ];
        }

        return ['result' => $result];
    }

    /** Rows for every requested FBS product × `created` FBS warehouse, in catalog order; cursor is the next offset. */
    private function read(CabinetState $state, array $input): array
    {
        $skus   = array_map('intval', $input['sku'] ?? []);
        $offers = array_map('strval', $input['offer_id'] ?? []);
        $limit  = $input['limit'];
        $cursor = (string) ($input['cursor'] ?? '');
        if ($skus === [] && $offers === []) {
            throw new SellerApiException('sku or offer_id required', 400, 3);
        }
        if ($limit < 1 || ($cursor !== '' && !ctype_digit($cursor))) {
            throw new SellerApiException('Invalid limit or cursor', 400, 3);
        }
        $reserved = self::reserved($state);
        $rows     = [];
        foreach (FbsConfig::products($state) as $product) {
            if (!in_array($product['sku'], $skus, true) && !in_array($product['offerId'], $offers, true)) {
                continue;
            }
            foreach (FbsConfig::warehouses($state) as $warehouse) {
                if ($warehouse['status'] !== 'created') {
                    continue;
                }
                $stock  = self::stock($state, $product['sku'], $warehouse['id']);
                $held   = $reserved[self::key($product['sku'], $warehouse['id'])] ?? 0;
                $rows[] = ['sku'     => $product['sku'], 'product_id' => $product['productId'], 'offer_id' => $product['offerId'], 'warehouse_id' => $warehouse['id'],
                    'warehouse_name' => $warehouse['name'], 'present' => $stock + $held, 'reserved' => $held, 'free_stock' => $stock];
            }
        }
        $offset = (int) $cursor;
        $next   = $offset + $limit < count($rows);

        return ['products' => array_slice($rows, $offset, $limit), 'cursor' => $next ? (string) ($offset + $limit) : '', 'has_next' => $next];
    }

    private static function key(int $sku, int $warehouseId): string
    {
        return $sku . ':' . $warehouseId;
    }
}
