# OpenFGA async reconciliation — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use `superpowers:subagent-driven-development` (recommended) or
> `superpowers:executing-plans` to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Land Options B + C from issue #567 — a Postgres outbox + Redis Streams + systemd consumer + cron backstop
that turns OpenFGA tuple writes/deletes from "best-effort during the request" into "eventually consistent with
admin-visible DLQ".

**Architecture:** Handler commits business state and outbox rows in one Postgres transaction. A sync fast path
attempts OpenFGA inline. Failures (or process crashes) leave durable outbox rows that a systemd-managed consumer
drains via Redis Streams, with a cron-driven backstop as the durability anchor. State machine in the outbox table is
the idempotency anchor — re-running an operation on a terminal row is a no-op.

**Tech Stack:** PHP 8.4+, Postgres (Doctrine migrations + PDO), Redis Streams (`ext-redis`), Guzzle for OpenFGA HTTP,
PHPUnit, PHPStan L10, phpcs PSR-12.

**Spec reference:** `docs/superpowers/specs/2026-06-02-openfga-async-reconciliation-design.md` (read this first if you
have not already). All file paths, schema, response shapes, and behavior tables in this plan come from the spec —
when something is ambiguous in the plan, the spec is canonical.

**Branch:** `feature/openfga-async-reconciliation-design` (already created off `origin/development`).

**Pre-requisites:**

- `composer install` has been run on the host.
- `docker compose up -d --build` brought Postgres + Zitadel + OpenFGA + Redis up and `litcal-migrate` applied all
  prior migrations.
- The host can reach `localhost:5432` (Postgres), `localhost:8083` (OpenFGA), `localhost:6379` (Redis).
- `.env.local` has `DB_HOST`/`DB_PORT`/`DB_NAME`/`DB_USER`/`DB_PASSWORD` so `RepositoryTestCase` tests don't skip.

---

## File map

### New files

```text
src/Migrations/Version<TIMESTAMP>.php             — DB migration (generated)
src/Services/Outbox/OutboxOperation.php           — enum: write_tuple | delete_tuple
src/Services/Outbox/OutboxStatus.php              — enum: pending | retrying | succeeded | failed_terminal
src/Services/Outbox/OutboxDisposition.php         — enum: BENIGN_SUCCESS | RETRY | TERMINAL
src/Services/Outbox/OutboxBackoff.php             — pure: secondsForAttempt(int): int
src/Services/Outbox/OutboxClassifier.php          — pure: classify(\Throwable): OutboxDisposition
src/Services/Outbox/OutboxRow.php                 — readonly value object for a row in transit
src/Services/Outbox/OutboxProcessor.php           — orchestrates one row: attempt → classify → update
src/Services/Outbox/OutboxNotifier.php            — best-effort \Redis::xAdd()
src/Services/Outbox/RedisStreamConsumer.php       — XREADGROUP + XCLAIM loop primitive
src/Services/Outbox/ConsumerLoop.php              — long-lived loop body (tick/run)
src/Services/Outbox/BackstopRunner.php            — one-shot scan via OutboxRepository::pickupPending
src/Repositories/OutboxRepository.php             — PDO repo for openfga_outbox
src/Handlers/Admin/OutboxAdminHandler.php         — GET /admin/outbox, POST /admin/outbox/{id}/retry
bin/reconcile-outbox                              — CLI entry: subcommands `consumer` and `backstop`
deploy/systemd/liturgical-calendar-reconciler.service
deploy/cron/liturgical-calendar-backstop.cron
docs/ops/openfga-outbox-runbook.md
phpunit_tests/Services/Outbox/OutboxBackoffTest.php
phpunit_tests/Services/Outbox/OutboxClassifierTest.php
phpunit_tests/Services/Outbox/OutboxProcessorTest.php
phpunit_tests/Services/Outbox/OutboxNotifierTest.php
phpunit_tests/Services/Outbox/RedisStreamConsumerTest.php
phpunit_tests/Services/Outbox/BackstopRunnerTest.php
phpunit_tests/Services/Outbox/ConsumerLoopTest.php
phpunit_tests/Repositories/OutboxRepositoryTest.php
phpunit_tests/Handlers/Admin/OutboxAdminHandlerTest.php
```

### Modified files

```text
src/Handlers/Admin/AccessRequestAdminHandler.php  — approve/revoke switch to outbox pattern
src/Handlers/Admin/PermissionAdminHandler.php     — grant/revoke switch to outbox pattern
src/Services/RoleCascadeService.php               — silent catch → outbox enqueue
src/Router.php                                    — new /admin/outbox route
src/Health.php                                    — openfga_outbox block in health output
.env.example                                      — new keys
composer.json                                     — reconciler:consumer/backstop scripts
phpunit_tests/Handlers/Admin/AccessRequestAdminHandlerTest.php  — new tests for outbox path
phpunit_tests/Handlers/Admin/PermissionAdminHandlerTest.php     — new tests for outbox path
phpunit_tests/Repositories/RepositoryTestCase.php — add 'openfga_outbox' to TABLES const
```

---

## Phase 0 — Bootstrap

### Task 0: Verify branch and tooling

**Files:**

- Verify: `git rev-parse --abbrev-ref HEAD` returns `feature/openfga-async-reconciliation-design`

- [ ] **Step 1: Confirm branch**

Run: `git rev-parse --abbrev-ref HEAD`
Expected output: `feature/openfga-async-reconciliation-design`

- [ ] **Step 2: Confirm clean tooling**

Run: `composer analyse && composer lint && composer test`
Expected: all pass. (If `composer test` skips repository tests with "Postgres unreachable", run
`docker compose up -d --build` first; see prerequisites at top of plan.)

- [ ] **Step 3: Read the spec**

Read `docs/superpowers/specs/2026-06-02-openfga-async-reconciliation-design.md` end-to-end before
starting Phase 1.

---

## Phase 1 — Database schema

### Task 1: Generate migration scaffold

**Files:**

- Create: `src/Migrations/Version<generated>.php` (`composer db:migrations:generate` will pick the timestamp)

- [ ] **Step 1: Generate the migration**

Run: `composer db:migrations:generate`
Expected: prints `Generated new migration class to src/Migrations/Version<TIMESTAMP>.php`. Note the timestamp; use
it in commit messages for this task.

- [ ] **Step 2: Replace the scaffold**

Open the generated file and replace its body with:

```php
<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Migrations;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * OpenFGA async reconciliation outbox (Options B+C from issue #567).
 *
 * One row per OpenFGA tuple operation (write or delete) that the API has
 * committed to perform. The handler inserts the row in the same Postgres
 * transaction as the business write (e.g. access_requests.status = 'approved'),
 * so commit atomicity gives us durable intent. A systemd consumer drains via
 * Redis Streams on the fast path; a cron backstop catches the cracks.
 */
final class Version<TIMESTAMP> extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create openfga_outbox table for async reconciliation (issue #567 Options B+C)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !( $this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform ),
            'This migration targets PostgreSQL only.'
        );

        $this->addSql("CREATE TYPE outbox_op AS ENUM ('write_tuple', 'delete_tuple')");
        $this->addSql("CREATE TYPE outbox_status AS ENUM ('pending', 'retrying', 'succeeded', 'failed_terminal')");

        $this->addSql(<<<'SQL'
            CREATE TABLE openfga_outbox (
                id                BIGSERIAL    PRIMARY KEY,
                operation         outbox_op    NOT NULL,
                fga_user          TEXT         NOT NULL,
                fga_relation      TEXT         NOT NULL,
                fga_object        TEXT         NOT NULL,
                status            outbox_status NOT NULL DEFAULT 'pending',
                attempts          SMALLINT     NOT NULL DEFAULT 0,
                next_attempt_at   TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
                last_error        TEXT         NULL,
                last_error_code   TEXT         NULL,
                metadata          JSONB        NOT NULL DEFAULT '{}'::jsonb,
                created_at        TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
                completed_at      TIMESTAMPTZ  NULL,

                CONSTRAINT openfga_outbox_idempotency_unique
                    UNIQUE ((metadata->>'idempotency_key'))
            )
        SQL);

        $this->addSql(<<<'SQL'
            CREATE INDEX idx_outbox_pickup ON openfga_outbox (status, next_attempt_at)
                WHERE status IN ('pending', 'retrying')
        SQL);

        $this->addSql(<<<'SQL'
            CREATE INDEX idx_outbox_dlq ON openfga_outbox (status, created_at)
                WHERE status = 'failed_terminal'
        SQL);

        $this->addSql(<<<'SQL'
            CREATE INDEX idx_outbox_metadata_request ON openfga_outbox ((metadata->>'access_request_id'))
                WHERE metadata ? 'access_request_id'
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !( $this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform ),
            'This migration targets PostgreSQL only.'
        );

        $this->addSql('DROP TABLE IF EXISTS openfga_outbox');
        $this->addSql('DROP TYPE IF EXISTS outbox_status');
        $this->addSql('DROP TYPE IF EXISTS outbox_op');
    }
}
```

Replace `<TIMESTAMP>` in the class name with the actual timestamp from Step 1.

- [ ] **Step 3: Apply the migration**

Run: `composer db:migrate`
Expected output: `++ migrated (took <ms>, used <KB> memory)` for the new version. Migration status shows it as
applied.

- [ ] **Step 4: Sanity-check the schema**

Run: `psql "$DATABASE_URL" -c "\d openfga_outbox"` (or use Adminer at `localhost:8081`).
Expected: table exists with all columns, three indexes (`idx_outbox_pickup`, `idx_outbox_dlq`,
`idx_outbox_metadata_request`), one unique constraint.

- [ ] **Step 5: Commit**

```bash
git add src/Migrations/Version*.php
git commit -m "feat(outbox): create openfga_outbox table

One row per OpenFGA tuple operation. Atomically committed with the
business write; drained by the consumer (Redis Streams) and the cron
backstop. Partial indexes on (status, next_attempt_at) keep the pickup
hot path tight; UNIQUE constraint on metadata->>'idempotency_key' makes
re-issued handler calls a no-op insert.

Part of issue #567 Options B+C."
```

---

## Phase 2 — Pure types and logic (TDD)

### Task 2: OutboxOperation enum

**Files:**

- Create: `src/Services/Outbox/OutboxOperation.php`

- [ ] **Step 1: Write the file**

```php
<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services\Outbox;

/**
 * The two OpenFGA tuple operations the outbox tracks.
 *
 * Values match the `outbox_op` Postgres enum from
 * Version<TIMESTAMP> migration.
 */
enum OutboxOperation: string
{
    case WRITE_TUPLE  = 'write_tuple';
    case DELETE_TUPLE = 'delete_tuple';
}
```

- [ ] **Step 2: Verify it parses**

Run: `vendor/bin/parallel-lint src/Services/Outbox/OutboxOperation.php`
Expected: `No syntax error found`

- [ ] **Step 3: Commit**

```bash
git add src/Services/Outbox/OutboxOperation.php
git commit -m "feat(outbox): add OutboxOperation enum"
```

### Task 3: OutboxStatus enum

**Files:**

- Create: `src/Services/Outbox/OutboxStatus.php`

- [ ] **Step 1: Write the file**

```php
<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services\Outbox;

/**
 * Outbox row lifecycle states.
 *
 * Values match the `outbox_status` Postgres enum. State transitions:
 *
 *   pending → succeeded
 *   pending → retrying → retrying → ... → succeeded
 *   retrying → failed_terminal (attempts == 10 on transient, OR any 4xx classified TERMINAL)
 *   failed_terminal → pending (admin retry via POST /admin/outbox/{id}/retry)
 *
 * `succeeded` and `failed_terminal` are terminal unless the admin retry
 * endpoint resets the row back to `pending`.
 */
enum OutboxStatus: string
{
    case PENDING         = 'pending';
    case RETRYING        = 'retrying';
    case SUCCEEDED       = 'succeeded';
    case FAILED_TERMINAL = 'failed_terminal';
}
```

- [ ] **Step 2: Verify it parses**

Run: `vendor/bin/parallel-lint src/Services/Outbox/OutboxStatus.php`

- [ ] **Step 3: Commit**

```bash
git add src/Services/Outbox/OutboxStatus.php
git commit -m "feat(outbox): add OutboxStatus enum"
```

### Task 4: OutboxDisposition enum

**Files:**

- Create: `src/Services/Outbox/OutboxDisposition.php`

- [ ] **Step 1: Write the file**

```php
<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services\Outbox;

/**
 * What OutboxClassifier::classify decided about an exception.
 *
 * The processor consults this to decide whether to mark the row
 * succeeded, schedule a retry, or mark it failed_terminal.
 */
enum OutboxDisposition
{
    case BENIGN_SUCCESS;  // TupleAlreadyExists on write, TupleNotFound on delete
    case RETRY;           // 5xx, 429, network — schedule with backoff
    case TERMINAL;        // 4xx validation/auth — no retry, surface in DLQ
}
```

- [ ] **Step 2: Verify it parses**

Run: `vendor/bin/parallel-lint src/Services/Outbox/OutboxDisposition.php`

- [ ] **Step 3: Commit**

```bash
git add src/Services/Outbox/OutboxDisposition.php
git commit -m "feat(outbox): add OutboxDisposition enum"
```

### Task 5: OutboxBackoff — pure backoff function (TDD)

**Files:**

- Create: `src/Services/Outbox/OutboxBackoff.php`
- Test: `phpunit_tests/Services/Outbox/OutboxBackoffTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Services\Outbox;

use LiturgicalCalendar\Api\Services\Outbox\OutboxBackoff;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(OutboxBackoff::class)]
final class OutboxBackoffTest extends TestCase
{
    /**
     * @return iterable<string, array{int, int}>
     */
    public static function backoffCases(): iterable
    {
        yield 'attempt 1 → 1s'   => [1, 1];
        yield 'attempt 2 → 2s'   => [2, 2];
        yield 'attempt 3 → 4s'   => [3, 4];
        yield 'attempt 4 → 8s'   => [4, 8];
        yield 'attempt 5 → 16s'  => [5, 16];
        yield 'attempt 6 → 32s'  => [6, 32];
        yield 'attempt 7 → 64s'  => [7, 64];
        yield 'attempt 8 → 128s' => [8, 128];
        yield 'attempt 9 → 256s' => [9, 256];
        yield 'attempt 10 → 512s' => [10, 512];
        yield 'attempts past cap stay at 512s' => [99, 512];
    }

    #[DataProvider('backoffCases')]
    public function testSecondsForAttempt(int $attempts, int $expectedSeconds): void
    {
        self::assertSame($expectedSeconds, OutboxBackoff::secondsForAttempt($attempts));
    }

    public function testZeroAttemptsThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        OutboxBackoff::secondsForAttempt(0);
    }

    public function testNegativeAttemptsThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        OutboxBackoff::secondsForAttempt(-1);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit phpunit_tests/Services/Outbox/OutboxBackoffTest.php`
Expected: FAIL with `Error: Class "LiturgicalCalendar\Api\Services\Outbox\OutboxBackoff" not found`.

- [ ] **Step 3: Write minimal implementation**

