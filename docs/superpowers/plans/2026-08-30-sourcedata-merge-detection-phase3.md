# Source-data change requests, phase 3 — implementation plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or
> superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Detect when a rolling pull request merges or closes, write the resulting status and side effects, and
publish approved batches from a Redis stream with cron demoted to the backstop.

**Architecture:** A `MergePollRunner` polls the distinct pull-request numbers among `open` rows and writes
`merged` / `closed`, verifying per-batch containment before asserting anything was published. A claim token
closes the ownership hole in phase 2's claim protocol. A best-effort `XADD` on approval wakes a long-lived
consumer that calls the existing `PublishRunner` path; Postgres stays the queue of record.

**Tech Stack:** PHP 8.4, PostgreSQL (PDO), Doctrine Migrations, ext-redis, Guzzle (PSR-18), Monolog, PHPUnit 12,
PHPStan level 10, phpcs (PSR-12).

**Spec:** `docs/superpowers/specs/2026-08-30-sourcedata-merge-detection-design.md`

## Global Constraints

- PHP >= 8.4. Short array syntax, 4-space indent, single quotes unless interpolating. PSR-12 via `phpcs`.
- `composer analyse` is PHPStan **level 10**, and `phpstan.neon.dist` scans `paths: [src]` **only** — anything
  under `scripts/` or `bin/` needs a standalone run: `vendor/bin/phpstan analyse --level=10 scripts/foo.php`.
- Every non-HTTP caller of `LoggerFactory::create()` MUST pass `includeProcessors: false` (the sixth argument).
  The default attaches `RequestResponseProcessor`, which THROWS on any record whose context lacks
  `type => request|response`. This shipped once already and killed the runner inside its own catch blocks.
- `GITHUB_REPOSITORY` is a GitHub Actions built-in injected into every job as `owner/repo`. Tests that clear it
  must clear **both** `$_ENV` and the process environment (`putenv`), because `getEnvString()` falls back to
  `getenv()`. Clearing only `$_ENV` passes locally and fails CI, every time.
- Never round-trip `jsondata/schemas/openapi.json` through `json_decode`/`json_encode` — it is canonical literal
  UTF-8 with zero `\uXXXX` escapes and PHP re-escapes non-ASCII, producing a ~14,000-line phantom diff. Edit it
  as text. `composer lint:jsondata` is what catches encoding drift, not `lint:openapi`.
- PostgreSQL rejects `FOR UPDATE` combined with `GROUP BY`. Any new claim-shaped query must keep the
  candidate-then-lock pattern `claimNextPublishableBatch()` uses, repeating the claimability predicate on the
  lock query.
- Use quoted heredocs (`<<'EOF'`) for commit messages and docs. An unquoted heredoc runs command substitution
  and silently eats backticked words.
- Feature branches from `development`; PRs target `development`, never `main`. Never `--no-verify`. Do not push
  immediately after committing — CodeRabbit is rate-limited; batch commits.
- Work happens on branch `feature/sourcedata-merge-detection`, which already carries the spec commit.

## File Structure

**Created:**

| Path                                                     | Responsibility                                             |
| -------------------------------------------------------- | ---------------------------------------------------------- |
| `src/Migrations/Version20260830120000.php`               | `publish_claim_token`, `publication_settled_at`, 2 indexes |
| `src/Services/SourceData/PublishClaim.php`               | `{batchId, token}` returned by the claim                   |
| `src/Services/GitHub/PullRequestState.php`               | `{state, merged, mergeCommitSha, headSha}`                 |
| `src/Services/SourceData/MergePollRunner.php`            | Poll PRs, verify containment, write transitions            |
| `src/Services/SourceData/MergePollRunResult.php`         | `{merged, closed, reset, unpollable, stoppedOnFailure}`    |
| `src/Services/SourceData/SourceDataPublishNotifier.php`  | Best-effort `XADD` of a batch id                           |
| `src/Services/SourceData/PublishConsumerLoop.php`        | Stream tick → `PublishRunner`, plus rate-limited idle poll |
| `src/Services/SourceData/SourceDataPublisherFactory.php` | The one place all three entry points wire from             |
| `scripts/poll-sourcedata-merges.php`                     | Cron entry for merge detection                             |
| `bin/publish-sourcedata-consumer`                        | Long-lived stream consumer                                 |

**Modified:**

| Path                                                                 | Change                                                                 |
| -------------------------------------------------------------------- | ---------------------------------------------------------------------- |
| `src/Enum/ClaimReleaseOutcome.php`                                   | add `CLAIM_LOST`                                                       |
| `src/Repositories/SourceDataChangeRequestRepository.php`             | claim token; merge-detection queries; `UNPUBLISHED_PREDICATE` docblock |
| `src/Services/SourceData/PublishRunner.php`                          | carry the token through claim → release                                |
| `src/Services/GitHub/GitHubGitDataClient.php`                        | `getPullRequest()`, `compareCommits()`                                 |
| `src/Services/SourceData/SourceDataWriter.php`                       | `commit(ChangeResource, bool $deletesResource = false)`                |
| `src/Services/SourceData/DiskSourceDataWriter.php`                   | accept and ignore the flag                                             |
| `src/Services/SourceData/ChangeRequestSourceDataWriter.php`          | write `deletes_resource` into `metadata`                               |
| `src/Handlers/RegionalDataHandler.php`                               | pass `true` from `deleteCalendar()`                                    |
| `src/Handlers/TestsHandler.php`                                      | pass `true` from the DELETE path                                       |
| `src/Handlers/Concerns/WritesSourceData.php`                         | thread the flag through `commitStagedFiles()`                          |
| `src/Handlers/Admin/ChangeRequestAdminHandler.php`                   | `XADD` after `approve()` commits                                       |
| `src/Repositories/UserNotificationRepository.php`                    | change-request inbox items                                             |
| `src/Services/Outbox/StreamConsumerInterface.php`                    | `callable(string)`                                                     |
| `src/Services/Outbox/RedisStreamConsumer.php`                        | payload field name as a constructor argument                           |
| `src/Services/Outbox/ConsumerLoop.php`                               | `(int)` cast and the `<= 0` guard move here                            |
| `src/Health.php`                                                     | `open_batches`, `oldest_open_age_seconds`                              |
| `scripts/publish-sourcedata.php`                                     | wire through the factory                                               |
| `docs/ops/change-request-runbook.md`, `.env.example`, `CHANGELOG.md` | operator-facing docs                                                   |

---

### Task 1: Migration — claim token and settled-at

**Files:**

- Create: `src/Migrations/Version20260830120000.php`
- Test: `phpunit_tests/Repositories/SourceDataChangeRequestSchemaTest.php` (modify)

**Interfaces:**

- Consumes: nothing.
- Produces: columns `sourcedata_change_requests.publish_claim_token UUID NULL` and
  `sourcedata_change_requests.publication_settled_at TIMESTAMPTZ NULL`; indexes
  `idx_scr_open_pr` and `idx_scr_settled_for_submitter`.

- [ ] **Step 1: Write the failing test**

Append to `phpunit_tests/Repositories/SourceDataChangeRequestSchemaTest.php`:

```php
public function testPublishClaimTokenAndSettledAtColumnsExist(): void
{
    $stmt = self::$pdo->query(
        "SELECT column_name, data_type, is_nullable
           FROM information_schema.columns
          WHERE table_name = 'sourcedata_change_requests'
            AND column_name IN ('publish_claim_token', 'publication_settled_at')
          ORDER BY column_name"
    );
    self::assertNotFalse($stmt);
    /** @var list<array<string, mixed>> $rows */
    $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

    self::assertCount(2, $rows, 'Both phase-3 columns must exist');
    self::assertSame('publication_settled_at', $rows[0]['column_name']);
    self::assertSame('timestamp with time zone', $rows[0]['data_type']);
    self::assertSame('YES', $rows[0]['is_nullable']);
    self::assertSame('publish_claim_token', $rows[1]['column_name']);
    self::assertSame('uuid', $rows[1]['data_type']);
    self::assertSame('YES', $rows[1]['is_nullable']);
}

public function testPhase3IndexesExist(): void
{
    $stmt = self::$pdo->query(
        "SELECT indexname FROM pg_indexes
          WHERE tablename = 'sourcedata_change_requests'
            AND indexname IN ('idx_scr_open_pr', 'idx_scr_settled_for_submitter')
          ORDER BY indexname"
    );
    self::assertNotFalse($stmt);
    self::assertSame(
        ['idx_scr_open_pr', 'idx_scr_settled_for_submitter'],
        $stmt->fetchAll(\PDO::FETCH_COLUMN)
    );
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit phpunit_tests/Repositories/SourceDataChangeRequestSchemaTest.php --filter 'Phase3|PublishClaimToken'`

Expected: FAIL — `assertCount(2, …)` sees 0 rows; the index query returns `[]`.

If instead the whole class is SKIPPED, Postgres credentials are missing. Set `DB_HOST` / `DB_NAME` / `DB_USER` /
`DB_PASSWORD` in `.env.local` (CI does this) before continuing — a skipped test proves nothing here.

- [ ] **Step 3: Write the migration**

```php
<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Migrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Phase 3: claim ownership, and a stable cursor for publication notifications.
 *
 * `publish_claim_token` closes the hole `releaseClaim()`'s own docblock describes: its
 * `publication_status = 'queued'` guard identifies *a* claim, not *whose*, so a runner whose
 * publish failed late can release a claim a DIFFERENT runner has since taken — spending a second
 * attempt against `MAX_PUBLISH_ATTEMPTS` and parking a merely-slow batch in three cycles instead
 * of five. Comparing a token in the `WHERE` makes a stale release match nothing, which costs the
 * batch nothing.
 *
 * `publication_settled_at` is the notification cursor. `updated_at` cannot be: it moves on every
 * claim, release, reclaim and record, so it answers "when was this row last touched" rather than
 * "when did this become news for the submitter". This column is written once, by the transition to
 * `merged` or `closed`, and is compared against `user_notification_state.last_notification_seen_at`.
 */
final class Version20260830120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add sourcedata_change_requests.publish_claim_token and .publication_settled_at (phase 3)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !( $this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform ),
            'This migration targets PostgreSQL only.'
        );

        $this->addSql('ALTER TABLE sourcedata_change_requests ADD COLUMN publish_claim_token UUID NULL');
        $this->addSql('ALTER TABLE sourcedata_change_requests ADD COLUMN publication_settled_at TIMESTAMPTZ NULL');

        $this->addSql(
            'COMMENT ON COLUMN sourcedata_change_requests.publish_claim_token IS '
            . "'Identifies WHICH runner holds the queued claim; compared in releaseClaim() so a stale release is a no-op'"
        );
        $this->addSql(
            'COMMENT ON COLUMN sourcedata_change_requests.publication_settled_at IS '
            . "'Written once, by the transition to merged or closed; the notification cursor (updated_at is not)'"
        );

        // The merge poller scans DISTINCT pr_number among open rows.
        $this->addSql(
            'CREATE INDEX idx_scr_open_pr ON sourcedata_change_requests (pr_number) '
            . "WHERE publication_status = 'open'"
        );

        // The notifications inbox reads a submitter's settled batches, newest first.
        $this->addSql(
            'CREATE INDEX idx_scr_settled_for_submitter ON sourcedata_change_requests '
            . '(submitted_by_sub, publication_settled_at DESC) WHERE publication_settled_at IS NOT NULL'
        );
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !( $this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform ),
            'This migration targets PostgreSQL only.'
        );

        $this->addSql('DROP INDEX IF EXISTS idx_scr_settled_for_submitter');
        $this->addSql('DROP INDEX IF EXISTS idx_scr_open_pr');
        $this->addSql('ALTER TABLE sourcedata_change_requests DROP COLUMN IF EXISTS publication_settled_at');
        $this->addSql('ALTER TABLE sourcedata_change_requests DROP COLUMN IF EXISTS publish_claim_token');
    }
}
```

- [ ] **Step 4: Apply the migration and run the test**

Run:

```bash
vendor/bin/doctrine-migrations migrate --no-interaction
vendor/bin/phpunit phpunit_tests/Repositories/SourceDataChangeRequestSchemaTest.php
```

Expected: PASS.

- [ ] **Step 5: Verify `down()` actually reverses**

Run:

```bash
vendor/bin/doctrine-migrations migrate prev --no-interaction
vendor/bin/doctrine-migrations migrate --no-interaction
```

Expected: both succeed. A `down()` that leaves an index behind makes the second `migrate` fail on a duplicate
index name — which is exactly what this step is for.

- [ ] **Step 6: Commit**

```bash
git add src/Migrations/Version20260830120000.php phpunit_tests/Repositories/SourceDataChangeRequestSchemaTest.php
git commit -m "feat(sourcedata): add publish_claim_token and publication_settled_at"
```

---

### Task 2: Claim ownership

**Files:**

- Create: `src/Services/SourceData/PublishClaim.php`
- Modify: `src/Enum/ClaimReleaseOutcome.php`, `src/Repositories/SourceDataChangeRequestRepository.php`,
  `src/Services/SourceData/PublishRunner.php`
- Test: `phpunit_tests/Repositories/SourceDataChangeRequestPublishQueueTest.php`,
  `phpunit_tests/Services/SourceData/PublishRunnerTest.php`

**Interfaces:**

- Consumes: the columns from Task 1.
- Produces:
  - `final readonly class PublishClaim { public function __construct(public string $batchId, public string $token) {} }`
  - `SourceDataChangeRequestRepository::claimNextPublishableBatch(array $skipBatchIds = []): ?PublishClaim`
    (**was** `?string` — every caller changes)
  - `SourceDataChangeRequestRepository::releaseClaim(string $batchId, string $token): ClaimReleaseOutcome`
    (**was** one argument)
  - `ClaimReleaseOutcome::CLAIM_LOST`

- [ ] **Step 1: Write the failing tests**

Append to `phpunit_tests/Repositories/SourceDataChangeRequestPublishQueueTest.php`:

```php
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
```

Add these helpers to the same class (next to the existing fixtures):

```php
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
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit phpunit_tests/Repositories/SourceDataChangeRequestPublishQueueTest.php`

Expected: FAIL — `claimNextPublishableBatch()` returns a `string`, so `$claim->batchId` is an
"Attempt to read property on string" error, and `releaseClaim()` rejects the second argument.

- [ ] **Step 3: Add the DTO and the enum case**

`src/Services/SourceData/PublishClaim.php`:

```php
<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services\SourceData;

/**
 * A held claim on one publishable batch: which batch, and — the part phase 2 lacked — WHICH
 * runner holds it.
 *
 * Phase 2 returned a bare batch id, so `releaseClaim()`'s `publication_status = 'queued'` guard
 * could only ask "is this batch under SOME claim", never "under MINE". The token closes that:
 * see {@see \LiturgicalCalendar\Api\Repositories\SourceDataChangeRequestRepository::releaseClaim()}.
 */
final readonly class PublishClaim
{
    public function __construct(
        public string $batchId,
        /** Generated inside the claiming transaction; cleared by record and by reclaim. */
        public string $token
    ) {
    }
}
```

In `src/Enum/ClaimReleaseOutcome.php`, add a case beside the existing four:

```php
    /**
     * The batch is still `queued`, but under a DIFFERENT claim token — another runner holds it.
     *
     * Semantically distinct from both neighbours, which is why it is not folded into either.
     * Not {@see SETTLED_ELSEWHERE}: nothing is published, so this is no evidence the work is
     * done. Not {@see NOT_CLAIMED}: the batch is not lying around unclaimed, it is actively
     * being published by someone else.
     *
     * Reached by the sequence `releaseClaim()`'s docblock describes: this runner was merely
     * slow, the grace period elapsed, `reclaimStaleClaims()` freed the batch, and another
     * runner claimed it before this runner's own doomed call returned. The release correctly
     * does nothing — and, the point of the token, spends none of the batch's bounded attempts
     * on a claim it does not hold.
     */
    case CLAIM_LOST = 'claim_lost';
```

Leave `isSettled()` alone: `CLAIM_LOST` is not settled, so the existing `match` returning false by
default is already correct. Add a one-line comment there saying so, or the next reader will wonder.

- [ ] **Step 4: Thread the token through the repository**

In `claimNextPublishableBatch()`, generate the token in the same transaction and return the DTO. Replace the
claim `UPDATE` and the `return $batchId;` inside the candidate loop with:

```php
                $token = $this->newBatchId(); // gen_random_uuid(); same generator, different purpose

                $claim = $this->db->prepare(
                    'UPDATE sourcedata_change_requests
                        SET publication_status  = :queued,
                            publish_claim_token = :token,
                            updated_at          = NOW()
                      WHERE batch_id = :batch_id'
                );
                $claim->execute([
                    'queued'   => ChangePublicationStatus::QUEUED->value,
                    'token'    => $token,
                    'batch_id' => $batchId,
                ]);

                $this->db->commit();

                return new PublishClaim($batchId, $token);
```

Change the signature to `: ?PublishClaim` and update its docblock's `@return`.

In `releaseClaim()`, take the token and compare it. The observation half is unchanged; the `released` CTE gains
one predicate, and the `match` gains one arm:

