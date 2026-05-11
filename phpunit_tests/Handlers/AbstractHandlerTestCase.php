<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Handlers;

use LiturgicalCalendar\Api\Router;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Stream;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Base class for handler tests that call `handle()` directly with a
 * synthetic PSR-7 request, rather than going through the HTTP server.
 *
 * Most handlers in src/Handlers/ depend on:
 * - JWT config in $_ENV (JWT_SECRET, JWT_EXPIRY, ...).
 * - For admin handlers: an `oidc_user` request attribute, a Postgres
 *   connection (DB_HOST/DB_NAME/DB_USER/DB_PASSWORD), and sometimes
 *   OpenFGA/Zitadel services we can't reach from in-process tests.
 *
 * This base class:
 * - Asserts JWT envs are present and the secret looks plausible (32+ chars).
 * - Opens a shared PDO for tests that need DB access, mirroring the
 *   RepositoryTestCase pattern. `setUp()` truncates the four target
 *   tables before each test for isolation.
 * - Provides `markTestSkipped` semantics when DB credentials are missing.
 *
 * Tests that don't need DB can use `requestFor(...)` directly without
 * touching the truncate path; tests that do need DB call
 * `requireDatabase()` in their own setUp.
 */
abstract class AbstractHandlerTestCase extends TestCase
{
    protected static ?PDO $pdo = null;

    /** @var array<int,string> */
    protected const TABLES = ['api_keys', 'applications', 'access_requests', 'audit_log'];

    /**
     * Subclasses set true when their tests need a working Postgres.
     * Default false so plain HTTP-shape tests don't pay the truncate cost.
     */
    protected static bool $requiresDatabase = false;

    /**
     * Default to '' so tearDownAfterClass can always restore Router::$apiPath
     * to a known string value, even if it was uninitialised before setUp ran.
     * The typed-string property on Router can't accept null.
     */
    private static string $savedApiPath     = '';
    private static string $savedApiFilePath = '';

    public static function setUpBeforeClass(): void
    {
        // Pin Router::$apiPath + Router::$apiFilePath so handlers that read
        // them (for self-links + JsonData::*->path() filesystem lookups)
        // get stable, predictable values in tests. isset() is false for
        // typed-uninitialised properties, so we fall back to defaults
        // rather than tripping an Error on the access.
        self::$savedApiPath     = isset(Router::$apiPath) ? Router::$apiPath : '';
        self::$savedApiFilePath = isset(Router::$apiFilePath) ? Router::$apiFilePath : '';
        Router::$apiPath        = '';
        // apiFilePath is used as a prefix when JsonData enum cases build
        // filesystem paths like '<root>/jsondata/schemas/Foo.json', so set
        // it to the project root with a trailing slash, matching Router's
        // production behaviour.
        Router::$apiFilePath = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR;

        if (static::$requiresDatabase) {
            $host     = self::env('DB_HOST');
            $port     = self::env('DB_PORT') ?? '5432';
            $name     = self::env('DB_NAME');
            $user     = self::env('DB_USER');
            $password = self::env('DB_PASSWORD');

            if ($host === null || $name === null || $user === null || $password === null) {
                self::$pdo = null;
                return;
            }

            try {
                self::$pdo = new PDO(
                    sprintf('pgsql:host=%s;port=%s;dbname=%s', $host, $port, $name),
                    $user,
                    $password,
                    [
                        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                        PDO::ATTR_EMULATE_PREPARES   => false,
                        PDO::ATTR_TIMEOUT            => 5,
                    ]
                );
                self::$pdo->exec("SET timezone TO 'Europe/Vatican'");
            } catch (\PDOException $e) {
                self::$pdo = null;
            }
        }
    }

    public static function tearDownAfterClass(): void
    {
        self::$pdo           = null;
        Router::$apiPath     = self::$savedApiPath;
        Router::$apiFilePath = self::$savedApiFilePath;
    }

