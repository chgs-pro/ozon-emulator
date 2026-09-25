<?php

declare(strict_types=1);

namespace App\Feature\Fbo;

use App\Feature\Fbs\FbsApiService;

use function array_column;
use function in_array;
use function str_starts_with;

/** Status URLs and their different envelopes are part of Seller API, not WMS conventions. */
final class OperationCatalog
{
    public const METHODS = [
        '/v1/cargoes/create'                          => ['/v2/cargoes/create/info', 'cargo.create'],
        '/v1/cargoes/delete'                          => ['/v1/cargoes/delete/status', 'cargo.delete'],
        '/v2/cargoes/delete'                          => ['/v2/cargoes/delete/status', 'cargo.delete2'],
        '/v1/cargoes/transport/activate'              => ['/v1/cargoes/transport/activate/status', 'transport.activate'],
        '/v1/cargoes/transport/create'                => ['/v1/cargoes/transport/create/status', 'transport.create'],
        '/v1/cargoes/transport/bind'                  => ['/v1/cargoes/transport/bind/status', 'transport.bind'],
        '/v1/cargoes-label/create'                    => ['/v1/cargoes-label/get', 'label.cargo'],
        '/v1/cargoes/label/transport/create'          => ['/v1/cargoes/label/transport/status', 'label.transport'],
        '/v1/cargoes/label/transport-by-order/create' => ['/v1/cargoes/label/transport-by-order/status', 'label.order'],
        '/v1/supply-order/content/update'             => ['/v1/supply-order/content/update/status', 'content'],
        '/v1/supply-order/timeslot/update'            => ['/v1/supply-order/timeslot/status', 'timeslot'],
        '/v1/supply-order/pass/create'                => ['/v1/supply-order/pass/status', 'pass'],
        '/v1/supply-order/cancel'                     => ['/v1/supply-order/cancel/status', 'cancel'],
        '/v1/supply-order/act/accept'                 => ['/v1/supply-order/act/accept/status', 'act.accept'],
    ];

    public static function isWrite(string $path): bool
    {
        return isset(self::METHODS[$path]) || in_array($path, ['/v1/draft/direct/create', '/v1/draft/crossdock/create', '/v1/draft/multi-cluster/create', '/v2/draft/supply/create'], true)
            || in_array($path, FbsApiService::WRITE_PATHS, true);
    }

    public static function statusPath(string $path): bool
    {
        return in_array($path, array_column(self::METHODS, 0), true);
    }

    public static function response(array $op): array
    {
        $kind    = $op['kind'];
        $status  = $op['status'];
        $error   = $op['error'] ?? null;
        $reasons = $error === null ? [] : [self::reason($kind, $error)];
        $result  = $op['result'] ?? [];
        if ($kind === 'pass') {
            return ['result' => match ($status) {
                'SUCCESS' => 'Success', 'FAILED' => 'Failed', default => 'InProgress'
            }, 'errors' => $reasons];
        }
        if ($kind === 'timeslot') {
            return ['status' => match ($status) {
                'SUCCESS' => 'STATUS_SUCCESS', 'FAILED' => 'STATUS_ERROR', default => 'STATUS_IN_PROGRESS'
            }, 'errors' => $reasons];
        }
        if ($kind === 'act.accept') {
            return ['status' => $status, 'error_message' => $error ?? ''];
        }
        $response = ['status' => $status === 'FAILED' && in_array($kind, ['cargo.delete', 'cancel', 'content'], true) ? 'ERROR' : $status];
        if (in_array($kind, ['cargo.create', 'label.cargo'], true)) {
            $response['errors'] = ['error_reasons' => $reasons];
            if ($kind === 'cargo.create') {
                $response['errors']['items_validation'] = $op['details']['items_validation'] ?? [];
            }
        } elseif (str_starts_with($kind, 'cargo.delete')) {
            $response['errors'] = ['supply_error_reasons' => $reasons, 'cargo_error_reasons' => $op['details']['cargo_error_reasons'] ?? []];
            if ($kind === 'cargo.delete2') {
                $response['errors']['transport_cargo_error_reasons'] = $op['details']['transport_cargo_error_reasons'] ?? [];
            }
        } elseif ($kind === 'content') {
            $response['errors'] = $reasons;

            return $response + $result;
        } else {
            $response['error_reasons'] = $reasons;
        }
        if ($result !== []) {
            $response['result'] = $result;
        }

        return $response;
    }

    private static function reason(string $kind, string $reason): string
    {
        return match ($kind) {
            'pass'     => 'SET_VEHICLE_ERROR_INVALID_ORDER_STATE',
            'timeslot' => match ($reason) {
                'LIMIT' => 'UPDATE_TIMESLOT_ERROR_LIMIT_OF_CHANGING_TIMESLOT_EXCEEDED', 'SLOT' => 'UPDATE_TIMESLOT_ERROR_OUT_OF_ALLOWED_RANGE', default => 'UPDATE_TIMESLOT_ERROR_INVALID_ORDER_STATE'
            },
            'cancel'  => $reason === 'BUSY' ? 'OTHER_ASYNCHRONOUS_OPERATION_IN_PROGRESS' : 'INVALID_ORDER_STATE',
            'content' => match ($reason) {
                'INVALID_STATE' => 'INCORRECT_SUPPLY_STATE', 'UTD_IS_UPLOADED' => 'HAS_UTD', 'BUSY' => 'SUPPLY_LOCKED', 'FAILED' => 'SOME_SERVICE_ERROR', default => $reason
            },
            'cargo.delete', 'cargo.delete2' => match ($reason) {
                'BUSY', 'FAILED' => 'SUPPLY_CARGOES_LOCKED', 'INVALID_STATE' => 'SUPPLY_CARGOES_IS_FINALIZED', default => $reason
            },
            'transport.activate' => $reason === 'INVALID_STATE' ? 'SUPPLY_IS_FINALIZED' : 'CAN_NOT_EDIT_TAG',
            'transport.create'   => match ($reason) {
                'INVALID_STATE' => 'SUPPLY_CARGOES_IS_FINALIZED', 'BUSY' => 'SUPPLY_CARGOES_LOCKED', 'WAREHOUSE_LIMITS_EXCEED' => $reason, default => 'UNDEFINED'
            },
            'transport.bind' => match ($reason) {
                'INVALID_STATE' => 'INVALID_SUPPLY_STATE', 'BUSY', 'FAILED' => 'OPERATION_FAILED', default => $reason
            },
            'cargo.create' => match ($reason) {
                'BUSY', 'FAILED' => 'OPERATION_FAILED', default => $reason
            },
            'label.cargo', 'label.transport' => in_array($reason, ['INVALID_STATE', 'SUPPLY_IS_EMPTY', 'CARGOES_NOT_FOUND'], true) ? $reason : 'OPERATION_FAILED',
            'label.order'                    => $reason === 'SUPPLY_IS_EMPTY' ? 'ALL_SUPPLIES_SKIPPED' : 'OPERATION_FAILED',
            default                          => $reason,
        };
    }
}