```php
    public function releaseClaim(string $batchId, string $token): ClaimReleaseOutcome
    {
        $stmt = $this->db->prepare(
            'WITH observed AS (
                 SELECT publication_status, publish_claim_token
                   FROM sourcedata_change_requests
                  WHERE batch_id = :batch_id
                  LIMIT 1
             ),
             released AS (
                 UPDATE sourcedata_change_requests
                    SET publication_status  = :none,
                        publish_claim_token = NULL,
                        publish_attempts    = publish_attempts + 1,
                        updated_at          = NOW()
                  WHERE batch_id = :batch_id
                    AND publication_status  = :queued
                    AND publish_claim_token = :token
                 RETURNING id
             )
             SELECT ( SELECT publication_status  FROM observed ) AS observed_status,
                    ( SELECT publish_claim_token FROM observed ) AS observed_token,
                    ( SELECT COUNT(*) FROM released )            AS released_rows'
        );
        $stmt->execute([
            'none'     => ChangePublicationStatus::NONE->value,
            'queued'   => ChangePublicationStatus::QUEUED->value,
            'token'    => $token,
            'batch_id' => $batchId,
        ]);

        /** @var array<string, mixed>|false $row */
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (false === $row) {
            return ClaimReleaseOutcome::BATCH_MISSING;
        }

        if (self::requireInt($row['released_rows'] ?? null, 'released_rows') > 0) {
            return ClaimReleaseOutcome::RELEASED;
        }

        $observed = $row['observed_status'] ?? null;
        if (!is_string($observed)) {
            return ClaimReleaseOutcome::BATCH_MISSING;
        }

        $observedToken = $row['observed_token'] ?? null;

        // Still queued, but not under OUR token: another runner holds a live claim. Reported
        // distinctly so the caller neither treats it as published (SETTLED_ELSEWHERE) nor as
        // unclaimed work (NOT_CLAIMED) — and, critically, so no attempt is spent above.
        if (
            ChangePublicationStatus::QUEUED === ChangePublicationStatus::tryFrom($observed)
            && is_string($observedToken)
            && $observedToken !== $token
        ) {
            return ClaimReleaseOutcome::CLAIM_LOST;
        }

        return match (ChangePublicationStatus::tryFrom($observed)) {
            ChangePublicationStatus::OPEN,
            ChangePublicationStatus::MERGED,
            ChangePublicationStatus::CLOSED => ClaimReleaseOutcome::SETTLED_ELSEWHERE,
            default                         => ClaimReleaseOutcome::NOT_CLAIMED,
        };
    }
```

In `recordPublication()`, add `publish_claim_token = NULL,` to the `SET` list. In `reclaimStaleClaims()`, add
`publish_claim_token = NULL,` to its `SET` list. Both are the same reason: no token may outlive the claim it
names, or a later stale release could match by accident.

- [ ] **Step 5: Update `PublishRunner` to carry the token**

In `runOnce()`, `$batchId = $this->repository->claimNextPublishableBatch($attempted);` becomes:

```php
                $claim = $this->repository->claimNextPublishableBatch($attempted);
```

then `if (null === $claim) { break; }`, `$attempted[] = $claim->batchId;`, `$this->publisher->publish($claim->batchId);`
and `$outcome = $this->releaseClaimSafely($claim->batchId, $claim->token);`. Every log context key `batch_id`
becomes `$claim->batchId`.

`releaseClaimSafely()` takes both and forwards both. Add `CLAIM_LOST` to the "everything else is a real failure"
comment block — it stops the run, because this runner's publish did fail and the runner that actually holds the
claim carries on regardless.

- [ ] **Step 6: Run the tests**

Run:

```bash
vendor/bin/phpunit phpunit_tests/Repositories/SourceDataChangeRequestPublishQueueTest.php
vendor/bin/phpunit phpunit_tests/Services/SourceData/PublishRunnerTest.php
```

Expected: PASS. `PublishRunnerTest` may need its fakes updated where they assert on a returned batch id string.

- [ ] **Step 7: Prove the token holds under REAL concurrency**

PHP is single-threaded, so no in-process test can make two claims genuinely concurrent. Phase 2 found four
concurrency defects and every one needed two real processes — and a test that hand-duplicates the fixed SQL
instead of calling the real method passes even with the fix reverted.

`SourceDataChangeRequestPublishQueueTest::testTwoRealConcurrentRunnersNeverClaimTheSameBatch()` already races
two OS processes via `proc_open` against `claimNextPublishableBatch()` itself. It must be updated for the new
return type and extended to assert on the token:

```php
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
```

Reuse whatever `proc_open` helper that file already has for its existing race test rather than writing a second
one; if it is inlined in that test, extract it to `raceTwoProcesses()` first, unchanged, as its own commit.

Run: `vendor/bin/phpunit phpunit_tests/Repositories/SourceDataChangeRequestPublishQueueTest.php --filter Concurrent`

Expected: PASS. Then **verify the test can fail**: temporarily drop `AND publish_claim_token = :token` from
`releaseClaim()`, re-run, and confirm the last assertion fails. Restore it. A concurrency test that passes with
the fix reverted is worse than no test, because it is believed.

- [ ] **Step 8: Static analysis and style**

Run: `composer analyse && composer lint`

Expected: clean. PHPStan will flag any caller of the two changed signatures you missed — that is the point of
running it before committing.

- [ ] **Step 9: Commit**

```bash
git add src/Services/SourceData/PublishClaim.php src/Enum/ClaimReleaseOutcome.php \
        src/Repositories/SourceDataChangeRequestRepository.php src/Services/SourceData/PublishRunner.php \
        phpunit_tests/Repositories/SourceDataChangeRequestPublishQueueTest.php \
        phpunit_tests/Services/SourceData/PublishRunnerTest.php
git commit -m "fix(sourcedata): a claim now identifies whose it is, not merely that one exists"
```

---

### Task 3: Pin the `closed` decision — no SQL change

**Files:**

- Modify: `src/Repositories/SourceDataChangeRequestRepository.php` (docblock only)
- Test: `phpunit_tests/Repositories/SourceDataChangeRequestRepositoryTest.php`

**Interfaces:**

- Consumes: nothing.
- Produces: nothing. This task changes no behaviour; it converts an accident into a decision and pins it.

- [ ] **Step 1: Write the failing tests**

Append to `phpunit_tests/Repositories/SourceDataChangeRequestRepositoryTest.php`:

```php
/**
 * A batch whose PR was closed unmerged is excluded from the accumulation base — by the REVIEW
 * axis (phase 3 writes `rejected` alongside `closed`), not by the publication axis.
 */
public function testClosedAndRejectedRowIsExcludedFromTheAccumulationBase(): void
{
    $path    = 'jsondata/sourcedata/rite/roman/decrees/decrees.json';
    $batchId = $this->submitDecrees('editor-1', '{"decrees":["A"]}');

    $this->forceStatuses($batchId, review: 'rejected', publication: 'closed');

    self::assertNull($this->repo->findUnpublishedContent($path, 'editor-1'));
}

/**
 * The mirror image, and the reason the previous test proves what it claims: a `closed` row that
 * is still `approved` IS in the base. `closed` means nothing reached the repository, so on the
 * publication axis it genuinely belongs there. If this ever starts returning null, someone has
 * "simplified" `publication_status <> 'merged'` into treating `closed` as published — which would
 * silently drop an editor's un-merged work from their next submission.
 *
 * Constructible only by direct SQL: no code path produces closed-without-rejected.
 */
public function testClosedButStillApprovedRowRemainsInTheAccumulationBase(): void
{
    $path    = 'jsondata/sourcedata/rite/roman/decrees/decrees.json';
    $batchId = $this->submitDecrees('editor-1', '{"decrees":["A"]}');

    $this->forceStatuses($batchId, review: 'approved', publication: 'closed');

    self::assertSame('{"decrees":["A"]}', $this->repo->findUnpublishedContent($path, 'editor-1'));
}

/**
 * `closed` must never become the NOT_SUPERSEDED_BY_PUBLISHED floor. A closed batch published
 * nothing, so using it as the floor would exclude older rows on the strength of content that
 * never reached the repository.
 */
public function testClosedRowIsNotASupersessionFloor(): void
{
    $path = 'jsondata/sourcedata/rite/roman/decrees/decrees.json';

    $older = $this->submitDecrees('editor-1', '{"decrees":["A"]}');
    $this->repo->approveBatch($older, 'reviewer-1');

    $newer = $this->submitDecrees('editor-1', '{"decrees":["A","B"]}');
    $this->forceStatuses($newer, review: 'rejected', publication: 'closed');

    // The closed batch is out of the base; the older approved one is still in it, NOT excluded
    // by a floor the closed batch had no right to set.
    self::assertSame('{"decrees":["A"]}', $this->repo->findUnpublishedContent($path, 'editor-1'));
}
```

Helpers for the same class:

```php
private function submitDecrees(string $sub, string $content): string
{
    return $this->repo->submitBatch(
        ChangeResource::decrees(),
        [[
            'path'      => 'jsondata/sourcedata/rite/roman/decrees/decrees.json',
            'operation' => ChangeOperation::UPDATE,
            'content'   => $content,
        ]],
        $sub,
        'Editor',
        $sub . '@example.test',
        true
    )['batch_id'];
}

/** Both statuses at once, by direct SQL — no code path produces every combination this pins. */
private function forceStatuses(string $batchId, string $review, string $publication): void
{
    $stmt = self::$pdo->prepare(
        'UPDATE sourcedata_change_requests
            SET review_status = :r, publication_status = :p
          WHERE batch_id = :b'
    );
    $stmt->execute(['r' => $review, 'p' => $publication, 'b' => $batchId]);
}
```

- [ ] **Step 2: Run the tests**

Run: `vendor/bin/phpunit phpunit_tests/Repositories/SourceDataChangeRequestRepositoryTest.php --filter Closed`

Expected: **PASS on the first run.** This task asserts existing behaviour is already correct — that is the whole
finding. If any of the three FAILS, stop: the predicate does not do what the spec concluded it does, and the
design decision needs revisiting before you write a docblock claiming otherwise.

- [ ] **Step 3: Record the decision in the docblock**

In `src/Repositories/SourceDataChangeRequestRepository.php`, append to `UNPUBLISHED_PREDICATE`'s docblock:

```php
     * # Why `closed` is admitted here, and what actually excludes it
     *
     * `chk_scr_publication_status` also allows `closed`, which phase 3 writes when a pull request
     * is closed unmerged. This predicate excludes only `merged`, so a `closed` row is ADMITTED by
     * the publication half — and that is correct, not an oversight. The publication axis answers
     * "is this row's content in the repository?", and for a pull request closed unmerged the
     * answer is no.
     *
     * What excludes it is the review half: phase 3 writes `review_status = 'rejected'` alongside
     * `closed`, and a rejected batch is no longer a proposal whatever became of its pull request.
     * Two axes answering two different questions — the parent design's own reason for keeping them
     * as two columns rather than one flattened enum.
     *
     * Decided in `docs/superpowers/specs/2026-08-30-sourcedata-merge-detection-design.md` and
     * pinned by `SourceDataChangeRequestRepositoryTest::testClosedAndRejectedRowIsExcludedFromTheAccumulationBase()`
     * and its deliberate mirror image `…ClosedButStillApprovedRowRemainsInTheAccumulationBase()`.
     * Do NOT "fix" this by adding `closed` to the exclusion: that would drop an editor's un-merged
     * work from their next submission on the strength of content that never reached the repository.
```

And to `NOT_SUPERSEDED_BY_PUBLISHED`'s docblock:

```php
     * The floor is `publication_status = 'merged'` ALONE, never `IN ('merged','closed')`. A closed
     * batch published nothing, so letting it set the floor would exclude older rows on the strength
     * of content that is not in the repository. Pinned by `testClosedRowIsNotASupersessionFloor()`.
```

- [ ] **Step 4: Re-run and lint**

Run: `vendor/bin/phpunit phpunit_tests/Repositories/SourceDataChangeRequestRepositoryTest.php && composer lint`

Expected: PASS, clean.

- [ ] **Step 5: Commit**

```bash
git add src/Repositories/SourceDataChangeRequestRepository.php \
        phpunit_tests/Repositories/SourceDataChangeRequestRepositoryTest.php
git commit -m "test(sourcedata): pin the closed-in-accumulation-base decision"
```

---

### Task 4: `getPullRequest()` and `compareCommits()`

**Files:**

- Create: `src/Services/GitHub/PullRequestState.php`
- Modify: `src/Services/GitHub/GitHubGitDataClient.php`
- Test: `phpunit_tests/Services/GitHub/GitHubGitDataClientTest.php`

**Interfaces:**

- Consumes: nothing.
- Produces:
  - `final readonly class PullRequestState { public string $state; public bool $merged; public ?string $mergeCommitSha; public string $headSha; }`
  - `GitHubGitDataClient::getPullRequest(int $number): PullRequestState`
  - `GitHubGitDataClient::compareCommits(string $base, string $head): string` — returns GitHub's
    `status`: one of `identical`, `ahead`, `behind`, `diverged`.

- [ ] **Step 1: Write the failing tests**

Append to `phpunit_tests/Services/GitHub/GitHubGitDataClientTest.php`, following that file's existing
`MockHandler` + `HandlerStack` setup:

```php
public function testGetPullRequestReadsStateMergedAndShas(): void
{
    $client = $this->clientForResponses([
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
    $client = $this->clientForResponses([
        new GuzzleResponse(404, [], json_encode(['message' => 'Not Found'], JSON_THROW_ON_ERROR)),
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
    $client = $this->clientForResponses([
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
    $client = $this->clientForResponses([
        new GuzzleResponse(200, [], json_encode(['status' => 'behind'], JSON_THROW_ON_ERROR)),
    ]);

    self::assertSame('behind', $client->compareCommits('aaa', 'bbb'));
}

public function testCompareCommitsThrowsWhenGithubReturnsNoStatus(): void
{
    $client = $this->clientForResponses([
        new GuzzleResponse(200, [], json_encode(['files' => []], JSON_THROW_ON_ERROR)),
    ]);

    $this->expectException(GitHubApiException::class);
    $client->compareCommits('aaa', 'bbb');
}
```

If `clientForResponses()` does not already exist in that test class, add it, mirroring how
`SourceDataPublisherTest` builds its transport:

```php
/** @param list<GuzzleResponse> $responses */
private function clientForResponses(array $responses): GitHubGitDataClient
{
    $http = new GuzzleClient(['handler' => HandlerStack::create(new MockHandler($responses))]);

    return new GitHubGitDataClient('Liturgical-Calendar', 'LiturgicalCalendarAPI', $this->auth($http), $http);
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit phpunit_tests/Services/GitHub/GitHubGitDataClientTest.php --filter 'PullRequest|Compare'`

Expected: FAIL — `Call to undefined method … ::getPullRequest()`.

- [ ] **Step 3: Add the DTO**

`src/Services/GitHub/PullRequestState.php`:

```php
<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services\GitHub;

/**
 * The three facts merge detection needs about a rolling pull request, plus the one it needs to
 * decide WHICH batches the merge actually carried.
 *
 * `$headSha` is here because "this batch's pull request merged" is not the same as "this batch
 * was in the merge": a reviewer clicking Merge concurrently with a publish leaves a commit on the
 * branch and outside the merge. See
 * {@see \LiturgicalCalendar\Api\Services\SourceData\MergePollRunner} for what is done with it.
 */
final readonly class PullRequestState
{
    public function __construct(
        /** GitHub's `state`: `open` or `closed`. A merged PR is always `closed`. */
        public string $state,
        public bool $merged,
        /** Null while the pull request is open — never the empty string. */
        public ?string $mergeCommitSha,
        /** The head commit at the time of this read; at merge time, what was merged. */
        public string $headSha
    ) {
    }

    public function isClosedUnmerged(): bool
    {
        return 'closed' === $this->state && false === $this->merged;
    }
}
```

- [ ] **Step 4: Add the two client methods**

Insert after `findOpenPullRequest()` in `src/Services/GitHub/GitHubGitDataClient.php`:

```php
    /**
     * Read one pull request's merge state.
     *
     * A 404 is a real failure here, not a value. `getRef()` is the only method in this class that
     * treats one as a value, and it does so because a missing branch is the expected state before
     * a resource's first publication. A pull request number came out of our own database, written
     * by our own publisher — if GitHub cannot find it, something is wrong with the repository or
     * the credential, and reporting "not merged" would hide that forever.
     *
     * @throws GitHubApiException If GitHub responds with a non-2xx status, or the payload carries
     *                            no usable `state` / `head.sha`
     */
    public function getPullRequest(int $number): PullRequestState
    {
        $response = $this->send('GET', '/pulls/' . $number, null);
        $decoded  = $this->decodeOrThrow($response);

        $state = $decoded['state'] ?? null;
        if (!is_string($state) || '' === $state) {
            throw new GitHubApiException($response->getStatusCode(), 'GitHub returned a pull request with no usable state');
        }

        $head    = $decoded['head'] ?? null;
        $headSha = is_array($head) ? ( $head['sha'] ?? null ) : null;
        if (!is_string($headSha) || '' === $headSha) {
            throw new GitHubApiException($response->getStatusCode(), 'GitHub returned a pull request with no usable head.sha');
        }

        $mergeCommitSha = $decoded['merge_commit_sha'] ?? null;

        return new PullRequestState(
            $state,
            true === ( $decoded['merged'] ?? false ),
            is_string($mergeCommitSha) && '' !== $mergeCommitSha ? $mergeCommitSha : null,
            $headSha
        );
    }

    /**
     * Compare two commits, returning GitHub's `status`: `identical`, `ahead`, `behind` or
     * `diverged`, read as "$head is <status> $base".
     *
     * Merge detection calls this as `compareCommits($batchCommitSha, $mergeCommitSha)` and treats
     * `ahead` or `identical` as "the merge commit's history contains the batch's commit". Anything
     * else means it does not, and the batch must NOT be marked merged.
     *
     * A missing `status` is an error rather than a default, because every default is wrong here:
     * assuming contained loses content silently, assuming not-contained republishes work that is
     * already in the repository. Neither guess is safe, so this refuses to guess.
     *
     * @throws GitHubApiException If GitHub responds with a non-2xx status, or returns no `status`
     */
    public function compareCommits(string $base, string $head): string
    {
        $response = $this->send(
            'GET',
            '/compare/' . rawurlencode($base) . '...' . rawurlencode($head),
            null
        );
        $decoded  = $this->decodeOrThrow($response);

        $status = $decoded['status'] ?? null;
        if (!is_string($status) || '' === $status) {
            throw new GitHubApiException($response->getStatusCode(), 'GitHub returned a comparison with no usable status');
        }

        return $status;
    }
```

- [ ] **Step 5: Run the tests**

Run: `vendor/bin/phpunit phpunit_tests/Services/GitHub/GitHubGitDataClientTest.php`

Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add src/Services/GitHub/PullRequestState.php src/Services/GitHub/GitHubGitDataClient.php \
        phpunit_tests/Services/GitHub/GitHubGitDataClientTest.php
