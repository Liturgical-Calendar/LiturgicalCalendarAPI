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
    private ?string $savedRateLimitStoragePath  = null;
    private static ?string $rateLimitStorageDir = null;

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

        // Pin the rate-limit storage to a per-process isolated directory so
        // failed-attempt records written by these in-process unit tests can
        // never leak into the HTTP server's RateLimiter, which during CI
        // shares the same sys_get_temp_dir(). Without this, when one of
        // these tests happens to hash to an IP also used by the
        // /auth/login integration tests in phpunit_tests/Routes/Auth/, the
        // server reads a poisoned counter and the integration test trips
        // its 5-attempt budget early.
        if (self::$rateLimitStorageDir === null) {
            self::$rateLimitStorageDir = sys_get_temp_dir()
                . DIRECTORY_SEPARATOR
                . 'litcal_unit_rate_limits_'
                . getmypid()
                . '_'
                . bin2hex(random_bytes(4));
        }
        $existing                        = $_ENV['RATE_LIMIT_STORAGE_PATH'] ?? null;
        $this->savedRateLimitStoragePath = is_string($existing) ? $existing : null;
        $_ENV['RATE_LIMIT_STORAGE_PATH'] = self::$rateLimitStorageDir;

        // Clear any rate-limit state for this test's synthetic IP so consecutive
        // runs of this class don't interfere with each other.
        RateLimiterFactory::fromEnv()->clearAttempts($this->clientIp());
    }

    protected function tearDown(): void
    {
        if ($this->savedRateLimitStoragePath === null) {
            unset($_ENV['RATE_LIMIT_STORAGE_PATH']);
        } else {
            $_ENV['RATE_LIMIT_STORAGE_PATH'] = $this->savedRateLimitStoragePath;
        }
        parent::tearDown();
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$rateLimitStorageDir !== null && is_dir(self::$rateLimitStorageDir)) {
            $litcalDir = self::$rateLimitStorageDir . DIRECTORY_SEPARATOR . 'litcal_rate_limits';
            if (is_dir($litcalDir)) {
                foreach (glob($litcalDir . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
                    @unlink($file);
                }
                @rmdir($litcalDir);
            }
            @rmdir(self::$rateLimitStorageDir);
        }
        self::$rateLimitStorageDir = null;
        parent::tearDownAfterClass();
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
        // Empty JSON body passes the Content-Type gate and reaches the
        // empty-body check inside parseBodyParams(), which throws
        // ValidationException with a specific message.
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Empty body content');
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
