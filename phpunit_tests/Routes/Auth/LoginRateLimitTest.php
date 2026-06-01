<?php

namespace LiturgicalCalendar\Tests\Routes\Auth;

use LiturgicalCalendar\Tests\ApiTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Integration tests for login endpoint rate limiting
 *
 * These tests verify that the /auth/login endpoint properly enforces rate limiting
 * to protect against brute-force attacks.
 */
#[Group('slow')]
class LoginRateLimitTest extends ApiTestCase
{
    private string $testIp = '';

    /**
     * Each test method gets its own synthetic client IP from RFC 5737 TEST-NET-1
     * (192.0.2.0/24). The login rate limiter keys on client IP, so per-method
     * isolation gives every test a fresh budget — no shared state across tests,
     * across full-suite reruns within the 15-minute window, or between the host
     * test runner and a containerized API. The earlier reset-by-filesystem-delete
     * approach didn't work in containerized setups.
     *
     * The hash mixes in `getmypid()` so the same test method picks a different
     * IP on each PHP process. A bucket exhausted by one composer-test run no
     * longer 429s the next run — the next run uses a different IP. (Within a
     * single run, the PID is stable, so each test method's IP stays consistent
     * across its own setUp/exhaustRateLimit/assertion calls.) Hashes still mod
     * by 99 so the resulting octet stays in the 1-99 reservation; rare
     * collisions across PID boundaries reduce the rerun-failure rate from
     * 100% to ~1%.
     *
     * Octet range 1-99 is reserved for per-method IPs so they never collide
     * with CalendarTest's bucket-rotation range (100-199) or ApiTestCase's
     * per-class range (200-254).
     */
    protected function setUp(): void
    {
        parent::setUp();
        $hash         = crc32($this->name() . '|' . getmypid());
        $this->testIp = '192.0.2.' . ( ( abs($hash) % 99 ) + 1 );
    }

    /**
     * Headers for /auth/login requests, including the per-test X-Forwarded-For
     * that ClientIpTrait::getClientIp() honours.
     *
     * @return array<string, string>
     */
    private function loginHeaders(): array
    {
        return [
            'X-Forwarded-For' => $this->testIp,
            'Content-Type'    => 'application/json',
            'Accept'          => 'application/json',
        ];
    }

    /**
     * Exhaust the rate limit by making failed login attempts up to the configured maximum.
     *
     * @return \Psr\Http\Message\ResponseInterface The rate-limited (429) response after exceeding the limit.
     */
    private function exhaustRateLimit(): \Psr\Http\Message\ResponseInterface
    {
        $maxAttempts = (int) ( $_ENV['RATE_LIMIT_LOGIN_ATTEMPTS'] ?? 5 );

        for ($i = 0; $i < $maxAttempts; $i++) {
            $response = self::$http->post('/auth/login', [
                'headers' => $this->loginHeaders(),
                'json'    => [
                    'username' => 'admin',
                    'password' => 'wrong-' . uniqid()
                ]
            ]);
            // Sanity check: intermediate attempts should return 401 or 429
            $this->assertContains(
                $response->getStatusCode(),
                [401, 429],
                'Failed attempt should return 401 or 429'
            );
        }

        // Return the rate-limited response
        return self::$http->post('/auth/login', [
            'headers' => $this->loginHeaders(),
            'json'    => [
                'username' => 'admin',
                'password' => 'wrong-final'
            ]
        ]);
    }

    /**
     * Test that login fails with invalid credentials.
     *
     * This is a basic test to ensure the login endpoint returns 401 for wrong passwords.
     */
    public function testLoginFailsWithInvalidCredentials(): void
    {
        $response = self::$http->post('/auth/login', [
            'headers' => $this->loginHeaders(),
            'json'    => [
                'username' => 'admin',
                'password' => 'wrong-password-' . uniqid()
            ]
        ]);

        $this->assertEquals(401, $response->getStatusCode());
    }

