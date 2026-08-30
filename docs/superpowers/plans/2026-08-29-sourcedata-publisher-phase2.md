# Source-Data Publisher (Phase 2) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or
> superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Turn an approved change request into a commit on a per-resource branch and a rolling pull request, so that
edits made through the API reach the GitHub repository instead of being destroyed by the next deploy.

**Architecture:** A cron-driven publisher claims one approved batch at a time from `sourcedata_change_requests`,
authenticates as a GitHub App, and writes the batch through the Git Data API (blob → tree → commit → ref → PR) with the
authenticated editor as commit author and the App as committer. The change-request table is itself the queue: its
`publication_status` column already models `none → queued → open`, and `branch`, `commit_sha`, `pr_number` and
`base_sha` are already there to be filled in.

**Tech Stack:** PHP 8.4, `firebase/php-jwt` (RS256 App JWT), `guzzlehttp/guzzle` (GitHub REST), `symfony/cache`
`FilesystemAdapter` (installation-token cache), Postgres via PDO, PHPUnit.

**Spec:** `docs/superpowers/specs/2026-08-28-sourcedata-change-requests-design.md`, section "Phase 2: the publisher".

## Corrections to the spec, established before planning

The spec was written before phase 1 existed. Two of its reuse claims do not survive contact with the merged code. Both
are recorded here so no task is written against them:

1. **"Outbox row (reuses ConsumerLoop / BackstopRunner / OutboxBackoff unchanged)" is only one-third true.**
   `BackstopRunner::__construct()` types its first argument as the concrete `OutboxRepository`
   (`src/Services/Outbox/BackstopRunner.php:26`), and `ConsumerLoop` needs a Redis stream plus an integer row id
   (`src/Services/Outbox/ConsumerLoop.php:26-31`), while the unit of publication is a **batch**, not a row. The existing
   outbox is also specifically OpenFGA-shaped: table `openfga_outbox`, and `OutboxOperation` has exactly two cases,
   `write_tuple` and `delete_tuple`. Only `OutboxBackoff::secondsForAttempt(int $attempts): int` is genuinely reusable —
   it is a pure static function. The publisher therefore gets its own small runner that **mirrors**
   `BackstopRunner`'s locking discipline (`FOR UPDATE SKIP LOCKED` inside an explicit transaction, per the comment at
   `BackstopRunner.php:36-42`) rather than inheriting from it.

2. **No new queue table is needed.** `Version20260828120000` already ships `publication_status` with the five-state
   CHECK constraint, plus `base_sha`, `branch`, `commit_sha`, `pr_number` and `merge_commit_sha`. Approved batches with
   `publication_status = 'none'` are the work list.

## Global Constraints

- PHP >= 8.4. PSR-12 via `phpcs.xml`: 4-space indent, short array syntax `[]`, single quotes unless interpolating.
- PHPStan level 10 (`composer analyse`) must pass. Never a blind cast of `mixed`.
- `#[CoversClass]` on every test. A test that exercises a class must name it, or PHPUnit discards that coverage.
- Postgres and OpenFGA are configured and reachable in dev and CI. A skipping repository test proves nothing and is a
  defect, not an environment quirk.
- Never use `--no-verify`. Run `composer parallel-lint && composer lint:fix && composer analyse` before every commit,
  `composer lint:md` when Markdown changes.
- Feature branches target `development`. Never `main`.
- `jsondata/schemas/openapi.json` is canonical literal UTF-8 with **zero** `\uXXXX` escapes. Edit it as text; never
  round-trip it through `json_decode`/`json_encode`. `composer lint:jsondata` — not `lint:openapi` — is what catches
  encoding drift, and it must stay green.
- Phase 2 never writes `publication_status = 'closed'`. That state and its effect on the accumulation base belong to
  phase 3.
- Phase 2 does **not** purge OpenFGA tuples for deleted calendars or tests. That is phase 3, at merge detection.
  Task 9 requires the runbook to state the resulting gap explicitly.

## File Structure

