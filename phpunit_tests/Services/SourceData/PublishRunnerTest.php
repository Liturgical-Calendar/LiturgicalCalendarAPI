<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Services\SourceData;

use LiturgicalCalendar\Api\Enum\ChangeOperation;
use LiturgicalCalendar\Api\Enum\ChangePublicationStatus;
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

        $runner = $this->runnerThatThrows(new GitHubApiException(422, 'Update is not a fast forward'));
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
        self::assertSame($batchId, $this->repo->claimNextPublishableBatch());
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
}
