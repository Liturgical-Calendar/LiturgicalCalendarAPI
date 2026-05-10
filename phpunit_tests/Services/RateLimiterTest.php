<?php

namespace LiturgicalCalendar\Tests\Services;

use LiturgicalCalendar\Api\Services\RateLimiter;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the RateLimiter service
 *
 * Note: This test extends TestCase (not ApiTestCase) because it is a pure unit test
 * that tests the RateLimiter service in isolation without requiring a running API server.
 * ApiTestCase is reserved for integration tests that make HTTP requests to the API.
 */
class RateLimiterTest extends TestCase
{
    private string $testStoragePath;
    private RateLimiter $rateLimiter;

    protected function setUp(): void
    {
        parent::setUp();

        // Use a unique temp directory for each test
        $this->testStoragePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'litcal_test_' . uniqid();
        mkdir($this->testStoragePath, 0755, true);

        // Create rate limiter with 3 attempts in 60 seconds for faster testing
        $this->rateLimiter = new RateLimiter(3, 60, $this->testStoragePath);
    }

    protected function tearDown(): void
    {
        // Clean up test directory
        $this->removeDirectory($this->testStoragePath);

        parent::tearDown();
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir) ?: [], ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . DIRECTORY_SEPARATOR . $file;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }

    public function testNewIdentifierIsNotRateLimited(): void
    {
        $this->assertFalse($this->rateLimiter->isRateLimited('192.168.1.1'));
    }

    public function testRemainingAttemptsStartsAtMax(): void
    {
        $this->assertEquals(3, $this->rateLimiter->getRemainingAttempts('192.168.1.1'));
    }

    public function testRecordingFailedAttemptsDecreasesRemaining(): void
    {
        $ip = '192.168.1.2';

        $this->assertEquals(3, $this->rateLimiter->getRemainingAttempts($ip));

        $this->rateLimiter->recordFailedAttempt($ip);
        $this->assertEquals(2, $this->rateLimiter->getRemainingAttempts($ip));

        $this->rateLimiter->recordFailedAttempt($ip);
        $this->assertEquals(1, $this->rateLimiter->getRemainingAttempts($ip));
    }

    public function testRateLimitedAfterMaxAttempts(): void
    {
        $ip = '192.168.1.3';

        // Record max attempts
        $this->rateLimiter->recordFailedAttempt($ip);
        $this->rateLimiter->recordFailedAttempt($ip);
        $this->rateLimiter->recordFailedAttempt($ip);

        $this->assertTrue($this->rateLimiter->isRateLimited($ip));
        $this->assertEquals(0, $this->rateLimiter->getRemainingAttempts($ip));
    }

    public function testRetryAfterIsPositiveWhenRateLimited(): void
    {
        $ip = '192.168.1.4';

        // Record max attempts
        for ($i = 0; $i < 3; $i++) {
            $this->rateLimiter->recordFailedAttempt($ip);
        }

        $retryAfter = $this->rateLimiter->getRetryAfter($ip);

        $this->assertGreaterThan(0, $retryAfter);
        $this->assertLessThanOrEqual(60, $retryAfter);
    }

    public function testRetryAfterIsZeroWhenNotRateLimited(): void
    {
        $ip = '192.168.1.5';

        // Only one failed attempt
        $this->rateLimiter->recordFailedAttempt($ip);

        $this->assertEquals(0, $this->rateLimiter->getRetryAfter($ip));
    }

    public function testClearAttemptsResetsRateLimit(): void
    {
        $ip = '192.168.1.6';

        // Rate limit the IP
        for ($i = 0; $i < 3; $i++) {
            $this->rateLimiter->recordFailedAttempt($ip);
        }

        $this->assertTrue($this->rateLimiter->isRateLimited($ip));

        // Clear attempts
        $this->rateLimiter->clearAttempts($ip);

        $this->assertFalse($this->rateLimiter->isRateLimited($ip));
        $this->assertEquals(3, $this->rateLimiter->getRemainingAttempts($ip));
    }

    public function testDifferentIpsAreTrackedSeparately(): void
    {
        $ip1 = '192.168.1.10';
        $ip2 = '192.168.1.11';

        // Rate limit ip1
        for ($i = 0; $i < 3; $i++) {
            $this->rateLimiter->recordFailedAttempt($ip1);
        }

        $this->assertTrue($this->rateLimiter->isRateLimited($ip1));
        $this->assertFalse($this->rateLimiter->isRateLimited($ip2));
        $this->assertEquals(3, $this->rateLimiter->getRemainingAttempts($ip2));
    }

    public function testGetMaxAttempts(): void
    {
        $this->assertEquals(3, $this->rateLimiter->getMaxAttempts());
    }

    public function testGetWindowSeconds(): void
    {
        $this->assertEquals(60, $this->rateLimiter->getWindowSeconds());
    }

    /**
     * @group slow
     */
    public function testCleanupRemovesStaleFiles(): void
    {
        $ip = '192.168.1.20';

        // Record an attempt
        $this->rateLimiter->recordFailedAttempt($ip);

        // Create a new limiter with a very short window (1 second)
        $shortWindowLimiter = new RateLimiter(3, 1, $this->testStoragePath);

        // Wait for the window to expire
        sleep(2);

        // Cleanup should remove the stale file
        $cleaned = $shortWindowLimiter->cleanup();

        // At least one stale file (for this IP) should have been removed
        $this->assertGreaterThanOrEqual(1, $cleaned);
    }

    public function testHandlesSpecialCharactersInIdentifier(): void
    {
        // IPv6 addresses contain colons
        $ipv6 = '2001:0db8:85a3:0000:0000:8a2e:0370:7334';

        $this->assertFalse($this->rateLimiter->isRateLimited($ipv6));

        $this->rateLimiter->recordFailedAttempt($ipv6);
        $this->assertEquals(2, $this->rateLimiter->getRemainingAttempts($ipv6));
    }

    /**
     * Helper to save current RATE_LIMIT_* env values
     *
     * @return array{attempts: mixed, window: mixed, storage: mixed}
     */
    private function saveEnvValues(): array
    {
        return [
            'attempts' => $_ENV['RATE_LIMIT_LOGIN_ATTEMPTS'] ?? null,
            'window'   => $_ENV['RATE_LIMIT_LOGIN_WINDOW'] ?? null,
            'storage'  => $_ENV['RATE_LIMIT_STORAGE_PATH'] ?? null,
        ];
    }

    /**
     * Helper to restore RATE_LIMIT_* env values
     *
     * @param array{attempts: mixed, window: mixed, storage: mixed} $saved
     */
    private function restoreEnvValues(array $saved): void
    {
        if ($saved['attempts'] === null) {
            unset($_ENV['RATE_LIMIT_LOGIN_ATTEMPTS']);
        } else {
            $_ENV['RATE_LIMIT_LOGIN_ATTEMPTS'] = $saved['attempts'];
        }

        if ($saved['window'] === null) {
            unset($_ENV['RATE_LIMIT_LOGIN_WINDOW']);
        } else {
            $_ENV['RATE_LIMIT_LOGIN_WINDOW'] = $saved['window'];
        }

        if ($saved['storage'] === null) {
            unset($_ENV['RATE_LIMIT_STORAGE_PATH']);
        } else {
            $_ENV['RATE_LIMIT_STORAGE_PATH'] = $saved['storage'];
        }
    }

    public function testFactoryCreatesFromEnv(): void
    {
        $saved = $this->saveEnvValues();

        try {
            // Ensure clean environment for testing defaults
            unset($_ENV['RATE_LIMIT_LOGIN_ATTEMPTS']);
            unset($_ENV['RATE_LIMIT_LOGIN_WINDOW']);
            unset($_ENV['RATE_LIMIT_STORAGE_PATH']);

            // Test that the factory can create an instance
            $limiter = \LiturgicalCalendar\Api\Services\RateLimiterFactory::fromEnv();

            $this->assertInstanceOf(RateLimiter::class, $limiter);

            // Default values
            $this->assertEquals(5, $limiter->getMaxAttempts());
            $this->assertEquals(900, $limiter->getWindowSeconds());
        } finally {
            $this->restoreEnvValues($saved);
        }
    }

    public function testFactoryRespectsEnvVariables(): void
    {
        $saved = $this->saveEnvValues();

        try {
            // Set environment variables
            $_ENV['RATE_LIMIT_LOGIN_ATTEMPTS'] = '10';
            $_ENV['RATE_LIMIT_LOGIN_WINDOW']   = '300';

            $limiter = \LiturgicalCalendar\Api\Services\RateLimiterFactory::fromEnv();

            $this->assertEquals(10, $limiter->getMaxAttempts());
            $this->assertEquals(300, $limiter->getWindowSeconds());
        } finally {
            $this->restoreEnvValues($saved);
        }
    }

    public function testFactoryRespectsStoragePath(): void
    {
        $saved = $this->saveEnvValues();

        // Use a custom storage path
        $customPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'litcal_factory_test_' . uniqid();
        mkdir($customPath, 0755, true);

        try {
            $_ENV['RATE_LIMIT_STORAGE_PATH'] = $customPath;

            $limiter = \LiturgicalCalendar\Api\Services\RateLimiterFactory::fromEnv();

            // Record an attempt and verify the file is created in the custom path
            $limiter->recordFailedAttempt('test-ip');

            $rateLimitDir = $customPath . DIRECTORY_SEPARATOR . 'litcal_rate_limits';
            $this->assertDirectoryExists($rateLimitDir);

            $files = glob($rateLimitDir . DIRECTORY_SEPARATOR . '*.json');
            $this->assertNotEmpty($files, 'Expected rate limit file to be created in custom storage path');
        } finally {
            $this->restoreEnvValues($saved);
            $this->removeDirectory($customPath);
        }
    }

    public function testFactoryClampsWindowToMinimum(): void
    {
        $saved = $this->saveEnvValues();

        try {
            // Set window below the 60-second minimum
            $_ENV['RATE_LIMIT_LOGIN_WINDOW'] = '30';

            $limiter = \LiturgicalCalendar\Api\Services\RateLimiterFactory::fromEnv();

            // Should be clamped to minimum of 60 seconds
            $this->assertEquals(60, $limiter->getWindowSeconds());
        } finally {
            $this->restoreEnvValues($saved);
        }
    }

    public function testRecordRequestAndGetCountRespectsCapWithOverflow(): void
    {
        $identifier = 'apikey_test-cap';
        $cap        = 3;

        // Requests 1-3: under cap, all recorded
        for ($i = 1; $i <= $cap; $i++) {
            $count = $this->rateLimiter->recordRequestAndGetCount($identifier, $cap);
            $this->assertEquals($i, $count, "Request {$i} should return count {$i}");
        }

        // Request 4: at cap, one overflow recorded (count becomes cap+1)
        $count = $this->rateLimiter->recordRequestAndGetCount($identifier, $cap);
        $this->assertEquals($cap + 1, $count, 'Overflow request should return cap+1');

        // Request 5: beyond overflow, not recorded, count stays at cap+1
        $count = $this->rateLimiter->recordRequestAndGetCount($identifier, $cap);
        $this->assertEquals($cap + 1, $count, 'Further requests should not increase count beyond cap+1');
    }

    public function testRecordRequestAndGetCountTriggersRateLimit(): void
    {
        $identifier = 'apikey_test-trigger';
        $limit      = 3;

        // Requests 1-3: within limit, remaining >= 0
        for ($i = 1; $i <= $limit; $i++) {
            $count     = $this->rateLimiter->recordRequestAndGetCount($identifier, $limit);
            $remaining = $limit - $count;
            $this->assertGreaterThanOrEqual(0, $remaining, "Request {$i} should not exceed limit");
        }

        // Request 4: overflow triggers rate limit (remaining < 0)
        $count     = $this->rateLimiter->recordRequestAndGetCount($identifier, $limit);
        $remaining = $limit - $count;
        $this->assertLessThan(0, $remaining, 'Request after limit should trigger rate limiting');
    }

    public function testGetWindowResetTimeReturnsCorrectTimestamp(): void
    {
        $identifier = 'test-reset-time';

        // No attempts: reset time should be between before and after
        $before    = time();
        $resetTime = $this->rateLimiter->getWindowResetTime($identifier);
        $after     = time();
        $this->assertGreaterThanOrEqual($before, $resetTime);
        $this->assertLessThanOrEqual($after, $resetTime);

        // Record an attempt
        $before = time();
        $this->rateLimiter->recordRequest($identifier);
        $after = time();

        // Reset time should be between (before + window) and (after + window)
        $resetTime = $this->rateLimiter->getWindowResetTime($identifier);
        $this->assertGreaterThanOrEqual($before + 60, $resetTime);
        $this->assertLessThanOrEqual($after + 60, $resetTime);
    }

    public function testGetAttemptCountReturnsCorrectCount(): void
    {
        $identifier = 'test-count';

        $this->assertEquals(0, $this->rateLimiter->getAttemptCount($identifier));

        $this->rateLimiter->recordRequest($identifier);
        $this->assertEquals(1, $this->rateLimiter->getAttemptCount($identifier));

        $this->rateLimiter->recordRequest($identifier);
        $this->assertEquals(2, $this->rateLimiter->getAttemptCount($identifier));
    }

    public function testFactoryClampsAttemptsToMinimum(): void
    {
        $saved = $this->saveEnvValues();

        try {
            // Set attempts to 0 (should be clamped to 1)
            $_ENV['RATE_LIMIT_LOGIN_ATTEMPTS'] = '0';

            $limiter = \LiturgicalCalendar\Api\Services\RateLimiterFactory::fromEnv();

            // Should be clamped to minimum of 1
            $this->assertEquals(1, $limiter->getMaxAttempts());
        } finally {
            $this->restoreEnvValues($saved);
        }
    }
}
