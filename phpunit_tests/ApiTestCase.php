<?php

namespace LiturgicalCalendar\Tests;

use PHPUnit\Framework\TestCase;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\CurlMultiHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use Psr\Http\Message\RequestInterface;

abstract class ApiTestCase extends TestCase
{
    protected static bool $apiAvailable = false;

    protected static ?int $transferStats = null;

    protected static ?Client $http = null;

    protected static ?CurlMultiHandler $multiHandler = null;

    private static ?\Throwable $lastException = null;
    private static int $lastStatusCode        = 0;
    private static string $responseBody       = '';
    private static bool $preferV4             = true; // default to IPv4 unless detected otherwise
    private static string $addr               = '';
    protected static string $currentTestIp    = '192.0.2.1';

    public static function setUpBeforeClass(): void
    {
        // Per-class synthetic client IP (RFC 5737 TEST-NET-1). Each test class
        // gets its own rate-limit budget so the full suite never saturates a
        // single IP, and the host's natural IP is never used at all. Mixing in
        // getmypid() keeps consecutive `composer test` runs on different IPs,
        // so a saturated previous run doesn't carry over within the limiter's
        // window. Honoured only when APP_ENV is dev/test (see
        // ApiKeyRateLimitMiddleware::getClientIp()).
        //
        // Octet range 200-254 is reserved for per-class IPs to keep three
        // disjoint subranges within 192.0.2.0/24:
        //   1-99    : per-method IPs (LoginRateLimitTest)
        //   100-199 : bucket-rotation (CalendarTest::testGetCalendarSampleAllCalendars)
        //   200-254 : per-class default (here)
        self::$currentTestIp = '192.0.2.' . ( ( abs(crc32(static::class . '|' . getmypid())) % 55 ) + 200 );

        // Create a shared CurlMultiHandler that will persist connections
        self::$multiHandler = new CurlMultiHandler(['max_handles' => 50]); // pool size; tune as needed

        $stack = HandlerStack::create(self::$multiHandler);
        // Inject the per-class X-Forwarded-For on every outgoing request unless
        // the test has already set one (LoginRateLimitTest sets a per-method IP
        // for budget-isolation purposes).
        // Reads $currentTestIp at call time so subsequent setUpBeforeClass
        // invocations (one per test class) flip the value cleanly.
        $stack->push(Middleware::mapRequest(static function (RequestInterface $request): RequestInterface {
            if ($request->hasHeader('X-Forwarded-For')) {
                return $request;
            }
            return $request->withHeader('X-Forwarded-For', ApiTestCase::$currentTestIp);
        }));

        // Validate required environment variables
        $requiredEnvVars = ['API_PROTOCOL', 'API_HOST', 'API_PORT'];
        foreach ($requiredEnvVars as $var) {
            if (empty($_ENV[$var])) {
                throw new \RuntimeException("Required environment variable {$var} is not set");
            }
        }

        if (self::isIPAddress($_ENV['API_HOST'])) {
            // Already an IP — detect family directly
            if (filter_var($_ENV['API_HOST'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                self::$preferV4 = false;
                self::$addr     = $_ENV['API_HOST'];
            } elseif (filter_var($_ENV['API_HOST'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                self::$preferV4 = true;
                self::$addr     = $_ENV['API_HOST'];
            }
        } else {
            // Hostname — detect preferred stack via DNS resolution
            $result = self::detectBinding((int) $_ENV['API_PORT']);
            if ($result['addr'] !== null) {
                self::$preferV4 = $result['preferV4'];
                self::$addr     = $result['addr'];
            } else {
                throw new \RuntimeException('Could not detect API binding on ' . sprintf('%s://%s:%s', $_ENV['API_PROTOCOL'], $_ENV['API_HOST'], $_ENV['API_PORT']));
            }
        }

        self::$http = new Client([
            'base_uri'         => sprintf('%s://%s:%s', $_ENV['API_PROTOCOL'], $_ENV['API_HOST'], $_ENV['API_PORT']),
            'handler'          => $stack,
            'timeout'          => 60,
            'connect_timeout'  => 5,
            'http_errors'      => false,
            'headers'          => [ 'Connection' => 'keep-alive' ],
            'curl'             => [ CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_2_0 ],
            'force_ip_resolve' => self::$preferV4 ? 'v4' : 'v6'
        ]);

        try {
            // Simple check — adjust path if your API root needs authentication
            $response             = self::$http->get('/', [
                'on_stats' => function (\GuzzleHttp\TransferStats $stats) {
                    self::$transferStats = $stats->getHandlerStat('http_version');
                }
            ]);
            self::$lastStatusCode = $response->getStatusCode();
            self::$apiAvailable   = self::$lastStatusCode < 500;
            if (false === self::$apiAvailable) {
                self::$responseBody = (string) $response->getBody();
            }
        } catch (ConnectException $e) {
            self::$apiAvailable  = false;
            self::$lastException = $e;
        }
    }

    protected function setUp(): void
    {
        if (! self::$apiAvailable) {
            // We use `fail` instead of `markSkipped` because we want the message to show without the `--debug` flag,
            // but `markSkipped` only shows the message with `--debug`
            $this->fail(
                "API is not running on {$_ENV['API_PROTOCOL']}://{$_ENV['API_HOST']}:{$_ENV['API_PORT']} "
                . ( self::$addr !== '' ? '(bound to ' . ( self::$preferV4 ? 'IPv4' : 'IPv6' ) . ' address ' . self::$addr . ') ' : '' )
                . '— skipping integration tests. Maybe run `composer start` first?' . PHP_EOL
                . (
                    self::$lastException
                    ? 'Error: ' . self::$lastException->getMessage()
                    : 'Last status code: ' . self::$lastStatusCode . (
                        self::$responseBody
                        ? ' (response body: ' . self::$responseBody . ')'
                        : ''
                    )
                )
            );
        }

        if (self::$transferStats === null || ( self::$transferStats !== 2 && self::$transferStats !== 3 )) {
            $this->fail(
                'Expected HTTP2 or HTTP3 transport, but got ' . ( self::$transferStats ?? 'unknown' )
            );
        }
    }

    protected function tearDown(): void
    {
        // After each test method, tick until idle
        if (self::$multiHandler) {
            do {
                $stillRunning = self::$multiHandler->tick();
            } while ($stillRunning > 0);
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$multiHandler) {
            // Tick until all pending curl handles are processed
            do {
                $stillRunning = self::$multiHandler->tick();
            } while ($stillRunning > 0);

            self::$multiHandler = null;
        }

        self::$http = null;
    }

    protected static function findProjectRoot(string $startDir = __DIR__, string $marker = 'composer.json'): ?string
    {
        $dir = $startDir;

        while (true) {
            if (file_exists($dir . DIRECTORY_SEPARATOR . $marker)) {
                return $dir;
            }

            $parentDir = dirname($dir);
            if ($parentDir === $dir) { // reached the project root
                return null;
            }

            $dir = $parentDir;
        }
    }

    private static function isIPAddress(string $host): bool
    {
        // Strip IPv6 brackets if present
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $host = substr($host, 1, -1);
        }

        return filter_var($host, FILTER_VALIDATE_IP) !== false;
    }

    /**
     * Regex fragment matching the configured API_HOST plus equivalent
     * localhost forms the dev server may report.
     *
     * Why: the PHP built-in server, Docker port forwarding, and curl can
     * each surface a different localhost variant in URLs even though they
     * all reach the same process. A test that hardcodes only `localhost`
     * fails when the server's $_SERVER['SERVER_NAME'] resolves to
     * `0.0.0.0` (e.g. when bound to all interfaces inside a container).
     *
     * Returns a fragment with no surrounding delimiters, ready to embed
     * in a larger pattern via sprintf.
     */
    protected static function hostRegex(): string
    {
        $host = $_ENV['API_HOST'] ?? '';
        // Strip IPv6 brackets so '[::1]' matches the '::1' alias (same
        // normalization isIPAddress() applies).
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $host = substr($host, 1, -1);
        }
        $localhostAliases = ['localhost', '127.0.0.1', '0.0.0.0', '::1'];
        if (in_array($host, $localhostAliases, true)) {
            return '(?:localhost|127\.0\.0\.1|0\.0\.0\.0|\[::1\])';
        }

        return preg_quote($host, '/');
    }

    private static function detectBinding(int $port): array
    {
        // Bracket IPv6 host if needed
        $sock6 = @fsockopen('tcp://[::1]', $port, $errno, $errstr, 0.5);
        if ($sock6) {
            fclose($sock6);
            return ['preferV4' => false, 'addr' => '::1'];
        }

        // Then try IPv4
        $sock4 = @fsockopen('tcp://127.0.0.1', $port, $errno, $errstr, 0.5);
        if ($sock4) {
            fclose($sock4);
            return ['preferV4' => true, 'addr' => '127.0.0.1'];
        }

        // Neither reachable
        return ['preferV4' => null, 'addr' => null];
    }

    /**
     * Check if the database is configured for tests that require authorization.
     *
     * Protected routes (PUT/PATCH/DELETE on /data, /tests, /temporale) require
     * a database connection for role-based authorization. This method checks if
     * the necessary environment variables are set.
     *
     * @return bool True if database is configured, false otherwise.
     */
    protected static function isDatabaseConfigured(): bool
    {
        $host     = $_ENV['DB_HOST'] ?? '';
        $name     = $_ENV['DB_NAME'] ?? '';
        $user     = $_ENV['DB_USER'] ?? '';
        $password = $_ENV['DB_PASSWORD'] ?? null;

        return $host !== '' && $name !== '' && $user !== '' && $password !== null;
    }

    /**
     * Obtain an access token for authenticated tests.
     *
     * Attempts Zitadel OIDC authentication first (via JWT Profile grant using a service account key),
     * then falls back to legacy JWT authentication via /auth/login.
     *
     * @return string|null The access token, or null if authentication fails.
     */
    protected static function getJwtToken(): ?string
    {
        if (self::$http === null) {
            return null;
        }

        // Try Zitadel OIDC token first
        $oidcToken = self::getZitadelToken();
        if ($oidcToken !== null) {
            return $oidcToken;
        }

        // Fall back to legacy JWT authentication
        return self::getLegacyJwtToken();
    }

    /**
     * Obtain a Zitadel OIDC access token via the JWT Profile grant (machine-to-machine).
     *
     * Requires a service account key file (JSON) at the project root or path specified
     * by the ZITADEL_SERVICE_KEY_FILE environment variable.
     *
     * @return string|null The JWT access token, or null if Zitadel auth is not configured or fails.
     */
    private static function getZitadelToken(): ?string
    {
        $issuer    = $_ENV['ZITADEL_ISSUER'] ?? '';
        $projectId = $_ENV['ZITADEL_PROJECT_ID'] ?? '';
        $keyFile   = $_ENV['ZITADEL_SERVICE_KEY_FILE']
            ?? dirname(__DIR__) . '/test-service-account-key.json';

        if (empty($issuer) || empty($projectId) || !file_exists($keyFile)) {
            return null;
        }

        $keyData = json_decode((string) file_get_contents($keyFile), true);
        if (!is_array($keyData) || empty($keyData['key']) || empty($keyData['userId'])) {
            return null;
        }

        $now     = time();
        $payload = [
            'iss' => $keyData['userId'],
            'sub' => $keyData['userId'],
            'aud' => rtrim($issuer, '/'),
            'iat' => $now,
            'exp' => $now + 3600,
        ];

        // Sign the JWT assertion with the service account's private key
        $assertion = \Firebase\JWT\JWT::encode($payload, $keyData['key'], 'RS256', $keyData['keyId']);

        // Exchange the assertion for an access token at the OIDC token endpoint
        // The scope includes the project audience so the token's aud claim matches what the middleware expects
        $tokenUrl = rtrim($issuer, '/') . '/oauth/v2/token';
        $scope    = "openid profile email urn:zitadel:iam:org:project:id:{$projectId}:aud urn:zitadel:iam:org:project:roles";

        try {
            $client   = new Client(['timeout' => 10]);
            $response = $client->post($tokenUrl, [
                'form_params' => [
                    'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                    'scope'      => $scope,
                    'assertion'  => $assertion,
                ],
            ]);

            if ($response->getStatusCode() !== 200) {
                error_log("Zitadel token exchange failed: HTTP {$response->getStatusCode()} - {$response->getBody()}");
                return null;
            }

            $data = json_decode((string) $response->getBody(), true);
            return is_array($data) ? ( $data['access_token'] ?? null ) : null;
        } catch (\Throwable $e) {
            error_log("Zitadel token exchange error: {$e->getMessage()}");
            return null;
        }
    }

    /**
     * Obtain a legacy JWT access token via /auth/login.
     *
     * Uses admin credentials from environment variables (ADMIN_USERNAME, ADMIN_PASSWORD)
     * or defaults to admin/password which are available in development and test environments.
     *
     * @return string|null The JWT access token, or null if authentication fails.
     */
    private static function getLegacyJwtToken(): ?string
    {
        if (self::$http === null) {
            return null;
        }

        $response = self::$http->post('/auth/login', [
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept'       => 'application/json'
            ],
            'json'    => [
                'username' => $_ENV['ADMIN_USERNAME'] ?? 'admin',
                'password' => $_ENV['ADMIN_PASSWORD'] ?? 'password'
            ]
        ]);

        if ($response->getStatusCode() !== 200) {
            return null;
        }

        $body = (string) $response->getBody();
        $data = json_decode($body, true);

        if (!is_array($data)) {
            return null;
        }

        return $data['access_token'] ?? null;
    }

    /**
     * Create authorization headers with the provided JWT token.
     *
     * @param string $token The JWT access token.
     * @return array<string, string> Headers array with Authorization header.
     */
    protected static function authHeaders(string $token): array
    {
        return ['Authorization' => 'Bearer ' . $token];
    }
}
