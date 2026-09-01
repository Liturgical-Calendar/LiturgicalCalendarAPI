<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Repositories;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\Query\Query;
use LiturgicalCalendar\Api\Migrations\Version20260901130000;
use PHPUnit\Framework\Attributes\CoversNothing;
use Psr\Log\NullLogger;

/**
 * The #955 rewrite of persisted resource types, exercised as SQL against a real Postgres.
 *
 * The statements executed here are pulled out of the migration itself — `up()` / `down()` are
 * invoked with a throwaway `Schema` (both ignore it) and `AbstractMigration::getSql()` hands
 * back the planned `Query` objects. There is therefore exactly ONE copy of this SQL in the
 * repository, and no way for a test copy to drift away from what the migration actually runs.
 *
 * What is pinned here is behaviour on representative rows, especially the JSONB rewrite: it is
 * element-wise, so a non-matching tuple sharing the array must survive unchanged, element order
 * must be preserved, and every `relation` must be untouched.
 */
#[CoversNothing]
final class RiteCalendarResourceTypeMigrationTest extends RepositoryTestCase
{
    private static ?Connection $dbal = null;

    public static function tearDownAfterClass(): void
    {
        self::$dbal?->close();
        self::$dbal = null;

        parent::tearDownAfterClass();
    }

    public function testChangeRequestRowsAreRetypedAndRiteQualified(): void
    {
        $this->seedChangeRequest('general_roman_calendar', 'decrees');
        $this->seedChangeRequest('general_roman_calendar', 'EDITIO_TYPICA_2024');
        $this->seedChangeRequest('national_calendar', 'roman/US');

        $this->runMigration('up');

        self::assertSame(['rite_calendar', 'roman/decrees'], $this->fetchResource('decrees'));
        self::assertSame(['rite_calendar', 'ambrosian/EDITIO_TYPICA_2024'], $this->fetchResource('EDITIO_TYPICA_2024'));

        // An untouched type keeps both of its values.
        self::assertSame(['national_calendar', 'roman/US'], $this->fetchResource('roman/US'));
    }

    public function testAnAlreadyQualifiedIdIsLeftAlone(): void
    {
        $this->seedChangeRequest('general_roman_calendar', 'roman/decrees');
        $this->seedChangeRequest('general_roman_calendar', 'ambrosian/EDITIO_TYPICA_2024');

        $this->runMigration('up');

        self::assertSame(['rite_calendar', 'roman/decrees'], $this->fetchResource('roman/decrees'));
        self::assertSame(
            ['rite_calendar', 'ambrosian/EDITIO_TYPICA_2024'],
            $this->fetchResource('ambrosian/EDITIO_TYPICA_2024')
        );
    }

    public function testTheRewriteIsIdempotent(): void
    {
        $this->seedChangeRequest('general_roman_calendar', 'decrees');
        $this->seedAccessRequest([
            ['object_type' => 'general_roman_calendar', 'object_id' => 'decrees', 'relation' => 'editor'],
        ]);

        $this->runMigration('up');
        $afterFirstRun = [$this->fetchResource('decrees'), $this->fetchPermissions()];

        $this->runMigration('up');
        $afterSecondRun = [$this->fetchResource('decrees'), $this->fetchPermissions()];

        self::assertSame(['rite_calendar', 'roman/decrees'], $afterFirstRun[0]);
        self::assertSame($afterFirstRun, $afterSecondRun, 'a second run of the migration must be a no-op');
    }