| File                                                     | Responsibility                                              |
| -------------------------------------------------------- | ----------------------------------------------------------- |
| `src/Services/GitHub/GitHubAppAuth.php`                  | App JWT (RS256) → installation token, cached ~50 min        |
| `src/Services/GitHub/GitHubGitDataClient.php`            | The Git Data + Pulls REST calls, one method each            |
| `src/Services/GitHub/GitHubApiException.php`             | Typed failure carrying status and GitHub's message          |
| `src/Services/SourceData/PublishablePayload.php`         | One batch flattened into paths, contents and operations     |
| `src/Services/SourceData/SourceDataPublisher.php`        | Orchestrates one batch: branch → blobs → tree → commit → PR |
| `src/Services/SourceData/PublishRunner.php`              | Claim, publish, settle; backoff on failure                  |
| `scripts/publish-sourcedata.php`                         | Cron entry point calling `PublishRunner::runOnce()`         |
| `src/Repositories/SourceDataChangeRequestRepository.php` | Age-based ancestor exclusion; claim and settle methods      |
| `docs/ops/change-request-runbook.md`                     | Operator documentation, including the phase-3 purge gap     |

---

## Task 1: Age-based ancestor exclusion

This lands first because it is a latent bug that activates the instant anything reaches `merged` — which Task 6 makes
possible. Shipping the publisher without it would reintroduce the exact silent data loss phase 1 spent four rounds
closing.

**Files:**

- Modify: `src/Repositories/SourceDataChangeRequestRepository.php` (the `UNPUBLISHED_PREDICATE` constant and both
  `findUnpublished*` methods)
- Test: `phpunit_tests/Repositories/SourceDataChangeRequestRepositoryTest.php`

**Interfaces:**

- Consumes: nothing new.
- Produces: no signature changes. `findUnpublishedContent(string $path, string $sub): ?string` and
  `findUnpublishedPathsUnder(string $pathPrefix, string $sub): array` keep their signatures; only which rows they
  consider changes.

**The rule:** for a given `(path, submitted_by_sub)`, ignore any row older than the newest row whose
`publication_status = 'merged'`. Do not rewrite any row's status.

- [ ] **Step 1: Write the failing test**

```php
public function testAnAncestorOlderThanAMergedRowIsNotUsedAsTheBase(): void
{
    $path = 'jsondata/sourcedata/rite/roman/decrees/decrees.json';

    // Batch A: approved, never published. Batch B accumulated onto it and was published.
    $batchA = $this->repo->submitBatch(
        ChangeResource::decrees(),
        [['path' => $path, 'operation' => ChangeOperation::UPDATE, 'content' => '["A"]']],
        'editor-1',
        'Alice',
        'alice@example.test',
        true
    )['batch_id'];
    $this->repo->approveBatch($batchA, 'admin-1');

    $batchB = $this->repo->submitBatch(
        ChangeResource::decrees(),
        [['path' => $path, 'operation' => ChangeOperation::UPDATE, 'content' => '["A","B"]']],
        'editor-1',
        'Alice',
        'alice@example.test',
        true
    )['batch_id'];
    $this->repo->approveBatch($batchB, 'admin-1');
    $this->repo->markBatchPublicationStatus($batchB, ChangePublicationStatus::MERGED);

    // A is older than the newest merged row for this path, so it must not become the base again.
    self::assertNull(
        $this->repo->findUnpublishedContent($path, 'editor-1'),
        'a merged batch must not fall back to the ancestor it superseded'
    );
}
```

- [ ] **Step 2: Run it and watch it fail**

```bash
vendor/bin/phpunit --filter testAnAncestorOlderThanAMergedRowIsNotUsedAsTheBase \
  phpunit_tests/Repositories/SourceDataChangeRequestRepositoryTest.php
```

Expected: FAIL — it returns `'["A"]'`, batch A's content, because A is `approved`/`none` and so still matches the
predicate. That failure IS the bug this task exists to fix; capture the output in your report.

- [ ] **Step 3: Add the age floor to both queries**

Keep `UNPUBLISHED_PREDICATE` as it is, and add the floor as a separate clause so the two ideas stay legible:

```php
    /**
     * Rows that are not yet in the repository: still in review, or approved and awaiting publication.
     *
     * Deliberately wider than the supersede DELETE's `review_status = 'submitted'`: an approved batch has
     * not reached disk in phase 1, and phase 2 publishes it later, so its content is still the submitter's
     * work in flight.
     */
    private const UNPUBLISHED_PREDICATE = 'review_status IN (:submitted, :approved)
                AND publication_status <> :merged';

    /**
     * Rows superseded by published content are excluded by AGE, not by rewriting their status.
     *
     * Accumulation makes each batch the submitter's cumulative proposal, so publishing batch B also
     * publishes the content of the older batch A that B accumulated onto. A is then stale. Marking A
     * `merged` would say it was published, which is false: the publisher selects approved rows that are
     * not yet merged, so a broken containment assumption would make it skip A and lose its content
     * silently. Excluding by age asserts nothing — A stays approved, visible and publishable — and the
     * worst case degrades from lost data to a suboptimal rebuild base.
     */
    private const NOT_SUPERSEDED_BY_PUBLISHED = 'created_at > COALESCE((
                    SELECT MAX(m.created_at)
                      FROM sourcedata_change_requests m
                     WHERE m.path = sourcedata_change_requests.path
                       AND m.submitted_by_sub = sourcedata_change_requests.submitted_by_sub
                       AND m.publication_status = :merged_floor
                ), TIMESTAMPTZ \'-infinity\')';
```