```php
<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services\Outbox;

/**
 * Exponential backoff for outbox retries.
 *
 * Schedule: 1s, 2s, 4s, 8s, 16s, 32s, 64s, 128s, 256s, 512s — capped at
 * 2^9 = 512s. Total budget across 10 attempts: ~17 minutes.
 *
 * Pure function. Lives in its own file for testability and so the
 * schedule is editable in one place without touching the processor.
 */
final class OutboxBackoff
{
    private function __construct()
    {
    }

    /**
     * @param int $attempts The new attempt count (just incremented), 1..n.
     */
    public static function secondsForAttempt(int $attempts): int
    {
        if ($attempts < 1) {
            throw new \InvalidArgumentException('attempts must be >= 1');
        }

        return 1 << min($attempts - 1, 9);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit phpunit_tests/Services/Outbox/OutboxBackoffTest.php`
Expected: PASS, 12 tests.

- [ ] **Step 5: Commit**

```bash
git add src/Services/Outbox/OutboxBackoff.php phpunit_tests/Services/Outbox/OutboxBackoffTest.php
git commit -m "feat(outbox): exponential backoff (1s..512s, 10 attempts)"
```

### Task 6: OutboxClassifier (TDD)

**Files:**

- Create: `src/Services/Outbox/OutboxClassifier.php`
- Test: `phpunit_tests/Services/Outbox/OutboxClassifierTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Services\Outbox;

use LiturgicalCalendar\Api\Services\Exception\OpenFgaApiException;
use LiturgicalCalendar\Api\Services\Exception\TupleAlreadyExistsException;
use LiturgicalCalendar\Api\Services\Exception\TupleNotFoundException;
use LiturgicalCalendar\Api\Services\Outbox\OutboxClassifier;
use LiturgicalCalendar\Api\Services\Outbox\OutboxDisposition;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(OutboxClassifier::class)]
final class OutboxClassifierTest extends TestCase
{
    public function testTupleAlreadyExistsIsBenign(): void
    {
        $e = new TupleAlreadyExistsException('already exists', 400, 'cannot_allow_duplicate_tuple');
        self::assertSame(OutboxDisposition::BENIGN_SUCCESS, OutboxClassifier::classify($e));
    }

    public function testTupleNotFoundIsBenign(): void
    {
        $e = new TupleNotFoundException('not found', 400, 'cannot_allow_unknown_tuple_to_be_deleted');
        self::assertSame(OutboxDisposition::BENIGN_SUCCESS, OutboxClassifier::classify($e));
    }

    public function testValidationErrorIsTerminal(): void
    {
        $e = new OpenFgaApiException('invalid input', 400, 'validation_error');
        self::assertSame(OutboxDisposition::TERMINAL, OutboxClassifier::classify($e));
    }

    public function testInvalidInputFormatIsTerminal(): void
    {
        $e = new OpenFgaApiException('bad format', 400, 'invalid_input_format');
        self::assertSame(OutboxDisposition::TERMINAL, OutboxClassifier::classify($e));
    }

    public function testTypeNotFoundIsTerminal(): void
    {
        $e = new OpenFgaApiException('no such type', 400, 'type_not_found');
        self::assertSame(OutboxDisposition::TERMINAL, OutboxClassifier::classify($e));
    }

    public function testRelationNotFoundIsTerminal(): void
    {
        $e = new OpenFgaApiException('no such relation', 400, 'relation_not_found');
        self::assertSame(OutboxDisposition::TERMINAL, OutboxClassifier::classify($e));
    }

    public function testAuthFailureIsTerminal(): void
    {
        $e = new OpenFgaApiException('auth failure', 401, 'auth_failure');
        self::assertSame(OutboxDisposition::TERMINAL, OutboxClassifier::classify($e));
    }

    public function testUnauthenticatedIsTerminal(): void
    {
        $e = new OpenFgaApiException('unauthenticated', 401, 'unauthenticated');
        self::assertSame(OutboxDisposition::TERMINAL, OutboxClassifier::classify($e));
    }

    public function testRateLimitedIsRetry(): void
    {
        $e = new OpenFgaApiException('rate-limited', 429, null);
        self::assertSame(OutboxDisposition::RETRY, OutboxClassifier::classify($e));
    }

    public function test500IsRetry(): void
    {
        $e = new OpenFgaApiException('server error', 500, null);
        self::assertSame(OutboxDisposition::RETRY, OutboxClassifier::classify($e));
    }

    public function test503IsRetry(): void
    {
        $e = new OpenFgaApiException('unavailable', 503, null);
        self::assertSame(OutboxDisposition::RETRY, OutboxClassifier::classify($e));
    }

    public function testGenericRuntimeExceptionIsRetry(): void
    {
        // Network errors surface as \RuntimeException (Guzzle ConnectException) or similar.
        $e = new \RuntimeException('connection refused');
        self::assertSame(OutboxDisposition::RETRY, OutboxClassifier::classify($e));
    }

    public function testUnknownErrorCodeIsRetry(): void
    {
        // Safe default: anything we don't recognize gets retried.
        $e = new OpenFgaApiException('mystery', 418, 'i_am_a_teapot');
        self::assertSame(OutboxDisposition::RETRY, OutboxClassifier::classify($e));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit phpunit_tests/Services/Outbox/OutboxClassifierTest.php`
Expected: FAIL — `OutboxClassifier` not found.

- [ ] **Step 3: Write minimal implementation**

```php
<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services\Outbox;

use LiturgicalCalendar\Api\Services\Exception\OpenFgaApiException;
use LiturgicalCalendar\Api\Services\Exception\TupleAlreadyExistsException;
use LiturgicalCalendar\Api\Services\Exception\TupleNotFoundException;

/**
 * Stateless classifier that decides what to do with an exception raised
 * by an OpenFGA call inside OutboxProcessor.
 *
 * The mapping is canonical — every branch in OutboxProcessor consults
 * this. New OpenFGA error codes get a new test in OutboxClassifierTest
 * and a new arm in classify().
 */
final class OutboxClassifier
{
    /**
     * Error codes we recognize as admin-input bugs (no retry).
     *
     * Validation errors, type/relation lookups failing, auth failures —
     * retrying these 9 more times wastes work and pollutes metrics.
     *
     * @var list<string>
     */
    private const TERMINAL_CODES = [
        'validation_error',
        'invalid_input_format',
        'type_not_found',
        'relation_not_found',
        'auth_failure',
        'unauthenticated',
    ];

    private function __construct()
    {
    }

    public static function classify(\Throwable $e): OutboxDisposition
    {
        if ($e instanceof TupleAlreadyExistsException || $e instanceof TupleNotFoundException) {
            return OutboxDisposition::BENIGN_SUCCESS;
        }

        if ($e instanceof OpenFgaApiException) {
            $code = $e->getErrorCode();
            if ($code !== null && in_array($code, self::TERMINAL_CODES, true)) {
                return OutboxDisposition::TERMINAL;
            }
            // 429 / 5xx / unknown errorCode → retry.
            return OutboxDisposition::RETRY;
        }

        // Network errors, unexpected RuntimeException, anything else — retry.
        // The 10-attempt budget keeps us from looping forever on bugs.
        return OutboxDisposition::RETRY;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit phpunit_tests/Services/Outbox/OutboxClassifierTest.php`
Expected: PASS, 13 tests.

- [ ] **Step 5: Static analysis**

Run: `composer analyse -- src/Services/Outbox/OutboxClassifier.php`
Expected: clean.

- [ ] **Step 6: Commit**

```bash
git add src/Services/Outbox/OutboxClassifier.php phpunit_tests/Services/Outbox/OutboxClassifierTest.php
git commit -m "feat(outbox): error classifier (benign/retry/terminal)"
```

---

## Phase 3 — Repository

### Task 7: OutboxRow value object

**Files:**

- Create: `src/Services/Outbox/OutboxRow.php`

- [ ] **Step 1: Write the file**

```php
<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services\Outbox;

/**
 * Readonly snapshot of one openfga_outbox row in transit between
 * OutboxRepository and OutboxProcessor.
 *
 * Repository hydrates these from PG; processor reads them, performs the
 * OpenFGA call, then calls back into the repository to update the
 * underlying row. The row object itself does not mutate — re-read after
 * an update to see the new state.
 */
final class OutboxRow
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public readonly int $id,
        public readonly OutboxOperation $operation,
        public readonly string $fgaUser,
        public readonly string $fgaRelation,
        public readonly string $fgaObject,
        public readonly OutboxStatus $status,
        public readonly int $attempts,
        public readonly \DateTimeImmutable $nextAttemptAt,
        public readonly ?string $lastError,
        public readonly ?string $lastErrorCode,
        public readonly array $metadata,
        public readonly \DateTimeImmutable $createdAt,
        public readonly ?\DateTimeImmutable $completedAt,
    ) {
    }
}
```

- [ ] **Step 2: Parse-check**

Run: `vendor/bin/parallel-lint src/Services/Outbox/OutboxRow.php`

- [ ] **Step 3: Commit**

```bash
git add src/Services/Outbox/OutboxRow.php
git commit -m "feat(outbox): OutboxRow readonly value object"
```

### Task 8: Add openfga_outbox to RepositoryTestCase truncation list

**Files:**

- Modify: `phpunit_tests/Repositories/RepositoryTestCase.php` — the `TABLES` const

- [ ] **Step 1: Edit the TABLES const**

Find:

```php
protected const TABLES = ['api_keys', 'applications', 'access_requests', 'audit_log'];
```

Replace with:

```php
protected const TABLES = ['api_keys', 'applications', 'access_requests', 'audit_log', 'openfga_outbox'];
```

- [ ] **Step 2: Commit**

```bash
git add phpunit_tests/Repositories/RepositoryTestCase.php
git commit -m "test(outbox): truncate openfga_outbox between repository tests"
```

### Task 9: OutboxRepository::insertBatch + idempotency (TDD)

**Files:**

- Create: `src/Repositories/OutboxRepository.php`
- Test: `phpunit_tests/Repositories/OutboxRepositoryTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Repositories;

use LiturgicalCalendar\Api\Repositories\OutboxRepository;
use LiturgicalCalendar\Api\Services\Outbox\OutboxOperation;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(OutboxRepository::class)]
final class OutboxRepositoryTest extends RepositoryTestCase
{
    private OutboxRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new OutboxRepository(self::$pdo);
    }

    /**
     * @return list<array{operation: OutboxOperation, fga_user: string, fga_relation: string, fga_object: string, idempotency_key: string, metadata: array<string,mixed>}>
     */
    private function samplePayload(): array
    {
        return [
            [
                'operation'       => OutboxOperation::WRITE_TUPLE,
                'fga_user'        => 'user:alice',
                'fga_relation'    => 'editor',
                'fga_object'      => 'national_calendar:IT',
                'idempotency_key' => 'access_request:r1:write_tuple:user:alice:editor:national_calendar:IT',
                'metadata'        => ['access_request_id' => 'r1', 'admin_user' => 'admin:bob'],
            ],
            [
                'operation'       => OutboxOperation::WRITE_TUPLE,
                'fga_user'        => 'user:alice',
                'fga_relation'    => 'viewer',
                'fga_object'      => 'diocesan_calendar:romamo_it',
                'idempotency_key' => 'access_request:r1:write_tuple:user:alice:viewer:diocesan_calendar:romamo_it',
                'metadata'        => ['access_request_id' => 'r1', 'admin_user' => 'admin:bob'],
            ],
        ];
    }

    public function testInsertBatchReturnsRowIds(): void
    {
        $ids = $this->repo->insertBatch($this->samplePayload());

        self::assertCount(2, $ids);
        self::assertContainsOnly('int', $ids);
        self::assertGreaterThan(0, $ids[0]);
        self::assertGreaterThan(0, $ids[1]);
        self::assertNotSame($ids[0], $ids[1]);
    }

    public function testInsertBatchIsIdempotentOnDuplicateKey(): void
    {
        $firstIds  = $this->repo->insertBatch($this->samplePayload());
        // Re-insert the same payload — same idempotency keys, no new rows.
        $secondIds = $this->repo->insertBatch($this->samplePayload());

        // Same IDs returned both times.
        self::assertSame($firstIds, $secondIds);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit phpunit_tests/Repositories/OutboxRepositoryTest.php`
Expected: FAIL — `OutboxRepository` not found.

- [ ] **Step 3: Write minimal implementation**

```php
<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Repositories;

use LiturgicalCalendar\Api\Services\Outbox\OutboxOperation;
use LiturgicalCalendar\Api\Services\Outbox\OutboxRow;
use LiturgicalCalendar\Api\Services\Outbox\OutboxStatus;
use PDO;

/**
 * PDO repository for the openfga_outbox table.
 *
 * Sole writer of outbox rows. Hot path is insertBatch (called inside the
 * handler's tx with the business write) and pickupPending (the consumer
 * and backstop both call this).
 */
final class OutboxRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * Insert a batch of outbox rows, idempotent on idempotency_key.
     *
     * Returns the row IDs in the same order as the input payload.
     *
     * @param list<array{
     *     operation: OutboxOperation,
     *     fga_user: string,
     *     fga_relation: string,
     *     fga_object: string,
     *     idempotency_key: string,
     *     metadata: array<string, mixed>
     * }> $rows
     * @return list<int>
     */
    public function insertBatch(array $rows): array
    {
        if (empty($rows)) {
            return [];
        }

        $ids = [];
        $insert = $this->db->prepare(<<<'SQL'
            INSERT INTO openfga_outbox (operation, fga_user, fga_relation, fga_object, metadata)
            VALUES (:operation, :fga_user, :fga_relation, :fga_object, :metadata::jsonb)
            ON CONFLICT ((metadata->>'idempotency_key')) DO NOTHING
            RETURNING id
        SQL);

        $select = $this->db->prepare(<<<'SQL'
            SELECT id FROM openfga_outbox WHERE metadata->>'idempotency_key' = :key
        SQL);

        foreach ($rows as $row) {
            // The idempotency_key must live inside the metadata JSONB so the
            // expression UNIQUE index catches conflicts.
            $metadata                    = $row['metadata'];
            $metadata['idempotency_key'] = $row['idempotency_key'];

            $insert->execute([
                ':operation'    => $row['operation']->value,
                ':fga_user'     => $row['fga_user'],
                ':fga_relation' => $row['fga_relation'],
                ':fga_object'   => $row['fga_object'],
                ':metadata'     => json_encode($metadata, JSON_THROW_ON_ERROR),
            ]);

            $insertedId = $insert->fetchColumn();
            if ($insertedId !== false) {
                $ids[] = (int) $insertedId;
                continue;
            }

            // Conflict path — the row already exists. Look up its ID.
            $select->execute([':key' => $row['idempotency_key']]);
            $existingId = $select->fetchColumn();
            if ($existingId === false) {
                throw new \RuntimeException(sprintf(
                    'OutboxRepository::insertBatch: conflict on key %s but row not found',
                    $row['idempotency_key']
                ));
            }
            $ids[] = (int) $existingId;
        }

        return $ids;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit phpunit_tests/Repositories/OutboxRepositoryTest.php`
Expected: PASS, 2 tests.

- [ ] **Step 5: Commit**

```bash
git add src/Repositories/OutboxRepository.php phpunit_tests/Repositories/OutboxRepositoryTest.php
git commit -m "feat(outbox): repository.insertBatch with idempotency"
```

### Task 10: OutboxRepository::getById (TDD)

**Files:**

- Modify: `src/Repositories/OutboxRepository.php`
- Modify: `phpunit_tests/Repositories/OutboxRepositoryTest.php`

- [ ] **Step 1: Add the failing test**

Append to `OutboxRepositoryTest`:

