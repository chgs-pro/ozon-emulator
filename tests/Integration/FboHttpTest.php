<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Feature\Fbo\Contract;
use App\Feature\Fbo\SellerApiService;
use App\Feature\Token\Command\IssueToken\IssueTokenCommand;
use App\Feature\Token\Command\IssueToken\IssueTokenHandler;
use App\Feature\Token\TokenRecord;
use App\Feature\Token\TokenRepositoryInterface;
use App\Http\Action\SellerAction;
use App\Http\Middleware\SellerJsonMiddleware;
use App\Http\Middleware\SellerTokenMiddleware;
use App\Http\Request\SellerRequestSchema;
use App\Tests\Support\ApplicationHttpClient;
use App\Tests\Support\FboTestCase;
use PhpSoftBox\Application\Application;
use PhpSoftBox\Http\Message\RequestFactory;
use PhpSoftBox\Http\Message\ServerRequest;
use PhpSoftBox\Http\Message\StreamFactory;
use PhpSoftBox\Ozon\Dto\V3\SupplyOrder\V3SupplyOrderGetResponse;
use PhpSoftBox\Ozon\OzonApiClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;

use function array_column;
use function dirname;
use function gmdate;
use function json_decode;
use function json_encode;
use function parse_str;
use function parse_url;
use function str_repeat;

use const JSON_THROW_ON_ERROR;
use const PHP_URL_QUERY;