Add `AND ' . self::NOT_SUPERSEDED_BY_PUBLISHED` to both `findUnpublishedContent()` and
`findUnpublishedPathsUnder()`, and bind `:merged_floor` to `ChangePublicationStatus::MERGED->value` alongside the
existing `unpublishedParams()`. Bind it as a distinct placeholder rather than reusing `:merged`, so the two clauses
stay independently readable.

- [ ] **Step 4: Add the sibling test that pins what must NOT change**

```php
public function testAnAncestorWithNoMergedDescendantIsStillTheBase(): void
{
    $path = 'jsondata/sourcedata/rite/roman/decrees/decrees.json';

    $batchA = $this->repo->submitBatch(
        ChangeResource::decrees(),
        [['path' => $path, 'operation' => ChangeOperation::UPDATE, 'content' => '["A"]']],
        'editor-1',
        'Alice',
        'alice@example.test',
        true
    )['batch_id'];
    $this->repo->approveBatch($batchA, 'admin-1');

    // Nothing is merged, so phase 1's behaviour must be untouched.
    self::assertSame('["A"]', $this->repo->findUnpublishedContent($path, 'editor-1'));
}
```

- [ ] **Step 5: Run both and the whole repository suite**

```bash
vendor/bin/phpunit phpunit_tests/Repositories/SourceDataChangeRequestRepositoryTest.php
vendor/bin/phpunit phpunit_tests/Handlers/SourceDataChangeRequestSupersedeRegressionTest.php
```

Expected: PASS. The supersede regression suite must stay green unchanged — it encodes phase 1's guarantees, and this
change must not alter any of them while nothing is merged.

- [ ] **Step 6: Commit**

```bash
composer parallel-lint && composer lint:fix && composer analyse
git add src/Repositories/SourceDataChangeRequestRepository.php phpunit_tests/Repositories/SourceDataChangeRequestRepositoryTest.php
git commit -m "fix(change-requests): exclude ancestors superseded by published content"
```

---

## Task 2: Claim and settle a batch

**Files:**

- Modify: `src/Repositories/SourceDataChangeRequestRepository.php`
- Test: `phpunit_tests/Repositories/SourceDataChangeRequestPublishQueueTest.php` (create)

**Interfaces:**

- Produces, all on `SourceDataChangeRequestRepository`:
  - `claimNextPublishableBatch(): ?string` — batch id, or null. Sets that batch's rows to `queued` inside the same
    transaction that selected them, using `FOR UPDATE SKIP LOCKED` so two runners never claim the same batch.
  - `markBatchPublicationStatus(string $batchId, ChangePublicationStatus $status): int`
  - `recordPublication(string $batchId, string $branch, string $commitSha, ?int $prNumber, string $baseSha): int`
  - `releaseClaim(string $batchId): int` — back to `none`, for a failed attempt.

Only batches whose rows are **all** `review_status = 'approved'` and `publication_status = 'none'` are claimable. A
batch is never mixed-status (`decideBatch()` transitions every still-submitted row in one `UPDATE`), so `MIN`/`MAX`
over the batch is a safe test.

- [ ] **Step 1: Write the failing tests**

```php
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
}
```

- [ ] **Step 2: Run and watch them fail**

```bash
vendor/bin/phpunit phpunit_tests/Repositories/SourceDataChangeRequestPublishQueueTest.php
```

Expected: FAIL with "Call to undefined method ... claimNextPublishableBatch()".

- [ ] **Step 3: Implement the claim**

