<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Repositories;

use LiturgicalCalendar\Api\Enum\ChangeOperation;
use LiturgicalCalendar\Api\Enum\ChangePublicationStatus;
use LiturgicalCalendar\Api\Enum\ChangeReviewStatus;
use LiturgicalCalendar\Api\Enum\ClaimReleaseOutcome;
use LiturgicalCalendar\Api\Enum\Rite;
use LiturgicalCalendar\Api\Repositories\SourceDataChangeRequestRepository;
use LiturgicalCalendar\Api\Services\ChangeResource;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Covers the publish-queue half of {@see SourceDataChangeRequestRepository}: claiming an
 * approved batch for publication, and settling a claim (recording success, or releasing
 * a failed attempt back to `none`).
 *
 * The class docblock on the repository explains why a batch is never mixed-status; this
 * suite leans on that invariant the same way the repository's own claim query does.
 */
#[CoversClass(SourceDataChangeRequestRepository::class)]
final class SourceDataChangeRequestPublishQueueTest extends RepositoryTestCase
{
    private SourceDataChangeRequestRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new SourceDataChangeRequestRepository(self::$pdo);
    }

    /** @return list<array{path: string, operation: ChangeOperation, content: ?string}> */
    private function calendarFile(string $nation): array
    {
        return [
            [
                'path'      => "jsondata/sourcedata/rite/roman/calendars/nations/{$nation}/{$nation}.json",
                'operation' => ChangeOperation::CREATE,
                'content'   => '{"litcal":[]}',
            ],
        ];
    }

    private function submitOnly(string $sub, string $nation = 'US'): string
    {
        return $this->repo->submitBatch(
            ChangeResource::nationalCalendar(Rite::ROMAN, $nation),
            $this->calendarFile($nation),
            $sub,
            'Editor',
            $sub . '@example.test',
            true
        )['batch_id'];
    }

    private function submitAndApprove(string $sub, string $nation = 'US'): string
    {
        $batchId = $this->submitOnly($sub, $nation);
        $this->repo->approveBatch($batchId, 'reviewer-1');

        return $batchId;
    }

    /**
     * Opens a second, independent PDO connection to the same test database. Used to prove
     * the claim is genuinely atomic across connections, not merely well-behaved when
     * called twice in a row on one connection (which {@see testAClaimedBatchIsNotClaimedTwice}
     * already covers, but which alone could not distinguish "correctly locked" from
     * "correctly re-checked the now-committed status").
     */
    private function secondConnection(): PDO
    {
        $host     = self::env('DB_HOST');
        $port     = self::env('DB_PORT') ?? '5432';
        $name     = self::env('DB_NAME');
        $user     = self::env('DB_USER');
        $password = self::env('DB_PASSWORD');

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
    }

    public function testClaimingReturnsAnApprovedBatchAndMarksItQueued(): void
    {
        $batchId = $this->submitAndApprove('editor-1');

        self::assertSame($batchId, $this->repo->claimNextPublishableBatch());

        foreach ($this->repo->getBatch($batchId) as $row) {
            self::assertSame(ChangePublicationStatus::QUEUED->value, $row['publication_status']);
        }
    }

    public function testAnUnapprovedBatchIsNeverClaimed(): void
    {
        $this->submitOnly('editor-1');

        self::assertNull($this->repo->claimNextPublishableBatch(), 'submitted-but-undecided work is not publishable');
    }

    public function testAClaimedBatchIsNotClaimedTwice(): void
    {
        $this->submitAndApprove('editor-1');

        self::assertNotNull($this->repo->claimNextPublishableBatch());
        self::assertNull($this->repo->claimNextPublishableBatch(), 'a queued batch must not be handed out again');
    }

    public function testReleasingAClaimMakesItPublishableAgain(): void
    {
        $batchId = $this->submitAndApprove('editor-1');
        $this->repo->claimNextPublishableBatch();

        $this->repo->releaseClaim($batchId);

        self::assertSame($batchId, $this->repo->claimNextPublishableBatch(), 'a failed attempt must be retryable');

        foreach ($this->repo->getBatch($batchId) as $row) {
            self::assertSame(ChangePublicationStatus::QUEUED->value, $row['publication_status']);
        }
    }

    /**
     * Regression test for the race a phase-2 review round caught: `releaseClaim()` used to
     * delegate to `markBatchPublicationStatus()`, which is unconditional. A batch claimed by
     * runner A but genuinely, successfully published by runner B in the meantime (a merely
     * SLOW A, reclaimed by B after A's grace period elapsed but before A's own — now doomed —
     * GitHub call returned) would have that unconditional release revert it from `open` back
     * to `none` while it still carried B's real `commit_sha`/`pr_number` — silently
     * re-publishing already-published work on the very next tick.
     *
     * `releaseClaim()` must now be a no-op (nothing released, `open` untouched) once the batch
     * is no longer `queued`, and must SAY SO — reporting `SETTLED_ELSEWHERE`, not a bare zero
     * that a caller cannot tell apart from the batch having been released back to `none` by
     * someone else's equally-failed attempt. Reproduces the race directly against the repository without
     * needing two real processes: "B publishes" is simulated with a plain
     * `recordPublication()` call, since from the repository's point of view that is
     * indistinguishable from an actual second runner having done it.
     */
    public function testReleaseClaimIsANoOpOnceAnotherRunnerHasAlreadyPublished(): void
    {
        $batchId = $this->submitAndApprove('editor-1');
        $this->repo->claimNextPublishableBatch();

        // Runner B: publishes successfully while A's own attempt is (unbeknownst to A) still
        // in flight.
        $this->repo->recordPublication($batchId, 'litcal-data/national_calendar/roman/US', 'deadbeef', 42, 'base-sha');

        // Runner A: its own GitHub call now fails for real (branch moved under it) and it
        // tries to release the claim it believes it still holds.
        $outcome = $this->repo->releaseClaim($batchId);
        self::assertSame(
            ClaimReleaseOutcome::SETTLED_ELSEWHERE,
            $outcome,
            'releaseClaim() must not affect rows once the batch is no longer queued, and must report why'
        );

        foreach ($this->repo->getBatch($batchId) as $row) {
            self::assertSame(ChangePublicationStatus::OPEN->value, $row['publication_status']);
            self::assertSame('deadbeef', $row['commit_sha']);
            self::assertEquals(42, $row['pr_number']);
        }
    }

    public function testOlderApprovedBatchIsClaimedBeforeANewerOne(): void
    {
        $older = $this->submitAndApprove('editor-1', 'US');
        // Force a distinct, later created_at so ordering is unambiguous rather than
        // relying on two inserts in the same transaction landing on different microseconds.
        self::$pdo->exec('UPDATE sourcedata_change_requests SET created_at = created_at - INTERVAL \'1 hour\'');
        $this->submitAndApprove('editor-2', 'DE');

        self::assertSame($older, $this->repo->claimNextPublishableBatch());
    }

    public function testRecordPublicationSetsOpenStatusAndGitFields(): void
    {
        $batchId = $this->submitAndApprove('editor-1');
        $this->repo->claimNextPublishableBatch();

        $updated = $this->repo->recordPublication($batchId, 'publish/us-2026-08-29', 'deadbeef', 42, 'basecommitsha');
        self::assertGreaterThan(0, $updated);

        foreach ($this->repo->getBatch($batchId) as $row) {
            self::assertSame(ChangePublicationStatus::OPEN->value, $row['publication_status']);
            self::assertSame('publish/us-2026-08-29', $row['branch']);
            self::assertSame('deadbeef', $row['commit_sha']);
            self::assertEquals(42, $row['pr_number']);
            self::assertSame('basecommitsha', $row['base_sha']);
        }
    }

    public function testRecordPublicationAcceptsANullPrNumber(): void
    {
        $batchId = $this->submitAndApprove('editor-1');
        $this->repo->claimNextPublishableBatch();

        $this->repo->recordPublication($batchId, 'publish/us-2026-08-29', 'deadbeef', null, 'basecommitsha');

        foreach ($this->repo->getBatch($batchId) as $row) {
            self::assertNull($row['pr_number']);
        }
    }

    /**
     * The test that actually demonstrates atomicity, not just that a single caller's
     * claim behaves. It reproduces the exact locking half of claimNextPublishableBatch()
     * on a second, independent connection and holds it open in an uncommitted
     * transaction — simulating "runner A is mid-claim" — then proves a second runner
     * (this test's own $this->repo, on the original connection) cannot see or take the
     * same row while that lock is held, and CAN take it the instant the lock is released.
     *
     * If the repository's SELECT and UPDATE did not share one transaction (i.e. if
     * autocommit released the lock right after the SELECT), this test would fail: the
     * second connection's claim would succeed while connection A's transaction is still
     * open, because there would be no lock left to skip.
     */
    public function testConcurrentClaimsCannotSelectTheSameBatch(): void
    {
        $batchId = $this->submitAndApprove('editor-1');

        $connA = $this->secondConnection();
        $connA->beginTransaction();
        try {
            // Reproduces the row-locking half of claimNextPublishableBatch(): a plain
            // FOR UPDATE SKIP LOCKED against every row of the batch (Postgres rejects FOR
            // UPDATE combined with GROUP BY, so the repository's own aggregate candidate
            // query cannot itself hold the lock — see that method's docblock).
            $lock = $connA->prepare(
                'SELECT id
                   FROM sourcedata_change_requests
                  WHERE batch_id = :batch_id
                    FOR UPDATE SKIP LOCKED'
            );
            $lock->execute(['batch_id' => $batchId]);
            self::assertCount(
                1,
                $lock->fetchAll(PDO::FETCH_COLUMN),
                'connection A must actually hold the row lock for this to be a meaningful test'
            );

            // Connection A's transaction is still open — the row lock is held. A second
            // runner using a different connection must skip the locked row, not double-claim it.
            self::assertNull(
                $this->repo->claimNextPublishableBatch(),
                'a row locked by an in-flight claim on another connection must not be handed out again'
            );
        } finally {
            $connA->commit();
        }

        // Once connection A's transaction ends, the lock is released and the batch
        // becomes claimable again — proving the null above was "locked, try later", not
        // some unrelated bug swallowing the row.
        self::assertSame($batchId, $this->repo->claimNextPublishableBatch());
    }

    /**
     * Regression test for a race the earlier lock query missed entirely: it locked on
     * `batch_id` alone, with no re-check of status. `FOR UPDATE SKIP LOCKED` only protects
     * against a lock another transaction still HOLDS — it releases at COMMIT — so a runner
     * that took its candidate snapshot before a different runner claims-and-commits the same
     * batch would, at its own later lock step, find that batch's rows perfectly unlocked
     * (now `queued`, but "unlocked" all the same) and claim them a second time.
     *
     * A single PHP process cannot demonstrate this even with two PDO connections: PHP is
     * single-threaded, so two `claimNextPublishableBatch()` calls issued from one script,
     * whatever connections they use, execute strictly one after another and never overlap in
     * time. The race needs one runner's SELECT and another runner's COMMIT genuinely
     * interleaved, which only real OS-level concurrency produces — hence two actual
     * subprocesses (`phpunit_tests/fixtures/claim-race-worker.php`), each hammering
     * `claimNextPublishableBatch()` against a shared pool of approved batches until it sees
     * three consecutive misses, racing head-to-head with no artificial synchronisation
     * between them.
     *
     * This is deliberately a real stress test over hand-sequenced SQL: a hand-sequenced test
     * that reproduces the fixed query inline would keep passing even if the fix were later
     * reverted in the repository, because its own copy of the query — not the repository's —
     * is what gets asserted on. Calling the actual method from two real processes closes that
     * gap: it exercises `claimNextPublishableBatch()` itself, on both sides of the race, so a
     * regression in the repository's own query is what the test would catch.
     *
     * Measured against the pre-fix implementation (batch_id-only lock predicate), 100
     * batches raced this way were double-claimed at a roughly 45% rate per batch — not a
     * rare timing fluke. Against the fix, repeated runs show zero double-claims across every
     * batch. See "Fix round 1" in `.superpowers/sdd/2026-08-29-sourcedata-publisher-phase2/task-2-report.md`
     * for the raw before/after counts.
     */
    public function testTwoRealConcurrentRunnersNeverClaimTheSameBatch(): void
    {
        $batchCount  = 80;
        $expectedIds = [];
        for ($i = 0; $i < $batchCount; $i++) {
            $expectedIds[] = $this->submitAndApprove("race-editor-{$i}", sprintf('Z%02d', $i));
        }
        sort($expectedIds);

        $fixture = dirname(__DIR__) . '/fixtures/claim-race-worker.php';
        self::assertFileExists($fixture);

        $env         = [
            'DB_HOST'     => (string) self::env('DB_HOST'),
            'DB_PORT'     => (string) ( self::env('DB_PORT') ?? '5432' ),
            'DB_NAME'     => (string) self::env('DB_NAME'),
            'DB_USER'     => (string) self::env('DB_USER'),
            'DB_PASSWORD' => (string) self::env('DB_PASSWORD'),
            'PATH'        => (string) getenv('PATH'),
        ];
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];

        // Both subprocesses are launched — and therefore both running — before either pipe
        // is read. proc_open() itself does not block, so this is what makes the two workers
        // genuinely concurrent rather than sequential.
        $procA = proc_open([PHP_BINARY, $fixture], $descriptors, $pipesA, null, $env);
        $procB = proc_open([PHP_BINARY, $fixture], $descriptors, $pipesB, null, $env);
        self::assertIsResource($procA, 'could not start worker A');
        self::assertIsResource($procB, 'could not start worker B');

        $outA = (string) stream_get_contents($pipesA[1]);
        $errA = (string) stream_get_contents($pipesA[2]);
        fclose($pipesA[1]);
        fclose($pipesA[2]);
        $exitA = proc_close($procA);

        $outB = (string) stream_get_contents($pipesB[1]);
        $errB = (string) stream_get_contents($pipesB[2]);
        fclose($pipesB[1]);
        fclose($pipesB[2]);
        $exitB = proc_close($procB);

        self::assertSame(0, $exitA, "worker A failed:\n{$errA}");
        self::assertSame(0, $exitB, "worker B failed:\n{$errB}");

        $claimedA = array_values(array_filter(explode("\n", trim($outA)), static fn (string $id): bool => $id !== ''));
        $claimedB = array_values(array_filter(explode("\n", trim($outB)), static fn (string $id): bool => $id !== ''));

        $doubleClaimed = array_values(array_intersect($claimedA, $claimedB));
        self::assertSame(
            [],
            $doubleClaimed,
            sprintf(
                '%d of %d batches were claimed by BOTH concurrent runners: %s',
                count($doubleClaimed),
                $batchCount,
                implode(', ', $doubleClaimed)
            )
        );

        $union = array_unique(array_merge($claimedA, $claimedB));
        sort($union);
        self::assertSame($expectedIds, $union, 'every approved batch must be claimed exactly once, by exactly one runner');
    }

    /**
     * The distinction the final whole-branch review found missing, at the level that settles
     * it: `releaseClaim()` affects zero rows for reasons that are semantic OPPOSITES, and a row
     * count cannot tell them apart. `open` means another runner published the batch; `none`
     * means nobody did — reached whenever another runner's own publish failed too, or whenever
     * the grace-period reclaim released this batch out from under a merely-slow runner. The
     * caller stops the run for one and carries on for the other, so this method must report
     * WHICH.
     */
    public function testReleaseClaimReportsWhatItObservedNotJustWhetherItChangedAnything(): void
    {
        // 1. The live claim: released, and the failure is genuine.
        $queued = $this->submitAndApprove('editor-1', 'US');
        $this->repo->claimNextPublishableBatch();
        self::assertSame(ClaimReleaseOutcome::RELEASED, $this->repo->releaseClaim($queued));

        // 2. Already back at `none` — the sibling of `open`, and the one that used to read as
        //    "settled, carry on" purely because both affect zero rows.
        self::assertSame(ClaimReleaseOutcome::NOT_CLAIMED, $this->repo->releaseClaim($queued));

        // 3. Published by someone else: the ONLY zero-row case that is not a failure.
        $published = $this->submitAndApprove('editor-2', 'DE');
        $this->repo->claimNextPublishableBatch();
        $this->repo->recordPublication($published, 'litcal-data/national_calendar/roman/DE', 'sha', 7, 'base');
        self::assertSame(ClaimReleaseOutcome::SETTLED_ELSEWHERE, $this->repo->releaseClaim($published));

        // 4. No such batch: an unexplained state, never read optimistically.
        self::assertSame(
            ClaimReleaseOutcome::BATCH_MISSING,
            $this->repo->releaseClaim('00000000-0000-4000-8000-000000000000')
        );
    }

    /**
     * A release is also the moment an attempt is counted, and the count is what eventually
     * parks a batch that fails forever so the rest of the queue can drain past it. Counting it
     * anywhere else would either miss the crash path or double-count the ordinary one.
     */
    public function testReleaseClaimCountsAnAttemptAndParksTheBatchAtTheBound(): void
    {
        $batchId = $this->submitAndApprove('editor-1', 'US');

        for ($attempt = 1; $attempt <= SourceDataChangeRequestRepository::MAX_PUBLISH_ATTEMPTS; $attempt++) {
            self::assertSame($batchId, $this->repo->claimNextPublishableBatch(), "attempt {$attempt} must be claimable");
            self::assertSame(ClaimReleaseOutcome::RELEASED, $this->repo->releaseClaim($batchId));

            foreach ($this->repo->getBatch($batchId) as $row) {
                self::assertEquals($attempt, $row['publish_attempts']);
            }
        }

        self::assertNull(
            $this->repo->claimNextPublishableBatch(),
            'a batch that has spent every attempt must stop being claimed, or it blocks the queue forever'
        );
        self::assertSame(1, $this->repo->countParkedBatches(), 'and it must be visible while it is skipped');

        // Parked, not dead-lettered: clearing the counter is all it takes to retry, which is
        // what the runbook tells an operator to do.
        self::$pdo->prepare('UPDATE sourcedata_change_requests SET publish_attempts = 0 WHERE batch_id = :b')
            ->execute(['b' => $batchId]);
        self::assertSame($batchId, $this->repo->claimNextPublishableBatch());
        self::assertSame(0, $this->repo->countParkedBatches());
    }

    /**
     * The skip list is a WITHIN-RUN exclusion, not a status: a batch passed over here is still
     * perfectly claimable on the caller's next tick. Its whole job is to stop a caller that
     * continues past a failure from immediately re-claiming the batch it just released, which
     * — being back at `none` and the oldest — would otherwise be the next candidate.
     */
    public function testSkippedBatchIdsArePassedOverWithoutBeingParked(): void
    {
        $first  = $this->submitAndApprove('editor-1', 'US');
        $second = $this->submitAndApprove('editor-2', 'DE');

        self::assertSame($second, $this->repo->claimNextPublishableBatch([$first]));
        self::assertSame(0, $this->repo->countParkedBatches(), 'skipping is not parking');
        self::assertSame($first, $this->repo->claimNextPublishableBatch(), 'and it lasts only as long as the caller says');
    }

    public function testCountParkedBatchesIgnoresBatchesThatAreNoLongerWaiting(): void
    {
        $published = $this->submitAndApprove('editor-1', 'US');

        // Exhaust the bound, then publish anyway (an operator retry, or phase 3).
        self::$pdo->prepare('UPDATE sourcedata_change_requests SET publish_attempts = :n WHERE batch_id = :b')
            ->execute(['n' => SourceDataChangeRequestRepository::MAX_PUBLISH_ATTEMPTS, 'b' => $published]);
        self::assertSame(1, $this->repo->countParkedBatches());

        $this->repo->recordPublication($published, 'litcal-data/national_calendar/roman/US', 'sha', 7, 'base');
        self::assertSame(
            0,
            $this->repo->countParkedBatches(),
            'a batch that reached GitHub is not stuck, whatever its attempt history'
        );
    }
}