git commit -m "feat(github): read a pull request's merge state and compare two commits"
```

---

### Task 5: Repository queries for merge detection

**Files:**

- Modify: `src/Repositories/SourceDataChangeRequestRepository.php`
- Test: `phpunit_tests/Repositories/SourceDataChangeRequestPublishQueueTest.php`

**Interfaces:**

- Consumes: `ChangePublicationStatus`, `ChangeReviewStatus`, the columns from Task 1.
- Produces:
  - `listOpenPullRequestNumbers(): list<int>` — DISTINCT `pr_number`, oldest first
  - `countOpenBatchesWithoutPullRequest(): int`
  - `listOpenBatchesForPullRequest(int $prNumber): list<array{batch_id: string, commit_sha: ?string}>`
  - `markBatchMerged(string $batchId, string $mergeCommitSha): int`
  - `markBatchClosedUnmerged(string $batchId, string $reason): int`
  - `returnBatchToUnpublished(string $batchId): int`
  - `openBatchStats(): array{open_batches: int, oldest_open_age_seconds: int}`

- [ ] **Step 1: Write the failing tests**

Append to `phpunit_tests/Repositories/SourceDataChangeRequestPublishQueueTest.php`:

```php
private function publishTo(string $batchId, int $prNumber, string $commitSha): void
{
    $claim = $this->repo->claimNextPublishableBatch();
    self::assertNotNull($claim);
    self::assertSame($batchId, $claim->batchId);
    $this->repo->recordPublication($batchId, 'litcal-data/national_calendar/roman/US', $commitSha, $prNumber, 'base');
}

public function testListOpenPullRequestNumbersDeduplicatesAndOrdersOldestFirst(): void
{
    $first  = $this->submitAndApprove('editor-1', 'US');
    $this->publishTo($first, 11, 'sha-a');
    $second = $this->submitAndApprove('editor-2', 'US');
    $this->publishTo($second, 11, 'sha-b');   // same rolling PR
    $third  = $this->submitAndApprove('editor-3', 'IT');
    $this->publishTo($third, 22, 'sha-c');

    self::assertSame([11, 22], $this->repo->listOpenPullRequestNumbers());
}

public function testListOpenBatchesForPullRequestReturnsEveryBatchOnIt(): void
{
    $first  = $this->submitAndApprove('editor-1', 'US');
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
```

Helper for the same class:

```php
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
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit phpunit_tests/Repositories/SourceDataChangeRequestPublishQueueTest.php`

Expected: FAIL — `Call to undefined method … ::listOpenPullRequestNumbers()`.

- [ ] **Step 3: Implement the queries**

Add to `src/Repositories/SourceDataChangeRequestRepository.php`, after `recordPublication()`:

```php
    /**
     * The DISTINCT pull request numbers among rows still `open`, oldest first.
     *
     * DISTINCT, not one row per batch, because the rolling branch is per RESOURCE: several
     * batches for one resource are published onto one branch and reuse one open pull request via
     * `findOpenPullRequest()`. Polling per batch would ask GitHub the same question N times and
     * get the same answer N times.
     *
     * `MIN(created_at)` orders it, so the oldest unresolved pull request is polled first and a
     * long queue cannot starve it.
     *
     * Rows with a NULL `pr_number` are deliberately NOT returned here — they are unpollable — and
     * are counted separately by {@see countOpenBatchesWithoutPullRequest()} so they cannot be
     * silently skipped.
     *
     * @return list<int>
     */
    public function listOpenPullRequestNumbers(): array
    {
        $stmt = $this->db->prepare(
            'SELECT pr_number
               FROM sourcedata_change_requests
              WHERE publication_status = :open
                AND pr_number IS NOT NULL
              GROUP BY pr_number
              ORDER BY MIN(created_at) ASC'
        );
        $stmt->execute(['open' => ChangePublicationStatus::OPEN->value]);

        /** @var list<int|string> $numbers */
        $numbers = $stmt->fetchAll(PDO::FETCH_COLUMN);

        return array_map(static fn (int|string $n): int => (int) $n, $numbers);
    }

    /**
     * How many batches are `open` with no pull request number to poll.
     *
     * Never non-zero in practice: `SourceDataPublisher` opens a pull request whenever
     * `findOpenPullRequest()` returns null, and `openPullRequest()` returns an `int` or throws. So
     * a non-zero count here is an UNEXPLAINED state — a batch that is stuck forever, since nothing
     * will ever poll it. Counted rather than filtered out of the poller's query, because a row
     * quietly excluded from a `WHERE` is exactly as invisible as one stranded `queued`, which is
     * the defect class this feature keeps rediscovering.
     */
    public function countOpenBatchesWithoutPullRequest(): int
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(DISTINCT batch_id) AS unpollable
               FROM sourcedata_change_requests
              WHERE publication_status = :open
                AND pr_number IS NULL'
        );
        $stmt->execute(['open' => ChangePublicationStatus::OPEN->value]);

        /** @var array<string, mixed>|false $row */
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return false === $row ? 0 : self::requireInt($row['unpollable'] ?? null, 'unpollable');
    }

    /**
     * Every `open` batch recorded against this pull request, with the commit it was published as.
     *
     * One row per BATCH, not per file: `recordPublication()` writes one commit sha across the
     * whole batch, so `MIN(commit_sha)` over the group is that single value, not an arbitrary
     * pick. Ordered by `MIN(created_at)` so the caller sees them in publication order.
     *
     * @return list<array{batch_id: string, commit_sha: ?string}>
     */
    public function listOpenBatchesForPullRequest(int $prNumber): array
    {
        $stmt = $this->db->prepare(
            'SELECT batch_id, MIN(commit_sha) AS commit_sha
               FROM sourcedata_change_requests
              WHERE publication_status = :open
                AND pr_number = :pr
              GROUP BY batch_id
              ORDER BY MIN(created_at) ASC'
        );
        $stmt->execute(['open' => ChangePublicationStatus::OPEN->value, 'pr' => $prNumber]);

        /** @var list<array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(
            static fn (array $row): array => [
                'batch_id'   => self::requireString($row['batch_id'] ?? null, 'batch_id'),
                'commit_sha' => is_string($row['commit_sha'] ?? null) ? $row['commit_sha'] : null,
            ],
            $rows
        );
    }

    /**
     * Record that a batch's content reached the base branch.
     *
     * Guarded on `publication_status = 'open'`, unlike {@see markBatchPublicationStatus()} which is
     * deliberately unconditional. The guard makes two racing pollers produce one transition and one
     * no-op instead of two writes of possibly-different merge shas — which is why the poller needs
     * no claim protocol of its own.
     *
     * `review_status` is untouched: a merge is not a review, and the batch was already approved.
     *
     * @return int Rows transitioned.
     */
    public function markBatchMerged(string $batchId, string $mergeCommitSha): int
    {
        $stmt = $this->db->prepare(
            'UPDATE sourcedata_change_requests
                SET publication_status     = :merged,
                    merge_commit_sha       = :merge_commit_sha,
                    publication_settled_at = NOW(),
                    updated_at             = NOW()
              WHERE batch_id = :batch_id
                AND publication_status = :open'
        );
        $stmt->execute([
            'merged'           => ChangePublicationStatus::MERGED->value,
            'merge_commit_sha' => $mergeCommitSha,
            'batch_id'         => $batchId,
            'open'             => ChangePublicationStatus::OPEN->value,
        ]);

        return $stmt->rowCount();
    }

    /**
     * Record that a batch's pull request was closed without merging.
     *
     * Writes `review_status = 'rejected'` with it, which is what keeps the batch out of the
     * accumulation base — see `UNPUBLISHED_PREDICATE`'s docblock, where that pairing is a decision
     * rather than a coincidence. `rejected_reason` is generated rather than left null so an
     * editor's history explains why a batch they never withdrew is rejected.
     *
     * Nothing needs reverting: the change was never live anywhere.
     *
     * @return int Rows transitioned.
     */
    public function markBatchClosedUnmerged(string $batchId, string $reason): int
    {
        $stmt = $this->db->prepare(
            'UPDATE sourcedata_change_requests
                SET publication_status     = :closed,
                    review_status          = :rejected,
                    rejected_reason        = :reason,
                    publication_settled_at = NOW(),
                    updated_at             = NOW()
              WHERE batch_id = :batch_id
                AND publication_status = :open'
        );
        $stmt->execute([
            'closed'   => ChangePublicationStatus::CLOSED->value,
            'rejected' => ChangeReviewStatus::REJECTED->value,
            'reason'   => $reason,
            'batch_id' => $batchId,
            'open'     => ChangePublicationStatus::OPEN->value,
        ]);

        return $stmt->rowCount();
    }

    /**
     * Put an `open` batch back to claimable, because its pull request merged WITHOUT it.
     *
     * Reachable whenever a reviewer merges concurrently with a publish: the batch's commit lands
     * on the branch, the merge takes the head it had a moment earlier, and the batch is left
     * pointing at a pull request that closed without carrying it. Marking it `merged` would assert
     * it reached the repository and make the publisher skip it forever, losing its content
     * silently — the same failure the age-based ancestor exclusion exists to avoid.
     *
     * `publish_attempts` is cleared: the batch spent no attempt, it was simply overtaken. The git
     * identifiers (`branch`, `commit_sha`, `pr_number`) are deliberately KEPT, so an operator
     * asking "what happened to this batch" can see which pull request passed it by.
     *
     * @return int Rows transitioned.
     */
    public function returnBatchToUnpublished(string $batchId): int
    {
        $stmt = $this->db->prepare(
            'UPDATE sourcedata_change_requests
                SET publication_status  = :none,
                    publish_attempts    = 0,
                    publish_claim_token = NULL,
                    updated_at          = NOW()
              WHERE batch_id = :batch_id
                AND publication_status = :open'
        );
        $stmt->execute([
            'none'     => ChangePublicationStatus::NONE->value,
            'batch_id' => $batchId,
            'open'     => ChangePublicationStatus::OPEN->value,
        ]);

        return $stmt->rowCount();
    }

    /**
     * How many batches are awaiting a merge decision, and how long the oldest has waited.
     *
     * Reported by `GET /health`. An open batch is NOT an error — a pull request awaiting review is
     * the ordinary state — so the age is what carries the signal: a value that keeps climbing past
     * any plausible review time means the poller is not running at all.
     *
     * @return array{open_batches: int, oldest_open_age_seconds: int}
     */
    public function openBatchStats(): array
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(DISTINCT batch_id) AS open_batches,
                    COALESCE(EXTRACT(EPOCH FROM (NOW() - MIN(updated_at))), 0) AS oldest_age
               FROM sourcedata_change_requests
              WHERE publication_status = :open'
        );
        $stmt->execute(['open' => ChangePublicationStatus::OPEN->value]);

        /** @var array<string, mixed>|false $row */
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (false === $row) {
            return ['open_batches' => 0, 'oldest_open_age_seconds' => 0];
        }

        return [
            'open_batches'            => self::requireInt($row['open_batches'] ?? null, 'open_batches'),
            'oldest_open_age_seconds' => self::requireInt($row['oldest_age'] ?? null, 'oldest_age'),
        ];
    }
```

- [ ] **Step 4: Run the tests**

Run: `vendor/bin/phpunit phpunit_tests/Repositories/SourceDataChangeRequestPublishQueueTest.php`

Expected: PASS.

- [ ] **Step 5: Static analysis**

Run: `composer analyse && composer lint`

Expected: clean.

- [ ] **Step 6: Commit**

```bash
git add src/Repositories/SourceDataChangeRequestRepository.php \
        phpunit_tests/Repositories/SourceDataChangeRequestPublishQueueTest.php
git commit -m "feat(sourcedata): repository queries for merge detection"
```

---

### Task 6: `MergePollRunner`

**Files:**

- Create: `src/Services/SourceData/MergePollRunResult.php`, `src/Services/SourceData/MergePollRunner.php`
- Test: `phpunit_tests/Services/SourceData/MergePollRunnerTest.php`

**Interfaces:**

- Consumes: Task 4's `GitHubGitDataClient::getPullRequest()` / `compareCommits()` and `PullRequestState`;
  Task 5's seven repository methods.
- Produces:
  - `final readonly class MergePollRunResult { public int $merged; public int $closed; public int $reset; public int $unpollable; public bool $stoppedOnFailure; }`
  - `MergePollRunner::__construct(SourceDataChangeRequestRepository $repository, GitHubGitDataClient $client, ?LoggerInterface $logger = null)`
  - `MergePollRunner::runOnce(int $limit = 50): MergePollRunResult`

`GitHubGitDataClient` is `final`, so tests wire a REAL client to a Guzzle `MockHandler`, exactly as
`SourceDataPublisherTest` does. Do not add an interface to make it mockable — asserting on the wire is what
caught phase 2's bugs.

- [ ] **Step 1: Write the failing tests**

Create `phpunit_tests/Services/SourceData/MergePollRunnerTest.php`:

```php
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

#[CoversClass(MergePollRunner::class)]
final class MergePollRunnerTest extends RepositoryTestCase
{
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
            [[
                'path'      => "jsondata/sourcedata/rite/roman/calendars/nations/{$nation}/{$nation}.json",
                'operation' => ChangeOperation::CREATE,
                'content'   => '{"litcal":[]}',
            ]],
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

    /** @param list<GuzzleResponse> $responses */
    private function runnerFor(array $responses): MergePollRunner
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

        $auth   = new GitHubAppAuth('1', '2', $this->privateKeyPath(), $http, new ArrayAdapter());
        $client = new GitHubGitDataClient('Liturgical-Calendar', 'LiturgicalCalendarAPI', $auth, $http);

        return new MergePollRunner($this->repo, $client);
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
            new GuzzleResponse(200, [], json_encode(['token' => 'gh_t', 'expires_at' => '2099-01-01T00:00:00Z'], JSON_THROW_ON_ERROR)),
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
            new GuzzleResponse(200, [], json_encode(['token' => 'gh_t', 'expires_at' => '2099-01-01T00:00:00Z'], JSON_THROW_ON_ERROR)),
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
            new GuzzleResponse(200, [], json_encode(['token' => 'gh_t', 'expires_at' => '2099-01-01T00:00:00Z'], JSON_THROW_ON_ERROR)),
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
            new GuzzleResponse(200, [], json_encode(['token' => 'gh_t', 'expires_at' => '2099-01-01T00:00:00Z'], JSON_THROW_ON_ERROR)),
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
            new GuzzleResponse(200, [], json_encode(['token' => 'gh_t', 'expires_at' => '2099-01-01T00:00:00Z'], JSON_THROW_ON_ERROR)),
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
            new GuzzleResponse(200, [], json_encode(['token' => 'gh_t', 'expires_at' => '2099-01-01T00:00:00Z'], JSON_THROW_ON_ERROR)),
            self::prJson('closed', true, 'merge-sha', 'sha-other'),
            new GuzzleResponse(500, [], json_encode(['message' => 'boom'], JSON_THROW_ON_ERROR)),
        ])->runOnce();

        self::assertTrue($result->stoppedOnFailure);
        self::assertSame(ChangePublicationStatus::OPEN->value, $this->publicationStatus($head));
    }

    public function testAFailedPollStopsTheRun(): void
    {
        $this->publishedBatch('editor-1', 'US', 11, 'sha-a');
        $this->publishedBatch('editor-2', 'IT', 22, 'sha-b');

        $result = $this->runnerFor([
            new GuzzleResponse(200, [], json_encode(['token' => 'gh_t', 'expires_at' => '2099-01-01T00:00:00Z'], JSON_THROW_ON_ERROR)),
            new GuzzleResponse(503, [], json_encode(['message' => 'unavailable'], JSON_THROW_ON_ERROR)),
        ])->runOnce();

        self::assertTrue($result->stoppedOnFailure);
        self::assertSame(0, $result->merged);
    }

    public function testUnpollableOpenBatchesAreCountedNotSkipped(): void
    {
        $batchId = $this->publishedBatch('editor-1', 'US', 11, 'sha-a');
        self::$pdo->exec("UPDATE sourcedata_change_requests SET pr_number = NULL WHERE batch_id = '{$batchId}'");

        $result = $this->runnerFor([])->runOnce();

        self::assertSame(1, $result->unpollable);
        self::assertFalse($result->stoppedOnFailure, 'an unexplained row is reported, not an outage');
    }
}
```

`privateKeyPath()` should return a path to a throwaway RSA key. If `GitHubAppAuthTest` already has such a
fixture helper, reuse it; otherwise generate one into `sys_get_temp_dir()` in `setUp()` with
`openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048])` and
`openssl_pkey_export_to_file()`, deleting it in `tearDown()`.

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit phpunit_tests/Services/SourceData/MergePollRunnerTest.php`

Expected: FAIL — `Class "…\MergePollRunner" not found`.

- [ ] **Step 3: Write `MergePollRunResult`**

```php
<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services\SourceData;

/**
 * What one {@see MergePollRunner::runOnce()} pass did.
 *
 * Every count is reported on EVERY run, including runs that stopped early, for the same reason
 * {@see PublishRunResult} reports `parkedBatches` unconditionally: the runs most likely to leave
 * work in an odd state are exactly the runs that end before they meant to.
 */
final readonly class MergePollRunResult
{
    public function __construct(
        /** Batches transitioned to `merged`. */
        public int $merged = 0,
        /** Batches transitioned to `closed` (and `rejected`) because their PR closed unmerged. */
        public int $closed = 0,
        /**
         * Batches returned to `none` because their pull request merged WITHOUT them. Not a
         * failure — the design's answer to a concurrent merge — but never silent either, since a
         * non-zero value here means work was overtaken and will be republished.
         */
        public int $reset = 0,
        /**
         * Batches `open` with a NULL `pr_number`: unpollable, and stuck forever unless an operator
         * intervenes. Should always be zero; a non-zero value is an unexplained state, reported
         * rather than filtered out of the query.
         */
        public int $unpollable = 0,
        /** True if the run stopped because a poll or a containment check threw. */
        public bool $stoppedOnFailure = false
    ) {
    }
}
```

- [ ] **Step 4: Write `MergePollRunner`**

