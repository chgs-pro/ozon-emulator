<?php

declare(strict_types=1);

namespace App\Feature\Catalog;

use App\Feature\Fbo\SellerApiException;

use function array_column;
use function array_filter;
use function array_map;
use function array_slice;
use function array_values;
use function count;
use function in_array;
use function max;
use function min;
use function usort;

/**
 * Каталог товаров: дерево категорий, атрибуты типа и характеристики товаров (/v4/product/info/attributes).
 * Данные берутся из необязательного раздела catalog и полей товаров конфигурации кабинета.
 */
final readonly class CatalogReadService
{
    public const array PATHS = ['/v1/description-category/tree', '/v1/description-category/attribute', '/v4/product/info/attributes'];

    private const int DEFAULT_LIMIT = 100;
    private const int MAX_LIMIT     = 1000;

    public function supports(string $path): bool
    {
        return in_array($path, self::PATHS, true);
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function read(array $config, string $path, array $input): array
    {
        $catalog = $config['catalog'] ?? ['categories' => [], 'attributes' => []];

        return match ($path) {
            '/v1/description-category/tree'      => ['result' => array_map($this->treeNode(...), $catalog['categories'])],
            '/v1/description-category/attribute' => ['result' => $this->typeAttributes($catalog, (int) $input['description_category_id'], (int) $input['type_id'])],
            default                              => $this->productAttributes($config['products'], $input),
        };
    }

    /**
     * @param array<string, mixed> $node
     * @return array<string, mixed>
     */
    private function treeNode(array $node): array
    {
        return [
            'description_category_id' => $node['id'],
            'category_name'           => $node['name'],
            'disabled'                => false,
            'children'                => [
                ...array_map($this->treeNode(...), $node['children'] ?? []),
                ...array_map(static fn (array $type): array => [
                    'type_id'   => $type['id'],
                    'type_name' => $type['name'],
                    'disabled'  => false,
                    'children'  => [],
                ], $node['types'] ?? []),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $catalog
     * @return list<array<string, mixed>>
     */
    private function typeAttributes(array $catalog, int $categoryId, int $typeId): array
    {
        $type = self::findType($catalog['categories'], $categoryId, $typeId)
            ?? throw new SellerApiException('Description category or type not found', 404, 5);
        $attributes = array_column($catalog['attributes'], null, 'id');

        return array_values(array_map(static fn (int $id): array => [
            'id'                    => $id,
            'name'                  => $attributes[$id]['name'],
            'description'           => '',
            'type'                  => $attributes[$id]['type'] ?? 'String',
            'is_collection'         => $attributes[$id]['isCollection'] ?? false,
            'is_required'           => false,
            'is_aspect'             => false,
            'category_dependent'    => false,
            'group_id'              => 0,
            'group_name'            => '',
            'dictionary_id'         => $attributes[$id]['dictionaryId'] ?? 0,
            'attribute_complex_id'  => 0,
            'max_value_count'       => 0,
            'complex_is_collection' => false,
        ], $type['attributeIds']));
    }

    /**
     * Тип внутри своей категории описания (пара description_category_id + type_id).
     *
     * @param list<array<string, mixed>> $nodes
     * @return array<string, mixed>|null
     */
    public static function findType(array $nodes, int $categoryId, int $typeId): ?array
    {
        foreach ($nodes as $node) {
            if ($node['id'] === $categoryId) {
                foreach ($node['types'] ?? [] as $type) {
                    if ($type['id'] === $typeId) {
                        return $type;
                    }
                }
            }

            $found = self::findType($node['children'] ?? [], $categoryId, $typeId);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /**
     * @param list<array<string, mixed>> $products
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function productAttributes(array $products, array $input): array
    {
        $filter = $input['filter'] ?? [];
        if (isset($filter['visibility']) && !in_array($filter['visibility'], ['ALL', 'VISIBLE'], true)) {
            throw new SellerApiException('Visibility filter not implemented by this test profile');
        }
        foreach (['product_id' => 'productId', 'offer_id' => 'offerId', 'sku' => 'sku'] as $key => $field) {
            if (!empty($filter[$key])) {
                $values   = array_map('strval', $filter[$key]);
                $products = array_values(array_filter($products, static fn (array $p): bool => in_array((string) $p[$field], $values, true)));
            }
        }
        usort($products, static fn (array $a, array $b): int => $a['productId'] <=> $b['productId']);

        $lastId = (int) ($input['last_id'] ?? 0);
        $after  = array_values(array_filter($products, static fn (array $p): bool => $p['productId'] > $lastId));
        $limit  = min(self::MAX_LIMIT, max(1, (int) ($input['limit'] ?? self::DEFAULT_LIMIT)));
        $page   = array_slice($after, 0, $limit);

        return [
            'result'  => array_map($this->productItem(...), $page),
            'last_id' => count($after) > $limit ? (string) $page[count($page) - 1]['productId'] : '',
            'total'   => (string) count($products),
        ];
    }

    /**
     * @param array<string, mixed> $p
     * @return array<string, mixed>
     */
    private function productItem(array $p): array
    {
        $dimensions = $p['dimensions'] ?? [];

        return [
            'id'                       => $p['productId'],
            'offer_id'                 => $p['offerId'],
            'sku'                      => (string) $p['sku'],
            'name'                     => $p['name'],
            'barcode'                  => $p['barcodes'][0],
            'barcodes'                 => $p['barcodes'],
            'description_category_id'  => $p['descriptionCategoryId'] ?? 0,
            'type_id'                  => $p['typeId'] ?? 0,
            'height'                   => $dimensions['height'] ?? 0,
            'width'                    => $dimensions['width'] ?? 0,
            'depth'                    => $dimensions['depth'] ?? 0,
            'dimension_unit'           => $dimensions['unit'] ?? 'mm',
            'weight'                   => $dimensions['weight'] ?? 0,
            'weight_unit'              => $dimensions['weightUnit'] ?? 'g',
            'primary_image'            => '',
            'color_image'              => '',
            'images'                   => [],
            'pdf_list'                 => [],
            'model_info'               => ['model_id' => $p['productId'], 'count' => 1],
            'attributes_with_defaults' => [],
            'complex_attributes'       => [],
            'attributes'               => array_map(static fn (array $attribute): array => [
                'id'         => $attribute['id'],
                'complex_id' => 0,
                'values'     => array_map(static fn (string $value): array => ['dictionary_value_id' => 0, 'value' => $value], $attribute['values']),
            ], $p['attributes'] ?? []),
        ];
    }
}
