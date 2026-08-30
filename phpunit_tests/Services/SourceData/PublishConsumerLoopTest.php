<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Services\SourceData;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use LiturgicalCalendar\Api\Enum\ChangeOperation;
use LiturgicalCalendar\Api\Enum\ChangePublicationStatus;
use LiturgicalCalendar\Api\Enum\Rite;
use LiturgicalCalendar\Api\Repositories\SourceDataChangeRequestRepository;
use LiturgicalCalendar\Api\Services\ChangeResource;
use LiturgicalCalendar\Api\Services\GitHub\GitHubAppAuth;
use LiturgicalCalendar\Api\Services\GitHub\GitHubGitDataClient;
use LiturgicalCalendar\Api\Services\SourceData\MergePollRunner;
use LiturgicalCalendar\Api\Services\SourceData\PublishConsumerLoop;
use LiturgicalCalendar\Api\Services\SourceData\PublishRunner;
use LiturgicalCalendar\Tests\Repositories\RepositoryTestCase;
use LiturgicalCalendar\Tests\Support\ThrowingLogger;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Log\LoggerInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * Exercises `PublishConsumerLoop` against REAL `PublishRunner` / `MergePollRunner` instances —
 * both `final`, so neither can be subclassed into a call-counting spy the way the original
 * design for this test sketched. Every assertion here is therefore on an OBSERVABLE OUTCOME of
 * a real run (a batch's `publication_status` changing, an actual HTTP request landing on a
 * recording middleware) rather than on a call count, mirroring
 * {@see \LiturgicalCalendar\Tests\Services\SourceData\PublishRunnerTest} and
 * {@see \LiturgicalCalendar\Tests\Services\SourceData\MergePollRunnerTest}, which already solve
 * this same problem for their own subjects.
 *
 * `PublishConsumerLoop`'s own `try`/`catch` around each `runOnce()` call is NOT dead
 * defense-in-depth. A first pass at this suite tried to make `PublishRunner::runOnce()` /
 * `MergePollRunner::runOnce()` throw via the repository, publisher, GitHub client, purge
 * service, and audit log, and concluded that was impossible because every call to those four
 * collaborators is already wrapped in the runner's own `catch (\Throwable)`. That inventory
 * missed a fifth, ordinary constructor collaborator on both classes: `?LoggerInterface $logger`.
 * Every one of those `catch` blocks calls the logger from INSIDE itself, and
 * {@see MergePollRunner::unpollableCountSafely()}'s `warning()` call (fired when
 * `countOpenBatchesWithoutPullRequest()` is non-zero) sits entirely outside any `try`/`catch`,
 * at the very top of `runOnce()` — so a logger whose write throws propagates straight out,
 * exactly the escape the `PublishConsumerLoop` catch exists to stop. See
 * {@see testAPublishRunFailureDoesNotKillTheConsumer} and
 * {@see testAMergePollFailureDoesNotKillTheConsumer}, and the task report for the falsification
 * evidence (removing `PublishConsumerLoop`'s own catch makes both fail with the escaped
 * exception).
 */
#[CoversClass(PublishConsumerLoop::class)]
final class PublishConsumerLoopTest extends RepositoryTestCase
{
    /** Matches GitHubAppAuth::cacheKey() for installation id '67890'. */
    private const AUTH_CACHE_KEY = 'github_app_installation_token_67890';

    private SourceDataChangeRequestRepository $repo;

    /** @var list<\Psr\Http\Message\RequestInterface> */
    private array $sentRequests = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo         = new SourceDataChangeRequestRepository(self::$pdo);
        $this->sentRequests = [];
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