```php
<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services\SourceData;

use LiturgicalCalendar\Api\Repositories\SourceDataChangeRequestRepository;
use LiturgicalCalendar\Api\Services\GitHub\GitHubGitDataClient;
use LiturgicalCalendar\Api\Services\GitHub\PullRequestState;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Polls the rolling pull requests that carry published change-request batches and records what
 * became of them.
 *
 * # Polling, not webhooks
 *
 * A webhook would need a new public route, HMAC verification, and a second authentication mode on
 * `/_ops` — real attack surface for a transition nobody is waiting on. A missed webhook is a
 * silently stuck row; a missed poll is picked up on the next tick.
 *
 * # One poll per pull request, not per batch
 *
 * The rolling branch is per RESOURCE, and {@see SourceDataPublisher} reuses an already-open pull
 * request. Two batches for one resource therefore share one `pr_number`, and polling per batch
 * would ask GitHub the same question twice and answer it twice.
 *
 * # Sharing a pull request is not being in the merge
 *
 * A reviewer clicking Merge concurrently with a publish separates the two: the publish
 * fast-forwards the branch to batch C's commit, the merge takes the head it had a moment earlier,
 * and C is left recorded against a pull request that closed without carrying it.
 *
 * Marking C `merged` would ASSERT it reached the repository. The publisher selects approved rows
 * that are not yet `merged`, so C would never be attempted again and its content would be lost
 * silently — the same failure mode the age-based ancestor exclusion was chosen to avoid, reached
 * from the other direction. So containment is verified rather than assumed: a batch whose commit
 * is the merged head is contained (no extra call, the ordinary case), and any other batch on that
 * pull request is checked with one `compareCommits()`. A batch that is NOT contained is returned
 * to `none` and republished under a fresh pull request.
 *
 * A containment check that FAILS is read neither way. Assuming contained loses content; assuming
 * not-contained republishes work already in the repository. Both guesses are wrong, so the run
 * stops and the batch stays `open` for the next tick.
 *
 * # No claim protocol
 *
 * Unlike {@see PublishRunner}, this holds nothing and claims nothing. Its writes are `UPDATE`s
 * guarded on `publication_status = 'open'`, so two racing pollers produce one transition and one
 * no-op. There is no stranded state to reclaim, which is why there is no grace period, no attempt
 * bound and no reclaim step here. Do not add one for symmetry.
 *
 * # Stop, don't hammer
 *
 * A failed poll stops the run rather than moving to the next pull request. If GitHub is down or
 * the installation credential is stale, every remaining poll fails identically, and retrying
 * in-process would only exhaust the rate limit faster. The cron interval is what re-attempts.
 */
final class MergePollRunner
{
    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly SourceDataChangeRequestRepository $repository,
        private readonly GitHubGitDataClient $client,
        ?LoggerInterface $logger = null
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public function runOnce(int $limit = 50): MergePollRunResult
    {
        $unpollable = $this->unpollableCountSafely();

        try {
            $prNumbers = $this->repository->listOpenPullRequestNumbers();
        } catch (\Throwable $e) {
            $this->logger->error(
                'Listing open source-data pull requests failed; stopping this run rather than '
                    . 'polling against an unhealthy database.',
                ['exception' => $e::class, 'message' => $e->getMessage()]
            );

            return new MergePollRunResult(unpollable: $unpollable, stoppedOnFailure: true);
        }

        $merged = 0;
        $closed = 0;
        $reset  = 0;

        foreach (array_slice($prNumbers, 0, $limit) as $prNumber) {
            try {
                $tally = $this->pollOne($prNumber);
            } catch (\Throwable $e) {
                $this->logger->error(
                    'Polling a source-data pull request failed; stopping this run rather than '
                        . 'hammering a failing API with the rest of the queue.',
                    ['pr_number' => $prNumber, 'exception' => $e::class, 'message' => $e->getMessage()]
                );

                return new MergePollRunResult($merged, $closed, $reset, $unpollable, true);
            }

            $merged += $tally['merged'];
            $closed += $tally['closed'];
            $reset  += $tally['reset'];
        }

        return new MergePollRunResult($merged, $closed, $reset, $unpollable);
    }

    /**
     * @return array{merged: int, closed: int, reset: int}
     */
    private function pollOne(int $prNumber): array
    {
        $pr     = $this->client->getPullRequest($prNumber);
        $tally  = ['merged' => 0, 'closed' => 0, 'reset' => 0];
        $states = $this->repository->listOpenBatchesForPullRequest($prNumber);

        if ('open' === $pr->state) {
            return $tally;
        }

        if ($pr->isClosedUnmerged()) {
            foreach ($states as $batch) {
                $reason = sprintf('Pull request #%d was closed without merging.', $prNumber);
                if ($this->repository->markBatchClosedUnmerged($batch['batch_id'], $reason) > 0) {
                    $tally['closed']++;
                    $this->logger->info(
                        'Source-data change request batch closed unmerged.',
                        ['batch_id' => $batch['batch_id'], 'pr_number' => $prNumber]
                    );
                }
            }

            return $tally;
        }

        // Merged. `mergeCommitSha` is non-null for a merged pull request; a merged PR without one
        // is GitHub contradicting itself, and guessing a sha is worse than stopping.
        $mergeCommitSha = $pr->mergeCommitSha;
        if (null === $mergeCommitSha) {
            throw new \RuntimeException(sprintf('Pull request #%d reports merged but carries no merge_commit_sha', $prNumber));
        }

        foreach ($states as $batch) {
            if ($this->isContained($batch['commit_sha'], $pr, $mergeCommitSha)) {
                if ($this->repository->markBatchMerged($batch['batch_id'], $mergeCommitSha) > 0) {
                    $tally['merged']++;
                    $this->logger->info(
                        'Source-data change request batch merged.',
                        ['batch_id' => $batch['batch_id'], 'pr_number' => $prNumber, 'merge_commit_sha' => $mergeCommitSha]
                    );
                }
                continue;
            }

            if ($this->repository->returnBatchToUnpublished($batch['batch_id']) > 0) {
                $tally['reset']++;
                $this->logger->warning(
                    'A pull request merged WITHOUT one of the batches recorded against it — most '
                        . 'likely a review that landed concurrently with a publish. The batch is '
                        . 'claimable again and the next publish opens a fresh pull request carrying '
                        . 'it; it is deliberately NOT marked merged, which would assert it reached '
                        . 'the repository and lose its content silently.',
                    [
                        'batch_id'         => $batch['batch_id'],
                        'pr_number'        => $prNumber,
                        'batch_commit_sha' => $batch['commit_sha'],
                        'merge_commit_sha' => $mergeCommitSha,
                    ]
                );
            }
        }

        return $tally;
    }

    /**
     * Is this batch's commit inside the merged pull request's history?
     *
     * Equality with the merged head is decided locally and costs nothing — the ordinary case, since
     * most pull requests carry one batch. Anything else costs one `compareCommits()`, read as
     * "$head is <status> $base": `ahead` or `identical` means the merge commit's history contains
     * the batch's commit.
     *
     * A null `commit_sha` on an `open` row is unexplained (the publisher writes one for every row
     * it records), so it is read conservatively as NOT contained rather than optimistically.
     */
    private function isContained(?string $batchCommitSha, PullRequestState $pr, string $mergeCommitSha): bool
    {
        if (null === $batchCommitSha || '' === $batchCommitSha) {
            return false;
        }

        if ($batchCommitSha === $pr->headSha) {
            return true;
        }

        $status = $this->client->compareCommits($batchCommitSha, $mergeCommitSha);

        return in_array($status, ['ahead', 'identical'], true);
    }

    /**
     * Wrapped for the same reason every DB call in {@see PublishRunner} is: reporting how much work
     * is in an odd state must never be the thing that turns a completed run into a raw fatal.
     */
    private function unpollableCountSafely(): int
    {
        try {
            $unpollable = $this->repository->countOpenBatchesWithoutPullRequest();
        } catch (\Throwable $e) {
            $this->logger->error(
                'Counting unpollable open source-data batches failed; this run reports 0, which may '
                    . 'understate what is actually stuck.',
                ['exception' => $e::class, 'message' => $e->getMessage()]
            );

            return 0;
        }

        if ($unpollable > 0) {
            $this->logger->warning(
                'Source-data change request batches are `open` with no pull request number, so '
                    . 'nothing will ever poll them. The publisher always records one, so this is an '
                    . 'unexplained state and needs an operator.',
                ['unpollable_batches' => $unpollable]
            );
        }

        return $unpollable;
    }
}
```

- [ ] **Step 5: Run the tests**

Run: `vendor/bin/phpunit phpunit_tests/Services/SourceData/MergePollRunnerTest.php`

Expected: PASS.

- [ ] **Step 6: Static analysis and style**

Run: `composer analyse && composer lint`

Expected: clean.

- [ ] **Step 7: Commit**

```bash
git add src/Services/SourceData/MergePollRunner.php src/Services/SourceData/MergePollRunResult.php \
        phpunit_tests/Services/SourceData/MergePollRunnerTest.php
git commit -m "feat(sourcedata): merge detection, with containment verified rather than assumed"
```

---

### Task 7: `deletes_resource` — the signal the purge needs

**Files:**

- Modify: `src/Services/SourceData/SourceDataWriter.php`, `src/Services/SourceData/DiskSourceDataWriter.php`,
  `src/Services/SourceData/ChangeRequestSourceDataWriter.php`,
  `src/Handlers/Concerns/WritesSourceData.php`, `src/Handlers/RegionalDataHandler.php`,
  `src/Handlers/TestsHandler.php`
- Test: `phpunit_tests/Handlers/RegionalDataChangeRequestTest.php`,
  `phpunit_tests/Handlers/TestsChangeRequestTest.php`

**Interfaces:**

- Consumes: nothing.
- Produces: `SourceDataWriter::commit(ChangeResource $resource, bool $deletesResource = false): array`, and the
  `metadata` key `deletes_resource` (bool) on every row of a batch that removes a whole resource. Task 8 reads it.

- [ ] **Step 1: Write the failing tests**

In `phpunit_tests/Handlers/RegionalDataChangeRequestTest.php`:

```php
/**
 * The signal Task 8's OpenFGA purge keys on. It must be set ONLY when the resource itself is
 * removed — see the sibling test below for the case that makes `operation = 'delete'` unusable.
 */
public function testDeletingACalendarFlagsTheBatchAsAResourceDeletion(): void
{
    $batchId = $this->deleteNationalCalendarAsQueuedRequest('US');

    foreach ($this->repo->getBatch($batchId) as $row) {
        self::assertTrue(
            $row['metadata']['deletes_resource'] ?? false,
            'every row of a resource-deletion batch carries the flag'
        );
    }
}

/**
 * THE false positive. Dropping a locale from `metadata.locales` stages a DELETE for that i18n
 * file on a calendar that still exists. If this batch were flagged, merging it would revoke every
 * editor and viewer on a live calendar because a translator removed one language.
 */
public function testRemovingALocaleStagesADeleteButIsNotAResourceDeletion(): void
{
    $batchId = $this->updateNationalCalendarDroppingALocale('US');
    $rows    = $this->repo->getBatch($batchId);

    self::assertContains(
        ChangeOperation::DELETE->value,
        array_column($rows, 'operation'),
        'the fixture must actually stage a delete, or this test proves nothing'
    );

    foreach ($rows as $row) {
        self::assertFalse(
            $row['metadata']['deletes_resource'] ?? false,
            'a locale removal is an update, not a resource deletion'
        );
    }
}
```

In `phpunit_tests/Handlers/TestsChangeRequestTest.php`, the analogue:

```php
public function testDeletingATestDefinitionFlagsTheBatchAsAResourceDeletion(): void
{
    $batchId = $this->deleteTestAsQueuedRequest('MyTest');

    foreach ($this->repo->getBatch($batchId) as $row) {
        self::assertTrue($row['metadata']['deletes_resource'] ?? false);
    }
}
```

Build `deleteNationalCalendarAsQueuedRequest()`, `updateNationalCalendarDroppingALocale()` and
`deleteTestAsQueuedRequest()` on the existing fixtures in those files — they already drive the handlers in
queue mode; these just name the three shapes the assertions need.

- [ ] **Step 2: Run tests to verify they fail**

Run:

```bash
vendor/bin/phpunit phpunit_tests/Handlers/RegionalDataChangeRequestTest.php --filter ResourceDeletion
vendor/bin/phpunit phpunit_tests/Handlers/TestsChangeRequestTest.php --filter ResourceDeletion
```

Expected: FAIL on the two `assertTrue` cases (`metadata.deletes_resource` is absent). The locale case
should already PASS — it is the guard rail, and it must keep passing after Step 3.

- [ ] **Step 3: Widen the writer seam**

In `src/Services/SourceData/SourceDataWriter.php`:

```php
    /**
     * @param bool $deletesResource True only when this request removes the RESOURCE, not merely
     *        some of its files. Set by the handler that knows, at the moment it knows.
     *
     *        `operation = ChangeOperation::DELETE` cannot answer this and must never be used to:
     *        `RegionalDataHandler::writeI18nFiles()` stages a DELETE for every locale file dropped
     *        from `metadata.locales`, on a calendar that still exists. Keying the OpenFGA purge on
     *        the operation would revoke every editor on a live calendar because a translator
     *        removed one language.
     *
     * @return array<string, mixed> Always carries a `disposition` key.
     */
    public function commit(ChangeResource $resource, bool $deletesResource = false): array;
```

In `DiskSourceDataWriter::commit()`, accept and ignore it:

```php
    public function commit(ChangeResource $resource, bool $deletesResource = false): array
    {
        // Ignored deliberately. Disk mode purges inline, in the handler, gated on the write having
        // landed — there is no later moment at which to act on this, because there is no later.
```

In `ChangeRequestSourceDataWriter::commit()`, change the signature the same way and widen the metadata:

```php
            $metadata = ['authorizing_relation' => 'admin'];
            if ($deletesResource) {
                // Read at merge time by MergePollRunner, which is the only moment that knows the
                // deletion actually happened. Written here because this is the only moment that
                // knows it was a resource deletion at all.
                $metadata['deletes_resource'] = true;
            }

            $submission = $this->repository->submitBatch(
                $resource,
                $this->staged,
                $sub,
                $name,
                $email,
                $emailVerified,
                $metadata
            );
```

In `WritesSourceData::commitStagedFiles()`:

```php
    protected function commitStagedFiles(ChangeResource $resource, bool $deletesResource = false): array
    {
        return $this->sourceDataWriter()->commit($resource, $deletesResource);
    }
```

- [ ] **Step 4: Pass `true` from the two deletion call sites, and only those**

`src/Handlers/RegionalDataHandler.php`, in `deleteCalendar()` — the call that follows the `stageFile(…DELETE…)`
at lines 1063 and 1076:

```php
        $changeRequest = $this->commitStagedFiles($this->changeResourceForRequest(), deletesResource: true);
```

`src/Handlers/TestsHandler.php:270`:

```php
        $changeRequest = $this->commitStagedFiles($this->changeResourceForTest($scope), deletesResource: true);
```

Leave `TestsHandler.php:372` and `:598` (create/update) and every `RegionalDataHandler` write path alone —
including the one at line 1262 that stages locale deletions. Named arguments here, not positional: a bare
`true` at a call site reads as nothing at all, and this argument's whole job is to be unmistakable.

- [ ] **Step 5: Run the tests**

Run:

```bash
vendor/bin/phpunit phpunit_tests/Handlers/RegionalDataChangeRequestTest.php
vendor/bin/phpunit phpunit_tests/Handlers/TestsChangeRequestTest.php
vendor/bin/phpunit phpunit_tests/Services/SourceData/DiskSourceDataWriterTest.php
vendor/bin/phpunit phpunit_tests/Handlers/Concerns/WritesSourceDataTest.php
```

Expected: PASS, including the locale case that must not regress.

- [ ] **Step 6: Static analysis and style**

Run: `composer analyse && composer lint`

Expected: clean. PHPStan flags any `SourceDataWriter` implementation whose signature you missed.

- [ ] **Step 7: Commit**

```bash
git add src/Services/SourceData/SourceDataWriter.php src/Services/SourceData/DiskSourceDataWriter.php \
        src/Services/SourceData/ChangeRequestSourceDataWriter.php src/Handlers/Concerns/WritesSourceData.php \
        src/Handlers/RegionalDataHandler.php src/Handlers/TestsHandler.php \
        phpunit_tests/Handlers/RegionalDataChangeRequestTest.php phpunit_tests/Handlers/TestsChangeRequestTest.php
git commit -m "feat(sourcedata): flag a batch that deletes a resource, not merely one of its files"
```

---

### Task 8: OpenFGA purge and audit trail on a merged deletion

**Files:**

- Modify: `src/Services/SourceData/MergePollRunner.php`
- Test: `phpunit_tests/Services/SourceData/MergePollRunnerTest.php`,
  `phpunit_tests/Services/SourceData/RecordingTuplePurgeService.php` (create)

**Interfaces:**

- Consumes: Task 7's `metadata.deletes_resource`; `ResourceTuplePurgeServiceInterface::purgeForObject(string): int`;
  `AuditLogRepository::log(?string $userId, string $action, string $resourceType, ?string $resourceId, ?array $details)`.
- Produces: `MergePollRunner::__construct(SourceDataChangeRequestRepository $repository, GitHubGitDataClient $client,
  ?ResourceTuplePurgeServiceInterface $purge = null, ?AuditLogRepository $auditLog = null, ?LoggerInterface $logger = null)`.

Both new dependencies are optional and nullable, matching how `ResolvesOutboxTooling` treats the purge service:
a deployment without OpenFGA must still detect merges.

- [ ] **Step 1: Write the failing tests**

Create `phpunit_tests/Services/SourceData/RecordingTuplePurgeService.php`:

```php
<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Services\SourceData;

use LiturgicalCalendar\Api\Services\ResourceTuplePurgeServiceInterface;

/**
 * Records which FGA objects were purged, so a test can assert on the exact object string — the
 * thing that goes wrong here is a double-qualified id (`roman/roman/US`), and only the string
 * shows that.
 */
final class RecordingTuplePurgeService implements ResourceTuplePurgeServiceInterface
{
    /** @var list<string> */
    public array $purged = [];

    public function __construct(private readonly ?\Throwable $throws = null)
    {
    }

    public function purgeForObject(string $fgaObject): int
    {
        $this->purged[] = $fgaObject;

        if (null !== $this->throws) {
            throw $this->throws;
        }

        return 1;
    }
}
```

