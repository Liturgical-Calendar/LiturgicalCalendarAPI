<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Services\GitHub;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use LiturgicalCalendar\Api\Services\GitHub\GitHubApiException;
use LiturgicalCalendar\Api\Services\GitHub\GitHubAppAuth;
use LiturgicalCalendar\Api\Services\GitHub\GitHubGitDataClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * Exercises the Git Data and Pulls wrapper entirely against a mocked HTTP client — same
 * mock-first approach as {@see \LiturgicalCalendar\Tests\Services\GitHub\GitHubAppAuthTest}, no
 * network and no credentials.
 *
 * `GitHubAppAuth::installationToken()` is a real collaborator rather than a stub — it is
 * `final`, so PHPUnit cannot double it — but its cache is pre-warmed with a fake token before
 * each client is built, so its own (separately mocked, empty) HTTP queue is never touched. That
 * keeps the single queued response in every test reserved for the Git Data call actually under
 * test.
 */
#[CoversClass(GitHubGitDataClient::class)]
final class GitHubGitDataClientTest extends TestCase
{
    private const OWNER = 'Liturgical-Calendar';

    private const REPO = 'LiturgicalCalendarAPI';

    /**
     * Matches the private key GitHubAppAuth::cacheKey() derives from installation id '67890':
     * 'github_app_installation_token_' . preg_replace('/[^A-Za-z0-9_.]/', '_', $installationId).
     */
    private const AUTH_CACHE_KEY = 'github_app_installation_token_67890';

    private function auth(): GitHubAppAuth
    {
        $cache = new ArrayAdapter();
        $item  = $cache->getItem(self::AUTH_CACHE_KEY);
        $item->set('ghs_test_token');
        $cache->save($item);

        // Empty queue: if installationToken() ever falls through to a real exchange instead of
        // the cache hit above, this throws loudly ("mock queue is empty") instead of quietly
        // consuming a response meant for the Git Data call under test.
        $noHttp = new GuzzleClient(['handler' => HandlerStack::create(new MockHandler([]))]);

        return new GitHubAppAuth('12345', '67890', '/nonexistent/should-not-be-read.pem', $noHttp, $cache);
    }

    /** @param array<int, GuzzleResponse> $responses */
    private function client(array $responses): GitHubGitDataClient
    {
        $guzzle = new GuzzleClient(['handler' => HandlerStack::create(new MockHandler($responses))]);

        return new GitHubGitDataClient(self::OWNER, self::REPO, $this->auth(), $guzzle);
    }

    /**
     * @param array<int, RequestInterface> $captured
     * @param array<int, GuzzleResponse>   $responses
     */
    private function clientCapturing(array &$captured, array $responses): GitHubGitDataClient
    {
        $handlerStack = HandlerStack::create(new MockHandler($responses));
        $handlerStack->push(static function (callable $handler) use (&$captured): callable {
            return static function (RequestInterface $request, array $options) use ($handler, &$captured) {
                $captured[] = $request;

                return $handler($request, $options);
            };
        });
        $guzzle = new GuzzleClient(['handler' => $handlerStack]);

        return new GitHubGitDataClient(self::OWNER, self::REPO, $this->auth(), $guzzle);
    }

    public function testGetRefReturnsNullForAMissingBranch(): void
    {
        $client = $this->client([new GuzzleResponse(404, [], json_encode(['message' => 'Not Found']))]);

        self::assertNull($client->getRef('litcal-data/roman/nation/US'));
    }

    public function testGetRefReturnsTheHeadShaWhenTheBranchExists(): void
    {
        $client = $this->client([
            new GuzzleResponse(200, [], json_encode(['ref' => 'refs/heads/development', 'object' => ['sha' => 'headsha1']])),
        ]);

        self::assertSame('headsha1', $client->getRef('development'));
    }

    public function testCreateRefSendsTheFullRefNameAndFromSha(): void
    {
        $captured = [];
        $client   = $this->clientCapturing($captured, [new GuzzleResponse(201, [], '{}')]);

        $client->createRef('litcal-data/roman/nation/US', 'basesha1');

        self::assertSame('POST', $captured[0]->getMethod());
        self::assertStringEndsWith('/git/refs', (string) $captured[0]->getUri());

        $body = json_decode((string) $captured[0]->getBody(), true);
        self::assertSame('refs/heads/litcal-data/roman/nation/US', $body['ref']);
        self::assertSame('basesha1', $body['sha']);
    }

