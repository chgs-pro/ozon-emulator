<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Feature\Fbo\CabinetRepositoryInterface;
use App\Feature\Fbo\CabinetState;
use App\Feature\Fbo\LabelService;
use App\Feature\Fbo\MongoCabinetRepository;
use App\Feature\Fbo\SellerApiException;
use App\Feature\Fbo\SellerApiService;
use App\Tests\Support\FboTestCase;
use PhpSoftBox\MongoDb\Configurator\MongoFactory;
use PhpSoftBox\MongoDb\Connection\MongoConnectionManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\Test;
use Psr\Clock\ClockInterface;

use function bin2hex;
use function dirname;
use function getenv;
use function parse_str;
use function parse_url;
use function random_bytes;

use const PHP_URL_QUERY;

#[CoversClass(MongoCabinetRepository::class)]
#[CoversClass(SellerApiService::class)]
#[CoversMethod(MongoCabinetRepository::class, 'change')]
#[CoversMethod(SellerApiService::class, 'execute')]
final class FboMongoTest extends FboTestCase
{
    private ?MongoConnectionManager $mongo = null;
    private array $mongoConfig             = [];
    protected function setUp(): void
    {
        parent::setUp();
        $uri = getenv('OZON_TEST_MONGO_URI');
        if (!$uri) {
            self::markTestSkipped('Set OZON_TEST_MONGO_URI for isolated MongoDB tests');
        }
        $this->mongoConfig = ['uri' => $uri,'database' => 'ozon_test_' . bin2hex(random_bytes(8))];
        $this->mongo       = new MongoConnectionManager(new MongoFactory($this->mongoConfig));

        $this->repository = new MongoCabinetRepository($this->mongo);

        // Rebuild service dependencies rather than reusing a cached in-memory repository.
        $this->container = require dirname(__DIR__, 2) . '/config/container.php';
        $this->container->set(ClockInterface::class, $this->clock);
        $this->container->set(CabinetRepositoryInterface::class, $this->repository);
        $this->configure();
        $this->service = $this->container->get(SellerApiService::class);
    }
    protected function tearDown(): void
    {
        $this->mongo?->database()->drop();
    }
    /** A new application connection recovers the accepted creation after an ambiguous response.
     * @see MongoCabinetRepository::change()
     * @see SellerApiService::execute()
     */
    #[Test]
    public function recoversPendingSupplyAfterConnectionRecreation(): void
    {
        $id                           = $this->draft();
        $input                        = $this->supplyInput($id);
        $this->configuration['fault'] = ['path' => '/v2/draft/supply/create','phase' => 'after','status' => 504,'remaining' => 1];
        $this->configure();
        try {
            $this->call('/v2/draft/supply/create', $input);
            self::fail('Expected ambiguous response');
        } catch (SellerApiException $e) {
            self::assertSame(504, $e->status);
        }
        $container = require dirname(__DIR__, 2) . '/config/container.php';
        $container->set(CabinetRepositoryInterface::class, new MongoCabinetRepository(new MongoConnectionManager(new MongoFactory($this->mongoConfig))));
        $container->set(ClockInterface::class, $this->clock);
        $this->service = $container->get(SellerApiService::class);
        $this->clock->timestamp += 3;
        $status = $this->call('/v2/draft/supply/create/status', ['draft_id' => $id]);
        self::assertSame('SUCCESS', $status['status']);
        self::assertSame(['ORDER_ALREADY_CREATED'], $this->call('/v2/draft/supply/create', $input)['error_reasons']);
        self::assertCount(1, $this->call('/v3/supply-order/list', ['filter' => ['states' => []],'limit' => 100,'sort_by' => 'ORDER_CREATION'])['order_ids']);
    }
    /** A concurrent committed mutation forces CAS retry without losing either write.
     * @see MongoCabinetRepository::change()
     */
    #[Test]
    public function retriesConflictingRevisionWithoutLosingUpdate(): void
    {
        $other       = new MongoCabinetRepository(new MongoConnectionManager(new MongoFactory($this->mongoConfig)));
        $interleaved = false;
        $result      = $this->repository->change('1001', static function (CabinetState $state) use ($other, &$interleaved): int {
            if (!$interleaved) {
                $interleaved = true;
                $other->change('1001', static fn (CabinetState $s): int => $s->id());
            }

            return $state->id();
        });
        self::assertSame(100002, $result);
        self::assertSame(100002, $other->change('1001', static fn (CabinetState $s): int => $s->data['sequence']));
    }

    /** Lost responses recover cargo and PDFs from a new Mongo connection, without duplicate cargo.
     * @see MongoCabinetRepository::change()
     * @see SellerApiService::execute()
     */
    #[Test]
    public function recoversCargoAndDocumentAfterRestartAndLostResponse(): void
    {
        $id                           = $this->createOrder()['supplies'][0]['supply_id'];
        $input                        = $this->cargoInput($id);
        $this->configuration['fault'] = ['path' => '/v1/cargoes/create', 'phase' => 'after', 'status' => 504, 'remaining' => 1];
        $this->configure();
        try {
            $this->call('/v1/cargoes/create', $input);
            self::fail('Expected lost response');
        } catch (SellerApiException $error) {
            self::assertSame(504, $error->status);
        }
        $container = require dirname(__DIR__, 2) . '/config/container.php';
        $container->set(CabinetRepositoryInterface::class, new MongoCabinetRepository(new MongoConnectionManager(new MongoFactory($this->mongoConfig))));
        $container->set(ClockInterface::class, $this->clock);
        $this->service = $container->get(SellerApiService::class);
        $this->clock->timestamp += 3;
        $cargoes = $this->call('/v1/cargoes/get', ['supply_ids' => [$id]])['supply'][0]['cargoes'];
        self::assertCount(2, $cargoes);
        $retry = $this->complete('/v1/cargoes/create', $input);
        self::assertSame('FAILED', $retry['status']);
        self::assertSame($cargoes, $this->call('/v1/cargoes/get', ['supply_ids' => [$id]])['supply'][0]['cargoes']);
        $labels = $this->complete('/v1/cargoes-label/create', ['supply_id' => $id]);
        parse_str(parse_url($labels['result']['file_url'], PHP_URL_QUERY), $query);
        $pdf = $container->get(LabelService::class)->download($query['client_id'], $query['document_id'], $query['token']);
        self::assertStringStartsWith('%PDF-', $pdf);
        $snapshot = new MongoCabinetRepository(new MongoConnectionManager(new MongoFactory($this->mongoConfig)));

        self::assertCount(1, $snapshot->change('1001', static fn (CabinetState $state): array => $state->data['documents']));
    }
}