```php
    public function claimNextPublishableBatch(): ?string
    {
        // FOR UPDATE SKIP LOCKED only holds its locks for the surrounding transaction, so the
        // select and the status update must share one — otherwise autocommit releases the lock
        // immediately and two runners can claim the same batch. Same reasoning as
        // BackstopRunner::runOnce(), which this mirrors rather than reuses.
        $this->db->beginTransaction();
        try {
            $select = $this->db->prepare(
                'SELECT batch_id
                   FROM sourcedata_change_requests
                  GROUP BY batch_id
                 HAVING bool_and(review_status = :approved)
                    AND bool_and(publication_status = :none)
                  ORDER BY MIN(created_at) ASC
                  LIMIT 1
                    FOR UPDATE SKIP LOCKED'
            );
            $select->execute([
                'approved' => ChangeReviewStatus::APPROVED->value,
                'none'     => ChangePublicationStatus::NONE->value,
            ]);

            $batchId = $select->fetchColumn();
            if (!is_string($batchId)) {
                $this->db->commit();

                return null;
            }

            $claim = $this->db->prepare(
                'UPDATE sourcedata_change_requests
                    SET publication_status = :queued, updated_at = NOW()
                  WHERE batch_id = :batch_id'
            );
            $claim->execute([
                'queued'   => ChangePublicationStatus::QUEUED->value,
                'batch_id' => $batchId,
            ]);

            $this->db->commit();

            return $batchId;
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }
```

`ORDER BY MIN(created_at) ASC` publishes oldest-approved-first, which keeps a busy resource from starving an old batch.

- [ ] **Step 4: Implement the settle methods**

```php
    public function markBatchPublicationStatus(string $batchId, ChangePublicationStatus $status): int
    {
        $stmt = $this->db->prepare(
            'UPDATE sourcedata_change_requests
                SET publication_status = :status, updated_at = NOW()
              WHERE batch_id = :batch_id'
        );
        $stmt->execute(['status' => $status->value, 'batch_id' => $batchId]);

        return $stmt->rowCount();
    }

    public function recordPublication(
        string $batchId,
        string $branch,
        string $commitSha,
        ?int $prNumber,
        string $baseSha
    ): int {
        $stmt = $this->db->prepare(
            'UPDATE sourcedata_change_requests
                SET publication_status = :open,
                    branch             = :branch,
                    commit_sha         = :commit_sha,
                    pr_number          = :pr_number,
                    base_sha           = :base_sha,
                    updated_at         = NOW()
              WHERE batch_id = :batch_id'
        );
        $stmt->execute([
            'open'       => ChangePublicationStatus::OPEN->value,
            'branch'     => $branch,
            'commit_sha' => $commitSha,
            'pr_number'  => $prNumber,
            'base_sha'   => $baseSha,
            'batch_id'   => $batchId,
        ]);

        return $stmt->rowCount();
    }

    public function releaseClaim(string $batchId): int
    {
        return $this->markBatchPublicationStatus($batchId, ChangePublicationStatus::NONE);
    }
```

- [ ] **Step 5: Run the tests, then commit**

```bash
vendor/bin/phpunit phpunit_tests/Repositories/SourceDataChangeRequestPublishQueueTest.php
composer parallel-lint && composer lint:fix && composer analyse
git add -A && git commit -m "feat(change-requests): claim and settle batches for publication"
```

---

## Task 3: GitHub App authentication

**Files:**

- Create: `src/Services/GitHub/GitHubAppAuth.php`, `src/Services/GitHub/GitHubApiException.php`
- Test: `phpunit_tests/Services/GitHub/GitHubAppAuthTest.php`

**Interfaces:**

- Produces:
  - `GitHubAppAuth::__construct(string $appId, string $installationId, string $privateKeyPath, ClientInterface $http, CacheItemPoolInterface $cache)`
  - `GitHubAppAuth::installationToken(): string` — cached, refreshed at ~50 minutes of the one-hour life
  - `GitHubAppAuth::isConfigured(): bool` — static, mirroring `OpenFgaClient::isConfigured()`
  - `GitHubApiException extends \RuntimeException` with `public readonly int $status`

The App JWT is RS256-signed with `firebase/php-jwt`, `iss` = app id, `iat` backdated 60 seconds for clock skew, `exp`
at most 10 minutes out (GitHub rejects longer). It is exchanged at
`POST /app/installations/{installation_id}/access_tokens` for an installation token.

- [ ] **Step 1: Write the failing test**

