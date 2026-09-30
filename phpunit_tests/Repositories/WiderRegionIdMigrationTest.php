<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Repositories;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\Exception\AbortMigration;
use Doctrine\Migrations\Query\Query;
use LiturgicalCalendar\Api\Migrations\Version20260930120000;
use PHPUnit\Framework\Attributes\CoversNothing;
use Psr\Log\NullLogger;

/**
 * The #1018 rewrite of persisted wider region ids, exercised as SQL against a real Postgres.
 *
 * As in RiteCalendarResourceTypeMigrationTest, the statements come out of the migration itself, so there is one copy
 * of the SQL. Unlike that test, this migration refuses to run while live work names a legacy region, and that refusal
 * lives in preUp(), which Doctrine's executor calls but `getSql()` does not. runMigration() therefore calls preUp()
 * explicitly, exactly as the executor would, before planning up().
 */
#[CoversNothing]
final class WiderRegionIdMigrationTest extends RepositoryTestCase
{
    private static ?Connection $dbal = null;

    public static function tearDownAfterClass(): void
    {
        self::$dbal?->close();
        self::$dbal = null;

        parent::tearDownAfterClass();
    }

    public function testLegacyIdsAreRewrittenAndOrderIsKept(): void
    {
        $this->seedChangeRequest('wider_region', 'roman/Europe', review: 'approved', publication: 'merged');
        $this->seedChangeRequest('national_calendar', 'roman/US', review: 'approved', publication: 'merged');
        $id = $this->seedAccessRequest([
            ['object_type' => 'national_calendar', 'object_id' => 'roman/IT', 'relation' => 'editor'],
            ['object_type' => 'wider_region', 'object_id' => 'roman/Middle East', 'relation' => 'editor'],
            ['object_type' => 'wider_region', 'object_id' => 'roman/europe', 'relation' => 'viewer'],
        ]);

        $this->runMigration('up');

        self::assertSame(['wider_region', 'roman/europe'], $this->fetchResource('roman/Europe'));
        self::assertSame(['national_calendar', 'roman/US'], $this->fetchResource('roman/US'));
        // Postgres normalises jsonb key order on storage, so compare with keys sorted; element order stays under test.
        self::assertSame(
            self::sortKeys([
                ['object_type' => 'national_calendar', 'object_id' => 'roman/IT', 'relation' => 'editor'],
                ['object_type' => 'wider_region', 'object_id' => 'roman/middle-east', 'relation' => 'editor'],
                ['object_type' => 'wider_region', 'object_id' => 'roman/europe', 'relation' => 'viewer'],
            ]),
            self::sortKeys($this->fetchPermissions($id))
        );
    }

    public function testItIsIdempotent(): void
    {
        $this->seedChangeRequest('wider_region', 'roman/Europe', review: 'rejected', publication: 'none');
        $this->runMigration('up');
        $this->runMigration('up');
        self::assertSame(['wider_region', 'roman/europe'], $this->fetchResource('roman/Europe'));
    }

    public function testItAbortsWhileAChangeRequestOnALegacyRegionIsLive(): void
    {
        $this->seedChangeRequest('wider_region', 'roman/Europe', review: 'submitted', publication: 'none');
        $this->expectException(AbortMigration::class);
        $this->runMigration('up');
    }

    public function testItAbortsWhileAnApprovedUnpublishedChangeRequestOnALegacyRegionIsLive(): void
    {
        $this->seedChangeRequest('wider_region', 'roman/Europe', review: 'approved', publication: 'open');
        $this->expectException(AbortMigration::class);
        $this->runMigration('up');
    }

