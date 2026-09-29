<?php

declare(strict_types=1);

namespace App\Http\Request;

use App\Feature\Catalog\CatalogReadService;
use App\Feature\Fbo\Contract;
use App\Feature\Fbo\SellerApiException;
use App\Feature\Fbs\FbsRequirements;
use App\Feature\Fbs\FbsWarehouseService;
use DateTimeZone;
use PhpSoftBox\Request\AbstractInputSchema;
use PhpSoftBox\Validator\Rule\ArrayValidation;
use PhpSoftBox\Validator\Rule\StringValidation;
use PhpSoftBox\Validator\ValidationOptions;
use PhpSoftBox\Validator\ValidationResult;

use function array_column;
use function array_is_list;
use function array_values;
use function count;
use function in_array;
use function is_array;
use function is_bool;
use function is_int;
use function is_numeric;
use function is_string;
use function preg_match;

final class CabinetConfigurationSchema extends AbstractInputSchema
{
    public function rules(): array
    {
        return ['clientId' => [new StringValidation()], 'configuration' => [new ArrayValidation()]];
    }

    public function process(?ValidationOptions $options = null): ValidationResult
    {
        $result = parent::process($options);
        if ($result->hasErrors()) {
            return $result;
        }
        $id = $this->getString('clientId');
        $c  = $this->getArray('configuration');
        $this->require(preg_match('/^[1-9][0-9]{0,18}$/D', $id) === 1, 'Invalid Client ID');
        $this->require(($c['schemaVersion'] ?? null) === 1, 'schemaVersion must be 1');
        foreach (['fboEnabled', 'contractActive', 'writeEnabled', 'draftFailure', 'supplyFailure'] as $field) {
            $this->require(is_bool($c[$field] ?? null), $field . ' must be boolean');
        }
        foreach (['clockOffsetSeconds' => [-31536000,31536000], 'draftLifetimeSeconds' => [1,86400], 'operationDelaySeconds' => [0,3600]] as $key => [$min,$max]) {
            $this->require(is_int($c[$key] ?? null) && $c[$key] >= $min && $c[$key] <= $max, 'Invalid ' . $key);
        }
        foreach (['max_box_count', 'max_box_sku_count', 'max_pallet_count', 'max_transport_pallet_count'] as $key) {
            if (isset($c['cargoLimits'])) {
                $this->require(is_int($c['cargoLimits'][$key] ?? null) && $c['cargoLimits'][$key] >= 1 && $c['cargoLimits'][$key] <= 1500, 'Invalid cargo limit ' . $key);
            }
        }
        $this->require(!isset($c['vehicleRequired']) || is_bool($c['vehicleRequired']), 'vehicleRequired must be boolean');
        if (isset($c['timeslotChangesLimit'])) {
            $this->require(is_int($c['timeslotChangesLimit']) && $c['timeslotChangesLimit'] >= 0 && $c['timeslotChangesLimit'] <= 100, 'Invalid timeslotChangesLimit');
        }
        if (isset($c['sellerWarehouses'])) {
            $this->require(is_array($c['sellerWarehouses']) && array_is_list($c['sellerWarehouses']), 'sellerWarehouses must be a list');
            new Contract()->validate('/v1/warehouse/fbo/seller/list', ['warehouses' => $c['sellerWarehouses']], 'response');
        }
        $this->require(is_string($c['roleName'] ?? null) && $c['roleName'] !== '', 'roleName required');
        $ids = [];
        foreach (['products' => 'sku', 'warehouses' => 'id', 'clusters' => 'macrolocalId'] as $group => $key) {
            $rows = $c[$group] ?? null;
            $this->require(is_array($rows) && array_is_list($rows) && count($rows) > 0 && count($rows) <= 5000, 'Invalid ' . $group);
            $ids[$group] = [];
            foreach ($rows as $row) {
                $this->require(is_array($row) && is_int($row[$key] ?? null) && $row[$key] > 0 && !in_array($row[$key], $ids[$group], true), 'Invalid/duplicate ' . $group . ' ID');
                $ids[$group][] = $row[$key];
                $this->require(is_string($row['name'] ?? null) && $row['name'] !== '', 'Name required');
            }
        }
        $offers   = $productIds = [];
        $contract = new Contract();

        $itemTags   = $contract->data['components']['schemas']['v1ItemResponse']['properties']['tags']['items']['enum'];
        $supplyTags = $contract->data['components']['schemas']['WarehouseSupplyTagEnum']['enum'];
        $zones      = $contract->data['components']['schemas']['v1ItemResponse']['properties']['placement_zone']['enum'];
        foreach ($c['products'] as $p) {
            $this->require(!isset($p['expirationRequired']) || is_bool($p['expirationRequired']), 'Invalid expirationRequired');
            $this->require(!isset($p['placementZone']) || in_array($p['placementZone'], $zones, true), 'Invalid placementZone');
            $this->require(is_int($p['productId'] ?? null) && $p['productId'] > 0 && !in_array($p['productId'], $productIds, true), 'Invalid/duplicate productId');
            $this->require(is_string($p['offerId'] ?? null) && $p['offerId'] !== '' && !in_array($p['offerId'], $offers, true), 'Invalid/duplicate offerId');
            $offers[]     = $p['offerId'];
            $productIds[] = $p['productId'];
            $this->require(is_array($p['barcodes'] ?? null) && count($p['barcodes']) > 0, 'barcodes required');
            foreach ($p['barcodes'] as $barcode) {
                $this->require(is_string($barcode) && $barcode !== '', 'Invalid barcode');
            }
            $this->require(is_int($p['quant'] ?? null) && $p['quant'] > 0 && is_int($p['maxQuantity'] ?? null) && $p['maxQuantity'] >= 0, 'Invalid product quantity rule');
            $this->require(is_numeric($p['volumeLitres'] ?? null) && $p['volumeLitres'] > 0, 'volumeLitres must be positive');
            $this->require(is_array($p['tags'] ?? null) && array_is_list($p['tags']), 'tags must be a list');
            foreach ($p['tags'] as $tag) {
                $this->require(in_array($tag, $itemTags, true), 'Invalid item tag');
            }
            $this->require(is_array($p['supplyTags'] ?? []) && array_is_list($p['supplyTags'] ?? []), 'supplyTags must be a list');
            foreach ($p['supplyTags'] ?? [] as $tag) {
                $this->require(in_array($tag, $supplyTags, true), 'Invalid supply tag');
            }
        }
        $this->validateCatalog($c);
        foreach ($c['warehouses'] as $w) {
            $this->require(is_string($w['address'] ?? null) && in_array($w['timezone'] ?? '', DateTimeZone::listIdentifiers(), true), 'Warehouse address/timezone required');
            $this->require(in_array($w['type'] ?? '', ['FULL_FILLMENT','SORTING_CENTER','CROSS_DOCK','DELIVERY_POINT','ORDERS_RECEIVING_POINT'], true), 'Invalid warehouse type');
        }
        foreach ($c['clusters'] as $cluster) {
            $this->require(is_int($cluster['id'] ?? null) && $cluster['id'] > 0, 'Cluster v1 ID required');
            $this->require(is_array($cluster['warehouseIds'] ?? null) && count($cluster['warehouseIds']) > 0, 'Cluster warehouses required');
            foreach ($cluster['warehouseIds'] as $w) {
                $this->require(in_array($w, $ids['warehouses'], true), 'Unknown cluster warehouse');
            }
        }
        $this->require(is_array($c['routes'] ?? null) && array_is_list($c['routes']) && count($c['routes']) > 0, 'routes required');
        foreach ($c['routes'] as $r) {
            $this->require(is_array($r) && in_array($r['type'] ?? '', ['DIRECT','CROSSDOCK','MULTI_CLUSTER'], true), 'Invalid route');
            $this->require(in_array($r['dropoffWarehouseId'] ?? null, $ids['warehouses'], true), 'Unknown dropoff');
            foreach (['clusterIds' => 'clusters', 'allowedSkus' => 'products'] as $key => $group) {
                $this->require(is_array($r[$key] ?? null) && array_is_list($r[$key]) && count($r[$key]) > 0, 'Invalid route ' . $key);
                foreach ($r[$key] as $value) {
                    $this->require(in_array($value, $ids[$group], true), 'Unknown route ' . $key);
                }
            }
        }
        $s = $c['slots'] ?? [];
        foreach (['daysAhead' => [1,28], 'startHour' => [0,23], 'durationHours' => [1,24], 'capacity' => [0,1000]] as $key => [$min,$max]) {
            $this->require(is_int($s[$key] ?? null) && $s[$key] >= $min && $s[$key] <= $max, 'Invalid slots.' . $key);
        }
        $this->require($s['startHour'] + $s['durationHours'] <= 24, 'Slot crosses day boundary');
        $this->require(is_array($s['unavailableDates'] ?? null), 'unavailableDates required');
        foreach ($s['unavailableDates'] as $date) {
            $this->require(is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date) === 1, 'Invalid unavailable date');
        }
        if (isset($c['fbs'])) {
            $fbs = $c['fbs'];
            $this->require(is_array($fbs) && is_bool($fbs['enabled'] ?? null), 'fbs.enabled must be boolean');
            $this->require(is_bool($fbs['warehouseCreateFailure'] ?? false), 'fbs.warehouseCreateFailure must be boolean');
            $this->require(is_array($fbs['warehouses'] ?? null) && array_is_list($fbs['warehouses']) && count($fbs['warehouses']) > 0, 'fbs.warehouses required');
            $warehouseIds = $methodIds = [];
            foreach ($fbs['warehouses'] as $w) {
                $this->require(is_array($w) && is_int($w['id'] ?? null) && $w['id'] > 0 && !in_array($w['id'], $warehouseIds, true), 'Invalid/duplicate fbs warehouse ID');
                $this->require(is_string($w['name'] ?? null) && $w['name'] !== '', 'fbs warehouse name required');
                $this->require(!isset($w['firstMileType']) || in_array($w['firstMileType'], ['PICK_UP', 'DROP_OFF'], true), 'Invalid fbs firstMileType');
                $this->require(!isset($w['dropOffPointId']) || (($w['firstMileType'] ?? 'DROP_OFF') === 'DROP_OFF'
                    && in_array($w['dropOffPointId'], array_column(FbsWarehouseService::DROP_OFF_POINTS, 'id'), true)), 'fbs dropOffPointId must be a drop-off point of a DROP_OFF warehouse');
                $this->require(!isset($w['address']) || (is_string($w['address']) && $w['address'] !== ''), 'fbs warehouse address must be a string');
                $this->require(isset($w['latitude']) === isset($w['longitude']) && (!isset($w['latitude']) || (is_numeric($w['latitude']) && is_numeric($w['longitude']))), 'fbs warehouse latitude and longitude must be numbers');
                $this->require(is_array($w['deliveryMethods'] ?? null) && array_is_list($w['deliveryMethods']) && count($w['deliveryMethods']) > 0, 'fbs deliveryMethods required');
                foreach ($w['deliveryMethods'] as $m) {
                    $this->require(is_array($m) && is_int($m['id'] ?? null) && $m['id'] > 0 && !in_array($m['id'], $methodIds, true) && is_string($m['name'] ?? null) && $m['name'] !== '', 'Invalid/duplicate fbs delivery method');
                    $methodIds[] = $m['id'];
                }
                $warehouseIds[] = $w['id'];
            }
            foreach (['productSkus', 'multiboxSkus', ...array_values(FbsRequirements::CONFIG_KEYS)] as $key) {
                $this->require(is_array($fbs[$key] ?? []) && array_is_list($fbs[$key] ?? []), 'fbs.' . $key . ' must be a list');
                foreach ($fbs[$key] ?? [] as $sku) {
                    $this->require(in_array($sku, $ids['products'], true), 'Unknown fbs.' . $key . ' SKU');
                }
            }
        }
        $f = $c['fault'] ?? [];
        $this->require(is_string($f['path'] ?? null) && in_array($f['phase'] ?? '', ['before','after'], true) && in_array($f['status'] ?? null, [429,503,504], true) && is_int($f['remaining'] ?? null) && $f['remaining'] >= 0, 'Invalid fault');

