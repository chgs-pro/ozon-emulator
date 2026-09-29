<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Feature\Fbo\CabinetState;
use App\Feature\Fbo\Contract;
use App\Feature\Fbs\FbsCarriageService;
use App\Feature\Fbs\FbsConfig;
use App\Feature\Token\Command\IssueToken\IssueTokenCommand;
use App\Feature\Token\Command\IssueToken\IssueTokenHandler;
use App\Feature\Token\TokenRecord;
use App\Feature\Token\TokenRepositoryInterface;
use App\Http\Action\SellerAction;
use App\Tests\Support\ApplicationHttpClient;
use App\Tests\Support\FboTestCase;
use PhpSoftBox\Application\Application;
use PhpSoftBox\Http\Message\RequestFactory;
use PhpSoftBox\Http\Message\StreamFactory;
use PHPUnit\Framework\Attributes\{CoversMethod, Test};

use function dirname;
use function file_get_contents;
use function json_decode;
use function json_encode;

use const JSON_THROW_ON_ERROR;

#[CoversMethod(SellerAction::class, '__invoke')]
#[CoversMethod(Contract::class, 'fileContentType')]
final class FbsCarriageHttpTest extends FboTestCase
{
    private Application $application;
    private string $key;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configuration = json_decode(file_get_contents(dirname(__DIR__, 2) . '/fixtures/fbs-basic.json'), true, flags: JSON_THROW_ON_ERROR);
        $this->configure();
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

    /** Документы отгрузки скачиваются файлом: `act/get-pdf` отвечает самим PDF, а не JSON-описанием файла.
     * @see SellerAction::__invoke()
     */
    #[Test]
    public function answersWithThePdfOfTheShippingList(): void
    {
        $this->repository->change('1001', function (CabinetState $state): void {
            FbsConfig::of($state);
            $state->data['fbs']['carriages']['500'] = ['id' => 500, 'warehouse_id' => 1020001, 'delivery_method_id' => 1030001, 'departure_date' => '2026-09-29',
                'status'                                    => 'formed', 'postings' => ['10000001-0001-1'], 'containers_count' => 0, 'created_at' => $this->clock->timestamp,
                'updated_at'                                => $this->clock->timestamp, 'documents_ready_at' => $this->clock->timestamp - FbsCarriageService::DOCUMENTS_SECONDS];
        });
        $factory = new RequestFactory();

        $request = $factory->createRequest('POST', 'http://ozon.test/v2/posting/fbs/act/get-pdf')
                    ->withHeader('Client-Id', '1001')->withHeader('Api-Key', $this->key)->withHeader('Content-Type', 'application/json')
                    ->withBody(new StreamFactory()->createStream(json_encode(['id' => 500], JSON_THROW_ON_ERROR)));

        $response = new ApplicationHttpClient($this->application)->sendRequest($request);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('application/pdf', $response->getHeaderLine('Content-Type'));
        self::assertStringContainsString('carriage-500.pdf', $response->getHeaderLine('Content-Disposition'));
        self::assertStringStartsWith('%PDF', (string) $response->getBody());
    }
}
