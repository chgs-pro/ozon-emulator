<?php

declare(strict_types=1);

namespace App\Feature\Fbs;

use App\Feature\Fbo\CabinetState;
use App\Feature\Fbo\SellerApiException;

use function array_fill_keys;
use function array_filter;
use function array_map;
use function array_merge;
use function array_values;
use function in_array;

/** FBS section of the test cabinet configuration; FBS methods are available only when it is enabled. */
final class FbsConfig
{
    /** @return array{enabled: bool, warehouses: list<array>, productSkus: list<int>, markedSkus: list<int>, ...} requirement lists of {@see FbsRequirements::CONFIG_KEYS} default to empty */
    public static function of(CabinetState $state): array
    {
        $fbs = $state->config()['fbs'] ?? [];
        if (($fbs['enabled'] ?? false) !== true) {
            throw new SellerApiException('Access denied', 403, 7);
        }

        return $fbs + ['productSkus' => []] + array_fill_keys(array_values(FbsRequirements::CONFIG_KEYS), []);
    }

    public static function enabled(array $config): bool
    {
        return ($config['fbs']['enabled'] ?? false) === true;
    }

    /**
     * Configured warehouses followed by the ones created through `/v1/warehouse/fbs/create` (cabinet state).
     * Both share `id`, `name`, `status`, `firstMileType` and `deliveryMethods`; created ones also keep the request fields.
     * A configured DROP_OFF warehouse hands postings over at `dropOffPointId` (the first point of the directory by default),
     * like a real warehouse whose first mile was chosen at creation.
     *
     * @return list<array>
     */
    public static function warehouses(CabinetState $state): array
    {
        $configured = array_map(static function (array $w): array {
            $w += ['status' => 'created', 'firstMileType' => 'DROP_OFF'];

            // Only a sorting centre accepts oversized goods.
            $default = ($w['is_kgt'] ?? false) ? FbsWarehouseService::DROP_OFF_POINTS[3]['id'] : FbsWarehouseService::DROP_OFF_POINTS[0]['id'];

            return $w + ['dropOffPointId' => $w['firstMileType'] === 'DROP_OFF' ? $default : null];
        }, self::of($state)['warehouses']);

        return array_merge($configured, array_values($state->data['fbs']['warehouses'] ?? []));
    }

    /** @return array{float, float} coordinates of the warehouse: the created one keeps the requested ones, configured — `latitude`/`longitude` or the centre of Moscow */
    public static function coordinates(array $warehouse): array
    {
        return isset($warehouse['latitude'], $warehouse['longitude'])
            ? [(float) $warehouse['latitude'], (float) $warehouse['longitude']]
            : FbsWarehouseService::DEFAULT_COORDINATES;
    }

    /** @return list<array> products of the FBS assortment (`productSkus`, empty = the whole catalog) */
    public static function products(CabinetState $state): array
    {
        $skus = self::of($state)['productSkus'];

        return array_values(array_filter($state->config()['products'], static fn (array $product): bool => $skus === [] || in_array($product['sku'], $skus, true)));
    }
}
