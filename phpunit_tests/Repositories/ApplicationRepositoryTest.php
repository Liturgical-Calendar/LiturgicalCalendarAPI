<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Repositories;

use LiturgicalCalendar\Api\Repositories\ApplicationRepository;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(ApplicationRepository::class)]
final class ApplicationRepositoryTest extends RepositoryTestCase
{
    private ApplicationRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new ApplicationRepository(self::$pdo);
    }

    public function testCreateReturnsRowWithGeneratedUuidAndDefaults(): void
    {
        $row = $this->repo->create('user-1', 'App One', 'desc', 'https://example.test', 'read');

        self::assertNotEmpty($row['id']);
        self::assertSame('App One', $row['name']);
        self::assertSame('desc', $row['description']);
        self::assertSame('https://example.test', $row['website']);
        self::assertSame('read', $row['requested_scope']);
        self::assertSame('pending', $row['status']);
        self::assertTrue((bool) $row['is_active']);
    }

    public function testCreateRejectsInvalidScope(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid requested_scope');

        $this->repo->create('user-1', 'App', null, null, 'admin');
    }

    public function testGetByUuidAndGetByIdAreAliases(): void
    {
        $created = $this->repo->create('user-1', 'App');
        /** @var string $id */
        $id = $created['id'];

        $byUuid = $this->repo->getByUuid($id);
        $byId   = $this->repo->getById($id);

        self::assertNotNull($byUuid);
        self::assertSame($byUuid, $byId);
    }

    public function testGetByUuidReturnsNullForMissingRow(): void
    {
        self::assertNull($this->repo->getByUuid('00000000-0000-0000-0000-000000000000'));
    }

    public function testGetByUserReturnsRowsInDescendingOrder(): void
    {
        $first = $this->repo->create('user-x', 'First');
        // Slight delay ensures created_at differs by at least 1 microsecond
        // even though the SQL DEFAULT uses CURRENT_TIMESTAMP.
        usleep(2000);
        $second = $this->repo->create('user-x', 'Second');

        $rows = $this->repo->getByUser('user-x');

        self::assertCount(2, $rows);
        self::assertSame($second['id'], $rows[0]['id']);
        self::assertSame($first['id'], $rows[1]['id']);
    }

    public function testUpdateChangesAllowedFieldsAndIgnoresOthers(): void
    {
        $row = $this->repo->create('user-1', 'Original');
        /** @var string $id */
        $id = $row['id'];

        $updated = $this->repo->update($id, 'user-1', [
            'name'        => 'Renamed',
            'description' => 'New desc',
            'website'     => 'https://new.test',
            'status'      => 'approved', // disallowed — must be ignored
        ]);

        self::assertNotNull($updated);
        self::assertSame('Renamed', $updated['name']);
        self::assertSame('New desc', $updated['description']);
        self::assertSame('https://new.test', $updated['website']);
        self::assertSame('pending', $updated['status']);
    }

    public function testUpdateWithEmptyDataReturnsCurrentRowForOwner(): void
    {
        $row = $this->repo->create('user-1', 'X');
        /** @var string $id */
        $id = $row['id'];

        $result = $this->repo->update($id, 'user-1', []);

        self::assertNotNull($result);
        self::assertSame($id, $result['id']);
    }

    public function testUpdateWithEmptyDataReturnsNullForNonOwner(): void
    {
        $row = $this->repo->create('owner', 'X');
        /** @var string $id */
        $id = $row['id'];

        self::assertNull($this->repo->update($id, 'stranger', []));
    }

    public function testUpdateNotOwnerReturnsNull(): void
    {
        $row = $this->repo->create('owner', 'X');
        /** @var string $id */
        $id = $row['id'];

        self::assertNull($this->repo->update($id, 'someone-else', ['name' => 'Hijack']));
    }

    public function testDeactivateAndReactivateFlipIsActive(): void
    {
        $row = $this->repo->create('user-1', 'X');
        /** @var string $id */
        $id = $row['id'];

        self::assertTrue($this->repo->deactivate($id, 'user-1'));
        $afterOff = $this->repo->getByUuid($id);
        self::assertNotNull($afterOff);
        self::assertFalse((bool) $afterOff['is_active']);

        self::assertTrue($this->repo->reactivate($id, 'user-1'));
        $afterOn = $this->repo->getByUuid($id);
        self::assertNotNull($afterOn);
        self::assertTrue((bool) $afterOn['is_active']);
    }

    public function testDeactivateFailsForNonOwner(): void
    {
        $row = $this->repo->create('owner', 'X');
        /** @var string $id */
        $id = $row['id'];

        self::assertFalse($this->repo->deactivate($id, 'stranger'));
    }

    public function testDeleteRemovesRowForOwner(): void
    {
        $row = $this->repo->create('user-1', 'X');
        /** @var string $id */
        $id = $row['id'];

        self::assertTrue($this->repo->delete($id, 'user-1'));
        self::assertNull($this->repo->getByUuid($id));
    }

    public function testDeleteFailsForNonOwner(): void
    {
        $row = $this->repo->create('owner', 'X');
        /** @var string $id */
        $id = $row['id'];

        self::assertFalse($this->repo->delete($id, 'stranger'));
    }

    public function testIsOwnerAndCountByUser(): void
    {
        $this->repo->create('user-a', 'A1');
        $this->repo->create('user-a', 'A2');
        $row = $this->repo->create('user-b', 'B1');
        /** @var string $id */
        $id = $row['id'];

        self::assertTrue($this->repo->isOwner($id, 'user-b'));
        self::assertFalse($this->repo->isOwner($id, 'user-a'));
        self::assertSame(2, $this->repo->countByUser('user-a'));
        self::assertSame(1, $this->repo->countByUser('user-b'));
        self::assertSame(0, $this->repo->countByUser('user-c'));
    }

    public function testGetPendingApplicationsReturnsOnlyPendingInOrder(): void
    {
        $a = $this->repo->create('user-1', 'Pending A');
        usleep(2000);
        $b        = $this->repo->create('user-2', 'Pending B');
        $approved = $this->repo->create('user-3', 'Approved');
        /** @var string $approvedId */
        $approvedId = $approved['id'];
        $this->repo->approveApplication($approvedId, 'admin');

        $pending = $this->repo->getPendingApplications();

        // Pending rows are returned oldest first (created_at ASC).
        self::assertCount(2, $pending);
        self::assertSame($a['id'], $pending[0]['id']);
        self::assertSame($b['id'], $pending[1]['id']);
        self::assertSame(2, $this->repo->countPendingApplications());
    }

    public function testGetAllApplicationsWithoutFilterReturnsEverything(): void
    {
        $this->repo->create('user-1', 'A');
        $this->repo->create('user-2', 'B');

        self::assertCount(2, $this->repo->getAllApplications());
    }

    public function testGetAllApplicationsFiltersByStatus(): void
    {
        $approved = $this->repo->create('user-1', 'X');
        /** @var string $approvedId */
        $approvedId = $approved['id'];
        $this->repo->approveApplication($approvedId, 'admin');
        $this->repo->create('user-2', 'Y'); // stays pending

        $approvedRows = $this->repo->getAllApplications('approved');
        $pendingRows  = $this->repo->getAllApplications('pending');

        self::assertCount(1, $approvedRows);
        self::assertSame($approvedId, $approvedRows[0]['id']);
        self::assertCount(1, $pendingRows);
    }

    public function testGetAllApplicationsRejectsBadFilter(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->repo->getAllApplications('not-a-status');
    }

    public function testApproveRejectRevokeStateMachine(): void
    {
        $row = $this->repo->create('user-1', 'X');
        /** @var string $id */
        $id = $row['id'];

        $approved = $this->repo->approveApplication($id, 'admin', 'looks good');
        self::assertNotNull($approved);
        self::assertSame('approved', $approved['status']);
        self::assertSame('admin', $approved['reviewed_by']);
        self::assertSame('looks good', $approved['review_notes']);
        self::assertTrue($this->repo->isApproved($id));

        // Cannot approve an already-approved row again — UPDATE matches nothing.
        self::assertNull($this->repo->approveApplication($id, 'admin'));

        // Revoke flips approved → revoked.
        $revoked = $this->repo->revokeApplication($id, 'admin', 'misuse');
        self::assertNotNull($revoked);
        self::assertSame('revoked', $revoked['status']);
        self::assertFalse($this->repo->isApproved($id));

        // Revoke is a no-op on a revoked row.
        self::assertNull($this->repo->revokeApplication($id, 'admin'));
    }

    public function testRejectAndResubmit(): void
    {
        $row = $this->repo->create('user-1', 'X');
        /** @var string $id */
        $id = $row['id'];

        $rejected = $this->repo->rejectApplication($id, 'admin', 'no good');
        self::assertNotNull($rejected);
        self::assertSame('rejected', $rejected['status']);

        // Reject only matches pending rows.
        self::assertNull($this->repo->rejectApplication($id, 'admin'));

        // Owner can resubmit a rejected row.
        $resubmitted = $this->repo->resubmitApplication($id, 'user-1');
        self::assertNotNull($resubmitted);
        self::assertSame('pending', $resubmitted['status']);
        self::assertNull($resubmitted['reviewed_by']);
        self::assertNull($resubmitted['review_notes']);

        // Approve-after-reject path is allowed (status IN (pending, rejected)).
        $rejectedAgain = $this->repo->rejectApplication($id, 'admin');
        self::assertNotNull($rejectedAgain);
        $approvedAfterReject = $this->repo->approveApplication($id, 'admin');
        self::assertNotNull($approvedAfterReject);
        self::assertSame('approved', $approvedAfterReject['status']);
    }

    public function testResubmitFailsForNonOwner(): void
    {
        $row = $this->repo->create('owner', 'X');
        /** @var string $id */
        $id = $row['id'];
        $this->repo->rejectApplication($id, 'admin');

        self::assertNull($this->repo->resubmitApplication($id, 'stranger'));
    }

    public function testIsApprovedRequiresActive(): void
    {
        $row = $this->repo->create('user-1', 'X');
        /** @var string $id */
        $id = $row['id'];
        $this->repo->approveApplication($id, 'admin');
        $this->repo->deactivate($id, 'user-1');

        self::assertFalse($this->repo->isApproved($id));
    }
}
