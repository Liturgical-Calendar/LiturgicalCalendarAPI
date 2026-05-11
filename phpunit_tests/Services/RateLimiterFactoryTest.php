<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Services;

use LiturgicalCalendar\Api\Services\RateLimiter;
use LiturgicalCalendar\Api\Services\RateLimiterFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RateLimiterFactory::class)]
final class RateLimiterFactoryTest extends TestCase
{
    /** @var array<string,mixed> */
    private array $savedEnv = [];

    private const UNSET = "\0__unset__\0";

    /**
     * Each test gets a unique identifier so concurrent runs of this class
     * don't poison each other's storage path.
     */
    private function identifier(): string
    {
        return self::class . '|' . $this->name() . '|' . getmypid();
    }

    protected function setUp(): void
    {
        foreach (['RATE_LIMIT_LOGIN_ATTEMPTS', 'RATE_LIMIT_LOGIN_WINDOW', 'RATE_LIMIT_STORAGE_PATH'] as $k) {
            $this->savedEnv[$k] = array_key_exists($k, $_ENV) ? $_ENV[$k] : self::UNSET;
            unset($_ENV[$k]);
        }
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

    public function testDefaultsAppliedWhenNoEnvSet(): void
    {
        $limiter = RateLimiterFactory::fromEnv();
        self::assertInstanceOf(RateLimiter::class, $limiter);
        $limiter->clearAttempts($this->identifier());
        // Default is 5 attempts; 5 failures must trigger the limit on the 6th.
        for ($i = 0; $i < 5; $i++) {
            $limiter->recordFailedAttempt($this->identifier());
        }
        self::assertTrue($limiter->isRateLimited($this->identifier()));
    }

    public function testAttemptsCapIsRespected(): void
    {
        $_ENV['RATE_LIMIT_LOGIN_ATTEMPTS'] = '2';
        $limiter                           = RateLimiterFactory::fromEnv();

        $limiter->clearAttempts($this->identifier());
        $limiter->recordFailedAttempt($this->identifier());
        self::assertFalse($limiter->isRateLimited($this->identifier()));
        $limiter->recordFailedAttempt($this->identifier());
        self::assertTrue($limiter->isRateLimited($this->identifier()));
    }

    public function testNonNumericAttemptsFallsBackToDefault(): void
    {
        $_ENV['RATE_LIMIT_LOGIN_ATTEMPTS'] = 'maybe-five';
        // Non-numeric is silently ignored; the limiter should still construct.
        $limiter = RateLimiterFactory::fromEnv();
        self::assertInstanceOf(RateLimiter::class, $limiter);
    }

    public function testWindowSecondsBelowMinimumIsClampedUp(): void
    {
        $_ENV['RATE_LIMIT_LOGIN_WINDOW'] = '10'; // factory enforces a 60-second floor
        $limiter                         = RateLimiterFactory::fromEnv();
        self::assertInstanceOf(RateLimiter::class, $limiter);
    }

    public function testCustomStoragePathIsUsed(): void
    {
        $dir = sys_get_temp_dir() . '/litcal_test_ratelimit_factory_' . bin2hex(random_bytes(4));
        try {
            $_ENV['RATE_LIMIT_STORAGE_PATH'] = $dir;
            $limiter                         = RateLimiterFactory::fromEnv();
            $limiter->clearAttempts('probe');
            $limiter->recordFailedAttempt('probe');
            // RateLimiter creates a litcal_rate_limits subdirectory and one
            // file per identifier inside it.
            self::assertDirectoryExists($dir . '/litcal_rate_limits');
        } finally {
            $files = glob($dir . '/litcal_rate_limits/*');
            foreach ($files !== false ? $files : [] as $f) {
                if (is_file($f)) {
                    unlink($f);
                }
            }
            if (is_dir($dir . '/litcal_rate_limits')) {
                rmdir($dir . '/litcal_rate_limits');
            }
            if (is_dir($dir)) {
                rmdir($dir);
            }
        }
    }

    public function testEmptyStoragePathFallsBackToDefault(): void
    {
        $_ENV['RATE_LIMIT_STORAGE_PATH'] = '';
        $limiter                         = RateLimiterFactory::fromEnv();
        self::assertInstanceOf(RateLimiter::class, $limiter);
    }
}
