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
     * Stable mapping from `test*` method name → unique synthetic client IP
     * in RFC 5737 TEST-NET-1 (192.0.2.0/24). Built once per class run via
     * reflection: all public methods starting with `test` are enumerated,
     * sorted alphabetically for determinism, and assigned consecutive
     * octets starting from a per-run random offset. Each method ends up
     * on its own dedicated bucket, so within-class birthday collisions
     * become structurally impossible — fixing the residual ~4% flake the
     * prior `crc32 % 254` scheme retained.
     *
     * Per-run rotation: the random offset varies on every class run
     * (host or CI, PID 1 or otherwise), so a bucket exhausted by one
     * run is unlikely to be hit by the next within the 15-minute
     * rate-limit window — same inter-run-isolation property the prior
     * `runSeed` provided, just driving the IP space directly instead
     * of via hash.
     *
     * Cross-class IP overlap with CalendarTest (100-199) or ApiTestCase
     * (200-254) is harmless: the rate limiter SHA-256s its identifier
     * (`src/Services/RateLimiter.php:70`) and `LoginHandler` records
     * attempts under the raw IP, while `ApiKeyRateLimitMiddleware`
     * prefixes with `ip_` (`src/Http/Middleware/ApiKeyRateLimitMiddleware.php:65`)
     * — so `192.0.2.150` under login and `192.0.2.150` under calendar
     * are different bucket files regardless of overlap.
     *
     * DataProvider note: `$this->name()` returns the bare method name in
     * PHPUnit 12+ (data set qualifiers are exposed via `nameWithDataSet()`),
     * so all rows of a parameterized test share their method's slot. None
     * of this file's tests are parameterized today.
     *
     * Capacity: 254 candidate octets. Beyond 254 test methods the modulus
     * wraps and birthday collisions re-emerge; this class is well under
     * that limit (5 methods at last count).
     *
     * @var array<string, string>|null
     */
    private static ?array $methodToIp = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (self::$methodToIp === null) {
            $reflection  = new \ReflectionClass(static::class);
            $methodNames = [];
            foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                if (str_starts_with($method->getName(), 'test')) {
                    $methodNames[] = $method->getName();
                }
            }
            sort($methodNames);

            $offset           = random_int(0, 253);
            self::$methodToIp = [];
            foreach ($methodNames as $i => $name) {
                self::$methodToIp[$name] = '192.0.2.' . ( ( ( $offset + $i ) % 254 ) + 1 );
            }
        }

        // Defensive fallback for any name not in the map (e.g. a
        // DataProvider variant the bare-name lookup misses, or a method
        // added at runtime via reflection in a future test). 192.0.2.1
        // is a single shared bucket — acceptable for the safety net,
        // not the happy path.
        $this->testIp = self::$methodToIp[$this->name()] ?? '192.0.2.1';
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
     * Read RATE_LIMIT_LOGIN_ATTEMPTS from $_ENV with safe coercion.
     *
     * `$_ENV[...]` is typed as mixed; raw `(int) (...)` silently coerces
     * arrays or objects to 1 / throws respectively, which PHPStan L10
     * rightly rejects. Gate the cast on `is_numeric()` so anything else
     * falls back to the documented default of 5.
     */
    private function loginRateLimit(): int
    {
        $raw = $_ENV['RATE_LIMIT_LOGIN_ATTEMPTS'] ?? null;
        return is_numeric($raw) ? (int) $raw : 5;
    }

    /**
     * Exhaust the rate limit by making failed login attempts up to the configured maximum.
     *
     * @return \Psr\Http\Message\ResponseInterface The rate-limited (429) response after exceeding the limit.
     */
    /**
     * Non-nullable accessor for the shared HTTP client.
     *
     * `ApiTestCase::$http` is declared `?Client` and initialised in
     * `setUpBeforeClass`, but PHPStan can't carry that narrowing across
     * the static-property boundary into every call site. Routing all
     * test-class access through one strongly-typed getter satisfies the
     * type checker without sprinkling `\assert` everywhere, and gives a
     * clean fail-fast when somebody calls the API before the base class
     * has had a chance to build the client.
     */
    private static function http(): \GuzzleHttp\Client
    {
        if (self::$http === null) {
            throw new \LogicException(
                'ApiTestCase::$http not initialised — ApiTestCase::setUpBeforeClass must run first.'
            );
        }
        return self::$http;
    }

    private function exhaustRateLimit(): \Psr\Http\Message\ResponseInterface
    {
        $maxAttempts = $this->loginRateLimit();

        for ($i = 0; $i < $maxAttempts; $i++) {
            $response = self::http()->post('/auth/login', [
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
        return self::http()->post('/auth/login', [
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
        $response = self::http()->post('/auth/login', [
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
        $maxAttempts = $this->loginRateLimit();

        // Make failed login attempts up to the limit
        for ($i = 0; $i < $maxAttempts; $i++) {
            $response = self::http()->post('/auth/login', [
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
        $response = self::http()->post('/auth/login', [
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
        $maxAttempts = $this->loginRateLimit();

        // Make some failed attempts (but not enough to trigger rate limiting)
        $failedAttempts = max(1, $maxAttempts - 2);
        for ($i = 0; $i < $failedAttempts; $i++) {
            $response = self::http()->post('/auth/login', [
                'headers' => $this->loginHeaders(),
                'json'    => [
                    'username' => 'admin',
                    'password' => 'wrong-password-clear-test-' . $i
                ]
            ]);
            $this->assertEquals(401, $response->getStatusCode());
        }

        // Now login successfully
        $response = self::http()->post('/auth/login', [
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
            $response = self::http()->post('/auth/login', [
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
