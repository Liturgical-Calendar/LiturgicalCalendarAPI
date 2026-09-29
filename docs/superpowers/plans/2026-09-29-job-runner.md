# One Job Runner Implementation Plan (#1008, with #1015 first)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement
> this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace two consumer units and four cron lines with one supervised job runner whose schedule, leases and run history live in
Postgres and show in `/health`.

**Architecture:** `bin/litcal-jobs supervise` (one systemd unit) starts every job as a child process `bin/litcal-jobs run <job>`. The child
takes a lease row in `job_schedule`, runs the job, records the outcome and the next due time. Stream jobs (the two Redis consumers) are
long-lived children that renew their lease; interval jobs (backstops, sweeps) are short-lived children killed at their timeout.

**Tech Stack:** PHP 8.4, `ext-pcntl`/`ext-posix`, PDO pgsql, Doctrine Migrations, PHPUnit 12, POSIX sh for deploy scripts.

**Spec:** `docs/superpowers/specs/2026-09-29-job-runner-design.md`

## Global Constraints

- PHP 8.4, `declare(strict_types=1);` in every new PHP file; PSR-12 per `phpcs.xml`; single quotes unless interpolating.
- PHPStan level 10 over `src/` (`composer analyse`); every environment read lives in `src/`, not in `bin/` or `scripts/`.
- Namespace `LiturgicalCalendar\Api\…` for `src/`, `LiturgicalCalendar\Tests\…` for `phpunit_tests/`.
- All time comparisons use the database clock (`now()` in SQL). PHP code never compares its own clock to a stored timestamp.
- Exit codes: 0 success, 1 failed, 2 usage/configuration, 3 refused, 75 lease not acquired.
- Reserved job name `supervisor`. Job names match `^[a-z][a-z0-9-]*$`.
- Stream lease 60 s renewed every 15 s; interval lease = timeout + 30 s; supervisor lease 60 s renewed every 15 s; standby retry 15 s;
  shutdown wait 30 s; kill grace after SIGTERM 10 s; unit `TimeoutStopSec=45`.
- Stream restart backoff 1 s doubling to 60 s, reset after a child has run 5 minutes.
- `last_error` truncated to 2000 characters; never emitted by `/health`.
- `LITCAL_JOBS_DISABLED` is a comma-separated list of job names; whitespace trimmed, empty entries ignored.
- No new Composer dependency. No `@group slow` unless measured. Group attributes only (`#[Group(...)]`), never docblocks.
- Never mutate `jsondata/` in a test; use temp directories. Never `git checkout`/commit in the main checkout; work in the worktrees.
- Every markdown file passes `composer lint:md` (180-char lines, aligned tables).

## Review Focus

1. **A child that dies before taking its lease** (autoload fatal, bad config) must not be relaunched every second: an interval job is
   launched at most once per `min(interval, 60)` seconds by a supervisor, whatever its exit code. Test in Task B6.
2. **The database restarts under a running supervisor:** a renewal or query that throws keeps the children running and reconnects on
   the next pass instead of reusing the dead PDO. Test in Task B6.
3. **An operator runs `bin/litcal-jobs run <job>` while the supervisor's child is running it:** exit 75, nothing recorded, the running
   child's row untouched. Test in Task B3.
4. **A job fails with a huge or sensitive error message:** stored truncated to 2000 characters, absent from `/health`. Tests in B1, B8.
5. **`LITCAL_JOBS_DISABLED` with spaces, trailing commas or unknown names:** trimmed, empty ignored, unknown names logged once and
   otherwise harmless. Test in Task B6.

---

## Part A — #1015: guard and dry run for the resource-tuple sweep

Branch `fix/1015-tuple-sweep-guard` in worktree `../wt-1015-tuple-sweep-guard`, off `origin/development`. Its own PR.
Part B's branch is rebased onto this branch before Task B5.

### Task A1: `SourceTreeGuard`, shared by the membership seeder

**Files:**

- Create: `src/Services/SourceTreeGuard.php`
- Modify: `src/Services/WiderRegionMembershipSeeder.php` (`reconcile()`)
- Test: `phpunit_tests/Services/SourceTreeGuardTest.php`

**Interfaces:**

- Produces: `final class SourceTreeGuard { __construct(?string $nationsDir = null); nationsDir(): string; refusalReason(): ?string }`.
  `refusalReason()` returns null when at least one `{dir}/{N}/{N}.json` exists, else a sentence starting
  `No national calendar files found in`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Services;

use LiturgicalCalendar\Api\Services\SourceTreeGuard;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SourceTreeGuard::class)]
final class SourceTreeGuardTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/stg_' . uniqid();
        mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*/*') ?: [] as $f) {
            @unlink($f);
        }
        foreach (glob($this->dir . '/*') ?: [] as $d) {
            @rmdir($d);
        }
        @rmdir($this->dir);
    }

    public function testAnEmptyFolderIsRefused(): void
    {
        $reason = ( new SourceTreeGuard($this->dir) )->refusalReason();
        self::assertNotNull($reason);
        self::assertStringStartsWith('No national calendar files found in', $reason);
    }

    public function testAMissingFolderIsRefused(): void
    {
        self::assertNotNull(( new SourceTreeGuard($this->dir . '/nope') )->refusalReason());
    }

    public function testFoldersWithoutTheirFileAreRefused(): void
    {
        mkdir($this->dir . '/IT');
        self::assertNotNull(( new SourceTreeGuard($this->dir) )->refusalReason());
    }

    public function testOneNationalCalendarFileIsEnough(): void
    {
        mkdir($this->dir . '/IT');
        mkdir($this->dir . '/XX');
        file_put_contents($this->dir . '/IT/IT.json', '{}');
        self::assertNull(( new SourceTreeGuard($this->dir) )->refusalReason());
    }

    public function testTheRealTreeIsPopulated(): void
    {
        self::assertNull(( new SourceTreeGuard() )->refusalReason());
    }
}
```

- [ ] **Step 2: Run it to see it fail** — `vendor/bin/phpunit phpunit_tests/Services/SourceTreeGuardTest.php`; expect "Class … not found".

- [ ] **Step 3: Implement**

```php
<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services;

use LiturgicalCalendar\Api\Enum\JsonData;

/**
 * Refuses to let a destructive job act on a missing or half-written source tree.
 *
 * A sweep that prunes access for resources "no longer on disk" reads a missing tree (a deploy in
 * progress, a bad mount, the wrong working directory) as "every resource was deleted". This guard
 * is the one check every such job shares: at least one national calendar file must exist.
 */
final class SourceTreeGuard
{
    public function __construct(private readonly ?string $nationsDir = null)
    {
    }

    public function nationsDir(): string
    {
        return $this->nationsDir ?? JsonData::NATIONAL_CALENDARS_FOLDER->path();
    }

    /** Null when the tree is populated; otherwise why a destructive job must not run. */
    public function refusalReason(): ?string
    {
        $dir  = $this->nationsDir();
        $dirs = glob($dir . '/*', GLOB_ONLYDIR);
        foreach ($dirs === false ? [] : $dirs as $nationDir) {
            $nation = basename($nationDir);
            if (is_file("{$nationDir}/{$nation}.json")) {
                return null;
            }
        }

        return "No national calendar files found in {$dir}; refusing to run against a missing or partial source tree.";
    }
}
```

In `WiderRegionMembershipSeeder::reconcile()` replace the `$declared === []` check with the guard, before `declaredRegions()`:

```php
        // A reconcile that finds no national calendar at all is reading a missing or half-written tree (a deploy in
        // progress, a bad mount), not a deployment without nations: every nation holding tuples would be pruned to
        // none. Refuse before contacting OpenFGA, so a scheduled run fails loudly instead of revoking access.
        $refusal = ( new SourceTreeGuard($nationsDir) )->refusalReason();
        if ($refusal !== null) {
            throw new \RuntimeException($refusal);
        }
        $declared = $this->declaredRegions($nationsDir);
