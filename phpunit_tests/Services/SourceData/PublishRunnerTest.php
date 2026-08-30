<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Services\SourceData;

use LiturgicalCalendar\Api\Enum\ChangeOperation;
use LiturgicalCalendar\Api\Enum\ChangePublicationStatus;
use LiturgicalCalendar\Api\Enum\ChangeReviewStatus;
use LiturgicalCalendar\Api\Enum\Rite;
use LiturgicalCalendar\Api\Repositories\SourceDataChangeRequestRepository;
use LiturgicalCalendar\Api\Services\ChangeResource;
use LiturgicalCalendar\Api\Services\GitHub\GitHubApiException;
use LiturgicalCalendar\Api\Services\SourceData\PublishRunner;
use LiturgicalCalendar\Tests\Repositories\RepositoryTestCase;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Exercises `PublishRunner`'s orchestration — reclaim-stale-claims, claim, publish,
 * release-and-stop-on-failure — against a real Postgres-backed
 * {@see SourceDataChangeRequestRepository} (a skipping repository test would prove nothing
 * about the claim/release invariant this suite exists to pin down) and a lightweight
 * {@see FakeSourceDataPublisher} standing in for the real, `final`
 * {@see \LiturgicalCalendar\Api\Services\SourceData\SourceDataPublisher} — see
 * {@see \LiturgicalCalendar\Api\Services\SourceData\SourceDataPublisherInterface} for why a
 * fake is used here rather than a mocked Guzzle stack. No network, no credentials.
 */