```php
    public function testGetByIdHydratesAllFields(): void
    {
        [$id1, ] = $this->repo->insertBatch($this->samplePayload());

        $row = $this->repo->getById($id1);

        self::assertNotNull($row);
        self::assertSame($id1, $row->id);
        self::assertSame(OutboxOperation::WRITE_TUPLE, $row->operation);
        self::assertSame('user:alice', $row->fgaUser);
        self::assertSame('editor', $row->fgaRelation);
        self::assertSame('national_calendar:IT', $row->fgaObject);
        self::assertSame(OutboxStatus::PENDING, $row->status);
        self::assertSame(0, $row->attempts);
        self::assertNull($row->lastError);
        self::assertNull($row->lastErrorCode);
        self::assertSame('r1', $row->metadata['access_request_id']);
        self::assertNull($row->completedAt);
    }

    public function testGetByIdReturnsNullForMissingRow(): void
    {
        self::assertNull($this->repo->getById(999999));
    }
```

Add the `use` statements at the top of the test file for `OutboxStatus`.

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit phpunit_tests/Repositories/OutboxRepositoryTest.php`
Expected: FAIL on the two new methods — `getById` not defined.

- [ ] **Step 3: Add the method**

Add to `OutboxRepository`:

```php
    public function getById(int $id): ?OutboxRow
    {
        $stmt = $this->db->prepare(<<<'SQL'
            SELECT id, operation, fga_user, fga_relation, fga_object,
                   status, attempts, next_attempt_at, last_error, last_error_code,
                   metadata, created_at, completed_at
            FROM openfga_outbox
            WHERE id = :id
        SQL);
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        return $this->hydrate($row);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): OutboxRow
    {
        $metadataJson = is_string($row['metadata']) ? $row['metadata'] : '{}';
        /** @var array<string, mixed> $metadata */
        $metadata = json_decode($metadataJson, true, flags: JSON_THROW_ON_ERROR);

        return new OutboxRow(
            id: (int) $row['id'],
            operation: OutboxOperation::from((string) $row['operation']),
            fgaUser: (string) $row['fga_user'],
            fgaRelation: (string) $row['fga_relation'],
            fgaObject: (string) $row['fga_object'],
            status: OutboxStatus::from((string) $row['status']),
            attempts: (int) $row['attempts'],
            nextAttemptAt: new \DateTimeImmutable((string) $row['next_attempt_at']),
            lastError: $row['last_error'] !== null ? (string) $row['last_error'] : null,
            lastErrorCode: $row['last_error_code'] !== null ? (string) $row['last_error_code'] : null,
            metadata: $metadata,
            createdAt: new \DateTimeImmutable((string) $row['created_at']),
            completedAt: $row['completed_at'] !== null ? new \DateTimeImmutable((string) $row['completed_at']) : null,
        );
    }
```

- [ ] **Step 4: Run tests**

Run: `vendor/bin/phpunit phpunit_tests/Repositories/OutboxRepositoryTest.php`
Expected: PASS, 4 tests.

- [ ] **Step 5: Commit**

```bash
git add src/Repositories/OutboxRepository.php phpunit_tests/Repositories/OutboxRepositoryTest.php
git commit -m "feat(outbox): repository.getById with row hydration"
```

### Task 11: OutboxRepository::markSucceeded, markRetryable, markFailedTerminal (TDD)

**Files:**

- Modify: `src/Repositories/OutboxRepository.php`
- Modify: `phpunit_tests/Repositories/OutboxRepositoryTest.php`

- [ ] **Step 1: Add the failing tests**

Append to `OutboxRepositoryTest`:

```php
    public function testMarkSucceededSetsTerminalStateAndCompletedAt(): void
    {
        [$id, ] = $this->repo->insertBatch($this->samplePayload());

        $this->repo->markSucceeded($id);

        $row = $this->repo->getById($id);
        self::assertNotNull($row);
        self::assertSame(OutboxStatus::SUCCEEDED, $row->status);
        self::assertNotNull($row->completedAt);
    }

    public function testMarkSucceededOnAlreadyTerminalIsNoOp(): void
    {
        [$id, ] = $this->repo->insertBatch($this->samplePayload());
        $this->repo->markSucceeded($id);
        $firstCompleted = $this->repo->getById($id)?->completedAt;

        // Sleep 1 second so completed_at would observably change if a second call rewrote it.
        sleep(1);
        $this->repo->markSucceeded($id);

        $secondCompleted = $this->repo->getById($id)?->completedAt;
        self::assertEquals($firstCompleted, $secondCompleted, 'completed_at must not change on second markSucceeded');
    }

    public function testMarkRetryableIncrementsAttemptsAndSchedulesNext(): void
    {
        [$id, ] = $this->repo->insertBatch($this->samplePayload());
        $nextAt = (new \DateTimeImmutable('now'))->modify('+8 seconds');

        $this->repo->markRetryable($id, attempts: 3, nextAttemptAt: $nextAt, lastError: 'OpenFGA 503', lastErrorCode: null);

        $row = $this->repo->getById($id);
        self::assertNotNull($row);
        self::assertSame(OutboxStatus::RETRYING, $row->status);
        self::assertSame(3, $row->attempts);
        self::assertSame('OpenFGA 503', $row->lastError);
        self::assertEqualsWithDelta(
            $nextAt->getTimestamp(),
            $row->nextAttemptAt->getTimestamp(),
            1.0,
            'next_attempt_at within 1s of requested',
        );
    }

    public function testMarkFailedTerminalIsSticky(): void
    {
        [$id, ] = $this->repo->insertBatch($this->samplePayload());

        $this->repo->markFailedTerminal($id, lastError: 'validation_error', lastErrorCode: 'validation_error');

        $row = $this->repo->getById($id);
        self::assertNotNull($row);
        self::assertSame(OutboxStatus::FAILED_TERMINAL, $row->status);
        self::assertSame('validation_error', $row->lastErrorCode);

        // Subsequent markRetryable on a terminal row must NOT downgrade it.
        $this->repo->markRetryable($id, attempts: 4, nextAttemptAt: new \DateTimeImmutable(), lastError: 'late retry', lastErrorCode: null);
        $row = $this->repo->getById($id);
        self::assertNotNull($row);
        self::assertSame(
            OutboxStatus::FAILED_TERMINAL,
            $row->status,
            'markRetryable must not overwrite a terminal status',
        );
    }
```

- [ ] **Step 2: Run tests — they must fail**

Run: `vendor/bin/phpunit phpunit_tests/Repositories/OutboxRepositoryTest.php`
Expected: FAIL on the four new methods.

- [ ] **Step 3: Add the methods**

Add to `OutboxRepository`:

```php
    public function markSucceeded(int $id): void
    {
        // Guard against terminal-status downgrades: only mark succeeded if currently
        // in a non-terminal state. Re-applying succeeded is a no-op (completed_at preserved).
        $stmt = $this->db->prepare(<<<'SQL'
            UPDATE openfga_outbox
            SET status = 'succeeded',
                completed_at = COALESCE(completed_at, NOW())
            WHERE id = :id AND status IN ('pending', 'retrying', 'succeeded')
        SQL);
        $stmt->execute([':id' => $id]);
    }

    public function markRetryable(
        int $id,
        int $attempts,
        \DateTimeImmutable $nextAttemptAt,
        string $lastError,
        ?string $lastErrorCode,
    ): void {
        // Only transition out of pending/retrying. A failed_terminal row must
        // stay terminal — admin retry has its own path (resetForRetry).
        $stmt = $this->db->prepare(<<<'SQL'
            UPDATE openfga_outbox
            SET status = 'retrying',
                attempts = :attempts,
                next_attempt_at = :next_attempt_at,
                last_error = :last_error,
                last_error_code = :last_error_code
            WHERE id = :id AND status IN ('pending', 'retrying')
        SQL);
        $stmt->execute([
            ':id'              => $id,
            ':attempts'        => $attempts,
            ':next_attempt_at' => $nextAttemptAt->format('Y-m-d H:i:sP'),
            ':last_error'      => $lastError,
            ':last_error_code' => $lastErrorCode,
        ]);
    }

    public function markFailedTerminal(int $id, string $lastError, ?string $lastErrorCode): void
    {
        $stmt = $this->db->prepare(<<<'SQL'
            UPDATE openfga_outbox
            SET status = 'failed_terminal',
                last_error = :last_error,
                last_error_code = :last_error_code,
                completed_at = NOW()
            WHERE id = :id AND status IN ('pending', 'retrying')
        SQL);
        $stmt->execute([
            ':id'              => $id,
            ':last_error'      => $lastError,
            ':last_error_code' => $lastErrorCode,
        ]);
    }
```

- [ ] **Step 4: Run tests**

Run: `vendor/bin/phpunit phpunit_tests/Repositories/OutboxRepositoryTest.php`
Expected: PASS, 8 tests.

- [ ] **Step 5: Commit**

```bash
git add src/Repositories/OutboxRepository.php phpunit_tests/Repositories/OutboxRepositoryTest.php
git commit -m "feat(outbox): markSucceeded/markRetryable/markFailedTerminal with terminal stickiness"
```

### Task 12: OutboxRepository::pickupPending with FOR UPDATE SKIP LOCKED (TDD)

**Files:**

- Modify: `src/Repositories/OutboxRepository.php`
- Modify: `phpunit_tests/Repositories/OutboxRepositoryTest.php`

- [ ] **Step 1: Add the failing tests**

Append to `OutboxRepositoryTest`:

```php
    public function testPickupPendingReturnsOnlyEligibleRows(): void
    {
        $ids = $this->repo->insertBatch($this->samplePayload());
        $this->repo->markSucceeded($ids[0]); // exclude succeeded
        $this->repo->markFailedTerminal($ids[1], 'x', null); // exclude failed_terminal

        $picked = $this->repo->pickupPending(limit: 10, now: new \DateTimeImmutable());

        self::assertSame([], $picked, 'no eligible rows after both are terminal');
    }

    public function testPickupPendingRespectsNextAttemptAt(): void
    {
        [$id] = $this->repo->insertBatch([$this->samplePayload()[0]]);

        // Schedule the next attempt 60 seconds into the future.
        $this->repo->markRetryable(
            $id,
            attempts: 1,
            nextAttemptAt: (new \DateTimeImmutable())->modify('+60 seconds'),
            lastError: 'transient',
            lastErrorCode: null,
        );

        $tooEarly = $this->repo->pickupPending(limit: 10, now: new \DateTimeImmutable());
        self::assertSame([], $tooEarly);

        // Far-future cutoff should pick it up.
        $picked = $this->repo->pickupPending(limit: 10, now: (new \DateTimeImmutable())->modify('+120 seconds'));
        self::assertCount(1, $picked);
        self::assertSame($id, $picked[0]->id);
    }

    /**
     * Two concurrent transactions must each get distinct rows
     * thanks to FOR UPDATE SKIP LOCKED. This is the load-bearing
     * concurrency test for the consumer + backstop topology.
     */
    public function testPickupPendingSkipLockedSeparatesConcurrentRunners(): void
    {
        $ids = $this->repo->insertBatch($this->samplePayload()); // 2 rows

        // Open a second PDO connection — both share the same Postgres DB.
        $other = new \PDO(
            (string) self::$pdo->getAttribute(\PDO::ATTR_CONNECTION_STATUS),
            (string) ( $_ENV['DB_USER'] ?? '' ),
            (string) ( $_ENV['DB_PASSWORD'] ?? '' ),
            [
                \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_EMULATE_PREPARES   => false,
            ],
        );
        $other->exec("SET timezone TO 'Europe/Vatican'");
        $otherRepo = new OutboxRepository($other);

        // Both runners attempt to pick up; SKIP LOCKED must give each one a distinct row.
        self::$pdo->beginTransaction();
        $picked1 = $this->repo->pickupPending(limit: 10, now: new \DateTimeImmutable());

        $other->beginTransaction();
        $picked2 = $otherRepo->pickupPending(limit: 10, now: new \DateTimeImmutable());

        self::$pdo->commit();
        $other->commit();

        $idsPicked = array_merge(
            array_map(static fn ($r) => $r->id, $picked1),
            array_map(static fn ($r) => $r->id, $picked2),
        );
        sort($idsPicked);
        sort($ids);
        self::assertSame($ids, $idsPicked, 'two transactions must collectively pick up every row exactly once');
        self::assertCount(1, $picked1);
        self::assertCount(1, $picked2);
    }
```

NOTE on the second-PDO setup: `RepositoryTestCase` exposes `DB_HOST` etc. via `self::env()`. If your local DSN
construction differs from `pgsql:host=...;port=...;dbname=...`, mirror what `RepositoryTestCase::setUpBeforeClass`
builds — the simplest fix is to add a `protected static function makeSecondPdo(): \PDO` to `RepositoryTestCase`
that reuses the env reading and returns a fresh connection, then call that here. Do that refactor if/when the
inline DSN feels awkward; it's not required for v1.

- [ ] **Step 2: Run tests — they must fail**

Run: `vendor/bin/phpunit phpunit_tests/Repositories/OutboxRepositoryTest.php`
Expected: FAIL on `pickupPending`.

- [ ] **Step 3: Add the method**

Add to `OutboxRepository`:

```php
    /**
     * Pick up rows ready for the consumer / backstop to process.
     *
     * Uses FOR UPDATE SKIP LOCKED so concurrent runners don't collide:
     * each runner gets a distinct slice of the eligible rows. Caller is
     * responsible for COMMIT/ROLLBACK of the surrounding transaction
     * (the lock is held until the runner finishes processing or rolls
     * back).
     *
     * @return list<OutboxRow>
     */
    public function pickupPending(int $limit, \DateTimeImmutable $now): array
    {
        $stmt = $this->db->prepare(<<<'SQL'
            SELECT id, operation, fga_user, fga_relation, fga_object,
                   status, attempts, next_attempt_at, last_error, last_error_code,
                   metadata, created_at, completed_at
            FROM openfga_outbox
            WHERE status IN ('pending', 'retrying')
              AND next_attempt_at <= :now
            ORDER BY next_attempt_at ASC, id ASC
            LIMIT :limit
            FOR UPDATE SKIP LOCKED
        SQL);
        $stmt->bindValue(':now', $now->format('Y-m-d H:i:sP'), PDO::PARAM_STR);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        $rows = [];
        while (($r = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
            $rows[] = $this->hydrate($r);
        }
        return $rows;
    }
```

- [ ] **Step 4: Run tests**

Run: `vendor/bin/phpunit phpunit_tests/Repositories/OutboxRepositoryTest.php`
Expected: PASS, 11 tests.

- [ ] **Step 5: Commit**

```bash
git add src/Repositories/OutboxRepository.php phpunit_tests/Repositories/OutboxRepositoryTest.php
git commit -m "feat(outbox): repository.pickupPending with FOR UPDATE SKIP LOCKED"
```

### Task 13: OutboxRepository::resetForRetry + countByStatus (TDD)

**Files:**

- Modify: `src/Repositories/OutboxRepository.php`
- Modify: `phpunit_tests/Repositories/OutboxRepositoryTest.php`

- [ ] **Step 1: Add the failing tests**

```php
    public function testResetForRetryClearsAttemptsAndStatus(): void
    {
        [$id] = $this->repo->insertBatch([$this->samplePayload()[0]]);
        $this->repo->markFailedTerminal($id, 'validation_error', 'validation_error');

        $changed = $this->repo->resetForRetry($id);

        self::assertTrue($changed, 'resetForRetry must return true when a row was reset');
        $row = $this->repo->getById($id);
        self::assertNotNull($row);
        self::assertSame(OutboxStatus::PENDING, $row->status);
        self::assertSame(0, $row->attempts);
        self::assertNull($row->lastError);
        self::assertNull($row->lastErrorCode);
        self::assertNull($row->completedAt);
    }

    public function testResetForRetryReturnsFalseForNonTerminalRow(): void
    {
        [$id] = $this->repo->insertBatch([$this->samplePayload()[0]]);
        // Row is still 'pending' — admin retry must refuse.
        $changed = $this->repo->resetForRetry($id);
        self::assertFalse($changed);
    }

    public function testCountByStatusBucketsAllFour(): void
    {
        $ids = $this->repo->insertBatch($this->samplePayload());
        $this->repo->markSucceeded($ids[0]);
        $this->repo->markFailedTerminal($ids[1], 'x', null);

        $counts = $this->repo->countByStatus();

        self::assertSame(0, $counts['pending'] ?? 0);
        self::assertSame(0, $counts['retrying'] ?? 0);
        self::assertSame(1, $counts['succeeded'] ?? 0);
        self::assertSame(1, $counts['failed_terminal'] ?? 0);
    }
```

- [ ] **Step 2: Verify they fail**

Run: `vendor/bin/phpunit phpunit_tests/Repositories/OutboxRepositoryTest.php`
Expected: FAIL on the three new methods.

- [ ] **Step 3: Add the methods**

```php
    /**
     * Reset a failed_terminal row back to pending so the consumer/backstop
     * will retry it. Only failed_terminal rows are eligible — admin retry on
     * a pending/retrying row is a 409 from the handler.
     *
     * Returns true if a row was reset; false if the row was not in
     * failed_terminal state.
     */
    public function resetForRetry(int $id): bool
    {
        $stmt = $this->db->prepare(<<<'SQL'
            UPDATE openfga_outbox
            SET status = 'pending',
                attempts = 0,
                last_error = NULL,
                last_error_code = NULL,
                completed_at = NULL,
                next_attempt_at = NOW()
            WHERE id = :id AND status = 'failed_terminal'
        SQL);
        $stmt->execute([':id' => $id]);
        return $stmt->rowCount() === 1;
    }

    /**
     * @return array<string, int>
     */
    public function countByStatus(): array
    {
        $stmt = $this->db->query(<<<'SQL'
            SELECT status::text AS status, COUNT(*)::int AS n
            FROM openfga_outbox
            GROUP BY status
        SQL);
        $out = [];
        if ($stmt !== false) {
            /** @var array{status: string, n: int} $r */
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $out[$r['status']] = $r['n'];
            }
        }
        return $out;
    }