```php
#[CoversClass(GitHubAppAuth::class)]
final class GitHubAppAuthTest extends TestCase
{
    private function auth(array $responses, CacheItemPoolInterface $cache): GitHubAppAuth
    {
        $guzzle = new GuzzleClient(['handler' => HandlerStack::create(new MockHandler($responses))]);

        return new GitHubAppAuth('12345', '67890', self::keyPath(), $guzzle, $cache);
    }

    public function testItExchangesTheAppJwtForAnInstallationToken(): void
    {
        $auth = $this->auth(
            [new GuzzleResponse(201, [], json_encode(['token' => 'ghs_abc', 'expires_at' => '2026-01-01T00:00:00Z']))],
            new ArrayAdapter()
        );

        self::assertSame('ghs_abc', $auth->installationToken());
    }

    public function testTheTokenIsCachedRatherThanFetchedEveryCall(): void
    {
        // One response only: a second HTTP call would throw "queue is empty".
        $auth = $this->auth(
            [new GuzzleResponse(201, [], json_encode(['token' => 'ghs_abc', 'expires_at' => '2026-01-01T00:00:00Z']))],
            new ArrayAdapter()
        );

        self::assertSame('ghs_abc', $auth->installationToken());
        self::assertSame('ghs_abc', $auth->installationToken());
    }

    public function testAFailedExchangeRaisesGitHubApiExceptionCarryingTheStatus(): void
    {
        $auth = $this->auth([new GuzzleResponse(401, [], json_encode(['message' => 'Bad credentials']))], new ArrayAdapter());

        try {
            $auth->installationToken();
            self::fail('a 401 must not be swallowed');
        } catch (GitHubApiException $e) {
            self::assertSame(401, $e->status);
            self::assertStringContainsString('Bad credentials', $e->getMessage());
        }
    }
}
```

`self::keyPath()` writes a throwaway RSA private key to the test's temp directory in `setUpBeforeClass()` and returns
its path; remove it child-first in `tearDownAfterClass()`. Generate it with
`openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA])` so the suite needs no
committed key material.

- [ ] **Step 2: Run and watch it fail**

```bash
vendor/bin/phpunit phpunit_tests/Services/GitHub/GitHubAppAuthTest.php
```

Expected: FAIL — class not found.

- [ ] **Step 3: Implement `GitHubApiException`**

```php
final class GitHubApiException extends \RuntimeException
{
    public function __construct(public readonly int $status, string $message, ?\Throwable $previous = null)
    {
        parent::__construct($message, $status, $previous);
    }
}
```

- [ ] **Step 4: Implement `GitHubAppAuth`**

Key points the implementation must honour:

- Read the private key with `file_get_contents()` on the configured **path**. Never accept the key itself through an
  environment variable — it belongs outside the deployed tree.
- `JWT::encode(['iat' => time() - 60, 'exp' => time() + 540, 'iss' => $appId], $key, 'RS256')`.
- Cache under a key derived from the installation id, with a TTL of **3000 seconds** (50 minutes) against GitHub's
  one-hour life, so a token is never used in its final ten minutes.
- Any non-2xx from the exchange throws `GitHubApiException` carrying the status and GitHub's `message` field.

- [ ] **Step 5: Run tests, then commit**

```bash
vendor/bin/phpunit phpunit_tests/Services/GitHub/GitHubAppAuthTest.php
composer parallel-lint && composer lint:fix && composer analyse
git add -A && git commit -m "feat(github): authenticate as a GitHub App with a cached installation token"
```

---

## Task 4: The Git Data client

**Files:**

- Create: `src/Services/GitHub/GitHubGitDataClient.php`
- Test: `phpunit_tests/Services/GitHub/GitHubGitDataClientTest.php`

**Interfaces:**

- Consumes: `GitHubAppAuth::installationToken()`, `GitHubApiException`.
- Produces, on `GitHubGitDataClient`:

```php
public function getRef(string $branch): ?string;                          // head sha, or null on 404
public function createRef(string $branch, string $fromSha): void;
public function createBlob(string $content): string;                      // blob sha
public function createTree(string $baseTreeSha, array $entries): string;  // tree sha
public function createCommit(string $message, string $treeSha, string $parentSha, array $author): string;
public function updateRef(string $branch, string $commitSha): void;       // force: false
public function openPullRequest(string $branch, string $base, string $title, string $body): int;
public function findOpenPullRequest(string $branch): ?int;
```

A tree entry is `['path' => string, 'mode' => '100644', 'type' => 'blob', 'sha' => string|null]`. **A null `sha` is
how the Git Data API expresses a deletion** — that is the mechanism behind a `delete` change request, and it is easy
to lose to a type declaration that forbids null.

- [ ] **Step 1: Write the failing tests**