Append to `MergePollRunnerTest`:

```php
private function deletionBatch(string $sub, string $nation, int $prNumber, string $commitSha): string
{
    $batchId = $this->repo->submitBatch(
        ChangeResource::nationalCalendar(Rite::ROMAN, $nation),
        [[
            'path'      => "jsondata/sourcedata/rite/roman/calendars/nations/{$nation}/{$nation}.json",
            'operation' => ChangeOperation::DELETE,
            'content'   => null,
        ]],
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

public function testAMergedResourceDeletionPurgesOperationalTuples(): void
{
    $this->deletionBatch('editor-1', 'US', 11, 'sha-a');
    $purge = new RecordingTuplePurgeService();

    $this->runnerFor([
        new GuzzleResponse(200, [], json_encode(['token' => 'gh_t', 'expires_at' => '2099-01-01T00:00:00Z'], JSON_THROW_ON_ERROR)),
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
        new GuzzleResponse(200, [], json_encode(['token' => 'gh_t', 'expires_at' => '2099-01-01T00:00:00Z'], JSON_THROW_ON_ERROR)),
        self::prJson('closed', true, 'merge-sha', 'sha-a'),
    ], $purge)->runOnce();

    self::assertSame([], $purge->purged, 'a locale removal is not a resource deletion');
}

public function testAClosedUnmergedDeletionPurgesNothing(): void
{
    $this->deletionBatch('editor-1', 'US', 11, 'sha-a');
    $purge = new RecordingTuplePurgeService();

    $this->runnerFor([
        new GuzzleResponse(200, [], json_encode(['token' => 'gh_t', 'expires_at' => '2099-01-01T00:00:00Z'], JSON_THROW_ON_ERROR)),
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
        new GuzzleResponse(200, [], json_encode(['token' => 'gh_t', 'expires_at' => '2099-01-01T00:00:00Z'], JSON_THROW_ON_ERROR)),
        self::prJson('closed', true, 'merge-sha', 'sha-a'),
    ], $purge)->runOnce();

    self::assertSame(1, $result->merged);
    self::assertFalse($result->stoppedOnFailure);
    self::assertSame(ChangePublicationStatus::MERGED->value, $this->publicationStatus($batchId));
}
```

Change `runnerFor()` to take an optional purge service and pass it through:

```php
    /** @param list<GuzzleResponse> $responses */
    private function runnerFor(array $responses, ?RecordingTuplePurgeService $purge = null): MergePollRunner
    {
        // … existing transport wiring …
        return new MergePollRunner($this->repo, $client, $purge);
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit phpunit_tests/Services/SourceData/MergePollRunnerTest.php --filter 'Purge|purges'`

Expected: FAIL — `MergePollRunner::__construct()` takes no third argument.

- [ ] **Step 3: Wire the purge and the audit entry**

Add the two optional constructor parameters:

```php
    public function __construct(
        private readonly SourceDataChangeRequestRepository $repository,
        private readonly GitHubGitDataClient $client,
        /**
         * Null on a deployment without OpenFGA. Merge detection must still work there — the whole
         * point of the write-mode seam is that the stack is optional — so a null purge service is
         * a quiet no-op, not a failure.
         */
        private readonly ?ResourceTuplePurgeServiceInterface $purge = null,
        private readonly ?AuditLogRepository $auditLog = null,
        ?LoggerInterface $logger = null
    ) {
        $this->logger = $logger ?? new NullLogger();
    }
```

In `pollOne()`, after a successful `markBatchMerged()` (inside the `if (… > 0)` block, after `$tally['merged']++`):

```php
                    $this->audit('change_request.merged', $batch['batch_id'], ['pr_number' => $prNumber, 'merge_commit_sha' => $mergeCommitSha]);
                    $this->purgeIfResourceDeletion($batch['batch_id']);
```

and after a successful `markBatchClosedUnmerged()`:

```php
                    $this->audit('change_request.closed_unmerged', $batch['batch_id'], ['pr_number' => $prNumber]);
```

Add the two helpers:

```php
    /**
     * Purge a deleted resource's operational OpenFGA tuples, now that the deletion is real.
     *
     * The trigger is `metadata.deletes_resource`, NOT `operation = 'delete'`. The operation cannot
     * answer this: `RegionalDataHandler::writeI18nFiles()` stages a DELETE for every locale file
     * dropped from `metadata.locales`, on a calendar that still exists, so keying on it would
     * revoke every editor on a live calendar because a translator removed a language.
     *
     * The object string is rebuilt from the row's own `resource_type` and `resource_id`, which are
     * already rite-qualified. Do NOT reconstruct a `ChangeResource` here: its factories RE-qualify
     * a bare id, so `roman/US` would become `roman/roman/US` and fail closed for the wrong reason.
     *
     * `admin` tuples survive — that is `ResourceTuplePurgeService`'s own contract — so ownership
     * outlives a deletion and a recreated resource id belongs to the same person.
     *
     * Best-effort, and deliberately so: the batch is already `merged`, which is a fact about the
     * repository, and a reachable OpenFGA is not a precondition for recording it. A failure logs
     * and leaves the tuples for `ResourceTuplePurgeReconciler`'s sweep, exactly as the disk-mode
     * path does.
     */
    private function purgeIfResourceDeletion(string $batchId): void
    {
        if (null === $this->purge) {
            return;
        }

        try {
            $rows = $this->repository->getBatch($batchId);
        } catch (\Throwable $e) {
            $this->logger->error(
                'Could not read a merged batch to decide whether it deleted a resource; any '
                    . 'operational tuples it orphaned stay live until the reconciler sweep.',
                ['batch_id' => $batchId, 'exception' => $e::class, 'message' => $e->getMessage()]
            );

            return;
        }

        $first = $rows[0] ?? null;
        if (null === $first) {
            return;
        }

        $metadata = is_array($first['metadata'] ?? null) ? $first['metadata'] : [];
        if (true !== ( $metadata['deletes_resource'] ?? false )) {
            return;
        }

        $resourceType = $first['resource_type'] ?? null;
        $resourceId   = $first['resource_id'] ?? null;
        if (!is_string($resourceType) || !is_string($resourceId)) {
            return;
        }

        $fgaObject = $resourceType . ':' . $resourceId;

        try {
            $purged = $this->purge->purgeForObject($fgaObject);
            $this->logger->info(
                'Purged operational tuples for a resource whose deletion has merged.',
                ['batch_id' => $batchId, 'object' => $fgaObject, 'tuples' => $purged]
            );
        } catch (\Throwable $e) {
            $this->logger->error(
                'Purging operational tuples for a merged resource deletion failed; the merge stands '
                    . 'and the reconciler sweep will retry. Until it does, the deleted resource\'s '
                    . 'former editors retain access to an object whose files are gone.',
                ['batch_id' => $batchId, 'object' => $fgaObject, 'exception' => $e::class, 'message' => $e->getMessage()]
            );
        }
    }

    /**
     * Best-effort audit entry. A logging failure must never turn a recorded transition into a
     * failed run — same rule, and same reasoning, as `ChangeRequestAdminHandler::audit()`.
     *
     * The actor is null: nobody at this deployment performed the merge. The reviewer who clicked
     * Merge did so on GitHub, and attributing it to the approving admin would be a fabrication.
     *
     * @param array<string, mixed> $details
     */
    private function audit(string $action, string $batchId, array $details): void
    {
        if (null === $this->auditLog) {
            return;
        }

        try {
            $this->auditLog->log(null, $action, 'sourcedata_change_request', $batchId, $details);
        } catch (\Throwable) {
            // Deliberately swallowed — see method docblock.
        }
    }
```

Add the imports: `LiturgicalCalendar\Api\Repositories\AuditLogRepository` and
`LiturgicalCalendar\Api\Services\ResourceTuplePurgeServiceInterface`.

- [ ] **Step 4: Run the tests**

Run: `vendor/bin/phpunit phpunit_tests/Services/SourceData/MergePollRunnerTest.php`

Expected: PASS, all cases including the ones from Task 6.

- [ ] **Step 5: Static analysis and style**

Run: `composer analyse && composer lint`

Expected: clean.

- [ ] **Step 6: Commit**

```bash
git add src/Services/SourceData/MergePollRunner.php \
        phpunit_tests/Services/SourceData/MergePollRunnerTest.php \
        phpunit_tests/Services/SourceData/RecordingTuplePurgeService.php
git commit -m "feat(sourcedata): purge a deleted resource's tuples once its deletion has merged"
```

---

### Task 9: Change-request notifications in the inbox

**Files:**

- Modify: `src/Repositories/UserNotificationRepository.php`, `src/Handlers/Auth/NotificationsHandler.php` (docblock)
- Test: `phpunit_tests/Repositories/UserNotificationRepositoryTest.php` (create if absent)

**Interfaces:**

- Consumes: `publication_settled_at` from Task 1; the transitions from Task 5.
- Produces: `fetchInbox()` items may now carry `type: 'change_request_published'` with keys
  `batch_id`, `resource_type`, `resource_id`, `publication_status`, `pr_number`, `settled_at`, `unread`.
  `total` and `unread_count` span both sources.

- [ ] **Step 1: Write the failing tests**

```php
public function testInboxCarriesSettledChangeRequests(): void
{
    $batchId = $this->settledBatch('user-1', 'merged', '-1 hour');

    $inbox = $this->repo->fetchInbox('user-1');

    self::assertSame(1, $inbox['total']);
    self::assertSame('change_request_published', $inbox['items'][0]['type']);
    self::assertSame($batchId, $inbox['items'][0]['batch_id']);
    self::assertSame('merged', $inbox['items'][0]['publication_status']);
    self::assertTrue($inbox['items'][0]['unread']);
}

public function testAnUnsettledBatchIsNotNews(): void
{
    $this->openBatch('user-1');

    self::assertSame(0, $this->repo->fetchInbox('user-1')['total']);
}

public function testOneItemPerBatchNotPerFile(): void
{
    $this->settledBatch('user-1', 'merged', '-1 hour', files: 3);

    self::assertCount(1, $this->repo->fetchInbox('user-1')['items']);
}

public function testTheTwoSourcesInterleaveByTimestampAndShareTheTotals(): void
{
    $this->reviewedAccessRequest('user-1', '-2 hours');
    $this->settledBatch('user-1', 'merged', '-1 hour');
    $this->reviewedAccessRequest('user-1', '-3 hours');

    $inbox = $this->repo->fetchInbox('user-1');

    self::assertSame(3, $inbox['total']);
    self::assertSame(3, $inbox['unread_count']);
    self::assertSame(
        ['change_request_published', 'access_request_reviewed', 'access_request_reviewed'],
        array_column($inbox['items'], 'type'),
        'newest first, across both sources'
    );
}

public function testTheSeenBookmarkMarksChangeRequestsRead(): void
{
    $this->settledBatch('user-1', 'merged', '-2 hours');
    $this->repo->markSeen('user-1');
    $this->settledBatch('user-1', 'closed', '+0 seconds');

    $inbox = $this->repo->fetchInbox('user-1');

    self::assertSame(2, $inbox['total']);
    self::assertSame(1, $inbox['unread_count']);
}

public function testAnotherUsersBatchIsInvisible(): void
{
    $this->settledBatch('user-2', 'merged', '-1 hour');

    self::assertSame(0, $this->repo->fetchInbox('user-1')['total']);
}
```

Fixtures: `settledBatch()` inserts a batch's rows directly with `submitted_by_sub`, a `publication_status`, and
`publication_settled_at = NOW() + interval`; `openBatch()` leaves `publication_settled_at` NULL;
`reviewedAccessRequest()` inserts an `access_requests` row with a `reviewed_at`. Direct SQL is right here —
driving the whole publish path to produce one inbox row would test the publisher, not the inbox.

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit phpunit_tests/Repositories/UserNotificationRepositoryTest.php`

Expected: FAIL — `total` is 0; change-request rows are not read at all.

- [ ] **Step 3: Implement the second source and the PHP-side merge**

In `src/Repositories/UserNotificationRepository.php`, split the existing body of `fetchInbox()` into a private
`accessRequestItems()` and add a sibling, then merge. Keep the existing 50-row clamp.

```php
    /**
     * The user's inbox: reviewed access requests AND settled source-data change requests.
     *
     * # Why two queries and a PHP merge, rather than one UNION
     *
     * The two sources have genuinely different shapes, and `total` / `unread_count` are window
     * counts over the FULL filtered set rather than over the returned page — so a UNION would need
     * `COUNT(*) OVER ()` spanning both halves, over rows whose columns do not line up. That is one
     * query that is hard to read and harder to prove right. Two straightforward queries plus a
     * `usort` are verifiable by inspection, and each source is already capped at 50 rows, so the
     * merge is bounded.
     *
     * # Why `publication_settled_at` and not `updated_at`
     *
     * `updated_at` moves on every claim, release, reclaim and record, so it answers "when was this
     * row last touched", not "when did this become news". `publication_settled_at` is written once,
     * by the transition to `merged` or `closed`.
     *
     * # One item per batch
     *
     * An editor who changed a calendar and its fourteen i18n files proposed ONE thing. `DISTINCT ON
     * (batch_id)` collapses the rows the way the reviewer already sees them.
     */
```

The change-request half:

```php
    /**
     * @return list<array{type: string, batch_id: string, resource_type: string, resource_id: string,
     *                    publication_status: string, pr_number: ?int, settled_at: string, unread: bool}>
     */
    private function changeRequestItems(string $userId, string $lastSeen, int $limit): array
    {
        $sql = <<<'SQL'
            SELECT DISTINCT ON (batch_id)
                batch_id,
                resource_type,
                resource_id,
                publication_status,
                pr_number,
                publication_settled_at,
                (publication_settled_at > :last_seen::timestamptz) AS unread
            FROM sourcedata_change_requests
            WHERE submitted_by_sub = :uid
              AND publication_settled_at IS NOT NULL
            ORDER BY batch_id, publication_settled_at DESC
        SQL;
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':uid', $userId);
        $stmt->bindValue(':last_seen', $lastSeen);
        $stmt->execute();
        /** @var list<array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $items = array_map(
            function (array $row): array {
                $prNumber = $row['pr_number'] ?? null;

                return [
                    'type'               => 'change_request_published',
                    'batch_id'           => $this->toString($row['batch_id']),
                    'resource_type'      => $this->toString($row['resource_type']),
                    'resource_id'        => $this->toString($row['resource_id']),
                    'publication_status' => $this->toString($row['publication_status']),
                    'pr_number'          => null === $prNumber ? null : (int) $prNumber,
                    'settled_at'         => $this->iso8601($this->toString($row['publication_settled_at'])),
                    'unread'             => in_array($row['unread'], [true, 't', 'true', '1', 1], true),
                ];
            },
            $rows
        );

        // DISTINCT ON forces ORDER BY batch_id first, so the newest-first ordering the inbox needs
        // has to be re-applied here rather than left to SQL.
        usort($items, static fn (array $a, array $b): int => strcmp($b['settled_at'], $a['settled_at']));

        return array_slice($items, 0, $limit);
    }
```

Then in `fetchInbox()`, after computing `$lastSeen` / `$lastSeenIso`:

```php
        $accessItems = $this->accessRequestItems($userId, $lastSeen, $limit);
        $changeItems = $this->changeRequestItems($userId, $lastSeen, $limit);

        /** @var list<array<string, mixed>> $items */
        $items = array_merge($accessItems['items'], $changeItems);
        usort(
            $items,
            static fn (array $a, array $b): int => strcmp(
                (string) ( $b['settled_at'] ?? $b['reviewed_at'] ?? '' ),
                (string) ( $a['settled_at'] ?? $a['reviewed_at'] ?? '' )
            )
        );

        // total and unread_count span the FULL filtered set of both sources, not the merged page —
        // consistent with what the access-request half already promised on its own.
        $total       = $accessItems['total'] + count($changeItems);
        $unreadCount = $accessItems['unread_count'] + count(array_filter(
            $changeItems,
            static fn (array $item): bool => true === $item['unread']
        ));

        return [
            'items'        => array_slice($items, 0, $limit),
            'total'        => $total,
            'unread_count' => $unreadCount,
            'last_seen_at' => $lastSeenIso,
        ];
```

Update `NotificationsHandler`'s docblock and the `@return` shape on `fetchInbox()` to say `items` is a
DISCRIMINATED list and clients must switch on `type` — the two shapes do not share keys beyond `type` and
`unread`.

- [ ] **Step 4: Run the tests**

Run:

```bash
vendor/bin/phpunit phpunit_tests/Repositories/UserNotificationRepositoryTest.php
vendor/bin/phpunit --filter Notifications
```

Expected: PASS, and no regression in the existing access-request notification tests.

- [ ] **Step 5: Static analysis and style**

Run: `composer analyse && composer lint`

Expected: clean.

- [ ] **Step 6: Commit**

```bash
git add src/Repositories/UserNotificationRepository.php src/Handlers/Auth/NotificationsHandler.php \
        phpunit_tests/Repositories/UserNotificationRepositoryTest.php
git commit -m "feat(notifications): tell a submitter when their change request merges or closes"
```

---

### Task 10: `SourceDataPublishNotifier` and the two `XADD` sites

**Files:**

- Create: `src/Services/SourceData/SourceDataPublishNotifier.php`
- Modify: `src/Handlers/Admin/ChangeRequestAdminHandler.php`,
  `src/Services/SourceData/ChangeRequestSourceDataWriter.php`
- Test: `phpunit_tests/Services/SourceData/SourceDataPublishNotifierTest.php` (create),
  `phpunit_tests/Handlers/ChangeRequestAdminHandlerTest.php`

**Interfaces:**

- Consumes: nothing.
- Produces: `SourceDataPublishNotifier::__construct(?\Redis $redis, string $streamName, ?LoggerInterface $logger = null)`
  and `notify(string $batchId): void`. Task 12's consumer reads the `batch_id` field it writes.

- [ ] **Step 1: Write the failing tests**

Create `phpunit_tests/Services/SourceData/SourceDataPublishNotifierTest.php`:

```php
<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Services\SourceData;