#[CoversClass(PublishRunner::class)]
final class PublishRunnerTest extends RepositoryTestCase
{
    private SourceDataChangeRequestRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new SourceDataChangeRequestRepository(self::$pdo);
    }

    // -- Fixtures -----------------------------------------------------------------------------

    private function approveOne(string $sub, string $nation = 'US'): string
    {
        $submission = $this->repo->submitBatch(
            ChangeResource::nationalCalendar(Rite::ROMAN, $nation),
            [
                [
                    'path'      => "jsondata/sourcedata/rite/roman/calendars/nations/{$nation}/{$nation}.json",
                    'operation' => ChangeOperation::CREATE,
                    'content'   => '{"litcal":[]}',
                ],
            ],
            $sub,
            'Editor',
            $sub . '@example.test',
            true
        );
        $batchId    = $submission['batch_id'];

        $this->repo->approveBatch($batchId, 'reviewer-1');

        return $batchId;
    }

    private function runner(?FakeSourceDataPublisher $publisher = null): PublishRunner
    {
        return new PublishRunner($this->repo, $publisher ?? new FakeSourceDataPublisher($this->repo));
    }

    private function runnerThatThrows(\Throwable $exception): PublishRunner
    {
        return $this->runner(new FakeSourceDataPublisher($this->repo, $exception));
    }

    /**
     * Backdates every row of a batch's `updated_at`, simulating a claim left behind by a
     * process that crashed (SIGKILL / OOM / cron timeout) between claiming and finishing.
     */
    private function backdateUpdatedAt(string $batchId, int $minutesAgo): void
    {
        // The interval is interpolated (not bound) to match the existing
        // testOlderApprovedBatchIsClaimedBeforeANewerOne precedent in
        // SourceDataChangeRequestPublishQueueTest — Postgres' INTERVAL literal syntax does not
        // take a bound parameter for the unit, and $minutesAgo is caller-controlled test data,
        // never external input.
        $stmt = self::$pdo->prepare(
            "UPDATE sourcedata_change_requests
                SET updated_at = NOW() - INTERVAL '{$minutesAgo} minutes'
              WHERE batch_id = :batch_id"
        );
        $stmt->execute(['batch_id' => $batchId]);
    }

    // -- Tests --------------------------------------------------------------------------------

    public function testASuccessfulPublishRecordsTheBranchCommitAndPr(): void
    {
        $batchId = $this->approveOne('editor-1');

        $result = $this->runner()->runOnce();
        self::assertSame(1, $result->published);
        self::assertFalse($result->stoppedOnFailure);

        foreach ($this->repo->getBatch($batchId) as $row) {
            self::assertSame(ChangePublicationStatus::OPEN->value, $row['publication_status']);
            self::assertNotNull($row['commit_sha']);
            self::assertSame(FakeSourceDataPublisher::PR_NUMBER, $row['pr_number']);
        }
    }

    public function testAFailedPublishReleasesTheClaimSoItIsRetried(): void
    {
        $batchId = $this->approveOne('editor-1');

        // A 500 rather than the 422 this test used to throw: a 422 is branch CONTENTION, which
        // the runner deliberately treats as expected and self-healing (see
        // testALostBranchRaceContinuesTheRunButStaysVisible below). Any other status is an
        // outage-shaped failure, which is what this test is about.
        $runner = $this->runnerThatThrows(new GitHubApiException(500, 'Server Error'));
        $result = $runner->runOnce();
        self::assertSame(0, $result->published);
        self::assertTrue($result->stoppedOnFailure, 'the exit code depends on this to signal an operator');

        // Back to `none`, not stranded in `queued`: a batch nobody will ever pick up again is
        // worse than one that retries, because it is invisible to the operator and to the
        // editor alike.
        foreach ($this->repo->getBatch($batchId) as $row) {
            self::assertSame(ChangePublicationStatus::NONE->value, $row['publication_status']);
        }
    }

    /**
     * The brief is explicit that this must hold for ANY `\Throwable`, not only the expected
     * `GitHubApiException` — a `TypeError` or an out-of-memory condition inside the publisher
     * must not leave a batch stranded in `queued` either.
     */
    public function testANonGitHubThrowableAlsoReleasesTheClaim(): void
    {
        $batchId = $this->approveOne('editor-1');

        $runner = $this->runnerThatThrows(new \TypeError('unexpected null'));
        $result = $runner->runOnce();
        self::assertSame(0, $result->published);
        self::assertTrue($result->stoppedOnFailure);

        foreach ($this->repo->getBatch($batchId) as $row) {
            self::assertSame(ChangePublicationStatus::NONE->value, $row['publication_status']);
        }
    }

    /**
     * `releaseClaim()` itself is wrapped in `runOnce()`'s failure path: if the same outage
     * that failed the publish also breaks the release call, that must be logged, not escape
     * as a raw fatal — an operator greps logs, not stack traces. It still cannot un-strand
     * the batch (that is what the grace-period reclaim is for), so the batch is asserted to
     * remain `queued` here rather than `none`.
     */
    public function testAFailureToReleaseTheClaimIsLoggedNotThrown(): void
    {
        $batchId = $this->approveOne('editor-1');

        $throwingRepo = new ThrowingReleaseClaimRepository(self::$pdo);
        $publisher    = new FakeSourceDataPublisher($this->repo, new GitHubApiException(500, 'boom'));
        $testHandler  = new TestHandler();
        $logger       = new Logger('test', [$testHandler]);

        $runner = new PublishRunner($throwingRepo, $publisher, logger: $logger);

        $result = $runner->runOnce();
        self::assertSame(0, $result->published);
        self::assertTrue($result->stoppedOnFailure);

        self::assertTrue($testHandler->hasErrorThatContains('Publishing source-data change request batch failed'));
        self::assertTrue($testHandler->hasErrorThatContains('Releasing the claim'));

        // Could not be released — the batch is exactly as stuck as a real double-outage would
        // leave it. The grace-period reclaim (tested separately) is what eventually recovers it.
        foreach ($this->repo->getBatch($batchId) as $row) {
            self::assertSame(ChangePublicationStatus::QUEUED->value, $row['publication_status']);
        }
    }

    public function testAnEmptyQueueIsANoOp(): void
    {
        $result = $this->runner()->runOnce();
        self::assertSame(0, $result->published);
        self::assertFalse($result->stoppedOnFailure);
    }

    public function testALimitOfZeroClaimsNothing(): void
    {
        $batchId = $this->approveOne('editor-1');

        $result = $this->runner()->runOnce(0);
        self::assertSame(0, $result->published);
        self::assertFalse($result->stoppedOnFailure);

        // Never even claimed: still exactly as approveOne() left it, not queued-then-released.
        foreach ($this->repo->getBatch($batchId) as $row) {
            self::assertSame(ChangePublicationStatus::NONE->value, $row['publication_status']);
        }
    }

    /**
     * On failure the loop stops rather than moving on to the next approved batch — hammering
     * a failing GitHub API with every remaining batch is how rate limits get exhausted. The
     * fake publisher counts its own invocations so this test can assert the second batch was
     * never even attempted, not merely that it also ended up back at `none` (which claim-then-
     * release would produce too, and so would not distinguish the two).
     */
    public function testAFailureStopsTheLoopRatherThanTryingTheNextBatch(): void
    {
        $this->approveOne('editor-1', 'US');
        $this->approveOne('editor-2', 'DE');

        $publisher = new FakeSourceDataPublisher($this->repo, new GitHubApiException(500, 'boom'));
        $runner    = $this->runner($publisher);

        $result = $runner->runOnce();
        self::assertSame(0, $result->published);
        self::assertTrue($result->stoppedOnFailure);
        self::assertSame(1, $publisher->calls, 'the second approved batch must never be attempted after the first failure');
    }

    public function testRunOnceStopsAtTheGivenLimit(): void
    {
        $this->approveOne('editor-1', 'US');
        $this->approveOne('editor-2', 'DE');
        $this->approveOne('editor-3', 'FR');

        $result = $this->runner()->runOnce(2);
        self::assertSame(2, $result->published);
        self::assertFalse($result->stoppedOnFailure);
    }

    /**
     * The stale-claim reclaim this test exercises is the fix for a plan gap the review
     * surfaced: without it, a crash (SIGKILL / OOM / cron timeout) between
     * `claimNextPublishableBatch()` and the publish finishing leaves a batch `queued`
     * forever, with nothing left running to release it. Backdating `updated_at` past the
     * grace period reproduces exactly that "left behind by a dead process" state.
     */
    public function testAStaleQueuedBatchBecomesClaimableAgainAfterTheGracePeriod(): void
    {
        $batchId = $this->approveOne('editor-1');
        $this->repo->claimNextPublishableBatch();
        $this->backdateUpdatedAt($batchId, minutesAgo: 20);

        $runner = new PublishRunner($this->repo, new FakeSourceDataPublisher($this->repo), graceSeconds: 600);

        // limit: 0 isolates the reclaim from any subsequent claim — this run only reclaims.
        $result = $runner->runOnce(0);
        self::assertSame(0, $result->published);
        self::assertFalse($result->stoppedOnFailure, 'a reclaim is ordinary recovery, not a failure');

        foreach ($this->repo->getBatch($batchId) as $row) {
            self::assertSame(ChangePublicationStatus::NONE->value, $row['publication_status']);
        }

        // "Becomes claimable again", not merely "back to none" as a status side effect.
        self::assertSame($batchId, $this->repo->claimNextPublishableBatch()?->batchId);
    }

    /**
     * A batch still well within the grace period is presumably being actively published by a
     * live process — reclaiming it unconditionally would risk routine double-publishes
     * instead of the rare, crash-only case this mechanism exists for.
     */
    public function testAQueuedBatchWithinTheGracePeriodIsNotReclaimed(): void
    {
        $batchId = $this->approveOne('editor-1');
        $this->repo->claimNextPublishableBatch();

        $runner = new PublishRunner($this->repo, new FakeSourceDataPublisher($this->repo), graceSeconds: 600);
        $runner->runOnce(0);

        foreach ($this->repo->getBatch($batchId) as $row) {
            self::assertSame(ChangePublicationStatus::QUEUED->value, $row['publication_status']);
        }
    }

    /**
     * A stale reclaim followed immediately by a successful publish of that same batch, in one
     * run — proving the reclaimed batch is not just "back to none" in isolation but genuinely
     * flows through the rest of the loop, and that recovering it does not itself count as
     * the kind of failure that should make the cron script exit non-zero.
     */
    public function testAReclaimedBatchIsPublishedInTheSameRun(): void
    {
        $batchId = $this->approveOne('editor-1');
        $this->repo->claimNextPublishableBatch();
        $this->backdateUpdatedAt($batchId, minutesAgo: 20);

        $runner = new PublishRunner($this->repo, new FakeSourceDataPublisher($this->repo), graceSeconds: 600);

        $result = $runner->runOnce();
        self::assertSame(1, $result->published);
        self::assertFalse($result->stoppedOnFailure);

        foreach ($this->repo->getBatch($batchId) as $row) {
            self::assertSame(ChangePublicationStatus::OPEN->value, $row['publication_status']);
        }
    }

    /**
     * The concurrent case a review round caught missing: a runner whose own publish attempt
     * is merely SLOW, not dead. `RaceLosingSourceDataPublisher` simulates a second runner
     * recording a successful publication for the SAME batch before this run's own (first)
     * attempt fails — reproducing the race without needing two real processes. This must not
     * be treated as this run's failure: `releaseClaim()`'s own `publication_status = 'queued'`
     * guard (see that method's docblock) makes the release a no-op, and `runOnce()` must read
     * that as "already settled elsewhere" — continuing to the next batch, not stopping, and
     * not setting `stoppedOnFailure`. Exit code 1 for a run whose actual work succeeded is
     * exactly the false-alarm the previous fix round's item 1 existed to prevent; this test
     * pins down that reaching it via this different path is equally wrong.
     */
    public function testABatchSettledByAnotherRunnerIsNotThisRunsFailure(): void
    {
        $raceBatch = $this->approveOne('editor-1', 'US');
        $nextBatch = $this->approveOne('editor-2', 'DE');

        $testHandler = new TestHandler();
        $logger      = new Logger('test', [$testHandler]);
        $publisher   = new RaceLosingSourceDataPublisher($this->repo, $raceBatch);
        $runner      = new PublishRunner($this->repo, $publisher, logger: $logger);

        $result = $runner->runOnce();

        self::assertFalse(
            $result->stoppedOnFailure,
            'a batch another runner already published successfully must not read as this run failing'
        );
        self::assertSame(1, $result->published, 'only the batch this run itself actually published counts');
        self::assertTrue($testHandler->hasInfoThatContains('already settled'));

        // The race batch keeps the OTHER runner's real commit/PR — proof releaseClaim() left
        // it alone rather than reverting it to `none`.
        foreach ($this->repo->getBatch($raceBatch) as $row) {
            self::assertSame(ChangePublicationStatus::OPEN->value, $row['publication_status']);
            self::assertSame(RaceLosingSourceDataPublisher::OTHER_RUNNER_COMMIT_SHA, $row['commit_sha']);
            self::assertEquals(RaceLosingSourceDataPublisher::OTHER_RUNNER_PR_NUMBER, $row['pr_number']);
        }

        // The loop continued past the race batch and published the next one normally.
        foreach ($this->repo->getBatch($nextBatch) as $row) {
            self::assertSame(ChangePublicationStatus::OPEN->value, $row['publication_status']);
            self::assertSame(FakeSourceDataPublisher::COMMIT_SHA, $row['commit_sha']);
        }
    }

    /**
     * The SIBLING of the test above, and the defect the final whole-branch review found: a
     * zero-row release is not one signal, it is two. `open` means another runner genuinely
     * published this batch; `none` means another runner's publish FAILED too (or a reclaim
     * fired) and nothing is published anywhere. The old code read both as "already settled
     * (published) by another runner", so a GitHub outage — which fails every runner
     * identically, producing exactly the `none` variant — reported success and re-claimed the
     * same batch on every iteration of the very loop that exists to stop hammering.
     *
     * `$publisher->calls` is the load-bearing assertion: without the fix the same batch is
     * claimed and attempted once per iteration, ten times in a default-limit run, with
     * `stoppedOnFailure` false and the cron script exiting 0.
     */
    public function testABatchLeftAtNoneByAnotherRunnersFailedPublishIsThisRunsFailure(): void
    {
        $batchId = $this->approveOne('editor-1');

        $testHandler = new TestHandler();
        $logger      = new Logger('test', [$testHandler]);
        $publisher   = new ClaimVanishedSourceDataPublisher($this->repo);
        $runner      = new PublishRunner($this->repo, $publisher, logger: $logger);

        $result = $runner->runOnce();

        self::assertTrue(
            $result->stoppedOnFailure,
            'a batch nobody published is a genuine failure, however the release row count reads'
        );
        self::assertSame(0, $result->published);
        self::assertSame(1, $publisher->calls, 'the run must stop, not re-claim and re-attempt the same batch');
        self::assertFalse(
            $testHandler->hasInfoThatContains('already settled'),
            'nothing was settled: claiming otherwise is the false success this test pins down'
        );

        foreach ($this->repo->getBatch($batchId) as $row) {
            self::assertSame(ChangePublicationStatus::NONE->value, $row['publication_status']);
        }
    }

    /**
     * A lost race for a resource's branch is not an outage. Two runners publishing DIFFERENT
     * batches of the SAME resource both target that resource's one branch, and
     * `updateRef()`'s hardcoded `force: false` makes the loser fail with a `422` rather than
     * clobber the winner. The runbook calls that expected and self-healing, so reporting it
     * identically to a revoked credential — stopping the tick and exiting 1 — pages an
     * operator for the design working as intended.
     *
     * It must not be silenced either: the warning is what distinguishes "two editors are busy
     * on one resource" from "this branch has been stuck for a week".
     */
    public function testALostBranchRaceContinuesTheRunButStaysVisible(): void
    {
        $contended = $this->approveOne('editor-1', 'US');
        $nextBatch = $this->approveOne('editor-2', 'DE');

        $testHandler = new TestHandler();
        $logger      = new Logger('test', [$testHandler]);
        $publisher   = new FakeSourceDataPublisher(
            $this->repo,
            new GitHubApiException(422, 'Update is not a fast forward'),
            $contended
        );
        $runner      = new PublishRunner($this->repo, $publisher, logger: $logger);

        $result = $runner->runOnce();

        self::assertFalse($result->stoppedOnFailure, 'expected contention must not page an operator');
        self::assertSame(1, $result->published);
        self::assertTrue(
            $testHandler->hasWarningThatContains('lost a race'),
            'continuing must not mean going quiet'
        );

        // The contended batch is released, not stranded: the next tick republishes it onto the
        // branch head the winner pushed.
        foreach ($this->repo->getBatch($contended) as $row) {
            self::assertSame(ChangePublicationStatus::NONE->value, $row['publication_status']);
        }

        // The rest of the queue drained rather than being abandoned for the tick.
        foreach ($this->repo->getBatch($nextBatch) as $row) {
            self::assertSame(ChangePublicationStatus::OPEN->value, $row['publication_status']);
        }
    }

    /**
     * Fix-round-2 finding: CLAIM_LOST reaching the branch-contention path must continue, not
     * stop, and the earlier fix (excluding CLAIM_LOST from the continue gate) was wrong on the
     * merits. Inside a lost branch race (a GitHub 422), CLAIM_LOST is doubly benign — the 422
     * itself is proof GitHub is healthy and answering (see the class docblock's "Contention is
     * not an outage"), and the release outcome is a POSITIVE observation that a different
     * runner holds a live, freshly-issued claim on this exact batch and is actively working
     * it. That is the opposite of BATCH_MISSING, which stays excluded because nothing is known
     * about a vanished row. {@see ClaimStolenSourceDataPublisher} reproduces the sequence
     * releaseClaim()'s own docblock narrates (A claims, the grace period elapses, B claims
     * with a fresh token, A's own doomed call finally returns) directly against the row.
     */
    public function testClaimLostUnderBranchContentionContinuesTheRun(): void
    {
        $contended = $this->approveOne('editor-1', 'US');
        $nextBatch = $this->approveOne('editor-2', 'DE');

        $testHandler = new TestHandler();
        $logger      = new Logger('test', [$testHandler]);
        $publisher   = new ClaimStolenSourceDataPublisher(
            $this->repo,
            self::$pdo,
            $contended,
            new GitHubApiException(422, 'Update is not a fast forward')
        );
        $runner      = new PublishRunner($this->repo, $publisher, logger: $logger);

        $result = $runner->runOnce();

        self::assertFalse(
            $result->stoppedOnFailure,
            'a batch actively held by another runner\'s live claim, lost only to a benign branch race, must not stop the run'
        );
        self::assertSame(1, $result->published, 'the queue must drain past the CLAIM_LOST batch to the next one');
        self::assertTrue($testHandler->hasWarningThatContains('lost a race'));
        self::assertFalse(
            $testHandler->hasWarningThatContains('the batch stays claimable'),
            'CLAIM_LOST is not "stays claimable": another runner already holds it'
        );
        self::assertTrue(
            $testHandler->hasWarningThatContains('another runner holds a live claim'),
            'the message must say the batch is spoken for, not merely available again'
        );

        // Untouched by this runner's release: still queued, under the "second runner"'s token,
        // exactly as a live claim actually being worked would be.
        foreach ($this->repo->getBatch($contended) as $row) {
            self::assertSame(ChangePublicationStatus::QUEUED->value, $row['publication_status']);
        }

        // The rest of the queue drained rather than being abandoned for the tick.
        foreach ($this->repo->getBatch($nextBatch) as $row) {
            self::assertSame(ChangePublicationStatus::OPEN->value, $row['publication_status']);
        }
    }

    /**
     * The sibling of the test above, and the one that pins the spec's actual sentence
     * ("PublishRunner treats it as a failure and stops the run"): that sentence is about the
     * fall-through default OUTSIDE of branch contention, which this test reaches by using a
     * non-422 failure. CLAIM_LOST here gets no positive "another runner is healthy and
     * working it, and GitHub just proved it" reading to lean on, so the run must stop exactly
     * as it would for RELEASED or NOT_CLAIMED. This is the sentence the branch-contention
     * exception above must not be allowed to quietly widen into a blanket "CLAIM_LOST never
     * stops" rule.
     */
    public function testClaimLostWithoutBranchContentionStopsTheRun(): void
    {
        $batchId = $this->approveOne('editor-1', 'US');

        $testHandler = new TestHandler();
        $logger      = new Logger('test', [$testHandler]);
        $publisher   = new ClaimStolenSourceDataPublisher(
            $this->repo,
            self::$pdo,
            $batchId,
            new GitHubApiException(500, 'Server Error')
        );
        $runner      = new PublishRunner($this->repo, $publisher, logger: $logger);

        $result = $runner->runOnce();

        self::assertTrue(
            $result->stoppedOnFailure,
            'CLAIM_LOST outside a benign branch race is a genuine failure and must stop the run'
        );
        self::assertSame(0, $result->published);
        self::assertTrue($testHandler->hasWarningThatContains('Stopping this run after a failed publish attempt.'));

        // Untouched by this runner's release: still queued, under the "second runner"'s token.
        foreach ($this->repo->getBatch($batchId) as $row) {
            self::assertSame(ChangePublicationStatus::QUEUED->value, $row['publication_status']);
        }
    }

    /**
     * `claimNextPublishableBatch()` was the one unwrapped DB call in `runOnce()`, inside a
     * method whose own docblock justifies wrapping its two siblings so a DB outage cannot
     * escape as a raw fatal with no summary line for the cron script to report. Same
     * treatment, same reason.
     */
    public function testAFailureToClaimIsLoggedNotThrown(): void
    {
        $this->approveOne('editor-1');

        $testHandler = new TestHandler();
        $logger      = new Logger('test', [$testHandler]);
        $runner      = new PublishRunner(
            new ThrowingClaimRepository(self::$pdo),
            new FakeSourceDataPublisher($this->repo),
            logger: $logger
        );

        $result = $runner->runOnce();

        self::assertSame(0, $result->published);
        self::assertTrue($result->stoppedOnFailure, 'a DB outage must reach the exit code, not a stack trace');
        self::assertTrue($testHandler->hasErrorThatContains('Claiming the next publishable'));
    }

    /**
     * Head-of-line blocking: the defect the final whole-branch review measured at five ticks,
     * the same batch attempted every time, two other editors' approved work never attempted
     * once. Candidates are ordered oldest-first, a failed publish returns its batch to `none`,
     * and the runner stops on failure — so without a bound the oldest failing batch is
     * re-claimed first on every tick, forever, and nothing behind it ever publishes.
     *
     * The bound is CONSECUTIVE attempts per batch. Once the oldest batch has spent them, it is
     * parked (not claimed, not deleted, not dead-lettered) and the queue drains past it. The
     * younger batch publishing on the very next tick is the assertion that matters; without the
     * fix it publishes on no tick at all, however many run.
     */
    public function testABatchThatKeepsFailingIsEventuallySkippedSoTheQueueDrains(): void
    {
        $failing = $this->approveOne('editor-1', 'US');
        $healthy = $this->approveOne('editor-2', 'DE');

        $testHandler = new TestHandler();
        $logger      = new Logger('test', [$testHandler]);
        $publisher   = new FakeSourceDataPublisher(
            $this->repo,
            new GitHubApiException(500, 'Server Error'),
            $failing
        );
        $runner      = new PublishRunner($this->repo, $publisher, logger: $logger);

        // Every attempt this batch is allowed. Each tick claims the OLDEST batch, which is the
        // failing one, and stops — so the healthy batch is not reached in any of them.
        for ($tick = 1; $tick <= SourceDataChangeRequestRepository::MAX_PUBLISH_ATTEMPTS; $tick++) {
            $result = $runner->runOnce();
            self::assertTrue($result->stoppedOnFailure, "tick {$tick} must still report a genuine failure");
            self::assertSame(0, $result->published);
        }

        self::assertSame(
            SourceDataChangeRequestRepository::MAX_PUBLISH_ATTEMPTS,
            $publisher->calls,
            'exactly one attempt per tick, on the same batch'
        );
        foreach ($this->repo->getBatch($healthy) as $row) {
            self::assertSame(
                ChangePublicationStatus::NONE->value,
                $row['publication_status'],
                'the younger batch is behind the failing one and has not been reached yet'
            );
        }

        // The tick that used to be identical to the five before it.
        $result = $runner->runOnce();

        self::assertSame(1, $result->published, 'the queue must drain past a batch that has stopped being attempted');
        self::assertFalse($result->stoppedOnFailure, 'a parked batch is not this run\'s failure');
        self::assertSame(1, $result->parkedBatches, 'a parked batch must be reported, not silently skipped');
        self::assertTrue(
            $testHandler->hasWarningThatContains('exhausted their publish attempts'),
            'a batch that stopped being attempted must reach the log, not only the health endpoint'
        );

        foreach ($this->repo->getBatch($healthy) as $row) {
            self::assertSame(ChangePublicationStatus::OPEN->value, $row['publication_status']);
        }

        // Parked, not lost: still approved, still `none`, its rows untouched, and claimable
        // again the moment an operator clears the counter.
        foreach ($this->repo->getBatch($failing) as $row) {
            self::assertSame(ChangePublicationStatus::NONE->value, $row['publication_status']);
            self::assertSame(ChangeReviewStatus::APPROVED->value, $row['review_status']);
            self::assertEquals(SourceDataChangeRequestRepository::MAX_PUBLISH_ATTEMPTS, $row['publish_attempts']);
        }
    }

    /**
     * A transient failure must NOT park a batch. `recordPublication()` clears the counter, so
     * the bound only ever fires on genuinely consecutive failures — otherwise a GitHub blip
     * every few days would eventually park perfectly good work.
     */
    public function testASuccessfulPublishClearsEarlierFailedAttempts(): void
    {
        $batchId = $this->approveOne('editor-1');

        $failing = $this->runnerThatThrows(new GitHubApiException(500, 'Server Error'));
        $failing->runOnce();

        foreach ($this->repo->getBatch($batchId) as $row) {
            self::assertEquals(1, $row['publish_attempts'], 'the failed attempt is counted');
        }

        $result = $this->runner()->runOnce();
        self::assertSame(1, $result->published);
        self::assertSame(0, $result->parkedBatches);

        foreach ($this->repo->getBatch($batchId) as $row) {
            self::assertSame(ChangePublicationStatus::OPEN->value, $row['publication_status']);
            self::assertEquals(0, $row['publish_attempts'], 'a batch that publishes carries no failure residue');
        }
    }

    /**
     * The sibling of the caught-failure path, and the one nothing else covers: a batch that
     * KILLS the process (an OOM on a large payload) is caught by no `catch` at all. Its claim
     * is recovered by the grace-period reclaim — so if a reclaim were free, that batch would be
     * re-claimed and re-crash forever, the same permanent head-of-line block reached through
     * the one path that runs no PHP. A reclaim therefore spends an attempt too.
     */
    public function testAReclaimedStaleClaimAlsoSpendsAnAttempt(): void
    {
        $batchId = $this->approveOne('editor-1');
        $this->repo->claimNextPublishableBatch();
        $this->backdateUpdatedAt($batchId, minutesAgo: 20);

        $runner = new PublishRunner($this->repo, new FakeSourceDataPublisher($this->repo), graceSeconds: 600);
        $runner->runOnce(0);

        foreach ($this->repo->getBatch($batchId) as $row) {
            self::assertSame(ChangePublicationStatus::NONE->value, $row['publication_status']);
            self::assertEquals(1, $row['publish_attempts'], 'an abandoned attempt is still an attempt');
        }
    }

    /**
     * Forgiving a 422 must depend on the release having actually been OBSERVED, not on the
     * GitHub status alone.
     *
     * When `releaseClaimSafely()` returns null the release itself threw — the "same outage
     * broke both" case it exists for — so the batch is still `queued` and nothing will touch it
     * until the 1800-second reclaim. Continuing there exits 0 on a database failure purely
     * because the GitHub error beside it happened to be a 422 (the same DB failure beside a 500
     * stops the run), and the warning would assert the opposite of what happened: that the
     * batch "stays claimable" and "the next tick republishes it".
     */
    public function testALostBranchRaceWhoseReleaseAlsoFailedIsStillThisRunsFailure(): void
    {
        $batchId = $this->approveOne('editor-1');

        $testHandler = new TestHandler();
        $logger      = new Logger('test', [$testHandler]);
        $runner      = new PublishRunner(
            new ThrowingReleaseClaimRepository(self::$pdo),
            new FakeSourceDataPublisher($this->repo, new GitHubApiException(422, 'Update is not a fast forward')),
            logger: $logger
        );

        $result = $runner->runOnce();

        self::assertTrue(
            $result->stoppedOnFailure,
            'whether a database outage trips the alarm must not depend on which GitHub status accompanied it'
        );
        self::assertSame(0, $result->published);
        self::assertTrue($testHandler->hasErrorThatContains('Releasing the claim'));
        self::assertFalse(
            $testHandler->hasWarningThatContains('stays claimable'),
            'the batch is stranded queued: saying otherwise sends an operator looking in the wrong place'
        );

        foreach ($this->repo->getBatch($batchId) as $row) {
            self::assertSame(ChangePublicationStatus::QUEUED->value, $row['publication_status']);
        }
    }

    /**
     * The sibling of the case above, and the one that would otherwise read an unexplained state
     * optimistically: a 422 whose batch has vanished entirely. `ClaimReleaseOutcome`'s own rule
     * is that `BATCH_MISSING` is never treated as settled; the contention branch must honour it
     * rather than swallowing it because the status was 422.
     */
    public function testALostBranchRaceOnAVanishedBatchIsStillThisRunsFailure(): void
    {
        $this->approveOne('editor-1');

        $testHandler = new TestHandler();
        $logger      = new Logger('test', [$testHandler]);
        $publisher   = new VanishingBatchSourceDataPublisher(
            self::$pdo,
            new GitHubApiException(422, 'Update is not a fast forward')
        );
        $runner      = new PublishRunner($this->repo, $publisher, logger: $logger);

        $result = $runner->runOnce();

        self::assertTrue($result->stoppedOnFailure, 'an unexplained state is never read optimistically');
        self::assertSame(0, $result->published);
        self::assertSame(1, $publisher->calls);
        self::assertFalse($testHandler->hasWarningThatContains('stays claimable'));
    }
}
