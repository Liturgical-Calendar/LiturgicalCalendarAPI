<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Repositories;

use LiturgicalCalendar\Api\Repositories\AccessRequestRepository;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(AccessRequestRepository::class)]
final class AccessRequestRepositoryTest extends RepositoryTestCase
{
    private AccessRequestRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new AccessRequestRepository(self::$pdo);
    }

    /** @return list<array{object_type:string,object_id:string,relation:string}> */
    private function samplePermissions(): array
    {
        return [
            ['object_type' => 'national_calendar', 'object_id' => 'IT', 'relation' => 'editor'],
            ['object_type' => 'diocesan_calendar', 'object_id' => 'romamo_it', 'relation' => 'viewer'],
        ];
    }

    public function testCreateReturnsUuidAndPersistsPermissionsAsJsonb(): void
    {
        $id = $this->repo->create(
            'user-1',
            'a@b.test',
            'Alice',
            'calendar_editor',
            $this->samplePermissions(),
            'because',
            'I have credentials'
        );

        self::assertNotEmpty($id);

        $row = $this->repo->getById($id);
        self::assertNotNull($row);
        self::assertSame('user-1', $row['zitadel_user_id']);
        self::assertSame('a@b.test', $row['user_email']);
        self::assertSame('Alice', $row['user_name']);
        self::assertSame('calendar_editor', $row['requested_role']);
        self::assertSame('pending', $row['status']);
        // getById decodes JSONB permissions back to an array; assertEquals
        // because PG may have reordered the keys within each tuple.
        self::assertEquals($this->samplePermissions(), $row['permissions']);
        self::assertSame('because', $row['justification']);
        self::assertSame('I have credentials', $row['credentials']);
    }

    public function testCreateRejectsUnknownRole(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid role');

        $this->repo->create('u', 'e@e.test', null, 'bogus_role', []);
    }

    public function testGetByIdReturnsNullForMissingRow(): void
    {
        self::assertNull($this->repo->getById('00000000-0000-0000-0000-000000000000'));
    }

    public function testHasPendingRequestIsScopedToUserAndRole(): void
    {
        $this->repo->create('user-1', 'a@b.test', null, 'developer', []);

        self::assertTrue($this->repo->hasPendingRequest('user-1', 'developer'));
        self::assertFalse($this->repo->hasPendingRequest('user-1', 'calendar_editor'));
        self::assertFalse($this->repo->hasPendingRequest('user-2', 'developer'));
        // Defense-in-depth: unknown roles can't be pending.
        self::assertFalse($this->repo->hasPendingRequest('user-1', 'bogus'));
    }

    public function testHasApprovedRoleTracksAcrossRoles(): void
    {
        $devId = $this->repo->create('user-1', 'a@b.test', null, 'developer', []);

        self::assertFalse($this->repo->hasApprovedRole('user-1'));

        self::assertTrue($this->repo->approve($devId, 'admin'));
        self::assertTrue($this->repo->hasApprovedRole('user-1'));
    }

    public function testGetByUserReturnsRowsForThatUserOnly(): void
    {
        $this->repo->create('user-1', 'a@b.test', null, 'developer', []);
        $this->repo->create('user-1', 'a@b.test', null, 'calendar_editor', []);
        $this->repo->create('user-2', 'b@b.test', null, 'developer', []);

        $rows = $this->repo->getByUser('user-1');

        self::assertCount(2, $rows);
        foreach ($rows as $row) {
            self::assertSame('user-1', $row['zitadel_user_id']);
        }
    }

    public function testGetPendingReturnsOnlyPendingOldestFirst(): void
    {
        $first = $this->repo->create('user-1', 'a@b.test', null, 'developer', []);
        usleep(2000);
        $second     = $this->repo->create('user-2', 'b@b.test', null, 'developer', []);
        $approvedId = $this->repo->create('user-3', 'c@b.test', null, 'developer', []);
        $this->repo->approve($approvedId, 'admin');

        $pending = $this->repo->getPending();

        self::assertCount(2, $pending);
        self::assertSame($first, $pending[0]['id']);
        self::assertSame($second, $pending[1]['id']);
        self::assertSame(2, $this->repo->countPending());
    }

    public function testGetAllOptionallyFiltersByStatus(): void
    {
        $this->repo->create('user-1', 'a@b.test', null, 'developer', []);
        $approvedId = $this->repo->create('user-2', 'b@b.test', null, 'developer', []);
        $this->repo->approve($approvedId, 'admin');

        self::assertCount(2, $this->repo->getAll());
        self::assertCount(1, $this->repo->getAll('approved'));
        self::assertCount(1, $this->repo->getAll('pending'));
    }

    public function testGetAllRejectsBadStatus(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->repo->getAll('weird');
    }

    public function testGetCountsReportsAllFourBuckets(): void
    {
        $a = $this->repo->create('user-1', 'a@b.test', null, 'developer', []);
        $b = $this->repo->create('user-2', 'b@b.test', null, 'developer', []);
        $c = $this->repo->create('user-3', 'c@b.test', null, 'developer', []);
        $d = $this->repo->create('user-4', 'd@b.test', null, 'developer', []);

        $this->repo->approve($a, 'admin');
        $this->repo->reject($b, 'admin');
        $this->repo->approve($c, 'admin');
        $this->repo->revoke($c, 'admin');
        // $d stays pending.

        $counts = $this->repo->getCounts();

        self::assertSame(1, $counts['pending']);
        self::assertSame(1, $counts['approved']);
        self::assertSame(1, $counts['rejected']);
        self::assertSame(1, $counts['revoked']);
    }

    public function testApproveRejectRevokeStateGuards(): void
    {
        $id = $this->repo->create('user-1', 'a@b.test', null, 'developer', []);

        self::assertTrue($this->repo->approve($id, 'admin', 'ok'));
        // Approving twice does nothing (status != 'pending').
        self::assertFalse($this->repo->approve($id, 'admin'));
        // Reject after approve is rejected (only matches pending).
        self::assertFalse($this->repo->reject($id, 'admin'));
        // Revoke flips approved → revoked.
        self::assertTrue($this->repo->revoke($id, 'admin', 'cleanup'));
        // Revoke again is a no-op (status != 'approved').
        self::assertFalse($this->repo->revoke($id, 'admin'));

        $row = $this->repo->getById($id);
        self::assertNotNull($row);
        self::assertSame('revoked', $row['status']);
    }

    public function testResubmitRequiresRejectedStatusAndUpdatesPermissions(): void
    {
        $id = $this->repo->create('user-1', 'a@b.test', null, 'developer', $this->samplePermissions());
        // Resubmit fails while still pending.
        self::assertFalse($this->repo->resubmit($id, []));

        self::assertTrue($this->repo->reject($id, 'admin', 'try again'));

        $newPerms = [
            ['object_type' => 'test_definition', 'object_id' => 'all', 'relation' => 'editor'],
        ];
        self::assertTrue($this->repo->resubmit($id, $newPerms, 'better case'));

        $row = $this->repo->getById($id);
        self::assertNotNull($row);
        self::assertSame('pending', $row['status']);
        // Postgres JSONB normalises key order, so assertSame would be brittle;
        // assertEquals compares contents regardless of insertion order.
        self::assertEquals($newPerms, $row['permissions']);
        self::assertSame('better case', $row['justification']);
        self::assertNull($row['reviewed_by']);
    }

    public function testResubmitKeepsExistingJustificationWhenNullProvided(): void
    {
        $id = $this->repo->create('user-1', 'a@b.test', null, 'developer', [], 'original-reason');
        $this->repo->reject($id, 'admin');

        self::assertTrue($this->repo->resubmit($id, []));

        $row = $this->repo->getById($id);
        self::assertNotNull($row);
        self::assertSame('original-reason', $row['justification']);
    }

    public function testCascadeRevokeByRoleOnlyTouchesApprovedMatches(): void
    {
        // A unique partial index disallows two pending rows for the same
        // (user, role), so we approve as we go to free the slot.
        $approvedDev = $this->repo->create('user-1', 'a@b.test', null, 'developer', []);
        $this->repo->approve($approvedDev, 'admin');

        $approvedEditor = $this->repo->create('user-1', 'a@b.test', null, 'calendar_editor', []);
        $this->repo->approve($approvedEditor, 'admin');

        // Now there's no pending developer for user-1, so we can create one.
        $pendingDev = $this->repo->create('user-1', 'a@b.test', null, 'developer', []);

        $otherUser = $this->repo->create('user-2', 'b@b.test', null, 'developer', []);
        $this->repo->approve($otherUser, 'admin');

        $touched = $this->repo->cascadeRevokeByRole('user-1', 'developer', 'because');

        self::assertSame(1, $touched);
        $devRow = $this->repo->getById($approvedDev);
        self::assertNotNull($devRow);
        self::assertSame('revoked', $devRow['status']);
        self::assertSame('system:cascade', $devRow['reviewed_by']);
        // Other rows untouched.
        $editorRow = $this->repo->getById($approvedEditor);
        self::assertNotNull($editorRow);
        self::assertSame('approved', $editorRow['status']);
        $pendingRow = $this->repo->getById($pendingDev);
        self::assertNotNull($pendingRow);
        self::assertSame('pending', $pendingRow['status']);
        $otherRow = $this->repo->getById($otherUser);
        self::assertNotNull($otherRow);
        self::assertSame('approved', $otherRow['status']);
    }

    public function testUpdateZitadelSyncStatusValidatesValue(): void
    {
        $id = $this->repo->create('user-1', 'a@b.test', null, 'developer', []);

        $this->repo->updateZitadelSyncStatus($id, 'failed', 'boom');
        $row = $this->repo->getById($id);
        self::assertNotNull($row);
        self::assertSame('failed', $row['zitadel_sync_status']);
        self::assertSame('boom', $row['zitadel_sync_error']);

        $this->expectException(\InvalidArgumentException::class);
        $this->repo->updateZitadelSyncStatus($id, 'nope');
    }

    public function testRemovePermissionTupleOnlyTouchesApprovedRowsWithMatch(): void
    {
        $approved        = $this->repo->create('user-1', 'a@b.test', null, 'developer', $this->samplePermissions());
        $approvedNoMatch = $this->repo->create(
            'user-1',
            'a@b.test',
            null,
            'calendar_editor',
            [['object_type' => 'wider_region', 'object_id' => 'Europe', 'relation' => 'editor']]
        );
        $pending         = $this->repo->create('user-1', 'a@b.test', null, 'test_editor', $this->samplePermissions());

        $this->repo->approve($approved, 'admin');
        $this->repo->approve($approvedNoMatch, 'admin');
        // $pending stays pending — must not be touched.

        $changed = $this->repo->removePermissionTuple('user-1', 'national_calendar', 'IT', 'editor');

        self::assertSame(1, $changed);

        $row = $this->repo->getById($approved);
        self::assertNotNull($row);
        /** @var list<array{object_type:string,object_id:string,relation:string}> $perms */
        $perms = $row['permissions'];
        self::assertCount(1, $perms);
        self::assertSame('diocesan_calendar', $perms[0]['object_type']);

        // Pending row untouched.
        $pendingRow = $this->repo->getById($pending);
        self::assertNotNull($pendingRow);
        self::assertCount(2, $pendingRow['permissions']);
    }
}