#[CoversClass(SellerJsonMiddleware::class)]
#[CoversMethod(SellerJsonMiddleware::class, 'process')]
#[CoversClass(SellerAction::class)]
#[CoversClass(SellerRequestSchema::class)]
#[CoversClass(SellerTokenMiddleware::class)]
#[CoversClass(SellerApiService::class)]
#[CoversMethod(SellerAction::class, '__invoke')]
#[CoversMethod(SellerRequestSchema::class, 'process')]
#[CoversMethod(SellerTokenMiddleware::class, 'process')]
final class FboHttpTest extends FboTestCase
{
    private Application $application;
    private string $key;
    protected function setUp(): void
    {
        parent::setUp();
        $saved  = null;
        $tokens = $this->createMock(TokenRepositoryInterface::class);
        $tokens->method('insert')->willReturnCallback(static function (TokenRecord $record) use (&$saved): void {
            $saved = $record;
        });
        $tokens->method('find')->willReturnCallback(static function () use (&$saved): ?TokenRecord {
            return $saved;
        });
        $this->container->set(TokenRepositoryInterface::class, $tokens);
        $this->key         = $this->container->get(IssueTokenHandler::class)->handle(new IssueTokenCommand('1001'))->apiKey;
        $this->application = (require dirname(__DIR__, 2) . '/config/app.php')($this->container);
    }
    /** The pinned SDK crosses the real router/schema/auth pipeline and parses the resulting DTO.
     * @see SellerAction::__invoke()
     */
    #[Test]
    public function createsSupplyThroughOzonSdkAndParsesDto(): void
    {
        $client = new OzonApiClient(clientId:'1001', apiKey:$this->key, httpClient:new ApplicationHttpClient($this->application), requestFactory:new RequestFactory(), streamFactory:new StreamFactory(), apiBase:'http://ozon.test');

        self::assertNotEmpty($client->rolesV1()->list()->all()['roles']);
        $id = $client->draftV1()->directCreate(['cluster_info' => ['macrolocal_cluster_id' => 510001,'items' => [['sku' => 910001,'quantity' => 10],['sku' => 910002,'quantity' => 6]]],'deletion_sku_mode' => 'PARTIAL'])->all()['draft_id'];
        self::assertSame('IN_PROGRESS', $client->draftV2()->createInfo(['draft_id' => $id])->all()['status']);
        $this->clock->timestamp += 3;
        $input  = $this->selection($id);
        $slots  = $client->draftV2()->timeslotInfo($input + ['date_from' => gmdate('Y-m-d', $this->clock->timestamp),'date_to' => gmdate('Y-m-d', $this->clock->timestamp + 86400)])->all();
        $result = $client->draftV2()->supplyCreate($input + ['timeslot' => $slots['result']['drop_off_warehouse_timeslots']['days'][0]['timeslots'][0]])->all();
        self::assertSame([], $result['error_reasons']);
        $this->clock->timestamp += 3;
        $orderId = $client->draftV2()->supplyCreateStatus(['draft_id' => $id])->all()['order_id'];
        $dto     = $client->supplyOrderV3()->get(['order_ids' => [$orderId]])->makeDto(V3SupplyOrderGetResponse::class);
        self::assertSame($orderId, $dto->orders[0]->orderId);
        self::assertCount(1, $dto->orders[0]->supplies);
        self::assertNotEmpty($dto->orders[0]->supplies[0]->bundleId);
    }
    /** Missing credentials use the Seller rpcStatus envelope, without app API metadata.
     * @see SellerTokenMiddleware::process()
     */
    #[Test]
    public function rejectsMissingKeyWithSellerError(): void
    {
        $r = $this->application->handle(new ServerRequest('POST', '/v1/roles', ['Content-Type' => 'application/json'], '{}'));
        self::assertSame(401, $r->getStatusCode());
        self::assertSame(16, json_decode((string)$r->getBody(), true)['code']);
    }
    /** A malformed business payload cannot persist a draft.
     * @see SellerRequestSchema::process()
     */
    #[Test]
    public function rejectsInvalidDraftBeforeWriting(): void
    {
        $r = $this->request('/v1/draft/direct/create', ['cluster_info' => ['macrolocal_cluster_id' => 510001,'items' => [['sku' => 910001,'quantity' => 'ten']]],'deletion_sku_mode' => 'PARTIAL']);
        self::assertSame(400, $r->getStatusCode(), (string)$r->getBody());
        self::assertSame(3, json_decode((string)$r->getBody(), true)['code']);
    }
    /** Every registered read route returns its actual Seller contract for the basic cabinet.
     * @see SellerAction::__invoke()
     */
    #[Test]
    public function exposesCatalogAndDirectionContracts(): void
    {
        $inputs = ['/v1/roles' => [], '/v3/product/list' => ['limit' => 1], '/v3/product/info/list' => ['product_id' => [810001]],'/v1/cluster/list' => ['cluster_type' => 'CLUSTER_TYPE_OZON'], '/v2/cluster/list' => [], '/v1/warehouse/fbo/list' => ['search' => 'Тест','filter_by_supply_type' => ['CREATE_TYPE_DIRECT','CREATE_TYPE_CROSSDOCK']]];
        foreach ($inputs as $path => $input) {
            $r = $this->request($path, $input);
            self::assertSame(200, $r->getStatusCode(), $path . ': ' . (string)$r->getBody());
            new Contract()->validate($path, json_decode((string)$r->getBody(), true), 'response');
        }
    }
    /** Cargo validates its input; no administrative shortcut creates supplies.
     * @see SellerAction::__invoke()
     */
    #[Test]
    public function validatesCargoAndDoesNotExposeCliSupplyCreation(): void
    {
        self::assertSame(400, $this->request('/v1/cargoes/create', [])->getStatusCode());
        self::assertSame(404, $this->request('/test/v1/fbo/supply/create', [])->getStatusCode());
    }

