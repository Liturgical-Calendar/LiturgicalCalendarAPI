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
    protected const TABLES = ['api_keys', 'applications', 'access_requests', 'audit_log'];

    public static function setUpBeforeClass(): void
    {
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
            // Pin the session TZ so date assertions don't drift by environment.
            self::$pdo->exec("SET timezone TO 'Europe/Vatican'");
        } catch (\PDOException $e) {
            self::$pdo = null;
            // Defer reporting to setUp(); class-level skip messages aren't shown
            // without --debug, but per-test skips are.
            self::$skipReason = 'Postgres unreachable: ' . $e->getMessage();
        }
    }

    public static function tearDownAfterClass(): void
    {
        self::$pdo        = null;
        self::$skipReason = null;
    }

    protected function setUp(): void
    {
        if (self::$pdo === null) {
            $this->markTestSkipped(
                self::$skipReason
                ?? 'Repository tests require Postgres credentials in DB_HOST/DB_NAME/DB_USER/DB_PASSWORD. '
                . 'CI sets these via .env.local; locally, set them or run scripts/init-db.sql against your dev cluster.'
            );
        }

        // CASCADE clears api_keys when applications get nuked (FK ON DELETE CASCADE);
        // listing every table explicitly is safer than relying on cascade alone and
        // keeps the truncate fast (these tables stay small in tests).
        self::$pdo->exec('TRUNCATE TABLE ' . implode(', ', self::TABLES) . ' RESTART IDENTITY CASCADE');
    }

    private static ?string $skipReason = null;

    private static function env(string $name): ?string
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
