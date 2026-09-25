<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Feature\Token\Command\IssueToken\IssueTokenCommand;
use App\Feature\Token\Command\IssueToken\IssueTokenHandler;
use App\Feature\Token\TokenRecord;
use App\Feature\Token\TokenRepositoryInterface;
use App\Http\Action\HealthAction;
use App\Http\Action\IdentityAction;
use App\Http\Middleware\ApiTokenMiddleware;
use PhpSoftBox\Application\Application;
use PhpSoftBox\Http\Message\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function dirname;
use function json_decode;

use const JSON_THROW_ON_ERROR;

#[CoversClass(ApiTokenMiddleware::class)]
#[CoversClass(IdentityAction::class)]
#[CoversClass(HealthAction::class)]
#[CoversMethod(ApiTokenMiddleware::class, 'process')]
#[CoversMethod(IdentityAction::class, '__invoke')]
#[CoversMethod(HealthAction::class, '__invoke')]
final class HttpTokenTest extends TestCase
{
    /** Health is JSON without browser session or cookies.
     * @see HealthAction::__invoke()
     */
    #[Test]
    public function healthReturnsJsonWithoutSession(): void
    {
        $app      = $this->application();
        $response = $app->handle(new ServerRequest('GET', '/health'));
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('application/json', $response->getHeaderLine('Content-Type'));
        self::assertFalse($response->hasHeader('Set-Cookie'));
    }

    /** Missing credentials fail before entering a protected action.
     * @see ApiTokenMiddleware::process()
     */
    #[Test]
    public function identityRejectsMissingCredentials(): void
    {
        $response = $this->application()->handle(new ServerRequest('GET', '/test/v1/identity'));
        self::assertSame(401, $response->getStatusCode());
        self::assertStringContainsString('unauthenticated', (string) $response->getBody());
    }

    /** Issued keys authorize only their client through the actual middleware pipeline.
     * @see ApiTokenMiddleware::process()
     * @see IdentityAction::__invoke()
     */
    #[Test]
    public function identityReturnsOnlyPublicCredentialIdentity(): void
    {
        $container = require dirname(__DIR__, 2) . '/config/container.php';
        $store     = $this->createMock(TokenRepositoryInterface::class);
        $saved     = null;
        $store->method('insert')->willReturnCallback(static function (TokenRecord $record) use (&$saved): void {
            $saved = $record;
        });
        // Capture by reference because issuance happens after repository registration.
        $store->method('find')->willReturnCallback(static function () use (&$saved): ?TokenRecord {
            return $saved;
        });
        $container->set(TokenRepositoryInterface::class, $store);
        $issued   = $container->get(IssueTokenHandler::class)->handle(new IssueTokenCommand('1001'));
        $app      = (require dirname(__DIR__, 2) . '/config/app.php')($container);
        $response = $app->handle(new ServerRequest('GET', '/test/v1/identity', ['Client-Id' => '1001', 'Api-Key' => $issued->apiKey]));
        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['client_id' => '1001', 'audience' => 'ozon-seller'], json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR));
        self::assertFalse($response->hasHeader('Set-Cookie'));
    }

    /** Skeleton UI and token issuance must not be exposed as HTTP routes.
     * @see HealthAction::__invoke()
     */
    #[Test]
    public function removedBrowserLoginReturnsJsonNotFound(): void
    {
        $response = $this->application()->handle(new ServerRequest('POST', '/auth/login'));
        self::assertSame(404, $response->getStatusCode());
        self::assertStringContainsString('application/json', $response->getHeaderLine('Content-Type'));
    }

    private function application(): Application
    {
        $container = require dirname(__DIR__, 2) . '/config/container.php';

        return (require dirname(__DIR__, 2) . '/config/app.php')($container);
    }
}
