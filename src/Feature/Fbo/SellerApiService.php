<?php

declare(strict_types=1);

namespace App\Feature\Fbo;

use App\Feature\Catalog\CatalogReadService;
use App\Feature\Fbo\Command\AdvanceOperations\AdvanceOperationsCommand;
use App\Feature\Fbo\Command\AdvanceOperations\AdvanceOperationsHandler;
use App\Feature\Fbo\Command\AdvanceSupply\AdvanceSupplyCommand;
use App\Feature\Fbo\Command\AdvanceSupply\AdvanceSupplyHandler;
use App\Feature\Fbo\Command\CreateDraft\CreateDraftCommand;
use App\Feature\Fbo\Command\CreateDraft\CreateDraftHandler;
use App\Feature\Fbo\Command\CreateSupply\CreateSupplyCommand;
use App\Feature\Fbo\Command\CreateSupply\CreateSupplyHandler;
use App\Feature\Fbo\Command\ExecuteOperation\ExecuteOperationCommand;
use App\Feature\Fbo\Command\ExecuteOperation\ExecuteOperationHandler;
use App\Feature\Fbs\FbsApiService;
use App\Feature\Token\TokenIdentity;
use Psr\Clock\ClockInterface;

use function in_array;

/** Transaction boundary of the local external system, not a WMS tenant operation. */
final readonly class SellerApiService
{
    public function __construct(
        private CabinetRepositoryInterface $cabinets,
        private CreateDraftHandler $drafts,
        private CreateSupplyHandler $supplies,
        private AdvanceSupplyHandler $advance,
        private FboReadService $reader,
        private Contract $contract,
        private ExecuteOperationHandler $operations,
        private AdvanceOperationsHandler $advanceOperations,
        private FboOperationsReader $operationsReader,
        private ClockInterface $clock,
        private FbsApiService $fbs,
        private CatalogReadService $catalog,
    ) {
    }

    public function execute(TokenIdentity $identity, string $path, array $input, ?string $cargoScenario = null): array
    {
        if ($cargoScenario !== null && (!in_array($path, $cargoScenario === 'reset' ? ['/v1/cargoes/delete', '/v2/cargoes/delete'] : ['/v1/cargoes/create'], true) || !in_array($cargoScenario, ['success', 'error', 'reset'], true))) {
            throw new SellerApiException('Invalid cargo scenario');
        }
        $this->contract->validate($path, $input);
        $realNow = $this->clock->now()->getTimestamp();
        $result  = $this->cabinets->change($identity->clientId, function (CabinetState $state) use ($identity, $path, $input, $realNow, $cargoScenario): array {
            $state->authorize(OperationCatalog::isWrite($path), $this->fbs->supports($path));
            if (in_array($path, $state->data['disabled_paths'] ?? [], true)) {
                throw new SellerApiException('Method is unavailable for this test cabinet', 403, 7);
            }
            $c        = $state->config();
            $now      = $realNow + $c['clockOffsetSeconds'];
            $fault    = $c['fault'];
            $hasFault = $fault['path'] === $path && $fault['remaining'] > 0;
            if ($hasFault) {
                --$state->data['config']['fault']['remaining'];
            }
            if ($hasFault && $fault['phase'] === 'before') {
                return ['fault' => $fault['status']];
            }
            $this->advance->handle($state, new AdvanceSupplyCommand($now));
            $this->advanceOperations->handle($state, new AdvanceOperationsCommand($identity->clientId, $now));
            $response = match ($path) {
                '/v1/draft/direct/create'        => $this->drafts->handle($state, new CreateDraftCommand('DIRECT', $input, $now)),
                '/v1/draft/crossdock/create'     => $this->drafts->handle($state, new CreateDraftCommand('CROSSDOCK', $input, $now)),
                '/v1/draft/multi-cluster/create' => $this->drafts->handle($state, new CreateDraftCommand('MULTI_CLUSTER', $input, $now)),
                '/v2/draft/supply/create'        => $this->supplies->handle($state, new CreateSupplyCommand($input, $now)),
                default                          => isset(OperationCatalog::METHODS[$path])
                    ? $this->operations->handle($state, new ExecuteOperationCommand($identity->clientId, $path, $input, $now, $cargoScenario))
                    : ($this->fbs->supports($path) ? $this->fbs->handle($state, $identity->clientId, $path, $input, $now)
                        : ($this->catalog->supports($path) ? $this->catalog->read($c, $path, $input)
                            : ($this->operationsReader->supports($path) ? $this->operationsReader->read($state, $path, $input, $now) : $this->reader->read($state, $identity, $path, $input, $now)))),
            };
            $this->contract->validate($path, $response, 'response');

            return $hasFault ? ['fault' => $fault['status']] : ['response' => $response];
        });
        if (isset($result['fault'])) {
            throw new SellerApiException('Configured local transport failure', $result['fault'], $result['fault'] === 429 ? 8 : 14);
        }

        return $result['response'];
    }
}