use LiturgicalCalendar\Api\Services\SourceData\SourceDataPublishNotifier;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SourceDataPublishNotifier::class)]
final class SourceDataPublishNotifierTest extends TestCase
{
    public function testANullRedisIsAQuietNoOp(): void
    {
        // A self-hoster running cron only has no Redis. This must not throw and must not log.
        $handler  = new TestHandler();
        $notifier = new SourceDataPublishNotifier(null, 'litcal:publish-stream', new Logger('t', [$handler]));

        $notifier->notify('batch-1');

        self::assertSame([], $handler->getRecords());
    }

    public function testItXaddsTheBatchId(): void
    {
        if (!extension_loaded('redis')) {
            self::markTestSkipped('ext-redis is required for this test');
        }

        $redis = $this->createMock(\Redis::class);
        $redis->expects(self::once())
            ->method('xAdd')
            ->with('litcal:publish-stream', '*', ['batch_id' => 'batch-1'])
            ->willReturn('1-0');

        ( new SourceDataPublishNotifier($redis, 'litcal:publish-stream') )->notify('batch-1');
    }

    /**
     * The whole contract: the batch is already durable in Postgres and cron is the backstop, so a
     * Redis failure costs latency and never correctness. It must never propagate into the approval
     * the caller has already committed.
     */
    public function testARedisFailureIsLoggedAndSwallowed(): void
    {
        if (!extension_loaded('redis')) {
            self::markTestSkipped('ext-redis is required for this test');
        }

        $redis = $this->createMock(\Redis::class);
        $redis->method('xAdd')->willThrowException(new \RedisException('connection refused'));

        $handler = new TestHandler();
        ( new SourceDataPublishNotifier($redis, 'litcal:publish-stream', new Logger('t', [$handler])) )
            ->notify('batch-1');

        self::assertTrue($handler->hasWarningThatContains('sourcedata.redis.notify_failed'));
    }
}
```

Append to `phpunit_tests/Handlers/ChangeRequestAdminHandlerTest.php`:

```php
public function testApproveNotifiesTheStreamAfterTheStatusUpdate(): void
{
    $batchId  = $this->submittedBatch('editor-1');
    $notifier = new RecordingPublishNotifier();

    $this->handlerFor('approve', $batchId, $notifier)->handle($this->adminRequest());

    self::assertSame([$batchId], $notifier->notified);
}

public function testRejectDoesNotNotifyTheStream(): void
{
    $batchId  = $this->submittedBatch('editor-1');
    $notifier = new RecordingPublishNotifier();

    $this->handlerFor('reject', $batchId, $notifier)->handle($this->adminRequest());

    self::assertSame([], $notifier->notified, 'a rejected batch is never publishable');
}

/**
 * A batch approved on the auto-approval path never passes through this handler at all, and it is
 * the COMMON path — an admin editing a resource they administer. Missing it would leave the most
 * frequent approval waiting for cron.
 */
public function testAutoApprovalOnTheWritePathAlsoNotifies(): void
{
    $notifier = new RecordingPublishNotifier();
    $writer   = $this->changeRequestWriterForAdmin($notifier);

    $writer->stage('/abs/jsondata/sourcedata/rite/roman/calendars/nations/US/US.json', ChangeOperation::UPDATE, '{}');
    $result = $writer->commit(ChangeResource::nationalCalendar(Rite::ROMAN, 'US'));

    self::assertSame('approved', $result['disposition']);
    self::assertSame([$result['change_request']['batch_id']], $notifier->notified);
}
```

with a tiny recorder alongside the other test doubles:

```php
final class RecordingPublishNotifier extends SourceDataPublishNotifier
{
    /** @var list<string> */
    public array $notified = [];

    public function __construct()
    {
        parent::__construct(null, 'litcal:publish-stream');
    }

    public function notify(string $batchId): void
    {
        $this->notified[] = $batchId;
    }
}
```

`SourceDataPublishNotifier` must therefore **not** be `final`, and `notify()` must not be `private`. That is a
deliberate exception to this codebase's default: unlike `SourceDataPublisher`, whose `final` protects the
author/committer split, nothing here is worth protecting from a subclass, and the alternative is another
interface for a class with one method and no logic.

- [ ] **Step 2: Run tests to verify they fail**

Run:

```bash
vendor/bin/phpunit phpunit_tests/Services/SourceData/SourceDataPublishNotifierTest.php
vendor/bin/phpunit phpunit_tests/Handlers/ChangeRequestAdminHandlerTest.php --filter Notif
```

Expected: FAIL — `Class "…\SourceDataPublishNotifier" not found`.

- [ ] **Step 3: Write the notifier**

```php
<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services\SourceData;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Best-effort `XADD` announcing that a batch has become publishable.
 *
 * Mirrors {@see \LiturgicalCalendar\Api\Services\Outbox\OutboxNotifier}, and for the same reason:
 * the row is already durable in Postgres and cron is the backstop, so this is an accelerator over
 * a durable queue, never the queue itself. It therefore NEVER throws to the caller — a Redis
 * outage must not fail an approval that has already committed. Losing the message costs latency
 * and nothing else.
 *
 * The message is a HINT, not a work item: the consumer ignores the batch id except for logging and
 * claims from Postgres exactly as cron does. That is what makes a lost, duplicated or out-of-order
 * message harmless.
 *
 * Pass a null `\Redis` to disable — the ordinary state for a self-hoster, since `REDIS_SOCKET` /
 * `REDIS_HOST` are commented out in `.env.example`.
 *
 * Not `final`, unlike most of this namespace: it has one method and no invariant worth protecting,
 * and tests substitute a recording subclass rather than justify an interface for it.
 */
class SourceDataPublishNotifier
{
    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly ?\Redis $redis,
        private readonly string $streamName,
        ?LoggerInterface $logger = null
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public function notify(string $batchId): void
    {
        if (null === $this->redis) {
            return;
        }

        try {
            $this->redis->xAdd($this->streamName, '*', ['batch_id' => $batchId]);
        } catch (\RedisException $e) {
            $this->logger->warning('sourcedata.redis.notify_failed', [
                'batch_id' => $batchId,
                'error'    => $e->getMessage(),
            ]);
        }
    }
}
```

- [ ] **Step 4: Call it from both approval sites**

`ChangeRequestAdminHandler`: add a nullable `?SourceDataPublishNotifier $publishNotifier = null` constructor
parameter (tests inject; production resolves lazily like `getRepository()` does), and in `approve()`, AFTER the
`ConflictException` guard:

```php
        $this->audit('change_request.approve', $sub, $batchId, $firstRow, []);

        // AFTER the status UPDATE has committed, never before: a consumer that wakes on this
        // message claims from Postgres, so announcing an approval the database has not recorded
        // would send it looking for work that is not yet there. Same ordering constraint the
        // OpenFGA outbox already documents.
        $this->publishNotifier()->notify($batchId);
```

`ChangeRequestSourceDataWriter`: add a nullable `?SourceDataPublishNotifier $publishNotifier = null`
constructor parameter and, in `commit()`:

```php
        $autoApproved = $this->review->administers($resource, $sub);
        if ($autoApproved) {
            $this->repository->approveBatch($batchId, $sub);
            // The auto-approval path is the COMMON one — an admin editing a resource they
            // administer — and it never reaches ChangeRequestAdminHandler. Announcing only there
            // would leave the most frequent approval waiting for the cron backstop.
            $this->publishNotifier?->notify($batchId);
        }
```

Wire the notifier into `WritesSourceData::sourceDataWriter()`'s `ChangeRequestSourceDataWriter` construction,
resolving `\Redis` the same way `ResolvesOutboxTooling` already does (null when `ext-redis` is missing or no
`REDIS_SOCKET` / `REDIS_HOST` is configured). Stream name from `REDIS_SOURCEDATA_PUBLISH_STREAM`, defaulting to
`litcal:sourcedata-publish-stream`.

- [ ] **Step 5: Run the tests**

Run:

```bash
vendor/bin/phpunit phpunit_tests/Services/SourceData/SourceDataPublishNotifierTest.php
vendor/bin/phpunit phpunit_tests/Handlers/ChangeRequestAdminHandlerTest.php
vendor/bin/phpunit phpunit_tests/Handlers/Concerns/WritesSourceDataTest.php
```

Expected: PASS.

- [ ] **Step 6: Static analysis and style**

Run: `composer analyse && composer lint`

Expected: clean.

- [ ] **Step 7: Commit**

```bash
git add src/Services/SourceData/SourceDataPublishNotifier.php \
        src/Services/SourceData/ChangeRequestSourceDataWriter.php \
        src/Handlers/Admin/ChangeRequestAdminHandler.php src/Handlers/Concerns/WritesSourceData.php \
        phpunit_tests/Services/SourceData/SourceDataPublishNotifierTest.php \
        phpunit_tests/Handlers/ChangeRequestAdminHandlerTest.php
git commit -m "feat(sourcedata): announce an approved batch on a Redis stream, best-effort"
```

---

### Task 11: Widen the stream seam to string payloads

**Files:**

- Modify: `src/Services/Outbox/StreamConsumerInterface.php`, `src/Services/Outbox/RedisStreamConsumer.php`,
  `src/Services/Outbox/ConsumerLoop.php`
- Test: `phpunit_tests/Services/Outbox/RedisStreamConsumerTest.php`,
  `phpunit_tests/Services/Outbox/ConsumerLoopTest.php`

**Interfaces:**

- Consumes: nothing.
- Produces:
  - `StreamConsumerInterface::readOnce(int $blockMs, callable $process): void` where `$process` is
    `callable(string): void`
  - `RedisStreamConsumer::__construct(\Redis $redis, string $streamName, string $groupName, string $consumerName,
    ?LoggerInterface $logger = null, string $payloadField = 'row_id')`
  - `ConsumerLoop` now owns the `(int)` cast and the `<= 0` bad-id guard.

This touches the OpenFGA consumer, which is live. Its existing tests are the safety net — run the whole
`phpunit_tests/Services/Outbox/` directory before and after.

- [ ] **Step 1: Capture the current behaviour**

Run: `vendor/bin/phpunit phpunit_tests/Services/Outbox/`

Expected: PASS. Note the count; it must not drop.

- [ ] **Step 2: Write the failing tests**

Append to `phpunit_tests/Services/Outbox/RedisStreamConsumerTest.php`:

```php
public function testItPassesTheRawPayloadValueThroughAsAString(): void
{
    $redis = $this->redisReturningMessages(['1-0' => ['row_id' => '42']]);
    $seen  = [];

    ( new RedisStreamConsumer($redis, 'stream', 'group', 'consumer') )
        ->readOnce(0, function (string $id) use (&$seen): void {
            $seen[] = $id;
        });

    self::assertSame(['42'], $seen, 'the consumer no longer decides what a valid id looks like');
}

public function testItReadsAConfigurablePayloadField(): void
{
    $redis = $this->redisReturningMessages(['1-0' => ['batch_id' => 'b7f3-uuid']]);
    $seen  = [];

    ( new RedisStreamConsumer($redis, 'stream', 'group', 'consumer', null, 'batch_id') )
        ->readOnce(0, function (string $id) use (&$seen): void {
            $seen[] = $id;
        });

    self::assertSame(['b7f3-uuid'], $seen);
}

/**
 * A message missing the payload field entirely is unprocessable by anyone, so it is still ACKed
 * here rather than left to redeliver forever. What moved to the caller is deciding whether a
 * PRESENT value is valid.
 */
public function testAMessageMissingThePayloadFieldIsAckedAndSkipped(): void
{
    $redis = $this->redisReturningMessages(['1-0' => ['unrelated' => 'x']]);
    $redis->expects(self::once())->method('xAck')->with('stream', 'group', ['1-0']);
    $called = false;

    ( new RedisStreamConsumer($redis, 'stream', 'group', 'consumer') )
        ->readOnce(0, function (string $id) use (&$called): void {
            $called = true;
        });

    self::assertFalse($called);
}
```

Append to `phpunit_tests/Services/Outbox/ConsumerLoopTest.php`:

```php
/**
 * The `<= 0` guard moved here with the cast. The outbox's unit of work is an integer row id, and
 * this is now the only layer that knows that.
 */
public function testANonNumericOrNonPositiveIdIsNotProcessed(): void
{
    $consumer  = new FakeStreamConsumer(['0', '-1', 'not-a-number', '7']);
    $processor = new RecordingOutboxProcessor();

    ( new ConsumerLoop($consumer, $processor) )->tick();

    self::assertSame([7], $processor->processed);
}
```

- [ ] **Step 3: Run tests to verify they fail**

Run: `vendor/bin/phpunit phpunit_tests/Services/Outbox/`

Expected: FAIL on the new cases — the payload field is hardcoded and the value arrives as `int`.

- [ ] **Step 4: Widen the interface**

```php
interface StreamConsumerInterface
{
    public function ensureGroup(): void;

    /**
     * Read one message (or batch) and invoke `$process` with the payload field's RAW STRING value.
     *
     * String, not int, because the two streams that use this carry different id types: the OpenFGA
     * outbox's unit of work is an integer row id, while the source-data publisher's is a batch id,
     * which is a UUID. Validating and narrowing the value is the caller's job — this layer no
     * longer knows what a valid id looks like.
     *
     * @param callable(string): void $process
     */
    public function readOnce(int $blockMs, callable $process): void;
}
```

- [ ] **Step 5: Generalise `RedisStreamConsumer`**

Add the constructor parameter:

```php
        ?LoggerInterface $logger = null,
        /**
         * Which message field carries the id. `row_id` for the OpenFGA outbox, `batch_id` for the
         * source-data publish stream.
         */
        private readonly string $payloadField = 'row_id',
```

In both `readOnce()` and `claimStale()`, replace the `$rowId = isset($payload['row_id']) ? (int) $payload['row_id'] : 0;`
pattern and its `<= 0` test with:

```php
            $id = $payload[$this->payloadField] ?? null;
            if (!is_string($id) || '' === $id) {
                // Unprocessable by ANY caller — there is no id at all — so ACK it rather than let
                // it redeliver forever. Deciding whether a PRESENT id is valid belongs to the
                // caller, which is the only layer that knows what shape it should be.
                $this->logger->warning('outbox.consumer.bad_message', ['msg_id' => $msgId, 'payload' => $payload]);
                $ackIds[] = $msgId;
                continue;
            }

            try {
                $process($id);
                $ackIds[] = $msgId;
            } catch (\Throwable $e) {
                // Leave the message in the PEL; XCLAIM on the next pass picks it up.
                $this->logger->error('outbox.consumer.process_failed', [
                    'msg_id' => $msgId,
                    'id'     => $id,
                    'error'  => $e->getMessage(),
                ]);
            }
```

Keep `claimStale()`'s existing "log the XCLAIM at warning" behaviour, adjusting its context key from `row_id`
to `id` for the same reason.

- [ ] **Step 6: Move the cast and the guard into `ConsumerLoop`**

```php
        $this->consumer->readOnce(
            $this->blockMs,
            function (string $id): void {
                // The stream layer hands over a raw string, because the publish stream on the other
                // side of the same interface carries UUIDs. The outbox's unit of work is an integer
                // row id, so narrowing it — and rejecting anything that is not a positive integer —
                // is this layer's job now.
                if (!ctype_digit($id) || (int) $id <= 0) {
                    return;
                }

                $rowId       = (int) $id;
                $disposition = $this->processor->processOne($rowId);
                if ($disposition === OutboxDisposition::BENIGN_SUCCESS && $this->cascade !== null) {
                    try {
                        $this->cascade->evaluate($rowId);
                    } catch (\Throwable) {
                        // Never fail the consumer over a cascade decision — the row is already in
                        // succeeded state and a future sibling success (or admin re-revoke) will
                        // trigger evaluate() again.
                    }
                }
            },
        );
```

- [ ] **Step 7: Run the whole Outbox suite**

Run: `vendor/bin/phpunit phpunit_tests/Services/Outbox/`

Expected: PASS, with at least the count from Step 1 plus the new cases. A DROP in passing tests means a
regression in the live OpenFGA consumer — stop and fix it before moving on.

- [ ] **Step 8: Static analysis and style**

Run: `composer analyse && composer lint`

Expected: clean.

- [ ] **Step 9: Commit**

```bash
git add src/Services/Outbox/StreamConsumerInterface.php src/Services/Outbox/RedisStreamConsumer.php \
        src/Services/Outbox/ConsumerLoop.php phpunit_tests/Services/Outbox/
git commit -m "refactor(outbox): the stream seam carries a raw id, not an outbox row id"
```

---

### Task 12: `PublishConsumerLoop`

**Files:**

- Create: `src/Services/SourceData/PublishConsumerLoop.php`
- Test: `phpunit_tests/Services/SourceData/PublishConsumerLoopTest.php`

**Interfaces:**

- Consumes: Task 11's `StreamConsumerInterface`; `PublishRunner`; Task 6/8's `MergePollRunner`.
- Produces: `PublishConsumerLoop::__construct(StreamConsumerInterface $consumer, PublishRunner $publisher,
  ?MergePollRunner $mergePoller = null, int $blockMs = 5000, int $mergePollIntervalSeconds = 60,
  ?LoggerInterface $logger = null)`, with `tick(): void` and `run(): never`.

`ConsumerLoop` is not reused: it is constructor-typed to `OutboxProcessorInterface`. This is its sibling, with
the same `tick()` / `run()` split so the loop body stays unit-testable and `run()` stays out of coverage.

- [ ] **Step 1: Write the failing tests**

```php
<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Services\SourceData;

use LiturgicalCalendar\Api\Services\Outbox\StreamConsumerInterface;
use LiturgicalCalendar\Api\Services\SourceData\PublishConsumerLoop;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * A stream consumer that replays a fixed script of messages, one `readOnce()` at a time. An empty
 * array for a tick means "blocked and nothing arrived" — the idle tick.
 */
final class ScriptedStreamConsumer implements StreamConsumerInterface
{
    public int $ensureGroupCalls = 0;

    /** @param list<list<string>> $script */
    public function __construct(private array $script)
    {
    }

    public function ensureGroup(): void
    {
        $this->ensureGroupCalls++;
    }

