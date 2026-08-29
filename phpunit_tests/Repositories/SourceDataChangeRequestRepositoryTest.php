<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Repositories;

use LiturgicalCalendar\Api\Enum\ChangeOperation;
use LiturgicalCalendar\Api\Enum\ChangeReviewStatus;
use LiturgicalCalendar\Api\Enum\Rite;
use LiturgicalCalendar\Api\Repositories\SourceDataChangeRequestRepository;
use LiturgicalCalendar\Api\Services\ChangeResource;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(SourceDataChangeRequestRepository::class)]
final class SourceDataChangeRequestRepositoryTest extends RepositoryTestCase
{
    private SourceDataChangeRequestRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new SourceDataChangeRequestRepository(self::$pdo);
    }

    /** @return list<array{path: string, operation: ChangeOperation, content: ?string}> */
    private function calendarWithTranslations(): array
    {
        return [
            [
                'path'      => 'jsondata/sourcedata/rite/roman/calendars/nations/US/US.json',
                'operation' => ChangeOperation::CREATE,
                'content'   => '{"litcal":[]}',
            ],
            [
                'path'      => 'jsondata/sourcedata/rite/roman/calendars/nations/US/i18n/en_US.json',
                'operation' => ChangeOperation::CREATE,
                'content'   => '{"key":"value"}',
            ],
        ];
    }

    private function submitUsa(string $sub = 'user-1'): string
    {
        return $this->repo->submitBatch(
            ChangeResource::nationalCalendar(Rite::ROMAN, 'US'),
            $this->calendarWithTranslations(),
            $sub,
            'Alice',
            'alice@example.test',
            true,
            ['authorized_by' => 'admin']
        );
    }

    public function testSubmitBatchPersistsEveryFileUnderOneBatchId(): void
    {
        $batchId = $this->submitUsa();

        $rows = $this->repo->getBatch($batchId);
        self::assertCount(2, $rows);

        foreach ($rows as $row) {
            self::assertSame($batchId, $row['batch_id']);
            self::assertSame('national_calendar', $row['resource_type']);
            self::assertSame('roman/US', $row['resource_id']);
            self::assertSame('submitted', $row['review_status']);
            self::assertSame('none', $row['publication_status']);
            self::assertSame('user-1', $row['submitted_by_sub']);
            self::assertSame('Alice', $row['submitted_by_name']);
            self::assertSame('alice@example.test', $row['submitted_by_email']);
            self::assertTrue($row['submitted_by_email_verified']);
        }

        // Ordered by path — but under this database's en_US.utf8 collation, ordering is
        // linguistic (alphabetic, primarily case-insensitive) rather than byte order, so
        // "i18n/..." sorts before "US.json": 'i' precedes 'u' in the alphabet. A nation
        // whose code starts with a letter before 'i' (e.g. "DE") would see the opposite
        // order. Do not read this as "the calendar file always sorts first" — it doesn't.
        self::assertSame('jsondata/sourcedata/rite/roman/calendars/nations/US/i18n/en_US.json', $rows[0]['path']);
        self::assertSame('jsondata/sourcedata/rite/roman/calendars/nations/US/US.json', $rows[1]['path']);
    }

    public function testGetBatchAttachesTheAdminPermissionTupleForFiltering(): void
    {
        $rows = $this->repo->getBatch($this->submitUsa());

        self::assertSame(
            [['object_type' => 'national_calendar', 'object_id' => 'roman/US', 'relation' => 'admin']],
            $rows[0]['permissions']
        );
    }

    public function testMetadataRoundTripsAsAnArray(): void
    {
        $rows = $this->repo->getBatch($this->submitUsa());

        self::assertSame(['authorized_by' => 'admin'], $rows[0]['metadata']);
    }

    public function testResubmittingReplacesTheSubmittersPendingRowsForThatResource(): void
    {
        $first = $this->submitUsa();

        $second = $this->repo->submitBatch(
            ChangeResource::nationalCalendar(Rite::ROMAN, 'US'),
            [
                [
                    'path'      => 'jsondata/sourcedata/rite/roman/calendars/nations/US/US.json',
                    'operation' => ChangeOperation::UPDATE,
                    'content'   => '{"litcal":[{"event_key":"NewFeast"}]}',
                ],
            ],
            'user-1',
            'Alice',
            'alice@example.test',
            true
        );

        self::assertNotSame($first, $second);
        self::assertSame([], $this->repo->getBatch($first), 'the superseded batch should be gone');

        $rows = $this->repo->getBatch($second);
        self::assertCount(1, $rows);
        self::assertSame('update', $rows[0]['operation']);
    }

    public function testAnotherSubmittersPendingRowsAreNotReplaced(): void
    {
        $mine   = $this->submitUsa('user-1');
        $theirs = $this->repo->submitBatch(
            ChangeResource::nationalCalendar(Rite::ROMAN, 'US'),
            [
                [
                    'path'      => 'jsondata/sourcedata/rite/roman/calendars/nations/US/i18n/es_US.json',
                    'operation' => ChangeOperation::CREATE,
                    'content'   => '{"key":"valor"}',
                ],
            ],
            'user-2',
            'Bob',
            null,
            false
        );

        self::assertCount(2, $this->repo->getBatch($mine));
        self::assertCount(1, $this->repo->getBatch($theirs));
    }

    public function testDeleteOperationsCarryNoContent(): void
    {
        $batchId = $this->repo->submitBatch(
            ChangeResource::diocesanCalendar(Rite::ROMAN, 'romamo_it'),
            [
                [
                    'path'      => 'jsondata/sourcedata/rite/roman/calendars/dioceses/IT/romamo_it/Diocesi di Roma.json',
                    'operation' => ChangeOperation::DELETE,
                    'content'   => null,
                ],
            ],
            'user-1',
            'Alice',
            'alice@example.test',
            true
        );

        $rows = $this->repo->getBatch($batchId);
        self::assertSame('delete', $rows[0]['operation']);
        self::assertNull($rows[0]['content']);
    }

    public function testSubmitBatchRejectsAnEmptyFileList(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('at least one file');

        $this->repo->submitBatch(
            ChangeResource::nationalCalendar(Rite::ROMAN, 'US'),
            [],
            'user-1',
            'Alice',
            'alice@example.test',
            true
        );
    }

    public function testGetByIdReturnsNullForAnUnknownId(): void
    {
        self::assertNull($this->repo->getById('00000000-0000-0000-0000-000000000000'));
    }

    public function testGetByIdReturnsAFullyHydratedRow(): void
    {
        $batchId = $this->submitUsa();
        $rows    = $this->repo->getBatch($batchId);
        $rowId   = $rows[0]['id'];
        self::assertIsString($rowId);

        $row = $this->repo->getById($rowId);

        self::assertNotNull($row);
        self::assertSame($rowId, $row['id']);
        self::assertSame($batchId, $row['batch_id']);
        // hydrate() decoded the JSONB column into an array, not a JSON string.
        self::assertSame(['authorized_by' => 'admin'], $row['metadata']);
        // hydrate() normalised PDO's pgsql 't'/'f' into a real PHP bool.
        self::assertIsBool($row['submitted_by_email_verified']);
        self::assertTrue($row['submitted_by_email_verified']);
        // hydrate() attached the synthetic permissions tuple on the single-row path too,
        // not just on getBatch()'s multi-row path.
        self::assertSame(
            [['object_type' => 'national_calendar', 'object_id' => 'roman/US', 'relation' => 'admin']],
            $row['permissions']
        );
    }

    public function testGetBatchReturnsAnEmptyArrayForAnUnknownBatch(): void
    {
        self::assertSame([], $this->repo->getBatch('00000000-0000-0000-0000-000000000000'));
    }

    public function testAFailedInsertRollsBackTheEntireBatchAndTheSupersedeDelete(): void
    {
        $first          = $this->submitUsa();
        $batchIdsBefore = $this->allBatchIds();

        $failingFiles = [
            [
                'path'      => 'jsondata/sourcedata/rite/roman/calendars/nations/US/US.json',
                'operation' => ChangeOperation::UPDATE,
                'content'   => '{"litcal":[{"event_key":"NewFeast"}]}',
            ],
            // Violates chk_scr_write_has_content (Task 1): a non-delete operation must
            // carry content. This is what forces the mid-batch failure.
            [
                'path'      => 'jsondata/sourcedata/rite/roman/calendars/nations/US/i18n/en_US.json',
                'operation' => ChangeOperation::CREATE,
                'content'   => null,
            ],
        ];

        try {
            $this->repo->submitBatch(
                ChangeResource::nationalCalendar(Rite::ROMAN, 'US'),
                $failingFiles,
                'user-1',
                'Alice',
                'alice@example.test',
                true
            );
            self::fail('Expected a PDOException from the chk_scr_write_has_content violation');
        } catch (\PDOException $e) {
            // (a) the exception propagated out of submitBatch() -- reaching this catch
            // block (rather than the self::fail() above) is that assertion.
        }

        // (b) no trace of the failed batch survives: no new batch id appears at all, and
        // specifically not even the one row (the UPDATE) that was successfully inserted
        // before the second, invalid, insert aborted the transaction.
        self::assertSame($batchIdsBefore, $this->allBatchIds(), 'no new batch id should exist after the rollback');
        self::assertSame([], $this->repo->getBatch('11111111-1111-1111-1111-111111111111'));

        $stmt = self::$pdo->query("SELECT COUNT(*) FROM sourcedata_change_requests WHERE operation = 'update'");
        self::assertNotFalse($stmt);
        self::assertSame(0, (int) $stmt->fetchColumn(), 'the successful first insert of the failed batch must have rolled back too');

        // (c) the previously submitted batch -- the thing the supersede DELETE targeted --
        // is still fully intact, proving the DELETE rolled back along with the failed INSERT.
        $rows = $this->repo->getBatch($first);
        self::assertCount(2, $rows);
        foreach ($rows as $row) {
            self::assertSame($first, $row['batch_id']);
            self::assertSame('submitted', $row['review_status']);
        }
    }

    /** @return list<string> */
    private function allBatchIds(): array
    {
        $stmt = self::$pdo->query('SELECT DISTINCT batch_id::text FROM sourcedata_change_requests ORDER BY batch_id');
        self::assertNotFalse($stmt);

        /** @var list<string> $ids */
        $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

        return $ids;
    }

    public function testApproveBatchStampsTheApproverOnEveryRow(): void
    {
        $batchId = $this->submitUsa();

        self::assertSame(2, $this->repo->approveBatch($batchId, 'admin-1'));

        foreach ($this->repo->getBatch($batchId) as $row) {
            self::assertSame('approved', $row['review_status']);
            self::assertSame('admin-1', $row['approved_by_sub']);
            self::assertNotNull($row['approved_at']);
        }
    }

    public function testSelfApprovalIsRecordedAsSuch(): void
    {
        $batchId = $this->submitUsa('admin-1');
        $this->repo->approveBatch($batchId, 'admin-1');

        $row = $this->repo->getBatch($batchId)[0];
        self::assertSame($row['submitted_by_sub'], $row['approved_by_sub']);
    }

    public function testListBySubmitterReturnsOneEntryPerBatch(): void
    {
        $this->submitUsa('user-1');
        $this->repo->submitBatch(
            ChangeResource::widerRegion('Americas'),
            [
                [
                    'path'      => 'jsondata/sourcedata/rite/roman/calendars/wider_regions/Americas/Americas.json',
                    'operation' => ChangeOperation::UPDATE,
                    'content'   => '{"litcal":[]}',
                ],
            ],
            'user-1',
            'Alice',
            'alice@example.test',
            true
        );

        $batches = $this->repo->listBySubmitter('user-1');

        self::assertCount(2, $batches);
        // Both batches came from the same submitter, so both group by their own batch_id
        // regardless of ordering: one has 2 files (USA), the other has 1 (Americas).
        // Do not assert a specific slot for either count -- ordering is by created_at
        // DESC, which testListingIsNewestFirst covers explicitly and deliberately (by
        // forcing the timestamps apart), rather than relying on wall-clock timing here.
        self::assertSame([1, 2], self::sortInts([$batches[0]['file_count'], $batches[1]['file_count']]));
    }

    /**
     * @param array<int, int> $ints
     * @return array<int, int>
     */
    private static function sortInts(array $ints): array
    {
        sort($ints);

        return $ints;
    }

    public function testListBySubmitterExcludesOtherSubmitters(): void
    {
        $this->submitUsa('user-1');
        $this->submitUsa('user-2');

        $batches = $this->repo->listBySubmitter('user-1');

        self::assertCount(1, $batches);
        self::assertSame('user-1', $batches[0]['submitted_by_sub']);
    }

    public function testListBatchesCarryTheirPathsAndPermissions(): void
    {
        $this->submitUsa('user-1');

        $batch = $this->repo->listBySubmitter('user-1')[0];

        self::assertSame(2, $batch['file_count']);
        self::assertContains('jsondata/sourcedata/rite/roman/calendars/nations/US/US.json', $batch['paths']);
        self::assertContains('jsondata/sourcedata/rite/roman/calendars/nations/US/i18n/en_US.json', $batch['paths']);
        self::assertSame(
            [['object_type' => 'national_calendar', 'object_id' => 'roman/US', 'relation' => 'admin']],
            $batch['permissions']
        );
    }

    public function testListAllCanFilterByReviewStatus(): void
    {
        $approved = $this->submitUsa('user-1');
        $this->repo->approveBatch($approved, 'admin-1');
        $this->submitUsa('user-2');

        self::assertCount(2, $this->repo->listAll());
        self::assertCount(1, $this->repo->listAll(ChangeReviewStatus::APPROVED));
        self::assertCount(1, $this->repo->listAll(ChangeReviewStatus::SUBMITTED));
    }

    public function testCountsMatchTheListings(): void
    {
        $this->submitUsa('user-1');
        $this->submitUsa('user-2');

        // Neither submission was approved/rejected, so both batches are still in
        // review_status = 'submitted' -- countAll() and countAll(SUBMITTED) must agree.
        self::assertSame(2, $this->repo->countAll());
        self::assertSame(2, $this->repo->countAll(ChangeReviewStatus::SUBMITTED));
        self::assertSame(0, $this->repo->countAll(ChangeReviewStatus::APPROVED));
        self::assertSame(1, $this->repo->countBySubmitter('user-1'));
    }

    public function testListingIsNewestFirst(): void
    {
        $older = $this->submitUsa('user-1');
        self::$pdo->exec("UPDATE sourcedata_change_requests SET created_at = NOW() - INTERVAL '1 day'");
        $newer = $this->repo->submitBatch(
            ChangeResource::widerRegion('Europe'),
            [
                [
                    'path'      => 'jsondata/sourcedata/rite/roman/calendars/wider_regions/Europe/Europe.json',
                    'operation' => ChangeOperation::UPDATE,
                    'content'   => '{"litcal":[]}',
                ],
            ],
            'user-1',
            'Alice',
            'alice@example.test',
            true
        );

        $batches = $this->repo->listBySubmitter('user-1');

        self::assertSame($newer, $batches[0]['batch_id']);
        self::assertSame($older, $batches[1]['batch_id']);
    }

    public function testListingBreaksATiedCreatedAtDeterministically(): void
    {
        $first  = $this->submitUsa('user-1');
        $second = $this->repo->submitBatch(
            ChangeResource::widerRegion('Europe'),
            [
                [
                    'path'      => 'jsondata/sourcedata/rite/roman/calendars/wider_regions/Europe/Europe.json',
                    'operation' => ChangeOperation::UPDATE,
                    'content'   => '{"litcal":[]}',
                ],
            ],
            'user-1',
            'Alice',
            'alice@example.test',
            true
        );

        // Force a genuine tie: both batches now share the exact same created_at, which
        // is the scenario ORDER BY MIN(created_at) DESC alone cannot resolve.
        self::$pdo->exec("UPDATE sourcedata_change_requests SET created_at = TIMESTAMP '2026-01-01 00:00:00'");

        $firstCall  = $this->repo->listBySubmitter('user-1');
        $secondCall = $this->repo->listBySubmitter('user-1');

        self::assertSame(
            array_column($firstCall, 'batch_id'),
            array_column($secondCall, 'batch_id'),
            'two successive calls over a tie must return the same order'
        );

        // The assertion that actually pins the bug: page through the tie with
        // limit 1 and prove no batch is repeated or dropped across the two pages.
        $page0 = $this->repo->listBySubmitter('user-1', null, 1, 0);
        $page1 = $this->repo->listBySubmitter('user-1', null, 1, 1);

        self::assertCount(1, $page0);
        self::assertCount(1, $page1);

        $pagedIds = [$page0[0]['batch_id'], $page1[0]['batch_id']];
        sort($pagedIds);
        $expectedIds = [$first, $second];
        sort($expectedIds);

        self::assertNotSame($page0[0]['batch_id'], $page1[0]['batch_id'], 'paging through a tie must not repeat a batch');
        self::assertSame($expectedIds, $pagedIds, 'the two pages together must cover both batches, with none dropped');
    }

    public function testRejectBatchRecordsTheReason(): void
    {
        $batchId = $this->submitUsa();

        self::assertSame(2, $this->repo->rejectBatch($batchId, 'admin-1', 'Wrong feast rank'));

        foreach ($this->repo->getBatch($batchId) as $row) {
            self::assertSame('rejected', $row['review_status']);
            self::assertSame('admin-1', $row['approved_by_sub']);
            self::assertSame('Wrong feast rank', $row['rejected_reason']);
        }
    }

    public function testWithdrawBatchIsScopedToItsOwnSubmitter(): void
    {
        $batchId = $this->submitUsa('user-1');

        self::assertSame(0, $this->repo->withdrawBatch($batchId, 'user-2'), 'another user must not withdraw it');

        foreach ($this->repo->getBatch($batchId) as $row) {
            self::assertSame('submitted', $row['review_status'], "user-2's failed withdraw must not have touched the batch");
        }

        self::assertSame(2, $this->repo->withdrawBatch($batchId, 'user-1'));

        self::assertSame('withdrawn', $this->repo->getBatch($batchId)[0]['review_status']);
    }

    public function testADecidedBatchCannotBeDecidedAgain(): void
    {
        $batchId = $this->submitUsa();
        $this->repo->approveBatch($batchId, 'admin-1');

        $approvedAt = $this->repo->getBatch($batchId)[0]['approved_at'];

        self::assertSame(0, $this->repo->rejectBatch($batchId, 'admin-2', 'too late'));
        self::assertSame(0, $this->repo->approveBatch($batchId, 'admin-2'));

        $row = $this->repo->getBatch($batchId)[0];
        self::assertSame('approved', $row['review_status']);
        self::assertSame('admin-1', $row['approved_by_sub']);
        // Both no-op decide attempts must leave the original decision's timestamp
        // untouched too, not just its status and decider -- decideBatch() writes
        // decider and timestamp in the same UPDATE, but the test should prove that
        // rather than leave it inferred from the two columns above.
        self::assertSame($approvedAt, $row['approved_at']);
    }
}