    public function testPendingPermissionTuplesAreRewrittenElementWise(): void
    {
        $untouched = ['object_type' => 'national_calendar', 'object_id' => 'roman/US', 'relation' => 'admin'];

        $this->seedAccessRequest([
            ['object_type' => 'general_roman_calendar', 'object_id' => 'decrees', 'relation' => 'editor'],
            ['object_type' => 'general_roman_calendar_test', 'object_id' => 'general_roman_calendar', 'relation' => 'editor'],
            $untouched,
        ]);

        $this->runMigration('up');

        $tuples = $this->fetchPermissions();

        self::assertCount(3, $tuples);

        self::assertSame('rite_calendar', $tuples[0]['object_type']);
        self::assertSame('roman/decrees', $tuples[0]['object_id']);
        self::assertSame('editor', $tuples[0]['relation'], 'the relation must survive untouched');

        self::assertSame('rite_calendar_test', $tuples[1]['object_type']);
        self::assertSame('roman', $tuples[1]['object_id']);
        self::assertSame('editor', $tuples[1]['relation'], 'the relation must survive untouched');

        // An unrelated tuple in the same array is left exactly as it was.
        self::assertSame('national_calendar', $tuples[2]['object_type']);
        self::assertSame('roman/US', $tuples[2]['object_id']);
        self::assertTuplesSame([$untouched], [$tuples[2]], 'a non-matching element must survive unchanged');
    }

    public function testAnAccessRequestNamingNoLegacyTypeIsNotTouchedAtAll(): void
    {
        $permissions = [
            ['object_type' => 'national_calendar', 'object_id' => 'roman/US', 'relation' => 'admin'],
            ['object_type' => 'diocesan_calendar', 'object_id' => 'roman/romamo_it', 'relation' => 'editor'],
        ];

        $this->seedAccessRequest($permissions);

        $this->runMigration('up');

        self::assertTuplesSame($permissions, $this->fetchPermissions());
    }

    public function testAuditLogIsNotRewritten(): void
    {
        $this->seedAuditRow('general_roman_calendar', 'decrees');

        $this->runMigration('up');

        $stmt = self::$pdo->query("SELECT resource_type FROM audit_log WHERE resource_id = 'decrees'");
        self::assertNotFalse($stmt);

        self::assertSame(
            'general_roman_calendar',
            $stmt->fetchColumn(),
            'the audit log records what happened under the name in force at the time'
        );
    }

    public function testDownReversesUp(): void
    {
        $this->seedChangeRequest('general_roman_calendar', 'decrees');
        $this->seedChangeRequest('general_roman_calendar', 'EDITIO_TYPICA_2024');
        $this->seedAccessRequest([
            ['object_type' => 'general_roman_calendar', 'object_id' => 'decrees', 'relation' => 'editor'],
            ['object_type' => 'national_calendar', 'object_id' => 'roman/US', 'relation' => 'admin'],
        ]);

        $this->runMigration('up');
        $this->runMigration('down');

        self::assertSame(['general_roman_calendar', 'decrees'], $this->fetchResource('decrees'));
        self::assertSame(['general_roman_calendar', 'EDITIO_TYPICA_2024'], $this->fetchResource('EDITIO_TYPICA_2024'));

        self::assertTuplesSame(
            [
                ['object_type' => 'general_roman_calendar', 'object_id' => 'decrees', 'relation' => 'editor'],
                ['object_type' => 'national_calendar', 'object_id' => 'roman/US', 'relation' => 'admin'],
            ],
            $this->fetchPermissions()
        );
    }

    /**
     * down() deliberately does NOT restore `general_roman_calendar_test`: a `rite_calendar_test`
     * row may predate this migration entirely (#767 gave that type two provenances), so reverting
     * it would corrupt rows the migration never touched.
     */
    public function testDownLeavesTheTestScopeTypeAlone(): void
    {
        $this->seedAccessRequest([
            ['object_type' => 'general_roman_calendar_test', 'object_id' => 'general_roman_calendar', 'relation' => 'editor'],
        ]);

        $this->runMigration('up');
        $this->runMigration('down');

        self::assertTuplesSame(
            [['object_type' => 'rite_calendar_test', 'object_id' => 'roman', 'relation' => 'editor']],
            $this->fetchPermissions()
        );
    }