    /** Cargo, labels, acts and changes cross the real SDK and HTTP boundary.
     * @see SellerAction::__invoke()
     */
    #[Test]
    public function processesFboThroughSdkAndDownloadsAuthenticatedDocumentCapability(): void
    {
        $client = new OzonApiClient(clientId: '1001', apiKey: $this->key, httpClient: new ApplicationHttpClient($this->application), requestFactory: new RequestFactory(), streamFactory: new StreamFactory(), apiBase: 'http://ozon.test');
        $order  = $this->createOrder();
        $id     = $order['supplies'][0]['supply_id'];
        $op     = $client->cargoesV1()->create($this->cargoInput($id))->all();
        self::assertSame('IN_PROGRESS', $client->cargoesV2()->createInfo($op)->all()['status']);
        $this->clock->timestamp += 3;
        $cargoResponse = $client->cargoesV2()->createInfo($op);
        self::assertSame('SUCCESS', $cargoResponse->all()['status']);
        self::assertNotNull($cargoResponse->makeDto());
        self::assertNotNull($client->cargoesV2()->get(['supplies' => [['supply_id' => $id, 'cargo_ids' => []]]])->makeDto());
        $labelOp = $client->cargoesLabelV1()->create(['supply_id' => $id])->all();
        $this->clock->timestamp += 3;
        $labels = $client->cargoesLabelV1()->get($labelOp);
        self::assertNotNull($labels->makeDto());
        $url = $labels->all()['result']['file_url'];
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        $download = $this->application->handle(new ServerRequest('GET', $url)->withQueryParams($query));
        self::assertSame(200, $download->getStatusCode(), (string) $download->getBody());
        self::assertSame('application/pdf', $download->getHeaderLine('Content-Type'));
        self::assertStringStartsWith('%PDF-', (string) $download->getBody());
        $query['token'] = str_repeat('0', 64);
        $denied         = $this->application->handle(new ServerRequest('GET', $url)->withQueryParams($query));
        self::assertSame(404, $denied->getStatusCode());
        $this->control(['type' => 'state', 'orderId' => $order['order_id'], 'state' => 'ACCEPTED_AT_SUPPLY_WAREHOUSE']);
        $this->control(['type' => 'acceptance', 'supplyId' => $id, 'items' => []]);
        self::assertNotNull($client->supplyOrderV1()->actSummaryGet(['order_id' => $order['order_id']])->makeDto());
        self::assertNotNull($client->supplyOrderV1()->actProductGet(['supply_id' => $id])->makeDto());
        $cancel = $client->supplyOrderV1()->cancel(['order_id' => $order['order_id']])->all();
        $this->clock->timestamp += 3;
        self::assertSame('ERROR', $client->supplyOrderV1()->cancelStatus($cancel)->all()['status']);
    }

    /** Query metadata selects a local rejection without changing the Seller JSON contract.
     * @see SellerAction::__invoke()
     * @see SellerRequestSchema::cargoScenario()
     */
    #[Test]
    public function selectsCargoScenarioThroughHttp(): void
    {
        $supply  = $this->createOrder()['supplies'][0]['supply_id'];
        $input   = $this->cargoInput($supply);
        $request = new ServerRequest('POST', '/v1/cargoes/create', ['Content-Type' => 'application/json', 'Client-Id' => '1001', 'Api-Key' => $this->key], json_encode($input, JSON_THROW_ON_ERROR));

        $failed = $this->application->handle($request->withQueryParams(['wms_cargo_scenario' => 'error']));
        self::assertSame(400, $failed->getStatusCode(), (string) $failed->getBody());
        self::assertSame([], $this->call('/v1/cargoes/get', ['supply_ids' => [$supply]])['supply'][0]['cargoes']);
        $success = $this->application->handle($request->withQueryParams(['wms_cargo_scenario' => 'success']));
        self::assertSame(200, $success->getStatusCode(), (string) $success->getBody());
        $operation = json_decode((string) $success->getBody(), true);
        $this->clock->timestamp += 3;
        self::assertSame('SUCCESS', $this->call('/v2/cargoes/create/info', ['operation_id' => $operation['operation_id']])['status']);
        $invalid = $this->application->handle($request->withQueryParams(['wms_cargo_scenario' => 'unknown']));
        self::assertSame(400, $invalid->getStatusCode());
    }

