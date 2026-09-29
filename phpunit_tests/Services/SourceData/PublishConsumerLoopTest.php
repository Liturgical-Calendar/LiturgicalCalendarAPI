<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Services\SourceData;

use LiturgicalCalendar\Api\Enum\ChangeOperation;
use LiturgicalCalendar\Api\Enum\ChangePublicationStatus;
use LiturgicalCalendar\Api\Enum\Rite;
use LiturgicalCalendar\Api\Repositories\SourceDataChangeRequestRepository;
use LiturgicalCalendar\Api\Services\ChangeResource;
use LiturgicalCalendar\Api\Services\SourceData\PublishConsumerLoop;
use LiturgicalCalendar\Api\Services\SourceData\PublishRunner;
use LiturgicalCalendar\Tests\Repositories\RepositoryTestCase;
use LiturgicalCalendar\Tests\Support\ThrowingLogger;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Exercises `PublishConsumerLoop` against a REAL `PublishRunner` — `final`, so it cannot be
 * subclassed into a call-counting spy. Every assertion here is therefore on an OBSERVABLE OUTCOME
 * of a real run (a batch's `publication_status` changing), mirroring
 * {@see \LiturgicalCalendar\Tests\Services\SourceData\PublishRunnerTest}.
 *
 * The loop only consumes. Publishing with no message (stranded claims, elapsed backoff) and merge
 * polling are the job runner's `publish-backstop` and `merge-poll` jobs (#1008), tested with the
 * job catalog, so an idle tick here must publish nothing.
 *
 * `PublishConsumerLoop`'s own `try`/`catch` around `runOnce()` is NOT dead defense-in-depth: every
 * `catch` inside `PublishRunner` reports through its `?LoggerInterface $logger`, so a logger whose
 * write throws propagates straight out of `runOnce()` — exactly the escape the loop's catch exists
 * to stop. See {@see testAPublishRunFailureDoesNotKillTheConsumer}.
 */
#[CoversClass(PublishConsumerLoop::class)]
final class PublishConsumerLoopTest extends RepositoryTestCase
{
    private SourceDataChangeRequestRepository $repo;

    /** @var list<\Psr\Http\Message\RequestInterface> */
    private array $sentRequests = [];

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

        $batchId = $submission['batch_id'];
        $this->repo->approveBatch($batchId, 'reviewer-1');

        return $batchId;
    }

    private function publicationStatus(string $batchId): string
    {
        $stmt = self::$pdo->prepare('SELECT publication_status FROM sourcedata_change_requests WHERE batch_id = :b LIMIT 1');
        $stmt->execute(['b' => $batchId]);

        return (string) $stmt->fetchColumn();
    }

    private function publishRunner(): PublishRunner
    {
        return new PublishRunner($this->repo, new FakeSourceDataPublisher($this->repo));
    }

    // -- Tests: the message is a hint, never a work item ---------------------------------------

    public function testAMessageTriggersAPublishRunThatClaimsFromPostgres(): void
    {
        $batchId = $this->approveOne('editor-1');

        $loop = new PublishConsumerLoop(
            new ScriptedStreamConsumer([['batch-1']]),
            $this->publishRunner()
        );

        $loop->tick();

        self::assertSame(ChangePublicationStatus::OPEN->value, $this->publicationStatus($batchId));
    }

    /**
     * `PublishRunner::runOnce()` takes no batch id argument at all — it claims whatever is
     * oldest and claimable in Postgres. So a message carrying an id that matches NOTHING in the
     * database still results in the real, approved batch being published: the message id is
     * read only for the log line, never used to decide what gets worked.
     */
    public function testAGarbageBatchIdInTheMessageStillPublishesTheRealApprovedBatch(): void
    {
        $realBatchId = $this->approveOne('editor-1');

        $loop = new PublishConsumerLoop(
            new ScriptedStreamConsumer([['00000000-0000-0000-0000-000000000000']]),
            $this->publishRunner()
        );

        $loop->tick();

        self::assertSame(ChangePublicationStatus::OPEN->value, $this->publicationStatus($realBatchId));
    }

    /**
     * A duplicated message (two ids in the same tick, or the same batch id sent twice) must
     * cost at most one wasted claim against an empty queue, not a duplicate publish — proving
     * the queue, not the message count, decides how much work happens.
     */
    public function testTwoMessagesInOneTickPublishOnlyTheOneApprovedBatch(): void
    {
        $batchId  = $this->approveOne('editor-1');
        $auditPub = new FakeSourceDataPublisher($this->repo);

        $loop = new PublishConsumerLoop(
            new ScriptedStreamConsumer([['batch-1', 'batch-1']]),
            new PublishRunner($this->repo, $auditPub)
        );

        $loop->tick();

        self::assertSame(ChangePublicationStatus::OPEN->value, $this->publicationStatus($batchId));
        self::assertSame(1, $auditPub->calls, 'the second message finds an empty queue, not a second batch');
    }

    // -- Tests: per-batch scheduling ------------------------------------------------------------

    /**
     * The observable half of the mechanism, asserted directly rather than inferred from a call
     * count: a failed publish must leave the batch NOT DUE, because that — and not any in-process
     * window — is what stops the next wake from re-attempting it.
     */
    public function testAFailedPublishSchedulesTheBatchIntoTheFuture(): void
    {
        $batchId   = $this->approveOne('editor-1');
        $publisher = new PublishRunner($this->repo, new FakeSourceDataPublisher($this->repo, new \RuntimeException('GitHub down')));

        ( new PublishConsumerLoop(new ScriptedStreamConsumer([['batch-1']]), $publisher, blockMs: 0) )->tick();

        self::assertSame(ChangePublicationStatus::NONE->value, $this->publicationStatus($batchId));
        self::assertTrue($this->isScheduledIntoTheFuture($batchId), 'releaseClaim must have set next_attempt_at ahead of now');
    }

    /**
     * A backlog of queued `XADD`s wakes `tick()` with no block between them, so before per-batch
     * scheduling existed each one drove another attempt and burned `publish_attempts` far faster
     * than the cron interval `MAX_PUBLISH_ATTEMPTS` was sized against. The batch is now simply not
     * claimable yet, so the second message finds an empty queue instead of being suppressed by a
     * window that would also have paused batches which never failed.
     */
    public function testASecondMessageDoesNotReAttemptABatchWhoseBackoffHasNotElapsed(): void
    {
        $this->approveOne('editor-1');
        $throwingPublisher = new FakeSourceDataPublisher($this->repo, new \RuntimeException('GitHub down'));
        $publisher         = new PublishRunner($this->repo, $throwingPublisher);

        $loop = new PublishConsumerLoop(new ScriptedStreamConsumer([['batch-1'], ['batch-2']]), $publisher, blockMs: 0);

        $loop->tick();
        self::assertSame(1, $throwingPublisher->calls, 'the first message runs and fails');

        $loop->tick();
        self::assertSame(1, $throwingPublisher->calls, 'the batch is not due, so the second message claims nothing');
    }

    /**
     * The other edge: the schedule is a delay, not a latch. Once the batch is due again a wake
     * re-attempts it, without waiting for cron.
     *
     * The wait itself is 300 seconds ({@see \LiturgicalCalendar\Api\Services\SourceData\PublishBackoff}),
     * so the elapsed time is simulated by moving the stamp rather than slept through.
     */
    public function testAMessageReAttemptsTheBatchOnceItIsDueAgain(): void
    {
        $batchId           = $this->approveOne('editor-1');
        $throwingPublisher = new FakeSourceDataPublisher($this->repo, new \RuntimeException('GitHub down'));
        $publisher         = new PublishRunner($this->repo, $throwingPublisher);

        $loop = new PublishConsumerLoop(new ScriptedStreamConsumer([['batch-1'], ['batch-2']]), $publisher, blockMs: 0);

        $loop->tick();
        self::assertSame(1, $throwingPublisher->calls, 'the first message runs and fails');

        $this->makeDue($batchId);

        $loop->tick();
        self::assertSame(2, $throwingPublisher->calls, 'a due batch is attempted again on the next wake');
    }

    /** True when every row of the batch is scheduled past now. */
    private function isScheduledIntoTheFuture(string $batchId): bool
    {
        $stmt = self::$pdo->prepare(
            'SELECT bool_and(next_attempt_at > NOW())::int AS scheduled
               FROM sourcedata_change_requests
              WHERE batch_id = :batch_id'
        );
        $stmt->execute(['batch_id' => $batchId]);

        // Cast in SQL rather than trusting the driver's boolean mapping, which differs between
        // PDO_PGSQL builds (bool vs. the strings 't'/'f').
        return 1 === (int) $stmt->fetchColumn();
    }

    /** Simulate the backoff having elapsed, rather than sleeping through it. */
    private function makeDue(string $batchId): void
    {
        $stmt = self::$pdo->prepare(
            "UPDATE sourcedata_change_requests SET next_attempt_at = NOW() - INTERVAL '1 second' WHERE batch_id = :batch_id"
        );
        $stmt->execute(['batch_id' => $batchId]);
    }

    // -- Tests: an idle tick, and stopping ---------------------------------------------------

    /**
     * With no message, a tick does nothing: publishing a batch whose `XADD` was lost, or one stranded
     * `queued`, is the `publish-backstop` job's work now (#1008), not a side effect of this loop.
     */
    public function testAnIdleTickPublishesNothing(): void
    {
        $batchId = $this->approveOne('editor-1');

        ( new PublishConsumerLoop(new ScriptedStreamConsumer([[]]), $this->publishRunner(), blockMs: 0) )->tick();

        self::assertSame(ChangePublicationStatus::NONE->value, $this->publicationStatus($batchId));
    }

    /** The job runner's SIGTERM reaches the loop as this callback; the loop must return rather than spin. */
    public function testRunReturnsWhenAskedToStop(): void
    {
        $consumer = new ScriptedStreamConsumer([[], [], [], []]);
        $checks   = 0;

        ( new PublishConsumerLoop($consumer, $this->publishRunner(), blockMs: 0) )->run(
            static function () use (&$checks): bool {
                return ++$checks > 2;
            }
        );

        self::assertSame(3, $checks);
        self::assertSame(1, $consumer->ensureGroupCalls, 'two ticks ran, and the group was ensured once');
    }

    // -- Tests: ensureGroup is memoised ---------------------------------------------------------

    public function testEnsureGroupRunsOnceAcrossManyTicks(): void
    {
        $consumer = new ScriptedStreamConsumer([[], [], []]);
        $loop     = new PublishConsumerLoop($consumer, $this->publishRunner());

        $loop->tick();
        $loop->tick();
        $loop->tick();

        self::assertSame(1, $consumer->ensureGroupCalls);
    }

    // -- Tests: nothing here may kill the consumer ----------------------------------------------

    /**
     * `PublishRunner`'s own `catch (\Throwable)` around `$this->publisher->publish()` calls
     * `$this->logger->error()` as ITS FIRST statement, before `releaseClaimSafely()` runs — and
     * that call is not itself wrapped by anything inside `PublishRunner`. A logger whose write
     * throws (see {@see ThrowingLogger}'s own docblock: this is not hypothetical —
     * `LoggerFactory::create()` and Monolog's stream handlers both throw for reachable
     * production conditions) therefore propagates straight out of `runOnce()`. Without
     * `PublishConsumerLoop`'s own catch around that call, this test fails with the escaped
     * `\RuntimeException` — see the task report for that falsification run.
     */
    /**
     * The hole the two tests around this one do NOT cover, and which this class's docblock used to
     * concede: they hand the ThrowingLogger to the RUNNER, so the loop reports the escape through a
     * logger of its own that happens to work. Give the LOOP a logger that throws on every write and
     * the old code died reporting the very failure it had just survived — the catch block's own
     * `error()` call threw straight back out of the catch.
     *
     * Both loggers throw here, so nothing in the chain can write: the run fails, the report of that
     * failure fails, and `tick()` must still return.
     */
    public function testALoggerThatThrowsOnEveryWriteCannotKillTheConsumer(): void
    {
        $this->approveOne('editor-1');
        $throwingPublisher = new FakeSourceDataPublisher($this->repo, new \RuntimeException('GitHub down'));
        $publisher         = new PublishRunner($this->repo, $throwingPublisher, logger: new ThrowingLogger());

        $loop = new PublishConsumerLoop(
            new ScriptedStreamConsumer([['batch-1']]),
            $publisher,
            blockMs: 0,
            logger: new ThrowingLogger()
        );

        $loop->tick();

        self::assertTrue(true, 'tick() returned even though reporting the failure also threw');
    }

    public function testAPublishRunFailureDoesNotKillTheConsumer(): void
    {
        $this->approveOne('editor-1');
        $throwingPublisher = new FakeSourceDataPublisher($this->repo, new \RuntimeException('GitHub down'));
        $publisher         = new PublishRunner($this->repo, $throwingPublisher, logger: new ThrowingLogger());

        $loop = new PublishConsumerLoop(new ScriptedStreamConsumer([['batch-1']]), $publisher);

        $loop->tick();

        self::assertTrue(true, 'tick() returned rather than propagating the logger\'s own throw');
    }

    /**
     * `ensureGroup()` and `readOnce()` sit OUTSIDE the inner try/catch that only ever guards
     * `$this->publisher->runOnce()` — see the class docblock's newest section. A `\RedisException`
     * from either (a dropped connection, a Redis restart) must not propagate out of `tick()`.
     *
     * Falsified by temporarily removing the outer `catch (\Throwable)` around `ensureGroup()` +
     * `readOnce()` in `PublishConsumerLoop::tick()`: this test then fails with the escaped
     * `RedisException` — see the task report for that run's output.
     */
    public function testAStreamReadFailureDoesNotKillTheConsumer(): void
    {
        $loop = new PublishConsumerLoop(
            new ThrowingReadStreamConsumer(),
            $this->publishRunner(),
            blockMs: 0
        );

        $loop->tick();

        self::assertTrue(true, 'tick() returned rather than propagating the RedisException');
    }

    /**
     * The connection may have dropped, so the group may no longer exist on whatever connection
     * replaces it — `groupEnsured` must reset to `false` on a stream-read failure so the NEXT
     * tick re-runs `ensureGroup()` rather than trusting a group that was only ever confirmed on
     * the now-broken connection.
     */
    public function testAStreamReadFailureResetsGroupEnsuredSoTheNextTickReEnsuresIt(): void
    {
        $consumer = new ThrowingReadStreamConsumer();
        $loop     = new PublishConsumerLoop($consumer, $this->publishRunner(), blockMs: 0);

        $loop->tick();
        $loop->tick();

        self::assertSame(2, $consumer->ensureGroupCalls, 'a failed read must not leave the group considered ensured');
    }
}