    /**
     * Compare two tuple arrays element by element, ignoring KEY order within each element.
     *
     * Postgres normalises a jsonb object's key order on storage (shortest key first, then
     * bytewise), so a round-tripped tuple never comes back in the order it was written and a
     * plain assertSame() would fail on that alone. Element ORDER within the array is NOT
     * normalised away — that is a real property of the rewrite and stays under test.
     *
     * @param array<int,array<string,string>> $expected
     * @param array<int,array<string,string>> $actual
     */
    private static function assertTuplesSame(array $expected, array $actual, string $message = ''): void
    {
        $sortKeys = static function (array $tuples): array {
            foreach ($tuples as $index => $tuple) {
                ksort($tuple);
                $tuples[$index] = $tuple;
            }

            return $tuples;
        };

        self::assertSame($sortKeys($expected), $sortKeys($actual), $message);
    }

    /**
     * Execute the migration's own planned statements against the test database.
     *
     * @param 'up'|'down' $direction
     */
    private function runMigration(string $direction): void
    {
        foreach (self::migrationStatements($direction) as $sql) {
            self::$pdo->exec($sql);
        }
    }

    /**
     * Pull the SQL out of the migration rather than re-declaring it here.
     *
     * @param  'up'|'down'      $direction
     * @return array<int,string>
     */
    private static function migrationStatements(string $direction): array
    {
        $migration = new Version20260901130000(self::dbalConnection(), new NullLogger());

        // Both up() and down() ignore the Schema they are handed; these are plain UPDATEs.
        if ($direction === 'down') {
            $migration->down(new Schema());
        } else {
            $migration->up(new Schema());
        }

        return array_map(
            static fn (Query $query): string => $query->getStatement(),
            $migration->getSql()
        );
    }

    /**
     * A live DBAL connection, which `AbstractMigration::__construct()` requires: it calls
     * createSchemaManager() and getDatabasePlatform() immediately, and abortIf() consults the
     * platform again. Same credentials the PDO connection uses.
     */
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

    /**
     * The `path` is the stable handle a row keeps across the rewrite — `resource_id` is one of the
     * things under test, so it cannot also be the lookup key.
     */
    private static function seedPath(string $resourceId): string
    {
        return 'jsondata/seed/' . $resourceId . '.json';
    }

    private function seedChangeRequest(string $resourceType, string $resourceId): void
    {
        $stmt = self::$pdo->prepare(
            "INSERT INTO sourcedata_change_requests
                (batch_id, resource_type, resource_id, path, operation, content, submitted_by_sub)
             VALUES
                (gen_random_uuid(), :resource_type, :resource_id, :path, 'update', '{}', :submitted_by_sub)"
        );
        $stmt->execute([
            'resource_type'    => $resourceType,
            'resource_id'      => $resourceId,
            'path'             => self::seedPath($resourceId),
            'submitted_by_sub' => 'user_' . bin2hex(random_bytes(4)),
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
    private function seedAccessRequest(array $permissions): void
    {
        $stmt = self::$pdo->prepare(
            "INSERT INTO access_requests
                (zitadel_user_id, user_email, requested_role, permissions)
             VALUES
                (:zitadel_user_id, :user_email, 'calendar_editor', CAST(:permissions AS jsonb))"
        );
        $stmt->execute([
            'zitadel_user_id' => 'user_' . bin2hex(random_bytes(4)),
            'user_email'      => 'seed@example.test',
            'permissions'     => json_encode($permissions, JSON_THROW_ON_ERROR),
        ]);
    }

    /** @return array<int,array<string,string>> */
    private function fetchPermissions(): array
    {
        $stmt = self::$pdo->query('SELECT permissions FROM access_requests');
        self::assertNotFalse($stmt);

        $raw = $stmt->fetchColumn();
        self::assertIsString($raw, 'the seeded access request row is missing');

        /** @var array<int,array<string,string>> $decoded */
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }

    private function seedAuditRow(string $resourceType, string $resourceId): void
    {
        $stmt = self::$pdo->prepare(
            "INSERT INTO audit_log (action, resource_type, resource_id) VALUES ('seed.write', :resource_type, :resource_id)"
        );
        $stmt->execute([
            'resource_type' => $resourceType,
            'resource_id'   => $resourceId,
        ]);
    }
}
