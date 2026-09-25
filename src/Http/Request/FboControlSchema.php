<?php

declare(strict_types=1);

namespace App\Http\Request;

use App\Feature\Fbo\Contract;
use App\Feature\Fbo\OperationCatalog;
use App\Feature\Fbo\SellerApiException;
use App\Feature\Fbs\FbsRequirements;
use PhpSoftBox\Request\AbstractInputSchema;
use PhpSoftBox\Validator\Rule\ArrayValidation;
use PhpSoftBox\Validator\Rule\StringValidation;
use PhpSoftBox\Validator\ValidationOptions;
use PhpSoftBox\Validator\ValidationResult;

use function array_is_list;
use function count;
use function in_array;
use function is_array;
use function is_bool;
use function is_int;
use function is_string;
use function preg_match;

final class FboControlSchema extends AbstractInputSchema
{
    public function rules(): array
    {
        return ['clientId' => [new StringValidation()], 'event' => [new ArrayValidation()]];
    }
    public function process(?ValidationOptions $options = null): ValidationResult
    {
        $result = parent::process($options);
        if ($result->hasErrors()) {
            return $result;
        }
        $id      = $this->getString('clientId');
        $event   = $this->getArray('event');
        $require = static function (bool $condition, string $message): void {
            if (!$condition) {
                throw new SellerApiException($message);
            }
        };
        $require(preg_match('/^[1-9][0-9]{0,18}$/D', $id) === 1, 'Invalid Client ID');
        $require(is_string($event['eventId'] ?? null) && preg_match('/^[a-zA-Z0-9._-]{1,100}$/D', $event['eventId']) === 1, 'eventId required');
        $type = $event['type'] ?? '';
        $require(in_array($type, ['scenario', 'release', 'advance', 'state', 'acceptance', 'reset', 'beta', 'requirements', 'fbsScenario', 'fbsRequirements'], true), 'Unknown control event type');
        if ($type === 'scenario') {
            $require(isset(OperationCatalog::METHODS[$event['path'] ?? '']), 'Scenario path must identify a supported async write');
            foreach (['remaining' => [1, 100], 'delaySeconds' => [0, 86400], 'partialCargoCount' => [1, 40]] as $key => [$min, $max]) {
                if (isset($event[$key])) {
                    $require(is_int($event[$key]) && $event[$key] >= $min && $event[$key] <= $max, 'Invalid ' . $key);
                }
            }
            foreach (['hold', 'fail'] as $key) {
                $require(!isset($event[$key]) || is_bool($event[$key]), 'Invalid ' . $key);
            }
            $require(!isset($event['partialCargoCount']) || $event['path'] === '/v1/cargoes/create', 'Partial outcome applies to cargo create');
        }
        if ($type === 'release') {
            $require(is_string($event['operationId'] ?? null), 'operationId required');
        }
        if ($type === 'advance') {
            $require(is_int($event['seconds'] ?? null) && $event['seconds'] > 0 && $event['seconds'] <= 31536000, 'Invalid seconds');
        }
        if ($type === 'state') {
            $require(is_int($event['orderId'] ?? null) && $event['orderId'] > 0, 'orderId required');
            $require(is_string($event['state'] ?? null) && preg_match('/^[A-Z][A-Z0-9_]{1,79}$/D', $event['state']) === 1, 'Invalid state');
        }
        if ($type === 'acceptance') {
            $require(is_int($event['supplyId'] ?? null) && $event['supplyId'] > 0, 'supplyId required');
            $require(is_array($event['items'] ?? null) && array_is_list($event['items']) && count($event['items']) <= 5000, 'items list required');
            $seen = [];
            foreach ($event['items'] as $item) {
                $require(is_array($item) && is_int($item['sku'] ?? null) && !isset($seen[$item['sku']]), 'Invalid/duplicate acceptance SKU');
                $seen[$item['sku']] = true;
                foreach (['factQuantity', 'defectQuantity'] as $key) {
                    $require(is_int($item[$key] ?? null) && $item[$key] >= 0, 'Invalid ' . $key);
                }
                $require($item['defectQuantity'] <= $item['factQuantity'], 'Defects cannot exceed physical quantity');
            }
        }
        if ($type === 'reset') {
            $require(($event['clientId'] ?? null) === $id, 'Reset must repeat the exact target clientId');
        }
        if ($type === 'beta') {
            $require(is_array($event['disabledPaths'] ?? null) && array_is_list($event['disabledPaths']), 'disabledPaths list required');
            foreach ($event['disabledPaths'] as $path) {
                $require(in_array($path, new Contract()->paths(), true), 'Unknown disabled path');
            }
        }
        if ($type === 'requirements') {
            $require(is_int($event['supplyId'] ?? null) && $event['supplyId'] > 0, 'supplyId required');
            foreach (['utdUploaded', 'ettnUploaded', 'evsdUploaded'] as $key) {
                $require(is_bool($event[$key] ?? null), $key . ' boolean required');
            }
        }
        if ($type === 'fbsScenario') {
            foreach (['shipFailures', 'labelFailures'] as $key) {
                $require(!isset($event[$key]) || (is_int($event[$key]) && $event[$key] >= 0 && $event[$key] <= 100), 'Invalid ' . $key);
            }
            $require(!isset($event['rejectedMarks']) || (is_array($event['rejectedMarks']) && array_is_list($event['rejectedMarks'])), 'rejectedMarks must be a list');
            foreach ($event['rejectedMarks'] ?? [] as $mark) {
                $require(is_string($mark) && $mark !== '', 'rejectedMarks must contain codes');
            }
        }
        if ($type === 'fbsRequirements') {
            $require(is_string($event['postingNumber'] ?? null) && $event['postingNumber'] !== '', 'postingNumber required');
            $require(is_array($event['requirements'] ?? null) && !array_is_list($event['requirements']), 'requirements object required');
            foreach ($event['requirements'] as $kind => $skus) {
                $require(isset(FbsRequirements::CONFIG_KEYS[$kind]) && is_array($skus) && array_is_list($skus), 'Unknown requirement ' . $kind);
                foreach ($skus as $sku) {
                    $require(is_int($sku) && $sku > 0, 'Invalid requirement SKU');
                }
            }
        }

        return $result;
    }
}
