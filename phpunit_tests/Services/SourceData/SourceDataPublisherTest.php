<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Services\SourceData;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use InvalidArgumentException;
use LiturgicalCalendar\Api\Enum\ChangeOperation;
use LiturgicalCalendar\Api\Repositories\SourceDataChangeRequestRepository;
use LiturgicalCalendar\Api\Services\GitHub\GitHubAppAuth;
use LiturgicalCalendar\Api\Services\GitHub\GitHubGitDataClient;
use LiturgicalCalendar\Api\Services\SourceData\PublishablePayload;
use LiturgicalCalendar\Api\Services\SourceData\PublishResult;
use LiturgicalCalendar\Api\Services\SourceData\SourceDataPublisher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * Exercises `SourceDataPublisher` against a real `GitHubGitDataClient` wired to a mocked HTTP
 * transport (same mock-first approach as {@see \LiturgicalCalendar\Tests\Services\GitHub\GitHubGitDataClientTest})
 * plus a stubbed `SourceDataChangeRequestRepository`. No network, no credentials, no database.
 *
 * The author/committer split and the null-sha deletion are asserted on the encoded HTTP request
 * bodies actually sent to GitHub, not on `SourceDataPublisher`'s return value — a bug that built
 * the right `PublishResult` while sending the wrong wire body would otherwise slip through.
 */
#[CoversClass(SourceDataPublisher::class)]
#[CoversClass(PublishablePayload::class)]
#[CoversClass(PublishResult::class)]
final class SourceDataPublisherTest extends TestCase
{
    private const OWNER = 'Liturgical-Calendar';

    private const REPO = 'LiturgicalCalendarAPI';

    private const BASE_BRANCH = 'development';

    private const BATCH_ID = 'batch-123';

    private const COMMITTER_NAME = 'Litcal Publisher';

    private const COMMITTER_EMAIL = 'publisher-app@example.test';

    private const BRANCH_HEAD_SHA = 'branch-head-sha';

    private const BASE_HEAD_SHA = 'base-head-sha';

    private const BASE_TREE_SHA = 'base-tree-sha';

    private const NEW_TREE_SHA = 'new-tree-sha';

    private const NEW_COMMIT_SHA = 'new-commit-sha';

    private const NEW_PR_NUMBER = 99;

    /** Matches GitHubAppAuth::cacheKey() for installation id '67890'. */
    private const AUTH_CACHE_KEY = 'github_app_installation_token_67890';

    /** @var list<RequestInterface> */
    private array $captured = [];

    private bool $createRefWasCalled = false;

    private bool $openPullRequestWasCalled = false;

    /** @var array{0: string, 1: string, 2: string, 3: int|null, 4: string}|null */
    private ?array $recordedPublication = null;

    // -- Fixtures -----------------------------------------------------------------------------

