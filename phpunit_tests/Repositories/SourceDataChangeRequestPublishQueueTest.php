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
use LiturgicalCalendar\Api\Services\SourceData\PublishBackoff;
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

    /**
     * Launches two copies of $command as OS processes and returns each one's captured
     * [stdout, stderr, exit code]. Both are launched — and therefore both running — before
     * either pipe is read: proc_open() itself does not block, so this is what makes the two
     * genuinely concurrent rather than sequential.
     *
     * Extracted from what was originally inlined in
     * {@see testTwoRealConcurrentRunnersNeverClaimTheSameBatch()}. The low-level primitive
     * behind {@see raceTwoProcesses()}, which every test needing real two-process concurrency
     * goes through rather than each hand-rolling its own proc_open dance.
     *
     * @param list<string> $command
     * @return array{0: array{string, string, int}, 1: array{string, string, int}}
     */
    private function launchTwoProcesses(array $command): array
    {
        $env         = [
            'DB_HOST'     => (string) self::env('DB_HOST'),
            'DB_PORT'     => (string) ( self::env('DB_PORT') ?? '5432' ),
            'DB_NAME'     => (string) self::env('DB_NAME'),
            'DB_USER'     => (string) self::env('DB_USER'),
            'DB_PASSWORD' => (string) self::env('DB_PASSWORD'),
            'PATH'        => (string) getenv('PATH'),
        ];
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];

        $procA = proc_open($command, $descriptors, $pipesA, null, $env);
        $procB = proc_open($command, $descriptors, $pipesB, null, $env);
        self::assertIsResource($procA, 'could not start process A');
        self::assertIsResource($procB, 'could not start process B');

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

        return [[$outA, $errA, $exitA], [$outB, $errB, $exitB]];
    }

    /**
     * Runs $code as two OS processes racing against the same live database — via
     * {@see launchTwoProcesses()}, so both are started before either pipe is read — and
     * returns each one's JSON-decoded stdout.
     *
     * $code may reference `$pdo` (a PDO connected per the DB_* environment this test class
     * itself was configured with) and `$repo` (a {@see SourceDataChangeRequestRepository}
     * over that connection); both are constructed by a small bootstrap this method prepends
     * before $code runs. $code is expected to `echo json_encode(...)` exactly once.
     *
     * @return array{0: mixed, 1: mixed}
     */
    private function raceTwoProcesses(string $code): array
    {
        $autoload  = var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true);
        $bootstrap = <<<PHP
            require {$autoload};
            \$pdo = new PDO(
                sprintf('pgsql:host=%s;port=%s;dbname=%s', getenv('DB_HOST'), getenv('DB_PORT') ?: '5432', getenv('DB_NAME')),
                getenv('DB_USER'),
                getenv('DB_PASSWORD'),
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
            \$repo = new \\LiturgicalCalendar\\Api\\Repositories\\SourceDataChangeRequestRepository(\$pdo);
            PHP;

        [$resultA, $resultB]   = $this->launchTwoProcesses([PHP_BINARY, '-r', $bootstrap . "\n" . $code]);
        [$outA, $errA, $exitA] = $resultA;
        [$outB, $errB, $exitB] = $resultB;

        self::assertSame(0, $exitA, "process A failed:\n{$errA}");
        self::assertSame(0, $exitB, "process B failed:\n{$errB}");

        return [json_decode($outA, true), json_decode($outB, true)];
    }

    public function testClaimingReturnsAnApprovedBatchAndMarksItQueued(): void
    {
        $batchId = $this->submitAndApprove('editor-1');

        self::assertSame($batchId, $this->repo->claimNextPublishableBatch()?->batchId);

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
        $claim   = $this->repo->claimNextPublishableBatch();
        self::assertNotNull($claim);

        $this->repo->releaseClaim($batchId, $claim->token);

        // "Publishable again" is now "publishable again once due": the release also schedules the
        // retry, so the batch is deliberately not claimable for the length of its backoff. What
        // this test pins is that the release is not terminal — see
        // {@see testAReleasedClaimIsNotClaimableUntilItsBackoffElapses} for the wait itself.
        $this->makeDue($batchId);

        self::assertSame(
            $batchId,
            $this->repo->claimNextPublishableBatch()?->batchId,
            'a failed attempt must be retryable'
        );

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
        $claimA  = $this->repo->claimNextPublishableBatch();
        self::assertNotNull($claimA);

        // Runner B: publishes successfully while A's own attempt is (unbeknownst to A) still
        // in flight.
        $this->repo->recordPublication($batchId, 'litcal-data/national_calendar/roman/US', 'deadbeef', 42, 'base-sha');

        // Runner A: its own GitHub call now fails for real (branch moved under it) and it
        // tries to release the claim it believes it still holds.
        $outcome = $this->repo->releaseClaim($batchId, $claimA->token);
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

        self::assertSame($older, $this->repo->claimNextPublishableBatch()?->batchId);
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
     * The `AND publication_status = 'queued'` guard's whole point: once a batch has already
     * been recorded `open` by one recorder, a SECOND `recordPublication()` call for the same
     * batch id — the shape a stale runner A's own late-arriving publish takes, after runner B
     * already recorded it (see {@see testReleaseClaimIsANoOpOnceAnotherRunnerHasAlreadyPublished()}
     * for the matching `releaseClaim()` half of this same race) — must change nothing and must
     * report zero rows, so the caller ({@see \LiturgicalCalendar\Api\Services\SourceData\SourceDataPublisher::publish()})
     * can detect and log the block rather than silently overwriting B's identifiers with A's.
     */
    public function testASecondRecordPublicationForAnAlreadyOpenBatchChangesNothingAndReportsZeroRows(): void
    {
        $batchId = $this->submitAndApprove('editor-1');
        $this->repo->claimNextPublishableBatch();

        // Runner B: the first, winning recorder.
        $firstUpdated = $this->repo->recordPublication($batchId, 'litcal-data/national_calendar/roman/US', 'b-sha', 42, 'base-b');
        self::assertGreaterThan(0, $firstUpdated);

        // Runner A: a stale second recorder for the SAME batch id, with different identifiers.
        $secondUpdated = $this->repo->recordPublication($batchId, 'litcal-data/national_calendar/roman/US', 'a-sha', 99, 'base-a');
        self::assertSame(0, $secondUpdated, 'a batch no longer "queued" must not be recordable again');

        foreach ($this->repo->getBatch($batchId) as $row) {
            self::assertSame(ChangePublicationStatus::OPEN->value, $row['publication_status']);
            self::assertSame('litcal-data/national_calendar/roman/US', $row['branch'], 'B\'s branch must survive A\'s stale call');
            self::assertSame('b-sha', $row['commit_sha'], 'B\'s commit sha must survive A\'s stale call');
            self::assertEquals(42, $row['pr_number'], 'B\'s pull request number must survive A\'s stale call');
            self::assertSame('base-b', $row['base_sha'], 'B\'s base sha must survive A\'s stale call');
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
        self::assertSame($batchId, $this->repo->claimNextPublishableBatch()?->batchId);
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

        [$resultA, $resultB]   = $this->launchTwoProcesses([PHP_BINARY, $fixture]);
        [$outA, $errA, $exitA] = $resultA;
        [$outB, $errB, $exitB] = $resultB;

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
        $queued      = $this->submitAndApprove('editor-1', 'US');
        $queuedClaim = $this->repo->claimNextPublishableBatch();
        self::assertNotNull($queuedClaim);
        self::assertSame(ClaimReleaseOutcome::RELEASED, $this->repo->releaseClaim($queued, $queuedClaim->token));

        // 2. Already back at `none` — the sibling of `open`, and the one that used to read as
        //    "settled, carry on" purely because both affect zero rows.
        self::assertSame(ClaimReleaseOutcome::NOT_CLAIMED, $this->repo->releaseClaim($queued, $queuedClaim->token));

        // 3. Published by someone else: the ONLY zero-row case that is not a failure.
        $published = $this->submitAndApprove('editor-2', 'DE');
        // $queued is back at `none` after step 1's release, and older, so it would otherwise be
        // reclaimed here instead of $published — skip it explicitly so this claim (and the
        // `recordPublication()` guard below, which now requires the row to be `queued`) targets
        // the batch this step actually means to publish.
        $publishedClaim = $this->repo->claimNextPublishableBatch([$queued]);
        self::assertNotNull($publishedClaim);
        self::assertSame($published, $publishedClaim->batchId);
        $this->repo->recordPublication($published, 'litcal-data/national_calendar/roman/DE', 'sha', 7, 'base');
        self::assertSame(
            ClaimReleaseOutcome::SETTLED_ELSEWHERE,
            $this->repo->releaseClaim($published, $publishedClaim->token)
        );

        // 4. No such batch: an unexplained state, never read optimistically.
        self::assertSame(
            ClaimReleaseOutcome::BATCH_MISSING,
            $this->repo->releaseClaim('00000000-0000-4000-8000-000000000000', '00000000-0000-4000-8000-000000000001')
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
            $claim = $this->repo->claimNextPublishableBatch();
            self::assertNotNull($claim, "attempt {$attempt} must be claimable");
            self::assertSame($batchId, $claim->batchId, "attempt {$attempt} must be claimable");
            self::assertSame(ClaimReleaseOutcome::RELEASED, $this->repo->releaseClaim($batchId, $claim->token));

            foreach ($this->repo->getBatch($batchId) as $row) {
                self::assertEquals($attempt, $row['publish_attempts']);
            }

            // The bound is what this test is about, not the spacing between attempts; skip the
            // backoff so the loop measures attempts rather than wall-clock.
            $this->makeDue($batchId);
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
        self::assertSame($batchId, $this->repo->claimNextPublishableBatch()?->batchId);
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

        self::assertSame($second, $this->repo->claimNextPublishableBatch([$first])?->batchId);
        self::assertSame(0, $this->repo->countParkedBatches(), 'skipping is not parking');
        self::assertSame(
            $first,
            $this->repo->claimNextPublishableBatch()?->batchId,
            'and it lasts only as long as the caller says'
        );
    }

    public function testCountParkedBatchesIgnoresBatchesThatAreNoLongerWaiting(): void
    {
        $published = $this->submitAndApprove('editor-1', 'US');

        // Exhaust the bound: parked while still `none`.
        self::$pdo->prepare('UPDATE sourcedata_change_requests SET publish_attempts = :n WHERE batch_id = :b')
            ->execute(['n' => SourceDataChangeRequestRepository::MAX_PUBLISH_ATTEMPTS, 'b' => $published]);
        self::assertSame(1, $this->repo->countParkedBatches());

        // Recover it the only way the schema allows — reset the counter so it becomes claimable
        // again (see testReleaseClaimCountsAnAttemptAndParksTheBatchAtTheBound()), then claim and
        // publish it. recordPublication() now requires the row to be `queued`, which only a claim
        // produces — an operator retry or phase 3 reaches this same state through the ordinary
        // claim path, not by calling recordPublication() directly on a still-`none` row.
        self::$pdo->prepare('UPDATE sourcedata_change_requests SET publish_attempts = 0 WHERE batch_id = :b')
            ->execute(['b' => $published]);
        $claim = $this->repo->claimNextPublishableBatch();
        self::assertNotNull($claim);
        self::assertSame($published, $claim->batchId);

        $this->repo->recordPublication($published, 'litcal-data/national_calendar/roman/US', 'sha', 7, 'base');
        self::assertSame(
            0,
            $this->repo->countParkedBatches(),
            'a batch that reached GitHub is not stuck, whatever its attempt history'
        );
    }

    public function testClaimReturnsATokenAndReleaseRequiresIt(): void
    {
        $batchId = $this->submitAndApprove('editor-1');

        $claim = $this->repo->claimNextPublishableBatch();
        self::assertNotNull($claim);
        self::assertSame($batchId, $claim->batchId);
        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',
            $claim->token
        );

        self::assertSame(ClaimReleaseOutcome::RELEASED, $this->repo->releaseClaim($batchId, $claim->token));
    }

    /**
     * The defect this column exists for: runner A is slow, the grace period elapses,
     * reclaimStaleClaims() frees the batch, runner B claims it — and then A's late release must
     * NOT revoke B's live claim, and must NOT spend one of B's attempts.
     */
    public function testStaleReleaseNeitherRevokesTheLiveClaimNorSpendsAnAttempt(): void
    {
        $batchId = $this->submitAndApprove('editor-1');

        $claimA = $this->repo->claimNextPublishableBatch();
        self::assertNotNull($claimA);

        // The grace period elapses and the reclaim frees it (spending A's attempt), then B claims.
        $this->backdateUpdatedAt($batchId, 60);
        self::assertSame(1, $this->repo->reclaimStaleClaims(new \DateTimeImmutable('-30 minutes')));
        // A reclaim schedules the retry exactly as a release does; B's claim is the subject here,
        // not the wait before it.
        $this->makeDue($batchId);
        $claimB = $this->repo->claimNextPublishableBatch();
        self::assertNotNull($claimB);
        self::assertNotSame($claimA->token, $claimB->token);

        $attemptsBefore = $this->publishAttempts($batchId);

        // A's doomed GitHub call finally returns and A releases.
        self::assertSame(ClaimReleaseOutcome::CLAIM_LOST, $this->repo->releaseClaim($batchId, $claimA->token));

        self::assertSame(
            ChangePublicationStatus::QUEUED->value,
            $this->publicationStatus($batchId),
            "B's claim must survive A's stale release"
        );
        self::assertSame($attemptsBefore, $this->publishAttempts($batchId), 'A stale release spends no attempt');
    }

    public function testRecordPublicationClearsTheClaimToken(): void
    {
        $batchId = $this->submitAndApprove('editor-1');
        $claim   = $this->repo->claimNextPublishableBatch();
        self::assertNotNull($claim);

        $this->repo->recordPublication($batchId, 'litcal-data/national_calendar/roman/US', 'sha1', 7, 'base');

        self::assertNull($this->claimToken($batchId));
    }

    public function testReclaimStaleClaimsClearsTheClaimToken(): void
    {
        $batchId = $this->submitAndApprove('editor-1');
        self::assertNotNull($this->repo->claimNextPublishableBatch());

        $this->backdateUpdatedAt($batchId, 60);
        self::assertSame(1, $this->repo->reclaimStaleClaims(new \DateTimeImmutable('-30 minutes')));

        self::assertNull($this->claimToken($batchId));
    }

    /**
     * Two real processes, one approved batch. Exactly one claim, and — the phase-3 half — the winner's
     * token is the one on the row, so the loser cannot later release a claim it never held.
     */
    public function testTwoRealConcurrentRunnersProduceOneClaimAndOneToken(): void
    {
        $batchId = $this->submitAndApprove('editor-1');

        $results = $this->raceTwoProcesses(<<<'PHP'
            $claim = $repo->claimNextPublishableBatch();
            echo json_encode(['batch' => $claim?->batchId, 'token' => $claim?->token]);
            PHP);

        $claimed = array_values(array_filter($results, static fn (array $r): bool => null !== $r['batch']));
        self::assertCount(1, $claimed, 'exactly one process may claim the batch');
        self::assertSame($batchId, $claimed[0]['batch']);
        self::assertSame($claimed[0]['token'], $this->claimToken($batchId), 'the row carries the winner\'s token');

        // The loser holds no token, so it cannot release the winner's claim even by guessing the batch id.
        self::assertSame(
            ClaimReleaseOutcome::CLAIM_LOST,
            $this->repo->releaseClaim($batchId, '00000000-0000-4000-8000-000000000000')
        );
    }

    private function claimToken(string $batchId): ?string
    {
        $stmt = self::$pdo->prepare(
            'SELECT publish_claim_token FROM sourcedata_change_requests WHERE batch_id = :b LIMIT 1'
        );
        $stmt->execute(['b' => $batchId]);
        $value = $stmt->fetchColumn();

        return is_string($value) ? $value : null;
    }

    private function publishAttempts(string $batchId): int
    {
        $stmt = self::$pdo->prepare(
            'SELECT MAX(publish_attempts) FROM sourcedata_change_requests WHERE batch_id = :b'
        );
        $stmt->execute(['b' => $batchId]);

        return (int) $stmt->fetchColumn();
    }

    private function publicationStatus(string $batchId): string
    {
        $stmt = self::$pdo->prepare(
            'SELECT publication_status FROM sourcedata_change_requests WHERE batch_id = :b LIMIT 1'
        );
        $stmt->execute(['b' => $batchId]);

        return (string) $stmt->fetchColumn();
    }

    private function backdateUpdatedAt(string $batchId, int $minutesAgo): void
    {
        $stmt = self::$pdo->prepare(
            "UPDATE sourcedata_change_requests
                SET updated_at = NOW() - (:mins || ' minutes')::interval
              WHERE batch_id = :b"
        );
        $stmt->execute(['mins' => (string) $minutesAgo, 'b' => $batchId]);
    }

    private function publishTo(string $batchId, int $prNumber, string $commitSha): void
    {
        $claim = $this->repo->claimNextPublishableBatch();
        self::assertNotNull($claim);
        self::assertSame($batchId, $claim->batchId);
        $this->repo->recordPublication($batchId, 'litcal-data/national_calendar/roman/US', $commitSha, $prNumber, 'base');
    }

    public function testListOpenPullRequestNumbersDeduplicatesAndOrdersOldestFirst(): void
    {
        $first = $this->submitAndApprove('editor-1', 'US');
        $this->publishTo($first, 11, 'sha-a');
        $second = $this->submitAndApprove('editor-2', 'US');
        $this->publishTo($second, 11, 'sha-b');   // same rolling PR
        $third = $this->submitAndApprove('editor-3', 'IT');
        $this->publishTo($third, 22, 'sha-c');

        self::assertSame([11, 22], $this->repo->listOpenPullRequestNumbers());
    }

    public function testListOpenBatchesForPullRequestReturnsEveryBatchOnIt(): void
    {
        $first = $this->submitAndApprove('editor-1', 'US');
        $this->publishTo($first, 11, 'sha-a');
        $second = $this->submitAndApprove('editor-2', 'US');
        $this->publishTo($second, 11, 'sha-b');

        $rows = $this->repo->listOpenBatchesForPullRequest(11);

        self::assertCount(2, $rows);
        self::assertSame(
            ['sha-a', 'sha-b'],
            array_column($rows, 'commit_sha')
        );
    }

    public function testMarkBatchMergedRecordsTheMergeCommitAndSettledAt(): void
    {
        $batchId = $this->submitAndApprove('editor-1');
        $this->publishTo($batchId, 11, 'sha-a');

        self::assertSame(1, $this->repo->markBatchMerged($batchId, 'merge-sha'));

        $row = $this->firstRow($batchId);
        self::assertSame(ChangePublicationStatus::MERGED->value, $row['publication_status']);
        self::assertSame('merge-sha', $row['merge_commit_sha']);
        self::assertNotNull($row['publication_settled_at']);
        self::assertSame(ChangeReviewStatus::APPROVED->value, $row['review_status'], 'a merge does not re-review');
    }

    public function testMarkBatchClosedUnmergedAlsoRejectsAndGivesAReason(): void
    {
        $batchId = $this->submitAndApprove('editor-1');
        $this->publishTo($batchId, 11, 'sha-a');

        self::assertSame(1, $this->repo->markBatchClosedUnmerged($batchId, 'Pull request #11 was closed without merging.'));

        $row = $this->firstRow($batchId);
        self::assertSame(ChangePublicationStatus::CLOSED->value, $row['publication_status']);
        self::assertSame(ChangeReviewStatus::REJECTED->value, $row['review_status']);
        self::assertSame('Pull request #11 was closed without merging.', $row['rejected_reason']);
        self::assertNotNull($row['publication_settled_at']);
    }

    /**
     * Both transitions are guarded on `open`, so two racing pollers produce one transition and one
     * no-op rather than two writes of possibly-different merge shas.
     */
    public function testTransitionsAreGuardedOnOpen(): void
    {
        $batchId = $this->submitAndApprove('editor-1');
        $this->publishTo($batchId, 11, 'sha-a');

        self::assertSame(1, $this->repo->markBatchMerged($batchId, 'merge-sha'));
        self::assertSame(0, $this->repo->markBatchMerged($batchId, 'other-sha'), 'second poller must be a no-op');
        self::assertSame('merge-sha', $this->firstRow($batchId)['merge_commit_sha']);
    }

    /**
     * A batch on a merged PR whose commit was NOT in the merge goes back to claimable, clearing the
     * attempts it never spent, so the next publish opens a fresh pull request carrying it.
     */
    public function testReturnBatchToUnpublishedMakesItClaimableAgain(): void
    {
        $batchId = $this->submitAndApprove('editor-1');
        $this->publishTo($batchId, 11, 'sha-a');

        self::assertSame(1, $this->repo->returnBatchToUnpublished($batchId));

        $row = $this->firstRow($batchId);
        self::assertSame(ChangePublicationStatus::NONE->value, $row['publication_status']);
        self::assertSame(0, (int) $row['publish_attempts']);
        self::assertSame('sha-a', $row['commit_sha'], 'git identifiers are kept for forensics');

        $claim = $this->repo->claimNextPublishableBatch();
        self::assertNotNull($claim);
        self::assertSame($batchId, $claim->batchId);
    }

    public function testCountOpenBatchesWithoutPullRequestFindsTheUnpollableOnes(): void
    {
        $batchId = $this->submitAndApprove('editor-1');
        $this->publishTo($batchId, 11, 'sha-a');
        self::assertSame(0, $this->repo->countOpenBatchesWithoutPullRequest());

        self::$pdo->exec("UPDATE sourcedata_change_requests SET pr_number = NULL WHERE batch_id = '{$batchId}'");
        self::assertSame(1, $this->repo->countOpenBatchesWithoutPullRequest());
    }

    // -- Per-batch retry scheduling --------------------------------------------------------------

    /**
     * The spacing that used to come from the cron interval now lives on the row. Before this, a
     * released batch was instantly the oldest claimable candidate again — harmless while only cron
     * called `runOnce()`, and a hot retry loop the moment the consumer began scheduling its own
     * recovery ticks.
     */
    public function testAReleasedClaimIsNotClaimableUntilItsBackoffElapses(): void
    {
        $batchId = $this->submitAndApprove('editor-1');

        $claim = $this->repo->claimNextPublishableBatch();
        self::assertNotNull($claim);
        self::assertSame(ClaimReleaseOutcome::RELEASED, $this->repo->releaseClaim($claim->batchId, $claim->token));

        self::assertNull($this->repo->claimNextPublishableBatch(), 'a batch inside its backoff must not be claimable');

        $this->makeDue($batchId);
        self::assertNotNull($this->repo->claimNextPublishableBatch(), 'and must be claimable again once due');
    }

    /**
     * The curve, observed end to end rather than unit-tested in isolation: the wait a release
     * actually writes must be the one {@see PublishBackoff} names for the NEW attempt count, which
     * is what proves `backoffCaseSql()` reads the pre-increment `publish_attempts`.
     */
    public function testEachConsecutiveFailureWaitsLonger(): void
    {
        $batchId = $this->submitAndApprove('editor-1');

        foreach ([1, 2, 3] as $attempt) {
            $claim = $this->repo->claimNextPublishableBatch();
            self::assertNotNull($claim, "attempt {$attempt} must be claimable");
            $this->repo->releaseClaim($claim->batchId, $claim->token);

            $expected = PublishBackoff::secondsForAttempt($attempt);
            $actual   = $this->secondsUntilDue($batchId);
            self::assertGreaterThan($expected - 60, $actual, "attempt {$attempt} waits about {$expected}s");
            self::assertLessThanOrEqual($expected, $actual, "attempt {$attempt} waits about {$expected}s");

            $this->makeDue($batchId);
        }
    }

    /**
     * A reclaim spends an attempt exactly as a release does, so it must schedule exactly as a
     * release does. Without this, a batch stranded by a crashed publisher would be reclaimed and
     * immediately re-attempted on the same tick, spending two attempts where the operator's mental
     * model says one.
     */
    public function testAReclaimedStaleClaimIsScheduledToo(): void
    {
        $batchId = $this->submitAndApprove('editor-1');

        $claim = $this->repo->claimNextPublishableBatch();
        self::assertNotNull($claim);

        self::assertSame(1, $this->repo->reclaimStaleClaims(new \DateTimeImmutable('+1 hour')));

        self::assertSame(1, (int) $this->firstRow($batchId)['publish_attempts']);
        self::assertGreaterThan(0, $this->secondsUntilDue($batchId), 'a reclaim must schedule, not free for immediate retry');
    }

    /** Seconds from now until every row of the batch is due; negative when already due. */
    private function secondsUntilDue(string $batchId): float
    {
        $stmt = self::$pdo->prepare(
            'SELECT EXTRACT(EPOCH FROM (MIN(next_attempt_at) - NOW())) AS secs
               FROM sourcedata_change_requests
              WHERE batch_id = :b'
        );
        $stmt->execute(['b' => $batchId]);

        return (float) $stmt->fetchColumn();
    }

    /** Simulate the backoff having elapsed, rather than sleeping through it. */
    private function makeDue(string $batchId): void
    {
        $stmt = self::$pdo->prepare(
            "UPDATE sourcedata_change_requests SET next_attempt_at = NOW() - INTERVAL '1 second' WHERE batch_id = :b"
        );
        $stmt->execute(['b' => $batchId]);
    }

    /** @return array<string, mixed> */
    private function firstRow(string $batchId): array
    {
        $stmt = self::$pdo->prepare('SELECT * FROM sourcedata_change_requests WHERE batch_id = :b LIMIT 1');
        $stmt->execute(['b' => $batchId]);
        /** @var array<string, mixed>|false $row */
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        self::assertNotFalse($row);

        return $row;
    }
}
