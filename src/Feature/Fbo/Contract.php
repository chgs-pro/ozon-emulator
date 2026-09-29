<?php

declare(strict_types=1);

namespace App\Feature\Fbo;

use function array_diff;
use function array_is_list;
use function array_key_exists;
use function array_keys;
use function array_values;
use function basename;
use function count;
use function date_parse;
use function dirname;
use function file_get_contents;
use function in_array;
use function is_array;
use function is_bool;
use function is_float;
use function is_int;
use function is_string;
use function json_decode;
use function mb_strlen;
use function preg_match;

use const JSON_THROW_ON_ERROR;
use const PHP_INT_MAX;

/** Pinned Seller API schema; no dependency on another checkout at runtime. */
final readonly class Contract
{
    public array $data;

    public function __construct()
    {
        // FBO and FBS profiles are pinned from the same SDK snapshot; shared schema names are identical copies.
        $data = [];
        foreach (['fbo', 'fbs', 'catalog'] as $profile) {
            $part = json_decode(file_get_contents(dirname(__DIR__, 3) . '/contracts/' . $profile . '.json'), true, flags: JSON_THROW_ON_ERROR);
            $data = $data === [] ? $part : ['paths' => $data['paths'] + $part['paths'], 'components' => ['schemas' => $data['components']['schemas'] + $part['components']['schemas']]] + $data;
        }
        $this->data = $data;
    }

    public function paths(): array
    {
        return array_keys($this->data['paths']);
    }

    /** Content type of a method that answers with a file (the SDK describes the file as an object); `null` for JSON. */
    public function fileContentType(string $path): ?string
    {
        return $this->data['paths'][$path]['responseContentType'] ?? null;
    }

    public function validate(string $path, array $payload, string $direction = 'request'): void
    {
        $schema = $this->data['paths'][$path][$direction] ?? throw new SellerApiException('Method not implemented', 404, 5);
        $this->check($payload, $schema, '$', $direction === 'request', $payload);
    }

    private function check(mixed $value, array $schema, string $field, bool $input, array $root): void
    {
        if (isset($schema['$ref'])) {
            $name   = basename($schema['$ref']);
            $schema = $this->data['components']['schemas'][$name];
            // Upstream typo: property and SDK use operation_id, required says operation_idd.
            if ($input && in_array('operation_idd', $schema['required'] ?? [], true)) {
                $schema['required'] = ['operation_id'];
            }
            // Test control can expose a future external state, so WMS must handle unknown enums.
            if (!$input && in_array($name, ['OrderOrderStateEnum', 'OrderSupplyStateEnum', 'SupplyOrderDetailsResponseOrderStateEnum', 'SupplyStateEnum'], true)) {
                unset($schema['enum']);
            }
            // The snapshot contradicts itself: required for all, described as DIRECT-only.
            if ($input && $name === 'v2DraftSupplyCreateRequestSelectedClusterWarehouse' && ($root['supply_type'] ?? '') !== 'DIRECT') {
                $schema['required'] = ['macrolocal_cluster_id'];
            }
            // Snapshot requires quant_size without a property for it; product_id is optional next to offer_id (offer_id wins).
            if ($input && $name === 'productv2ProductsStocksRequestStock') {
                $schema['required'] = array_values(array_diff($schema['required'], isset($value['offer_id']) ? ['quant_size', 'product_id'] : ['quant_size']));
            }
        }
        if ($value === null && ($schema['nullable'] ?? false)) {
            return;
        }
        $type  = $schema['type'] ?? (isset($schema['items']) ? 'array' : (isset($schema['properties']) ? 'object' : null));
        $valid = match ($type) {
            'object'  => is_array($value) && ($value === [] || !array_is_list($value)),
            'array'   => is_array($value) && array_is_list($value),
            'integer' => is_int($value),
            'number'  => is_int($value) || is_float($value),
            'boolean' => is_bool($value),
            'string'  => is_string($value),
            default   => true,
        };
        // Ozon's int64 schema uses both JSON strings and numbers; the installed SDK sends both.
        if ($input && ($schema['format'] ?? '') === 'int64') {
            $valid = is_int($value) || (is_string($value) && preg_match('/^[0-9]+$/D', $value) === 1 && (string) (int) $value === $value);
        }
        if (!$valid) {
            throw new SellerApiException($field . ': expected ' . $type);
        }
        if (isset($schema['enum']) && !in_array($value, $schema['enum'], true)) {
            throw new SellerApiException($field . ': invalid enum value');
        }
        if (is_int($value) || is_float($value)) {
            if ($value < ($schema['minimum'] ?? -PHP_INT_MAX) || $value > ($schema['maximum'] ?? PHP_INT_MAX)) {
                throw new SellerApiException($field . ': outside allowed range');
            }
        }
        if (is_string($value) && mb_strlen($value) < ($schema['minLength'] ?? 0)) {
            throw new SellerApiException($field . ': too short');
        }
        if (is_string($value) && ($schema['format'] ?? '') === 'date-time') {
            if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/D', $value) !== 1) {
                throw new SellerApiException($field . ': RFC3339 date-time required');
            }
            $date = date_parse($value);
            if ($date['error_count'] > 0 || $date['warning_count'] > 0) {
                throw new SellerApiException($field . ': invalid calendar date');
            }
        }
        if ($type === 'array') {
            if (count($value) < ($schema['minItems'] ?? 0) || count($value) > ($schema['maxItems'] ?? 10000)) {
                throw new SellerApiException($field . ': invalid list size');
            }
            foreach ($value as $i => $item) {
                $this->check($item, $schema['items'] ?? [], $field . '.' . $i, $input, $root);
            }
        }
        if ($type === 'object') {
            foreach ($schema['required'] ?? [] as $key) {
                if (!array_key_exists($key, $value)) {
                    throw new SellerApiException($field . '.' . $key . ': required');
                }
            }
            foreach ($value as $key => $item) {
                if (isset($schema['properties'][$key])) {
                    $this->check($item, $schema['properties'][$key], $field . '.' . $key, $input, $root);
                }
            }
        }
    }
}
