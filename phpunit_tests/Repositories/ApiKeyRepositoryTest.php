<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Repositories;

use LiturgicalCalendar\Api\Repositories\ApiKeyRepository;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(ApiKeyRepository::class)]
final class ApiKeyRepositoryTest extends RepositoryTestCase
{
    private ApiKeyRepository $repo;
    private string $appId;
    private string $ownerUserId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo        = new ApiKeyRepository(self::$pdo);
        $this->ownerUserId = 'user_' . bin2hex(random_bytes(4));
        $this->appId       = $this->insertApplication([
            'zitadel_user_id' => $this->ownerUserId,
            'status'          => 'approved',
            'is_active'       => true,
        ]);
    }

    public function testGenerateReturnsPlainKeyAndStoresRecord(): void
    {
        $result = $this->repo->generate($this->appId, 'CI key', 'read', 500);

        self::assertNotEmpty($result['key']);
        // Default APP_ENV in tests isn't 'production', so the env tag is 'test'.
        self::assertStringStartsWith('litcal_test_', $result['key']);
        self::assertIsArray($result['record']);
        self::assertSame('CI key', $result['record']['name']);
        self::assertSame('read', $result['record']['scope']);
        self::assertSame(500, $result['record']['rate_limit_per_hour']);
        self::assertTrue((bool) $result['record']['is_active']);
        // key_prefix is the first 20 chars of the plaintext key.
        self::assertSame(substr($result['key'], 0, 20), $result['record']['key_prefix']);
    }

    public function testValidateReturnsRowForActiveApprovedKey(): void
    {
        $generated = $this->repo->generate($this->appId);
        $row       = $this->repo->validate($generated['key']);

        self::assertNotNull($row);
        self::assertSame($this->appId, $row['application_id']);
        self::assertSame($this->ownerUserId, $row['zitadel_user_id']);
        // First validate() reads the row first and then updates last_used_at,
        // so the returned snapshot still has it null. Re-validate to confirm
        // the timestamp was actually written.
        self::assertNull($row['last_used_at']);
        $secondPass = $this->repo->validate($generated['key']);
        self::assertNotNull($secondPass);
        self::assertNotNull($secondPass['last_used_at']);
    }

    public function testValidateRejectsUnknownKey(): void
    {
        self::assertNull($this->repo->validate('litcal_test_does_not_exist'));
    }

    public function testValidateRejectsRevokedKey(): void
    {
        $generated = $this->repo->generate($this->appId);
        /** @var array<string,mixed> $record */
        $record = $generated['record'];
        /** @var string $id */
        $id = $record['id'];

        self::assertTrue($this->repo->revoke($id, $this->ownerUserId));
        self::assertNull($this->repo->validate($generated['key']));
    }

    public function testValidateRejectsKeyWhoseApplicationIsInactive(): void
    {
        $generated = $this->repo->generate($this->appId);
        self::$pdo->prepare('UPDATE applications SET is_active = FALSE WHERE id = :id')
            ->execute(['id' => $this->appId]);

        self::assertNull($this->repo->validate($generated['key']));
    }

    public function testValidateRejectsKeyWhoseApplicationIsNotApproved(): void
    {
        // Pending applications shouldn't be issuing live traffic even if they
        // somehow already have keys generated.
        $pendingApp = $this->insertApplication([
            'zitadel_user_id' => $this->ownerUserId,
            'status'          => 'pending',
            'is_active'       => true,
        ]);
        $generated  = $this->repo->generate($pendingApp);

        self::assertNull($this->repo->validate($generated['key']));
    }

    public function testValidateRejectsExpiredKey(): void
    {
        $past      = new \DateTimeImmutable('2000-01-01 00:00:00', new \DateTimeZone('Europe/Vatican'));
        $generated = $this->repo->generate($this->appId, null, 'read', 1000, $past);

        self::assertNull($this->repo->validate($generated['key']));
    }

    public function testGetByApplicationReturnsKeysInsertedNewestFirst(): void
    {
        $first = $this->repo->generate($this->appId, 'first');
        usleep(2000);
        $second = $this->repo->generate($this->appId, 'second');

        $rows = $this->repo->getByApplication($this->appId);

        self::assertCount(2, $rows);
        self::assertSame('second', $rows[0]['name']);
        self::assertSame('first', $rows[1]['name']);
        // No raw hashes are leaked via this read path.
        foreach ($rows as $row) {
            self::assertArrayNotHasKey('key_hash', $row);
        }
    }

    public function testGetByIdReturnsRowOrNull(): void
    {
        $generated = $this->repo->generate($this->appId);
        /** @var array<string,mixed> $record */
        $record = $generated['record'];
        /** @var string $id */
        $id = $record['id'];

        $row = $this->repo->getById($id);
        self::assertNotNull($row);
        self::assertSame($id, $row['id']);
        self::assertSame($this->ownerUserId, $row['zitadel_user_id']);

        self::assertNull($this->repo->getById('00000000-0000-0000-0000-000000000000'));
    }

    public function testRevokeFailsForNonOwner(): void
    {
        $generated = $this->repo->generate($this->appId);
        /** @var array<string,mixed> $record */
        $record = $generated['record'];
        /** @var string $id */
        $id = $record['id'];

        self::assertFalse($this->repo->revoke($id, 'stranger'));
        $row = $this->repo->getById($id);
        self::assertNotNull($row);
        self::assertTrue((bool) $row['is_active']);
    }

    public function testDeleteRequiresOwnerAndCorrectApp(): void
    {
        $generated = $this->repo->generate($this->appId);
        /** @var array<string,mixed> $record */
        $record = $generated['record'];
        /** @var string $id */
        $id = $record['id'];

        self::assertFalse($this->repo->delete($id, 'stranger', $this->appId));
        self::assertFalse($this->repo->delete($id, $this->ownerUserId, '00000000-0000-0000-0000-000000000000'));
        self::assertTrue($this->repo->delete($id, $this->ownerUserId, $this->appId));
        self::assertNull($this->repo->getById($id));
    }

    public function testRotateRevokesOldAndIssuesNew(): void
    {
        $original = $this->repo->generate($this->appId, 'original', 'read', 250);
        /** @var array<string,mixed> $record */
        $record = $original['record'];
        /** @var string $oldId */
        $oldId = $record['id'];

        $rotated = $this->repo->rotate($oldId, $this->ownerUserId);

        self::assertNotNull($rotated);
        self::assertNotSame($original['key'], $rotated['key']);
        // Old key is now revoked.
        $oldRow = $this->repo->getById($oldId);
        self::assertNotNull($oldRow);
        self::assertFalse((bool) $oldRow['is_active']);
        // New key preserves scope/limit and tags the rotation in its name.
        self::assertIsArray($rotated['record']);
        self::assertSame('original (rotated)', $rotated['record']['name']);
        self::assertSame(250, $rotated['record']['rate_limit_per_hour']);
        self::assertSame('read', $rotated['record']['scope']);
    }

    public function testRotateRefusesNonOwner(): void
    {
        $generated = $this->repo->generate($this->appId);
        /** @var array<string,mixed> $record */
        $record = $generated['record'];
        /** @var string $id */
        $id = $record['id'];

        self::assertNull($this->repo->rotate($id, 'stranger'));
    }

    public function testCountActiveByApplicationOnlyCountsActive(): void
    {
        $a = $this->repo->generate($this->appId, 'a');
        $this->repo->generate($this->appId, 'b');
        /** @var array<string,mixed> $aRecord */
        $aRecord = $a['record'];
        /** @var string $aId */
        $aId = $aRecord['id'];
        $this->repo->revoke($aId, $this->ownerUserId);

        self::assertSame(1, $this->repo->countActiveByApplication($this->appId));
    }

    public function testCountActiveByApplicationsBatchReturnsAllRequestedIdsEvenIfZero(): void
    {
        $other = $this->insertApplication(['zitadel_user_id' => $this->ownerUserId]);
        $this->repo->generate($this->appId, 'k1');
        $this->repo->generate($this->appId, 'k2');

        $counts = $this->repo->countActiveByApplications([$this->appId, $other]);

        self::assertSame(2, $counts[$this->appId]);
        self::assertSame(0, $counts[$other]);
    }

    public function testCountActiveByApplicationsHandlesEmptyInput(): void
    {
        self::assertSame([], $this->repo->countActiveByApplications([]));
    }

    public function testGetUsageStatsReturnsSummaryOrEmpty(): void
    {
        $generated = $this->repo->generate($this->appId, 'stats');
        /** @var array<string,mixed> $record */
        $record = $generated['record'];
        /** @var string $id */
        $id = $record['id'];

        $stats = $this->repo->getUsageStats($id);
        self::assertSame($id, $stats['key_id']);
        // key_prefix is the first 20 chars of the plaintext key.
        self::assertSame(substr($generated['key'], 0, 20), $stats['key_prefix']);
        self::assertTrue((bool) $stats['is_active']);

        self::assertSame([], $this->repo->getUsageStats('00000000-0000-0000-0000-000000000000'));
    }
}
