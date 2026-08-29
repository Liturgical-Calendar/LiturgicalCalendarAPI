<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Repositories;

use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Base class for repository tests that need a real Postgres connection.
 *
 * Looks for DB_HOST / DB_NAME / DB_USER / DB_PASSWORD in either $_ENV or
 * getenv() (CI's phpunit workflow writes them to .env.local, which the
 * test bootstrap loads; local devs can do the same). When configured,
 * opens one connection per test class and TRUNCATEs the four target
 * tables before every test for isolation.
 *
 * When unconfigured, marks the test as skipped with a clear message so
 * the rest of the suite still runs. The CI run is the place that
 * actually enforces coverage on this layer (per #570 / #568).
 *
 * Per-test rollback (the strategy the issue suggests) does not work for
 * us because ApiKeyRepository::rotate() opens its own transaction; PDO
 * has no real nested-transaction support, so the inner beginTransaction
 * would error out. TRUNCATE … CASCADE is functionally equivalent and
 * sidesteps that.
 */
abstract class RepositoryTestCase extends TestCase
{
    protected static ?PDO $pdo = null;

    /** @var array<int,string> Tables truncated before each test, in any order — CASCADE handles FKs. */
    protected const TABLES = ['api_keys', 'applications', 'access_requests', 'audit_log', 'openfga_outbox', 'sourcedata_change_requests'];

    public static function setUpBeforeClass(): void
    {
        self::$skipReason = null;
        self::$pdo        = self::connect();

        // Skip at class level rather than per test. A skip raised here aborts the whole
        // class, so PHPUnit runs neither setUp() nor tearDown() for it — which is what
        // stops a subclass tearDown() reading a snapshot its setUp() never took (#868).
        //
        // This previously deferred to setUp() on the belief that suite-level skip
        // reasons are hidden without --debug while per-test ones are shown. That is not
        // true on PHPUnit 12: neither is printed without --display-skipped, and both are
        // printed with it.
        if (self::$pdo === null) {
            self::markTestSkipped(
                self::$skipReason
                ?? 'Repository tests require Postgres credentials in DB_HOST/DB_NAME/DB_USER/DB_PASSWORD. '
                . 'CI sets these via .env.local; locally, set them or run scripts/init-db.sql against your dev cluster.'
            );
        }
    }

    /**
     * Open the test database connection, or return null when it is unavailable — whether
     * because the credentials are absent or because Postgres cannot be reached.
     *
     * Every unavailable path RETURNS null rather than returning early from
     * setUpBeforeClass(), so the single `self::$pdo === null` check there cannot be
     * bypassed and the class can never proceed with a null connection.
     */
    private static function connect(): ?PDO
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
            // Pin the session TZ so date assertions don't drift by environment.
            $pdo->exec("SET timezone TO 'Europe/Vatican'");
            return $pdo;
        } catch (\PDOException $e) {
            self::$skipReason = 'Postgres unreachable: ' . $e->getMessage();
            return null;
        }
    }

    public static function tearDownAfterClass(): void
    {
        self::$pdo        = null;
        self::$skipReason = null;
    }

    protected function setUp(): void
    {
        // Availability was settled in setUpBeforeClass(); self::$pdo is non-null here.
        //
        // CASCADE clears api_keys when applications get nuked (FK ON DELETE CASCADE);
        // listing every table explicitly is safer than relying on cascade alone and
        // keeps the truncate fast (these tables stay small in tests).
        self::$pdo->exec('TRUNCATE TABLE ' . implode(', ', self::TABLES) . ' RESTART IDENTITY CASCADE');
    }

    private static ?string $skipReason = null;

    /**
     * Resolve a DB env var. Subclasses that need to open a second PDO
     * connection should reuse this rather than reading $_ENV directly,
     * so the resolution rules (env array first, getenv() fallback) stay
     * consistent with setUpBeforeClass().
     */
    protected static function env(string $name): ?string
    {
        if (isset($_ENV[$name]) && $_ENV[$name] !== '') {
            return (string) $_ENV[$name];
        }

        $value = getenv($name);
        if ($value === false || $value === '') {
            return null;
        }

        return $value;
    }

    /**
     * Convenience: insert a minimal applications row and return its id.
     * Tests that need an application but don't care about its specifics
     * can pull this in rather than re-deriving the schema.
     *
     * @param array<string,mixed> $overrides Override any column default.
     */
    protected function insertApplication(array $overrides = []): string
    {
        $row = array_merge(
            [
                'zitadel_user_id' => 'user_' . bin2hex(random_bytes(4)),
                'name'            => 'Test App',
                'description'     => null,
                'website'         => null,
                'status'          => 'approved',
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