```

- [ ] **Step 4: Run tests**

Run: `vendor/bin/phpunit phpunit_tests/Repositories/OutboxRepositoryTest.php`
Expected: PASS, 14 tests.

- [ ] **Step 5: Static analysis**

Run: `composer analyse -- src/Repositories/OutboxRepository.php`
Expected: clean.

- [ ] **Step 6: Commit**

```bash
git add src/Repositories/OutboxRepository.php phpunit_tests/Repositories/OutboxRepositoryTest.php
git commit -m "feat(outbox): repository.resetForRetry and countByStatus"
```

---

## Phase 4 — Processor

### Task 14: OutboxProcessor (TDD, the core orchestration)

**Files:**

- Create: `src/Services/Outbox/OutboxProcessor.php`
- Create: `phpunit_tests/Services/Outbox/OutboxProcessorTest.php`

- [ ] **Step 1: Write the failing test**

This test uses `MockHandler`-backed `OpenFgaClient` (the pattern from `OpenFgaClientTest` established in PR #628)
plus a real `OutboxRepository` against the test Postgres.

```php
<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Services\Outbox;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use LiturgicalCalendar\Api\Repositories\OutboxRepository;
use LiturgicalCalendar\Api\Services\OpenFgaClient;
use LiturgicalCalendar\Api\Services\Outbox\OutboxDisposition;
use LiturgicalCalendar\Api\Services\Outbox\OutboxOperation;
use LiturgicalCalendar\Api\Services\Outbox\OutboxProcessor;
use LiturgicalCalendar\Api\Services\Outbox\OutboxStatus;
use LiturgicalCalendar\Tests\Repositories\RepositoryTestCase;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(OutboxProcessor::class)]
final class OutboxProcessorTest extends RepositoryTestCase
{
    private OutboxRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = new OutboxRepository(self::$pdo);
    }

    private function makeClient(MockHandler $mock): OpenFgaClient
    {
        $psr17 = new Psr17Factory();
        return new OpenFgaClient(
            'http://localhost:8083',
            'store-123',
            'model-456',
            new Client(['handler' => HandlerStack::create($mock)]),
            $psr17,
            $psr17,
        );
    }

    /**
     * @return list<int>
     */
    private function seedOneWrite(): array
    {
        return $this->repo->insertBatch([
            [
                'operation'       => OutboxOperation::WRITE_TUPLE,
                'fga_user'        => 'user:alice',
                'fga_relation'    => 'editor',
                'fga_object'      => 'national_calendar:IT',
                'idempotency_key' => 'k-' . bin2hex(random_bytes(4)),
                'metadata'        => ['access_request_id' => 'r1'],
            ],
        ]);
    }

    public function testProcessOneSuccessMarksSucceeded(): void
    {
        [$id] = $this->seedOneWrite();
        $mock = new MockHandler([new Response(200, [], '')]);
        $proc = new OutboxProcessor($this->repo, $this->makeClient($mock));

        $disp = $proc->processOne($id);

        self::assertSame(OutboxDisposition::BENIGN_SUCCESS->name, $disp->name === OutboxDisposition::BENIGN_SUCCESS->name ? $disp->name : $disp->name);
        $row = $this->repo->getById($id);
        self::assertNotNull($row);
        self::assertSame(OutboxStatus::SUCCEEDED, $row->status);
    }

    public function testProcessOneTransientSchedulesRetryWithCorrectBackoff(): void
    {
        [$id] = $this->seedOneWrite();
        $mock = new MockHandler([
            new Response(503, [], (string) json_encode(['code' => 'temporarily_unavailable', 'message' => 'try again'])),
        ]);
        $proc = new OutboxProcessor($this->repo, $this->makeClient($mock));

        $proc->processOne($id);

        $row = $this->repo->getById($id);
        self::assertNotNull($row);
        self::assertSame(OutboxStatus::RETRYING, $row->status);
        self::assertSame(1, $row->attempts);
        $delta = $row->nextAttemptAt->getTimestamp() - (new \DateTimeImmutable())->getTimestamp();
        self::assertGreaterThanOrEqual(0, $delta);
        self::assertLessThanOrEqual(2, $delta, 'attempts=1 should schedule ~1s ahead');
    }

    public function testProcessOneValidationErrorMarksTerminalOnFirstAttempt(): void
    {
        [$id] = $this->seedOneWrite();
        $mock = new MockHandler([
            new Response(400, [], (string) json_encode(['code' => 'validation_error', 'message' => 'bad type'])),
        ]);
        $proc = new OutboxProcessor($this->repo, $this->makeClient($mock));

        $proc->processOne($id);

        $row = $this->repo->getById($id);
        self::assertNotNull($row);
        self::assertSame(OutboxStatus::FAILED_TERMINAL, $row->status);
        self::assertSame('validation_error', $row->lastErrorCode);
    }

    public function test10thAttemptOnTransientMarksTerminal(): void
    {
        [$id] = $this->seedOneWrite();
        // Pre-set the row to attempts=9 and retrying so this call is the 10th.
        $this->repo->markRetryable(
            $id,
            attempts: 9,
            nextAttemptAt: new \DateTimeImmutable('-1 second'),
            lastError: 'prior transient',
            lastErrorCode: null,
        );
        $mock = new MockHandler([new Response(503, [], '')]);
        $proc = new OutboxProcessor($this->repo, $this->makeClient($mock));

        $proc->processOne($id);

        $row = $this->repo->getById($id);
        self::assertNotNull($row);
        self::assertSame(
            OutboxStatus::FAILED_TERMINAL,
            $row->status,
            '10th attempt on transient must transition to failed_terminal',
        );
    }

    public function testProcessOneBenignAlreadyExistsCountsAsSuccess(): void
    {
        [$id] = $this->seedOneWrite();
        $mock = new MockHandler([
            new Response(400, [], (string) json_encode([
                'code'    => 'cannot_allow_duplicate_tuple',
                'message' => 'tuple already exists',
            ])),
        ]);
        $proc = new OutboxProcessor($this->repo, $this->makeClient($mock));

        $proc->processOne($id);

        $row = $this->repo->getById($id);
        self::assertNotNull($row);
        self::assertSame(OutboxStatus::SUCCEEDED, $row->status);
    }

    public function testProcessOneOnTerminalRowIsNoOp(): void
    {
        [$id] = $this->seedOneWrite();
        $this->repo->markSucceeded($id);
        $mock = new MockHandler([]); // No OpenFGA call should be made.
        $proc = new OutboxProcessor($this->repo, $this->makeClient($mock));

        // Should not throw despite MockHandler being empty.
        $proc->processOne($id);

        $row = $this->repo->getById($id);
        self::assertNotNull($row);
        self::assertSame(OutboxStatus::SUCCEEDED, $row->status);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit phpunit_tests/Services/Outbox/OutboxProcessorTest.php`
Expected: FAIL — `OutboxProcessor` not found.

- [ ] **Step 3: Write the implementation**

```php
<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services\Outbox;

use LiturgicalCalendar\Api\Repositories\OutboxRepository;
use LiturgicalCalendar\Api\Services\Exception\OpenFgaApiException;
use LiturgicalCalendar\Api\Services\OpenFgaClient;

/**
 * Single point of contact between the outbox and OpenFGA.
 *
 * Called from three places: the handler's sync fast path (after the
 * surrounding tx has committed), the consumer's XREADGROUP loop, and
 * the cron backstop's pickupPending scan. They all use the same
 * processOne() so classification, retry scheduling, and status
 * transitions live in exactly one file.
 *
 * processOne() is idempotent on terminal rows — re-running on a
 * succeeded or failed_terminal row is a no-op.
 */
final class OutboxProcessor
{
    private const MAX_ATTEMPTS = 10;

    public function __construct(
        private readonly OutboxRepository $repo,
        private readonly OpenFgaClient $client,
    ) {
    }

    public function processOne(int $rowId): OutboxDisposition
    {
        $row = $this->repo->getById($rowId);
        if ($row === null) {
            // Row was deleted between pickup and processOne — nothing to do.
            return OutboxDisposition::BENIGN_SUCCESS;
        }

        if ($row->status === OutboxStatus::SUCCEEDED || $row->status === OutboxStatus::FAILED_TERMINAL) {
            // Idempotency anchor — re-running on a terminal row no-ops.
            return OutboxDisposition::BENIGN_SUCCESS;
        }

        try {
            $this->invoke($row);
            $this->repo->markSucceeded($row->id);
            return OutboxDisposition::BENIGN_SUCCESS;
        } catch (\Throwable $e) {
            $disposition = OutboxClassifier::classify($e);
            $code        = $e instanceof OpenFgaApiException ? $e->getErrorCode() : null;
            $message     = $e->getMessage();

            switch ($disposition) {
                case OutboxDisposition::BENIGN_SUCCESS:
                    // TupleAlreadyExists / TupleNotFound — counts as success.
                    $this->repo->markSucceeded($row->id);
                    return OutboxDisposition::BENIGN_SUCCESS;

                case OutboxDisposition::TERMINAL:
                    $this->repo->markFailedTerminal($row->id, $message, $code);
                    return OutboxDisposition::TERMINAL;

                case OutboxDisposition::RETRY:
                    $newAttempts = $row->attempts + 1;
                    if ($newAttempts >= self::MAX_ATTEMPTS) {
                        // Last attempt failed transiently — terminal.
                        $this->repo->markFailedTerminal($row->id, $message, $code);
                        return OutboxDisposition::TERMINAL;
                    }
                    $delay = OutboxBackoff::secondsForAttempt($newAttempts);
                    $next  = (new \DateTimeImmutable())->modify("+{$delay} seconds");
                    $this->repo->markRetryable($row->id, $newAttempts, $next, $message, $code);
                    return OutboxDisposition::RETRY;
            }
        }
    }

    /**
     * Convenience alias used by handlers in the sync fast path.
     *
     * Same semantics as processOne — separate method to make the call
     * site read as "sync attempt" rather than "any-context attempt".
     */
    public function processSync(int $rowId): OutboxDisposition
    {
        return $this->processOne($rowId);
    }

    private function invoke(OutboxRow $row): void
    {
        match ($row->operation) {
            OutboxOperation::WRITE_TUPLE  => $this->client->writeTuple($row->fgaUser, $row->fgaRelation, $row->fgaObject),
            OutboxOperation::DELETE_TUPLE => $this->client->deleteTuple($row->fgaUser, $row->fgaRelation, $row->fgaObject),
        };
    }
}
```

- [ ] **Step 4: Run tests**

Run: `vendor/bin/phpunit phpunit_tests/Services/Outbox/OutboxProcessorTest.php`
Expected: PASS, 6 tests.

- [ ] **Step 5: Static analysis**

Run: `composer analyse -- src/Services/Outbox/OutboxProcessor.php`
Expected: clean.

- [ ] **Step 6: Commit**

```bash
git add src/Services/Outbox/OutboxProcessor.php phpunit_tests/Services/Outbox/OutboxProcessorTest.php
git commit -m "feat(outbox): OutboxProcessor — single point of OpenFGA contact"
```

---

## Phase 5 — Redis layer

### Task 15: OutboxNotifier (TDD, best-effort XADD)

**Files:**

- Create: `src/Services/Outbox/OutboxNotifier.php`
- Create: `phpunit_tests/Services/Outbox/OutboxNotifierTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Services\Outbox;

use LiturgicalCalendar\Api\Services\Outbox\OutboxNotifier;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(OutboxNotifier::class)]
final class OutboxNotifierTest extends TestCase
{
    public function testNotifyXAddsToStream(): void
    {
        $redis = $this->createMock(\Redis::class);
        $redis->expects(self::once())
            ->method('xAdd')
            ->with(
                self::equalTo('litcal:reconcile-stream'),
                self::equalTo('*'),
                self::callback(static function (array $payload): bool {
                    return ( $payload['row_id'] ?? null ) === '42' && ( $payload['op'] ?? null ) === 'write_tuple';
                }),
            )
            ->willReturn('1234567890-0');

        $notifier = new OutboxNotifier($redis, 'litcal:reconcile-stream');
        $notifier->notify(42, 'write_tuple');
    }

    public function testNotifyOnRedisExceptionDoesNotPropagate(): void
    {
        $redis = $this->createMock(\Redis::class);
        $redis->expects(self::once())
            ->method('xAdd')
            ->willThrowException(new \RedisException('connection refused'));

        $notifier = new OutboxNotifier($redis, 'litcal:reconcile-stream');

        // Must NOT throw — the outbox row is durable in PG; the backstop will pick it up.
        $notifier->notify(42, 'write_tuple');
        $this->addToAssertionCount(1);
    }

