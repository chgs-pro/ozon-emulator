<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Feature\Token\Command\IssueToken\IssueTokenCommand;
use App\Feature\Token\Command\IssueToken\IssueTokenHandler;
use App\Feature\Token\TokenAuthenticator;
use App\Feature\Token\TokenRecord;
use App\Feature\Token\TokenRepositoryInterface;
use DateTimeImmutable;
use InvalidArgumentException;
use PhpSoftBox\Api\Description\ApiDescription;
use PhpSoftBox\Auth\Token\CredentialCodec;
use PhpSoftBox\Clock\FrozenClock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function explode;
use function strlen;

use const PHP_INT_MAX;

#[CoversClass(IssueTokenHandler::class)]
#[CoversClass(TokenAuthenticator::class)]
#[CoversMethod(IssueTokenHandler::class, 'handle')]
#[CoversMethod(TokenAuthenticator::class, 'authenticate')]
final class TokenTest extends TestCase
{
    /** Verify issued keys are stored hashed and accepted for their client.
     * @see IssueTokenHandler::handle()
     * @see TokenAuthenticator::authenticate()
     */
    #[Test]
    public function issuePersistsOnlyHashAndAuthenticatesOwner(): void
    {
        $codec = new CredentialCodec();
        $store = $this->createMock(TokenRepositoryInterface::class);
        $saved = null;
        $store->expects(self::once())->method('insert')->willReturnCallback(static function (TokenRecord $token) use (&$saved): void {
            $saved = $token;
        });
        $api   = ApiDescription::create('ozon-seller')->build();
        $clock = new FrozenClock(new DateTimeImmutable('2026-09-15T00:00:00Z'));

        $issued = new IssueTokenHandler($store, $codec, $api, $clock)->handle(new IssueTokenCommand('1001', 2));
        self::assertInstanceOf(TokenRecord::class, $saved);
        self::assertNotSame($issued->apiKey, $saved->secretHash);
        self::assertSame(64, strlen($saved->secretHash));
        self::assertSame($clock->now()->getTimestamp() + 172800, $issued->expiresAt);
        $store->method('find')->willReturn($saved);
        self::assertSame('1001', new TokenAuthenticator($store, $codec, $api, $clock)->authenticate('1001', $issued->apiKey)?->clientId);
    }

    /** Reject another test cabinet even with a valid key.
     * @see TokenAuthenticator::authenticate()
     */
    #[Test]
    public function authenticateRejectsDifferentClient(): void
    {
        [$auth, $key] = $this->authenticator();
        self::assertNull($auth->authenticate('2002', $key));
    }

    /** Reject keys at the expiration boundary.
     * @see TokenAuthenticator::authenticate()
     */
    #[Test]
    public function authenticateRejectsExpiredKey(): void
    {
        [$auth, $key] = $this->authenticator(expiresAt: 0);
        self::assertNull($auth->authenticate('1001', $key));
    }

    /** Reject credentials from another API audience.
     * @see TokenAuthenticator::authenticate()
     */
    #[Test]
    public function authenticateRejectsDifferentAudience(): void
    {
        [$auth, $key] = $this->authenticator(audience: 'another-api');
        self::assertNull($auth->authenticate('1001', $key));
    }

    /** Reject a changed secret while preserving the valid selector.
     * @see TokenAuthenticator::authenticate()
     */
    #[Test]
    public function authenticateRejectsWrongSecret(): void
    {
        [$auth, $key] = $this->authenticator();
        self::assertNull($auth->authenticate('1001', explode('.', $key)[0] . '.wrong'));
    }

    /** Malformed credentials must not query storage.
     * @see TokenAuthenticator::authenticate()
     */
    #[Test]
    public function authenticateRejectsMalformedCredentialBeforeStorage(): void
    {
        $store = $this->createMock(TokenRepositoryInterface::class);
        $store->expects(self::never())->method('find');
        $auth = new TokenAuthenticator($store, new CredentialCodec(), ApiDescription::create('ozon-seller')->build(), new FrozenClock(new DateTimeImmutable()));

        self::assertNull($auth->authenticate('1001', 'invalid'));
    }

    /** Invalid client IDs cannot issue persistent credentials.
     * @see IssueTokenHandler::handle()
     */
    #[Test]
    public function issueRejectsInvalidClientBeforeWrite(): void
    {
        $store = $this->createMock(TokenRepositoryInterface::class);
        $store->expects(self::never())->method('insert');
        $handler = new IssueTokenHandler($store, new CredentialCodec(), ApiDescription::create('ozon-seller')->build(), new FrozenClock(new DateTimeImmutable()));
        $this->expectException(InvalidArgumentException::class);
        $handler->handle(new IssueTokenCommand('../1001'));
    }

    /** Invalid lifetime cannot issue persistent credentials.
     * @see IssueTokenHandler::handle()
     */
    #[Test]
    public function issueRejectsInvalidLifetimeBeforeWrite(): void
    {
        $store = $this->createMock(TokenRepositoryInterface::class);
        $store->expects(self::never())->method('insert');
        $handler = new IssueTokenHandler($store, new CredentialCodec(), ApiDescription::create('ozon-seller')->build(), new FrozenClock(new DateTimeImmutable()));
        $this->expectException(InvalidArgumentException::class);
        $handler->handle(new IssueTokenCommand('1001', 0));
    }

    /** @return array{TokenAuthenticator, string} */
    private function authenticator(string $audience = 'ozon-seller', int $expiresAt = PHP_INT_MAX): array
    {
        $codec = new CredentialCodec();

        $credential = $codec->generate();
        $store      = $this->createMock(TokenRepositoryInterface::class);
        $store->method('find')->willReturn(new TokenRecord($credential->selector, '1001', $credential->secretHash, $audience, 0, $expiresAt));

        return [new TokenAuthenticator($store, $codec, ApiDescription::create('ozon-seller')->build(), new FrozenClock(new DateTimeImmutable('2026-09-15T00:00:00Z'))), $credential->token];
    }
}