    public function readOnce(int $blockMs, callable $process): void
    {
        foreach (array_shift($this->script) ?? [] as $id) {
            $process($id);
        }
    }
}

#[CoversClass(PublishConsumerLoop::class)]
final class PublishConsumerLoopTest extends TestCase
{
    public function testAMessageTriggersAPublishRun(): void
    {
        $publisher = $this->spyPublishRunner();
        $loop      = new PublishConsumerLoop(new ScriptedStreamConsumer([['batch-1']]), $publisher);

        $loop->tick();

        self::assertSame(1, $publisher->runs);
    }

    /**
     * The message is a HINT, never a work item. The consumer must claim from Postgres, so the batch
     * id it was handed is never passed to the publisher — a duplicated or out-of-order message then
     * costs at most one claim against an empty queue.
     */
    public function testTheBatchIdIsNeverHandedToThePublisher(): void
    {
        $publisher = $this->spyPublishRunner();
        $loop      = new PublishConsumerLoop(new ScriptedStreamConsumer([['batch-1']]), $publisher);

        $loop->tick();

        self::assertSame([], $publisher->batchIdsReceived);
    }

    public function testEnsureGroupRunsOnceAcrossManyTicks(): void
    {
        $consumer = new ScriptedStreamConsumer([[], [], []]);
        $loop     = new PublishConsumerLoop($consumer, $this->spyPublishRunner());

        $loop->tick();
        $loop->tick();
        $loop->tick();

        self::assertSame(1, $consumer->ensureGroupCalls);
    }

    /**
     * `blockMs` is 5000, so an unrated idle tick would poll GitHub 720 times an hour to watch for a
     * transition nobody is waiting on.
     */
    public function testTheIdleMergePollIsRateLimited(): void
    {
        $poller = $this->spyMergePollRunner();
        $loop   = new PublishConsumerLoop(
            new ScriptedStreamConsumer([[], [], []]),
            $this->spyPublishRunner(),
            $poller,
            blockMs: 0,
            mergePollIntervalSeconds: 3600
        );

        $loop->tick();
        $loop->tick();
        $loop->tick();

        self::assertSame(1, $poller->runs, 'three idle ticks, one poll');
    }

    public function testAMergePollFailureDoesNotKillTheConsumer(): void
    {
        $poller = $this->spyMergePollRunner(new \RuntimeException('GitHub down'));
        $loop   = new PublishConsumerLoop(new ScriptedStreamConsumer([[]]), $this->spyPublishRunner(), $poller);

        $loop->tick();

        self::assertTrue(true, 'tick() returned rather than propagating');
    }

    public function testAPublishRunFailureDoesNotKillTheConsumer(): void
    {
        $publisher = $this->spyPublishRunner(new \RuntimeException('database down'));
        $loop      = new PublishConsumerLoop(new ScriptedStreamConsumer([['batch-1']]), $publisher);

        $loop->tick();

        self::assertTrue(true, 'tick() returned rather than propagating');
    }
}
```

`spyPublishRunner()` / `spyMergePollRunner()` return subclasses of `PublishRunner` / `MergePollRunner` that
count `runOnce()` calls, record any batch id they are handed, and optionally throw. Neither class is `final`,
so subclassing works; if either has been made `final` by the time you get here, extract a one-method interface
rather than weakening the class.

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit phpunit_tests/Services/SourceData/PublishConsumerLoopTest.php`

Expected: FAIL — `Class "…\PublishConsumerLoop" not found`.

- [ ] **Step 3: Write the loop**

```php
<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services\SourceData;

use LiturgicalCalendar\Api\Services\Outbox\StreamConsumerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Long-lived consumer for the source-data publish stream.
 *
 * # The message is a hint, not a work item
 *
 * A message says WHEN to look; Postgres says WHAT is claimable and by whom. So the batch id is
 * used only for logging and {@see PublishRunner::runOnce()} does the ordinary claim, exactly as
 * cron does. Three consequences, all of them the point:
 *
 * - A lost `XADD` costs latency, never correctness — the cron backstop finds the batch.
 * - A duplicate or out-of-order message costs one wasted claim against an empty queue.
 * - This class inherits every guarantee phase 2 built (the claim protocol, bounded attempts,
 *   parking, stop-don't-hammer) without reimplementing any of it.
 *
 * # Nothing here may kill the consumer
 *
 * `PublishRunner` and `MergePollRunner` already catch everything they can and report it in their
 * result objects, so an exception reaching this loop is unexpected by construction — which is
 * exactly why it must be caught rather than allowed to end a process that is meant to stay up.
 * systemd would restart it, but a crash loop against a failing database is the hammering both
 * runners exist to avoid.
 *
 * # The idle tick, and why it is rate-limited
 *
 * Merge detection has no event to wake on: nothing at this deployment knows a reviewer clicked
 * Merge. It runs on the idle tick instead — a `readOnce()` that blocked and returned nothing. With
 * `blockMs` at 5000 that is every five seconds, or 720 GitHub polls an hour for a transition nobody
 * is waiting on, so it is rate-limited to `$mergePollIntervalSeconds`. Cron polls it too; this
 * only shortens the wait.
 *
 * Not a reuse of {@see \LiturgicalCalendar\Api\Services\Outbox\ConsumerLoop}, which is
 * constructor-typed to `OutboxProcessorInterface`. Its sibling, sharing the `tick()` / `run()`
 * split that keeps the loop body unit-testable.
 */
final class PublishConsumerLoop
{
    private bool $groupEnsured = false;

    private ?int $lastMergePollAt = null;

    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly StreamConsumerInterface $consumer,
        private readonly PublishRunner $publisher,
        /** Null disables the idle poll; the cron entry point still runs it. */
        private readonly ?MergePollRunner $mergePoller = null,
        private readonly int $blockMs = 5000,
        private readonly int $mergePollIntervalSeconds = 60,
        ?LoggerInterface $logger = null
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public function tick(): void
    {
        if (!$this->groupEnsured) {
            $this->consumer->ensureGroup();
            $this->groupEnsured = true;
        }

        $woken = false;

        $this->consumer->readOnce(
            $this->blockMs,
            function (string $batchId) use (&$woken): void {
                $woken = true;
                $this->logger->info(
                    'Woken by an approved source-data batch; claiming from the database.',
                    ['batch_id' => $batchId]
                );

                try {
                    $result = $this->publisher->runOnce();
                    $this->logger->info('Stream-driven publish run finished.', [
                        'published'          => $result->published,
                        'stopped_on_failure' => $result->stoppedOnFailure,
                        'parked'             => $result->parkedBatches,
                    ]);
                } catch (\Throwable $e) {
                    // PublishRunner catches everything it can, so reaching here is unexpected —
                    // which is why it must not end the process. See the class docblock.
                    $this->logger->error('Stream-driven publish run threw; the consumer stays up.', [
                        'batch_id'  => $batchId,
                        'exception' => $e::class,
                        'message'   => $e->getMessage(),
                    ]);
                }
            },
        );

        if (!$woken) {
            $this->pollMergesIfDue();
        }
    }

    private function pollMergesIfDue(): void
    {
        if (null === $this->mergePoller) {
            return;
        }

        $now = time();
        if (null !== $this->lastMergePollAt && ( $now - $this->lastMergePollAt ) < $this->mergePollIntervalSeconds) {
            return;
        }
        $this->lastMergePollAt = $now;

        try {
            $result = $this->mergePoller->runOnce();
            if ($result->merged > 0 || $result->closed > 0 || $result->reset > 0) {
                $this->logger->info('Idle-tick merge poll settled some batches.', [
                    'merged' => $result->merged,
                    'closed' => $result->closed,
                    'reset'  => $result->reset,
                ]);
            }
        } catch (\Throwable $e) {
            $this->logger->error('Idle-tick merge poll threw; the consumer stays up.', [
                'exception' => $e::class,
                'message'   => $e->getMessage(),
            ]);
        }
    }

    /**
     * Forever. systemd restarts on crash.
     *
     * @codeCoverageIgnore
     */
    public function run(): never
    {
        while (true) {
            $this->tick();
        }
    }
}
```

- [ ] **Step 4: Run the tests**

Run: `vendor/bin/phpunit phpunit_tests/Services/SourceData/PublishConsumerLoopTest.php`

Expected: PASS.

- [ ] **Step 5: Static analysis and style**

Run: `composer analyse && composer lint`

Expected: clean.

- [ ] **Step 6: Commit**

```bash
git add src/Services/SourceData/PublishConsumerLoop.php \
        phpunit_tests/Services/SourceData/PublishConsumerLoopTest.php
git commit -m "feat(sourcedata): a stream consumer that wakes the publisher, with an idle merge poll"
```

---

### Task 13: One factory, three entry points

**Files:**

- Create: `src/Services/SourceData/SourceDataPublisherFactory.php`, `scripts/poll-sourcedata-merges.php`,
  `bin/publish-sourcedata-consumer`
- Modify: `scripts/publish-sourcedata.php`
- Test: `phpunit_tests/Services/SourceData/SourceDataPublisherFactoryTest.php` (create),
  `phpunit_tests/Services/SourceData/PublishSourceDataScriptTest.php`,
  `phpunit_tests/Services/SourceData/PollSourceDataMergesScriptTest.php` (create)

**Interfaces:**

- Consumes: everything above.
- Produces: `SourceDataPublisherFactory` with
  `logger(string $channel): LoggerInterface`, `repository(): SourceDataChangeRequestRepository`,
  `publishRunner(LoggerInterface $logger): PublishRunner`, `mergePollRunner(LoggerInterface $logger): MergePollRunner`,
  and `publishNotifier(): SourceDataPublishNotifier`.

**Why this exists.** Phase 2's most expensive defect was a script CONSTRUCTING what every test INJECTED: the
cron entry took `LoggerFactory::create()`'s default processors, which throw on non-request context, so every log
line the runner wrote would have thrown in production — including the ones inside its catch blocks, before
`releaseClaim()` ran. Every test passed; no test crossed that seam. Phase 3 adds two more entry points to the
same wiring. Moving it into `src/` also brings it under `composer analyse`, which scans `paths: [src]` only.

- [ ] **Step 1: Write the failing tests**

`phpunit_tests/Services/SourceData/SourceDataPublisherFactoryTest.php`:

```php
/**
 * The defect this factory exists to prevent, pinned directly. LoggerFactory's default attaches
 * RequestResponseProcessor, which THROWS on any record whose context lacks type => request|response
 * — and the runners log batch ids. If this ever regresses, every log call in production throws,
 * including the ones inside the catch blocks, stranding the batch.
 */
public function testTheLoggerDoesNotThrowOnARunnerShapedRecord(): void
{
    $logger = ( new SourceDataPublisherFactory() )->logger('publish-sourcedata-test');

    $logger->info('a runner-shaped record', ['batch_id' => 'batch-1', 'exception' => 'RuntimeException']);

    self::assertTrue(true, 'no exception was thrown');
}

public function testMergePollRunnerIsBuiltWhenTheGithubAppIsConfigured(): void
{
    $this->withGithubAppEnv(function (): void {
        $factory = new SourceDataPublisherFactory();
        self::assertInstanceOf(
            MergePollRunner::class,
            $factory->mergePollRunner($factory->logger('poll-sourcedata-merges-test'))
        );
    });
}

/**
 * GITHUB_REPOSITORY is a GitHub Actions built-in injected into every job as owner/repo. Clearing
 * only $_ENV leaves getenv() serving it, which passes locally and fails CI — every time.
 */
public function testAMalformedRepositoryIsRejectedRatherThanFatal(): void
{
    $this->withGithubAppEnv(function (): void {
        $_ENV['GITHUB_REPOSITORY'] = 'https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI';
        putenv('GITHUB_REPOSITORY=https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI');

        $factory = new SourceDataPublisherFactory();

        $this->expectException(\InvalidArgumentException::class);
        $factory->mergePollRunner($factory->logger('poll-sourcedata-merges-test'));
    });
}

public function testPublishNotifierIsANoOpWithoutRedisConfiguration(): void
{
    unset($_ENV['REDIS_SOCKET'], $_ENV['REDIS_HOST']);
    putenv('REDIS_SOCKET');
    putenv('REDIS_HOST');

    // Must not throw, must not connect.
    ( new SourceDataPublisherFactory() )->publishNotifier()->notify('batch-1');

    self::assertTrue(true);
}
```

`withGithubAppEnv()` sets `GITHUB_APP_ID`, `GITHUB_APP_INSTALLATION_ID`, `GITHUB_APP_PRIVATE_KEY_PATH` and
`GITHUB_REPOSITORY` in **both** `$_ENV` and `putenv()`, runs the closure, and restores both in a `finally`.

`phpunit_tests/Services/SourceData/PollSourceDataMergesScriptTest.php` mirrors `PublishSourceDataScriptTest`:
run the real script with `proc_open`, assert on its exit code and its summary line.

```php
public function testItExitsOneAndSaysWhyWhenTheGithubAppIsUnconfigured(): void
{
    [$stdout, $stderr, $exit] = $this->runScript([], env: ['GITHUB_REPOSITORY' => '']);

    self::assertSame(1, $exit);
    self::assertStringContainsString('not configured', $stderr);
}

public function testItReportsASummaryLine(): void
{
    [$stdout, , $exit] = $this->runScript([], env: $this->configuredGithubEnv());

    self::assertMatchesRegularExpression(
        '/^poll-sourcedata-merges merged=\d+ closed=\d+ reset=\d+ unpollable=\d+ stopped_on_failure=(true|false)$/m',
        $stdout
    );
    self::assertContains($exit, [0, 1]);
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run:

```bash
vendor/bin/phpunit phpunit_tests/Services/SourceData/SourceDataPublisherFactoryTest.php
vendor/bin/phpunit phpunit_tests/Services/SourceData/PollSourceDataMergesScriptTest.php
```

Expected: FAIL — the factory class and the script do not exist.

- [ ] **Step 3: Write the factory**

Move the wiring currently inline in `scripts/publish-sourcedata.php` into
`src/Services/SourceData/SourceDataPublisherFactory.php` verbatim, preserving every comment that explains WHY —
the `includeProcessors: false` argument, the umask window around the token cache, the explicit Guzzle timeouts
and their relationship to `PublishRunner::DEFAULT_GRACE_SECONDS`. Those comments are the record of what went
wrong; do not summarise them away in the move.

```php
    /**
     * `includeProcessors: false` — the sixth argument, and load-bearing rather than cosmetic.
     * LoggerFactory's default attaches RequestResponseProcessor, which THROWS a RuntimeException for
     * any record whose context does not carry type => request|response. The runners log batch ids,
     * so with the default every log call they make — including the ones inside their catch blocks —
     * would throw from inside the failure handling, before releaseClaim() ever ran, stranding the
     * batch and killing the process.
     */
    public function logger(string $channel): LoggerInterface
    {
        return LoggerFactory::create($channel, null, 30, false, true, false);
    }
```

`publishNotifier()` resolves `\Redis` from `REDIS_SOCKET` / `REDIS_HOST` / `REDIS_PORT` / `REDIS_PASSWORD`,
returning a notifier over a null `\Redis` when `ext-redis` is missing or neither host nor socket is set —
never throwing, because Redis is optional.

`mergePollRunner()` builds the same Guzzle client and token cache the publisher uses and constructs
`MergePollRunner` with the repository, a `GitHubGitDataClient`, an optional
`ResourceTuplePurgeServiceInterface` (null when OpenFGA is unconfigured) and an `AuditLogRepository`.

- [ ] **Step 4: Rewire `scripts/publish-sourcedata.php`**

Replace the inline wiring with factory calls. **The CLI contract does not change** — `[limit]` positional,
same exit codes, same `publish-sourcedata published=N stopped_on_failure=B parked=N` summary line — because
`PublishSourceDataScriptTest` pins it and the runbook documents it. Update the file's header docblock to say
its ROLE is now the backstop behind the stream consumer, catching a lost `XADD` or a dead consumer.

- [ ] **Step 5: Write `scripts/poll-sourcedata-merges.php`**

Same shape as `publish-sourcedata.php`: CLI-only guard, Dotenv chain over the same six files, logger first,
factory, wrapped run, summary line, exit code. Its docblock must state:

```text
 * Exit codes:
 *   0  Every open pull request was polled, or there were none.
 *   1  Misconfiguration (GitHub App or GITHUB_REPOSITORY unset OR malformed — GITHUB_REPOSITORY
 *      must be exactly "owner/repo"), a database failure, OR a poll failed and the run stopped
 *      early.
 *
 * The summary line also reports `reset=N` and `unpollable=N`. `reset` counts batches whose pull
 * request merged WITHOUT them (a review that landed concurrently with a publish); they are
 * claimable again and the next publish opens a fresh pull request carrying them, so this is not a
 * failure — but a value that keeps climbing means publishes and merges are racing routinely.
 * `unpollable` counts `open` batches with no pull request number, which should always be zero;
 * a non-zero value is an unexplained state that needs an operator, and it does NOT affect the
 * exit code, so monitor the line and GET /health, not the exit code alone.
```

Catch `\Throwable` around the factory call, not `\RuntimeException`: `fromEnv()` throws
`InvalidArgumentException` — a `LogicException`, not a `RuntimeException` — for a malformed
`GITHUB_REPOSITORY`, which is one pasted repository URL away for any operator. A narrower catch is what made
phase 2's publisher exit 255 with nothing logged past "run starting".

- [ ] **Step 6: Write `bin/publish-sourcedata-consumer`**

Mirror `bin/reconcile-outbox`'s structure: CLI guard, the same Dotenv chain, an `ext-redis` check that exits 2
with a clear message, `\Redis` connection from `REDIS_SOCKET` or `REDIS_HOST`/`REDIS_PORT` plus optional
`REDIS_PASSWORD`, then:

```php
$streamName   = (string) ($_ENV['REDIS_SOURCEDATA_PUBLISH_STREAM']   ?? 'litcal:sourcedata-publish-stream');
$groupName    = (string) ($_ENV['REDIS_SOURCEDATA_PUBLISH_GROUP']    ?? 'sourcedata-publisher');
$consumerName = (string) ($_ENV['REDIS_SOURCEDATA_PUBLISH_CONSUMER'] ?? (gethostname() ?: 'consumer'));

