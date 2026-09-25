<?php

declare(strict_types=1);

namespace App\Feature\Fbo;

use function array_map;
use function array_values;
use function in_array;

final readonly class FboOperationsReader
{
    public function __construct(
        private CargoService $cargo,
        private SupplyContext $context,
        private OrderMutationService $orders,
        private ActService $acts,
    ) {
    }
    public function supports(string $path): bool
    {
        return OperationCatalog::statusPath($path) || in_array($path, ['/v1/cargoes/get', '/v2/cargoes/get', '/v1/cargoes/rules/get', '/v1/cargoes/supplies/get', '/v2/supply-order/timeslot/list', '/v1/supply-order/content/update/validation', '/v1/supply-order/act/summary/get', '/v1/supply-order/act/product/get', '/v1/warehouse/fbo/seller/list'], true);
    }
    public function read(CabinetState $state, string $path, array $input, int $now): array
    {
        if (OperationCatalog::statusPath($path)) {
            $op = $state->data['operations'][$input['operation_id'] ?? ''] ?? throw new SellerApiException('Operation not found', 404, 5);
            if ($op['status_path'] !== $path) {
                throw new SellerApiException('Operation not found for this method', 404, 5);
            }

            return OperationCatalog::response($op);
        }

        return match ($path) {
            '/v1/cargoes/get', '/v2/cargoes/get'         => $this->cargo->get($state, $input, $path === '/v2/cargoes/get'),
            '/v1/cargoes/rules/get'                      => ['supply_check_lists' => array_map(fn ($id): array => $this->cargo->rules($state, (int) $id, $now), $input['supply_ids'])],
            '/v2/supply-order/timeslot/list'             => $this->orders->timeslots($state, (int) $input['order_id'], $now),
            '/v1/supply-order/content/update/validation' => $this->validation($state, $input),
            '/v1/supply-order/act/summary/get'           => $this->acts->summary($state, (int) $input['order_id']),
            '/v1/supply-order/act/product/get'           => $this->acts->products($state, (int) $input['supply_id']),
            '/v1/warehouse/fbo/seller/list'              => ['warehouses' => $state->config()['sellerWarehouses'] ?? []],
            '/v1/cargoes/supplies/get'                   => $this->supplies($state, $input),
        };
    }
    private function validation(CabinetState $state, array $input): array
    {
        $row = $state->data['validations'][$input['new_bundle_id']] ?? throw new SellerApiException('Validation bundle not found', 404, 5);
        if ($row['supply_id'] !== (int) $input['supply_id']) {
            throw new SellerApiException('Validation bundle does not belong to supply', 404, 5);
        }

        return $row['response'];
    }
    private function supplies(CabinetState $state, array $input): array
    {
        $result = ['not_found_supply_ids' => [], 'supplies_cargoes' => []];
        foreach ($input['supply_ids'] as $supplyId) {
            try {
                $cargo = $this->context->cargo($state, (int) $supplyId);
            } catch (SellerApiException) {
                $result['not_found_supply_ids'][] = (string) $supplyId;
                continue;
            }
            [$orderId, $index] = $this->context->locate($state, (int) $supplyId);
            $row               = ['supply_id' => (int) $supplyId, 'bundle_id' => $state->data['orders'][$orderId]['supplies'][$index]['bundle_id'], 'cargoes_without_transport_cargoes' => [], 'transport_cargoes' => []];
            foreach ($cargo['transport'] as $transportId => $transport) {
                $row['transport_cargoes'][$transportId] = ['transport_cargo_id' => $transportId, 'type' => 'PALLET', 'bundle_id' => $transport['summary_bundle_id'], 'cargoes' => []];
            }
            foreach ($cargo['cargoes'] as $box) {
                $dto = ['cargo_id' => $box['cargo_id'], 'bundle_id' => $box['bundle_id'], 'barcode' => LabelService::barcode($box['cargo_id'])];
                if ($box['transport_cargo_id'] > 0) {
                    $row['transport_cargoes'][$box['transport_cargo_id']]['cargoes'][] = $dto;
                } else {
                    $row['cargoes_without_transport_cargoes'][] = $dto;
                }
            }
            $row['transport_cargoes']     = array_values($row['transport_cargoes']);
            $result['supplies_cargoes'][] = $row;
        }

        return $result;
    }
}