    protected function setUp(): void
    {
        if (static::$requiresDatabase) {
            if (self::$pdo === null) {
                $this->markTestSkipped(
                    'Handler test requires Postgres credentials in DB_HOST/DB_NAME/DB_USER/DB_PASSWORD. '
                    . 'CI sets these via .env.local; locally, run scripts/init-db.sql.'
                );
            }

            self::$pdo->exec(
                'TRUNCATE TABLE ' . implode(', ', self::TABLES) . ' RESTART IDENTITY CASCADE'
            );
        }

        // Confirm the JWT env is set up at least to the minimum the
        // services need (JwtServiceFactory::fromEnv rejects secrets
        // shorter than 32 chars). Skipping (not failing) keeps the
        // rest of the suite usable for devs without auth configured.
        $secret = self::env('JWT_SECRET');
        if ($secret === null || strlen($secret) < 32) {
            $this->markTestSkipped(
                'Handler test requires JWT_SECRET (32+ chars) in env. '
                . 'See CLAUDE.md for the recommended values.'
            );
        }
    }

    /**
     * Build a synthetic PSR-7 request. Body is JSON-encoded automatically
     * when an array is provided; raw strings are passed through.
     *
     * When `$body` is an array, the array is also attached via
     * `withParsedBody()` so handlers that read `$request->getParsedBody()`
     * see it without needing a body-parser middleware in the pipeline.
     *
     * @param array<string,string>     $headers
     * @param array<string,mixed>|string|null $body
     */
    protected function requestFor(
        string $method,
        string $uri,
        array $headers = [],
        array|string|null $body = null
    ): ServerRequestInterface {
        $defaultHeaders = ['Accept' => 'application/json'];
        $parsedBody     = null;
        if (is_array($body)) {
            $defaultHeaders['Content-Type'] = 'application/json';
            $parsedBody                     = $body;
            $body                           = json_encode($body, JSON_THROW_ON_ERROR);
        }
        $request = new ServerRequest($method, $uri, array_merge($defaultHeaders, $headers));
        if ($body !== null) {
            $request = $request->withBody(Stream::create($body));
        }
        if ($parsedBody !== null) {
            $request = $request->withParsedBody($parsedBody);
        }
        return $request;
    }

    /**
     * Decode a JSON response body into an array. Fails the test if the
     * body isn't valid JSON. Buffers via getBody()->__toString() so the
     * caller can still inspect the response object afterwards.
     *
     * @return array<string,mixed>
     */
    protected function decodeJsonBody(ResponseInterface $response): array
    {
        $body = (string) $response->getBody();
        if ($body === '') {
            return [];
        }
        $decoded = json_decode($body, true);
        self::assertIsArray($decoded, 'Response body is not valid JSON: ' . $body);
        return $decoded;
    }

    /**
     * Attach a synthetic OIDC user attribute to a request, matching the
     * shape that OidcAuthMiddleware sets in production.
     *
     * @param array<int,string> $roles
     */
    protected function withOidcUser(
        ServerRequestInterface $request,
        string $sub = 'admin-user-1',
        array $roles = ['admin']
    ): ServerRequestInterface {
        return $request->withAttribute('oidc_user', [
            'sub'   => $sub,
            'roles' => $roles,
        ]);
    }

    private static function env(string $name): ?string
    {
        if (isset($_ENV[$name]) && $_ENV[$name] !== '') {
            return (string) $_ENV[$name];
        }
        $value = getenv($name);
        return $value === false || $value === '' ? null : $value;
    }

    /**
     * Helper for admin tests that need an application row to point at.
     *
     * @param array<string,mixed> $overrides
     */
    protected function insertApplication(array $overrides = []): string
    {
        $row = array_merge(
            [
                'zitadel_user_id' => 'user_' . bin2hex(random_bytes(4)),
                'name'            => 'Test App',
                'description'     => null,
                'website'         => null,
                'status'          => 'pending',
                'requested_scope' => 'read',
                'is_active'       => true,
            ],
            $overrides
        );

        $stmt = self::$pdo->prepare(
            'INSERT INTO applications
                (zitadel_user_id, name, description, website, status, requested_scope, is_active)
             VALUES
                (:zitadel_user_id, :name, :description, :website, :status, :requested_scope, :is_active)
             RETURNING id'
        );
        $stmt->execute([
            'zitadel_user_id' => $row['zitadel_user_id'],
            'name'            => $row['name'],
            'description'     => $row['description'],
            'website'         => $row['website'],
            'status'          => $row['status'],
            'requested_scope' => $row['requested_scope'],
            'is_active'       => $row['is_active'] ? 'true' : 'false',
        ]);

        return (string) $stmt->fetchColumn();
    }
}