    /**
     * @return list<array<string, mixed>>
     */
    private function approvedBatch(string $sub, ?string $name, ?string $email, bool $verified): array
    {
        return [
            $this->row($sub, $name, $email, $verified, [
                'path'      => 'jsondata/sourcedata/rite/roman/calendars/nations/US/US.json',
                'operation' => ChangeOperation::CREATE->value,
                'content'   => '{"litcal":[]}',
            ]),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function approvedDeletionBatch(string $sub): array
    {
        return [
            $this->row($sub, 'Alice', 'alice@example.test', true, [
                'path'      => 'jsondata/sourcedata/rite/roman/calendars/nations/US/US.json',
                'operation' => ChangeOperation::DELETE->value,
                'content'   => null,
            ]),
        ];
    }

    /**
     * @param array{path: string, operation: string, content: ?string} $file
     * @return array<string, mixed>
     */
    private function row(string $sub, ?string $name, ?string $email, bool $verified, array $file): array
    {
        return array_merge(
            [
                'id'                          => bin2hex(random_bytes(8)),
                'batch_id'                    => self::BATCH_ID,
                'resource_type'               => 'national_calendar',
                'resource_id'                 => 'roman/US',
                'submitted_by_sub'            => $sub,
                'submitted_by_name'           => $name,
                'submitted_by_email'          => $email,
                'submitted_by_email_verified' => $verified,
                'review_status'               => 'approved',
                'publication_status'          => 'none',
                'metadata'                    => [],
                'permissions'                 => [],
            ],
            $file
        );
    }

    // -- Wiring ---------------------------------------------------------------------------------

    private function auth(): GitHubAppAuth
    {
        $cache = new ArrayAdapter();
        $item  = $cache->getItem(self::AUTH_CACHE_KEY);
        $item->set('ghs_test_token');
        $cache->save($item);

        // Empty queue: a fall-through to a real token exchange throws loudly instead of
        // quietly consuming a response meant for the Git Data call under test.
        $noHttp = new GuzzleClient(['handler' => HandlerStack::create(new MockHandler([]))]);

        return new GitHubAppAuth('12345', '67890', '/nonexistent/should-not-be-read.pem', $noHttp, $cache);
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function publisherFor(array $rows, bool $branchExists = true, ?int $openPr = null): SourceDataPublisher
    {
        $this->captured                 = [];
        $this->createRefWasCalled       = false;
        $this->openPullRequestWasCalled = false;
        $this->recordedPublication      = null;

        $handlerStack = HandlerStack::create(new MockHandler($this->responseQueueFor($rows, $branchExists, $openPr)));
        $handlerStack->push(function (callable $handler): callable {
            return function (RequestInterface $request, array $options) use ($handler) {
                $this->captured[] = $request;

                $path = $request->getUri()->getPath();
                if ('POST' === $request->getMethod() && str_ends_with($path, '/git/refs')) {
                    $this->createRefWasCalled = true;
                }
                if ('POST' === $request->getMethod() && str_ends_with($path, '/pulls')) {
                    $this->openPullRequestWasCalled = true;
                }

                return $handler($request, $options);
            };
        });
        $guzzle = new GuzzleClient(['handler' => $handlerStack]);
        $client = new GitHubGitDataClient(self::OWNER, self::REPO, $this->auth(), $guzzle);

        $repository = $this->createMock(SourceDataChangeRequestRepository::class);
        $repository->method('getBatch')->with(self::BATCH_ID)->willReturn($rows);
        $repository->method('recordPublication')->willReturnCallback(
            function (string $batchId, string $branch, string $commitSha, ?int $prNumber, string $baseSha) use ($rows): int {
                $this->recordedPublication = [$batchId, $branch, $commitSha, $prNumber, $baseSha];

                return count($rows);
            }
        );

        return new SourceDataPublisher($repository, $client, self::BASE_BRANCH, self::COMMITTER_NAME, self::COMMITTER_EMAIL);
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<GuzzleResponse>
     */
    private function responseQueueFor(array $rows, bool $branchExists, ?int $openPr): array
    {
        $responses = [];

        if ($branchExists) {
            $responses[] = new GuzzleResponse(200, [], json_encode(['object' => ['sha' => self::BRANCH_HEAD_SHA]]));
        } else {
            $responses[] = new GuzzleResponse(404, [], json_encode(['message' => 'Not Found']));
            $responses[] = new GuzzleResponse(200, [], json_encode(['object' => ['sha' => self::BASE_HEAD_SHA]]));
            $responses[] = new GuzzleResponse(201, [], '{}');
        }

        // getCommitTreeSha() on the branch head (whether pre-existing or just created).
        $responses[] = new GuzzleResponse(
            200,
            [],
            json_encode(['sha' => 'unused-commit-sha', 'tree' => ['sha' => self::BASE_TREE_SHA]])
        );

        $nonDeleteCount = count(array_filter(
            $rows,
            static fn (array $row): bool => ChangeOperation::DELETE->value !== $row['operation']
        ));
        for ($i = 0; $i < $nonDeleteCount; $i++) {
            $responses[] = new GuzzleResponse(201, [], json_encode(['sha' => 'blob-sha-' . $i]));
        }

        $responses[] = new GuzzleResponse(201, [], json_encode(['sha' => self::NEW_TREE_SHA]));
        $responses[] = new GuzzleResponse(201, [], json_encode(['sha' => self::NEW_COMMIT_SHA]));
        $responses[] = new GuzzleResponse(200, [], '{}'); // updateRef

        if (null !== $openPr) {
            $responses[] = new GuzzleResponse(200, [], json_encode([['number' => $openPr]]));
        } else {
            $responses[] = new GuzzleResponse(200, [], '[]');
            $responses[] = new GuzzleResponse(201, [], json_encode(['number' => self::NEW_PR_NUMBER]));
        }

        return $responses;
    }

    /**
     * @return array<string, mixed>
     */
    private function capturedCommitPayload(): array
    {
        foreach ($this->captured as $request) {
            if ('POST' === $request->getMethod() && str_ends_with($request->getUri()->getPath(), '/git/commits')) {
                /** @var array<string, mixed> $decoded */
                $decoded = json_decode((string) $request->getBody(), true);

                return $decoded;
            }
        }

        self::fail('createCommit() was never called');
    }

    /**
     * @return array<string, mixed>
     */
    private function capturedTreePayload(): array
    {
        foreach ($this->captured as $request) {
            if ('POST' === $request->getMethod() && str_ends_with($request->getUri()->getPath(), '/git/trees')) {
                /** @var array<string, mixed> $decoded */
                $decoded = json_decode((string) $request->getBody(), true);

                return $decoded;
            }
        }

        self::fail('createTree() was never called');
    }

    // -- Tests ------------------------------------------------------------------------------------

    public function testItCommitsWithTheEditorAsAuthorAndTheAppAsCommitter(): void
    {
        $publisher = $this->publisherFor($this->approvedBatch('editor-1', 'Alice', 'alice@example.test', true));

        $result = $publisher->publish(self::BATCH_ID);

        $commit = $this->capturedCommitPayload();
        self::assertSame('Alice', $commit['author']['name']);
        self::assertSame('alice@example.test', $commit['author']['email']);
        self::assertNotSame('alice@example.test', $commit['committer']['email'], 'the App is the committer');
        self::assertSame(self::COMMITTER_EMAIL, $commit['committer']['email']);
        self::assertSame('litcal-data/national_calendar/roman/US', $result->branch);
        self::assertSame(self::NEW_COMMIT_SHA, $result->commitSha);
        self::assertSame(self::NEW_PR_NUMBER, $result->prNumber);
        self::assertSame(self::BRANCH_HEAD_SHA, $result->baseSha);

        self::assertNotNull($this->recordedPublication, 'the batch must be recorded as published');
        self::assertSame(
            [self::BATCH_ID, 'litcal-data/national_calendar/roman/US', self::NEW_COMMIT_SHA, self::NEW_PR_NUMBER, self::BRANCH_HEAD_SHA],
            $this->recordedPublication
        );
    }

    public function testAnUnverifiedEmailIsNeverUsedAsTheCommitAuthorEmail(): void
    {
        $publisher = $this->publisherFor($this->approvedBatch('editor-1', 'Alice', 'alice@example.test', false));

        $publisher->publish(self::BATCH_ID);

        $commit = $this->capturedCommitPayload();
        self::assertStringContainsString('noreply', $commit['author']['email']);
    }

    public function testAMissingBranchIsCreatedFromDevelopment(): void
    {
        // getRef returns null for the feature branch, then the base branch's head is used to create it.
        $publisher = $this->publisherFor(
            $this->approvedBatch('editor-1', 'Alice', 'alice@example.test', true),
            branchExists: false
        );

        $result = $publisher->publish(self::BATCH_ID);

        self::assertTrue($this->createRefWasCalled);
        self::assertSame(self::BASE_HEAD_SHA, $result->baseSha);
    }

    public function testADeleteOperationBecomesATreeEntryWithANullSha(): void
    {
        $publisher = $this->publisherFor($this->approvedDeletionBatch('editor-1'));

        $publisher->publish(self::BATCH_ID);

        $tree = $this->capturedTreePayload();
        self::assertNull($tree['tree'][0]['sha']);
        self::assertArrayHasKey('sha', $tree['tree'][0], 'the key must be present and null, not omitted');
    }

    public function testARollingPullRequestIsNotOpenedTwice(): void
    {
        $publisher = $this->publisherFor(
            $this->approvedBatch('editor-1', 'Alice', 'alice@example.test', true),
            openPr: 42
        );

        $result = $publisher->publish(self::BATCH_ID);

        self::assertSame(42, $result->prNumber, 'an existing open PR is reused, not duplicated');
        self::assertFalse($this->openPullRequestWasCalled);
    }

    public function testPublishRejectsAnEmptyBatch(): void
    {
        $publisher = $this->publisherFor([]);

        $this->expectException(InvalidArgumentException::class);
        $publisher->publish(self::BATCH_ID);
    }

    public function testSplitGithubRepositorySeparatesOwnerAndRepo(): void
    {
        $split = SourceDataPublisher::splitGithubRepository('Liturgical-Calendar/LiturgicalCalendarAPI');

        self::assertSame('Liturgical-Calendar', $split['owner']);
        self::assertSame('LiturgicalCalendarAPI', $split['repo']);
    }

    public function testSplitGithubRepositoryRejectsAValueWithNoSlash(): void
    {
        $this->expectException(InvalidArgumentException::class);
        SourceDataPublisher::splitGithubRepository('not-a-repository-reference');
    }

    public function testSplitGithubRepositoryRejectsAValueWithTooManySlashes(): void
    {
        $this->expectException(InvalidArgumentException::class);
        SourceDataPublisher::splitGithubRepository('too/many/slashes');
    }
}