```php
public function testGetRefReturnsNullForAMissingBranch(): void
{
    $client = $this->client([new GuzzleResponse(404, [], json_encode(['message' => 'Not Found']))]);

    self::assertNull($client->getRef('litcal-data/roman/nation/US'));
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
}

public function testATreeEntryMayCarryANullShaToExpressADeletion(): void
{
    $captured = [];
    $client   = $this->clientCapturing($captured, [new GuzzleResponse(201, [], json_encode(['sha' => 'tree1']))]);

    $client->createTree('base1', [
        ['path' => 'jsondata/sourcedata/rite/roman/calendars/nations/US/US.json', 'mode' => '100644', 'type' => 'blob', 'sha' => null],
    ]);

    $body = json_decode((string) $captured[0]->getBody(), true);
    self::assertNull($body['tree'][0]['sha'], 'a null sha is how the API deletes a path');
    self::assertArrayHasKey('sha', $body['tree'][0], 'the key must be present and null, not omitted');
}

public function testANon2xxRaisesGitHubApiException(): void
{
    $client = $this->client([new GuzzleResponse(422, [], json_encode(['message' => 'Update is not a fast forward']))]);

    $this->expectException(GitHubApiException::class);
    $client->updateRef('litcal-data/roman/nation/US', 'abc123');
}
```

- [ ] **Step 2: Run and watch them fail, then implement**

Every request carries `Authorization: Bearer <installation token>`, `Accept: application/vnd.github+json` and
`X-GitHub-Api-Version: 2022-11-28`. `getRef()` is the only method that treats 404 as a value rather than an error.
When building the tree payload, use `json_encode` on an array that includes the `sha` key explicitly set to `null` —
do not filter nulls out, or deletions silently become no-ops.

- [ ] **Step 3: Run tests, then commit**

```bash
vendor/bin/phpunit phpunit_tests/Services/GitHub/GitHubGitDataClientTest.php
composer parallel-lint && composer lint:fix && composer analyse
git add -A && git commit -m "feat(github): Git Data and Pulls client"
```

---

## Task 5: Publish one batch

**Files:**

- Create: `src/Services/SourceData/PublishablePayload.php`, `src/Services/SourceData/SourceDataPublisher.php`
- Test: `phpunit_tests/Services/SourceData/SourceDataPublisherTest.php`

**Interfaces:**

- Consumes: `GitHubGitDataClient` (Task 4), `SourceDataChangeRequestRepository::getBatch()` and
  `recordPublication()` (Task 2).
- Produces: `SourceDataPublisher::publish(string $batchId): PublishResult`, where `PublishResult` carries
  `branch`, `commitSha`, `prNumber` and `baseSha`.

**Branch naming:** `litcal-data/<resource_type>/<resource_id>`, e.g. `litcal-data/national_calendar/roman/US`. Stable
per resource, so the rolling PR falls out for free. `resource_id` already contains a `/` for rite-qualified ids, which
is legal in a git ref and intended.

**Author vs committer:** the commit's `author` is the editor (`submitted_by_name`, `submitted_by_email`), the
`committer` is the App. Only use the submitter's email when `submitted_by_email_verified` is true; otherwise use the
GitHub `noreply` form, since an unverified address must never be presented as an authenticated identity.

- [ ] **Step 1: Write the failing tests**

```php
public function testItCommitsWithTheEditorAsAuthorAndTheAppAsCommitter(): void
{
    $publisher = $this->publisherFor($this->approvedBatch('editor-1', 'Alice', 'alice@example.test', true));

    $result = $publisher->publish($this->batchId);

    $commit = $this->capturedCommitPayload();
    self::assertSame('Alice', $commit['author']['name']);
    self::assertSame('alice@example.test', $commit['author']['email']);
    self::assertNotSame('alice@example.test', $commit['committer']['email'], 'the App is the committer');
    self::assertSame('litcal-data/national_calendar/roman/US', $result->branch);
}

public function testAnUnverifiedEmailIsNeverUsedAsTheCommitAuthorEmail(): void
{
    $publisher = $this->publisherFor($this->approvedBatch('editor-1', 'Alice', 'alice@example.test', false));

    $publisher->publish($this->batchId);

    $commit = $this->capturedCommitPayload();
    self::assertStringContainsString('noreply', $commit['author']['email']);
}

public function testAMissingBranchIsCreatedFromDevelopment(): void
{
    // getRef returns null for the feature branch, then the base branch's head is used to create it.
    $publisher = $this->publisherFor($this->approvedBatch('editor-1', 'Alice', 'alice@example.test', true), branchExists: false);

    $publisher->publish($this->batchId);

    self::assertTrue($this->createRefWasCalled);
}

public function testADeleteOperationBecomesATreeEntryWithANullSha(): void
{
    $publisher = $this->publisherFor($this->approvedDeletionBatch('editor-1'));

    $publisher->publish($this->batchId);

    $tree = $this->capturedTreePayload();
    self::assertNull($tree['tree'][0]['sha']);
}

public function testARollingPullRequestIsNotOpenedTwice(): void
{
    $publisher = $this->publisherFor($this->approvedBatch('editor-1', 'Alice', 'alice@example.test', true), openPr: 42);

    $result = $publisher->publish($this->batchId);

    self::assertSame(42, $result->prNumber, 'an existing open PR is reused, not duplicated');
    self::assertFalse($this->openPullRequestWasCalled);
}
```

