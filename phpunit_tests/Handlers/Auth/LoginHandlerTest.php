<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Handlers\Auth;

use LiturgicalCalendar\Api\Handlers\Auth\LoginHandler;
use LiturgicalCalendar\Api\Http\Exception\MethodNotAllowedException;
use LiturgicalCalendar\Api\Http\Exception\TooManyRequestsException;
use LiturgicalCalendar\Api\Http\Exception\UnauthorizedException;
use LiturgicalCalendar\Api\Http\Exception\ValidationException;
use LiturgicalCalendar\Api\Services\JwtServiceFactory;
use LiturgicalCalendar\Api\Services\RateLimiterFactory;
use LiturgicalCalendar\Tests\Handlers\AbstractHandlerTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(LoginHandler::class)]
final class LoginHandlerTest extends AbstractHandlerTestCase
{
    /**
     * Each test gets a unique synthetic IP so the rate limiter doesn't carry
     * state between cases. The handler reads X-Forwarded-For when present.
     */
    private function clientIp(): string
    {
        // RFC 5737 TEST-NET-1, unique per test method via crc32 + pid.
        return '192.0.2.' . ( ( abs(crc32(__CLASS__ . '|' . $this->name() . '|' . getmypid())) % 100 ) + 1 );
    }

    protected function setUp(): void
    {
        parent::setUp();
        // Clear any rate-limit state for this test's synthetic IP so consecutive
        // runs of this class don't interfere with each other.
        RateLimiterFactory::fromEnv()->clearAttempts($this->clientIp());
    }

    public function testOptionsPreflightSucceeds(): void
    {
        $response = ( new LoginHandler() )->handle(
            $this->requestFor('OPTIONS', '/auth/login', [
                'Origin'                        => 'https://app.example.test',
                'Access-Control-Request-Method' => 'POST',
            ])
        );

        self::assertSame(204, $response->getStatusCode());
    }

    public function testGetIsMethodNotAllowed(): void
    {
        $this->expectException(MethodNotAllowedException::class);
        ( new LoginHandler() )->handle($this->requestFor('GET', '/auth/login'));
    }

    public function testMissingBodyIsValidationError(): void
    {
        $this->expectException(\Throwable::class); // ValidationException or UnsupportedMediaType
        ( new LoginHandler() )->handle($this->requestFor('POST', '/auth/login', ['Content-Type' => 'application/json'], ''));
    }

    public function testEmptyCredentialsAreRejected(): void
    {
        $this->expectException(ValidationException::class);
        ( new LoginHandler() )->handle(
            $this->requestFor('POST', '/auth/login', ['X-Forwarded-For' => $this->clientIp()], [
                'username' => '',
                'password' => '',
            ])
        );
    }

    public function testBadCredentialsAreUnauthorized(): void
    {
        $this->expectException(UnauthorizedException::class);
        ( new LoginHandler() )->handle(
            $this->requestFor('POST', '/auth/login', ['X-Forwarded-For' => $this->clientIp()], [
                'username' => 'admin',
                'password' => 'definitely-not-the-default',
            ])
        );
    }

    public function testValidCredentialsReturnTokensAndSetCookies(): void
    {
        // APP_ENV=test allows the default password 'password' without
        // ADMIN_PASSWORD_HASH being set.
        $response = ( new LoginHandler() )->handle(
            $this->requestFor('POST', '/auth/login', ['X-Forwarded-For' => $this->clientIp()], [
                'username' => 'admin',
                'password' => 'password',
            ])
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        $body = $this->decodeJsonBody($response);
        self::assertIsString($body['access_token']);
        self::assertIsString($body['refresh_token']);
        self::assertSame('Bearer', $body['token_type']);
        self::assertIsInt($body['expires_in']);

        // Verify the returned access token actually decodes.
        $payload = JwtServiceFactory::fromEnv()->verify($body['access_token']);
        self::assertNotNull($payload);

        // Both auth cookies are set.
        $setCookies = implode("\n", $response->getHeader('Set-Cookie'));
        self::assertStringContainsString('litcal_access_token=', $setCookies);
        self::assertStringContainsString('litcal_refresh_token=', $setCookies);
    }

    public function testRateLimitedAfterTooManyFailures(): void
    {
        $ip      = $this->clientIp();
        $handler = new LoginHandler();
        $limiter = RateLimiterFactory::fromEnv();

        // Default is 5 attempts; record enough failures to push past the cap
        // for this synthetic IP, then assert the next attempt is blocked.
        for ($i = 0; $i < 5; $i++) {
            $limiter->recordFailedAttempt($ip);
        }

        $this->expectException(TooManyRequestsException::class);
        $handler->handle(
            $this->requestFor('POST', '/auth/login', ['X-Forwarded-For' => $ip], [
                'username' => 'admin',
                'password' => 'password',
            ])
        );
    }
}
