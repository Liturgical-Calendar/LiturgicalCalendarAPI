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
    protected const TABLES = ['api_keys', 'applications', 'access_requests', 'audit_log', 'user_notification_state', 'openfga_outbox'];

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
        // Decide whether this class can run BEFORE mutating any process-global state.
        //
        // A skip raised here aborts the whole class: PHPUnit then runs neither setUp()
        // nor tearDown() nor tearDownAfterClass() for it. That is what makes the skip
        // safe — a subclass tearDown() can never observe a snapshot its setUp() never
        // got round to taking (#868). It is also why these checks must come first:
        // anything pinned above a skip would never be restored.
        //
        // Both conditions are class-invariant (process env), so per-test checking bought
        // nothing. Message visibility is identical either way: on PHPUnit 12 neither a
        // per-test nor a suite-level skip reason is printed without --display-skipped,
        // and both are printed with it.
        $secret = self::env('JWT_SECRET');
        if ($secret === null || strlen($secret) < 32) {
            self::markTestSkipped(
                'Handler test requires JWT_SECRET (32+ chars) in env. '
                . 'See CLAUDE.md for the recommended values.'
            );
        }

        if (static::$requiresDatabase) {
            self::$pdo = self::connectToDatabase();
            if (self::$pdo === null) {
                self::markTestSkipped(
                    'Handler test requires Postgres credentials in DB_HOST/DB_NAME/DB_USER/DB_PASSWORD. '
                    . 'CI sets these via .env.local; locally, run scripts/init-db.sql.'
                );
            }
        }

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
    }

    /**
     * Open the test database connection, or return null when it is unavailable —
     * whether because the credentials are absent or because Postgres cannot be reached.
     */
    private static function connectToDatabase(): ?PDO
    {
        $host     = self::env('DB_HOST');
        $port     = self::env('DB_PORT') ?? '5432';
        $name     = self::env('DB_NAME');
        $user     = self::env('DB_USER');
        $password = self::env('DB_PASSWORD');

        if ($host === null || $name === null || $user === null || $password === null) {
            return null;
        }

        try {
            $pdo = new PDO(
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
            $pdo->exec("SET timezone TO 'Europe/Vatican'");
            return $pdo;
        } catch (\PDOException $e) {
            return null;
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
        // Only genuinely per-test work belongs here. Whether this class can run at all
        // was settled in setUpBeforeClass(); by the time we get here, self::$pdo is
        // non-null whenever a database was required (#868).
        if (static::$requiresDatabase) {
            self::$pdo?->exec(
                'TRUNCATE TABLE ' . implode(', ', self::TABLES) . ' RESTART IDENTITY CASCADE'
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
