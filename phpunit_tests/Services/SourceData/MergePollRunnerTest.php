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
use LiturgicalCalendar\Tests\Repositories\RepositoryTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * Exercises `MergePollRunner` against a real `GitHubGitDataClient` wired to a mocked HTTP
 * transport (same mock-first approach as {@see \LiturgicalCalendar\Tests\Services\GitHub\GitHubGitDataClientTest}
 * and {@see \LiturgicalCalendar\Tests\Services\SourceData\SourceDataPublisherTest}) plus a real
 * repository against Postgres.
 *
 * `GitHubAppAuth::installationToken()` is a real collaborator rather than a stub — it is
 * `final`, so PHPUnit cannot double it — but its cache is pre-warmed with a fake token before
 * each client is built, so its own (separately mocked, empty) HTTP queue is never touched. That
 * keeps the queued responses in every test reserved for the pull-request / compare calls
 * actually under test, with no app-token exchange consuming the first slot.
 */
#[CoversClass(MergePollRunner::class)]
final class MergePollRunnerTest extends RepositoryTestCase
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

    // -- Fixtures ------------------------------------------------------------------------------

    private function publishedBatch(string $sub, string $nation, int $prNumber, string $commitSha): string
    {
        $batchId = $this->repo->submitBatch(
            ChangeResource::nationalCalendar(Rite::ROMAN, $nation),
            [
                [
                    'path'      => "jsondata/sourcedata/rite/roman/calendars/nations/{$nation}/{$nation}.json",
                    'operation' => ChangeOperation::CREATE,
                    'content'   => '{"litcal":[]}',
                ]
            ],
            $sub,
            'Editor',
            $sub . '@example.test',
            true
        )['batch_id'];

        $this->repo->approveBatch($batchId, 'reviewer-1');
        $claim = $this->repo->claimNextPublishableBatch();
        self::assertNotNull($claim);
        self::assertSame($batchId, $claim->batchId);
        $this->repo->recordPublication(
            $batchId,
            "litcal-data/national_calendar/roman/{$nation}",
            $commitSha,
            $prNumber,
            'base-sha'
        );

        return $batchId;
    }

    private function deletionBatch(string $sub, string $nation, int $prNumber, string $commitSha): string
    {
        $batchId = $this->repo->submitBatch(
            ChangeResource::nationalCalendar(Rite::ROMAN, $nation),
            [
                [
                    'path'      => "jsondata/sourcedata/rite/roman/calendars/nations/{$nation}/{$nation}.json",
                    'operation' => ChangeOperation::DELETE,
                    'content'   => null,
                ]
            ],
            $sub,
            'Editor',
            $sub . '@example.test',
            true,
            ['authorizing_relation' => 'admin', 'deletes_resource' => true]
        )['batch_id'];

        $this->repo->approveBatch($batchId, 'reviewer-1');
        $claim = $this->repo->claimNextPublishableBatch();
        self::assertNotNull($claim);
        $this->repo->recordPublication($batchId, "litcal-data/national_calendar/roman/{$nation}", $commitSha, $prNumber, 'base');

        return $batchId;
    }

    /**
     * Pre-seeds the installation token cache so `GitHubAppAuth::installationToken()` never
     * exchanges over HTTP. See the class docblock: this repo's convention (matching
     * `GitHubGitDataClientTest` and `SourceDataPublisherTest`) is to seed the token rather than
     * queue a token-exchange response as the first mock response — queuing one would silently
     * consume a response meant for the call actually under test, shifting every later
     * assertion by one.
     */
    private function auth(): GitHubAppAuth
    {
        $cache = new ArrayAdapter();
        $item  = $cache->getItem(self::AUTH_CACHE_KEY);
        $item->set('ghs_test_token');
        $cache->save($item);

        // Empty queue: if installationToken() ever falls through to a real exchange instead of
        // the cache hit above, this throws loudly ("mock queue is empty") instead of quietly
        // consuming a response meant for the call under test.
        $noHttp = new GuzzleClient(['handler' => HandlerStack::create(new MockHandler([]))]);

        return new GitHubAppAuth('12345', '67890', '/nonexistent/should-not-be-read.pem', $noHttp, $cache);
    }

    /** @param list<GuzzleResponse> $responses */
    private function runnerFor(
        array $responses,
        ?RecordingTuplePurgeService $purge = null,
        ?RecordingAuditLogRepository $auditLog = null
    ): MergePollRunner {
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

        return new MergePollRunner($this->repo, $client, $purge, $auditLog);
    }

    /**
     * Overwrites one row's persisted `metadata` directly. `submitBatch()`'s `$metadata` parameter
     * applies uniformly to every row of a single call, so a genuinely mixed batch (some rows
     * flagged `deletes_resource`, some not — the shape a stale carry-forward produces) cannot be
     * built through the public API in one submission. Reaching into the row is this class's own
     * convention for forcing a specific persisted state (see
     * `testUnpollableOpenBatchesAreCountedNotSkipped`'s direct `pr_number` UPDATE).
     */
    private function setRowMetadata(string $batchId, string $path, string $metadataJson): void
    {
        $stmt = self::$pdo->prepare(
            'UPDATE sourcedata_change_requests SET metadata = :metadata WHERE batch_id = :batch_id AND path = :path'
        );
        $stmt->execute(['metadata' => $metadataJson, 'batch_id' => $batchId, 'path' => $path]);
    }

    private static function prJson(string $state, bool $merged, ?string $mergeSha, string $headSha): GuzzleResponse
    {
        return new GuzzleResponse(200, [], json_encode([
            'state'            => $state,
            'merged'           => $merged,
            'merge_commit_sha' => $mergeSha,
            'head'             => ['sha' => $headSha],
        ], JSON_THROW_ON_ERROR));
    }

    private function publicationStatus(string $batchId): string
    {
        $stmt = self::$pdo->prepare('SELECT publication_status FROM sourcedata_change_requests WHERE batch_id = :b LIMIT 1');
        $stmt->execute(['b' => $batchId]);

        return (string) $stmt->fetchColumn();
    }

    // -- Tests ---------------------------------------------------------------------------------

    public function testAnOpenPullRequestChangesNothing(): void
    {
        $batchId = $this->publishedBatch('editor-1', 'US', 11, 'sha-a');

        $result = $this->runnerFor([
            self::prJson('open', false, null, 'sha-a'),
        ])->runOnce();

        self::assertSame(0, $result->merged);
        self::assertSame(0, $result->closed);
        self::assertFalse($result->stoppedOnFailure);
        self::assertSame(ChangePublicationStatus::OPEN->value, $this->publicationStatus($batchId));
    }

    public function testAMergedPullRequestMarksTheHeadBatchMerged(): void
    {
        $batchId = $this->publishedBatch('editor-1', 'US', 11, 'sha-a');

        $result = $this->runnerFor([
            self::prJson('closed', true, 'merge-sha', 'sha-a'),
            // Containment is ALWAYS verified with a compareCommits() call, even for the batch
            // whose commit sha equals the reported head.sha — see MergePollRunner's own class
            // docblock, "No zero-call fast path".
            new GuzzleResponse(200, [], json_encode(['status' => 'identical'], JSON_THROW_ON_ERROR)),
        ])->runOnce();

        self::assertSame(1, $result->merged);
        self::assertSame(ChangePublicationStatus::MERGED->value, $this->publicationStatus($batchId));
    }

    /**
     * Two batches, one rolling pull request, ONE GitHub `/pulls/` poll — the rolling branch is
     * per resource, so polling per batch would ask the same question twice. Containment is a
     * SEPARATE cost from the poll itself: both batches get their own `compareCommits()` call
     * (see MergePollRunner's own class docblock, "No zero-call fast path" — there is no shortcut
     * for the batch whose commit happens to equal the reported head.sha), so one `/pulls/` poll
     * still costs two `/compare/` calls here.
     */
    public function testTwoBatchesOnOnePullRequestCostOnePollAndBothTransition(): void
    {
        $first  = $this->publishedBatch('editor-1', 'US', 11, 'sha-a');
        $second = $this->publishedBatch('editor-2', 'US', 11, 'sha-b');

        $result = $this->runnerFor([
            self::prJson('closed', true, 'merge-sha', 'sha-b'),
            // one compare per batch: sha-a first (submitted first), then sha-b (the head sha)
            new GuzzleResponse(200, [], json_encode(['status' => 'ahead'], JSON_THROW_ON_ERROR)),
            new GuzzleResponse(200, [], json_encode(['status' => 'identical'], JSON_THROW_ON_ERROR)),
        ])->runOnce();

        self::assertSame(2, $result->merged);
        self::assertSame(ChangePublicationStatus::MERGED->value, $this->publicationStatus($first));
        self::assertSame(ChangePublicationStatus::MERGED->value, $this->publicationStatus($second));

        $pullPaths = array_filter(
            array_map(static fn ($r): string => $r->getUri()->getPath(), $this->sentRequests),
            static fn (string $p): bool => str_contains($p, '/pulls/')
        );
        self::assertCount(1, $pullPaths, 'one pull request, one poll');

        // Pins the containment check's argument ORIENTATION. Both compare tests in this class
        // return a canned status regardless of what was actually sent, so without asserting the
        // URI, swapping isContained()'s call to compareCommits($mergeCommitSha, $batchCommitSha)
        // would leave the whole suite green while inverting the exact branch that decides
        // whether a batch's content is lost. `compareCommits($base, $head)` is called as
        // `compareCommits($batchCommitSha, $mergeCommitSha)`, so base = the batch's commit
        // (`sha-a`) and head = the merge commit (`merge-sha`).
        $comparePaths = array_values(array_filter(
            array_map(static fn ($r): string => $r->getUri()->getPath(), $this->sentRequests),
            static fn (string $p): bool => str_contains($p, '/compare/')
        ));
        self::assertCount(2, $comparePaths, 'every batch on the pull request gets its own compare, including the head sha');
        self::assertStringEndsWith('/compare/sha-a...merge-sha', $comparePaths[0]);
        self::assertStringEndsWith('/compare/sha-b...merge-sha', $comparePaths[1]);
    }

    /**
     * THE defect this containment check exists for. A reviewer merged concurrently with a publish,
     * so `sha-late` is on the branch but outside the merge. Marking it merged would make the
     * publisher skip it forever and lose its content silently.
     */
    public function testABatchNotContainedInTheMergeIsResetRatherThanMarkedMerged(): void
    {
        $early = $this->publishedBatch('editor-1', 'US', 11, 'sha-early');
        $late  = $this->publishedBatch('editor-2', 'US', 11, 'sha-late');

        $result = $this->runnerFor([
            self::prJson('closed', true, 'merge-sha', 'sha-early'),
            // `sha-early` IS the reported head, but it still gets its own compareCommits() call —
            // see MergePollRunner's "No zero-call fast path" — which reports it identical/contained.
            new GuzzleResponse(200, [], json_encode(['status' => 'identical'], JSON_THROW_ON_ERROR)),
            // `sha-late` is not an ancestor of the merge commit
            new GuzzleResponse(200, [], json_encode(['status' => 'diverged'], JSON_THROW_ON_ERROR)),
        ])->runOnce();

        self::assertSame(1, $result->merged);
        self::assertSame(1, $result->reset);
        self::assertSame(ChangePublicationStatus::MERGED->value, $this->publicationStatus($early));
        self::assertSame(
            ChangePublicationStatus::NONE->value,
            $this->publicationStatus($late),
            'a batch outside the merge must go back to claimable, never to merged'
        );
    }

    public function testAClosedUnmergedPullRequestClosesAndRejects(): void
    {
        $batchId = $this->publishedBatch('editor-1', 'US', 11, 'sha-a');

        $result = $this->runnerFor([
            self::prJson('closed', false, null, 'sha-a'),
        ])->runOnce();

        self::assertSame(1, $result->closed);
        self::assertSame(ChangePublicationStatus::CLOSED->value, $this->publicationStatus($batchId));
    }

    /**
     * A failed compare must NOT be read either way: assuming contained loses content, assuming not
     * contained republishes work already in the repository. The batch stays `open` for the next tick.
     */
    public function testAFailedContainmentCheckLeavesTheBatchOpen(): void
    {
        // Named for what it IS, not for what it is compared against: 'sha-not-head' is the
        // batch whose commit is NOT the pull request's reported head.sha ('sha-other', below) —
        // it is submitted first, so it is the one whose compareCommits() call fails.
        $notHead = $this->publishedBatch('editor-1', 'US', 11, 'sha-not-head');
        $other   = $this->publishedBatch('editor-2', 'US', 11, 'sha-other');

        $result = $this->runnerFor([
            self::prJson('closed', true, 'merge-sha', 'sha-other'),
            new GuzzleResponse(500, [], json_encode(['message' => 'boom'], JSON_THROW_ON_ERROR)),
        ])->runOnce();

        self::assertTrue($result->stoppedOnFailure);
        self::assertSame(ChangePublicationStatus::OPEN->value, $this->publicationStatus($notHead));
    }

    /**
     * A throw partway through ONE pull request's batches must not discard the transitions already
     * COMMITTED (to the database) for earlier batches on that SAME pull request. The first batch's
     * compare succeeds and `markBatchMerged()` runs for real before the second batch's compare
     * fails — `MergePollRunResult::$merged` must report that real, already-committed transition,
     * not zero, per {@see MergePollRunResult}'s own docblock: every count is reported on every
     * run, "including runs that stopped early".
     */
    public function testAThrowPartwayThroughOnePullRequestReportsTheTransitionsAlreadyCommitted(): void
    {
        $first  = $this->publishedBatch('editor-1', 'US', 11, 'sha-first');
        $second = $this->publishedBatch('editor-2', 'US', 11, 'sha-second');

        $result = $this->runnerFor([
            self::prJson('closed', true, 'merge-sha', 'sha-first'),
            new GuzzleResponse(200, [], json_encode(['status' => 'identical'], JSON_THROW_ON_ERROR)),
            new GuzzleResponse(500, [], json_encode(['message' => 'boom'], JSON_THROW_ON_ERROR)),
        ])->runOnce();

        self::assertTrue($result->stoppedOnFailure);
        self::assertSame(1, $result->merged, 'the first batch was genuinely merged before the second batch threw');
        self::assertSame(ChangePublicationStatus::MERGED->value, $this->publicationStatus($first));
        self::assertSame(ChangePublicationStatus::OPEN->value, $this->publicationStatus($second));
    }

    /**
     * GitHub contradicting itself: `merged: true` but no `merge_commit_sha`. Guessing a sha here
     * would be worse than stopping, so `pollOne()` throws rather than reading anything either
     * way; the batch is left `open` for the next tick, not merged and not reset.
     */
    public function testAMergedPullRequestWithNoMergeCommitShaStopsTheRunAndLeavesTheBatchOpen(): void
    {
        $batchId = $this->publishedBatch('editor-1', 'US', 11, 'sha-a');

        $result = $this->runnerFor([
            self::prJson('closed', true, null, 'sha-a'),
        ])->runOnce();

        self::assertTrue($result->stoppedOnFailure);
        self::assertSame(0, $result->merged);
        self::assertSame(0, $result->reset);
        self::assertSame(ChangePublicationStatus::OPEN->value, $this->publicationStatus($batchId));
    }

    public function testAFailedPollStopsTheRun(): void
    {
        $this->publishedBatch('editor-1', 'US', 11, 'sha-a');
        $this->publishedBatch('editor-2', 'IT', 22, 'sha-b');

        $result = $this->runnerFor([
            new GuzzleResponse(503, [], json_encode(['message' => 'unavailable'], JSON_THROW_ON_ERROR)),
        ])->runOnce();

        self::assertTrue($result->stoppedOnFailure);
        self::assertSame(0, $result->merged);

        // Proves "stop, don't hammer": if the run continued to the second pull request instead
        // of stopping after the first 503, the mock queue would be empty and Guzzle would throw
        // — a throw the same catch() would still turn into stoppedOnFailure=true, so the two
        // assertions above would pass either way. Only counting the actual /pulls/ requests
        // distinguishes "stopped after one poll" from "kept going and merely failed later".
        $pullPaths = array_filter(
            array_map(static fn ($r): string => $r->getUri()->getPath(), $this->sentRequests),
            static fn (string $p): bool => str_contains($p, '/pulls/')
        );
        self::assertCount(1, $pullPaths, 'a failed poll must stop the run, not continue to the next pull request');
    }

    public function testUnpollableOpenBatchesAreCountedNotSkipped(): void
    {
        $batchId = $this->publishedBatch('editor-1', 'US', 11, 'sha-a');
        self::$pdo->exec("UPDATE sourcedata_change_requests SET pr_number = NULL WHERE batch_id = '{$batchId}'");

        $result = $this->runnerFor([])->runOnce();

        self::assertSame(1, $result->unpollable);
        self::assertFalse($result->stoppedOnFailure, 'an unexplained row is reported, not an outage');
    }

    public function testAMergedResourceDeletionPurgesOperationalTuples(): void
    {
        $this->deletionBatch('editor-1', 'US', 11, 'sha-a');
        $purge = new RecordingTuplePurgeService();

        $this->runnerFor([
            self::prJson('closed', true, 'merge-sha', 'sha-a'),
            new GuzzleResponse(200, [], json_encode(['status' => 'identical'], JSON_THROW_ON_ERROR)),
        ], $purge)->runOnce();

        self::assertSame(['national_calendar:roman/US'], $purge->purged);
    }

    /**
     * THE false positive, at the level that matters. A batch that stages a DELETE for an i18n locale
     * file but does NOT delete the calendar must purge nothing when it merges — otherwise removing a
     * translation revokes every editor on a live calendar.
     */
    public function testAMergedLocaleRemovalPurgesNothing(): void
    {
        $batchId = $this->repo->submitBatch(
            ChangeResource::nationalCalendar(Rite::ROMAN, 'US'),
            [
                [
                    'path'      => 'jsondata/sourcedata/rite/roman/calendars/nations/US/US.json',
                    'operation' => ChangeOperation::UPDATE,
                    'content'   => '{"litcal":[]}',
                ],
                [
                    'path'      => 'jsondata/sourcedata/rite/roman/calendars/nations/US/i18n/fr.json',
                    'operation' => ChangeOperation::DELETE,
                    'content'   => null,
                ],
            ],
            'editor-1',
            'Editor',
            'editor-1@example.test',
            true,
            ['authorizing_relation' => 'admin']
        )['batch_id'];

        $this->repo->approveBatch($batchId, 'reviewer-1');
        $claim = $this->repo->claimNextPublishableBatch();
        self::assertNotNull($claim);
        $this->repo->recordPublication($batchId, 'litcal-data/national_calendar/roman/US', 'sha-a', 11, 'base');

        $purge = new RecordingTuplePurgeService();

        $this->runnerFor([
            self::prJson('closed', true, 'merge-sha', 'sha-a'),
            new GuzzleResponse(200, [], json_encode(['status' => 'identical'], JSON_THROW_ON_ERROR)),
        ], $purge)->runOnce();

        self::assertSame([], $purge->purged, 'a locale removal is not a resource deletion');
    }

    public function testAClosedUnmergedDeletionPurgesNothing(): void
    {
        $this->deletionBatch('editor-1', 'US', 11, 'sha-a');
        $purge = new RecordingTuplePurgeService();

        $this->runnerFor([
            self::prJson('closed', false, null, 'sha-a'),
        ], $purge)->runOnce();

        self::assertSame([], $purge->purged, 'a deletion that never merged deleted nothing');
    }

    /**
     * The transition is a fact about the repository. A purge failure must not un-record it — the
     * reconciler sweep is what cleans up, exactly as in disk mode.
     */
    public function testAFailingPurgeDoesNotUndoTheMerge(): void
    {
        $batchId = $this->deletionBatch('editor-1', 'US', 11, 'sha-a');
        $purge   = new RecordingTuplePurgeService(new \RuntimeException('OpenFGA unreachable'));

        $result = $this->runnerFor([
            self::prJson('closed', true, 'merge-sha', 'sha-a'),
            new GuzzleResponse(200, [], json_encode(['status' => 'identical'], JSON_THROW_ON_ERROR)),
        ], $purge)->runOnce();

        self::assertSame(1, $result->merged);
        self::assertFalse($result->stoppedOnFailure);
        self::assertSame(ChangePublicationStatus::MERGED->value, $this->publicationStatus($batchId));
    }

    /**
     * THE regression a `$rows[0]`-only read produces. `getBatch()` orders `BY path ASC` under the
     * database's collation, so which row sorts first is an accident of string comparison, not a
     * signal that it belongs to the submission that actually deleted the resource — and
     * `submitBatch()`'s carry-forward UPDATE can leave an untouched row's `metadata` (and
     * therefore its flag) exactly as an earlier, non-deleting submission left it. Pins the case
     * where the row lacking the flag sorts FIRST, verified below before the poll runs so a
     * differently-collated environment fails loudly here rather than silently exercising the
     * wrong branch.
     */
    public function testAMergedDeletionPurgesNothingWhenAnUnflaggedRowSortsFirst(): void
    {
        $flaggedPath   = 'jsondata/sourcedata/rite/roman/calendars/nations/US/US.json';
        $unflaggedPath = 'jsondata/sourcedata/rite/roman/calendars/nations/US/i18n/en.json';

        $batchId = $this->repo->submitBatch(
            ChangeResource::nationalCalendar(Rite::ROMAN, 'US'),
            [
                ['path' => $flaggedPath, 'operation' => ChangeOperation::DELETE, 'content' => null],
                ['path' => $unflaggedPath, 'operation' => ChangeOperation::DELETE, 'content' => null],
            ],
            'editor-1',
            'Editor',
            'editor-1@example.test',
            true,
            ['authorizing_relation' => 'admin', 'deletes_resource' => true]
        )['batch_id'];

        // Force the mismatch a stale carry-forward would also produce: this row loses the flag
        // every other row in the batch carries.
        $this->setRowMetadata($batchId, $unflaggedPath, '{"authorizing_relation":"admin"}');

        $this->repo->approveBatch($batchId, 'reviewer-1');
        $claim = $this->repo->claimNextPublishableBatch();
        self::assertNotNull($claim);
        $this->repo->recordPublication($batchId, 'litcal-data/national_calendar/roman/US', 'sha-a', 11, 'base');

        $rows = $this->repo->getBatch($batchId);
        self::assertSame($unflaggedPath, $rows[0]['path'], 'this test requires the unflagged row to sort first');

        $purge = new RecordingTuplePurgeService();

        $this->runnerFor([
            self::prJson('closed', true, 'merge-sha', 'sha-a'),
            new GuzzleResponse(200, [], json_encode(['status' => 'identical'], JSON_THROW_ON_ERROR)),
        ], $purge)->runOnce();

        self::assertSame([], $purge->purged, 'one unflagged row must block the purge, wherever it sorts');
    }

    /**
     * The inverse of the above: the flagged row sorts FIRST and the unflagged row sorts LATER.
     * A position-based read (`$rows[0]`) would purge here — this is exactly the case it got
     * "right" by accident, which is why unanimity, not position, is what must decide it.
     */
    public function testAMergedDeletionPurgesNothingWhenAFlaggedRowSortsFirst(): void
    {
        $unflaggedPath = 'jsondata/sourcedata/rite/roman/calendars/nations/US/US.json';
        $flaggedPath   = 'jsondata/sourcedata/rite/roman/calendars/nations/US/i18n/en.json';

        $batchId = $this->repo->submitBatch(
            ChangeResource::nationalCalendar(Rite::ROMAN, 'US'),
            [
                ['path' => $unflaggedPath, 'operation' => ChangeOperation::DELETE, 'content' => null],
                ['path' => $flaggedPath, 'operation' => ChangeOperation::DELETE, 'content' => null],
            ],
            'editor-1',
            'Editor',
            'editor-1@example.test',
            true,
            ['authorizing_relation' => 'admin', 'deletes_resource' => true]
        )['batch_id'];

        $this->setRowMetadata($batchId, $unflaggedPath, '{"authorizing_relation":"admin"}');

        $this->repo->approveBatch($batchId, 'reviewer-1');
        $claim = $this->repo->claimNextPublishableBatch();
        self::assertNotNull($claim);
        $this->repo->recordPublication($batchId, 'litcal-data/national_calendar/roman/US', 'sha-a', 11, 'base');

        $rows = $this->repo->getBatch($batchId);
        self::assertSame($flaggedPath, $rows[0]['path'], 'this test requires the flagged row to sort first');

        $purge = new RecordingTuplePurgeService();

        $this->runnerFor([
            self::prJson('closed', true, 'merge-sha', 'sha-a'),
            new GuzzleResponse(200, [], json_encode(['status' => 'identical'], JSON_THROW_ON_ERROR)),
        ], $purge)->runOnce();

        self::assertSame([], $purge->purged, 'one unflagged row must block the purge even when a flagged row sorts first');
    }

    public function testAMergedTransitionWritesAnAuditEntryOnlyOnce(): void
    {
        $batchId  = $this->publishedBatch('editor-1', 'US', 11, 'sha-a');
        $auditLog = new RecordingAuditLogRepository(self::$pdo);

        $runner = $this->runnerFor([
            self::prJson('closed', true, 'merge-sha', 'sha-a'),
            new GuzzleResponse(200, [], json_encode(['status' => 'identical'], JSON_THROW_ON_ERROR)),
        ], null, $auditLog);

        $runner->runOnce();

        self::assertSame([
            [
                'userId'       => null,
                'action'       => 'change_request.merged',
                'resourceType' => 'sourcedata_change_request',
                'resourceId'   => $batchId,
                'details'      => ['pr_number' => 11, 'merge_commit_sha' => 'merge-sha'],
            ]
        ], $auditLog->entries);

        // The batch is no longer `open`, so listOpenPullRequestNumbers() returns nothing on a
        // second poll and pollOne() is never invoked again for it — no further GitHub call is
        // queued, and none should be needed.
        $runner->runOnce();

        self::assertCount(1, $auditLog->entries, 'an already-settled pull request must not be audited twice');
    }

    public function testAClosedUnmergedTransitionWritesAnAuditEntryOnlyOnce(): void
    {
        $batchId  = $this->publishedBatch('editor-1', 'US', 11, 'sha-a');
        $auditLog = new RecordingAuditLogRepository(self::$pdo);

        $runner = $this->runnerFor([
            self::prJson('closed', false, null, 'sha-a'),
        ], null, $auditLog);

        $runner->runOnce();

        self::assertSame([
            [
                'userId'       => null,
                'action'       => 'change_request.closed_unmerged',
                'resourceType' => 'sourcedata_change_request',
                'resourceId'   => $batchId,
                'details'      => ['pr_number' => 11],
            ]
        ], $auditLog->entries);

        $runner->runOnce();

        self::assertCount(1, $auditLog->entries, 'an already-settled pull request must not be audited twice');
    }
}