    public function testCreateBlobReturnsTheNewBlobSha(): void
    {
        $captured = [];
        $client   = $this->clientCapturing($captured, [new GuzzleResponse(201, [], json_encode(['sha' => 'blob1']))]);

        $sha = $client->createBlob('{"foo":"bar"}');

        self::assertSame('blob1', $sha);

        $body = json_decode((string) $captured[0]->getBody(), true);
        self::assertSame('{"foo":"bar"}', $body['content']);
        self::assertSame('utf-8', $body['encoding']);
    }

    public function testATreeEntryMayCarryANullShaToExpressADeletion(): void
    {
        $captured = [];
        $client   = $this->clientCapturing($captured, [new GuzzleResponse(201, [], json_encode(['sha' => 'tree1']))]);

        $client->createTree('base1', [
            [
                'path' => 'jsondata/sourcedata/rite/roman/calendars/nations/US/US.json',
                'mode' => '100644',
                'type' => 'blob',
                'sha'  => null,
            ],
        ]);

        $body = json_decode((string) $captured[0]->getBody(), true);
        self::assertNull($body['tree'][0]['sha'], 'a null sha is how the API deletes a path');
        self::assertArrayHasKey('sha', $body['tree'][0], 'the key must be present and null, not omitted');
    }

    public function testCreateTreeReturnsTheNewTreeSha(): void
    {
        $client = $this->client([new GuzzleResponse(201, [], json_encode(['sha' => 'tree2']))]);

        $sha = $client->createTree('base2', [
            ['path' => 'a.json', 'mode' => '100644', 'type' => 'blob', 'sha' => 'blobsha'],
        ]);

        self::assertSame('tree2', $sha);
    }

    public function testCreateCommitSendsTheSingleParentAuthorAndCommitterAndReturnsTheNewSha(): void
    {
        $captured = [];
        $client   = $this->clientCapturing($captured, [new GuzzleResponse(201, [], json_encode(['sha' => 'commit1']))]);

        $author    = ['name' => 'Alice', 'email' => 'alice@example.test'];
        $committer = ['name' => 'Publisher Bot', 'email' => 'publisher@example.com'];
        $sha       = $client->createCommit('publish batch', 'tree3', 'parent1', $author, $committer);

        self::assertSame('commit1', $sha);

        $body = json_decode((string) $captured[0]->getBody(), true);
        self::assertSame('publish batch', $body['message']);
        self::assertSame('tree3', $body['tree']);
        self::assertSame(['parent1'], $body['parents']);
        self::assertSame($author, $body['author']);
        self::assertSame($committer, $body['committer'], 'author and committer must be sent as distinct objects');
    }

    public function testGetCommitTreeShaReturnsTheTreeSha(): void
    {
        $client = $this->client([
            new GuzzleResponse(200, [], json_encode(['sha' => 'commit1', 'tree' => ['sha' => 'tree9']])),
        ]);

        self::assertSame('tree9', $client->getCommitTreeSha('commit1'));
    }

    public function testGetCommitTreeShaRejectsA404(): void
    {
        $client = $this->client([new GuzzleResponse(404, [], json_encode(['message' => 'Not Found']))]);

        $this->expectException(GitHubApiException::class);
        $client->getCommitTreeSha('missing-commit');
    }

    public function testUpdateRefRefusesToForcePush(): void
    {
        $captured = [];
        $client   = $this->clientCapturing($captured, [new GuzzleResponse(200, [], '{}')]);

        $client->updateRef('litcal-data/roman/nation/US', 'abc123');

        $body = json_decode((string) $captured[0]->getBody(), true);
        // force:false turns a concurrent update into a retryable 422 instead of silently
        // clobbering another editor's commit.
        self::assertFalse($body['force'], 'the publisher must never force-push');
        self::assertSame('abc123', $body['sha']);
    }

    public function testUpdateRefEncodesEachBranchSegmentWithoutCollapsingTheSlashes(): void
    {
        $captured = [];
        $client   = $this->clientCapturing($captured, [new GuzzleResponse(200, [], '{}')]);

        $client->updateRef('litcal-data/roman/nation/US', 'abc123');

        $path = $captured[0]->getUri()->getPath();
        self::assertStringEndsWith('/git/refs/heads/litcal-data/roman/nation/US', $path);
        self::assertStringNotContainsString('%2F', $path);
    }

