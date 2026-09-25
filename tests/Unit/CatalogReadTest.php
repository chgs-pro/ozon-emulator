<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Feature\Catalog\CatalogReadService;
use App\Feature\Fbo\SellerApiException;
use App\Tests\Support\FboTestCase;
use PHPUnit\Framework\Attributes\{CoversClass, CoversMethod, Test};
use Throwable;

use function array_column;

#[CoversClass(CatalogReadService::class)]
#[CoversMethod(CatalogReadService::class, 'read')]
final class CatalogReadTest extends FboTestCase
{
    /** Дерево отдаёт категории описания с вложенными типами, атрибуты — описания атрибутов своего типа.
     * @see CatalogReadService::read()
     */
    #[Test]
    public function returnsCategoryTreeAndTypeAttributes(): void
    {
        $tree = $this->call('/v1/description-category/tree', ['language' => 'DEFAULT'])['result'];
        self::assertSame('Одежда', $tree[0]['category_name']);
        $category = $tree[0]['children'][0];
        self::assertSame(['description_category_id' => 17028922, 'category_name' => 'Футболки и топы'], ['description_category_id' => $category['description_category_id'], 'category_name' => $category['category_name']]);
        self::assertSame(['type_id' => 92851, 'type_name' => 'Футболка', 'disabled' => false, 'children' => []], $category['children'][0]);

        $attributes = $this->call('/v1/description-category/attribute', ['description_category_id' => 17028922, 'type_id' => 92851, 'language' => 'DEFAULT'])['result'];
        self::assertSame(['Бренд', 'Цвет товара', 'Состав материала', 'Страна-изготовитель', 'Размер производителя'], array_column($attributes, 'name'));
        self::assertTrue($attributes[1]['is_collection']);

        try {
            $this->call('/v1/description-category/attribute', ['description_category_id' => 17028650, 'type_id' => 92851]);
            self::fail('Type of another category must not be found');
        } catch (SellerApiException $exception) {
            self::assertSame(404, $exception->status);
        }
    }

    /** Характеристики товаров: фильтр, габариты с единицами, значения атрибутов и курсор last_id.
     * @see CatalogReadService::read()
     */
    #[Test]
    public function returnsProductAttributesWithDimensionsAndCursor(): void
    {
        $page = $this->call('/v4/product/info/attributes', ['filter' => ['product_id' => ['810001', '810006', '810007']], 'limit' => 2]);
        self::assertSame('3', $page['total']);
        self::assertSame('810006', $page['last_id']);
        [$shirt, $mug] = $page['result'];
        self::assertSame([17028922, 92851], [$shirt['description_category_id'], $shirt['type_id']]);
        self::assertSame([30, 200, 250, 'mm', 180, 'g'], [$shirt['height'], $shirt['width'], $shirt['depth'], $shirt['dimension_unit'], $shirt['weight'], $shirt['weight_unit']]);
        self::assertSame(['id' => 10096, 'complex_id' => 0, 'values' => [['dictionary_value_id' => 0, 'value' => 'белый']]], $shirt['attributes'][1]);
        self::assertSame([12, 'cm', 1, 'kg'], [$mug['height'], $mug['dimension_unit'], $mug['weight'], $mug['weight_unit']]);

        $next = $this->call('/v4/product/info/attributes', ['filter' => ['product_id' => ['810001', '810006', '810007']], 'limit' => 2, 'last_id' => $page['last_id']]);
        self::assertSame([810007], array_column($next['result'], 'id'));
        self::assertSame('', $next['last_id']);
    }

    /** Атрибут товара должен входить в набор атрибутов его типа.
     * @see CatalogReadService::findType()
     */
    #[Test]
    public function rejectsProductAttributeOutsideItsType(): void
    {
        $this->configuration['products'][5]['attributes'][] = ['id' => 4604, 'values' => ['керамика']];

        try {
            $this->configure();
            self::fail('Configuration must be rejected');
        } catch (Throwable $exception) {
            self::assertStringContainsString('Product attribute is not defined for its type', $exception->getMessage());
        }
    }
}