```

- [ ] **Step 4: Run** `vendor/bin/phpunit phpunit_tests/Services/SourceTreeGuardTest.php phpunit_tests/Services/WiderRegionMembershipSeederTest.php`
  — all pass (the seeder's two `No national calendar files` expectations still match).
- [ ] **Step 5: Commit** `feat(access): SourceTreeGuard, shared by the membership seeder (#1015)`.

### Task A2: the sweep refuses an empty tree and gets a real dry run

**Files:**

- Modify: `src/Services/Outbox/ResourceTuplePurgeReconciler.php`
- Modify: `scripts/reconcile-resource-tuples.php`
- Test: `phpunit_tests/Services/Outbox/ResourceTuplePurgeReconcilerTest.php`

**Interfaces:**

- Consumes: `SourceTreeGuard` (A1).
- Produces: `ResourceTuplePurgeReconciler::__construct(OpenFgaClient $client, ResourceExistenceCheckerInterface $checker,
  ResourceTuplePurgeServiceInterface $purge, SourceTreeGuard $guard = new SourceTreeGuard())` and
  `sweep(bool $apply = true): array{scanned: int, purgedObjects: int, enqueued: int, objects: array<string, int>}` — `objects` maps
  each object purged (or, when `$apply` is false, that would be purged) to its operational tuple count. Throws `\RuntimeException`
  with the guard's reason before reading any tuple.

- [ ] **Step 1: Add failing tests** to `ResourceTuplePurgeReconcilerTest`:

```php
    public function testAnEmptyTreeIsRefusedBeforeAnyTupleIsRead(): void
    {
        $empty = sys_get_temp_dir() . '/rtpr_' . uniqid();
        mkdir($empty);
        try {
            $client = $this->createMock(OpenFgaClient::class);
            $client->expects($this->never())->method('readTuples');
            $purge = $this->createMock(ResourceTuplePurgeServiceInterface::class);
            $purge->expects($this->never())->method('purgeForObject');

            $reconciler = new ResourceTuplePurgeReconciler(
                $client,
                $this->createStub(ResourceExistenceCheckerInterface::class),
                $purge,
                new SourceTreeGuard($empty)
            );

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('No national calendar files found');
            $reconciler->sweep();
        } finally {
            @rmdir($empty);
        }
    }

    public function testADryRunListsWhatWouldBePurgedAndPurgesNothing(): void
    {
        $client = $this->createStub(OpenFgaClient::class);
        $client->method('readTuples')->willReturn([
            'tuples'                  => [
                ['user' => 'user:a', 'relation' => 'editor', 'object' => 'national_calendar:ZZ'],
                ['user' => 'user:b', 'relation' => 'viewer', 'object' => 'national_calendar:ZZ'],
                ['user' => 'user:c', 'relation' => 'admin', 'object' => 'national_calendar:ZZ'],
            ],
            'next_continuation_token' => '',
        ]);
        $checker = $this->createStub(ResourceExistenceCheckerInterface::class);
        $checker->method('isResourceType')->willReturn(true);
        $checker->method('exists')->willReturn(false);
        $purge = $this->createMock(ResourceTuplePurgeServiceInterface::class);
        $purge->expects($this->never())->method('purgeForObject');

        $result = ( new ResourceTuplePurgeReconciler($client, $checker, $purge) )->sweep(apply: false);

        $this->assertSame(['national_calendar:ZZ' => 2], $result['objects']);
        $this->assertSame(1, $result['purgedObjects']);
        $this->assertSame(0, $result['enqueued']);
    }
```

and assert `$result['objects'] === ['national_calendar:ZZ' => 1]` in the existing `testPurgesOnlyDeletedResourcesWithOperationalTuplesIgnoringAdmin`.

- [ ] **Step 2: Run** `vendor/bin/phpunit phpunit_tests/Services/Outbox/ResourceTuplePurgeReconcilerTest.php` — the new tests fail.
- [ ] **Step 3: Implement.** Constructor gains `private readonly SourceTreeGuard $guard = new SourceTreeGuard()`. `sweep(bool $apply = true)`:
  first `$refusal = $this->guard->refusalReason(); if ($refusal !== null) { throw new \RuntimeException($refusal); }`; the scan counts
  operational tuples per object (`$operational[$t['object']] = ($operational[$t['object']] ?? 0) + 1`) instead of a set; in the purge
  loop, record `$objects[$object] = $operational[$object]` and call `purgeForObject()` only when `$apply`. Return the four keys.
  Update the class docblock: "Cron-able" becomes "Run by the `resource-tuple-sweep` job; refuses an empty tree; `$apply = false` lists
  without purging".
- [ ] **Step 4: Rewrite the script's mode handling.** Remove the early `exit(0)` dry-run stub. The OpenFGA guard applies to both modes.
  Print `Mode: APPLY` or `Mode: DRY RUN (pass --apply to purge)`, call `$reconciler->sweep($apply)` inside
  `try { … } catch (\RuntimeException $e) { fwrite(STDERR, 'Error: ' . $e->getMessage() . PHP_EOL); exit(1); }`, print one line per
  object — `- {object} ({n} operational tuples)` — then the summary, with `Objects purged` reading `Objects to purge` in a dry run.
- [ ] **Step 5: Run** the test file, then `composer analyse` and `composer lint`. All pass.
- [ ] **Step 6: Update `docs/ops/rbac-create-governance-runbook.md` Step 7:** the dry run now lists what `--apply` would revoke, and the
  script refuses (exit 1) when no national calendar file exists. `composer lint:md`.
- [ ] **Step 7: Commit** `fix(access): the resource-tuple sweep refuses an empty tree and has a real dry run (#1015)`, push, open a PR to
  `development` with `Closes #1015`.

---

## Part B — #1008: the job runner

Branch `feat/1008-job-runner` in worktree `../wt-1008-job-runner`. Tasks B1–B4 do not depend on Part A; rebase onto
`fix/1015-tuple-sweep-guard` before Task B5 (`git rebase fix/1015-tuple-sweep-guard`).

### Task B1: `job_schedule` table and `JobScheduleRepository`

**Files:**

- Create: `src/Migrations/Version20260929120000.php`
- Create: `src/Enum/JobStatus.php`
- Create: `src/Models/Jobs/JobScheduleRow.php`
- Create: `src/Repositories/JobScheduleRepository.php`
- Modify: `phpunit_tests/Repositories/RepositoryTestCase.php` (`TABLES` gains `'job_schedule'`)
- Test: `phpunit_tests/Repositories/JobScheduleRepositoryTest.php`

**Interfaces:**

- Produces:
  - `enum JobStatus: string { SUCCEEDED = 'succeeded'; FAILED = 'failed'; TIMED_OUT = 'timed_out'; REFUSED = 'refused' }`
  - `final readonly class JobScheduleRow { string $name; DateTimeImmutable $nextDueAt; ?string $leaseOwner; ?DateTimeImmutable $leaseUntil;
    ?DateTimeImmutable $lastStartedAt; ?DateTimeImmutable $lastFinishedAt; ?DateTimeImmutable $lastSuccessAt; ?JobStatus $lastStatus;
    ?string $lastError; ?int $lastDurationMs; int $consecutiveFailures }`
  - `JobScheduleRepository`:
    - `__construct(PDO $pdo)`
    - `ensureRows(list<string> $names): void`
    - `acquire(string $name, string $owner, int $ttlSeconds): bool`
    - `renew(string $name, string $owner, int $ttlSeconds): bool` — true only while `lease_owner = $owner`
    - `finish(string $name, string $owner, JobStatus $status, ?string $error, int $durationMs, ?int $intervalSeconds): bool`
    - `release(string $name, string $owner): void`
    - `dueNames(list<string> $names): list<string>` — due and not leased
    - `all(): array<string, JobScheduleRow>`
    - `now(): DateTimeImmutable` — the database clock
    - `const MAX_ERROR_LENGTH = 2000`

- [ ] **Step 1: Migration.**

```php
<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Migrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Schedule state and leases for the job runner (#1008).
 *
 * One row per registered job, plus the reserved `supervisor` row whose lease keeps a single supervisor
 * active. A lease is `lease_owner` + `lease_until`, taken with a guarded UPDATE; every comparison uses
 * the database clock. See docs/superpowers/specs/2026-09-29-job-runner-design.md §5.
 */
final class Version20260929120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create job_schedule for the job runner (#1008)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !( $this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform ),
            'This migration targets PostgreSQL only.'
        );

        $this->addSql(<<<'SQL'
            CREATE TABLE job_schedule (
                name                  TEXT         PRIMARY KEY,
                next_due_at           TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
                lease_owner           TEXT         NULL,
                lease_until           TIMESTAMPTZ  NULL,
                last_started_at       TIMESTAMPTZ  NULL,
                last_finished_at      TIMESTAMPTZ  NULL,
                last_success_at       TIMESTAMPTZ  NULL,
                last_status           TEXT         NULL
                    CHECK (last_status IN ('succeeded', 'failed', 'timed_out', 'refused')),
                last_error            TEXT         NULL,
                last_duration_ms      INTEGER      NULL,
                consecutive_failures  INTEGER      NOT NULL DEFAULT 0
            )
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !( $this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform ),
            'This migration targets PostgreSQL only.'
        );

        $this->addSql('DROP TABLE IF EXISTS job_schedule');
    }
}
```

Apply locally: `composer db:migrate`. Add `'job_schedule'` to `RepositoryTestCase::TABLES`.

- [ ] **Step 2: Write the failing repository test** (`JobScheduleRepositoryTest extends RepositoryTestCase`), with these methods:

```php
    private function repo(): JobScheduleRepository
    {
        self::assertNotNull(self::$pdo);
        return new JobScheduleRepository(self::$pdo);
    }

    public function testEnsureRowsCreatesDueRowsAndKeepsHistory(): void
    {
        $repo = $this->repo();
        $repo->ensureRows(['a']);
        self::assertSame(['a'], $repo->dueNames(['a']));
        self::assertTrue($repo->acquire('a', 'h:1', 60));
        self::assertTrue($repo->finish('a', 'h:1', JobStatus::SUCCEEDED, null, 5, 300));
        $repo->ensureRows(['a', 'b']);
        $rows = $repo->all();
        self::assertSame(JobStatus::SUCCEEDED, $rows['a']->lastStatus);
        self::assertSame([], $repo->dueNames(['a']), 'next_due_at moved 300 s ahead');
        self::assertSame(['b'], $repo->dueNames(['a', 'b']));
    }

    public function testAcquireIsExclusiveUntilTheLeaseLapses(): void
    {
        $repo = $this->repo();
        $repo->ensureRows(['a']);
        self::assertTrue($repo->acquire('a', 'h:1', 60));
        self::assertFalse($repo->acquire('a', 'h:2', 60));
        self::assertSame([], $repo->dueNames(['a']), 'a leased job is not due');
        self::$pdo?->exec("UPDATE job_schedule SET lease_until = now() - interval '1 second'");
        self::assertTrue($repo->acquire('a', 'h:2', 60));
    }

    public function testRenewAndFinishAreOwnerGuarded(): void
    {
        $repo = $this->repo();
        $repo->ensureRows(['a']);
        self::assertTrue($repo->acquire('a', 'h:1', 60));
        self::assertFalse($repo->renew('a', 'h:2', 60));
        self::assertFalse($repo->finish('a', 'h:2', JobStatus::FAILED, 'x', 1, 60));
        self::assertTrue($repo->renew('a', 'h:1', 60));
        self::assertNull($repo->all()['a']->lastStatus, 'a stranger recorded nothing');
    }

    public function testFailuresCountUpAndASuccessResetsThem(): void
    {
        $repo = $this->repo();
        $repo->ensureRows(['a']);
        foreach ([JobStatus::FAILED, JobStatus::REFUSED, JobStatus::TIMED_OUT] as $status) {
            self::$pdo?->exec("UPDATE job_schedule SET next_due_at = now()");
            self::assertTrue($repo->acquire('a', 'h:1', 60));
            self::assertTrue($repo->finish('a', 'h:1', $status, 'boom', 1, 60));
        }
        self::assertSame(3, $repo->all()['a']->consecutiveFailures);
        self::assertNull($repo->all()['a']->lastSuccessAt);
        self::assertTrue($repo->acquire('a', 'h:1', 60));
        self::assertTrue($repo->finish('a', 'h:1', JobStatus::SUCCEEDED, null, 1, 60));
        $row = $repo->all()['a'];
        self::assertSame(0, $row->consecutiveFailures);
        self::assertNotNull($row->lastSuccessAt);
        self::assertNull($row->leaseOwner);
    }

    public function testAStreamFinishLeavesNextDueAlone(): void
    {
        $repo = $this->repo();
        $repo->ensureRows(['s']);
        $before = $repo->all()['s']->nextDueAt;
        self::assertTrue($repo->acquire('s', 'h:1', 60));
        self::assertTrue($repo->finish('s', 'h:1', JobStatus::SUCCEEDED, null, 1, null));
        self::assertEquals($before, $repo->all()['s']->nextDueAt);
    }

    public function testErrorsAreTruncated(): void
    {
        $repo = $this->repo();
        $repo->ensureRows(['a']);
        self::assertTrue($repo->acquire('a', 'h:1', 60));
        self::assertTrue($repo->finish('a', 'h:1', JobStatus::FAILED, str_repeat('é', 5000), 1, 60));
        self::assertSame(JobScheduleRepository::MAX_ERROR_LENGTH, mb_strlen((string) $repo->all()['a']->lastError));
    }

    public function testReleaseClearsOnlyTheOwnersLease(): void
    {
        $repo = $this->repo();
        $repo->ensureRows(['supervisor']);
        self::assertTrue($repo->acquire('supervisor', 'h:1', 60));
        $repo->release('supervisor', 'h:2');
        self::assertSame('h:1', $repo->all()['supervisor']->leaseOwner);
        $repo->release('supervisor', 'h:1');
        self::assertNull($repo->all()['supervisor']->leaseOwner);
    }
```

Plus a two-process race: `testTwoProcessesRaceForOneLeaseAndOneWins` spawning two `php -r` children via `proc_open` that each connect
with the test DB env and call `acquire('race', 'h:<pid>', 60)` after a shared `usleep` barrier, printing `1` or `0`; assert the
outputs sum to exactly 1. Model it on `SourceDataChangeRequestPublishQueueTest`'s two-process test (reuse its env-forwarding helper).

- [ ] **Step 3: Run it to see it fail** — `vendor/bin/phpunit phpunit_tests/Repositories/JobScheduleRepositoryTest.php`.
- [ ] **Step 4: Implement `JobStatus`, `JobScheduleRow`, `JobScheduleRepository`.** SQL (every placeholder name used once — native
  prepares forbid reuse):

```php
    public function acquire(string $name, string $owner, int $ttlSeconds): bool
    {
        $stmt = $this->pdo->prepare(<<<'SQL'
            UPDATE job_schedule
               SET lease_owner = :owner, lease_until = now() + make_interval(secs => :ttl), last_started_at = now()
             WHERE name = :name AND (lease_until IS NULL OR lease_until < now())
            SQL);
        $stmt->execute([':owner' => $owner, ':ttl' => $ttlSeconds, ':name' => $name]);

        return $stmt->rowCount() === 1;
    }

    public function renew(string $name, string $owner, int $ttlSeconds): bool
    {
        $stmt = $this->pdo->prepare(<<<'SQL'
            UPDATE job_schedule SET lease_until = now() + make_interval(secs => :ttl)
             WHERE name = :name AND lease_owner = :owner
            SQL);
        $stmt->execute([':ttl' => $ttlSeconds, ':name' => $name, ':owner' => $owner]);

        return $stmt->rowCount() === 1;
    }

    public function finish(string $name, string $owner, JobStatus $status, ?string $error, int $durationMs, ?int $intervalSeconds): bool
    {
        $succeeded = $status === JobStatus::SUCCEEDED;
        $stmt      = $this->pdo->prepare(<<<'SQL'
            UPDATE job_schedule
               SET lease_owner = NULL, lease_until = NULL, last_finished_at = now(),
                   last_status = :status, last_error = :error, last_duration_ms = :duration,
                   last_success_at = CASE WHEN :succeeded THEN now() ELSE last_success_at END,
                   consecutive_failures = CASE WHEN :succeeded2 THEN 0 ELSE consecutive_failures + 1 END,
                   next_due_at = CASE WHEN CAST(:interval AS INTEGER) IS NULL THEN next_due_at
                                      ELSE now() + make_interval(secs => CAST(:interval2 AS INTEGER)) END
             WHERE name = :name AND lease_owner = :owner
            SQL);
        $stmt->bindValue(':status', $status->value);
        $stmt->bindValue(':error', $error === null ? null : mb_substr($error, 0, self::MAX_ERROR_LENGTH), $error === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->bindValue(':duration', $durationMs, PDO::PARAM_INT);
        $stmt->bindValue(':succeeded', $succeeded, PDO::PARAM_BOOL);
        $stmt->bindValue(':succeeded2', $succeeded, PDO::PARAM_BOOL);
        $stmt->bindValue(':interval', $intervalSeconds, $intervalSeconds === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindValue(':interval2', $intervalSeconds, $intervalSeconds === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindValue(':name', $name);
        $stmt->bindValue(':owner', $owner);
        $stmt->execute();

        return $stmt->rowCount() === 1;
    }
```

`ensureRows`: one `INSERT INTO job_schedule (name) VALUES (:n) ON CONFLICT (name) DO NOTHING` per name. `release`:
`UPDATE … SET lease_owner = NULL, lease_until = NULL WHERE name = :name AND lease_owner = :owner`. `dueNames`: `SELECT name FROM
job_schedule WHERE name = ANY(CAST(:names AS TEXT[])) AND next_due_at <= now() AND (lease_until IS NULL OR lease_until < now())
ORDER BY name`, binding the names as a Postgres array literal built with each name quoted (`'{"a","b"}'`; names are validated
`^[a-z][a-z0-9-]*$` so no escaping is needed, but quote anyway). `all()`: `SELECT * FROM job_schedule ORDER BY name`, hydrated with
`new DateTimeImmutable($value)` for timestamps and `JobStatus::from()` for the status. `now()`: `SELECT now()`. Class docblock: all
time comparisons are the database's; a lease is owner + expiry; see the spec §5.

- [ ] **Step 5: Run** the test — passes. `composer analyse`, `composer lint`.
- [ ] **Step 6: Commit** `feat(jobs): job_schedule table and JobScheduleRepository (#1008)`.

### Task B2: job contracts and the registry

**Files:**

- Create: `src/Services/Jobs/Job.php`, `DestructiveJob.php`, `JobContext.php`, `JobKind.php`, `JobDefinition.php`, `JobRegistry.php`
- Test: `phpunit_tests/Services/Jobs/JobRegistryTest.php`

**Interfaces:**

- Produces:
  - `interface Job { public function run(JobContext $context): void; }` — throws on failure; a stream job returns only when
    `$context->shouldStop()`.
  - `interface DestructiveJob extends Job { public function preflight(): ?string; }`
  - `final class JobContext { __construct(public readonly bool $dryRun, \Closure $shouldStop, public readonly LoggerInterface $logger,
    ?\Closure $writer = null); shouldStop(): bool; say(string $line): void }` — `say()` writes `$line . PHP_EOL` to STDOUT by default.
  - `enum JobKind: string { STREAM = 'stream'; INTERVAL = 'interval' }`
  - `final class JobDefinition { __construct(string $name, JobKind $kind, class-string<Job> $class, \Closure $factory,
    ?int $intervalSeconds = null, ?int $timeoutSeconds = null); make(): Job; isDestructive(): bool; leaseSeconds(): int }` with public
    readonly `name`, `kind`, `class`, `intervalSeconds`, `timeoutSeconds`. `leaseSeconds()` is 60 for stream, timeout + 30 for interval.
    `make()` calls the factory and throws `\LogicException` unless the result is an instance of `$class`.
  - `final class JobRegistry { const RESERVED_NAME = 'supervisor'; __construct(JobDefinition ...$definitions); get(string): ?JobDefinition;
    all(): list<JobDefinition>; names(): list<string>; namesOfKind(JobKind): list<string> }`

- [ ] **Step 1: Failing tests** covering: a valid interval and stream definition; `InvalidArgumentException` for a name not matching
  `^[a-z][a-z0-9-]*$`, for the reserved name (thrown by the registry), for a duplicate name, for an interval job without a positive
  interval or timeout, for a stream job with an interval or timeout; `isDestructive()` true exactly for a class implementing
  `DestructiveJob`; `make()` throws `LogicException` when the factory returns another class; `leaseSeconds()` values; `JobContext::say()`
  goes to an injected writer and `shouldStop()` reflects the closure. Use two tiny fixture classes in the test file:

```php
final class OkJob implements Job
{
    public function run(JobContext $context): void
    {
    }
}

final class GuardedJob implements DestructiveJob
{
    public function preflight(): ?string
    {
        return null;
    }

    public function run(JobContext $context): void
    {
    }
}
```

- [ ] **Step 2: Run** `vendor/bin/phpunit phpunit_tests/Services/Jobs/JobRegistryTest.php` — fails.
- [ ] **Step 3: Implement** the six files. `JobDefinition` validation in the constructor:

```php
        if (preg_match('/^[a-z][a-z0-9-]*$/', $name) !== 1) {
            throw new \InvalidArgumentException("Invalid job name '{$name}'.");
        }
        if (!is_subclass_of($class, Job::class)) {
            throw new \InvalidArgumentException("{$class} does not implement " . Job::class . '.');
        }
        if ($kind === JobKind::INTERVAL && ( $intervalSeconds === null || $intervalSeconds < 1 || $timeoutSeconds === null || $timeoutSeconds < 1 )) {
            throw new \InvalidArgumentException("Interval job '{$name}' needs a positive interval and timeout.");
        }
        if ($kind === JobKind::STREAM && ( $intervalSeconds !== null || $timeoutSeconds !== null )) {
            throw new \InvalidArgumentException("Stream job '{$name}' takes no interval or timeout.");
        }
```

- [ ] **Step 4: Run** — passes; `composer analyse`, `composer lint`.
- [ ] **Step 5: Commit** `feat(jobs): job contracts and registry (#1008)`.

### Task B3: `JobRunner` — the `run` mode

**Files:**

- Create: `src/Services/Jobs/JobRunner.php`
- Test: `phpunit_tests/Services/Jobs/JobRunnerTest.php` (extends `RepositoryTestCase`)

**Interfaces:**

- Consumes: B1, B2.
- Produces: `final class JobRunner { const EXIT_OK = 0; EXIT_FAILED = 1; EXIT_USAGE = 2; EXIT_REFUSED = 3; EXIT_NOT_ACQUIRED = 75;
  const RENEW_EVERY_SECONDS = 15; __construct(JobRegistry $registry, JobScheduleRepository $schedule, LoggerInterface $logger,
  string $owner, int $renewEverySeconds = self::RENEW_EVERY_SECONDS); run(string $name, bool $dryRun = false): int;
  static function ownerId(?int $pid = null): string }` — `ownerId()` is `gethostname() . ':' . ($pid ?? getmypid())`.

Behaviour (spec §5.2, §7):

1. Unknown name → log, `EXIT_USAGE`. `--dry-run` on a stream job → `EXIT_USAGE`.
2. Install `pcntl_async_signals(true)` and SIGTERM/SIGINT handlers that set `$stop = true` (when `pcntl` is loaded).
3. Dry run: `make()` (a throw → `EXIT_FAILED`), preflight for destructive (a reason → `say('Refused: …')`, `EXIT_REFUSED`), `run()` with
   `dryRun: true`; nothing recorded, no lease.
4. Otherwise `acquire($name, $owner, $def->leaseSeconds())`; false → `EXIT_NOT_ACQUIRED`, nothing recorded.
5. Inside `try`: `make()`, preflight (a reason → finish `REFUSED` with the reason, `EXIT_REFUSED`), for stream jobs arm
   `pcntl_signal(SIGALRM, …)` + `pcntl_alarm($renewEverySeconds)` whose handler calls `renew()` (false → `$stop = true`; a throw is logged
   and ignored) and re-arms; then `run()`. Success → `SUCCEEDED`. `catch (\Throwable $e)` → `FAILED`, error `$e::class . ': ' .
   $e->getMessage()`. `finally` disarm the alarm.
6. `finish($name, $owner, $status, $error, $durationMs, $def->kind === JobKind::INTERVAL ? $def->intervalSeconds : null)`; false → log
   warning "lease lost".

- [ ] **Step 1: Failing tests** with a test registry built in `setUp()` from fixture jobs defined in the test file: `ok` (returns),
  `boom` (throws `RuntimeException('kaput')`), `refuse` (`DestructiveJob`, preflight `'tree missing'`, `run()` fails the test if
  called), `echo` (`DestructiveJob`, preflight null, `say('dry=' . var_export($context->dryRun, true))`), `stream` (stream kind; loops
  `while (!$context->shouldStop()) { usleep(100_000); }` and counts iterations; for the renewal test it self-stops after 2.5 s).
  Cases:
  - `ok` → 0, row `succeeded`, lease cleared, `next_due_at` ~ interval ahead (`dueNames` empty).
  - `boom` → 1, row `failed`, `last_error` `RuntimeException: kaput`, `consecutive_failures` 1.
  - `refuse` → 3, row `refused`, `last_error` `tree missing`, run never called.
  - `refuse` with `--dry-run` → 3, row untouched (`lastStatus` null).
  - `echo` with `--dry-run` → 0, writer received `dry=true`, row untouched, no lease taken.
  - unknown name → 2; `stream` with `--dry-run` → 2.
  - **Review Focus 3:** lease already held by `other:1` → 75, row's `lease_owner` still `other:1`, `last_status` null.
  - stream renewal: with `renewEverySeconds: 1`, run `stream`; while it runs the alarm renews — assert after the run that the row is
    `succeeded`; and a variant where the test's fixture sets `lease_owner = 'thief:1'` via SQL after 1.2 s: the job observes
    `shouldStop()` true within ~1 s and the finish records nothing (row still owned by `thief:1`). Skip both when `pcntl` is missing.
- [ ] **Step 2: Run** — fails.
- [ ] **Step 3: Implement `JobRunner`.**
- [ ] **Step 4: Run** — passes. `composer analyse`, `composer lint`.
- [ ] **Step 5: Commit** `feat(jobs): JobRunner runs one job under a lease and records the outcome (#1008)`.

### Task B4: the consumer loops stop on request; `PublishConsumerLoop` only consumes

**Files:**

- Modify: `src/Services/Outbox/ConsumerLoop.php` (`run()`)
- Modify: `src/Services/SourceData/PublishConsumerLoop.php` (drop idle ticks, `run()`)
- Modify: `bin/publish-sourcedata-consumer`, `bin/reconcile-outbox` (docblocks; the constructor call)
- Modify: `phpunit_tests/Services/SourceData/PublishConsumerLoopTest.php`, `phpunit_tests/Services/Outbox/ConsumerLoopTest.php`

**Interfaces:**

- Produces: `ConsumerLoop::run(?callable $shouldStop = null): void` and `PublishConsumerLoop::run(?callable $shouldStop = null): void` —
  `while (!( $shouldStop !== null && $shouldStop() )) { $this->tick(); }`. `PublishConsumerLoop::__construct(StreamConsumerInterface
  $consumer, PublishRunner $publisher, int $blockMs = 5000, ?LoggerInterface $logger = null)`.

- [ ] **Step 1: Failing tests.** `ConsumerLoopTest::testRunReturnsWhenAskedToStop` — a stop callback that returns true on its third
  call; assert `readOnce` was invoked exactly twice (mocked consumer). Same for `PublishConsumerLoopTest::testRunReturnsWhenAskedToStop`
  using `ScriptedStreamConsumer`. Delete the idle-tick tests: `testAnIdleTickPublishesWithNoMessageAtAll`, `testTheRecoveryTickIsRateLimited`,
  `testTheIdleMergePollIsRateLimited`, `testAZeroSecondIntervalPollsOnEveryIdleTick`, `testAWokenTickDoesNotAlsoPollMerges`,
  `testIdleTicksWithoutAMergePollerDoNotThrow`, `testAnIdleMergePollThatFindsAMergedPrSettlesTheBatch`,
  `testAMergePollFailureDoesNotKillTheConsumer`, `testAStreamReadFailureStillRunsTheIdleMergePoll`, and add
  `testAnIdleTickPublishesNothing`: a tick with no message leaves an approved batch `queued`/unpublished (that work now belongs to
  `publish-backstop`). Update every remaining constructor call to the new signature.
- [ ] **Step 2: Run** both test files — fail.
- [ ] **Step 3: Implement.** Remove `$lastMergePollAt`, `$lastRecoveryTickAt`, `runPublishRecoveryIfDue()`, `pollMergesIfDue()`, the
  `$mergePoller`, `$mergePollIntervalSeconds`, `$recoveryTickIntervalSeconds` parameters and the `if (!$woken)` block. Rewrite the class
  docblock sections "The recovery tick" and the idle-poll paragraphs into one paragraph: recovery and merge polling are the
  `publish-backstop` and `merge-poll` jobs of the job runner (#1008), so this loop only consumes. Drop the `$woken` bookkeeping if nothing
  else reads it. In `bin/publish-sourcedata-consumer`, drop `$mergePoller`, pass `blockMs: 5000, logger: $logger`, and rewrite the header
  docblock: it is the manual/legacy entry point; the job runner's `publish-consumer` job is the supported way to run it.
- [ ] **Step 4: Run** the two files plus `PublishSourceDataConsumerScriptTest` — pass. `composer analyse`, `composer lint`.
- [ ] **Step 5: Commit** `refactor(jobs): consumer loops stop on request; recovery and merge polling leave the publish loop (#1008)`.

### Task B5: the job catalog — seven jobs wrapping the existing runners

Rebase onto `fix/1015-tuple-sweep-guard` first.

**Files:**

- Create: `src/Services/Jobs/Catalog/OutboxConsumerJob.php`, `PublishConsumerJob.php`, `OutboxBackstopJob.php`, `PublishBackstopJob.php`,
  `MergePollJob.php`, `WiderRegionMembershipJob.php`, `ResourceTupleSweepJob.php`, `JobCatalog.php`
- Test: `phpunit_tests/Services/Jobs/Catalog/JobCatalogTest.php`, `phpunit_tests/Services/Jobs/Catalog/CatalogJobsTest.php`

**Interfaces:**

- Consumes: B2 contracts, B4 loop signatures, A1/A2.
- Produces: `JobCatalog::default(): JobRegistry` with exactly the spec §4.3 table; each wrapper takes closures, so tests need no final
  runner doubles:
  - `OutboxConsumerJob(\Closure $loop)` / `PublishConsumerJob(\Closure $loop)` — `$loop(callable $shouldStop): void`; `run()` calls
    `($this->loop)(fn (): bool => $context->shouldStop())`.
  - `OutboxBackstopJob(\Closure $runOnce)` — `$runOnce(): int`; says `backstop processed=N`.
  - `PublishBackstopJob(\Closure $runOnce)` — `$runOnce(): PublishRunResult`; says the same line the script prints; throws
    `\RuntimeException('The publish run stopped on a failure.')` when `stoppedOnFailure`.
  - `MergePollJob(\Closure $runOnce)` — `$runOnce(): MergePollRunResult`; same pattern.
  - `WiderRegionMembershipJob(SourceTreeGuard $guard, \Closure $reconcile)` implements `DestructiveJob` — `$reconcile(bool $apply): array
    {writes, deletes, skipped}`; preflight = guard; `run()` passes `!$context->dryRun`, says `+ …`, `- …`, `! …` lines and a summary.
  - `ResourceTupleSweepJob(SourceTreeGuard $guard, \Closure $sweep)` implements `DestructiveJob` — `$sweep(bool $apply): array`; says one
    line per object and a summary.

- [ ] **Step 1: Failing tests.** `CatalogJobsTest`: each wrapper with a fake closure — stop callback forwarded; `stoppedOnFailure` throws
  for publish and merge; destructive preflight returns the guard's reason for an empty temp dir and null for a populated one; dry run
  passes `apply: false`. `JobCatalogTest`: names, kinds, intervals and timeouts equal the spec table (`outbox-consumer`,
  `publish-consumer` stream; `outbox-backstop` 300/240; `publish-backstop` 60/900; `merge-poll` 60/300; `wider-region-membership`
  86400/900; `resource-tuple-sweep` 86400/1800); exactly the last two are destructive; building the registry calls no factory.
- [ ] **Step 2: Run** — fails.
- [ ] **Step 3: Implement.** Factories in `JobCatalog` (every env read here, via `SourceDataPublisherFactory::envString()`):
  - outbox pieces: `$pdo = Connection::getInstance()`, `new OutboxRepository($pdo)`, `new OutboxProcessor($repo, OpenFgaClient::fromEnv(),
    maxAttempts)` with `OUTBOX_MAX_ATTEMPTS` default 10, `CascadeReconciler::fromEnv()`; backstop `BackstopRunner(..., graceSeconds:
    OUTBOX_BACKSTOP_GRACE_SECONDS default 60, cascade: …)->runOnce(limit: 200)`; consumer: `extension_loaded('redis')` else throw
    `\RuntimeException('ext-redis is required for the outbox consumer.')`, `RedisConnection::openOrFail()`, stream/group/consumer from
    `REDIS_OUTBOX_STREAM` / `REDIS_OUTBOX_GROUP` / `REDIS_OUTBOX_CONSUMER_NAME` with the `bin/reconcile-outbox` defaults, `new
    ConsumerLoop($stream, $processor, blockMs: 5000, cascade: $cascade)`.
  - publish pieces: `umask(0o077)` first (the child is dedicated to this job, so nothing restores it), `$factory = new
    SourceDataPublisherFactory()`, `$logger = $factory->logger('<job name>')`, `publishRunner($logger)` / `mergePollRunner($logger)`;
    the consumer as `bin/publish-sourcedata-consumer` builds it, with `SourceDataPublishNotifier::BATCH_ID_FIELD`.
  - membership: `OpenFgaClient::isConfigured()` else throw; `(new WiderRegionMembershipSeeder())->reconcile(new
    WiderRegionMembershipReconciler(OpenFgaClient::fromEnv()), $guard->nationsDir(), $apply)`.
  - sweep: `OpenFgaClient::isConfigured()` else throw; wire as `scripts/reconcile-resource-tuples.php` does, passing the guard.
  - The two destructive factories set `Router::$apiFilePath` to the project root when it is empty, as the scripts do.
- [ ] **Step 4: Run** — passes; `composer analyse`, `composer lint`.
- [ ] **Step 5: Commit** `feat(jobs): the job catalog wraps the existing consumers, backstops and sweeps (#1008)`.

### Task B6: the `Supervisor`

**Files:**

- Create: `src/Services/Jobs/ChildProcess.php` (interface), `ChildLauncher.php` (interface), `ProcOpenChildLauncher.php`,
  `ProcOpenChildProcess.php`, `Supervisor.php`
- Test: `phpunit_tests/Services/Jobs/SupervisorTest.php` (fake launcher, real Postgres),
  `phpunit_tests/Services/Jobs/SupervisorProcessTest.php` (real processes), fixture `phpunit_tests/Support/job-runner-fixture.php`

**Interfaces:**

- Produces:
  - `interface ChildProcess { pid(): int; isRunning(): bool; exitCode(): ?int; signal(int $signal): void; }` — `exitCode()` caches the
    code, because `proc_get_status()` reports it only once.
  - `interface ChildLauncher { start(string $jobName): ChildProcess; }`
  - `ProcOpenChildLauncher(list<string> $commandPrefix)` — `proc_open([...$commandPrefix, $jobName], [0 => ['file', '/dev/null', 'r'],
    1 => STDOUT, 2 => STDERR], $pipes)`.
  - `final class Supervisor { const LEASE_NAME = 'supervisor'; LEASE_SECONDS = 60; RENEW_EVERY_SECONDS = 15; STANDBY_RETRY_SECONDS = 15;
    SHUTDOWN_WAIT_SECONDS = 30; KILL_GRACE_SECONDS = 10; MIN_RELAUNCH_SECONDS = 60; STREAM_BACKOFF_MAX_SECONDS = 60;
    STREAM_HEALTHY_SECONDS = 300; __construct(JobRegistry $registry, \Closure $connect, ChildLauncher $launcher, LoggerInterface $logger,
    string $owner, list<string> $disabled = [], ?\Closure $clock = null); tick(): void; run(): int; requestStop(): void; shutdown(): void;
    isHoldingLease(): bool; static function parseDisabled(string $raw): list<string> }`. `$connect(): JobScheduleRepository` is called
    whenever the supervisor has no repository (first pass, after any database error); `$clock(): float` is monotonic seconds
    (default `hrtime(true) / 1e9`).

Behaviour (spec §6), one `tick()`:

1. Reap: for each child no longer running, drop it; a stream job's backoff becomes 1 s after its first exit, doubles to 60 s, and resets
   to 1 s when the child ran ≥ 300 s; its next start time is now + backoff.
2. Not holding the lease: every 15 s try `acquire('supervisor', owner, 60)`; success → `ensureRows([...names, 'supervisor'])`, log
   "active"; failure → log "standby" once. Return.
3. Holding: every 15 s `renew()`. Throws → log, drop the repository, carry on. Returns false → log "lease lost", `stopChildren()`,
   not holding, return.
4. Timeouts: an interval child running longer than its timeout gets SIGTERM; 10 s later SIGKILL and
   `finish($name, ownerId(child pid), TIMED_OUT, "killed after {$timeout} s", ms, interval)`.
5. Streams: each enabled stream job without a child, past its next start time → start.
6. Intervals: `dueNames()` of enabled interval jobs that have no local child and were last launched ≥ `min(interval, 60)` s ago → start
   each. A throw drops the repository.

`run()`: `pcntl_async_signals(true)`, SIGTERM/SIGINT → `requestStop()`; loop `tick(); usleep(1_000_000)` until stopped; `shutdown()`
(SIGTERM every child, wait up to 30 s reaping, SIGKILL the rest and record each `FAILED` "killed at shutdown" under its owner id,
`release('supervisor', owner)`); return 0. `parseDisabled()` splits on commas, trims, drops empties. Unknown disabled names are logged once
in the constructor.

- [ ] **Step 1: Failing unit tests** (`SupervisorTest extends RepositoryTestCase`) with `FakeLauncher` / `FakeChild` in the test file
  (`FakeChild` has settable `running`, `exitCode`, and records `signals`) and a settable `$now` clock. Registry: `tick-job` interval 60 /
  timeout 5, `stream-job` stream, `off-job` interval 60 / timeout 5. Cases:
  - first tick acquires the lease and starts `tick-job` and `stream-job`, not `off-job` (disabled);
  - a second tick starts nothing new while the children run;
  - **Review Focus 1:** `tick-job`'s child exits without recording anything (still due in the table); ticks over the next 59 s start no
    new child; at 60 s one is started;
  - `stream-job`'s child exits: not restarted before 1 s, restarted after; exits again quickly: waits 2 s; a child that ran 300 s resets
    backoff to 1 s;
  - timeout: `tick-job` child still running at 5 s → SIGTERM; at 15 s → SIGKILL and the row reads `timed_out` (the test first acquires
    the row's lease as `ownerId(child pid)` to mimic the child);
  - standby: another owner holds `supervisor` → tick starts nothing, `isHoldingLease()` false; expire it via SQL, advance 15 s → takes
    over and starts jobs;
  - lease lost: set `lease_owner = 'thief:1'` on `supervisor`, advance 15 s → every child got SIGTERM, not holding;
  - **Review Focus 2:** `$connect` returns a repository on a dead connection — a fresh PDO that ran
    `SELECT pg_terminate_backend(pg_backend_pid())` on itself — so every call throws: children receive no signal, and the next tick
    calls `$connect` again (count the calls);
  - **Review Focus 5:** `parseDisabled(' off-job, ,unknown-job,')` → `['off-job', 'unknown-job']`; constructing with it logs
    `unknown-job` once (use a `TestLogger`-style array logger).
  - shutdown: running children get SIGTERM; the fake children then report exit; `shutdown()` returns promptly and the `supervisor` lease
    is released.
- [ ] **Step 2: Run** — fails.
- [ ] **Step 3: Implement** the five files.
- [ ] **Step 4: Real-process test** (`SupervisorProcessTest`, `#[RequiresPhpExtension('pcntl')]`, extends `RepositoryTestCase`). The
  fixture script bootstraps autoload and the test DB env (same `DB_*` forwarding as the B1 race test), builds a registry of `hang`
  (interval 60 / timeout 1, `sleep(30)`), `quick` (interval 60 / timeout 5), `loop` (stream, `while (!shouldStop) usleep(100_000)`), and
  runs `(new JobRunner(...))->run($argv[1])`, exiting with its code. Tests:
  - `hang` via a `Supervisor` with `ProcOpenChildLauncher([PHP_BINARY, fixture])`: tick until the row reads `timed_out` (≤ 15 s);
  - `loop` started by `ProcOpenChildLauncher` directly, then `signal(SIGTERM)`: exits 0 within 7 s; row `succeeded`, lease cleared.
- [ ] **Step 5: Run** all supervisor tests — pass. `composer analyse`, `composer lint`.
- [ ] **Step 6: Commit** `feat(jobs): the supervisor starts, times out and restarts job children under one lease (#1008)`.

### Task B7: `bin/litcal-jobs`

**Files:**

- Create: `bin/litcal-jobs` (executable), `src/Services/Jobs/JobsCli.php`
- Modify: `composer.json` scripts: `jobs:supervise`, `jobs:status`
- Test: `phpunit_tests/Services/Jobs/JobsCliTest.php`

**Interfaces:**

- Produces: `final class JobsCli { static function main(list<string> $argv, string $projectRoot): int }` — all logic in `src/` for
  PHPStan; the `bin/` file is bootstrap only (CLI-SAPI guard, autoload, the Dotenv chain `bin/reconcile-outbox` uses,
  `exit(JobsCli::main($argv, $root))`).

`main()`:

- `supervise`: refuse (2) without `pcntl` and `posix`; `new Supervisor(JobCatalog::default(), fn () => { Connection::close(); return new
  JobScheduleRepository(Connection::getInstance()); }, new ProcOpenChildLauncher([PHP_BINARY, $projectRoot . '/bin/litcal-jobs', 'run']),
  $logger, JobRunner::ownerId(), Supervisor::parseDisabled(envString('LITCAL_JOBS_DISABLED')))->run()`.
- `run <job> [--dry-run]`: `new JobRunner(JobCatalog::default(), new JobScheduleRepository(Connection::getInstance()), $logger,
  JobRunner::ownerId())->run($job, $dryRun)`; a database connection failure → 2.
- `status`: one block per registered job — name, kind, disabled, running (lease live), last status, last finished, last success,
  consecutive failures, next due, and `last_error` — computed with the database's `now()`.
- anything else: usage on STDERR, 2.
- Logger: `LoggerFactory::create('jobs', null, …)` following how `SourceDataPublisherFactory::logger()` builds CLI loggers.

- [ ] **Step 1: Failing tests:** usage → 2; `run nope` → 2; `run outbox-backstop` with the database unconfigured
  (`EnvIsolationTrait::withoutEnv(['DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASSWORD'], …)`) → 2; `status` prints every catalog name (with a
  database); plus a static check that `bin/litcal-jobs` contains the CLI-SAPI guard and calls `JobsCli::main`.
- [ ] **Step 2–4:** run (fail), implement, run (pass); `composer analyse`, `composer lint`.
- [ ] **Step 5: Commit** `feat(jobs): bin/litcal-jobs supervise|run|status (#1008)`.

### Task B8: `/health` reports the jobs

**Files:**

- Create: `src/Services/Jobs/JobsHealth.php`
- Modify: `src/Handlers/Ops/HealthHandler.php` (`'jobs' => JobsHealth::build()`, class docblock)
- Test: `phpunit_tests/Services/Jobs/JobsHealthTest.php`, `phpunit_tests/Handlers/Ops/HealthHandlerTest.php`

**Interfaces:**

- Produces: `JobsHealth::build(): array` (reads `Connection`, `JobCatalog::default()`, `LITCAL_JOBS_DISABLED`) and the pure
  `JobsHealth::summarize(JobRegistry $registry, array<string, JobScheduleRow> $rows, DateTimeImmutable $now, list<string> $disabled): array`
  returning `{status: 'ok'|'warning', message: string, supervisor: {alive: bool, lease_age_seconds: ?int}, jobs: array<string, {kind,
  interval_seconds, disabled, running, last_status, last_success_at, last_finished_at, consecutive_failures, next_due_at, overdue,
  heartbeat_age_seconds}>}`. `build()` returns `{status: 'not_configured', …}` without a database and `{status: 'unavailable', …}` when
  the query throws (for example, the migration is not applied).

Rules: supervisor `alive` = its `lease_until` > now; `lease_age_seconds` = `60 - (lease_until - now)` clamped ≥ 0. An interval job is
`overdue` when not running and `now > next_due_at + max(interval, 120)`. A stream job's `heartbeat_age_seconds` = `60 - (lease_until -
now)` when running, null otherwise; not running is a warning. Disabled jobs never warn. A job with no row yet is reported with nulls and
`overdue: false`. Timestamps as RFC 3339 strings. No `last_error`, ever. `status` is `warning` when any warning applies; `message` names
the warnings in one sentence, or "All jobs are running on schedule."

- [ ] **Step 1: Failing tests** for `summarize()`: all healthy → ok; supervisor lease lapsed → warning, `alive` false; interval job
  overdue → warning; the same job disabled → ok; stream job with no lease → warning; stream job leased 20 s ago (lease_until = now + 40 s)
  → `heartbeat_age_seconds` 20; **Review Focus 4:** a row with `lastError` `'secret-token'` never appears in `json_encode($result)`.
  `HealthHandlerTest::testGetSurfacesTheJobsBlock`: the block is present, its `status` is one of the allowed values, and the top-level
  status and HTTP code stay `ok`/200 even when it warns.
- [ ] **Step 2–4:** run (fail), implement, run (pass); `composer analyse`, `composer lint`.
- [ ] **Step 5: Commit** `feat(jobs): /health reports every job's last run and flags overdue ones (#1008)`.

### Task B9: deploy — one unit, restarted on every API deploy

**Files:**

- Create: `deploy/systemd/litcal-jobs.service.in`
- Modify: `deploy/install.sh`, `deploy/sbin/litcal-fpm-reload.sh`, `deploy/litcal-deploy.env.example`
- Delete: `deploy/systemd/liturgical-calendar-reconciler.service`, `deploy/systemd/litcal-publish-consumer.service.in`,
  `deploy/cron/liturgical-calendar-backstop.cron`
- Modify: `composer.json` (remove `reconciler:consumer` if it points at the deleted unit's flow — keep `reconciler:backstop`, it is a
  manual tool)
- Test: `phpunit_tests/Deploy/DeployScriptsTest.php`

- [ ] **Step 1: Unit template**, modelled on `litcal-publish-consumer.service.in`, with `After=network-online.target postgresql.service
  redis-server.service`, `WorkingDirectory=@API_ROOT@`, `ExecStart=@PHP_BIN@ @API_ROOT@/bin/litcal-jobs supervise`, `Restart=on-failure`,
  `RestartSec=5`, `StartLimitIntervalSec=300`, `StartLimitBurst=5`, `KillMode=mixed`, `TimeoutStopSec=45`,
  `SyslogIdentifier=litcal-jobs`, the same hardening lines, and a header explaining: one supervisor, every job a child, safe to restart.
- [ ] **Step 2: `install.sh`**: replace the `PUBLISH_CONSUMER_UNIT` blocks with the same pattern for `JOBS_UNIT` (render, chmod, enable,
  restart, status hint). Keep it optional.
- [ ] **Step 3: `litcal-fpm-reload.sh`**: factor the WebSocket restart + `NRestarts` check into a function `restart_checked <unit>`, call
  it for `$WS_UNIT` and then for `${JOBS_UNIT}` when non-empty; a failure in either releases the sentinels and exits 1 exactly as now.
  Update the header comment ("Two jobs" → three: the job runner is restarted so its children re-read `src/`).
- [ ] **Step 4: env example**: replace `PUBLISH_CONSUMER_UNIT` with `JOBS_UNIT=litcal-jobs.service` and a comment: optional; without it no
  background job runs, and `/health`'s `jobs` block says so.
- [ ] **Step 5: Test** `DeployScriptsTest` (`#[CoversNothing]`): `sh -n` passes on both scripts; `shellcheck` passes when installed
  (`markTestSkipped` otherwise); the template names `bin/litcal-jobs supervise`, `KillMode=mixed`, `TimeoutStopSec=45`; the deleted files
  are gone. Run it.
- [ ] **Step 6: Commit** `feat(deploy): one litcal-jobs unit replaces the consumer units and the backstop cron (#1008)`.

### Task B10: operator docs

**Files:**

- Modify: `docs/ops/openfga-outbox-runbook.md`, `docs/ops/change-request-runbook.md`, `docs/ops/rbac-create-governance-runbook.md`
  (Steps 4 and 7), `docs/ops/deploy-sentinel-runbook.md`, `CLAUDE.md` (the #1005 note), `.env.example` (`LITCAL_JOBS_DISABLED`)
- Modify: `docs/superpowers/specs/2026-09-29-job-runner-design.md` §6.1 (the supervisor renews every 15 s, not every pass)

- [ ] **Step 1:** Each runbook: where it tells the operator to install a unit or a cron line, it now points at `litcal-jobs.service` and
  the job name; add the staged rollout from spec §8.2 to `openfga-outbox-runbook.md` as the one migration procedure and link to it from
  the others; document `bin/litcal-jobs status`, `bin/litcal-jobs run <job> [--dry-run]` and exit codes.
- [ ] **Step 2:** `CLAUDE.md`: the #1005 paragraph says the `wider-region-membership` job reconciles daily; a manual `bin/litcal-jobs
  run wider-region-membership --dry-run` shows the `-`/`+` lines.
- [ ] **Step 3:** `composer lint:md`; fix. Commit `docs(ops): runbooks describe the job runner (#1008)`.

### Task B11: whole-branch verification and PR

- [ ] `composer test:quick` (note the shared-Postgres caveat: no other worktree's suite running at the same time), `composer analyse`,
  `composer lint`, `composer lint:md`, `composer parallel-lint`.
- [ ] A fresh reviewer checks the whole branch against the spec.
- [ ] Push; open the PR against `development`, noting it builds on the #1015 PR and must merge after it; `Closes #1008`.