    public function testOpenPullRequestReturnsTheNewPullNumber(): void
    {
        $captured = [];
        $client   = $this->clientCapturing($captured, [new GuzzleResponse(201, [], json_encode(['number' => 42]))]);

        $number = $client->openPullRequest('litcal-data/roman/nation/US', 'development', 'Publish US', 'body text');

        self::assertSame(42, $number);

        $body = json_decode((string) $captured[0]->getBody(), true);
        self::assertSame('litcal-data/roman/nation/US', $body['head']);
        self::assertSame('development', $body['base']);
        self::assertSame('Publish US', $body['title']);
        self::assertSame('body text', $body['body']);
    }

    public function testFindOpenPullRequestReturnsTheNumberOfAnExistingOpenPr(): void
    {
        $captured = [];
        $client   = $this->clientCapturing($captured, [new GuzzleResponse(200, [], json_encode([['number' => 7]]))]);

        self::assertSame(7, $client->findOpenPullRequest('litcal-data/roman/nation/US'));

        $query = $captured[0]->getUri()->getQuery();
        self::assertStringContainsString('state=open', $query);
        self::assertStringContainsString('head=' . rawurlencode(self::OWNER . ':litcal-data/roman/nation/US'), $query);
    }

    public function testFindOpenPullRequestReturnsNullWhenNoneAreOpen(): void
    {
        $client = $this->client([new GuzzleResponse(200, [], '[]')]);

        self::assertNull($client->findOpenPullRequest('litcal-data/roman/nation/US'));
    }

    public function testANon2xxRaisesGitHubApiException(): void
    {
        $client = $this->client([new GuzzleResponse(422, [], json_encode(['message' => 'Update is not a fast forward']))]);

        $this->expectException(GitHubApiException::class);
        $client->updateRef('litcal-data/roman/nation/US', 'abc123');
    }

    public function testANon2xxCarriesTheStatusAndGitHubMessage(): void
    {
        $client = $this->client([new GuzzleResponse(422, [], json_encode(['message' => 'Update is not a fast forward']))]);

        try {
            $client->updateRef('litcal-data/roman/nation/US', 'abc123');
            self::fail('a 422 must not be swallowed');
        } catch (GitHubApiException $e) {
            self::assertSame(422, $e->status);
            self::assertStringContainsString('Update is not a fast forward', $e->getMessage());
        }
    }

    public function testANon2xxOnCreateBlobIsNotTreatedAsSuccess(): void
    {
        $client = $this->client([new GuzzleResponse(500, [], json_encode(['message' => 'Server Error']))]);

        $this->expectException(GitHubApiException::class);
        $client->createBlob('content');
    }

    /**
     * The 404 asymmetry, checked per method rather than only through getRef().
     *
     * getRef() treats 404 as "the branch does not exist yet, create it". Every other call must
     * treat it as a real failure: a 404 swallowed in createBlob(), createTree(), createCommit()
     * or createRef() would drop the editor's work while the change request reported success.
     */
    #[DataProvider('methodsThatMustRejectA404')]
    public function testA404IsAnErrorEverywhereExceptGetRef(string $label, callable $call): void
    {
        $client = $this->client([new GuzzleResponse(404, [], json_encode(['message' => 'Not Found']))]);

        try {
            $call($client);
            self::fail($label . ' must not treat a 404 as success');
        } catch (GitHubApiException $e) {
            self::assertSame(404, $e->status, $label . ' should surface the 404');
        }
    }

    /**
     * @return array<string, array{0: string, 1: callable(GitHubGitDataClient): mixed}>
     */
    public static function methodsThatMustRejectA404(): array
    {
        return [
            'createRef'        => ['createRef', static fn (GitHubGitDataClient $c): mixed => $c->createRef('litcal-data/x', 'abc123')],
            'createBlob'       => ['createBlob', static fn (GitHubGitDataClient $c): mixed => $c->createBlob('{}')],
            'createTree'       => ['createTree', static fn (GitHubGitDataClient $c): mixed => $c->createTree('base1', [])],
            'createCommit'     => [
                'createCommit',
                static fn (GitHubGitDataClient $c): mixed => $c->createCommit(
                    'msg',
                    'tree1',
                    'parent1',
                    ['name' => 'Alice', 'email' => 'alice@example.test'],
                    ['name' => 'Publisher Bot', 'email' => 'publisher@example.com']
                ),
            ],
            'getCommitTreeSha' => [
                'getCommitTreeSha',
                static fn (GitHubGitDataClient $c): mixed => $c->getCommitTreeSha('commit1'),
            ],
            'updateRef'        => ['updateRef', static fn (GitHubGitDataClient $c): mixed => $c->updateRef('litcal-data/x', 'abc123')],
        ];
    }