    /** An approved-and-published batch, with an open pull request left for the merge poller. */
    private function publishedBatch(string $sub, string $nation, int $prNumber, string $commitSha): string
    {
        $batchId = $this->repo->submitBatch(
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
        )['batch_id'];

        $this->repo->approveBatch($batchId, 'reviewer-1');
        $claim = $this->repo->claimNextPublishableBatch();
        self::assertNotNull($claim);
        $this->repo->recordPublication($batchId, "litcal-data/national_calendar/roman/{$nation}", $commitSha, $prNumber, 'base-sha');

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

    /**
     * Pre-seeds the installation token cache so `GitHubAppAuth::installationToken()` never
     * exchanges over HTTP. Same convention as {@see MergePollRunnerTest::auth()}.
     */
    private function auth(): GitHubAppAuth
    {
        $cache = new ArrayAdapter();
        $item  = $cache->getItem(self::AUTH_CACHE_KEY);
        $item->set('ghs_test_token');
        $cache->save($item);

        $noHttp = new GuzzleClient(['handler' => HandlerStack::create(new MockHandler([]))]);

        return new GitHubAppAuth('12345', '67890', '/nonexistent/should-not-be-read.pem', $noHttp, $cache);
    }

    /** @param list<GuzzleResponse> $responses */
    private function mergePollRunnerFor(array $responses, ?LoggerInterface $logger = null): MergePollRunner
    {
        $mock  = new MockHandler($responses);
        $stack = HandlerStack::create($mock);
        $stack->push(function (callable $handler): callable {
            return function ($request, array $options) use ($handler) {
                $this->sentRequests[] = $request;

                return $handler($request, $options);
            };
        });
        $http = new GuzzleClient(['handler' => $stack]);

        $client = new GitHubGitDataClient('Liturgical-Calendar', 'LiturgicalCalendarAPI', $this->auth(), $http);

        return new MergePollRunner($this->repo, $client, logger: $logger);
    }

    /** Marks an already-open batch `open` with no `pr_number` — the "unpollable" state. */
    private function makeUnpollable(string $batchId): void
    {
        $stmt = self::$pdo->prepare('UPDATE sourcedata_change_requests SET pr_number = NULL WHERE batch_id = :b');
        $stmt->execute(['b' => $batchId]);
    }

    private static function openPrJson(string $headSha): GuzzleResponse
    {
        return new GuzzleResponse(200, [], json_encode([
            'state'            => 'open',
            'merged'           => false,
            'merge_commit_sha' => null,
            'head'             => ['sha' => $headSha],
        ], JSON_THROW_ON_ERROR));
    }

    /** @return list<string> Request paths containing '/pulls/', in order sent. */
    private function pullRequestPollPaths(): array
    {
        return array_values(array_filter(
            array_map(static fn ($r): string => $r->getUri()->getPath(), $this->sentRequests),
            static fn (string $p): bool => str_contains($p, '/pulls/')
        ));
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

    // -- Tests: publish-failure backoff ----------------------------------------------------------

    /**
     * A stream-driven publish run that stops on failure must suppress a SECOND stream-driven
     * run arriving inside the backoff window — otherwise a backlog of queued `XADD`s, each
     * waking `tick()` with no block between them, burns `publish_attempts` far faster than the
     * cron interval `MAX_PUBLISH_ATTEMPTS` was sized against. See the class docblock's
     * "Publish-failure backoff" section.
     */
    public function testASecondMessageInsideTheBackoffWindowDoesNotTriggerASecondPublishRun(): void
    {
        $this->approveOne('editor-1');
        $throwingPublisher = new FakeSourceDataPublisher($this->repo, new \RuntimeException('GitHub down'));
        $publisher         = new PublishRunner($this->repo, $throwingPublisher);

        $loop = new PublishConsumerLoop(
            new ScriptedStreamConsumer([['batch-1'], ['batch-2']]),
            $publisher,
            blockMs: 0,
            mergePollIntervalSeconds: 3600
        );

        $loop->tick();
        self::assertSame(1, $throwingPublisher->calls, 'the first message runs and fails');

        $loop->tick();
        self::assertSame(
            1,
            $throwingPublisher->calls,
            'a second message inside the backoff window must not trigger a second publish run'
        );
    }

    /**
     * The other edge of the same window: once it has elapsed, a stream-driven message must run
     * a publish attempt again — the backoff is temporary, not a one-way latch, and cron is only
     * the backstop, not the sole path back to trying.
     */
    public function testAMessageAfterTheBackoffWindowTriggersAnotherPublishRun(): void
    {
        $this->approveOne('editor-1');
        $throwingPublisher = new FakeSourceDataPublisher($this->repo, new \RuntimeException('GitHub down'));
        $publisher         = new PublishRunner($this->repo, $throwingPublisher);

        $loop = new PublishConsumerLoop(
            new ScriptedStreamConsumer([['batch-1'], ['batch-2']]),
            $publisher,
            blockMs: 0,
            mergePollIntervalSeconds: 1
        );

        $loop->tick();
        self::assertSame(1, $throwingPublisher->calls, 'the first message runs and fails');

        sleep(2);

        $loop->tick();
        self::assertSame(
            2,
            $throwingPublisher->calls,
            'a message after the backoff window has elapsed must trigger another publish run'
        );
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

    // -- Tests: the idle merge poll ---------------------------------------------------------

    /**
     * `blockMs` is 5000, so an unrated idle tick would poll GitHub 720 times an hour to watch
     * for a transition nobody is waiting on. Three idle ticks with a one-hour interval must
     * cost exactly one GitHub `/pulls/` request, not three.
     */
    public function testTheIdleMergePollIsRateLimited(): void
    {
        $this->publishedBatch('editor-1', 'US', 11, 'sha-a');

        $loop = new PublishConsumerLoop(
            new ScriptedStreamConsumer([[], [], []]),
            $this->publishRunner(),
            $this->mergePollRunnerFor([self::openPrJson('sha-a')]),
            blockMs: 0,
            mergePollIntervalSeconds: 3600
        );

        $loop->tick();
        $loop->tick();
        $loop->tick();

        self::assertCount(1, $this->pullRequestPollPaths(), 'three idle ticks, one poll');
    }

    /**
     * The inverse edge: a zero-second interval never blocks a poll, so every idle tick polls.
     * Pins the boundary condition ( `< $mergePollIntervalSeconds` ) from the other direction —
     * a suite that only ever exercised the rate-limited case could not tell an "always skip"
     * bug from a correctly-rate-limited one.
     */
    public function testAZeroSecondIntervalPollsOnEveryIdleTick(): void
    {
        $this->publishedBatch('editor-1', 'US', 11, 'sha-a');

        $loop = new PublishConsumerLoop(
            new ScriptedStreamConsumer([[], []]),
            $this->publishRunner(),
            $this->mergePollRunnerFor([self::openPrJson('sha-a'), self::openPrJson('sha-a')]),
            blockMs: 0,
            mergePollIntervalSeconds: 0
        );

        $loop->tick();
        $loop->tick();

        self::assertCount(2, $this->pullRequestPollPaths(), 'a zero-second interval never withholds a poll');
    }

    /**
     * Merge detection only runs on the idle tick. A tick woken by an actual message must not
     * also spend a GitHub call on merge polling — the mock queue is left empty, so a stray poll
     * would surface as an exception, but `MergePollRunner` would swallow that too; only counting
     * the real requests distinguishes "never polled" from "polled and happened to fail".
     */
    public function testAWokenTickDoesNotAlsoPollMerges(): void
    {
        $batchId = $this->approveOne('editor-1');

        $loop = new PublishConsumerLoop(
            new ScriptedStreamConsumer([['batch-1']]),
            $this->publishRunner(),
            $this->mergePollRunnerFor([]),
            blockMs: 0,
            mergePollIntervalSeconds: 0
        );

        $loop->tick();

        self::assertSame(ChangePublicationStatus::OPEN->value, $this->publicationStatus($batchId));
        self::assertCount(0, $this->pullRequestPollPaths(), 'a message tick must not spend a merge poll');
    }

    public function testIdleTicksWithoutAMergePollerDoNotThrow(): void
    {
        $loop = new PublishConsumerLoop(
            new ScriptedStreamConsumer([[], [], []]),
            $this->publishRunner(),
            mergePoller: null,
            blockMs: 0
        );

        $loop->tick();
        $loop->tick();
        $loop->tick();

        self::assertTrue(true, 'no merge poller configured; idle ticks must be inert, not fatal');
    }

    /**
     * A merge poll that actually settles a batch is a real, end-to-end observable outcome of the
     * idle tick — not just "an HTTP request was sent", but the batch's own status changing.
     */
    public function testAnIdleMergePollThatFindsAMergedPrSettlesTheBatch(): void
    {
        $batchId = $this->publishedBatch('editor-1', 'US', 11, 'sha-a');

        $loop = new PublishConsumerLoop(
            new ScriptedStreamConsumer([[]]),
            $this->publishRunner(),
            $this->mergePollRunnerFor([
                new GuzzleResponse(200, [], json_encode([
                    'state'            => 'closed',
                    'merged'           => true,
                    'merge_commit_sha' => 'merge-sha',
                    'head'             => ['sha' => 'sha-a'],
                ], JSON_THROW_ON_ERROR)),
                // Containment is always verified with a compareCommits() call, even for the
                // batch whose commit sha equals the reported head.sha — see MergePollRunner's
                // own class docblock, "No zero-call fast path".
                new GuzzleResponse(200, [], json_encode(['status' => 'identical'], JSON_THROW_ON_ERROR)),
            ]),
            blockMs: 0,
            mergePollIntervalSeconds: 0
        );

        $loop->tick();

        self::assertSame(ChangePublicationStatus::MERGED->value, $this->publicationStatus($batchId));
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
     * The cleanest trigger: {@see MergePollRunner::unpollableCountSafely()} calls
     * `$this->logger->warning()` on a non-zero unpollable count entirely OUTSIDE any
     * `try`/`catch` — and it runs at the very top of `runOnce()`, before the method's own first
     * `try` block even opens. A throwing logger there escapes `runOnce()` immediately and
     * completely. Without `PublishConsumerLoop`'s own catch around the idle-tick merge-poll
     * call, this test fails with the escaped `\RuntimeException` — see the task report.
     */
    public function testAMergePollFailureDoesNotKillTheConsumer(): void
    {
        $batchId = $this->publishedBatch('editor-1', 'US', 11, 'sha-a');
        $this->makeUnpollable($batchId);

        $loop = new PublishConsumerLoop(
            new ScriptedStreamConsumer([[]]),
            $this->publishRunner(),
            $this->mergePollRunnerFor([], new ThrowingLogger()),
            blockMs: 0
        );

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

    /**
     * The idle merge poll depends on Postgres and GitHub, not Redis — a stream outage is not a
     * reason to stop finding merged pull requests, and it stays useful throughout one. `$woken`
     * never becomes `true` when `readOnce()` throws before invoking its callback, so the normal
     * `if (!$woken)` branch already reaches `pollMergesIfDue()` on this path.
     */
    public function testAStreamReadFailureStillRunsTheIdleMergePoll(): void
    {
        $batchId = $this->publishedBatch('editor-1', 'US', 11, 'sha-a');

        $loop = new PublishConsumerLoop(
            new ThrowingReadStreamConsumer(),
            $this->publishRunner(),
            $this->mergePollRunnerFor([
                new GuzzleResponse(200, [], json_encode([
                    'state'            => 'closed',
                    'merged'           => true,
                    'merge_commit_sha' => 'merge-sha',
                    'head'             => ['sha' => 'sha-a'],
                ], JSON_THROW_ON_ERROR)),
                new GuzzleResponse(200, [], json_encode(['status' => 'identical'], JSON_THROW_ON_ERROR)),
            ]),
            blockMs: 0,
            mergePollIntervalSeconds: 0
        );

        $loop->tick();

        self::assertSame(
            ChangePublicationStatus::MERGED->value,
            $this->publicationStatus($batchId),
            'a stream-read failure must not skip the idle merge poll'
        );
    }
}