    public function testNotifyWithNullRedisIsNoOp(): void
    {
        $notifier = new OutboxNotifier(null, 'litcal:reconcile-stream');
        $notifier->notify(42, 'write_tuple');
        $this->addToAssertionCount(1);
    }
}
```

- [ ] **Step 2: Verify it fails**

Run: `vendor/bin/phpunit phpunit_tests/Services/Outbox/OutboxNotifierTest.php`
Expected: FAIL — `OutboxNotifier` not found.

- [ ] **Step 3: Write the implementation**

```php
<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services\Outbox;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Best-effort XADD to the reconcile stream.
 *
 * Never throws to the caller — the outbox row is durable in PG, and the
 * cron backstop is the safety net. Logging at WARNING is sufficient
 * signal that something is off; the system continues to function.
 *
 * Pass null \Redis to disable (e.g. in environments without Redis).
 */
final class OutboxNotifier
{
    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly ?\Redis $redis,
        private readonly string $streamName,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public function notify(int $outboxId, string $operation): void
    {
        if ($this->redis === null) {
            return;
        }

        try {
            $this->redis->xAdd(
                $this->streamName,
                '*',
                [
                    'row_id' => (string) $outboxId,
                    'op'     => $operation,
                ],
            );
        } catch (\RedisException $e) {
            $this->logger->warning('outbox.redis.notify_failed', [
                'row_id' => $outboxId,
                'op'     => $operation,
                'error'  => $e->getMessage(),
            ]);
        }
    }
}
```

- [ ] **Step 4: Run tests**

Run: `vendor/bin/phpunit phpunit_tests/Services/Outbox/OutboxNotifierTest.php`
Expected: PASS, 3 tests.

- [ ] **Step 5: Commit**

```bash
git add src/Services/Outbox/OutboxNotifier.php phpunit_tests/Services/Outbox/OutboxNotifierTest.php
git commit -m "feat(outbox): OutboxNotifier — best-effort XADD to reconcile stream"
```

### Task 16: RedisStreamConsumer (TDD, mocked Redis)

**Files:**

- Create: `src/Services/Outbox/RedisStreamConsumer.php`
- Create: `phpunit_tests/Services/Outbox/RedisStreamConsumerTest.php`

- [ ] **Step 1: Write the failing test (mocked Redis)**

```php
<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Services\Outbox;

use LiturgicalCalendar\Api\Services\Outbox\RedisStreamConsumer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RedisStreamConsumer::class)]
final class RedisStreamConsumerTest extends TestCase
{
    public function testEnsureGroupCreatesGroupIfMissing(): void
    {
        $redis = $this->createMock(\Redis::class);
        $redis->expects(self::once())
            ->method('xGroup')
            ->with('CREATE', 'litcal:reconcile-stream', 'reconciler', '$', true)
            ->willReturn(true);

        $consumer = new RedisStreamConsumer($redis, 'litcal:reconcile-stream', 'reconciler', 'consumer-1');
        $consumer->ensureGroup();
    }

    public function testEnsureGroupIgnoresBusygroup(): void
    {
        $redis = $this->createMock(\Redis::class);
        $redis->expects(self::once())
            ->method('xGroup')
            ->willThrowException(new \RedisException('BUSYGROUP Consumer Group name already exists'));

        $consumer = new RedisStreamConsumer($redis, 'litcal:reconcile-stream', 'reconciler', 'consumer-1');
        $consumer->ensureGroup();
        $this->addToAssertionCount(1);
    }

    public function testEnsureGroupReraisesOtherErrors(): void
    {
        $redis = $this->createMock(\Redis::class);
        $redis->expects(self::once())
            ->method('xGroup')
            ->willThrowException(new \RedisException('WRONGTYPE'));

        $consumer = new RedisStreamConsumer($redis, 'litcal:reconcile-stream', 'reconciler', 'consumer-1');
        $this->expectException(\RedisException::class);
        $this->expectExceptionMessage('WRONGTYPE');
        $consumer->ensureGroup();
    }

    public function testReadOneInvokesCallbackWithRowIdAndAcks(): void
    {
        $redis = $this->createMock(\Redis::class);
        $redis->method('xReadGroup')->willReturn([
            'litcal:reconcile-stream' => [
                '1700000000-0' => ['row_id' => '42', 'op' => 'write_tuple'],
            ],
        ]);
        $redis->expects(self::once())
            ->method('xAck')
            ->with('litcal:reconcile-stream', 'reconciler', ['1700000000-0'])
            ->willReturn(1);

        $captured = null;
        $consumer = new RedisStreamConsumer($redis, 'litcal:reconcile-stream', 'reconciler', 'consumer-1');
        $consumer->readOnce(
            blockMs: 5000,
            process: function (int $rowId) use (&$captured): void { $captured = $rowId; },
        );

        self::assertSame(42, $captured);
    }

    public function testReadOneReturnsWithoutAckOnEmptyRead(): void
    {
        $redis = $this->createMock(\Redis::class);
        // Empty arrays come back from xReadGroup on timeout / no messages.
        $redis->method('xReadGroup')->willReturn([]);
        $redis->expects(self::never())->method('xAck');

        $consumer = new RedisStreamConsumer($redis, 'litcal:reconcile-stream', 'reconciler', 'consumer-1');
        $consumer->readOnce(blockMs: 100, process: function (int $rowId): void {});
        $this->addToAssertionCount(1);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit phpunit_tests/Services/Outbox/RedisStreamConsumerTest.php`
Expected: FAIL — `RedisStreamConsumer` not found.

- [ ] **Step 3: Write the implementation**

```php
<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services\Outbox;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Thin wrapper around \Redis XREADGROUP + XACK for the consumer loop.
 *
 * Lives in its own class so the consumer's domain logic (look up the
 * outbox row, invoke OutboxProcessor) doesn't get tangled with Redis
 * Streams plumbing, and so we can unit-test by mocking \Redis.
 */
final class RedisStreamConsumer
{
    private const CLAIM_IDLE_MS = 30_000;

    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly \Redis $redis,
        private readonly string $streamName,
        private readonly string $groupName,
        private readonly string $consumerName,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Idempotent. BUSYGROUP errors mean the group already exists; that's fine.
     */
    public function ensureGroup(): void
    {
        try {
            $this->redis->xGroup('CREATE', $this->streamName, $this->groupName, '$', true);
        } catch (\RedisException $e) {
            if (str_contains($e->getMessage(), 'BUSYGROUP')) {
                return;
            }
            throw $e;
        }
    }

    /**
     * Read one message (or batch) from the stream and invoke $process
     * with the row_id field. XACK on success.
     *
     * Stale pending entries (idle > CLAIM_IDLE_MS) are reclaimed first
     * via XCLAIM so a new consumer can finish work a previous consumer
     * crashed mid-flight.
     *
     * @param callable(int): void $process
     */
    public function readOnce(int $blockMs, callable $process): void
    {
        // First, try to claim anything stale from another consumer.
        $this->claimStale($process);

        /** @var array<string, array<string, array<string, string>>> $batch */
        $batch = $this->redis->xReadGroup(
            $this->groupName,
            $this->consumerName,
            [$this->streamName => '>'],
            1,
            $blockMs,
        );

        $messages = $batch[$this->streamName] ?? [];
        if (empty($messages)) {
            return;
        }

        $ackIds = [];
        foreach ($messages as $msgId => $payload) {
            $rowId = isset($payload['row_id']) ? (int) $payload['row_id'] : 0;
            if ($rowId <= 0) {
                $this->logger->warning('outbox.consumer.bad_message', ['msg_id' => $msgId, 'payload' => $payload]);
                $ackIds[] = $msgId;
                continue;
            }
            try {
                $process($rowId);
                $ackIds[] = $msgId;
            } catch (\Throwable $e) {
                // Leave the message in the PEL; XCLAIM on the next pass picks it up.
                $this->logger->error('outbox.consumer.process_failed', [
                    'msg_id' => $msgId,
                    'row_id' => $rowId,
                    'error'  => $e->getMessage(),
                ]);
            }
        }

        if (!empty($ackIds)) {
            $this->redis->xAck($this->streamName, $this->groupName, $ackIds);
        }
    }

    /**
     * @param callable(int): void $process
     */
    private function claimStale(callable $process): void
    {
        // XPENDING + XCLAIM for messages idle longer than CLAIM_IDLE_MS.
        /** @var array<int, array{0: string, 1: string, 2: int, 3: int}>|false $pending */
        $pending = $this->redis->xPending($this->streamName, $this->groupName);
        if ($pending === false || empty($pending)) {
            return;
        }
        // xPending without an idle filter returns summary form; we need detail form.
        // Use $0 / $1 sentinels from ['-', '+'] to enumerate.
        /** @var array<int, array{0: string, 1: string, 2: int, 3: int}>|false $detail */
        $detail = $this->redis->xPending(
            $this->streamName,
            $this->groupName,
            '-',
            '+',
            100,
        );
        if ($detail === false || !is_array($detail) || !isset($detail[0]) || !is_array($detail[0])) {
            return;
        }

        $staleIds = [];
        foreach ($detail as $entry) {
            if (!is_array($entry) || count($entry) < 4) {
                continue;
            }
            // entry: [msgId, consumer, idle_ms, deliveries]
            $idleMs = (int) $entry[2];
            if ($idleMs >= self::CLAIM_IDLE_MS) {
                $staleIds[] = (string) $entry[0];
            }
        }

        if (empty($staleIds)) {
            return;
        }

        /** @var array<string, array<string, string>>|false $claimed */
        $claimed = $this->redis->xClaim(
            $this->streamName,
            $this->groupName,
            $this->consumerName,
            self::CLAIM_IDLE_MS,
            $staleIds,
        );
        if ($claimed === false || empty($claimed)) {
            return;
        }

        $ackIds = [];
        foreach ($claimed as $msgId => $payload) {
            $rowId = isset($payload['row_id']) ? (int) $payload['row_id'] : 0;
            $this->logger->warning('outbox.consumer.xclaim', [
                'msg_id'   => $msgId,
                'row_id'   => $rowId,
                'idle_ms'  => self::CLAIM_IDLE_MS,
            ]);
            if ($rowId <= 0) {
                $ackIds[] = $msgId;
                continue;
            }
            try {
                $process($rowId);
                $ackIds[] = $msgId;
            } catch (\Throwable) {
                // leave it pending; next pass retries.
            }
        }

        if (!empty($ackIds)) {
            $this->redis->xAck($this->streamName, $this->groupName, $ackIds);
        }
    }
}
```

- [ ] **Step 4: Run tests**

Run: `vendor/bin/phpunit phpunit_tests/Services/Outbox/RedisStreamConsumerTest.php`
Expected: PASS, 5 tests.

- [ ] **Step 5: Commit**

```bash
git add src/Services/Outbox/RedisStreamConsumer.php phpunit_tests/Services/Outbox/RedisStreamConsumerTest.php
git commit -m "feat(outbox): RedisStreamConsumer with XREADGROUP + XCLAIM stale reclaim"
```

---

## Phase 6 — Runners + CLI

### Task 17: BackstopRunner (TDD)

**Files:**

- Create: `src/Services/Outbox/BackstopRunner.php`
- Create: `phpunit_tests/Services/Outbox/BackstopRunnerTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Services\Outbox;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use LiturgicalCalendar\Api\Repositories\OutboxRepository;
use LiturgicalCalendar\Api\Services\OpenFgaClient;
use LiturgicalCalendar\Api\Services\Outbox\BackstopRunner;
use LiturgicalCalendar\Api\Services\Outbox\OutboxOperation;
use LiturgicalCalendar\Api\Services\Outbox\OutboxProcessor;
use LiturgicalCalendar\Api\Services\Outbox\OutboxStatus;
use LiturgicalCalendar\Tests\Repositories\RepositoryTestCase;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(BackstopRunner::class)]
final class BackstopRunnerTest extends RepositoryTestCase
{
    public function testRunOnceProcessesEligibleRowsAndIgnoresGraceWindow(): void
    {
        $repo  = new OutboxRepository(self::$pdo);
        $psr17 = new Psr17Factory();
        $mock  = new MockHandler([
            new Response(200, [], ''),
            new Response(200, [], ''),
        ]);
        $client = new OpenFgaClient(
            'http://localhost:8083',
            'store-123',
            'model-456',
            new Client(['handler' => HandlerStack::create($mock)]),
            $psr17,
            $psr17,
        );
        $processor = new OutboxProcessor($repo, $client);

        // Two ancient pending rows (eligible for backstop after 60s grace).
        $ids = $repo->insertBatch([
            [
                'operation'       => OutboxOperation::WRITE_TUPLE,
                'fga_user'        => 'user:a',
                'fga_relation'    => 'editor',
                'fga_object'      => 'national_calendar:IT',
                'idempotency_key' => 'k1-' . bin2hex(random_bytes(4)),
                'metadata'        => [],
            ],
            [
                'operation'       => OutboxOperation::WRITE_TUPLE,
                'fga_user'        => 'user:b',
                'fga_relation'    => 'editor',
                'fga_object'      => 'national_calendar:US',
                'idempotency_key' => 'k2-' . bin2hex(random_bytes(4)),
                'metadata'        => [],
            ],
        ]);

        $runner    = new BackstopRunner($repo, $processor, graceSeconds: 0);
        $processed = $runner->runOnce(limit: 100);

        self::assertSame(2, $processed);
        self::assertSame(OutboxStatus::SUCCEEDED, $repo->getById($ids[0])?->status);
        self::assertSame(OutboxStatus::SUCCEEDED, $repo->getById($ids[1])?->status);
    }

