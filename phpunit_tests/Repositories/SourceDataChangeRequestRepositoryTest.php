<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Repositories;

use LiturgicalCalendar\Api\Enum\ChangeOperation;
use LiturgicalCalendar\Api\Enum\ChangePublicationStatus;
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
        )['batch_id'];
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

    /**
     * Replacement is per-PATH, not per-resource: the incoming batch stages `US.json`, so
     * the row for that path is replaced and the batch id it belonged to stops existing.
     * (The older name of this test said "for that resource", which is precisely the keying
     * the supersede does NOT use.)
     *
     * What the incoming batch does NOT restage is carried forward rather than deleted with
     * it: `i18n/en_US.json` is re-parented onto the new batch, content and `created_at`
     * intact, so there is still exactly one reviewable unit and it is the submitter's
     * cumulative proposal. Deleting it was silent data loss — see the repository's class
     * docblock.
     */
    public function testResubmittingReplacesTheSubmittersPendingBatchForThatPath(): void
    {
        $calendarPath = 'jsondata/sourcedata/rite/roman/calendars/nations/US/US.json';
        $i18nPath     = 'jsondata/sourcedata/rite/roman/calendars/nations/US/i18n/en_US.json';

        $first     = $this->submitUsa();
        $firstRows = $this->repo->getBatch($first);
        self::assertSame([$i18nPath, $calendarPath], array_column($firstRows, 'path'));
        $i18nCreatedAt = $firstRows[0]['created_at'];

        $second = $this->repo->submitBatch(
            ChangeResource::nationalCalendar(Rite::ROMAN, 'US'),
            [
                [
                    'path'      => $calendarPath,
                    'operation' => ChangeOperation::UPDATE,
                    'content'   => '{"litcal":[{"event_key":"NewFeast"}]}',
                ],
            ],
            'user-1',
            'Alice',
            'alice@example.test',
            true
        )['batch_id'];

        self::assertNotSame($first, $second);
        self::assertSame([], $this->repo->getBatch($first), 'the superseded batch id must no longer exist');

        $rows = $this->repo->getBatch($second);
        self::assertSame([$i18nPath, $calendarPath], array_column($rows, 'path'), 'the untouched i18n row is carried forward, not deleted');

        $carried  = $rows[0];
        $replaced = $rows[1];

        self::assertSame('update', $replaced['operation']);
        self::assertSame('{"litcal":[{"event_key":"NewFeast"}]}', $replaced['content']);

        // The carried-forward row keeps everything but its batch id: `created_at` records
        // when the CONTENT was written, and findUnpublishedContent() orders by it.
        self::assertSame('{"key":"value"}', $carried['content']);
        self::assertSame($i18nCreatedAt, $carried['created_at'], 'a carried-forward row keeps its original created_at');
        self::assertSame('submitted', $carried['review_status']);
        self::assertSame('create', $carried['operation'], 'and its original operation');
    }

    /**
     * A superseded batch id stops existing, so the caller is told which ids this submission
     * folded into itself — otherwise a batch the client was tracking would simply vanish
     * from its listing. The ids do not mean the work was discarded: anything the incoming
     * request did not restage moved to the new batch.
     */
    public function testSupersedingReportsTheBatchIdsItReplaced(): void
    {
        $first = $this->submitUsa();

        $second = $this->repo->submitBatch(
            ChangeResource::nationalCalendar(Rite::ROMAN, 'US'),
            [
                [
                    'path'      => 'jsondata/sourcedata/rite/roman/calendars/nations/US/US.json',
                    'operation' => ChangeOperation::UPDATE,
                    'content'   => '{"litcal":[]}',
                ],
            ],
            'user-1',
            'Alice',
            'alice@example.test',
            true
        );

        self::assertSame([$first], $second['superseded_batch_ids']);
        // The i18n row moved to the new batch even though this submission never staged that
        // path, so the id names a batch that was folded in rather than one thrown away.
        self::assertSame([], $this->repo->getBatch($first));
        self::assertCount(2, $this->repo->getBatch($second['batch_id']));
    }

    /**
     * The carry-forward reaches only the submitter's OWN still-submitted rows.
     *
     * It re-parents rows onto a new batch id, which is a write, so it needs the same two
     * guards the delete beside it has: another submitter's pending work must never be moved
     * into this submitter's batch (it would hand them someone else's proposal to have
     * approved under their name), and an approved batch must never be moved either — an
     * approval is a decision, and dragging its rows into a fresh `submitted` batch would
     * quietly un-decide them.
     */
    public function testCarryForwardNeverMovesAnotherSubmittersOrAnApprovedRow(): void
    {
        $calendarPath = 'jsondata/sourcedata/rite/roman/calendars/nations/US/US.json';

        $theirs   = $this->submitUsa('user-2');
        $approved = $this->submitUsa('user-1');
        self::assertSame(2, $this->repo->approveBatch($approved, 'reviewer-1'));

        $mine = $this->repo->submitBatch(
            ChangeResource::nationalCalendar(Rite::ROMAN, 'US'),
            [
                [
                    'path'      => $calendarPath,
                    'operation' => ChangeOperation::UPDATE,
                    'content'   => '{"litcal":[{"event_key":"MyEdit"}]}',
                ],
            ],
            'user-1',
            'Alice',
            'alice@example.test',
            true
        );

        self::assertSame([], $mine['superseded_batch_ids'], 'an approved batch is never superseded');
        self::assertCount(1, $this->repo->getBatch($mine['batch_id']), 'nothing may be carried onto it either');

        $approvedRows = $this->repo->getBatch($approved);
        self::assertCount(2, $approvedRows, 'the approved batch keeps every one of its rows');
        foreach ($approvedRows as $row) {
            self::assertSame('approved', $row['review_status']);
        }

        $theirRows = $this->repo->getBatch($theirs);
        self::assertCount(2, $theirRows, 'another submitter\'s batch keeps every one of its rows');
        foreach ($theirRows as $row) {
            self::assertSame('user-2', $row['submitted_by_sub']);
        }
    }

    public function testSupersededBatchIdsIsEmptyWhenNothingWasReplaced(): void
    {
        $submission = $this->repo->submitBatch(
            ChangeResource::nationalCalendar(Rite::ROMAN, 'US'),
            $this->calendarWithTranslations(),
            'user-1',
            'Alice',
            'alice@example.test',
            true
        );

        self::assertSame([], $submission['superseded_batch_ids']);
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
        )['batch_id'];

        self::assertCount(2, $this->repo->getBatch($mine));
        self::assertCount(1, $this->repo->getBatch($theirs));
    }

    /**
     * The cross-submitter guarantee under the keying the supersede actually uses.
     *
     * {@see testAnotherSubmittersPendingRowsAreNotReplaced()} deliberately uses a
     * DIFFERENT path, so it cannot exercise this: nothing collides there, and it would
     * still pass if the `submitted_by_sub` predicate were dropped entirely. Here both
     * submitters have the SAME path pending, which is the only shape in which a missing
     * submitter predicate would delete someone else's work.
     */
    public function testTwoSubmittersMayHoldTheSamePathPendingAtOnce(): void
    {
        $mine = $this->submitUsa('user-1');

        $theirs = $this->repo->submitBatch(
            ChangeResource::nationalCalendar(Rite::ROMAN, 'US'),
            [
                [
                    'path'      => 'jsondata/sourcedata/rite/roman/calendars/nations/US/US.json',
                    'operation' => ChangeOperation::UPDATE,
                    'content'   => '{"litcal":[{"event_key":"TheirEdit"}]}',
                ],
            ],
            'user-2',
            'Bob',
            null,
            false
        );

        self::assertSame([], $theirs['superseded_batch_ids'], 'another submitter\'s batch must never be superseded');
        self::assertCount(2, $this->repo->getBatch($mine), 'user-1\'s batch must survive user-2 staging the same path');
        self::assertCount(1, $this->repo->getBatch($theirs['batch_id']));

        // And it works in the other direction too: user-1 resubmitting supersedes only
        // their own batch, leaving user-2's pending proposal for the same path alone.
        $resubmitted = $this->repo->submitBatch(
            ChangeResource::nationalCalendar(Rite::ROMAN, 'US'),
            [
                [
                    'path'      => 'jsondata/sourcedata/rite/roman/calendars/nations/US/US.json',
                    'operation' => ChangeOperation::UPDATE,
                    'content'   => '{"litcal":[{"event_key":"MyEdit"}]}',
                ],
            ],
            'user-1',
            'Alice',
            'alice@example.test',
            true
        );

        self::assertSame([$mine], $resubmitted['superseded_batch_ids']);
        self::assertCount(1, $this->repo->getBatch($theirs['batch_id']), 'user-2\'s pending batch is untouched');
    }

    /**
     * Read-your-own-unpublished-writes, at the repository boundary: a submitter sees their
     * own not-yet-published content for a path, and nobody else's.
     */
    public function testFindUnpublishedContentIsScopedToTheSubmittersOwnRow(): void
    {
        $path = 'jsondata/sourcedata/rite/roman/calendars/nations/US/US.json';

        $this->repo->submitBatch(
            ChangeResource::nationalCalendar(Rite::ROMAN, 'US'),
            [['path' => $path, 'operation' => ChangeOperation::UPDATE, 'content' => '{"mine":true}']],
            'user-1',
            'Alice',
            'alice@example.test',
            true
        );
        $theirs = $this->repo->submitBatch(
            ChangeResource::nationalCalendar(Rite::ROMAN, 'US'),
            [['path' => $path, 'operation' => ChangeOperation::UPDATE, 'content' => '{"theirs":true}']],
            'user-2',
            'Bob',
            null,
            false
        )['batch_id'];

        self::assertSame('{"mine":true}', $this->repo->findUnpublishedContent($path, 'user-1'));
        self::assertSame('{"theirs":true}', $this->repo->findUnpublishedContent($path, 'user-2'));
        self::assertNull($this->repo->findUnpublishedContent($path, 'user-3'));
        self::assertNull($this->repo->findUnpublishedContent('jsondata/nope.json', 'user-1'));

        // Approval is NOT publication. Phase 1's approve is a status UPDATE with no file
        // I/O and no publisher behind it, so user-2's content is still absent from disk
        // and must still be the accumulation base for their next write. Asserting null
        // here is what encoded the defect this predicate was widened to fix.
        $this->repo->approveBatch($theirs, 'admin-1');
        self::assertSame('{"theirs":true}', $this->repo->findUnpublishedContent($path, 'user-2'));
        self::assertSame('{"mine":true}', $this->repo->findUnpublishedContent($path, 'user-1'), 'the other submitter is unaffected');
    }

    /**
     * Once approved rows are in scope, several rows can share a (path, submitter) — the
     * partial unique index covers only `submitted` — so the newest must win deterministically.
     *
     * Both orderings are exercised: an approved row against a newer approved row (resolved by
     * `created_at`), and approved rows against a still-submitted one (resolved by the
     * `review_status = 'submitted'` leading key, which is what survives an exact `created_at`
     * tie between two transactions starting in the same microsecond).
     */
    public function testFindUnpublishedContentTakesTheNewestRowWhenSeveralAreUnpublished(): void
    {
        $path = 'jsondata/sourcedata/rite/roman/calendars/nations/US/US.json';

        foreach (['{"v":1}', '{"v":2}'] as $content) {
            $batchId = $this->repo->submitBatch(
                ChangeResource::nationalCalendar(Rite::ROMAN, 'US'),
                [['path' => $path, 'operation' => ChangeOperation::UPDATE, 'content' => $content]],
                'user-1',
                'Alice',
                'alice@example.test',
                true
            )['batch_id'];
            $this->repo->approveBatch($batchId, 'admin-1');
        }

        self::assertSame('{"v":2}', $this->repo->findUnpublishedContent($path, 'user-1'), 'the newer approved batch wins');

        // A still-submitted row is by construction the newest: creating it ran the
        // supersede DELETE, which cleared every then-submitted row for this path.
        $this->repo->submitBatch(
            ChangeResource::nationalCalendar(Rite::ROMAN, 'US'),
            [['path' => $path, 'operation' => ChangeOperation::UPDATE, 'content' => '{"v":3}']],
            'user-1',
            'Alice',
            'alice@example.test',
            true
        );

        self::assertSame('{"v":3}', $this->repo->findUnpublishedContent($path, 'user-1'));
    }

    /**
     * Merged content IS the repository: a later deploy brings it to disk, so accumulating
     * it on top of the disk read would double-count it. Phase 1 never writes this status,
     * so it is set directly here — the predicate exists for the phases that will.
     */
    public function testFindUnpublishedContentIgnoresMergedRows(): void
    {
        $path = 'jsondata/sourcedata/rite/roman/calendars/nations/US/US.json';

        $batchId = $this->repo->submitBatch(
            ChangeResource::nationalCalendar(Rite::ROMAN, 'US'),
            [['path' => $path, 'operation' => ChangeOperation::UPDATE, 'content' => '{"v":1}']],
            'user-1',
            'Alice',
            'alice@example.test',
            true
        )['batch_id'];
        $this->repo->approveBatch($batchId, 'admin-1');
        self::assertSame('{"v":1}', $this->repo->findUnpublishedContent($path, 'user-1'));

        $stmt = self::$pdo?->prepare(
            'UPDATE sourcedata_change_requests SET publication_status = :merged WHERE batch_id = :batch_id'
        );
        self::assertNotNull($stmt);
        $stmt->execute(['merged' => ChangePublicationStatus::MERGED->value, 'batch_id' => $batchId]);

        self::assertNull($this->repo->findUnpublishedContent($path, 'user-1'));
        self::assertSame([], $this->repo->findUnpublishedPathsUnder('jsondata/sourcedata/rite/roman/calendars/nations/US/', 'user-1'));
    }

    public function testFindUnpublishedContentIgnoresWithdrawnAndRejectedRows(): void
    {
        $path = 'jsondata/sourcedata/rite/roman/calendars/nations/US/US.json';

        $withdrawn = $this->repo->submitBatch(
            ChangeResource::nationalCalendar(Rite::ROMAN, 'US'),
            [['path' => $path, 'operation' => ChangeOperation::UPDATE, 'content' => '{"v":1}']],
            'user-1',
            'Alice',
            'alice@example.test',
            true
        )['batch_id'];
        $this->repo->withdrawBatch($withdrawn, 'user-1');
        self::assertNull($this->repo->findUnpublishedContent($path, 'user-1'));

        $rejected = $this->repo->submitBatch(
            ChangeResource::nationalCalendar(Rite::ROMAN, 'US'),
            [['path' => $path, 'operation' => ChangeOperation::UPDATE, 'content' => '{"v":2}']],
            'user-1',
            'Alice',
            'alice@example.test',
            true
        )['batch_id'];
        $this->repo->rejectBatch($rejected, 'admin-1', 'no');
        self::assertNull($this->repo->findUnpublishedContent($path, 'user-1'));
    }

    /**
     * The enumeration half: a locale sidecar that exists only as an unpublished proposal
     * must be discoverable, because `glob()` on the real folder cannot see it.
     *
     * The prefix match is literal, not LIKE — `_` and `%` are ordinary characters in real
     * paths (`en_US.json`), and a LIKE wildcard would quietly widen the match.
     */
    public function testFindUnpublishedPathsUnderReturnsOnlyTheSubmittersUnpublishedPathsBeneathThePrefix(): void
    {
        $this->submitUsa('user-1');
        $this->repo->submitBatch(
            ChangeResource::nationalCalendar(Rite::ROMAN, 'CA'),
            [
                [
                    'path'      => 'jsondata/sourcedata/rite/roman/calendars/nations/US/i18n/fr_CA.json',
                    'operation' => ChangeOperation::CREATE,
                    'content'   => '{"key":"valeur"}',
                ]
            ],
            'user-2',
            'Bob',
            null,
            false
        );

        $prefix = 'jsondata/sourcedata/rite/roman/calendars/nations/US/i18n/';

        self::assertSame(
            ['jsondata/sourcedata/rite/roman/calendars/nations/US/i18n/en_US.json'],
            $this->repo->findUnpublishedPathsUnder($prefix, 'user-1')
        );
        self::assertSame(
            ['jsondata/sourcedata/rite/roman/calendars/nations/US/i18n/fr_CA.json'],
            $this->repo->findUnpublishedPathsUnder($prefix, 'user-2')
        );
        self::assertSame([], $this->repo->findUnpublishedPathsUnder('jsondata/sourcedata/rite/ambrosian/', 'user-1'));
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
        )['batch_id'];

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
        )['batch_id'];

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
        )['batch_id'];

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

    /**
     * The enumeration half of the same rule.
     *
     * `findUnpublishedPathsUnder()` is what tells a handler which sidecar files exist for a
     * submitter, so a superseded ancestor left in its results reappears as a file the rebuild
     * then reads. Phase 1's decree bug had exactly this shape — the content half was fixed
     * while the enumeration half still swept the file away — so both halves get their own test
     * rather than trusting that a shared constant keeps them in step.
     */
    public function testAnAncestorOlderThanAMergedRowIsNotEnumeratedEither(): void
    {
        $folder = 'jsondata/sourcedata/rite/roman/decrees/i18n';
        $path   = $folder . '/cs.json';

        $batchA = $this->repo->submitBatch(
            ChangeResource::decrees(),
            [['path' => $path, 'operation' => ChangeOperation::UPDATE, 'content' => '{"A":"a"}']],
            'editor-1',
            'Alice',
            'alice@example.test',
            true
        )['batch_id'];
        $this->repo->approveBatch($batchA, 'admin-1');

        $batchB = $this->repo->submitBatch(
            ChangeResource::decrees(),
            [['path' => $path, 'operation' => ChangeOperation::UPDATE, 'content' => '{"A":"a","B":"b"}']],
            'editor-1',
            'Alice',
            'alice@example.test',
            true
        )['batch_id'];
        $this->repo->approveBatch($batchB, 'admin-1');
        $this->repo->markBatchPublicationStatus($batchB, ChangePublicationStatus::MERGED);

        self::assertSame(
            [],
            $this->repo->findUnpublishedPathsUnder($folder . '/', 'editor-1'),
            'a path whose only unpublished row is superseded by published content must not be enumerated'
        );
    }

    /**
     * The bug this task exists to fix: batch A is accumulated onto by batch B, and B is
     * published (`merged`). A is older than B and was never itself published, so it must not
     * resurface as the accumulation base for the next edit -- doing so would silently revert
     * everything B added.
     */
    public function testAnAncestorOlderThanAMergedRowIsNotUsedAsTheBase(): void
    {
        $path = 'jsondata/sourcedata/rite/roman/decrees/decrees.json';

        // Batch A: approved, never published. Batch B accumulated onto it and was published.
        $batchA = $this->repo->submitBatch(
            ChangeResource::decrees(),
            [['path' => $path, 'operation' => ChangeOperation::UPDATE, 'content' => '["A"]']],
            'editor-1',
            'Alice',
            'alice@example.test',
            true
        )['batch_id'];
        $this->repo->approveBatch($batchA, 'admin-1');

        $batchB = $this->repo->submitBatch(
            ChangeResource::decrees(),
            [['path' => $path, 'operation' => ChangeOperation::UPDATE, 'content' => '["A","B"]']],
            'editor-1',
            'Alice',
            'alice@example.test',
            true
        )['batch_id'];
        $this->repo->approveBatch($batchB, 'admin-1');
        $this->repo->markBatchPublicationStatus($batchB, ChangePublicationStatus::MERGED);

        // A is older than the newest merged row for this path, so it must not become the base again.
        self::assertNull(
            $this->repo->findUnpublishedContent($path, 'editor-1'),
            'a merged batch must not fall back to the ancestor it superseded'
        );
    }

    /**
     * The sibling of the above: while nothing for a path has ever been merged, the age floor
     * must not change phase 1's behaviour at all.
     */
    public function testAnAncestorWithNoMergedDescendantIsStillTheBase(): void
    {
        $path = 'jsondata/sourcedata/rite/roman/decrees/decrees.json';

        $batchA = $this->repo->submitBatch(
            ChangeResource::decrees(),
            [['path' => $path, 'operation' => ChangeOperation::UPDATE, 'content' => '["A"]']],
            'editor-1',
            'Alice',
            'alice@example.test',
            true
        )['batch_id'];
        $this->repo->approveBatch($batchA, 'admin-1');

        // Nothing is merged, so phase 1's behaviour must be untouched.
        self::assertSame('["A"]', $this->repo->findUnpublishedContent($path, 'editor-1'));
    }

    /**
     * fix-round-1 regression: a row must not be excluded merely because its `created_at` TIES
     * the newest merged row's -- only a row OLDER than the floor is superseded, and a tie is
     * not older. Reachable the same way this class's other documented tie is: two independent
     * transactions landing on the same microsecond, forced here exactly as
     * {@see testListingBreaksATiedCreatedAtDeterministically()} forces one.
     *
     * C is emphatically NOT an ancestor of B here -- it is submitted only after B is already
     * fully merged, so it cannot have been accumulated into B's content. Excluding C on the
     * tie would reproduce, for an unrelated row, exactly the silent-data-loss defect
     * {@see NOT_SUPERSEDED_BY_PUBLISHED} exists to prevent.
     */
    public function testATieWithTheMergedFloorDoesNotExcludeAnUnrelatedRow(): void
    {
        $path = 'jsondata/sourcedata/rite/roman/decrees/decrees.json';

        $batchB = $this->repo->submitBatch(
            ChangeResource::decrees(),
            [['path' => $path, 'operation' => ChangeOperation::UPDATE, 'content' => '["B"]']],
            'editor-1',
            'Alice',
            'alice@example.test',
            true
        )['batch_id'];
        $this->repo->approveBatch($batchB, 'admin-1');
        $this->repo->markBatchPublicationStatus($batchB, ChangePublicationStatus::MERGED);

        // C is submitted only AFTER B is fully merged -- it cannot be B's ancestor.
        $batchC = $this->repo->submitBatch(
            ChangeResource::decrees(),
            [['path' => $path, 'operation' => ChangeOperation::UPDATE, 'content' => '["C"]']],
            'editor-1',
            'Alice',
            'alice@example.test',
            true
        )['batch_id'];
        $this->repo->approveBatch($batchC, 'admin-1');

        // Force an exact tie between B's and C's created_at -- the scenario a strict `>` floor
        // excluded wrongly.
        $stmt = self::$pdo->prepare(
            "UPDATE sourcedata_change_requests
                SET created_at = TIMESTAMP '2026-01-01 00:00:00'
              WHERE batch_id IN (:batch_b, :batch_c)"
        );
        $stmt->execute(['batch_b' => $batchB, 'batch_c' => $batchC]);

        self::assertSame(
            '["C"]',
            $this->repo->findUnpublishedContent($path, 'editor-1'),
            'a row tied with the merged floor must not be excluded -- a tie is not older'
        );
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

    private function submitDecrees(string $sub, string $content): string
    {
        return $this->repo->submitBatch(
            ChangeResource::decrees(),
            [
                [
                    'path'      => 'jsondata/sourcedata/rite/roman/decrees/decrees.json',
                    'operation' => ChangeOperation::UPDATE,
                    'content'   => $content,
                ]
            ],
            $sub,
            'Editor',
            $sub . '@example.test',
            true
        )['batch_id'];
    }

    /** Both statuses at once, by direct SQL — no code path produces every combination this pins. */
    private function forceStatuses(string $batchId, string $review, string $publication): void
    {
        $stmt = self::$pdo->prepare(
            'UPDATE sourcedata_change_requests
                SET review_status = :r, publication_status = :p
              WHERE batch_id = :b'
        );
        $stmt->execute(['r' => $review, 'p' => $publication, 'b' => $batchId]);
    }

    /**
     * A batch whose PR was closed unmerged is excluded from the accumulation base — by the REVIEW
     * axis (phase 3 writes `rejected` alongside `closed`), not by the publication axis.
     */
    public function testClosedAndRejectedRowIsExcludedFromTheAccumulationBase(): void
    {
        $path    = 'jsondata/sourcedata/rite/roman/decrees/decrees.json';
        $batchId = $this->submitDecrees('editor-1', '{"decrees":["A"]}');

        $this->forceStatuses($batchId, review: 'rejected', publication: 'closed');

        self::assertNull($this->repo->findUnpublishedContent($path, 'editor-1'));
    }

    /**
     * The mirror image, and the reason the previous test proves what it claims: a `closed` row that
     * is still `approved` IS in the base. `closed` means nothing reached the repository, so on the
     * publication axis it genuinely belongs there. If this ever starts returning null, someone has
     * "simplified" `publication_status <> 'merged'` into treating `closed` as published — which would
     * silently drop an editor's un-merged work from their next submission.
     *
     * Constructible only by direct SQL: no code path produces closed-without-rejected.
     */
    public function testClosedButStillApprovedRowRemainsInTheAccumulationBase(): void
    {
        $path    = 'jsondata/sourcedata/rite/roman/decrees/decrees.json';
        $batchId = $this->submitDecrees('editor-1', '{"decrees":["A"]}');

        $this->forceStatuses($batchId, review: 'approved', publication: 'closed');

        self::assertSame('{"decrees":["A"]}', $this->repo->findUnpublishedContent($path, 'editor-1'));
    }

    /**
     * `closed` must never become the NOT_SUPERSEDED_BY_PUBLISHED floor. A closed batch published
     * nothing, so using it as the floor would exclude older rows on the strength of content that
     * never reached the repository.
     */
    public function testClosedRowIsNotASupersessionFloor(): void
    {
        $path = 'jsondata/sourcedata/rite/roman/decrees/decrees.json';

        $older = $this->submitDecrees('editor-1', '{"decrees":["A"]}');
        $this->repo->approveBatch($older, 'reviewer-1');

        $newer = $this->submitDecrees('editor-1', '{"decrees":["A","B"]}');
        $this->forceStatuses($newer, review: 'rejected', publication: 'closed');

        // The closed batch is out of the base; the older approved one is still in it, NOT excluded
        // by a floor the closed batch had no right to set.
        self::assertSame('{"decrees":["A"]}', $this->repo->findUnpublishedContent($path, 'editor-1'));
    }

    /**
     * `findBatchSummary()` deliberately goes through `listBatches()` rather than aggregating
     * separately, so that a detail route and a list route can never drift into two different
     * renderings of the same batch. Assert exactly that: same batch, same object.
     */
    public function testFindBatchSummaryReturnsTheSameShapeAsTheListing(): void
    {
        $batchId = $this->submitUsa();

        $fromList = $this->repo->listBySubmitter('user-1')[0];
        $summary  = $this->repo->findBatchSummary($batchId);

        self::assertNotNull($summary);
        self::assertSame($fromList, $summary);
        self::assertSame(2, $summary['file_count']);
        self::assertSame($batchId, $summary['batch_id']);
    }

    public function testFindBatchSummaryScopedToASubmitterIgnoresSomeoneElsesBatch(): void
    {
        $batchId = $this->submitUsa('user-1');

        self::assertNotNull($this->repo->findBatchSummary($batchId, 'user-1'));
        self::assertNull($this->repo->findBatchSummary($batchId, 'user-2'));
        // Unscoped, the admin path still sees it.
        self::assertNotNull($this->repo->findBatchSummary($batchId));
    }

    public function testGetBatchBySubmitterIsScopedInSql(): void
    {
        $batchId = $this->submitUsa('user-1');

        self::assertCount(2, $this->repo->getBatchBySubmitter($batchId, 'user-1'));
        // Not theirs reads back as [] — indistinguishable, deliberately, from a batch that
        // does not exist, which is what lets the handler answer 404 rather than 403.
        self::assertSame([], $this->repo->getBatchBySubmitter($batchId, 'user-2'));
    }

    /**
     * #924: every batch-level column the workflow stamps must survive the aggregate. MIN() is
     * used rather than MAX() deliberately — see listBatches()' docblock — and this pins the
     * values that come back for a fully published, merged batch.
     */
    public function testTheAggregateCarriesTheDecisionAndPublicationColumns(): void
    {
        $batchId = $this->submitUsa('user-1');
        $this->repo->approveBatch($batchId, 'reviewer-1');
        self::assertNotNull($this->repo->claimNextPublishableBatch());
        $this->repo->recordPublication($batchId, 'sourcedata/roman-US', 'c0ffee', 77, 'basebase');
        $this->repo->markBatchMerged($batchId, 'deadbeef');

        $summary = $this->repo->findBatchSummary($batchId);

        self::assertNotNull($summary);
        self::assertSame('sourcedata/roman-US', $summary['branch']);
        self::assertSame('c0ffee', $summary['commit_sha']);
        self::assertSame('deadbeef', $summary['merge_commit_sha']);
        // Narrowed to int, never the string PDO's pgsql driver may hand back.
        self::assertSame(77, $summary['pr_number']);
        self::assertIsString($summary['publication_settled_at']);
        self::assertNull($summary['rejected_reason']);
    }

    public function testTheAggregateCarriesTheRejectionReason(): void
    {
        $batchId = $this->submitUsa('user-1');
        $this->repo->rejectBatch($batchId, 'reviewer-1', 'Duplicates an existing memorial');

        $summary = $this->repo->findBatchSummary($batchId);

        self::assertNotNull($summary);
        self::assertSame('Duplicates an existing memorial', $summary['rejected_reason']);
        self::assertNull($summary['pr_number']);
        self::assertNull($summary['merge_commit_sha']);
        self::assertNull($summary['publication_settled_at']);
    }
}