$factory = new SourceDataPublisherFactory();
$logger  = $factory->logger('publish-sourcedata-consumer');

$stream = new RedisStreamConsumer($redis, $streamName, $groupName, $consumerName, $logger, 'batch_id');
$loop   = new PublishConsumerLoop(
    $stream,
    $factory->publishRunner($logger),
    $factory->mergePollRunner($logger),
    blockMs: 5000,
    logger: $logger
);

$loop->run();
```

Note the sixth `RedisStreamConsumer` argument: `'batch_id'`, not the `row_id` default. Getting that wrong makes
every message look malformed and get ACKed away — silently, since the consumer would report "bad message" and
carry on.

- [ ] **Step 7: RUN all three entry points for real**

This step is not optional and is not covered by any unit test. Two defects shipped in phase 2 with entirely
green suites because a script constructed what tests injected.

```bash
php scripts/publish-sourcedata.php 1 ; echo "exit=$?"
php scripts/poll-sourcedata-merges.php ; echo "exit=$?"
timeout 12 php bin/publish-sourcedata-consumer ; echo "exit=$?"
tail -n 40 logs/publish-sourcedata*.log logs/poll-sourcedata-merges*.log
```

Expected: each prints its summary line (or a clear configuration error) and exits 0 or 1 — never 255, never a
stack trace. The consumer stays up for the full 12 seconds and is killed by `timeout` (exit 124). Confirm the
log files exist, are readable (not 0600 — the umask window must cover only the token cache), and contain a
`run starting` record. **A log file that is empty or missing means the logger is throwing.**

- [ ] **Step 8: PHPStan the scripts standalone**

`phpstan.neon.dist` scans `paths: [src]` only, so CI will not check these:

```bash
composer analyse
vendor/bin/phpstan analyse --level=10 scripts/publish-sourcedata.php scripts/poll-sourcedata-merges.php bin/publish-sourcedata-consumer
composer lint && composer parallel-lint
```

Expected: clean.

- [ ] **Step 9: Run the full suite**

Run: `composer test`

Expected: no failures, and no drop against the phase-2 baseline of 4,136 tests.

- [ ] **Step 10: Commit**

```bash
git add src/Services/SourceData/SourceDataPublisherFactory.php scripts/publish-sourcedata.php \
        scripts/poll-sourcedata-merges.php bin/publish-sourcedata-consumer \
        phpunit_tests/Services/SourceData/SourceDataPublisherFactoryTest.php \
        phpunit_tests/Services/SourceData/PollSourceDataMergesScriptTest.php \
        phpunit_tests/Services/SourceData/PublishSourceDataScriptTest.php
git commit -m "feat(sourcedata): one wiring factory behind cron, the merge poller and the consumer"
```

---

### Task 14: Health reports what is awaiting a merge decision

**Files:**

- Modify: `src/Health.php`
- Test: `phpunit_tests/HealthSourceDataPublisherTest.php`

**Interfaces:**

- Consumes: Task 5's `SourceDataChangeRequestRepository::openBatchStats()`.
- Produces: `Health::buildSourceDataPublisherStatus(): array{status: 'ok'|'warning', message: string,
  parked_batches: int, open_batches: int, oldest_open_age_seconds: int}` — two keys added, none removed.

- [ ] **Step 1: Write the failing tests**

Append to `phpunit_tests/HealthSourceDataPublisherTest.php`:

```php
public function testTheBlockReportsOpenBatchesAndTheOldestAge(): void
{
    $status = Health::buildSourceDataPublisherStatus();

    self::assertArrayHasKey('open_batches', $status);
    self::assertArrayHasKey('oldest_open_age_seconds', $status);
    self::assertIsInt($status['open_batches']);
    self::assertIsInt($status['oldest_open_age_seconds']);
}

/**
 * An open pull request is the ORDINARY state — a reviewer has not got to it yet — so it must not
 * alarm. Only the age carries a signal, and only past a threshold no review plausibly reaches.
 */
public function testAnOpenBatchIsNotItselfAWarning(): void
{
    $this->givenAnOpenBatchAgedSeconds(3600);

    $status = Health::buildSourceDataPublisherStatus();

    self::assertSame(1, $status['open_batches']);
    self::assertNotSame('warning', $status['status'], 'a pull request awaiting review is not a fault');
}

public function testAVeryOldOpenBatchWarnsWithoutBlamingTheReviewer(): void
{
    $this->givenAnOpenBatchAgedSeconds(Health::STALE_OPEN_BATCH_SECONDS + 60);

    $status = Health::buildSourceDataPublisherStatus();

    self::assertSame('warning', $status['status']);
    self::assertStringContainsString('poll-sourcedata-merges', $status['message']);
}

/**
 * The degradation rule the whole block already follows: a database that is down must not break the
 * endpoint monitoring relies on.
 */
public function testTheCountsDegradeToZeroWhenTheDatabaseIsUnreachable(): void
{
    $this->withUnreachableDatabase(function (): void {
        $status = Health::buildSourceDataPublisherStatus();

        self::assertSame(0, $status['open_batches']);
        self::assertSame(0, $status['oldest_open_age_seconds']);
    });
}
```

`givenAnOpenBatchAgedSeconds()` inserts a row at `publication_status = 'open'` with a backdated `updated_at`;
`withUnreachableDatabase()` follows whatever pattern the existing test in this file already uses to exercise
the `Connection::isConfigured()` guard.

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit phpunit_tests/HealthSourceDataPublisherTest.php`

Expected: FAIL — `assertArrayHasKey('open_batches', …)` fails; `Health::STALE_OPEN_BATCH_SECONDS` is undefined.

- [ ] **Step 3: Add the constant and the stats reader**

In `src/Health.php`, beside the existing publisher helpers:

```php
    /**
     * How long a batch may sit `open` before `/health` says something.
     *
     * Thirty days, deliberately generous, because this number does NOT measure a fault: a pull
     * request awaiting review is the ordinary state, and a reviewer who has not got to one in a
     * fortnight is a reviewer, not an outage. What it catches is the case where the age keeps
     * climbing because nothing is polling at all — no cron entry for
     * `scripts/poll-sourcedata-merges.php`, no consumer running — which is otherwise INVISIBLE:
     * a merged pull request whose merge is never detected looks exactly like an unreviewed one
     * from this side, and every editor is told their change is still awaiting review forever.
     *
     * The message says both readings out loud rather than accusing the poller, because at this
     * threshold either is genuinely possible.
     */
    public const STALE_OPEN_BATCH_SECONDS = 2_592_000;

    /**
     * Read through the same `Connection::isConfigured()` + catch-everything guard
     * {@see buildOutboxStats()} uses: a database that is down must degrade this block to zeroes,
     * not break the endpoint monitoring relies on.
     *
     * @return array{open_batches: int, oldest_open_age_seconds: int}
     */
    private static function openChangeRequestBatchStats(): array
    {
        if (!Connection::isConfigured()) {
            return ['open_batches' => 0, 'oldest_open_age_seconds' => 0];
        }

        try {
            return ( new SourceDataChangeRequestRepository(Connection::getInstance()) )->openBatchStats();
        } catch (\Throwable) {
            return ['open_batches' => 0, 'oldest_open_age_seconds' => 0];
        }
    }
```

- [ ] **Step 4: Fold the two keys into every return path**

`buildSourceDataPublisherStatus()` has four `return` statements. **Every one** must carry the new keys, or a
deployment in the unconfigured-publisher state — the one this block exists to catch — would answer without
them and break a client that reads them unconditionally. Read the stats once at the top, beside `$parked`:

```php
        $queueModeOn = SourceDataWriteMode::changeRequestsEnabled();
        $configured  = SourceDataPublisher::isConfigured();
        $parked      = self::parkedChangeRequestBatches();
        $open        = self::openChangeRequestBatchStats();
```

then add `...$open,` to each returned array, and insert the new branch AFTER the existing `$parked > 0` branch
(an unconfigured publisher and a parked batch are both more actionable than a stale poll):

```php
        if ($open['oldest_open_age_seconds'] > self::STALE_OPEN_BATCH_SECONDS) {
            return [
                'status'         => 'warning',
                'message'        => sprintf(
                    'A source data change request batch has been awaiting a merge decision for %d days. Either a '
                        . 'reviewer has not reached its pull request, or nothing is detecting merges — check that '
                        . 'scripts/poll-sourcedata-merges.php runs on a schedule, or that the publish consumer is up. '
                        . 'An undetected merge is invisible from this side: it looks exactly like an unreviewed one, '
                        . 'and every editor waiting on it is told it is still open',
                    intdiv($open['oldest_open_age_seconds'], 86400)
                ),
                'parked_batches' => $parked,
                ...$open,
            ];
        }
```

Update the method's `@return` shape to include both new keys.

- [ ] **Step 5: Run the tests**

Run:

```bash
vendor/bin/phpunit phpunit_tests/HealthSourceDataPublisherTest.php
vendor/bin/phpunit --filter Health
```

Expected: PASS, with no regression in the other Health tests.

- [ ] **Step 6: Static analysis and style**

Run: `composer analyse && composer lint`

Expected: clean.

- [ ] **Step 7: Commit**

```bash
git add src/Health.php phpunit_tests/HealthSourceDataPublisherTest.php
git commit -m "feat(health): report batches awaiting a merge decision, and a poll that has stopped"
```

---

### Task 15: Operator documentation

**Files:**

- Modify: `docs/ops/change-request-runbook.md`, `.env.example`, `CHANGELOG.md`,
  `docs/superpowers/2026-08-30-phase-3-handoff.md`

**Interfaces:**

- Consumes: everything above.
- Produces: no code. This task is what makes phase 3 operable by somebody who did not build it.

- [ ] **Step 1: Add the new environment variables**

In `.env.example`, beside the existing commented Redis block and the `REDIS_OUTBOX_*` group:

```bash
# --- Source-data publish stream (optional) -----------------------------------
# Latency only. The queue of record stays in Postgres: an approved batch is durable there and
# scripts/publish-sourcedata.php is the backstop, so with Redis absent everything still publishes —
# it just waits for the next cron tick instead of waking in under a second.
REDIS_SOURCEDATA_PUBLISH_STREAM=litcal:sourcedata-publish-stream
REDIS_SOURCEDATA_PUBLISH_GROUP=sourcedata-publisher
REDIS_SOURCEDATA_PUBLISH_CONSUMER=          # default: hostname
```

- [ ] **Step 2: Extend the runbook**

Add a `## Merge detection (phase 3)` section after `### Exit codes and monitoring`, covering:

1. **The lifecycle, completed.** `none` → `queued` → `open` → `merged` | `closed`, and what writes each.
2. **The two cron entries**, with real crontab lines:

   ```cron
   */5 * * * * cd /path/to/api && php scripts/publish-sourcedata.php >> logs/cron-publish.log 2>&1
   */5 * * * * cd /path/to/api && php scripts/poll-sourcedata-merges.php >> logs/cron-poll.log 2>&1
   ```

3. **The consumer as an optional accelerator**, with a systemd unit mirroring the outbox consumer's, and the
   explicit statement that it is optional: cron alone is a complete, correct deployment.
4. **`reset=N` on the poll summary line** — what a concurrent merge is, why the batch is republished rather
   than marked merged, and why a value that keeps climbing means publishes and merges are racing routinely.
5. **`unpollable=N`** — should always be zero; a non-zero value is an unexplained state. Include the SQL:

   ```sql
   SELECT batch_id, resource_type, resource_id, commit_sha, updated_at
     FROM sourcedata_change_requests
    WHERE publication_status = 'open' AND pr_number IS NULL;
   ```

6. **Replace the "Known limitation: a deleted resource's editors keep access" section.** It is now closed.
   State what closed it (the merge-time purge), what the trigger is (`metadata.deletes_resource`, NOT
   `operation = 'delete'`), and that `admin` tuples deliberately survive so a recreated resource id keeps its
   owner. Include the SQL to find a deletion batch that merged:

   ```sql
   SELECT DISTINCT batch_id, resource_type, resource_id, merge_commit_sha, publication_settled_at
     FROM sourcedata_change_requests
    WHERE publication_status = 'merged'
      AND metadata->>'deletes_resource' = 'true'
    ORDER BY publication_settled_at DESC;
   ```

7. **Un-parking and claim tokens.** The existing "Parked batches" SQL clears `publish_attempts`; note that
   `publish_claim_token` is cleared by the same recovery and that a row `queued` with a token older than the
   grace period is reclaimed automatically.

- [ ] **Step 3: Add the CHANGELOG entry**

Follow the file's existing format. Cover: merge detection with containment verification; the OpenFGA purge for
merged deletions (closing the known limitation phase 2 documented); claim ownership; change-request
notifications on `GET /auth/notifications`, calling out that `items` is now a **discriminated** list and
clients must switch on `type`; the optional Redis publish stream; two new `/health` keys. The notifications
shape change is the only one a client can trip over — say so plainly.

- [ ] **Step 4: Update the phase-3 handoff document**

`docs/superpowers/2026-08-30-phase-3-handoff.md` currently says phase 3 is "not started" and lists five
obligations. Update the table, mark obligations 1–3 done with the commits that closed them, and leave 4
(per-file `base_sha`) and 5 (schema re-validation at the approval gate) as the two that remain — each needs
its own issue. Add what phase 3 learned, in the same "traps this project has already paid for" spirit:

- `operation = 'delete'` does not mean a resource was deleted. `RegionalDataHandler::writeI18nFiles()` stages a
  DELETE per dropped locale on a calendar that still exists, so keying authorization changes on the operation
  revokes every editor on a live calendar because a translator removed a language.
- Sharing a pull request number is not being in its merge. A reviewer merging concurrently with a publish
  separates them, and marking the overtaken batch `merged` loses its content silently.

- [ ] **Step 5: Lint the markdown**

Run: `composer lint:md`

Expected: 0 issues. If MD060 fires on a table, align its pipes by hand — `lint:md:fix` does not fix alignment.

- [ ] **Step 6: File the two follow-up issues**

```bash
gh issue create --repo Liturgical-Calendar/LiturgicalCalendarAPI \
  --title "Keep per-file base_sha so rebase detection is possible" \
  --body "Deferred from phase 3 (#902). recordPublication() overwrites every row's base_sha with the batch-level branch head, destroying the per-file bookkeeping a rebase check needs. See docs/superpowers/specs/2026-08-30-sourcedata-merge-detection-design.md, Scope."

gh issue create --repo Liturgical-Calendar/LiturgicalCalendarAPI \
  --title "Re-validate a change request against the current schema before publishing" \
  --body "Deferred from phase 3 (#902). approveBatch() is a single status UPDATE, so a batch approved against one schema and published after that schema changed produces a pull request whose CI fails lint:jsondata — a backstop on the wrong side of the gate. See docs/superpowers/specs/2026-08-30-sourcedata-merge-detection-design.md, Scope."
```

- [ ] **Step 7: Commit**

```bash
git add docs/ops/change-request-runbook.md .env.example CHANGELOG.md docs/superpowers/2026-08-30-phase-3-handoff.md
git commit -m "docs(sourcedata): operate phase 3 — merge detection, the purge, and the publish stream"
```

---

## Final verification

Run before opening the pull request. Nothing here is optional, and two of the four steps exist because a green
suite has already lied to this project twice.

- [ ] **Full suite, no drop against the phase-2 baseline**

```bash
composer test
```

Expected: 0 failures, and no fewer than phase 2's 4,136 tests.

- [ ] **Static analysis, including the parts CI does not scan**

```bash
composer analyse
vendor/bin/phpstan analyse --level=10 scripts/publish-sourcedata.php scripts/poll-sourcedata-merges.php bin/publish-sourcedata-consumer
composer lint && composer parallel-lint && composer lint:md && composer lint:jsondata
```

Expected: clean. `phpstan.neon.dist` scans `paths: [src]` only — the standalone run is the only thing checking
the three entry points.

- [ ] **Run the entry points, do not merely test them**

```bash
php scripts/publish-sourcedata.php 1 ; echo "exit=$?"
php scripts/poll-sourcedata-merges.php ; echo "exit=$?"
timeout 12 php bin/publish-sourcedata-consumer ; echo "exit=$?"
ls -l logs/publish-sourcedata*.log logs/poll-sourcedata-merges*.log
```

Expected: summary lines or clear configuration errors; exit 0 or 1, never 255 and never a stack trace; the
consumer survives its 12 seconds. **An empty or missing log file means the logger is throwing** — the exact
defect `SourceDataPublisherFactory` exists to prevent.

- [ ] **Migration round-trip**

```bash
vendor/bin/doctrine-migrations migrate prev --no-interaction
vendor/bin/doctrine-migrations migrate --no-interaction
```

Expected: both succeed. A `down()` that leaves an index behind fails the second `migrate`.

- [ ] **Open the pull request**

```bash
gh pr create --base development --title "feat(data): merge detection and stream publishing — phase 3"
```

`--base development`. Never `main`.

The body must cover, because a reviewer cannot infer any of them from the diff:

- **Why containment is verified rather than assumed** — a reviewer merging concurrently with a publish leaves a
  batch on the branch and outside the merge, and marking it `merged` loses its content silently.
- **Why `operation = 'delete'` is not the purge trigger** — the i18n locale-removal path stages deletes on a
  calendar that still exists.
- **That `GET /auth/notifications` `items` is now a discriminated list** — the one change a client can trip over.
- **That Redis is optional** and cron alone remains a complete deployment.
- **What was deferred** and to which issues.

## What this plan does not do

Named here so a reviewer does not read their absence as an oversight:

- **Per-file `base_sha` and rebase detection**, and **schema re-validation at the approval gate** — deferred
  from phase 3, each filed as its own issue in Task 15.
- **The frontend inbox UI.** `GET /auth/notifications` carries `change_request_published` items after this;
  rendering them is a follow-up issue in `LiturgicalCalendarFrontend`.
- **Moving the claim protocol into Redis consumer groups.** #915 excludes it explicitly: it would make Redis
  mandatory and break a self-hoster running cron only.
- **Notifying on review decisions** (approve, reject at the gate). Equally unnotified today, but a phase-1 gap
  rather than something phase 3 produces.
