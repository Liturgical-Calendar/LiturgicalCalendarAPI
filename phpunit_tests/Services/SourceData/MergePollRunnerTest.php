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
    private function runnerFor(array $responses, ?RecordingTuplePurgeService $purge = null): MergePollRunner
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

        return new MergePollRunner($this->repo, $client, $purge);
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
        ])->runOnce();

        self::assertSame(1, $result->merged);
        self::assertSame(ChangePublicationStatus::MERGED->value, $this->publicationStatus($batchId));
    }

    /**
     * Two batches, one rolling pull request, ONE GitHub poll. The rolling branch is per resource,
     * so polling per batch would ask the same question twice.
     */
    public function testTwoBatchesOnOnePullRequestCostOnePollAndBothTransition(): void
    {
        $first  = $this->publishedBatch('editor-1', 'US', 11, 'sha-a');
        $second = $this->publishedBatch('editor-2', 'US', 11, 'sha-b');

        $result = $this->runnerFor([
            self::prJson('closed', true, 'merge-sha', 'sha-b'),
            // one compare, for `sha-a` only — `sha-b` IS the head and needs none
            new GuzzleResponse(200, [], json_encode(['status' => 'ahead'], JSON_THROW_ON_ERROR)),
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
        self::assertCount(1, $comparePaths, 'sha-b is the head and needs no compare; only sha-a does');
        self::assertStringEndsWith('/compare/sha-a...merge-sha', $comparePaths[0]);
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
        $head  = $this->publishedBatch('editor-1', 'US', 11, 'sha-head');
        $other = $this->publishedBatch('editor-2', 'US', 11, 'sha-other');

        $result = $this->runnerFor([
            self::prJson('closed', true, 'merge-sha', 'sha-other'),
            new GuzzleResponse(500, [], json_encode(['message' => 'boom'], JSON_THROW_ON_ERROR)),
        ])->runOnce();

        self::assertTrue($result->stoppedOnFailure);
        self::assertSame(ChangePublicationStatus::OPEN->value, $this->publicationStatus($head));
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
        ], $purge)->runOnce();

        self::assertSame(1, $result->merged);
        self::assertFalse($result->stoppedOnFailure);
        self::assertSame(ChangePublicationStatus::MERGED->value, $this->publicationStatus($batchId));
    }
}