    public function testItAbortsWhileAnOutboxRowNamesALegacyRegion(): void
    {
        self::$pdo->exec("INSERT INTO openfga_outbox (operation, fga_user, fga_relation, fga_object, metadata)
                           VALUES ('write_tuple', 'user:a', 'editor', 'wider_region:roman/Europe', '{\"idempotency_key\":\"k\"}')");
        $this->expectException(AbortMigration::class);
        $this->runMigration('up');
    }

    public function testItDoesNotAbortForAnOutboxRowThatHasSucceeded(): void
    {
        self::$pdo->exec("INSERT INTO openfga_outbox (operation, fga_user, fga_relation, fga_object, status, metadata)
                           VALUES ('write_tuple', 'user:a', 'editor', 'wider_region:roman/Europe', 'succeeded', '{\"idempotency_key\":\"k\"}')");
        $this->runMigration('up');
        $this->addToAssertionCount(1);
    }

    /**
     * @param array<int,array<string,string>> $tuples
     * @return array<int,array<string,string>>
     */
    private static function sortKeys(array $tuples): array
    {
        foreach ($tuples as $index => $tuple) {
            ksort($tuple);
            $tuples[$index] = $tuple;
        }

        return $tuples;
    }

    /**
     * Run the migration as Doctrine's executor does: preUp() first, then up(), then the planned statements.
     *
     * @param 'up' $direction
     */
    private function runMigration(string $direction): void
    {
        $migration = new Version20260930120000(self::dbalConnection(), new NullLogger());

        $migration->preUp(new Schema());
        $migration->up(new Schema());

        foreach (array_map(static fn (Query $query): string => $query->getStatement(), $migration->getSql()) as $sql) {
            self::$pdo->exec($sql);
        }
    }

    private static function dbalConnection(): Connection
    {
        if (self::$dbal === null) {
            self::$dbal = DriverManager::getConnection([
                'driver'   => 'pdo_pgsql',
                'host'     => self::env('DB_HOST'),
                'port'     => (int) ( self::env('DB_PORT') ?? '5432' ),
                'dbname'   => self::env('DB_NAME'),
                'user'     => self::env('DB_USER'),
                'password' => self::env('DB_PASSWORD'),
            ]);
        }

        return self::$dbal;
    }

    /** The `path` is the stable handle a row keeps across the rewrite; `resource_id` is under test. */
    private static function seedPath(string $resourceId): string
    {
        return 'jsondata/seed/' . $resourceId . '.json';
    }

    private function seedChangeRequest(
        string $resourceType,
        string $resourceId,
        string $review = 'submitted',
        string $publication = 'none'
    ): void {
        $stmt = self::$pdo->prepare(
            "INSERT INTO sourcedata_change_requests
                (batch_id, resource_type, resource_id, path, operation, content, submitted_by_sub, review_status, publication_status)
             VALUES
                (gen_random_uuid(), :resource_type, :resource_id, :path, 'update', '{}', :submitted_by_sub, :review, :publication)"
        );
        $stmt->execute([
            'resource_type'    => $resourceType,
            'resource_id'      => $resourceId,
            'path'             => self::seedPath($resourceId),
            'submitted_by_sub' => 'user_' . bin2hex(random_bytes(4)),
            'review'           => $review,
            'publication'      => $publication,
        ]);
    }

    /**
     * @param  string             $resourceId The id as SEEDED, not as rewritten.
     * @return array<int,string>              [resource_type, resource_id]
     */
    private function fetchResource(string $resourceId): array
    {
        $stmt = self::$pdo->prepare(
            'SELECT resource_type, resource_id FROM sourcedata_change_requests WHERE path = :path'
        );
        $stmt->execute(['path' => self::seedPath($resourceId)]);

        /** @var array{resource_type:string,resource_id:string}|false $row */
        $row = $stmt->fetch();
        self::assertIsArray($row, 'the seeded change request row is missing');

        return [$row['resource_type'], $row['resource_id']];
    }

    /** @param array<int,array<string,string>> $permissions */
    private function seedAccessRequest(array $permissions): string
    {
        $stmt = self::$pdo->prepare(
            "INSERT INTO access_requests
                (zitadel_user_id, user_email, requested_role, permissions)
             VALUES
                (:zitadel_user_id, :user_email, 'calendar_editor', CAST(:permissions AS jsonb))
             RETURNING id"
        );
        $stmt->execute([
            'zitadel_user_id' => 'user_' . bin2hex(random_bytes(4)),
            'user_email'      => 'seed@example.test',
            'permissions'     => json_encode($permissions, JSON_THROW_ON_ERROR),
        ]);

        $id = $stmt->fetchColumn();
        self::assertIsString($id);

        return $id;
    }

    /** @return array<int,array<string,string>> */
    private function fetchPermissions(string $id): array
    {
        $stmt = self::$pdo->prepare('SELECT permissions FROM access_requests WHERE id = :id');
        $stmt->execute(['id' => $id]);

        $raw = $stmt->fetchColumn();
        self::assertIsString($raw, 'the seeded access request row is missing');

        /** @var array<int,array<string,string>> $decoded */
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }
}