    /**
     * Test that rate limiting is triggered after multiple failed login attempts.
     *
     * The API is configured with RATE_LIMIT_LOGIN_ATTEMPTS (default: 5) and
     * RATE_LIMIT_LOGIN_WINDOW (default: 900 seconds). This test makes more
     * failed attempts than the limit to trigger rate limiting.
     *
     * Important: This test uses a unique "identifier" to avoid affecting other tests.
     * Since rate limiting is IP-based, we rely on the test environment configuration.
     */
    public function testRateLimitingTriggeredAfterMaxAttempts(): void
    {
        // Get the configured rate limit (default is 5)
        $maxAttempts = (int) ( $_ENV['RATE_LIMIT_LOGIN_ATTEMPTS'] ?? 5 );

        // Make failed login attempts up to the limit
        for ($i = 0; $i < $maxAttempts; $i++) {
            $response = self::$http->post('/auth/login', [
                'headers' => $this->loginHeaders(),
                'json'    => [
                    'username' => 'admin',
                    'password' => 'wrong-password-attempt-' . $i
                ]
            ]);

            // Each failed attempt should return 401 until we hit the limit
            $this->assertEquals(
                401,
                $response->getStatusCode(),
                'Expected 401 on attempt ' . ( $i + 1 ) . ' of ' . $maxAttempts
            );
        }

        // The next attempt should be rate limited (429)
        $response = self::$http->post('/auth/login', [
            'headers' => $this->loginHeaders(),
            'json'    => [
                'username' => 'admin',
                'password' => 'wrong-password-final'
            ]
        ]);

        $this->assertEquals(
            429,
            $response->getStatusCode(),
            'Expected 429 Too Many Requests after ' . $maxAttempts . ' failed attempts'
        );

        // Verify Retry-After header is present
        $this->assertTrue(
            $response->hasHeader('Retry-After'),
            'Expected Retry-After header in 429 response'
        );

        $retryAfter = $response->getHeaderLine('Retry-After');
        $this->assertGreaterThan(0, (int) $retryAfter, 'Retry-After should be a positive integer');
    }

    /**
     * Test that the 429 response body contains expected error details.
     *
     * The response should follow RFC 7807 Problem Details format.
     */
    public function testRateLimitResponseFormat(): void
    {
        $response = $this->exhaustRateLimit();

        $this->assertEquals(429, $response->getStatusCode());

        // Parse response body
        $body = (string) $response->getBody();
        $data = json_decode($body, true);

        $this->assertIsArray($data, 'Response body should be valid JSON');

        // Check RFC 7807 Problem Details fields
        $this->assertArrayHasKey('status', $data, 'Response should have status field');
        $this->assertEquals(429, $data['status']);

        $this->assertArrayHasKey('title', $data, 'Response should have title field');
        $this->assertArrayHasKey('detail', $data, 'Response should have detail field');
        $this->assertArrayHasKey('type', $data, 'Response should have type field (RFC 7807)');

        // Check for retryAfter in body (custom field)
        $this->assertArrayHasKey('retryAfter', $data, 'Response should have retryAfter field');
        $this->assertIsInt($data['retryAfter']);
        $this->assertGreaterThan(0, $data['retryAfter']);
    }

    /**
     * Test that successful login clears the rate limit.
     *
     * After a user successfully authenticates, their rate limit counter should
     * be cleared, allowing subsequent attempts.
     */
    public function testSuccessfulLoginClearsRateLimit(): void
    {
        // Get the configured rate limit (default is 5)
        $maxAttempts = (int) ( $_ENV['RATE_LIMIT_LOGIN_ATTEMPTS'] ?? 5 );

        // Make some failed attempts (but not enough to trigger rate limiting)
        $failedAttempts = max(1, $maxAttempts - 2);
        for ($i = 0; $i < $failedAttempts; $i++) {
            $response = self::$http->post('/auth/login', [
                'headers' => $this->loginHeaders(),
                'json'    => [
                    'username' => 'admin',
                    'password' => 'wrong-password-clear-test-' . $i
                ]
            ]);
            $this->assertEquals(401, $response->getStatusCode());
        }

        // Now login successfully
        $response = self::$http->post('/auth/login', [
            'headers' => $this->loginHeaders(),
            'json'    => [
                'username' => $_ENV['ADMIN_USERNAME'] ?? 'admin',
                'password' => $_ENV['ADMIN_PASSWORD'] ?? 'password'
            ]
        ]);

        $this->assertEquals(
            200,
            $response->getStatusCode(),
            'Successful login should return 200'
        );

        // Now we should be able to make failed attempts again without being rate limited
        // Make the same number of failed attempts as before
        for ($i = 0; $i < $failedAttempts; $i++) {
            $response = self::$http->post('/auth/login', [
                'headers' => $this->loginHeaders(),
                'json'    => [
                    'username' => 'admin',
                    'password' => 'wrong-password-after-clear-' . $i
                ]
            ]);

            // Should get 401 (not 429) because rate limit was cleared
            $this->assertEquals(
                401,
                $response->getStatusCode(),
                'Expected 401 (not 429) on attempt ' . ( $i + 1 ) . ' after rate limit cleared'
            );
        }
    }

    /**
     * Test that rate limiting returns proper Content-Type header.
     */
    public function testRateLimitResponseContentType(): void
    {
        $response = $this->exhaustRateLimit();

        $this->assertEquals(429, $response->getStatusCode());

        // Check Content-Type header - should be application/problem+json for RFC 7807 compliance
        $contentType = $response->getHeaderLine('Content-Type');
        $this->assertStringContainsString(
            'application/problem+json',
            $contentType,
            'Rate limit response should use application/problem+json content type'
        );
    }
}