        return $result;
    }

    private function require(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new SellerApiException($message);
        }
    }

    /**
     * Необязательный каталог: дерево категорий с типами, описания атрибутов и привязка товаров.
     *
     * @param array<string, mixed> $c
     */
    private function validateCatalog(array $c): void
    {
        $catalog = $c['catalog'] ?? ['categories' => [], 'attributes' => []];
        $this->require(is_array($catalog['categories'] ?? null) && array_is_list($catalog['categories']), 'catalog.categories must be a list');
        $this->require(is_array($catalog['attributes'] ?? null) && array_is_list($catalog['attributes']), 'catalog.attributes must be a list');

        $attributeIds = [];
        foreach ($catalog['attributes'] as $attribute) {
            $this->require(is_int($attribute['id'] ?? null) && $attribute['id'] > 0 && !in_array($attribute['id'], $attributeIds, true), 'Invalid/duplicate catalog attribute ID');
            $this->require(is_string($attribute['name'] ?? null) && $attribute['name'] !== '', 'Catalog attribute name required');
            $this->require(!isset($attribute['isCollection']) || is_bool($attribute['isCollection']), 'Invalid attribute isCollection');
            $this->require(!isset($attribute['dictionaryId']) || is_int($attribute['dictionaryId']), 'Invalid attribute dictionaryId');
            $this->require(!isset($attribute['type']) || is_string($attribute['type']), 'Invalid attribute type');
            $attributeIds[] = $attribute['id'];
        }
        $seen = [];
        $this->validateCategoryNodes($catalog['categories'], $attributeIds, $seen);

        foreach ($c['products'] as $p) {
            $hasCategory = isset($p['descriptionCategoryId']) || isset($p['typeId']);
            $type        = null;
            if ($hasCategory) {
                $this->require(is_int($p['descriptionCategoryId'] ?? null) && is_int($p['typeId'] ?? null), 'Product needs both descriptionCategoryId and typeId');
                $type = CatalogReadService::findType($catalog['categories'], $p['descriptionCategoryId'], $p['typeId']);
                $this->require($type !== null, 'Unknown product category/type');
            }
            if (isset($p['dimensions'])) {
                $d = $p['dimensions'];
                foreach (['height', 'width', 'depth', 'weight'] as $key) {
                    $this->require(is_int($d[$key] ?? null) && $d[$key] >= 0, 'Invalid product dimensions.' . $key);
                }
                $this->require(in_array($d['unit'] ?? '', ['mm', 'cm', 'in'], true), 'Invalid product dimensions.unit');
                $this->require(in_array($d['weightUnit'] ?? '', ['g', 'kg', 'lb'], true), 'Invalid product dimensions.weightUnit');
            }
            $this->require(is_array($p['attributes'] ?? []) && array_is_list($p['attributes'] ?? []), 'Product attributes must be a list');
            foreach ($p['attributes'] ?? [] as $attribute) {
                $this->require($type !== null && in_array($attribute['id'] ?? null, $type['attributeIds'], true), 'Product attribute is not defined for its type');
                $this->require(is_array($attribute['values'] ?? null) && array_is_list($attribute['values']) && count($attribute['values']) > 0, 'Product attribute values required');
                foreach ($attribute['values'] as $value) {
                    $this->require(is_string($value) && $value !== '', 'Invalid product attribute value');
                }
            }
        }
    }

    /**
     * @param array<array-key, mixed> $nodes
     * @param list<int> $attributeIds
     * @param array<string, true> $seen
     */
    private function validateCategoryNodes(array $nodes, array $attributeIds, array &$seen): void
    {
        foreach ($nodes as $node) {
            $this->require(is_array($node) && is_int($node['id'] ?? null) && $node['id'] > 0 && !isset($seen['c' . $node['id']]), 'Invalid/duplicate category ID');
            $this->require(is_string($node['name'] ?? null) && $node['name'] !== '', 'Category name required');
            $seen['c' . $node['id']] = true;
            $this->require(is_array($node['children'] ?? []) && array_is_list($node['children'] ?? []), 'Category children must be a list');
            $this->require(is_array($node['types'] ?? []) && array_is_list($node['types'] ?? []), 'Category types must be a list');
            foreach ($node['types'] ?? [] as $type) {
                $this->require(is_array($type) && is_int($type['id'] ?? null) && $type['id'] > 0, 'Invalid type ID');
                $this->require(is_string($type['name'] ?? null) && $type['name'] !== '', 'Type name required');
                $this->require(is_array($type['attributeIds'] ?? null) && array_is_list($type['attributeIds']), 'Type attributeIds must be a list');
                foreach ($type['attributeIds'] as $id) {
                    $this->require(in_array($id, $attributeIds, true), 'Unknown type attribute');
                }
            }
            $this->validateCategoryNodes($node['children'] ?? [], $attributeIds, $seen);
        }
    }
}