- [ ] **Step 2: Run, watch them fail, then implement the sequence**

```text
getRef(branch)            null → createRef(branch, getRef(base))
createBlob(content)       once per non-delete row
createTree(parentTree)    one entry per row; delete rows carry sha: null
createCommit(...)         author = editor, committer = App, parent = branch head
updateRef(branch, sha)    force: false
findOpenPullRequest()     → openPullRequest() only when null
```

One commit per batch, never batched across batches: batching would merge two editors into one commit and destroy the
per-editor authorship this whole design exists to preserve.

- [ ] **Step 3: Run tests, then commit**

```bash
vendor/bin/phpunit phpunit_tests/Services/SourceData/SourceDataPublisherTest.php
composer parallel-lint && composer lint:fix && composer analyse
git add -A && git commit -m "feat(sourcedata): publish an approved batch as a commit and rolling PR"
```

---

## Task 6: The runner

**Files:**

- Create: `src/Services/SourceData/PublishRunner.php`, `scripts/publish-sourcedata.php`
- Test: `phpunit_tests/Services/SourceData/PublishRunnerTest.php`

**Interfaces:**

- Consumes: `claimNextPublishableBatch()`, `recordPublication()`, `releaseClaim()` (Task 2);
  `SourceDataPublisher::publish()` (Task 5); `OutboxBackoff::secondsForAttempt(int): int`.
- Produces: `PublishRunner::runOnce(int $limit = 10): int` — the number of batches published.

- [ ] **Step 1: Write the failing tests**

```php
public function testASuccessfulPublishRecordsTheBranchCommitAndPr(): void
{
    $batchId = $this->approveOne('editor-1');

    self::assertSame(1, $this->runner()->runOnce());

    foreach ($this->repo->getBatch($batchId) as $row) {
        self::assertSame(ChangePublicationStatus::OPEN->value, $row['publication_status']);
        self::assertNotNull($row['commit_sha']);
        self::assertSame(7, $row['pr_number']);
    }
}

public function testAFailedPublishReleasesTheClaimSoItIsRetried(): void
{
    $batchId = $this->approveOne('editor-1');

    $runner = $this->runnerThatThrows(new GitHubApiException(422, 'Update is not a fast forward'));
    self::assertSame(0, $runner->runOnce());

    // Back to `none`, not stranded in `queued`: a batch nobody will ever pick up again is worse
    // than one that retries, because it is invisible to the operator and to the editor alike.
    foreach ($this->repo->getBatch($batchId) as $row) {
        self::assertSame(ChangePublicationStatus::NONE->value, $row['publication_status']);
    }
}

public function testAnEmptyQueueIsANoOp(): void
{
    self::assertSame(0, $this->runner()->runOnce());
}
```

- [ ] **Step 2: Implement**

`runOnce()` loops up to `$limit` times: claim, publish, record; on `GitHubApiException` or any `\Throwable`, log it,
`releaseClaim()`, and stop the loop rather than hammering a failing API. `scripts/publish-sourcedata.php` is a thin
CLI entry point suitable for cron, mirroring how the existing outbox backstop script is invoked.

- [ ] **Step 3: Run tests, then commit**

```bash
vendor/bin/phpunit phpunit_tests/Services/SourceData/PublishRunnerTest.php
composer parallel-lint && composer lint:fix && composer analyse
git add -A && git commit -m "feat(sourcedata): cron runner that publishes approved batches"
```

---

## Task 7: Configuration and mode reporting

**Files:**

- Modify: `.env.example`, `src/Health.php`
- Test: `phpunit_tests/HealthSourceDataPublisherTest.php`

**Interfaces:**

- Produces: `Health::buildSourceDataPublisherStatus(): array{status: 'ok'|'warning', message: string}`, mirroring
  `buildSourceDataWriteModeStatus()`.

Add to `.env.example`, commented, with the private key path pointing outside the tree:

