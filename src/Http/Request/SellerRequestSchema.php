<?php

declare(strict_types=1);

namespace App\Http\Request;

use App\Feature\Fbo\Contract;
use App\Feature\Fbo\SellerApiException;
use PhpSoftBox\Request\Request;
use PhpSoftBox\Request\RequestSchema;
use PhpSoftBox\Validator\Rule\ArrayValidation;
use PhpSoftBox\Validator\Rule\StringValidation;
use PhpSoftBox\Validator\ValidationOptions;
use PhpSoftBox\Validator\ValidationResult;

use function array_is_list;
use function is_array;

final class SellerRequestSchema extends RequestSchema
{
    public function __construct(
        Request $request,
        private readonly Contract $contract,
    ) {
        parent::__construct($request);
    }

    public function beforeValidation(): void
    {
        $body = $this->request->psr()->getParsedBody() ?? [];
        if (!is_array($body) || ($body !== [] && array_is_list($body))) {
            throw new SellerApiException('JSON object required');
        }
        $query = $this->request->psr()->getQueryParams();
        $this->request->replace(['input' => $body, 'wms_cargo_scenario' => $query['wms_cargo_scenario'] ?? '']);
    }

    public function rules(): array
    {
        return ['input' => [new ArrayValidation()], 'wms_cargo_scenario' => [new StringValidation()->in('', 'success', 'error', 'reset')]];
    }

    public function cargoScenario(): ?string
    {
        return $this->getString('wms_cargo_scenario', '') ?: null;
    }

    public function process(?ValidationOptions $options = null): ValidationResult
    {
        $result = parent::process($options);
        if (!$result->hasErrors()) {
            $this->contract->validate($this->request->psr()->getUri()->getPath(), $this->getArray('input'));
        }

        return $result;
    }
}
