<?php

declare(strict_types=1);

namespace App\Feature\Fbs;

use App\Feature\Fbo\CabinetState;

use function in_array;

/** Entry point of the FBS Seller API methods: warehouses, postings, stocks, assembly and labels of an FBS-enabled cabinet. */
final readonly class FbsApiService
{
    public const array PATHS = [
        ...FbsReadService::PATHS, ...FbsWarehouseService::PATHS, ...FbsStockService::PATHS, ...FbsCancellationService::PATHS,
        ...FbsExemplarService::PATHS, ...FbsShipService::PATHS, ...FbsLabelService::PATHS,
    ];

    /** FBS methods that change the cabinet; they also need `writeEnabled`. */
    public const array WRITE_PATHS = [
        '/v1/warehouse/fbs/create', '/v2/products/stocks', ...FbsCancellationService::WRITE_PATHS, ...FbsExemplarService::WRITE_PATHS,
        ...FbsShipService::WRITE_PATHS, ...FbsLabelService::WRITE_PATHS,
    ];

    public function __construct(
        private FbsReadService $reader,
        private FbsWarehouseService $warehouses,
        private FbsStockService $stocks,
        private FbsCancellationService $cancellations,
        private FbsExemplarService $exemplars,
        private FbsShipService $ships,
        private FbsLabelService $labels,
    ) {
    }

    public function supports(string $path): bool
    {
        return in_array($path, self::PATHS, true);
    }

    public function handle(CabinetState $state, string $clientId, string $path, array $input, int $now): array
    {
        FbsConfig::of($state);
        $this->warehouses->advance($state, $now);

        return match (true) {
            $this->reader->supports($path)        => $this->reader->read($state, $path, $input, $now),
            $this->warehouses->supports($path)    => $this->warehouses->handle($state, $path, $input, $now),
            $this->cancellations->supports($path) => $this->cancellations->handle($state, $path, $input, $now),
            $this->exemplars->supports($path)     => $this->exemplars->handle($state, $path, $input, $now),
            $this->ships->supports($path)         => $this->ships->handle($state, $path, $input, $now),
            $this->labels->supports($path)        => $this->labels->handle($state, $clientId, $path, $input, $now),
            default                               => $this->stocks->handle($state, $path, $input, $now),
        };
    }
}