    public function testRunOnceRespectsGraceWindow(): void
    {
        $repo  = new OutboxRepository(self::$pdo);
        $psr17 = new Psr17Factory();
        $mock  = new MockHandler([]);
        $client = new OpenFgaClient(
            'http://localhost:8083',
            'store-123',
            'model-456',
            new Client(['handler' => HandlerStack::create($mock)]),
            $psr17,
            $psr17,
        );
        $processor = new OutboxProcessor($repo, $client);

        $repo->insertBatch([[
            'operation'       => OutboxOperation::WRITE_TUPLE,
            'fga_user'        => 'user:c',
            'fga_relation'    => 'editor',
            'fga_object'      => 'national_calendar:FR',
            'idempotency_key' => 'k3-' . bin2hex(random_bytes(4)),
            'metadata'        => [],
        ]]);

        $runner    = new BackstopRunner($repo, $processor, graceSeconds: 60);
        $processed = $runner->runOnce(limit: 100);

        self::assertSame(0, $processed, 'row is too fresh — under the 60s grace window');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit phpunit_tests/Services/Outbox/BackstopRunnerTest.php`
Expected: FAIL — `BackstopRunner` not found.

- [ ] **Step 3: Write the implementation**

```php
<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services\Outbox;

use LiturgicalCalendar\Api\Repositories\OutboxRepository;

/**
 * One-shot scan of openfga_outbox for the cron backstop.
 *
 * Picks up rows older than the grace window (default 60s — the consumer
 * gets first crack), processes them via OutboxProcessor, exits.
 *
 * The grace window is the durability buffer: the consumer's XREADGROUP
 * wake-up is sub-second on the happy path, so the backstop should only
 * see rows where Redis lost the XADD or the consumer is dead.
 */
final class BackstopRunner
{
    public function __construct(
        private readonly OutboxRepository $repo,
        private readonly OutboxProcessor $processor,
        private readonly int $graceSeconds = 60,
    ) {
    }

    public function runOnce(int $limit = 100): int
    {
        $cutoff = (new \DateTimeImmutable())->modify("-{$this->graceSeconds} seconds");
        $rows   = $this->repo->pickupPending($limit, $cutoff);

        foreach ($rows as $row) {
            $this->processor->processOne($row->id);
        }

        return count($rows);
    }
}
```

- [ ] **Step 4: Run tests**

Run: `vendor/bin/phpunit phpunit_tests/Services/Outbox/BackstopRunnerTest.php`
Expected: PASS, 2 tests.

- [ ] **Step 5: Commit**

```bash
git add src/Services/Outbox/BackstopRunner.php phpunit_tests/Services/Outbox/BackstopRunnerTest.php
git commit -m "feat(outbox): BackstopRunner — cron-driven scan with grace window"
```

### Task 18: ConsumerLoop (TDD, mocked Redis)

**Files:**

- Create: `src/Services/Outbox/ConsumerLoop.php`
- Create: `phpunit_tests/Services/Outbox/ConsumerLoopTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Services\Outbox;

use LiturgicalCalendar\Api\Services\Outbox\ConsumerLoop;
use LiturgicalCalendar\Api\Services\Outbox\OutboxProcessor;
use LiturgicalCalendar\Api\Services\Outbox\RedisStreamConsumer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ConsumerLoop::class)]
final class ConsumerLoopTest extends TestCase
{
    public function testTickEnsuresGroupOnceAndDelegatesToConsumer(): void
    {
        $consumer = $this->createMock(RedisStreamConsumer::class);
        $consumer->expects(self::once())->method('ensureGroup');
        $consumer->expects(self::exactly(3))
            ->method('readOnce')
            ->with(5000, self::isType('callable'));

        $processor = $this->createMock(OutboxProcessor::class);

        $loop = new ConsumerLoop($consumer, $processor, blockMs: 5000);
        $loop->tick();
        $loop->tick();
        $loop->tick();
    }

