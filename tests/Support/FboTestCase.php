<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Feature\Fbo\CabinetRepositoryInterface;
use App\Feature\Fbo\Command\ConfigureCabinet\ConfigureCabinetCommand;
use App\Feature\Fbo\Command\ConfigureCabinet\ConfigureCabinetHandler;
use App\Feature\Fbo\Command\ControlCabinet\ControlCabinetCommand;
use App\Feature\Fbo\Command\ControlCabinet\ControlCabinetHandler;
use App\Feature\Fbo\OperationCatalog;
use App\Feature\Fbo\SellerApiService;
use App\Feature\Token\TokenIdentity;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;

use function bin2hex;
use function dirname;
use function file_get_contents;
use function gmdate;
use function json_decode;
use function random_bytes;

use const JSON_THROW_ON_ERROR;

abstract class FboTestCase extends TestCase
{
    protected MutableClock $clock;
    protected CabinetRepositoryInterface $repository;
    protected mixed $container;
    protected SellerApiService $service;
    protected array $configuration;
    protected TokenIdentity $identity;
    protected function setUp(): void
    {
        $this->container = require dirname(__DIR__, 2) . '/config/container.php';
        $this->clock     = new MutableClock();

        $this->repository = new MemoryCabinetRepository();

        $this->container->set(ClockInterface::class, $this->clock);
        $this->container->set(CabinetRepositoryInterface::class, $this->repository);
        $this->configuration = json_decode(file_get_contents(dirname(__DIR__, 2) . '/fixtures/fbo-basic.json'), true, flags: JSON_THROW_ON_ERROR);
        $this->configure();
        $this->service  = $this->container->get(SellerApiService::class);
        $this->identity = new TokenIdentity('1001', 'ozon-seller', $this->clock->timestamp + 86400);
    }
    protected function configure(string $clientId = '1001'): int
    {
        return $this->container->get(ConfigureCabinetHandler::class)->handle(new ConfigureCabinetCommand($clientId, $this->configuration));
    }
    protected function call(string $path, array $input = []): array
    {
        return $this->service->execute($this->identity, $path, $input);
    }
    protected function draft(string $mode = 'PARTIAL', array $items = []): int
    {
        return $this->call('/v1/draft/direct/create', ['cluster_info' => ['macrolocal_cluster_id' => 510001, 'items' => $items ?: [['sku' => 910001,'quantity' => 10],['sku' => 910002,'quantity' => 6]]], 'deletion_sku_mode' => $mode])['draft_id'];
    }
    protected function selection(int $id): array
    {
        return ['draft_id' => $id,'supply_type' => 'DIRECT','selected_cluster_warehouses' => [['macrolocal_cluster_id' => 510001,'storage_warehouse_id' => 710001]]];
    }
    protected function supplyInput(int $id): array
    {
        $this->clock->timestamp += 3;
        $input = $this->selection($id);
        $slot  = $this->call('/v2/draft/timeslot/info', $input + ['date_from' => gmdate('Y-m-d', $this->clock->timestamp),'date_to' => gmdate('Y-m-d', $this->clock->timestamp + 7 * 86400)]);

        return $input + ['timeslot' => $slot['result']['drop_off_warehouse_timeslots']['days'][0]['timeslots'][0]];
    }
    protected function createOrder(): array
    {
        $id = $this->draft();
        $this->call('/v2/draft/supply/create', $this->supplyInput($id));
        $this->clock->timestamp += 3;
        $status = $this->call('/v2/draft/supply/create/status', ['draft_id' => $id]);

        return $this->call('/v3/supply-order/get', ['order_ids' => [$status['order_id']]])['orders'][0];
    }

    protected function complete(string $path, array $input): array
    {
        $operation = $this->call($path, $input);
        $this->clock->timestamp += 3;

        return $this->call(OperationCatalog::METHODS[$path][0], ['operation_id' => $operation['operation_id']]);
    }
    protected function cargoInput(int $supplyId): array
    {
        return ['supply_id' => $supplyId, 'cargoes' => [
            ['key' => 'box-a', 'value' => ['type' => 'BOX', 'items' => [['offer_id' => 'FBO-TEST-A', 'quantity' => 10, 'quant' => 1]]]],
            ['key' => 'box-b', 'value' => ['type' => 'BOX', 'items' => [['barcode' => '2000000000022', 'quantity' => 6, 'quant' => 1]]]],
        ]];
    }
    protected function control(array $event): array
    {
        $event += ['eventId' => 'event-' . bin2hex(random_bytes(8))];

        return $this->container->get(ControlCabinetHandler::class)->handle(new ControlCabinetCommand($this->identity->clientId, $event));
    }
}