```bash
# Phase 2 publisher. Without all four, approved change requests accumulate unpublished.
GITHUB_APP_ID=
GITHUB_APP_INSTALLATION_ID=
GITHUB_APP_PRIVATE_KEY_PATH=/etc/litcal/github-app.pem
GITHUB_REPOSITORY=Liturgical-Calendar/LiturgicalCalendarAPI
GITHUB_BASE_BRANCH=development
```

The health block must warn when queue mode is on but the publisher is unconfigured — that combination silently
accumulates approved work nobody is publishing, which looks exactly like success to an editor.

- [ ] **Step 1: Write the tests, run them, implement, run again, commit**

```bash
vendor/bin/phpunit phpunit_tests/HealthSourceDataPublisherTest.php
composer parallel-lint && composer lint:fix && composer analyse
git add -A && git commit -m "feat(health): report publisher configuration alongside write mode"
```

---

## Task 8: Register the GitHub App (human step)

This task is performed by the repository owner, not by an implementing agent. An agent reaching it should stop, print
these instructions, and continue to Task 9 — every earlier task is testable against mocks and needs no credential.

- [ ] **Step 1: Create the App** at <https://github.com/settings/apps/new>, owned by the `Liturgical-Calendar`
      organisation.
- [ ] **Step 2: Set repository permissions** — Contents: Read and write; Pull requests: Read and write;
      Metadata: Read-only. No account permissions, no webhook.
- [ ] **Step 3: Install it** on `Liturgical-Calendar/LiturgicalCalendarAPI` only.
- [ ] **Step 4: Generate a private key**, download the `.pem`, and place it **outside the deployed tree** — e.g.
      `/etc/litcal/github-app.pem`, mode `0400`, owned by the web-server user. The nginx location has been narrowed to
      `public/`, but a signing key must not depend on that narrowing holding.
- [ ] **Step 5: Record the ids.** The App id is on the App's settings page; the installation id is the trailing number
      in the URL of the installation's settings page.
- [ ] **Step 6: Fill `.env`** with the five keys from Task 7 and confirm `GET /health` reports the publisher as
      configured.

---

## Task 9: Runbook and documentation

**Files:**

- Modify: `docs/ops/change-request-runbook.md`, `CHANGELOG.md`

- [ ] **Step 1: Document the publish lifecycle** — `none → queued → open`, what each column means once filled, and
      that phase 3 supplies `merged`/`closed`.
- [ ] **Step 2: Document the operational failure modes** — an unconfigured publisher accumulating approved work; a
      non-fast-forward `422` as the expected symptom of two editors racing on one resource, and that it retries rather
      than clobbering; a stale installation token.
- [ ] **Step 3: State the phase-3 gap explicitly.** Required wording in substance:

> **Not closed by phase 2:** deleting a calendar or test through a change request does not purge its OpenFGA
> authorization tuples. Nothing between "PR merged" and "next redeploy" performs that purge, so a deleted diocese's
> former editors retain edit access on an object whose files are gone. This is deliberate — only merge detection
> (phase 3) knows the deletion actually happened — and it is a known limitation, not an oversight.

- [ ] **Step 4: CHANGELOG entry** under the commented unreleased block, matching the existing convention.
- [ ] **Step 5: Lint and commit**

```bash
composer lint:md && composer lint:jsondata
git add -A && git commit -m "docs(publisher): runbook for phase 2, including the phase-3 purge gap"
```

---

## Self-Review

**Spec coverage.** Credential → Task 3 + Task 8. Publishing sequence → Task 5. `force: false` → Task 4 Step 1. One
commit per change request → Task 5. Branch naming → Task 5. Generated PR body → Task 5 (`openPullRequest`). Ancestor
landmine → Task 1. `closed` predicate → explicitly out of scope, recorded in Global Constraints. OpenFGA purge →
explicitly phase 3, recorded in Global Constraints and Task 9 Step 3. Reuse and failure handling → Task 6, with the
spec's over-claim about `BackstopRunner` and `ConsumerLoop` corrected up front.

**Placeholders.** None: every code step carries real code, every test step a real assertion, and the one task with no
code (Task 8) is a human procedure with concrete URLs, permissions and file modes.

**Type consistency.** `claimNextPublishableBatch(): ?string`, `recordPublication(string, string, string, ?int, string): int`
and `releaseClaim(string): int` are defined in Task 2 and used with those exact signatures in Task 6.
`installationToken(): string` is defined in Task 3 and consumed in Task 4. `publish(string): PublishResult` is defined
in Task 5 and consumed in Task 6. `ChangePublicationStatus` cases used are `NONE`, `QUEUED`, `OPEN`, `MERGED` — all
present in the existing enum; `CLOSED` is deliberately never written.