    public function testTickPassesRowIdToProcessor(): void
    {
        $consumer = $this->createMock(RedisStreamConsumer::class);
        $consumer->method('readOnce')->willReturnCallback(
            static function (int $blockMs, callable $process): void {
                $process(42);
            },
        );

        $processor = $this->createMock(OutboxProcessor::class);
        $processor->expects(self::once())->method('processOne')->with(42);

        $loop = new ConsumerLoop($consumer, $processor, blockMs: 5000);
        $loop->tick();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit phpunit_tests/Services/Outbox/ConsumerLoopTest.php`
Expected: FAIL — `ConsumerLoop` not found.

- [ ] **Step 3: Write the implementation**

```php
<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services\Outbox;

/**
 * Long-lived consumer loop body.
 *
 * tick() does one readOnce + process cycle (the unit-testable part).
 * run() is the outer while (true), excluded from coverage. Splitting
 * keeps the test pyramid honest.
 */
final class ConsumerLoop
{
    private bool $groupEnsured = false;

    public function __construct(
        private readonly RedisStreamConsumer $consumer,
        private readonly OutboxProcessor $processor,
        private readonly int $blockMs = 5000,
    ) {
    }

    public function tick(): void
    {
        if (!$this->groupEnsured) {
            $this->consumer->ensureGroup();
            $this->groupEnsured = true;
        }
        $this->consumer->readOnce(
            $this->blockMs,
            function (int $rowId): void {
                $this->processor->processOne($rowId);
            },
        );
    }

    public function run(): never
    {
        // Forever. systemd restarts on crash.
        while (true) { // @phpstan-ignore-line — infinite loop is intentional
            $this->tick();
        }
    }
}
```

- [ ] **Step 4: Run tests**

Run: `vendor/bin/phpunit phpunit_tests/Services/Outbox/ConsumerLoopTest.php`
Expected: PASS, 2 tests.

- [ ] **Step 5: Commit**

```bash
git add src/Services/Outbox/ConsumerLoop.php phpunit_tests/Services/Outbox/ConsumerLoopTest.php
git commit -m "feat(outbox): ConsumerLoop — tick (testable) + run (forever)"
```

### Task 19: bin/reconcile-outbox CLI

**Files:**

- Create: `bin/reconcile-outbox`
- Modify: `composer.json`

- [ ] **Step 1: Write the CLI**

```php
#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Subcommands:
 *   consumer  — long-lived; systemd manages restart on failure
 *   backstop  — one-shot scan; cron invokes every 5 min
 *
 * Both routes through OutboxProcessor so the OpenFGA contact surface
 * stays single-source.
 */

use Dotenv\Dotenv;
use LiturgicalCalendar\Api\Repositories\OutboxRepository;
use LiturgicalCalendar\Api\Services\OpenFgaClient;
use LiturgicalCalendar\Api\Services\Outbox\BackstopRunner;
use LiturgicalCalendar\Api\Services\Outbox\ConsumerLoop;
use LiturgicalCalendar\Api\Services\Outbox\OutboxProcessor;
use LiturgicalCalendar\Api\Services\Outbox\RedisStreamConsumer;

// Locate the project root via composer.json — same pattern as public/index.php.
$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

if (file_exists($root . '/.env.local')) {
    Dotenv::createImmutable($root, '.env.local')->safeLoad();
} elseif (file_exists($root . '/.env')) {
    Dotenv::createImmutable($root)->safeLoad();
}

$mode = $argv[1] ?? '';
if ($mode !== 'consumer' && $mode !== 'backstop') {
    fwrite(STDERR, "Usage: reconcile-outbox consumer|backstop\n");
    exit(2);
}

// --- Build dependencies ---

$pdo = new PDO(
    sprintf(
        'pgsql:host=%s;port=%s;dbname=%s',
        $_ENV['DB_HOST'] ?? 'localhost',
        $_ENV['DB_PORT'] ?? '5432',
        $_ENV['DB_NAME'] ?? '',
    ),
    (string) ( $_ENV['DB_USER'] ?? '' ),
    (string) ( $_ENV['DB_PASSWORD'] ?? '' ),
    [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ],
);
$pdo->exec("SET timezone TO 'Europe/Vatican'");

$repo      = new OutboxRepository($pdo);
$fga       = OpenFgaClient::fromEnv();   // Use the existing static factory.
$processor = new OutboxProcessor($repo, $fga);

if ($mode === 'backstop') {
    $runner    = new BackstopRunner($repo, $processor, graceSeconds: (int) ( $_ENV['OUTBOX_BACKSTOP_GRACE_SECONDS'] ?? 60 ));
    $processed = $runner->runOnce(limit: 200);
    fwrite(STDOUT, sprintf("backstop processed=%d\n", $processed));
    exit(0);
}

// consumer mode
$redis = new \Redis();
if (isset($_ENV['REDIS_SOCKET']) && is_string($_ENV['REDIS_SOCKET']) && $_ENV['REDIS_SOCKET'] !== '') {
    $redis->connect((string) $_ENV['REDIS_SOCKET']);
} else {
    $redis->connect(
        (string) ( $_ENV['REDIS_HOST'] ?? '127.0.0.1' ),
        (int) ( $_ENV['REDIS_PORT'] ?? 6379 ),
    );
}
if (isset($_ENV['REDIS_PASSWORD']) && is_string($_ENV['REDIS_PASSWORD']) && $_ENV['REDIS_PASSWORD'] !== '') {
    $redis->auth((string) $_ENV['REDIS_PASSWORD']);
}

$streamName   = (string) ( $_ENV['REDIS_OUTBOX_STREAM']        ?? 'litcal:reconcile-stream' );
$groupName    = (string) ( $_ENV['REDIS_OUTBOX_GROUP']         ?? 'reconciler' );
$consumerName = (string) ( $_ENV['REDIS_OUTBOX_CONSUMER_NAME'] ?? gethostname() );

$stream = new RedisStreamConsumer($redis, $streamName, $groupName, $consumerName);
$loop   = new ConsumerLoop($stream, $processor, blockMs: 5000);

$loop->run();
```

NOTE: If `OpenFgaClient::fromEnv()` does not exist (verify with `grep -n 'fromEnv' src/Services/OpenFgaClient.php`),
construct the client inline using `getenv('OPENFGA_API_URL')` etc., mirroring how the existing handlers do it.

- [ ] **Step 2: Make it executable**

Run: `chmod +x bin/reconcile-outbox`

- [ ] **Step 3: Add composer scripts**

In `composer.json`, find the `"scripts"` block and add:

```json
        "reconciler:consumer": "@php bin/reconcile-outbox consumer",
        "reconciler:backstop": "@php bin/reconcile-outbox backstop"
```

- [ ] **Step 4: Smoke-test the backstop (no rows is OK)**

Run: `composer reconciler:backstop`
Expected: `backstop processed=0` (or processed=N if you happen to have rows around).

- [ ] **Step 5: Commit**

```bash
git add bin/reconcile-outbox composer.json
git commit -m "feat(outbox): bin/reconcile-outbox CLI (consumer + backstop subcommands)"
```

---

## Phase 7 — Handler integration

### Task 20: Refactor AccessRequestAdminHandler::approveRequest (TDD)

**Files:**

- Modify: `src/Handlers/Admin/AccessRequestAdminHandler.php`
- Modify: `phpunit_tests/Handlers/Admin/AccessRequestAdminHandlerTest.php`

This task is the largest single behavioral change in the plan. Read the current `approveRequest` carefully before
making changes (lines ~258-395 of `AccessRequestAdminHandler.php`). The new structure:

```text
  BEGIN tx
    repo.approve(requestId, adminId, notes)            ── current behavior
    outbox.insertBatch(rows)                           ── new
  COMMIT
  foreach row: OutboxProcessor::processSync(row)       ── new
  OutboxNotifier::notify(row_id, op) for any still-pending row
  return response with outbox_pending / outbox_failed / outbox_ids
```

- [ ] **Step 1: Add a failing integration test**

Append to `AccessRequestAdminHandlerTest`:

```php
    public function testApproveCommitsOutboxRowsAtomicallyWithDbWrite(): void
    {
        // Seed a pending request with two permissions.
        $requestId = $this->seedPendingRequest('user:alice', [
            ['object_type' => 'national_calendar', 'object_id' => 'IT', 'relation' => 'editor'],
            ['object_type' => 'national_calendar', 'object_id' => 'US', 'relation' => 'viewer'],
        ]);

        // OpenFGA mock: first call 503, second call 200. So one outbox row stays
        // 'retrying', the other goes to 'succeeded' on the fast path.
        $mock = new MockHandler([
            new \GuzzleHttp\Psr7\Response(503, [], ''),
            new \GuzzleHttp\Psr7\Response(200, [], ''),
        ]);
        $handler = $this->makeHandlerWithMockFga($mock);

        $response = $this->callApprove($handler, $requestId);
        $body     = json_decode((string) $response->getBody(), true);

        self::assertTrue($body['success']);
        self::assertSame(1, $body['outbox_pending']);
        self::assertSame(0, $body['outbox_failed']);
        self::assertCount(2, $body['outbox_ids']);

        // Verify the row that stayed pending has the right state.
        $outboxRepo = new \LiturgicalCalendar\Api\Repositories\OutboxRepository(self::$pdo);
        $stmt       = self::$pdo->prepare(
            "SELECT status::text AS status FROM openfga_outbox
             WHERE metadata->>'access_request_id' = :rid ORDER BY id"
        );
        $stmt->execute([':rid' => $requestId]);
        /** @var list<array{status: string}> $rows */
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        self::assertSame(['retrying', 'succeeded'], array_column($rows, 'status'));
    }

    public function testApproveIsIdempotentOnReissue(): void
    {
        $requestId = $this->seedPendingRequest('user:alice', [
            ['object_type' => 'national_calendar', 'object_id' => 'IT', 'relation' => 'editor'],
        ]);
        $mock = new MockHandler([
            new \GuzzleHttp\Psr7\Response(200, [], ''),
            // Second call (if it happened, which it shouldn't): no response queued.
        ]);
        $handler = $this->makeHandlerWithMockFga($mock);

        $r1 = json_decode((string) $this->callApprove($handler, $requestId)->getBody(), true);

        // Second invocation of the same request — must not throw, must produce same outbox_ids.
        $mock2    = new MockHandler([]);
        $handler2 = $this->makeHandlerWithMockFga($mock2);
        $r2       = json_decode((string) $this->callApprove($handler2, $requestId)->getBody(), true);

        self::assertSame($r1['outbox_ids'], $r2['outbox_ids'], 'idempotency key must collapse second insert to same row IDs');
    }
```

You will need helper methods on the test class for `seedPendingRequest`, `makeHandlerWithMockFga`, `callApprove`. If
`AccessRequestAdminHandlerTest` already has analogous helpers from #628, reuse them; otherwise pattern them after
the existing `withMockOpenFgaClient` helper added in PR #628 (`grep -n withMockOpenFgaClient phpunit_tests/Handlers/Admin/AccessRequestAdminHandlerTest.php`).

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit phpunit_tests/Handlers/Admin/AccessRequestAdminHandlerTest.php --filter testApproveCommits`
Expected: FAIL — `outbox_pending` doesn't exist in response.

- [ ] **Step 3: Refactor approveRequest**

In `AccessRequestAdminHandler::approveRequest`, replace the body **after the
`$this->requireAdminForAllResources(...)` line** through the end of the OpenFGA loop with the outbox pattern. Keep
the rest of the method (validation, Zitadel sync) intact.

Replace (current code from `// Step 1: Create OpenFGA tuples` down to and including the `if (!empty($fgaErrors)) {
return ... }` block that bails before DB mutation, **plus** the `// Step 2: Approve in database` block):

```php
        // ----- Outbox-backed step 1+2 (Options B+C from #567 design) -----
        $repository = $this->getRepository();
        $outboxRepo = $this->getOutboxRepository();
        $notifier   = $this->getOutboxNotifier();

        if (empty($permissions)) {
            // No tuples to write — just approve.
            if (!$repository->approve($requestId, $adminId, $notes)) {
                throw new ValidationException('Failed to approve request');
            }
            $outboxRows = [];
        } else {
            $outboxRows = [];
            self::$pdo->beginTransaction();
            try {
                if (!$repository->approve($requestId, $adminId, $notes)) {
                    throw new ValidationException('Failed to approve request');
                }
                $payload = [];
                foreach ($permissions as $perm) {
                    $objectType = (string) ( $perm['object_type'] ?? '' );
                    $objectId   = (string) ( $perm['object_id']   ?? '' );
                    $relation   = (string) ( $perm['relation']    ?? '' );
                    $fgaUser    = "user:{$userId}";
                    $fgaObject  = "{$objectType}:{$objectId}";
                    $payload[]  = [
                        'operation'       => OutboxOperation::WRITE_TUPLE,
                        'fga_user'        => $fgaUser,
                        'fga_relation'    => $relation,
                        'fga_object'      => $fgaObject,
                        'idempotency_key' => sprintf(
                            'access_request:%s:write_tuple:%s:%s:%s',
                            $requestId, $fgaUser, $relation, $fgaObject,
                        ),
                        'metadata'        => [
                            'access_request_id' => $requestId,
                            'admin_user'        => "user:{$adminId}",
                        ],
                    ];
                }
                $rowIds = $outboxRepo->insertBatch($payload);
                self::$pdo->commit();
            } catch (\Throwable $e) {
                self::$pdo->rollBack();
                throw $e;
            }

            // ----- Sync fast path: attempt each tuple inline -----
            $processor = $this->getOutboxProcessor();
            $outboxRows = [];
            foreach ($rowIds as $rowId) {
                $disp        = $processor->processSync($rowId);
                $current     = $outboxRepo->getById($rowId);
                $outboxRows[] = [
                    'id'         => $rowId,
                    'disposition'=> $disp->name,
                    'status'     => $current?->status->value ?? 'unknown',
                ];
                // If the sync attempt left the row pending/retrying, nudge Redis so the
                // consumer wakes immediately rather than waiting for the cron backstop.
                if ($current !== null && in_array($current->status, [OutboxStatus::PENDING, OutboxStatus::RETRYING], true)) {
                    $notifier->notify($rowId, OutboxOperation::WRITE_TUPLE->value);
                }
            }
        }

        $outboxIds      = array_map(static fn ($r): int => (int) $r['id'], $outboxRows);
        $outboxPending  = count(array_filter($outboxRows, static fn ($r): bool => $r['status'] === 'retrying' || $r['status'] === 'pending'));
        $outboxFailed   = count(array_filter($outboxRows, static fn ($r): bool => $r['status'] === 'failed_terminal'));
        $tuplesCreated  = [];
        foreach ($outboxRows as $r) {
            if ($r['status'] === 'succeeded') {
                // Hydrate the human-readable tuple for back-compat with the response shape.
                $row = $outboxRepo->getById((int) $r['id']);
                if ($row !== null) {
                    $tuplesCreated[] = [
                        'user'     => $row->fgaUser,
                        'relation' => $row->fgaRelation,
                        'object'   => $row->fgaObject,
                    ];
                }
            }
        }
```

Then update the response body so it includes `outbox_pending`, `outbox_failed`, `outbox_ids` and adjusts the
`message` based on those counts. The `success` field stays `true` (DB committed). Existing Zitadel sync block stays
unchanged after this point.

- [ ] **Step 4: Add the dependency-injection slots**

Add these properties + getters near the top of `AccessRequestAdminHandler`:

```php
    private ?OutboxRepository $outboxRepository = null;
    private ?OutboxNotifier   $outboxNotifier   = null;
    private ?OutboxProcessor  $outboxProcessor  = null;

    public function setOutboxDependencies(
        OutboxRepository $repo,
        OutboxNotifier $notifier,
        OutboxProcessor $processor,
    ): void {
        $this->outboxRepository = $repo;
        $this->outboxNotifier   = $notifier;
        $this->outboxProcessor  = $processor;
    }

    private function getOutboxRepository(): OutboxRepository { /* lazy build from $this->pdo */ }
    private function getOutboxNotifier(): OutboxNotifier     { /* lazy build from REDIS_* env */ }
    private function getOutboxProcessor(): OutboxProcessor   { /* lazy build from repo + getFgaClient() */ }
```

Implement the lazy builders to mirror the existing `getFgaClient()` pattern (factory + env reads). Test injection
uses `setOutboxDependencies`.

- [ ] **Step 5: Run tests**

Run: `vendor/bin/phpunit phpunit_tests/Handlers/Admin/AccessRequestAdminHandlerTest.php`
Expected: PASS, including the two new tests + all pre-existing tests for approve.

- [ ] **Step 6: PHPStan**

Run: `composer analyse -- src/Handlers/Admin/AccessRequestAdminHandler.php`
Expected: clean.

- [ ] **Step 7: Commit**

```bash
git add src/Handlers/Admin/AccessRequestAdminHandler.php phpunit_tests/Handlers/Admin/AccessRequestAdminHandlerTest.php
git commit -m "feat(outbox): wire approveRequest through the outbox pattern"
```

### Task 21: Refactor AccessRequestAdminHandler::revokeRequest

Mirror Task 20 for `revokeRequest`. Key differences:

- `operation: OutboxOperation::DELETE_TUPLE` instead of WRITE_TUPLE.
- The benign exception is `TupleNotFoundException` instead of `TupleAlreadyExistsException` — already handled by
  the classifier; no handler change.
- Response field names are `tuples_removed`, `outbox_pending`, `outbox_failed`, `outbox_ids`.

Write two tests symmetric to Task 20's: `testRevokeCommitsOutboxRowsAtomicallyWithDbWrite` and
`testRevokeIsIdempotentOnReissue`. Then refactor.

- [ ] **Step 1: Add the two tests**

(Symmetric to Task 20; substitute `revoke` for `approve` and `delete_tuple` for `write_tuple`.)

- [ ] **Step 2: Verify they fail**

- [ ] **Step 3: Refactor `revokeRequest` analogously**

Use the same outbox pattern as Task 20.

- [ ] **Step 4: Run tests**

Run: `vendor/bin/phpunit phpunit_tests/Handlers/Admin/AccessRequestAdminHandlerTest.php`
Expected: PASS.

- [ ] **Step 5: PHPStan**

Run: `composer analyse -- src/Handlers/Admin/AccessRequestAdminHandler.php`

- [ ] **Step 6: Commit**

```bash
git add src/Handlers/Admin/AccessRequestAdminHandler.php phpunit_tests/Handlers/Admin/AccessRequestAdminHandlerTest.php
git commit -m "feat(outbox): wire revokeRequest through the outbox pattern"
```

### Task 22: Refactor PermissionAdminHandler::grantPermission and revokePermission

Mirror the same outbox pattern in `PermissionAdminHandler`. The structure is simpler than
`AccessRequestAdminHandler` because there's no `access_requests` row to coordinate with — the outbox row is the
durable record of intent.

- [ ] **Step 1: Read the current grantPermission and revokePermission**

Run: `grep -n "function grantPermission\|function revokePermission" src/Handlers/Admin/PermissionAdminHandler.php`
Read both methods in their entirety before modifying.

- [ ] **Step 2: Add two failing tests in PermissionAdminHandlerTest**

For `grantPermission`: `testGrantPersistsOutboxRowAndAppliesViaSyncFastPath` and
`testGrantIsIdempotentOnReissue`. Pattern after Task 20.

- [ ] **Step 3: Verify they fail**

Run: `vendor/bin/phpunit phpunit_tests/Handlers/Admin/PermissionAdminHandlerTest.php --filter testGrant`

- [ ] **Step 4: Refactor grantPermission and revokePermission**

Apply the same outbox pattern. The idempotency key for ad-hoc grants uses `permission_grant` as the namespace prefix
(no access_request_id available):

```text
permission_grant:{adminId}:{operation}:{user}:{relation}:{object}
```

Metadata field: `'admin_user' => "user:{$adminId}"`, no `access_request_id`.

- [ ] **Step 5: Run tests**

Run: `vendor/bin/phpunit phpunit_tests/Handlers/Admin/PermissionAdminHandlerTest.php`
Expected: PASS.

- [ ] **Step 6: PHPStan**

- [ ] **Step 7: Commit**

```bash
git add src/Handlers/Admin/PermissionAdminHandler.php phpunit_tests/Handlers/Admin/PermissionAdminHandlerTest.php
git commit -m "feat(outbox): wire grant/revokePermission through the outbox pattern"
```

### Task 23: Refactor RoleCascadeService silent catch → outbox enqueue

**Files:**

- Modify: `src/Services/RoleCascadeService.php`
- Modify: `phpunit_tests/Services/RoleCascadeServiceTest.php` (if it exists; otherwise create it)

The current code (around `src/Services/RoleCascadeService.php:134`) silently catches OpenFGA `deleteTuple` failures
and logs WARNING. Replace the catch body so the failure becomes an outbox row.

- [ ] **Step 1: Add a failing test**

If `RoleCascadeServiceTest` does not exist, create it; pattern after `OutboxProcessorTest` (real PG + MockHandler
OpenFgaClient). Test name: `testCascadeRevokeEnqueuesOutboxRowOnFgaTransientFailure`.

The test:

1. Seeds an existing tuple.
2. Mocks OpenFGA to return 503 on `deleteTuple`.
3. Calls the cascade revoke method.
4. Asserts that an `openfga_outbox` row exists with `operation = delete_tuple`, the correct user/relation/object,
   and `status = pending`.

- [ ] **Step 2: Verify the test fails**

- [ ] **Step 3: Modify RoleCascadeService**

Inject `OutboxRepository` + `OutboxNotifier` (mirror the existing constructor pattern; `RoleCascadeService::fromEnv`
should construct them). In the catch block currently logging WARNING, replace with:

```php
catch (OpenFgaApiException $e) {
    $disp = OutboxClassifier::classify($e);
    if ($disp === OutboxDisposition::BENIGN_SUCCESS) {
        // Already gone — nothing to enqueue.
        continue;
    }
    $idempotencyKey = sprintf(
        'role_cascade:%s:%s:delete_tuple:user:%s:%s:%s',
        $userId,
        $role,
        $userId, // fga_user
        $relation,
        $fgaObject,
    );
    $ids = $this->outboxRepo->insertBatch([[
        'operation'       => OutboxOperation::DELETE_TUPLE,
        'fga_user'        => "user:{$userId}",
        'fga_relation'    => $relation,
        'fga_object'      => $fgaObject,
        'idempotency_key' => $idempotencyKey,
        'metadata'        => [
            'role_cascade_user' => $userId,
            'role_cascade_role' => $role,
        ],
    ]]);
    foreach ($ids as $rowId) {
        $this->outboxNotifier->notify($rowId, OutboxOperation::DELETE_TUPLE->value);
    }
}
```

Keep the WARNING log alongside, with `outbox_row_ids` added to the structured payload, so audit trails don't
regress.

- [ ] **Step 4: Run tests**

Run: `vendor/bin/phpunit phpunit_tests/Services/RoleCascadeServiceTest.php`
Expected: PASS.

- [ ] **Step 5: PHPStan**

Run: `composer analyse -- src/Services/RoleCascadeService.php`

- [ ] **Step 6: Commit**

```bash
git add src/Services/RoleCascadeService.php phpunit_tests/Services/RoleCascadeServiceTest.php
git commit -m "feat(outbox): RoleCascadeService transient FGA failures enqueue outbox rows"
```

---

## Phase 8 — Admin endpoint

### Task 24: OutboxAdminHandler (TDD)

**Files:**

- Create: `src/Handlers/Admin/OutboxAdminHandler.php`
- Create: `phpunit_tests/Handlers/Admin/OutboxAdminHandlerTest.php`

Pattern after `PermissionAdminHandler` for handler structure and auth-guard wiring.

- [ ] **Step 1: Write failing tests**

```php
<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Handlers\Admin;

use LiturgicalCalendar\Api\Handlers\Admin\OutboxAdminHandler;
use LiturgicalCalendar\Api\Repositories\OutboxRepository;
use LiturgicalCalendar\Api\Services\Outbox\OutboxOperation;
use LiturgicalCalendar\Tests\Repositories\RepositoryTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(OutboxAdminHandler::class)]
final class OutboxAdminHandlerTest extends RepositoryTestCase
{
    public function testGetWithStatusFilterReturnsMatchingRows(): void
    {
        $repo = new OutboxRepository(self::$pdo);
        $ids  = $repo->insertBatch([
            [
                'operation' => OutboxOperation::WRITE_TUPLE,
                'fga_user' => 'user:a', 'fga_relation' => 'editor', 'fga_object' => 'national_calendar:IT',
                'idempotency_key' => 'k1', 'metadata' => [],
            ],
            [
                'operation' => OutboxOperation::WRITE_TUPLE,
                'fga_user' => 'user:b', 'fga_relation' => 'editor', 'fga_object' => 'national_calendar:US',
                'idempotency_key' => 'k2', 'metadata' => [],
            ],
        ]);
        $repo->markFailedTerminal($ids[0], 'validation_error', 'validation_error');

        // (Construct handler with admin auth bypassed for the test — mirror PermissionAdminHandlerTest's auth wiring.)
        $handler  = $this->makeHandlerWithAdminAuth();
        $response = $handler->handle($this->makeRequest('GET', '/admin/outbox?status=failed_terminal'));
        $body     = json_decode((string) $response->getBody(), true);

        self::assertSame(1, $body['count']);
        self::assertSame($ids[0], $body['rows'][0]['id']);
        self::assertSame('failed_terminal', $body['rows'][0]['status']);
    }

    public function testGetSummaryReturnsCountsPerStatus(): void { /* … */ }
    public function testPostRetryResetsFailedTerminalToPending(): void { /* … */ }
    public function testPostRetryReturns409ForNonTerminalRow(): void { /* … */ }
}
```

Fill in `testGetSummaryReturnsCountsPerStatus`, `testPostRetryResetsFailedTerminalToPending`, and
`testPostRetryReturns409ForNonTerminalRow` following the same pattern.

- [ ] **Step 2: Verify they fail**

- [ ] **Step 3: Write the handler**

Implement `OutboxAdminHandler`:

```php
<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Handlers\Admin;

use LiturgicalCalendar\Api\Handlers\AbstractHandler;
use LiturgicalCalendar\Api\Http\Exception\ValidationException;
use LiturgicalCalendar\Api\Http\Enum\RequestMethod;
use LiturgicalCalendar\Api\Http\Enum\StatusCode;
use LiturgicalCalendar\Api\Repositories\OutboxRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET  /admin/outbox?status=…&access_request_id=…&summary=1
 * POST /admin/outbox/{id}/retry
 *
 * Admin-only — same JWT + role guard the other Admin handlers use.
 */
final class OutboxAdminHandler extends AbstractHandler
{
    private ?OutboxRepository $repository = null;

    public function __construct()
    {
        parent::__construct();
        $this->allowedMethods = [RequestMethod::GET, RequestMethod::POST];
    }

    public function setRepository(OutboxRepository $repo): void
    {
        $this->repository = $repo;
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->requireAdmin($request);  // Or your existing equivalent.
        $method = RequestMethod::from($request->getMethod());

        if ($method === RequestMethod::GET) {
            return $this->handleGet($request);
        }
        return $this->handlePost($request);
    }

    private function handleGet(ServerRequestInterface $request): ResponseInterface { /* implement: list, summary */ }
    private function handlePost(ServerRequestInterface $request): ResponseInterface { /* implement: retry */ }
}
```

Implement `handleGet`:

- Read `$_GET['status']`, `$_GET['access_request_id']`, `$_GET['summary']`, `$_GET['limit']`, `$_GET['offset']`.
- If `summary=1`, return `countByStatus()` plus `oldest_pending_age_seconds` (run a small SQL: `SELECT EXTRACT(EPOCH FROM
  NOW() - MIN(created_at)) FROM openfga_outbox WHERE status IN ('pending','retrying')`).
- Otherwise build a SELECT with the supplied filters and the standard limit/offset pagination (default
  limit 50, max 200 — pattern after `AccessRequestAdminHandler::listForAdmin` for the pagination envelope shape).

Implement `handlePost`:

- Extract `{id}` from the path.
- Call `$repo->resetForRetry($id)`.
- If true, return 200 with the new row state.
- If false, return 409 with `{"error": "Row is not in failed_terminal state"}`.

- [ ] **Step 4: Run tests**

Run: `vendor/bin/phpunit phpunit_tests/Handlers/Admin/OutboxAdminHandlerTest.php`
Expected: PASS, 4 tests.

- [ ] **Step 5: PHPStan**

Run: `composer analyse -- src/Handlers/Admin/OutboxAdminHandler.php`

- [ ] **Step 6: Commit**

```bash
git add src/Handlers/Admin/OutboxAdminHandler.php phpunit_tests/Handlers/Admin/OutboxAdminHandlerTest.php
git commit -m "feat(outbox): OutboxAdminHandler — GET list/summary, POST retry"
```

### Task 25: Wire OutboxAdminHandler into Router

**Files:**

- Modify: `src/Router.php`

- [ ] **Step 1: Add the route arm**

In `Router::route()`'s `case 'admin':` block (around lines 373-420), add a new `elseif` arm after the existing
`applications` arm:

```php
                    } elseif ($adminRoute === 'outbox') {
                        // Admin outbox management routes
                        // GET  /admin/outbox?status=…&summary=…   - List/summary
                        // POST /admin/outbox/{id}/retry            - Reset terminal row to pending
                        $outboxAdminHandler = new OutboxAdminHandler();
                        $this->handler      = $outboxAdminHandler;
                    }
```

Add the corresponding `use` import at the top of `Router.php`.

- [ ] **Step 2: Smoke-test the route**

Start the API (`composer start` if not running), then:

```bash
# Without admin auth — expect 401/403:
curl -i http://localhost:8000/admin/outbox?summary=1
```

Expected: non-200, with the same auth-failure shape the other admin endpoints produce.

(Authenticated test happens in the OutboxAdminHandlerTest harness.)

- [ ] **Step 3: Commit**

```bash
git add src/Router.php
git commit -m "feat(outbox): wire /admin/outbox routes into Router"
```

---

## Phase 9 — Health

### Task 26: Health endpoint adds openfga_outbox block

**Files:**

- Modify: `src/Health.php`

- [ ] **Step 1: Read Health.php to find the right insertion point**

Run: `grep -n "function\|return\|\$result\[" src/Health.php | head -30`

Find where the health output is assembled (likely a method that builds an array which gets returned/JSON-encoded).
Add a new top-level key `openfga_outbox` to that array.

- [ ] **Step 2: Add the outbox block builder**

```php
    /**
     * @return array{
     *   pending: int,
     *   retrying: int,
     *   succeeded: int,
     *   failed_terminal: int,
     *   oldest_pending_age_seconds: int,
     *   consumer: array{redis_reachable: bool, stream_name: string, group_name: string, pending_entries: int, oldest_pel_idle_seconds: int}
     * }
     */
    private function buildOutboxStats(): array
    {
        $counts = [
            'pending'         => 0,
            'retrying'        => 0,
            'succeeded'       => 0,
            'failed_terminal' => 0,
        ];
        $oldestAge = 0;

        try {
            $pdo = $this->getPdo();  // existing pattern from Health.php
            $stmt = $pdo->query(<<<'SQL'
                SELECT status::text AS status, COUNT(*)::int AS n
                FROM openfga_outbox
                GROUP BY status
            SQL);
            if ($stmt !== false) {
                /** @var array{status: string, n: int} $r */
                foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
                    $counts[$r['status']] = $r['n'];
                }
            }

            $ageStmt = $pdo->query(<<<'SQL'
                SELECT COALESCE(EXTRACT(EPOCH FROM (NOW() - MIN(created_at)))::int, 0) AS age
                FROM openfga_outbox
                WHERE status IN ('pending', 'retrying')
            SQL);
            if ($ageStmt !== false) {
                $oldestAge = (int) $ageStmt->fetchColumn();
            }
        } catch (\PDOException) {
            // PG unreachable — surface zeroes; health endpoint will mark DB unhealthy separately.
        }

        $consumer = [
            'redis_reachable'        => false,
            'stream_name'            => (string) ( $_ENV['REDIS_OUTBOX_STREAM'] ?? 'litcal:reconcile-stream' ),
            'group_name'             => (string) ( $_ENV['REDIS_OUTBOX_GROUP']  ?? 'reconciler' ),
            'pending_entries'        => 0,
            'oldest_pel_idle_seconds' => 0,
        ];
        $redis = $this->getRedis();  // existing Health.php pattern
        if ($redis !== null) {
            try {
                $info = $redis->xInfo('GROUPS', $consumer['stream_name']);
                $consumer['redis_reachable'] = true;
                if (is_array($info)) {
                    foreach ($info as $group) {
                        if (is_array($group) && ( $group['name'] ?? null ) === $consumer['group_name']) {
                            $consumer['pending_entries'] = (int) ( $group['pending'] ?? 0 );
                        }
                    }
                }
                // Oldest PEL idle: XPENDING returns it as element [3] of the summary.
                $pel = $redis->xPending($consumer['stream_name'], $consumer['group_name']);
                if (is_array($pel) && isset($pel[2]) && is_numeric($pel[2])) {
                    // xPending in summary form: [count, minId, maxId, consumers]; use Redis TIME to compute idle.
                    // For simplicity here, leave oldest_pel_idle_seconds at 0 unless we want a detail call.
                }
            } catch (\Throwable) {
                $consumer['redis_reachable'] = false;
            }
        }

        return [
            'pending'                    => $counts['pending'],
            'retrying'                   => $counts['retrying'],
            'succeeded'                  => $counts['succeeded'],
            'failed_terminal'            => $counts['failed_terminal'],
            'oldest_pending_age_seconds' => $oldestAge,
            'consumer'                   => $consumer,
        ];
    }
```

Then plug `$result['openfga_outbox'] = $this->buildOutboxStats();` into the health-response assembly site.

NOTE: `getPdo()` and `getRedis()` are placeholder names — use whatever existing accessors `Health.php` already has
(grep for the connection setup; the file already manages both PDO and \Redis with APCu fallback).

- [ ] **Step 2: Smoke-test**

```bash
curl -s http://localhost:8000/health | jq .openfga_outbox
```

Expected: an object with all the keys, all zeros for a freshly-migrated DB.

- [ ] **Step 3: PHPStan**

Run: `composer analyse -- src/Health.php`

- [ ] **Step 4: Commit**

```bash
git add src/Health.php
git commit -m "feat(outbox): /health surfaces openfga_outbox block"
```

---

## Phase 10 — Deployment artifacts + .env + docs

### Task 27: systemd unit file

**Files:**

- Create: `deploy/systemd/liturgical-calendar-reconciler.service`

- [ ] **Step 1: Write the unit file**

```ini
[Unit]
Description=Liturgical Calendar OpenFGA outbox reconciler
After=network-online.target postgresql.service redis-server.service
Wants=network-online.target

[Service]
Type=simple
User=litcal
Group=litcal
WorkingDirectory=/opt/liturgical-calendar
EnvironmentFile=/opt/liturgical-calendar/.env.local
ExecStart=/usr/bin/php /opt/liturgical-calendar/bin/reconcile-outbox consumer
Restart=on-failure
RestartSec=5
StandardOutput=journal
StandardError=journal
SyslogIdentifier=litcal-reconciler

# Hardening (adjust as your ops policy dictates).
ProtectSystem=full
PrivateTmp=true
NoNewPrivileges=true

[Install]
WantedBy=multi-user.target
```

- [ ] **Step 2: Commit**

```bash
git add deploy/systemd/liturgical-calendar-reconciler.service
git commit -m "feat(outbox): systemd unit file for the reconciler consumer"
```

### Task 28: cron entry

**Files:**

- Create: `deploy/cron/liturgical-calendar-backstop.cron`

- [ ] **Step 1: Write the cron entry**

```text
# Liturgical Calendar OpenFGA outbox backstop — every 5 minutes.
# Picks up rows older than the grace window that the consumer missed
# (e.g. Redis was down or the API process died between PG commit and XADD).
# Install: copy to /etc/cron.d/litcal-outbox-backstop and `systemctl restart cron`.
*/5 * * * * litcal /usr/bin/php /opt/liturgical-calendar/bin/reconcile-outbox backstop >> /var/log/litcal-backstop.log 2>&1
```

- [ ] **Step 2: Commit**

```bash
git add deploy/cron/liturgical-calendar-backstop.cron
git commit -m "feat(outbox): cron entry for the reconciler backstop"
```

### Task 29: Operator runbook

**Files:**

- Create: `docs/ops/openfga-outbox-runbook.md`

- [ ] **Step 1: Write the runbook**

```markdown
# OpenFGA outbox — operator runbook

## What this is

The OpenFGA outbox (`openfga_outbox` table) holds every tuple write/delete the API has committed to perform.
A systemd-managed consumer drains it via Redis Streams; a cron-driven backstop catches the cracks. Together they
guarantee at-least-once application of tuple operations even across multi-minute network partitions.

See `docs/superpowers/specs/2026-06-02-openfga-async-reconciliation-design.md` for the full design.

## Install

### 1. Apply the migration

The `litcal-migrate` one-shot container handles this on `docker compose up -d --build`. Manual fallback:

\`\`\`bash
composer db:migrate
\`\`\`

### 2. Install the systemd unit

\`\`\`bash
sudo cp deploy/systemd/liturgical-calendar-reconciler.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now liturgical-calendar-reconciler.service
sudo systemctl status liturgical-calendar-reconciler.service
\`\`\`

### 3. Install the cron backstop

\`\`\`bash
sudo cp deploy/cron/liturgical-calendar-backstop.cron /etc/cron.d/litcal-outbox-backstop
sudo systemctl restart cron
\`\`\`

### 4. Confirm

\`\`\`bash
curl -s http://localhost:8000/health | jq .openfga_outbox
\`\`\`

Should return an object with all-zero counts on a fresh install.

## Diagnostic queries

\`\`\`sql
-- How deep is the queue right now?
SELECT status, COUNT(*) FROM openfga_outbox GROUP BY status;

-- What's the oldest unfinished work?
SELECT id, operation, fga_user, fga_relation, fga_object, attempts,
       last_error_code, EXTRACT(EPOCH FROM NOW() - created_at) AS age_s
FROM openfga_outbox
WHERE status IN ('pending', 'retrying')
ORDER BY created_at ASC LIMIT 10;

-- What's stuck in DLQ and why?
SELECT id, operation, fga_user, fga_relation, fga_object, last_error, last_error_code
FROM openfga_outbox
WHERE status = 'failed_terminal'
ORDER BY created_at DESC LIMIT 20;
\`\`\`

## Common incidents

### `oldest_pending_age_seconds` growing past 60s

The consumer is wedged. Check `journalctl -u liturgical-calendar-reconciler.service --since '10 min ago'`. If it's
crash-looping, you'll see RestartSec=5 cycles. Common causes: Redis unreachable (consumer logs ERROR + exits;
backstop still drains, just every 5 minutes); PG unreachable (handlers also fail, /health surfaces it).

### Rows piling up in failed_terminal

\`\`\`bash
curl -H "Authorization: Bearer $ADMIN_TOKEN" \\
  http://localhost:8000/admin/outbox?status=failed_terminal | jq .rows
\`\`\`

The `last_error_code` tells you why each row failed. Typical: `validation_error` (the API built a bad tuple — fix
upstream), `auth_failure` (OpenFGA credentials wrong — fix env).

After fixing the upstream issue:

\`\`\`bash
# Retry one row:
curl -X POST -H "Authorization: Bearer $ADMIN_TOKEN" \\
  http://localhost:8000/admin/outbox/42/retry
\`\`\`

## Retention / pruning

There is no automated prune in v1. The table grows by one row per tuple operation. For typical admin-action volume
that is years before any concern. When it does become one:

\`\`\`sql
DELETE FROM openfga_outbox
WHERE status = 'succeeded'
  AND completed_at < NOW() - INTERVAL '30 days';
\`\`\`

Don't delete `failed_terminal` rows automatically — they are the audit trail for "what didn't apply".
```

- [ ] **Step 2: Lint the runbook**

Run: `composer lint:md -- docs/ops/openfga-outbox-runbook.md`
Expected: clean.

- [ ] **Step 3: Commit**

```bash
git add docs/ops/openfga-outbox-runbook.md
git commit -m "docs(outbox): operator runbook for systemd + cron deployment"
```

### Task 30: .env.example additions

**Files:**

- Modify: `.env.example`

- [ ] **Step 1: Add the keys**

Append (near the existing `REDIS_*` block):

```dotenv
# OpenFGA outbox reconciliation
REDIS_OUTBOX_STREAM=litcal:reconcile-stream
REDIS_OUTBOX_GROUP=reconciler
REDIS_OUTBOX_CONSUMER_NAME=          # default: hostname
OUTBOX_MAX_ATTEMPTS=10
OUTBOX_BACKSTOP_GRACE_SECONDS=60
```

- [ ] **Step 2: Commit**

```bash
git add .env.example
git commit -m "docs(outbox): document reconciler env vars in .env.example"
```

---

## Phase 11 — Final verification

### Task 31: Whole-suite quality gates

- [ ] **Step 1: Full test run**

Run: `composer test`
Expected: all tests pass; no skipped tests beyond pre-existing skips (e.g. `@group needs-redis` if you opted out
locally).

- [ ] **Step 2: PHPStan L10**

Run: `composer analyse`
Expected: no errors.

- [ ] **Step 3: phpcs PSR-12**

Run: `composer lint`
Expected: no errors.

- [ ] **Step 4: Markdown lint**

Run: `composer lint:md`
Expected: no errors.

- [ ] **Step 5: Migration apply/rollback round-trip**

Run: `composer db:migrate && composer db:migrations:execute --down <Version<TIMESTAMP>> && composer db:migrate`
Expected: the new migration's `down()` cleanly drops the table + enums; `up()` re-applies cleanly.

- [ ] **Step 6: End-to-end smoke**

With `composer start` running:

```bash
# 1. Approve a pending access request (replace IDs with real ones from your seed data).
curl -s -X POST -H "Authorization: Bearer $ADMIN_TOKEN" \
  http://localhost:8000/admin/access-requests/$REQ_ID/approve \
  -H 'Content-Type: application/json' -d '{}' | jq

# 2. Verify outbox rows landed.
psql -c "SELECT id, status, fga_user, fga_relation, fga_object FROM openfga_outbox ORDER BY id DESC LIMIT 5"

# 3. Verify the systemd consumer (if installed) drained them.
# 4. Otherwise verify the backstop drains:
composer reconciler:backstop
```

- [ ] **Step 7: Push the branch**

```bash
git push -u origin feature/openfga-async-reconciliation-design
```

- [ ] **Step 8: Open PR**

Use `gh pr create --base development` with the body summarizing the spec + which acceptance criteria are met. Link
to the spec doc.

---

## Self-review checklist (for the implementer before declaring complete)

- All steps in all tasks are checked off.
- `composer test` passes (no new skips).
- `composer analyse` clean (PHPStan L10).
- `composer lint` clean (phpcs PSR-12).
- `composer lint:md` clean.
- The new migration has both `up()` and `down()` and the round-trip works.
- `/health` surfaces the `openfga_outbox` block with all expected keys.
- A real approve/revoke through the running API creates outbox rows in PG and (if Redis is up + consumer is
  running) drains them within a second.
- `/admin/outbox?status=failed_terminal` returns the right shape.
- `POST /admin/outbox/{id}/retry` resets a `failed_terminal` row.
- The systemd unit + cron entry are in `deploy/` (not actually installed by CI — operator's job).
- The runbook exists at `docs/ops/openfga-outbox-runbook.md`.
