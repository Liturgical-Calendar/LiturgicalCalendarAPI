<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Services;

use LiturgicalCalendar\Api\Services\JwtServiceFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(JwtServiceFactory::class)]
final class JwtServiceFactoryTest extends TestCase
{
    private const VALID_SECRET = 'test-fixture-secret-not-a-placeholder-32+';

    /** @var array<string,mixed> */
    private array $savedEnv = [];

    private const UNSET = "\0__unset__\0";

    protected function setUp(): void
    {
        foreach (['JWT_SECRET', 'JWT_ALGORITHM', 'JWT_EXPIRY', 'JWT_REFRESH_EXPIRY', 'APP_ENV'] as $k) {
            $this->savedEnv[$k] = array_key_exists($k, $_ENV) ? $_ENV[$k] : self::UNSET;
            unset($_ENV[$k]);
        }
        $_ENV['APP_ENV'] = 'test';
    }

    protected function tearDown(): void
    {
        foreach ($this->savedEnv as $k => $v) {
            if ($v === self::UNSET) {
                unset($_ENV[$k]);
            } else {
                $_ENV[$k] = $v;
            }
        }
    }

    public function testMissingSecretThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('JWT_SECRET');
        JwtServiceFactory::fromEnv();
    }

    public function testEmptySecretThrows(): void
    {
        $_ENV['JWT_SECRET'] = '';
        $this->expectException(\RuntimeException::class);
        JwtServiceFactory::fromEnv();
    }

    public function testShortSecretThrows(): void
    {
        $_ENV['JWT_SECRET'] = 'too-short';
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('at least 32');
        JwtServiceFactory::fromEnv();
    }

    public function testValidEnvProducesService(): void
    {
        $_ENV['JWT_SECRET'] = self::VALID_SECRET;
        $svc                = JwtServiceFactory::fromEnv();
        self::assertSame(3600, $svc->getExpiry());
        self::assertSame(604800, $svc->getRefreshExpiry());
    }

    public function testAlgorithmEnvIsValidated(): void
    {
        $_ENV['JWT_SECRET']    = self::VALID_SECRET;
        $_ENV['JWT_ALGORITHM'] = 'RS256';
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('JWT_ALGORITHM');
        JwtServiceFactory::fromEnv();
    }

    public function testNonNumericExpiryIsRejected(): void
    {
        $_ENV['JWT_SECRET'] = self::VALID_SECRET;
        $_ENV['JWT_EXPIRY'] = 'forever';
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('JWT_EXPIRY');
        JwtServiceFactory::fromEnv();
    }

    public function testNonPositiveExpiryIsRejected(): void
    {
        $_ENV['JWT_SECRET'] = self::VALID_SECRET;
        $_ENV['JWT_EXPIRY'] = '0';
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('positive');
        JwtServiceFactory::fromEnv();
    }

    public function testNonNumericRefreshExpiryIsRejected(): void
    {
        $_ENV['JWT_SECRET']         = self::VALID_SECRET;
        $_ENV['JWT_REFRESH_EXPIRY'] = 'soon';
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('JWT_REFRESH_EXPIRY');
        JwtServiceFactory::fromEnv();
    }

    public function testNonPositiveRefreshExpiryIsRejected(): void
    {
        $_ENV['JWT_SECRET']         = self::VALID_SECRET;
        $_ENV['JWT_REFRESH_EXPIRY'] = '-5';
        $this->expectException(\RuntimeException::class);
        JwtServiceFactory::fromEnv();
    }

    public function testCustomExpiryAndAlgorithmAreApplied(): void
    {
        $_ENV['JWT_SECRET']         = self::VALID_SECRET;
        $_ENV['JWT_ALGORITHM']      = 'HS384';
        $_ENV['JWT_EXPIRY']         = '900';
        $_ENV['JWT_REFRESH_EXPIRY'] = '86400';

        $svc = JwtServiceFactory::fromEnv();
        self::assertSame(900, $svc->getExpiry());
        self::assertSame(86400, $svc->getRefreshExpiry());
    }

    public function testPlaceholderSecretRejectedInProduction(): void
    {
        $_ENV['APP_ENV'] = 'production';
        // 32+ chars but obviously a placeholder.
        $_ENV['JWT_SECRET'] = 'change-this-please-its-the-default-x';
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('placeholder');
        JwtServiceFactory::fromEnv();
    }

    public function testPlaceholderSecretAllowedInDevelopment(): void
    {
        $_ENV['APP_ENV']    = 'development';
        $_ENV['JWT_SECRET'] = 'change-this-please-its-the-default-x';
        // Should not throw; the placeholder check is production-only.
        $svc = JwtServiceFactory::fromEnv();
        self::assertSame(3600, $svc->getExpiry());
    }
}