    /** Полный сброс разрешён явным сценарием эмулятора; обычное удаление последнего cargo запрещено.
     * @see SellerAction::__invoke()
     */
    #[Test]
    public function resetsAllCargoOnlyWithExplicitDevelopmentScenario(): void
    {
        $order   = $this->createOrder();
        $supply  = $order['supplies'][0]['supply_id'];
        $created = $this->call('/v1/cargoes/create', $this->cargoInput($supply));
        $this->clock->timestamp += 3;
        self::assertSame('SUCCESS', $this->call('/v2/cargoes/create/info', ['operation_id' => $created['operation_id']])['status']);
        $cargoes = $this->call('/v1/cargoes/get', ['supply_ids' => [$supply]])['supply'][0]['cargoes'];
        $input   = ['supply_id' => $supply, 'cargo_ids' => array_column($cargoes, 'cargo_id')];
        $deleted = $this->call('/v1/cargoes/delete', $input);
        $this->clock->timestamp += 3;
        self::assertSame('ERROR', $this->call('/v1/cargoes/delete/status', ['operation_id' => $deleted['operation_id']])['status']);
        self::assertNotEmpty($this->call('/v1/cargoes/get', ['supply_ids' => [$supply]])['supply'][0]['cargoes']);
        $request = new ServerRequest('POST', '/v1/cargoes/delete', ['Content-Type' => 'application/json', 'Client-Id' => '1001', 'Api-Key' => $this->key], json_encode($input, JSON_THROW_ON_ERROR))->withQueryParams(['wms_cargo_scenario' => 'reset']);

        $response = $this->application->handle($request);
        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());
        $reset = json_decode((string)$response->getBody(), true);
        $this->clock->timestamp += 3;
        self::assertSame('SUCCESS', $this->call('/v1/cargoes/delete/status', ['operation_id' => $reset['operation_id']])['status']);
        self::assertSame([], $this->call('/v1/cargoes/get', ['supply_ids' => [$supply]])['supply'][0]['cargoes']);
        $createdAgain = $this->call('/v1/cargoes/create', $this->cargoInput($supply));
        $this->clock->timestamp += 3;
        self::assertSame('SUCCESS', $this->call('/v2/cargoes/create/info', ['operation_id' => $createdAgain['operation_id']])['status']);
    }

    /** Seller keys cannot reach local controls; read-only roles cannot mutate cargo.
     * @see SellerTokenMiddleware::process()
     * @see SellerAction::__invoke()
     */
    #[Test]
    public function protectsCargoWritesAndControlBoundary(): void
    {
        $order                               = $this->createOrder();
        $this->configuration['writeEnabled'] = false;
        $this->configure();
        self::assertSame(403, $this->request('/v1/cargoes/create', $this->cargoInput($order['supplies'][0]['supply_id']))->getStatusCode());
        self::assertSame(200, $this->request('/v1/cargoes/get', ['supply_ids' => [$order['supplies'][0]['supply_id']]])->getStatusCode());
        self::assertSame(404, $this->request('/test/v1/fbo/control', ['type' => 'reset'])->getStatusCode());
        $roles = json_decode((string) $this->request('/v1/roles', [])->getBody(), true);
        self::assertNotContains('/v1/cargoes/create', $roles['roles'][0]['methods']);
    }
    private function request(string $path, array $input): ResponseInterface
    {
        return $this->application->handle(new ServerRequest('POST', $path, ['Content-Type' => 'application/json','Client-Id' => '1001','Api-Key' => $this->key], json_encode($input === [] ? (object)[] : $input, JSON_THROW_ON_ERROR)));
    }
    /** Broken JSON receives the external error envelope rather than a framework exception page.
     * @see SellerJsonMiddleware::process()
     */
    #[Test]
    public function rejectsMalformedJsonWithRpcStatus(): void
    {
        $response = $this->application->handle(new ServerRequest('POST', '/v1/roles', ['Content-Type' => 'application/json'], '{bad'));
        self::assertSame(400, $response->getStatusCode());
        self::assertSame(3, json_decode((string) $response->getBody(), true)['code']);
    }

    /** A JSON list is not a valid empty roles object.
     * @see SellerJsonMiddleware::process()
     */
    #[Test]
    public function rejectsRootJsonList(): void
    {
        $response = $this->application->handle(new ServerRequest('POST', '/v1/roles', ['Content-Type' => 'application/json'], '[]'));
        self::assertSame(400, $response->getStatusCode());
    }

    /** Rate-limit simulation consumes the configured count and includes retry guidance.
     * @see SellerTokenMiddleware::process()
     */
    #[Test]
    public function returnsRetryAfterForConfiguredRateLimit(): void
    {
        $this->configuration['fault'] = ['path' => '/v1/roles', 'phase' => 'before', 'status' => 429, 'remaining' => 1];
        $this->configure();
        $response = $this->request('/v1/roles', []);
        self::assertSame(429, $response->getStatusCode());
        self::assertSame('1', $response->getHeaderLine('Retry-After'));
        self::assertSame(200, $this->request('/v1/roles', [])->getStatusCode());
    }

}