    public function testGetPullRequestReadsStateMergedAndShas(): void
    {
        $client = $this->client([
            new GuzzleResponse(200, [], json_encode([
                'number'           => 42,
                'state'            => 'closed',
                'merged'           => true,
                'merge_commit_sha' => 'merge-sha',
                'head'             => ['sha' => 'head-sha'],
            ], JSON_THROW_ON_ERROR)),
        ]);

        $pr = $client->getPullRequest(42);

        self::assertSame('closed', $pr->state);
        self::assertTrue($pr->merged);
        self::assertSame('merge-sha', $pr->mergeCommitSha);
        self::assertSame('head-sha', $pr->headSha);
    }

    public function testGetPullRequestTreatsA404AsAFailureNotAValue(): void
    {
        $client = $this->client([
            new GuzzleResponse(404, [], json_encode(['message' => 'Not Found'], JSON_THROW_ON_ERROR)),
        ]);

        $this->expectException(GitHubApiException::class);
        $client->getPullRequest(42);
    }

    /**
     * A missing `merged` must throw rather than default to `false` — a silent default on a
     * CLOSED pull request would write `closed` + `rejected` for a batch that was actually
     * merged, drop it from the accumulation base, skip the OpenFGA purge, and tell the
     * submitter their merged work was rejected. Mirrors
     * {@see testGetPullRequestTreatsA404AsAFailureNotAValue} and
     * {@see testCompareCommitsThrowsWhenGithubReturnsNoStatus}, which refuse to guess for
     * `state` and `status` the same way.
     */
    public function testGetPullRequestThrowsWhenGithubReturnsNoMergedFlag(): void
    {
        $client = $this->client([
            new GuzzleResponse(200, [], json_encode([
                'number'           => 42,
                'state'            => 'closed',
                'merge_commit_sha' => 'merge-sha',
                'head'             => ['sha' => 'head-sha'],
            ], JSON_THROW_ON_ERROR)),
        ]);

        $this->expectException(GitHubApiException::class);
        $client->getPullRequest(42);
    }

    /**
     * An open pull request has no merge commit. Null, not the empty string, so a caller cannot
     * accidentally record '' as a merge_commit_sha.
     */
    public function testGetPullRequestReportsNoMergeCommitWhileOpen(): void
    {
        $client = $this->client([
            new GuzzleResponse(200, [], json_encode([
                'number'           => 42,
                'state'            => 'open',
                'merged'           => false,
                'merge_commit_sha' => null,
                'head'             => ['sha' => 'head-sha'],
            ], JSON_THROW_ON_ERROR)),
        ]);

        $pr = $client->getPullRequest(42);

        self::assertFalse($pr->merged);
        self::assertNull($pr->mergeCommitSha);
    }

    public function testCompareCommitsReturnsGithubStatus(): void
    {
        $client = $this->client([
            new GuzzleResponse(200, [], json_encode(['status' => 'behind'], JSON_THROW_ON_ERROR)),
        ]);

        self::assertSame('behind', $client->compareCommits('aaa', 'bbb'));
    }

    public function testCompareCommitsThrowsWhenGithubReturnsNoStatus(): void
    {
        $client = $this->client([
            new GuzzleResponse(200, [], json_encode(['files' => []], JSON_THROW_ON_ERROR)),
        ]);

        $this->expectException(GitHubApiException::class);
        $client->compareCommits('aaa', 'bbb');
    }

    public function testEveryRequestCarriesTheRequiredAuthAndVersionHeaders(): void
    {
        $captured = [];
        $client   = $this->clientCapturing($captured, [
            new GuzzleResponse(200, [], json_encode(['object' => ['sha' => 'headsha1']])),
        ]);

        $client->getRef('development');

        $request = $captured[0];
        self::assertSame('Bearer ghs_test_token', $request->getHeaderLine('Authorization'));
        self::assertSame('application/vnd.github+json', $request->getHeaderLine('Accept'));
        self::assertSame('2022-11-28', $request->getHeaderLine('X-GitHub-Api-Version'));
    }
}
