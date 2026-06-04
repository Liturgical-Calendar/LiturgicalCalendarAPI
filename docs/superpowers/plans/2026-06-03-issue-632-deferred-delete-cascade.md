# Issue #632 — Deferred-Delete Cascade Coordination Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended)
> or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax
> for tracking.

**Goal:** Make `AccessRequestAdminHandler::revokeRequest` and `PermissionAdminHandler::revokePermission`
correctly coordinate the Zitadel role cascade with deferred OpenFGA delete-tuple rows, so a role is only
revoked once all in-scope tuples are gone, even when some deletes drain asynchronously through the outbox.

**Architecture:** A new `CascadeReconciler` service is invoked from `ConsumerLoop::tick` and
`BackstopRunner::runOnce` after every BENIGN_SUCCESS. It reads the freshly-succeeded outbox row,
dispatches on a new `metadata.cascade_kind` discriminator, and calls
`RoleCascadeService::maybeCascadeRoleRevoke` exactly when all sibling rows for the access-request have
settled (or, for the permission-revoke path, on every success). Handlers now branch on `outbox_pending`:
when 0 (sync fast-path drained everything), they keep today's synchronous cascade; when > 0, they skip
the cascade and return `cascade_deferred: true` so the reconciler takes over once the consumer/backstop
drains the queue.

**Tech Stack:** PHP 8.4+, PSR-7/15/17 (Slim-style middleware pipeline), Postgres outbox + Redis Streams +
cron backstop (from PR #631), PHPUnit 11 with layered base classes (`AbstractHandlerTestCase`,
`RepositoryTestCase`, `PHPUnit\Framework\TestCase`), PHPStan level 10, phpcs PSR-12, Redocly OpenAPI lint,
CaptainHook pre-commit.

**Source spec:** [`docs/superpowers/specs/2026-06-03-issue-632-deferred-delete-coordination-design.md`](../specs/2026-06-03-issue-632-deferred-delete-coordination-design.md).
Read it before touching code — the design rationale lives there. This plan is the executable form of
§§3–9 of that spec.

**Branch:** `feature/issue-632-deferred-delete-cascade` (already created; spec already committed in `45291dc7` + `88251ba7`).

**Top-level file inventory:**

- **Create:** `src/Services/Outbox/CascadeReconciler.php`, `phpunit_tests/Services/Outbox/CascadeReconcilerTest.php`
- **Modify (source):** `src/Repositories/OutboxRepository.php`,
  `src/Handlers/Admin/AccessRequestAdminHandler.php`, `src/Handlers/Admin/PermissionAdminHandler.php`,
  `src/Services/Outbox/ConsumerLoop.php`, `src/Services/Outbox/BackstopRunner.php`, `bin/reconcile-outbox`,
  `jsondata/schemas/openapi.json`
- **Modify (tests):** `phpunit_tests/Repositories/OutboxRepositoryTest.php`,
  `phpunit_tests/Handlers/Admin/AccessRequestAdminHandlerTest.php`,
  `phpunit_tests/Handlers/Admin/PermissionAdminHandlerTest.php`,
  `phpunit_tests/Services/Outbox/ConsumerLoopTest.php`, `phpunit_tests/Services/Outbox/BackstopRunnerTest.php`

---

## Task 1: Add `OutboxRepository::countSiblingNonTerminalDeletes`

**Files:**

- Modify: `src/Repositories/OutboxRepository.php` (add one method)
- Modify: `phpunit_tests/Repositories/OutboxRepositoryTest.php` (add one test method)

This method gates the access-request branch of the reconciler: it returns the number of `delete_tuple`
rows for a given `access_request_id` that are still in `pending` or `retrying`. Reconciler fires cascade
only when this returns 0.

- [ ] **Step 1: Write the failing test**

Append to `phpunit_tests/Repositories/OutboxRepositoryTest.php` (place it next to the other count tests; class ordering is alphabetical-ish but co-locating with `testList…` is fine):

```php
public function testCountSiblingNonTerminalDeletesCountsOnlyPendingAndRetryingDeletesForRequest(): void
{
    // Three rows for request "r1": pending delete, retrying delete, succeeded delete.
    // One row for request "r1" that's a WRITE_TUPLE (must be ignored — wrong op).
    // One row for request "r2" (must be ignored — wrong request id).
    $ids = $this->repo->insertBatch([
        [
            'operation'       => OutboxOperation::DELETE_TUPLE,
            'fga_user'        => 'user:alice',
            'fga_relation'    => 'editor',
            'fga_object'      => 'national_calendar:IT',
            'idempotency_key' => 't1:pending',
            'metadata'        => ['access_request_id' => 'r1'],
        ],
        [
            'operation'       => OutboxOperation::DELETE_TUPLE,
            'fga_user'        => 'user:alice',
            'fga_relation'    => 'editor',
            'fga_object'      => 'national_calendar:US',
            'idempotency_key' => 't2:retrying',
            'metadata'        => ['access_request_id' => 'r1'],
        ],
        [
            'operation'       => OutboxOperation::DELETE_TUPLE,
            'fga_user'        => 'user:alice',
            'fga_relation'    => 'editor',
            'fga_object'      => 'national_calendar:DE',
            'idempotency_key' => 't3:succeeded',
            'metadata'        => ['access_request_id' => 'r1'],
        ],
        [
            'operation'       => OutboxOperation::WRITE_TUPLE,
            'fga_user'        => 'user:alice',
            'fga_relation'    => 'editor',
            'fga_object'      => 'national_calendar:FR',
            'idempotency_key' => 't4:write_ignored',
            'metadata'        => ['access_request_id' => 'r1'],
        ],
        [
            'operation'       => OutboxOperation::DELETE_TUPLE,
            'fga_user'        => 'user:bob',
            'fga_relation'    => 'editor',
            'fga_object'      => 'national_calendar:IT',
            'idempotency_key' => 't5:other_request',
            'metadata'        => ['access_request_id' => 'r2'],
        ],
    ]);

    // Drive row 2 to retrying and row 3 to succeeded.
    $this->repo->markRetryable(
        $ids[1],
        1,
        new \DateTimeImmutable('+10 seconds', new \DateTimeZone('Europe/Vatican')),
        'transient',
        null,
    );
    $this->repo->markSucceeded($ids[2]);

    self::assertSame(2, $this->repo->countSiblingNonTerminalDeletes('r1'));
    self::assertSame(1, $this->repo->countSiblingNonTerminalDeletes('r2'));
    self::assertSame(0, $this->repo->countSiblingNonTerminalDeletes('nonexistent'));
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `composer test -- --filter testCountSiblingNonTerminalDeletes`

Expected: error like `Error: Call to undefined method ... countSiblingNonTerminalDeletes()`. If the DB env
is unset the test is skipped — that's fine for now (the test will run in CI), proceed to implementation.
Confirm the test is actually attempted (not class-load-error) by reading the failure message.

- [ ] **Step 3: Implement the method**

Add this method to `src/Repositories/OutboxRepository.php` immediately after `countByStatus()` (search for
`public function countByStatus`, place new method below it; keep `hydrate()` last):

```php
/**
 * Count `delete_tuple` rows for an access_request that haven't reached a terminal
 * status. Used by CascadeReconciler to decide whether the access-request's role
 * cascade can fire.
 *
 * "Non-terminal" = pending OR retrying. `failed_terminal` counts as settled because
 * the cascade decision should still run; maybeCascadeRoleRevoke's own FGA read will
 * correctly see any leftover orphan tuple and decline.
 */
public function countSiblingNonTerminalDeletes(string $accessRequestId): int
{
    $stmt = $this->db->prepare(<<<'SQL'
        SELECT COUNT(*) AS c
        FROM openfga_outbox
        WHERE metadata->>'access_request_id' = :access_request_id
          AND operation = 'delete_tuple'
          AND status IN ('pending', 'retrying')
    SQL);
    $stmt->execute([':access_request_id' => $accessRequestId]);
    $row = $stmt->fetch();
    return is_array($row) && isset($row['c']) ? (int) $row['c'] : 0;
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `composer test -- --filter testCountSiblingNonTerminalDeletes`

Expected: `OK (1 test, 3 assertions)` — or skipped if DB env unset. If skipped locally, that's fine; CI will run it.

- [ ] **Step 5: Static analysis + lint**

Run: `composer analyse -- src/Repositories/OutboxRepository.php phpunit_tests/Repositories/OutboxRepositoryTest.php`

Expected: `[OK] No errors`. Then `composer lint -- src/Repositories/OutboxRepository.php` → no output (clean).

- [ ] **Step 6: Commit**

```bash
git add src/Repositories/OutboxRepository.php phpunit_tests/Repositories/OutboxRepositoryTest.php
git commit -m "feat(outbox): countSiblingNonTerminalDeletes for cascade reconciler

Gates the access-request branch of CascadeReconciler: returns the number
of delete_tuple rows for an access_request still in pending or retrying.
failed_terminal counts as settled; the reconciler relies on
maybeCascadeRoleRevoke's own FGA read to correctly decline cascade when
an orphan tuple remains.

Part of #632."
```

---

## Task 2: Create `CascadeReconciler` skeleton + dispatch shell

**Files:**

- Create: `src/Services/Outbox/CascadeReconciler.php`
- Create: `phpunit_tests/Services/Outbox/CascadeReconcilerTest.php`

Skeleton-only: constructor, `evaluate($rowId)` with the four "no-op" branches from spec §4.2 steps 1, 4,
5. Discriminator-branch implementation comes in Tasks 3 and 4. This task locks down the no-op contract.

- [ ] **Step 1: Write failing tests for the no-op branches**

Create `phpunit_tests/Services/Outbox/CascadeReconcilerTest.php`:

```php
<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Services\Outbox;

use LiturgicalCalendar\Api\Repositories\OutboxRepository;
use LiturgicalCalendar\Api\Services\Outbox\CascadeReconciler;
use LiturgicalCalendar\Api\Services\Outbox\OutboxOperation;
use LiturgicalCalendar\Api\Services\Outbox\OutboxRow;
use LiturgicalCalendar\Api\Services\Outbox\OutboxStatus;
use LiturgicalCalendar\Api\Services\RoleCascadeService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CascadeReconciler::class)]
final class CascadeReconcilerTest extends TestCase
{
    private function row(
        int $id = 1,
        OutboxOperation $operation = OutboxOperation::DELETE_TUPLE,
        OutboxStatus $status = OutboxStatus::SUCCEEDED,
        array $metadata = [],
    ): OutboxRow {
        return new OutboxRow(
            id: $id,
            operation: $operation,
            fgaUser: 'user:alice',
            fgaRelation: 'editor',
            fgaObject: 'national_calendar:IT',
            status: $status,
            attempts: 0,
            nextAttemptAt: new \DateTimeImmutable('now', new \DateTimeZone('Europe/Vatican')),
            lastError: null,
            lastErrorCode: null,
            createdAt: new \DateTimeImmutable('now', new \DateTimeZone('Europe/Vatican')),
            completedAt: null,
            metadata: $metadata,
        );
    }

    public function testEvaluateNoOpsWhenRowMissing(): void
    {
        $repo = $this->createMock(OutboxRepository::class);
        $repo->method('getById')->willReturn(null);

        $cascade = $this->createMock(RoleCascadeService::class);
        $cascade->expects(self::never())->method('maybeCascadeRoleRevoke');

        (new CascadeReconciler($repo, $cascade))->evaluate(99);
    }

    public function testEvaluateNoOpsWhenStatusIsNotSucceeded(): void
    {
        $repo = $this->createMock(OutboxRepository::class);
        $repo->method('getById')->willReturn($this->row(
            status: OutboxStatus::RETRYING,
            metadata: ['cascade_kind' => 'access_request_revoke', 'access_request_id' => 'r1', 'cascade_user_id' => 'u1', 'cascade_role' => 'editor'],
        ));

        $cascade = $this->createMock(RoleCascadeService::class);
        $cascade->expects(self::never())->method('maybeCascadeRoleRevoke');

        (new CascadeReconciler($repo, $cascade))->evaluate(1);
    }

    public function testEvaluateNoOpsWhenOperationIsNotDeleteTuple(): void
    {
        $repo = $this->createMock(OutboxRepository::class);
        $repo->method('getById')->willReturn($this->row(
            operation: OutboxOperation::WRITE_TUPLE,
            metadata: ['cascade_kind' => 'access_request_revoke', 'access_request_id' => 'r1', 'cascade_user_id' => 'u1', 'cascade_role' => 'editor'],
        ));

        $cascade = $this->createMock(RoleCascadeService::class);
        $cascade->expects(self::never())->method('maybeCascadeRoleRevoke');

        (new CascadeReconciler($repo, $cascade))->evaluate(1);
    }

    public function testEvaluateNoOpsWhenMetadataHasNoCascadeKind(): void
    {
        $repo = $this->createMock(OutboxRepository::class);
        $repo->method('getById')->willReturn($this->row(metadata: ['admin_user' => 'admin:x']));

        $cascade = $this->createMock(RoleCascadeService::class);
        $cascade->expects(self::never())->method('maybeCascadeRoleRevoke');

        (new CascadeReconciler($repo, $cascade))->evaluate(1);
    }

    public function testEvaluateNoOpsOnUnknownCascadeKind(): void
    {
        $repo = $this->createMock(OutboxRepository::class);
        $repo->method('getById')->willReturn($this->row(metadata: ['cascade_kind' => 'future_kind_v3']));

        $cascade = $this->createMock(RoleCascadeService::class);
        $cascade->expects(self::never())->method('maybeCascadeRoleRevoke');

        (new CascadeReconciler($repo, $cascade))->evaluate(1);
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `composer test -- --filter CascadeReconciler`

Expected: `Error: Class "LiturgicalCalendar\Api\Services\Outbox\CascadeReconciler" not found`.

- [ ] **Step 3: Implement the skeleton**

Create `src/Services/Outbox/CascadeReconciler.php`:

```php
<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services\Outbox;

use LiturgicalCalendar\Api\Repositories\OutboxRepository;
use LiturgicalCalendar\Api\Services\RoleCascadeService;
use Psr\Log\LoggerInterface;

/**
 * Post-success bridge between the outbox and Zitadel role cascade.
 *
 * Invoked by ConsumerLoop::tick and BackstopRunner::runOnce after every
 * processOne() returns BENIGN_SUCCESS. Reads the row, dispatches on a
 * metadata.cascade_kind discriminator, and calls
 * RoleCascadeService::maybeCascadeRoleRevoke when appropriate. Never
 * throws back to the caller — failures are logged and swallowed; the
 * row stays in 'succeeded' regardless.
 *
 * See docs/superpowers/specs/2026-06-03-issue-632-deferred-delete-coordination-design.md
 * for the design and acceptance criteria.
 */
final class CascadeReconciler
{
    public function __construct(
        private readonly OutboxRepository $outboxRepo,
        private readonly RoleCascadeService $cascade,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function evaluate(int $rowId): void
    {
        $row = $this->outboxRepo->getById($rowId);
        if ($row === null) {
            return;
        }
        if ($row->status !== OutboxStatus::SUCCEEDED) {
            return;
        }
        if ($row->operation !== OutboxOperation::DELETE_TUPLE) {
            return;
        }

        $kind = is_string($row->metadata['cascade_kind'] ?? null)
            ? $row->metadata['cascade_kind']
            : null;
        if ($kind === null) {
            return;
        }

        match ($kind) {
            'access_request_revoke' => $this->dispatchAccessRequestRevoke($row),
            'permission_revoke'     => $this->dispatchPermissionRevoke($row),
            default                 => $this->logger?->warning(
                'CascadeReconciler: unknown cascade_kind, ignoring row',
                ['row_id' => $row->id, 'cascade_kind' => $kind],
            ),
        };
    }

    private function dispatchAccessRequestRevoke(OutboxRow $row): void
    {
        // Implemented in Task 3.
    }

    private function dispatchPermissionRevoke(OutboxRow $row): void
    {
        // Implemented in Task 4.
    }
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `composer test -- --filter CascadeReconciler`

Expected: `OK (5 tests, 5 assertions)`.

- [ ] **Step 5: Static analysis + lint**

Run: `composer analyse -- src/Services/Outbox/CascadeReconciler.php phpunit_tests/Services/Outbox/CascadeReconcilerTest.php`

Expected: `[OK] No errors`. Then `composer lint -- src/Services/Outbox/CascadeReconciler.php phpunit_tests/Services/Outbox/CascadeReconcilerTest.php` → clean.

- [ ] **Step 6: Commit**

```bash
git add src/Services/Outbox/CascadeReconciler.php phpunit_tests/Services/Outbox/CascadeReconcilerTest.php
git commit -m "feat(outbox): CascadeReconciler skeleton with no-op branches

Constructor + evaluate() that no-ops on missing row, non-succeeded
status, non-delete operation, no cascade_kind in metadata, and
unknown cascade_kind values. The two dispatch branches
(access_request_revoke, permission_revoke) are stubbed; their bodies
land in subsequent commits.

Part of #632."
```

---

## Task 3: Implement `access_request_revoke` dispatch

**Files:**

- Modify: `src/Services/Outbox/CascadeReconciler.php` (fill in `dispatchAccessRequestRevoke`)
- Modify: `phpunit_tests/Services/Outbox/CascadeReconcilerTest.php` (add behavioural tests)

- [ ] **Step 1: Write failing tests**

Add these methods to `CascadeReconcilerTest`:

```php
public function testAccessRequestRevokeDefersWhenSiblingsStillPending(): void
{
    $row = $this->row(metadata: [
        'cascade_kind'      => 'access_request_revoke',
        'access_request_id' => 'r1',
        'cascade_user_id'   => 'u1',
        'cascade_role'      => 'editor',
    ]);
    $repo = $this->createMock(OutboxRepository::class);
    $repo->method('getById')->willReturn($row);
    $repo->expects(self::once())
        ->method('countSiblingNonTerminalDeletes')
        ->with('r1')
        ->willReturn(2);

    $cascade = $this->createMock(RoleCascadeService::class);
    $cascade->expects(self::never())->method('maybeCascadeRoleRevoke');

    (new CascadeReconciler($repo, $cascade))->evaluate(1);
}

public function testAccessRequestRevokeFiresCascadeWhenAllSiblingsSettled(): void
{
    $row = $this->row(metadata: [
        'cascade_kind'      => 'access_request_revoke',
        'access_request_id' => 'r1',
        'cascade_user_id'   => 'u1',
        'cascade_role'      => 'editor',
    ]);
    $repo = $this->createMock(OutboxRepository::class);
    $repo->method('getById')->willReturn($row);
    $repo->method('countSiblingNonTerminalDeletes')->willReturn(0);

    $cascade = $this->createMock(RoleCascadeService::class);
    $cascade->expects(self::once())
        ->method('maybeCascadeRoleRevoke')
        ->with('u1', 'editor')
        ->willReturn(true);

    (new CascadeReconciler($repo, $cascade))->evaluate(1);
}

public function testAccessRequestRevokeNoOpsWhenCascadeFieldsMissing(): void
{
    // discriminator present, but cascade_user_id/cascade_role absent (defensive)
    $row = $this->row(metadata: [
        'cascade_kind'      => 'access_request_revoke',
        'access_request_id' => 'r1',
    ]);
    $repo = $this->createMock(OutboxRepository::class);
    $repo->method('getById')->willReturn($row);

    $cascade = $this->createMock(RoleCascadeService::class);
    $cascade->expects(self::never())->method('maybeCascadeRoleRevoke');

    (new CascadeReconciler($repo, $cascade))->evaluate(1);
}

public function testAccessRequestRevokeSwallowsCascadeException(): void
{
    $row = $this->row(metadata: [
        'cascade_kind'      => 'access_request_revoke',
        'access_request_id' => 'r1',
        'cascade_user_id'   => 'u1',
        'cascade_role'      => 'editor',
    ]);
    $repo = $this->createMock(OutboxRepository::class);
    $repo->method('getById')->willReturn($row);
    $repo->method('countSiblingNonTerminalDeletes')->willReturn(0);

    $cascade = $this->createMock(RoleCascadeService::class);
    $cascade->method('maybeCascadeRoleRevoke')->willThrowException(new \RuntimeException('zitadel down'));

    // Must not propagate.
    (new CascadeReconciler($repo, $cascade))->evaluate(1);
    $this->expectNotToPerformAssertions();
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `composer test -- --filter CascadeReconciler`

Expected: the four new tests fail (or the second one fails because `dispatchAccessRequestRevoke` is a
no-op stub; check `Method ... maybeCascadeRoleRevoke ... should be called once` in the failure output).

- [ ] **Step 3: Implement the dispatch**

Replace the stub `dispatchAccessRequestRevoke` in `src/Services/Outbox/CascadeReconciler.php`:

```php
private function dispatchAccessRequestRevoke(OutboxRow $row): void
{
    $accessRequestId = is_string($row->metadata['access_request_id'] ?? null)
        ? $row->metadata['access_request_id']
        : null;
    $userId = is_string($row->metadata['cascade_user_id'] ?? null)
        ? $row->metadata['cascade_user_id']
        : null;
    $role = is_string($row->metadata['cascade_role'] ?? null)
        ? $row->metadata['cascade_role']
        : null;

    if ($accessRequestId === null || $userId === null || $role === null) {
        $this->logger?->warning(
            'CascadeReconciler: access_request_revoke row missing cascade fields',
            ['row_id' => $row->id, 'has_request_id' => $accessRequestId !== null,
             'has_user_id' => $userId !== null, 'has_role' => $role !== null],
        );
        return;
    }

    $pending = $this->outboxRepo->countSiblingNonTerminalDeletes($accessRequestId);
    if ($pending > 0) {
        $this->logger?->info(
            'CascadeReconciler: deferring access-request cascade — siblings still in flight',
            ['row_id' => $row->id, 'access_request_id' => $accessRequestId, 'pending' => $pending],
        );
        return;
    }

    try {
        $removed = $this->cascade->maybeCascadeRoleRevoke($userId, $role);
        $this->logger?->info(
            'CascadeReconciler: evaluated access-request cascade',
            ['row_id' => $row->id, 'access_request_id' => $accessRequestId,
             'user_id' => $userId, 'role' => $role, 'role_removed' => $removed],
        );
    } catch (\Throwable $e) {
        $this->logger?->warning(
            'CascadeReconciler: maybeCascadeRoleRevoke threw — continuing',
            ['row_id' => $row->id, 'access_request_id' => $accessRequestId,
             'user_id' => $userId, 'role' => $role, 'error' => $e->getMessage()],
        );
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `composer test -- --filter CascadeReconciler`

Expected: `OK (9 tests, ... assertions)`.

- [ ] **Step 5: Static analysis + lint**

Run: `composer analyse -- src/Services/Outbox/CascadeReconciler.php` and `composer lint -- src/Services/Outbox/CascadeReconciler.php`. Expected clean.

- [ ] **Step 6: Commit**

```bash
git add src/Services/Outbox/CascadeReconciler.php phpunit_tests/Services/Outbox/CascadeReconcilerTest.php
git commit -m "feat(outbox): CascadeReconciler access_request_revoke dispatch

Reads access_request_id + cascade_user_id + cascade_role from the
succeeded delete-tuple row's metadata. Gates on
OutboxRepository::countSiblingNonTerminalDeletes — if siblings still
pending or retrying, defers; if all settled, calls
RoleCascadeService::maybeCascadeRoleRevoke and logs the boolean
result. maybeCascadeRoleRevoke exceptions are caught and logged
non-fatally.

Part of #632."
```

---

## Task 4: Implement `permission_revoke` dispatch

**Files:**

- Modify: `src/Services/Outbox/CascadeReconciler.php` (fill in `dispatchPermissionRevoke`)
- Modify: `phpunit_tests/Services/Outbox/CascadeReconcilerTest.php` (add tests)

- [ ] **Step 1: Write failing tests**

Append to `CascadeReconcilerTest`:

```php
public function testPermissionRevokeFiresMaybeCascadePerCandidateRole(): void
{
    $row = $this->row(metadata: [
        'cascade_kind'            => 'permission_revoke',
        'cascade_user_id'         => 'u1',
        'cascade_role_candidates' => ['editor', 'viewer'],
    ]);
    $repo = $this->createMock(OutboxRepository::class);
    $repo->method('getById')->willReturn($row);
    $repo->expects(self::never())->method('countSiblingNonTerminalDeletes');

    $cascade = $this->createMock(RoleCascadeService::class);
    $matcher = self::exactly(2);
    $cascade->expects($matcher)
        ->method('maybeCascadeRoleRevoke')
        ->willReturnCallback(function (string $userId, string $role) use ($matcher): bool {
            self::assertSame('u1', $userId);
            self::assertSame(['editor', 'viewer'][$matcher->numberOfInvocations() - 1], $role);
            return false;
        });

    (new CascadeReconciler($repo, $cascade))->evaluate(1);
}

public function testPermissionRevokeNoOpsWhenCandidatesEmpty(): void
{
    $row = $this->row(metadata: [
        'cascade_kind'            => 'permission_revoke',
        'cascade_user_id'         => 'u1',
        'cascade_role_candidates' => [],
    ]);
    $repo = $this->createMock(OutboxRepository::class);
    $repo->method('getById')->willReturn($row);

    $cascade = $this->createMock(RoleCascadeService::class);
    $cascade->expects(self::never())->method('maybeCascadeRoleRevoke');

    (new CascadeReconciler($repo, $cascade))->evaluate(1);
}

public function testPermissionRevokeNoOpsWhenCascadeUserIdMissing(): void
{
    $row = $this->row(metadata: [
        'cascade_kind'            => 'permission_revoke',
        'cascade_role_candidates' => ['editor'],
    ]);
    $repo = $this->createMock(OutboxRepository::class);
    $repo->method('getById')->willReturn($row);

    $cascade = $this->createMock(RoleCascadeService::class);
    $cascade->expects(self::never())->method('maybeCascadeRoleRevoke');

    (new CascadeReconciler($repo, $cascade))->evaluate(1);
}

public function testPermissionRevokeContinuesAfterOneCandidateThrows(): void
{
    $row = $this->row(metadata: [
        'cascade_kind'            => 'permission_revoke',
        'cascade_user_id'         => 'u1',
        'cascade_role_candidates' => ['editor', 'viewer'],
    ]);
    $repo = $this->createMock(OutboxRepository::class);
    $repo->method('getById')->willReturn($row);

    $cascade = $this->createMock(RoleCascadeService::class);
    $cascade->expects(self::exactly(2))
        ->method('maybeCascadeRoleRevoke')
        ->willReturnOnConsecutiveCalls(
            self::throwException(new \RuntimeException('boom')),
            true,
        );

    (new CascadeReconciler($repo, $cascade))->evaluate(1);
    $this->expectNotToPerformAssertions();
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `composer test -- --filter CascadeReconciler`

Expected: the four new tests fail (the stub dispatchPermissionRevoke does nothing).

- [ ] **Step 3: Implement the dispatch**

Replace the stub `dispatchPermissionRevoke` in `src/Services/Outbox/CascadeReconciler.php`:

```php
private function dispatchPermissionRevoke(OutboxRow $row): void
{
    $userId = is_string($row->metadata['cascade_user_id'] ?? null)
        ? $row->metadata['cascade_user_id']
        : null;
    $candidates = $row->metadata['cascade_role_candidates'] ?? null;

    if ($userId === null || !is_array($candidates)) {
        $this->logger?->warning(
            'CascadeReconciler: permission_revoke row missing cascade fields',
            ['row_id' => $row->id, 'has_user_id' => $userId !== null, 'has_candidates' => is_array($candidates)],
        );
        return;
    }

    foreach ($candidates as $role) {
        if (!is_string($role) || $role === '') {
            continue;
        }
        try {
            $removed = $this->cascade->maybeCascadeRoleRevoke($userId, $role);
            $this->logger?->info(
                'CascadeReconciler: evaluated permission-revoke cascade for role',
                ['row_id' => $row->id, 'user_id' => $userId, 'role' => $role, 'role_removed' => $removed],
            );
        } catch (\Throwable $e) {
            $this->logger?->warning(
                'CascadeReconciler: maybeCascadeRoleRevoke threw for one candidate — continuing',
                ['row_id' => $row->id, 'user_id' => $userId, 'role' => $role, 'error' => $e->getMessage()],
            );
        }
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `composer test -- --filter CascadeReconciler`

Expected: `OK (13 tests, ... assertions)`.

- [ ] **Step 5: Static analysis + lint**

Run: `composer analyse -- src/Services/Outbox/CascadeReconciler.php` and `composer lint -- src/Services/Outbox/CascadeReconciler.php`. Expected clean.

- [ ] **Step 6: Commit**

```bash
git add src/Services/Outbox/CascadeReconciler.php phpunit_tests/Services/Outbox/CascadeReconcilerTest.php
git commit -m "feat(outbox): CascadeReconciler permission_revoke dispatch

Reads cascade_user_id + cascade_role_candidates from the succeeded
delete-tuple row's metadata, then calls
RoleCascadeService::maybeCascadeRoleRevoke once per candidate. Each
call's exception is caught individually so a single bad candidate
doesn't block the others.

Part of #632."
```

---

## Task 5: Add `CascadeReconciler::fromEnv()` factory

**Files:**

- Modify: `src/Services/Outbox/CascadeReconciler.php` (add static factory)

The two `bin/reconcile-outbox` modes need a single-line constructor. Mirror the `fromEnv` pattern used by `RoleCascadeService` and `OpenFgaClient`.

- [ ] **Step 1: Write a failing factory test**

Append to `phpunit_tests/Services/Outbox/CascadeReconcilerTest.php`:

```php
public function testFromEnvBuildsInstanceWithoutThrowingWhenEnvNotConfigured(): void
{
    // fromEnv must be callable without DB/Zitadel/FGA env; lazy use comes later.
    // We don't actually exercise the wired dependencies — just confirm no env-read throws.
    $reconciler = CascadeReconciler::fromEnv();
    self::assertInstanceOf(CascadeReconciler::class, $reconciler);
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `composer test -- --filter CascadeReconciler::testFromEnv`

Expected: `Error: ... undefined method ... fromEnv`.

- [ ] **Step 3: Add the factory**

Add this static method to `src/Services/Outbox/CascadeReconciler.php`, immediately after the constructor:

```php
/**
 * Build a reconciler from environment-configured collaborators.
 *
 * Matches the fromEnv() pattern used by RoleCascadeService and OpenFgaClient.
 * Reads DB / OpenFGA / Zitadel / Redis settings from $_ENV. The reconciler
 * holds these as constructor-injected services; if any are misconfigured,
 * the underlying evaluate() call will surface that at use time (per-row
 * try/catch), not here at construction.
 */
public static function fromEnv(?LoggerInterface $logger = null): self
{
    return new self(
        new OutboxRepository(\LiturgicalCalendar\Api\Database\Connection::getInstance()),
        RoleCascadeService::fromEnv($logger),
        $logger,
    );
}
```

Make sure the namespace imports include `LiturgicalCalendar\Api\Database\Connection` (add it to the `use` block at the top of the file).

- [ ] **Step 4: Run the test to verify it passes**

Run: `composer test -- --filter CascadeReconciler`

Expected: all 14 tests pass. If `Connection::getInstance()` requires DB env to even construct, set DB env
via `EnvIsolationTrait` in the test instead, OR loosen the test to wrap in `try/catch` and assert the type
only on success. Read `src/Database/Connection.php` first; the existing `RoleCascadeService::fromEnv()`
already constructs an `OutboxRepository(Connection::getInstance())` without throwing, so this should be
safe.

- [ ] **Step 5: Static analysis + lint**

Run: `composer analyse -- src/Services/Outbox/CascadeReconciler.php` and `composer lint -- src/Services/Outbox/CascadeReconciler.php`. Expected clean.

- [ ] **Step 6: Commit**

```bash
git add src/Services/Outbox/CascadeReconciler.php phpunit_tests/Services/Outbox/CascadeReconcilerTest.php
git commit -m "feat(outbox): CascadeReconciler::fromEnv() factory

Mirrors the fromEnv() pattern used by RoleCascadeService and
OpenFgaClient. bin/reconcile-outbox uses this to wire the reconciler
into both ConsumerLoop and BackstopRunner in Task 10.

Part of #632."
```

---

## Task 6: Wire `CascadeReconciler` into `ConsumerLoop`

**Files:**

- Modify: `src/Services/Outbox/ConsumerLoop.php`
- Modify: `phpunit_tests/Services/Outbox/ConsumerLoopTest.php`

- [ ] **Step 1: Write failing tests**

Add these methods to `ConsumerLoopTest`:

```php
public function testTickInvokesCascadeReconcilerOnBenignSuccess(): void
{
    $consumer = $this->createStub(StreamConsumerInterface::class);
    $consumer->method('readOnce')->willReturnCallback(
        static function (int $blockMs, callable $process): void {
            $process(7);
        },
    );

    $processor = $this->createMock(OutboxProcessorInterface::class);
    $processor->method('processOne')->with(7)->willReturn(OutboxDisposition::BENIGN_SUCCESS);

    $reconciler = $this->createMock(CascadeReconciler::class);
    $reconciler->expects(self::once())->method('evaluate')->with(7);

    $loop = new ConsumerLoop($consumer, $processor, blockMs: 5000, cascade: $reconciler);
    $loop->tick();
}

public function testTickDoesNotInvokeReconcilerWhenDispositionIsRetryOrTerminal(): void
{
    $consumer = $this->createStub(StreamConsumerInterface::class);
    $consumer->method('readOnce')->willReturnCallback(
        static function (int $blockMs, callable $process): void {
            $process(7);
            $process(8);
        },
    );

    $processor = $this->createMock(OutboxProcessorInterface::class);
    $processor->method('processOne')->willReturnOnConsecutiveCalls(
        OutboxDisposition::RETRY,
        OutboxDisposition::TERMINAL,
    );

    $reconciler = $this->createMock(CascadeReconciler::class);
    $reconciler->expects(self::never())->method('evaluate');

    $loop = new ConsumerLoop($consumer, $processor, blockMs: 5000, cascade: $reconciler);
    $loop->tick();
}

public function testTickSwallowsReconcilerThrows(): void
{
    $consumer = $this->createStub(StreamConsumerInterface::class);
    $consumer->method('readOnce')->willReturnCallback(
        static function (int $blockMs, callable $process): void {
            $process(7);
        },
    );

    $processor = $this->createMock(OutboxProcessorInterface::class);
    $processor->method('processOne')->willReturn(OutboxDisposition::BENIGN_SUCCESS);

    $reconciler = $this->createMock(CascadeReconciler::class);
    $reconciler->method('evaluate')->willThrowException(new \RuntimeException('cascade fail'));

    $loop = new ConsumerLoop($consumer, $processor, blockMs: 5000, cascade: $reconciler);
    $loop->tick(); // Must not throw.

    $this->expectNotToPerformAssertions();
}
```

You'll need an import: add `use LiturgicalCalendar\Api\Services\Outbox\CascadeReconciler;` to the test file's `use` block.

- [ ] **Step 2: Run tests to verify they fail**

Run: `composer test -- --filter ConsumerLoop`

Expected: `Unknown named parameter $cascade` (or constructor signature mismatch) on the new tests; existing tests still pass.

- [ ] **Step 3: Update `ConsumerLoop`**

Modify `src/Services/Outbox/ConsumerLoop.php`. Replace the entire class body with:

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
 *
 * Optionally wires a CascadeReconciler that's invoked after every
 * BENIGN_SUCCESS to bridge the outbox row's success into the Zitadel
 * role-cascade decision. The reconciler is constructor-optional so
 * existing tests and tooling that don't need cascade can still
 * construct a ConsumerLoop with the original 3-arg signature.
 */
final class ConsumerLoop
{
    private bool $groupEnsured = false;

    public function __construct(
        private readonly StreamConsumerInterface $consumer,
        private readonly OutboxProcessorInterface $processor,
        private readonly int $blockMs = 5000,
        private readonly ?CascadeReconciler $cascade = null,
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
                $disposition = $this->processor->processOne($rowId);
                if ($disposition === OutboxDisposition::BENIGN_SUCCESS && $this->cascade !== null) {
                    try {
                        $this->cascade->evaluate($rowId);
                    } catch (\Throwable) {
                        // Never fail the consumer over a cascade decision — the row
                        // is already in succeeded state and a future sibling success
                        // (or admin re-revoke) will trigger evaluate() again.
                    }
                }
            },
        );
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

- [ ] **Step 4: Verify the existing test `testTickPassesRowIdToProcessor` still passes**

The existing test instantiates `new ConsumerLoop($consumer, $processor, blockMs: 5000)` — with the new `?CascadeReconciler $cascade = null` default, this keeps working.

Run: `composer test -- --filter ConsumerLoop`

Expected: all tests pass (existing + 3 new).

- [ ] **Step 5: Static analysis + lint**

Run:

```bash
composer analyse -- src/Services/Outbox/ConsumerLoop.php phpunit_tests/Services/Outbox/ConsumerLoopTest.php
composer lint -- src/Services/Outbox/ConsumerLoop.php phpunit_tests/Services/Outbox/ConsumerLoopTest.php
```

Expected clean.

- [ ] **Step 6: Commit**

```bash
git add src/Services/Outbox/ConsumerLoop.php phpunit_tests/Services/Outbox/ConsumerLoopTest.php
git commit -m "feat(outbox): wire CascadeReconciler into ConsumerLoop::tick

Optional ?CascadeReconciler constructor arg (default null preserves
the 3-arg signature). When set, tick() calls reconciler->evaluate()
after every processOne() returns BENIGN_SUCCESS. Reconciler throws
are caught and swallowed so the consumer loop never dies over a
cascade decision.

Part of #632."
```

---

## Task 7: Wire `CascadeReconciler` into `BackstopRunner`

**Files:**

- Modify: `src/Services/Outbox/BackstopRunner.php`
- Modify: `phpunit_tests/Services/Outbox/BackstopRunnerTest.php`

Mirror Task 6, with the additional wrinkle that `BackstopRunner::runOnce` iterates multiple rows in one PG transaction.

- [ ] **Step 1: Write failing tests**

Add to `BackstopRunnerTest`:

```php
public function testRunOnceInvokesReconcilerOnEachBenignSuccess(): void
{
    // Build two rows via pickupPending mock
    $repo = $this->createMock(OutboxRepository::class);
    $rowA = $this->makeOutboxRow(101); // helper from the existing test
    $rowB = $this->makeOutboxRow(102);
    $repo->method('pickupPending')->willReturn([$rowA, $rowB]);

    $processor = $this->createMock(OutboxProcessorInterface::class);
    $processor->method('processOne')->willReturnOnConsecutiveCalls(
        OutboxDisposition::BENIGN_SUCCESS,
        OutboxDisposition::RETRY,
    );

    $reconciler = $this->createMock(CascadeReconciler::class);
    $reconciler->expects(self::once())->method('evaluate')->with(101);

    $pdo = $this->makePdoStub(); // helper from the existing test
    $runner = new BackstopRunner($repo, $processor, $pdo, graceSeconds: 60, cascade: $reconciler);
    $runner->runOnce();
}

public function testRunOnceSwallowsReconcilerThrowsAndContinues(): void
{
    $repo = $this->createMock(OutboxRepository::class);
    $rowA = $this->makeOutboxRow(201);
    $rowB = $this->makeOutboxRow(202);
    $repo->method('pickupPending')->willReturn([$rowA, $rowB]);

    $processor = $this->createMock(OutboxProcessorInterface::class);
    $processor->method('processOne')->willReturn(OutboxDisposition::BENIGN_SUCCESS);

    $reconciler = $this->createMock(CascadeReconciler::class);
    $reconciler->expects(self::exactly(2))
        ->method('evaluate')
        ->willReturnOnConsecutiveCalls(
            self::throwException(new \RuntimeException('boom')),
            null,
        );

    $pdo = $this->makePdoStub();
    $runner = new BackstopRunner($repo, $processor, $pdo, graceSeconds: 60, cascade: $reconciler);
    $runner->runOnce();
    $this->expectNotToPerformAssertions();
}
```

Read the existing `BackstopRunnerTest` first to find the actual names of the row/PDO helpers
(`makeOutboxRow`, `makePdoStub` are placeholders — substitute the real ones). If no helper exists, inline
the construction the way the existing tests do; mimic the style.

Add `use LiturgicalCalendar\Api\Services\Outbox\CascadeReconciler;` to the imports.

- [ ] **Step 2: Run tests to verify they fail**

Run: `composer test -- --filter BackstopRunner`

Expected: `Unknown named parameter $cascade` on the new tests.

- [ ] **Step 3: Update `BackstopRunner`**

Modify `src/Services/Outbox/BackstopRunner.php`. Replace the class body with:

```php
<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services\Outbox;

use LiturgicalCalendar\Api\Repositories\OutboxRepository;
use PDO;

/**
 * One-shot scan of openfga_outbox for the cron backstop.
 *
 * Picks up rows older than the grace window (default 60s — the consumer
 * gets first crack), processes them via OutboxProcessorInterface, and
 * (optionally) invokes CascadeReconciler on every BENIGN_SUCCESS so the
 * Zitadel role-cascade decision is re-evaluated in the same idempotent
 * way the consumer's hot path does.
 *
 * The grace window is the durability buffer: the consumer's XREADGROUP
 * wake-up is sub-second on the happy path, so the backstop should only
 * see rows where Redis lost the XADD or the consumer is dead.
 */
final class BackstopRunner
{
    public function __construct(
        private readonly OutboxRepository $repo,
        private readonly OutboxProcessorInterface $processor,
        private readonly PDO $pdo,
        private readonly int $graceSeconds = 60,
        private readonly ?CascadeReconciler $cascade = null,
    ) {
    }

    public function runOnce(int $limit = 100): int
    {
        // FOR UPDATE SKIP LOCKED inside pickupPending only holds locks for
        // the lifetime of the surrounding transaction. Without an explicit
        // tx the locks would be released immediately by PG's autocommit,
        // defeating the SKIP LOCKED guarantee (concurrent runners could
        // double-process). Wrap pickup + processing in one tx so the row
        // locks survive across processOne() for every picked row.
        // Timezone pinned to Europe/Vatican per the project-wide convention.
        $cutoff = ( new \DateTimeImmutable('now', new \DateTimeZone('Europe/Vatican')) )
            ->modify("-{$this->graceSeconds} seconds");

        $this->pdo->beginTransaction();
        try {
            $rows = $this->repo->pickupPending($limit, $cutoff);
            foreach ($rows as $row) {
                $disposition = $this->processor->processOne($row->id);
                if ($disposition === OutboxDisposition::BENIGN_SUCCESS && $this->cascade !== null) {
                    try {
                        $this->cascade->evaluate($row->id);
                    } catch (\Throwable) {
                        // Never fail the backstop over a cascade decision; same
                        // rationale as ConsumerLoop::tick.
                    }
                }
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        return count($rows);
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `composer test -- --filter BackstopRunner`

Expected: all tests pass (existing + 2 new).

- [ ] **Step 5: Static analysis + lint**

Run:

```bash
composer analyse -- src/Services/Outbox/BackstopRunner.php phpunit_tests/Services/Outbox/BackstopRunnerTest.php
composer lint -- src/Services/Outbox/BackstopRunner.php phpunit_tests/Services/Outbox/BackstopRunnerTest.php
```

Expected clean.

- [ ] **Step 6: Commit**

```bash
git add src/Services/Outbox/BackstopRunner.php phpunit_tests/Services/Outbox/BackstopRunnerTest.php
git commit -m "feat(outbox): wire CascadeReconciler into BackstopRunner::runOnce

Mirror of Task 6 for the cron-driven backstop path. Optional
?CascadeReconciler constructor arg; reconciler is called after each
BENIGN_SUCCESS inside the same PG transaction that processes the
row. Exceptions caught and swallowed.

Part of #632."
```

---

## Task 8: Update `AccessRequestAdminHandler::revokeRequest`

**Files:**

- Modify: `src/Handlers/Admin/AccessRequestAdminHandler.php` (`revokeRequest` + `revocationMessage`)
- Modify: `phpunit_tests/Handlers/Admin/AccessRequestAdminHandlerTest.php` (add tests, possibly tweak existing)

Three sub-changes to `revokeRequest`:

- a) Add `cascade_kind`, `cascade_user_id`, `cascade_role` to each outbox row's metadata.
- b) After computing `$outboxPending` / `$outboxFailed`, branch: if `$outboxPending === 0`, call today's
  `syncZitadelRoleRevoke`; else set `$cascadeDeferred = true` and call
  `$repo->updateZitadelSyncStatus($requestId, 'pending')` instead.
- c) Add `cascade_deferred` to both response shapes (empty-permissions fast-path = always `false`; main
  path = computed). Add `bool $cascadeDeferred` param to `revocationMessage()` and a new branch for the
  deferred message.

- [ ] **Step 1: Write failing tests**

Read the existing `AccessRequestAdminHandlerTest.php` first to find:

- the helper that sets up the Guzzle `MockHandler` for OpenFGA (likely `withMockOpenFgaClient` or similar)
- how existing revoke tests build the access-request DB state
- which tests already cover "all rows succeed sync" — those become your regression case

Add two new test methods. Example shape (adapt to existing helpers):

```php
public function testRevokeWithPendingOutboxDefersCascade(): void
{
    // Arrange: an approved access request with 2 permissions.
    $requestId = $this->seedApprovedRequest(/* user, role 'editor', 2 perms */);

    // Mock FGA so the inline deleteTuple call leaves the rows in retrying
    // (e.g., 503 response → OutboxClassifier maps to RETRY).
    $fgaMock = new MockHandler([
        new \GuzzleHttp\Psr7\Response(503, [], '{"code":"service_unavailable"}'),
        new \GuzzleHttp\Psr7\Response(503, [], '{"code":"service_unavailable"}'),
    ]);

    $response = $this->withMockOpenFgaClient($fgaMock, function () use ($requestId): \Psr\Http\Message\ResponseInterface {
        return $this->dispatch(/* POST /admin/access-requests/{id}/revoke */);
    });

    $body = $this->decodeJsonBody($response);
    self::assertTrue($body['success']);
    self::assertSame(false, $body['role_removed']);
    self::assertSame(true, $body['cascade_deferred']);
    self::assertSame(2, $body['outbox_pending']);
    self::assertNull($body['zitadel_error']);
    self::assertStringContainsString('deferred', $body['message']);

    // Zitadel sync status must be 'pending' (the deferred-marker), not 'synced' / 'failed'.
    self::assertSame('pending', $this->fetchZitadelSyncStatus($requestId));

    // Outbox rows must carry the new metadata keys.
    $rows = $this->fetchOutboxRowsForRequest($requestId);
    foreach ($rows as $r) {
        self::assertSame('access_request_revoke', $r['metadata']['cascade_kind']);
        self::assertArrayHasKey('cascade_user_id', $r['metadata']);
        self::assertArrayHasKey('cascade_role', $r['metadata']);
    }
}

public function testRevokeWithCleanSyncStillCascadesSynchronously(): void
{
    // Arrange: an approved access request with 1 permission.
    $requestId = $this->seedApprovedRequest(/* ... */);

    // Mock FGA so the inline deleteTuple succeeds and the subsequent
    // listObjects (for userHasAnyTupleInRoleScope) returns empty.
    $fgaMock = new MockHandler([
        new \GuzzleHttp\Psr7\Response(200, [], '{}'),                          // deleteTuple
        new \GuzzleHttp\Psr7\Response(200, [], '{"object_ids":[]}'),           // listObjects per scope check
        // ... repeat for each (type, relation) the cascade walks (≤ 16)
    ]);

    $response = $this->withMockOpenFgaClient($fgaMock, fn() => $this->dispatch(/*...*/));

    $body = $this->decodeJsonBody($response);
    self::assertSame(false, $body['cascade_deferred']);
    self::assertSame(0, $body['outbox_pending']);
    // role_removed depends on whether Zitadel is mocked; assert it's a bool, not deferred.
    self::assertIsBool($body['role_removed']);
}
```

The helper methods `seedApprovedRequest`, `fetchZitadelSyncStatus`, `fetchOutboxRowsForRequest`,
`decodeJsonBody`, `dispatch` are illustrative — substitute whatever the existing test file uses. Read 2–3
existing test methods in the same file to see the real shape.

- [ ] **Step 2: Run the new tests to verify they fail**

Run: `composer test -- --filter AccessRequestAdminHandler`

Expected: the two new tests fail because: (a) response has no `cascade_deferred` key, (b) outbox metadata lacks `cascade_kind`, (c) the deferred-message branch doesn't exist yet.

- [ ] **Step 3: Modify the source**

In `src/Handlers/Admin/AccessRequestAdminHandler.php`:

3a. **Outbox row metadata** — find the loop that builds `$outboxRows[]` inside `revokeRequest` (around L711–L729 today). Change the metadata to:

```php
'metadata' => [
    'access_request_id' => $requestId,
    'cascade_kind'      => 'access_request_revoke',
    'cascade_user_id'   => $userId,
    'cascade_role'      => $requestedRole,
],
```

3b. **Cascade branch** — find the lines that compute `$outboxPending` / `$outboxFailed` and then call
`syncZitadelRoleRevoke` unconditionally (around L795–L800 today). Replace the unconditional cascade call
with:

```php
$cascadeDeferred = false;
$roleRemoved     = false;
$zitadelError    = null;

if ($outboxPending === 0) {
    [$roleRemoved, $zitadelError] = $this->syncZitadelRoleRevoke(
        $repo,
        $requestId,
        $userId,
        $requestedRole,
    );
} else {
    $cascadeDeferred = true;
    // Mark sync status as pending so an operator viewing the request
    // sees the deferral explicitly rather than a stale prior status.
    if (ZitadelService::isConfigured()) {
        $repo->updateZitadelSyncStatus($requestId, 'pending');
    }
}
```

3c. **Response shape (main path)** — find the `return $this->encodeResponseBody($response, [...])` for
the main path. Add `'cascade_deferred' => $cascadeDeferred,` to the array. Pass `$cascadeDeferred` into
the `revocationMessage` call:

```php
'message' => $this->revocationMessage($roleRemoved, $zitadelError, $outboxPending, $outboxFailed, $cascadeDeferred),
```

3d. **Response shape (empty-permissions fast path)** — find the earlier
`return $this->encodeResponseBody(...)` inside `if (empty($permissions))`. Add
`'cascade_deferred' => false,` and pass `false` as the 5th arg to `revocationMessage`.

3e. **`revocationMessage` signature and body**:

```php
private function revocationMessage(
    bool $roleRemoved,
    ?string $zitadelError,
    int $outboxPending,
    int $outboxFailed,
    bool $cascadeDeferred = false,
): string {
    $base = $roleRemoved
        ? 'Access revoked, role removed (no remaining permissions in scope) and permissions deleted'
        : ( $zitadelError !== null
            ? 'Access revoked but Zitadel sync failed (will retry)'
            : ( ZitadelService::isConfigured()
                ? 'Access revoked, permissions deleted; role retained (other in-scope permissions remain)'
                : 'Access revoked (Zitadel not configured)' ) );

    if ($cascadeDeferred && $outboxPending > 0) {
        return sprintf(
            'Access revoked, permissions queued for deletion; role revocation deferred until %d permission tuple(s) drain',
            $outboxPending,
        );
    }

    if ($outboxPending > 0 && $outboxFailed > 0) {
        return sprintf(
            '%s; %d permission tuple(s) deferred for async deletion, %d failed terminally (check outbox)',
            $base,
            $outboxPending,
            $outboxFailed,
        );
    }

    if ($outboxPending > 0) {
        return sprintf(
            '%s; %d permission tuple(s) deferred for async deletion',
            $base,
            $outboxPending,
        );
    }

    if ($outboxFailed > 0) {
        return sprintf(
            '%s; %d permission tuple(s) failed terminally (check outbox)',
            $base,
            $outboxFailed,
        );
    }

    return $base;
}
```

- [ ] **Step 4: Run all AccessRequestAdminHandler tests**

Run: `composer test -- --filter AccessRequestAdminHandler`

Expected: all existing tests still pass; the two new tests pass.

If existing tests fail because they don't have a `cascade_deferred` key in expected response shape:
update those tests to assert `'cascade_deferred' => false` is present (the empty-permissions and
happy-sync cases). Keep the change minimal — only add the new assertion.

- [ ] **Step 5: Static analysis + lint**

Run:

```bash
composer analyse -- src/Handlers/Admin/AccessRequestAdminHandler.php phpunit_tests/Handlers/Admin/AccessRequestAdminHandlerTest.php
composer lint -- src/Handlers/Admin/AccessRequestAdminHandler.php phpunit_tests/Handlers/Admin/AccessRequestAdminHandlerTest.php
```

Expected clean.

- [ ] **Step 6: Commit**

```bash
git add src/Handlers/Admin/AccessRequestAdminHandler.php phpunit_tests/Handlers/Admin/AccessRequestAdminHandlerTest.php
git commit -m "feat(access-requests): defer cascade when outbox_pending > 0

revokeRequest now branches on the post-sync outbox_pending counter:
when 0, today's synchronous Zitadel cascade runs unchanged; when > 0,
the cascade is skipped, response carries cascade_deferred: true, and
Zitadel sync status is marked 'pending' so operators see the
deferral. CascadeReconciler picks it up once siblings drain.

Outbox row metadata gains cascade_kind / cascade_user_id /
cascade_role for the reconciler's access_request_revoke dispatch.
revocationMessage gets a cascadeDeferred branch.

Part of #632."
```

---

## Task 9: Update `PermissionAdminHandler::revokePermission`

**Files:**

- Modify: `src/Handlers/Admin/PermissionAdminHandler.php`
- Modify: `phpunit_tests/Handlers/Admin/PermissionAdminHandlerTest.php`

Two sub-changes:

- a) Resolve cascade candidate roles *before* the outbox insert (today it runs after). Persist them as
  `cascade_kind`, `cascade_user_id`, `cascade_object_type`, `cascade_role_candidates` in metadata. Don't
  abort on Zitadel failure — log and continue with empty candidates.
- b) Branch the in-handler cascade loop on whether the single row reached `OutboxStatus::SUCCEEDED`
  synchronously. If yes, run today's loop (using `$cascadeRoleCandidates` already in scope). If no, set
  `$cascadeDeferred = true` and skip the loop.

- [ ] **Step 1: Write failing tests**

Add to `PermissionAdminHandlerTest`:

```php
public function testRevokeWithDeferredRowDefersCascade(): void
{
    // Mock FGA so the inline deleteTuple returns 503 → RETRY → row stays pending.
    // No second listObjects call should happen (cascade is deferred).
    $fgaMock = new MockHandler([
        new \GuzzleHttp\Psr7\Response(503, [], '{"code":"service_unavailable"}'),
    ]);

    $response = $this->withMockOpenFgaClient($fgaMock, function (): \Psr\Http\Message\ResponseInterface {
        return $this->dispatchRevoke(/* user, object_type, object_id, relation */);
    });

    $body = $this->decodeJsonBody($response);
    self::assertTrue($body['success']);
    self::assertSame(true, $body['cascade_deferred']);
    self::assertSame([], $body['cascaded_roles']);
    self::assertSame(1, $body['outbox_pending']);
    self::assertStringContainsString('deferred', $body['message']);

    // Single outbox row must carry the new cascade metadata.
    $rows = $this->fetchOutboxRowsForUser(/* admin id */);
    self::assertCount(1, $rows);
    self::assertSame('permission_revoke', $rows[0]['metadata']['cascade_kind']);
    self::assertArrayHasKey('cascade_user_id', $rows[0]['metadata']);
    self::assertArrayHasKey('cascade_role_candidates', $rows[0]['metadata']);
}

public function testRevokeWithCleanSyncStillCascadesSynchronously(): void
{
    // Mock FGA: deleteTuple 200 + listObjects empty for the cascade scope walk.
    $fgaMock = new MockHandler([
        new \GuzzleHttp\Psr7\Response(200, [], '{}'),
        new \GuzzleHttp\Psr7\Response(200, [], '{"object_ids":[]}'),
        // ... additional listObjects responses per scope (depends on role)
    ]);

    $response = $this->withMockOpenFgaClient($fgaMock, fn() => $this->dispatchRevoke(/*...*/));

    $body = $this->decodeJsonBody($response);
    self::assertSame(false, $body['cascade_deferred']);
    self::assertSame(0, $body['outbox_pending']);
}
```

Same caveat as Task 8: read existing test helpers and substitute their real names.

- [ ] **Step 2: Run tests to verify they fail**

Run: `composer test -- --filter PermissionAdminHandler`

Expected: new tests fail — response lacks `cascade_deferred`, metadata lacks `cascade_kind`.

- [ ] **Step 3: Modify `PermissionAdminHandler::revokePermission`**

In `src/Handlers/Admin/PermissionAdminHandler.php`, inside `revokePermission`:

3a. **Move candidate-role resolution earlier.** Today it's at L635–L665 (after the sync loop). Cut that
block and the `$cascadeRoleCandidates` it would produce, and re-paste a refactored version of it right
before the `$outboxRow = [...]` build (around L555). The refactored version replaces the cascade-call
loop's role-discovery with a candidate-builder:

```php
// Resolve which roles' scopes include the object_type being revoked so we
// can hand the cascade reconciler a pre-computed candidate list. Failures
// are non-fatal — an empty list means the consumer-side cascade is a no-op
// and the admin can re-revoke if they want a retry.
$cascadeRoleCandidates = [];
if (ZitadelService::isConfigured() && OpenFgaClient::isConfigured()) {
    try {
        $userRoles = ZitadelService::fromEnv()->getUserRoles($bareUserId);
        foreach ($userRoles as $role) {
            $allowedTypes = AccessRequestRepository::ROLE_OBJECT_TYPES[$role] ?? [];
            if (in_array($objectType, $allowedTypes, true)) {
                $cascadeRoleCandidates[] = $role;
            }
        }
    } catch (\Throwable $e) {
        $this->logger->warning(
            'PermissionAdminHandler: failed to resolve cascade candidates; revoke continues without cascade hint',
            ['exception' => $e],
        );
    }
}
```

Note: `$bareUserId` is currently computed below the outbox-insert. **Move that computation up too** so it's available when we build the metadata. The line is roughly:

```php
$bareUserId = str_starts_with($fgaUser, 'user:') ? substr($fgaUser, 5) : $fgaUser;
```

3b. **Update outbox row metadata** (around L559–L566). Replace:

```php
'metadata'        => ['admin_user' => "user:{$userId}"],
```

with:

```php
'metadata' => [
    'admin_user'              => "user:{$userId}",
    'cascade_kind'            => 'permission_revoke',
    'cascade_user_id'         => $bareUserId,
    'cascade_object_type'     => $objectType,
    'cascade_role_candidates' => $cascadeRoleCandidates,
],
```

3c. **Branch the in-handler cascade loop on row status**. Replace today's L634–L667 (the block that re-reads `$userRoles` and iterates) with:

```php
// After-sync cascade: only run in-handler when the single outbox row drained
// synchronously. If it's still pending/retrying, defer to CascadeReconciler.
$current = $outbox->getById($outboxIds[0]);
$singleSucceededSync = $current !== null && $current->status === OutboxStatus::SUCCEEDED;

$cascadedRoles   = [];
$cascadeDeferred = false;
if ($singleSucceededSync) {
    if (ZitadelService::isConfigured() && OpenFgaClient::isConfigured()) {
        try {
            $cascade = RoleCascadeService::fromEnv();
            foreach ($cascadeRoleCandidates as $role) {
                if ($cascade->maybeCascadeRoleRevoke($bareUserId, $role)) {
                    $cascadedRoles[] = $role;
                }
            }
        } catch (\Throwable $e) {
            $this->logger->error('PermissionAdminHandler cascade check failed', ['exception' => $e]);
        }
    }
} else {
    $cascadeDeferred = true;
}
```

3d. **Update the message + response shape**. Around L668–L685:

```php
$message = $cascadeDeferred
    ? 'Permission revoked, queued for async deletion; role cascade deferred'
    : ( empty($cascadedRoles)
        ? 'Permission revoked'
        : 'Permission revoked; cascaded role(s) revoked: ' . implode(', ', $cascadedRoles) );

return $this->encodeResponseBody($response, [
    'success'          => true,
    'message'          => $message,
    'user'             => $fgaUser,
    'relation'         => $relation,
    'object'           => $fgaObject,
    'cascaded_roles'   => $cascadedRoles,
    'cascade_deferred' => $cascadeDeferred,
    'tuples_deleted'   => $deletedTuples,
    'fga_errors'       => $fgaErrors,
    'outbox_ids'       => $outboxIds,
    'outbox_pending'   => $outboxPending,
    'outbox_failed'    => $outboxFailed,
]);
```

- [ ] **Step 4: Run all PermissionAdminHandler tests**

Run: `composer test -- --filter PermissionAdminHandler`

Expected: existing tests still pass (existing happy-sync test now also asserts `cascade_deferred: false`
if it asserts the full response shape — update it minimally if needed); new tests pass.

- [ ] **Step 5: Static analysis + lint**

Run:

```bash
composer analyse -- src/Handlers/Admin/PermissionAdminHandler.php phpunit_tests/Handlers/Admin/PermissionAdminHandlerTest.php
composer lint -- src/Handlers/Admin/PermissionAdminHandler.php phpunit_tests/Handlers/Admin/PermissionAdminHandlerTest.php
```

Expected clean.

- [ ] **Step 6: Commit**

```bash
git add src/Handlers/Admin/PermissionAdminHandler.php phpunit_tests/Handlers/Admin/PermissionAdminHandlerTest.php
git commit -m "feat(permissions): defer cascade when delete row stays non-terminal

Resolves cascade candidate roles up-front (before the outbox insert)
and persists them as cascade_role_candidates in the outbox row's
metadata, alongside cascade_kind / cascade_user_id /
cascade_object_type. Zitadel getUserRoles failures degrade to an
empty candidate list and a logged warning; the revoke still completes.

After-sync cascade now only runs in-handler when the single row
reached OutboxStatus::SUCCEEDED synchronously. Otherwise response
carries cascade_deferred: true, cascaded_roles: [], and the
CascadeReconciler picks up the cascade once the consumer/backstop
drains the row.

Part of #632."
```

---

## Task 10: Wire `CascadeReconciler` into `bin/reconcile-outbox`

**Files:**

- Modify: `bin/reconcile-outbox`

Both modes (`backstop`, `consumer`) construct the reconciler from env and pass it as the new optional constructor arg.

- [ ] **Step 1: Update `bin/reconcile-outbox`**

In `bin/reconcile-outbox`, after the `$processor = new OutboxProcessor(...)` line (around L64), add:

```php
// CascadeReconciler is constructed lazily because it needs RoleCascadeService::fromEnv(),
// which reads OpenFGA + Zitadel + Redis env. In modes/environments where those aren't
// configured the reconciler still builds but its underlying calls degrade — see
// docs/superpowers/specs/2026-06-03-issue-632-deferred-delete-coordination-design.md §4.5.
$cascade = \LiturgicalCalendar\Api\Services\Outbox\CascadeReconciler::fromEnv();
```

Then update the two constructor calls:

- The `backstop` branch (around L67–L72) changes to:

  ```php
  $runner = new BackstopRunner(
      $repo,
      $processor,
      $pdo,
      graceSeconds: (int) ($_ENV['OUTBOX_BACKSTOP_GRACE_SECONDS'] ?? 60),
      cascade: $cascade,
  );
  ```

- The `consumer` branch (around L101) changes to:

  ```php
  $loop = new ConsumerLoop($stream, $processor, blockMs: 5000, cascade: $cascade);
  ```

- [ ] **Step 2: Smoke check both modes**

Run: `php bin/reconcile-outbox backstop 2>&1 | head -5`

Expected: either `backstop processed=0` (DB up and clean) or a clear PG connection error if DB env unset. **Must not** show "Undefined method" or "Class not found".

Then: `timeout 2s php bin/reconcile-outbox consumer 2>&1 | head -10`

Expected: either it blocks for ~2s reading from Redis (if Redis + DB are up) and `timeout` kills it with
exit 124, or a clear Redis/DB connection error. **Must not** show class/method errors.

- [ ] **Step 3: Static analysis + lint**

Run: `composer analyse -- bin/reconcile-outbox` and `composer lint -- bin/reconcile-outbox`. Expected clean.

- [ ] **Step 4: Commit**

```bash
git add bin/reconcile-outbox
git commit -m "feat(outbox): wire CascadeReconciler into bin/reconcile-outbox

Both modes (backstop, consumer) now build CascadeReconciler::fromEnv()
once and pass it to BackstopRunner / ConsumerLoop. This is what
actually makes the deferred cascade fire in production.

Part of #632."
```

---

## Task 11: Update OpenAPI schema

**Files:**

- Modify: `jsondata/schemas/openapi.json`

Two response schemas gain `cascade_deferred: boolean` (required). Pre-existing schema drift (e.g.,
`AdminRevokeAccessRequestResponse.tuples_deleted` typed as `integer` while the handler returns an array)
is **out of scope** — don't fix it here, just add the new field.

- [ ] **Step 1: Locate the schemas**

```bash
grep -n '"AdminRevokeAccessRequestResponse"\|"AdminRevokePermissionResponse"\|"PermissionRevokeResponse"' jsondata/schemas/openapi.json
```

The access-request response is `AdminRevokeAccessRequestResponse` (around L7791). The permission-revoke
response name needs verification — search for the actual name with the grep above. If it's named
differently in the source (e.g., `AdminPermissionRevokeResponse` or referenced inline), adjust
accordingly.

- [ ] **Step 2: Add `cascade_deferred` to `AdminRevokeAccessRequestResponse`**

In `jsondata/schemas/openapi.json`, find the `AdminRevokeAccessRequestResponse` schema (block starting
around L7791). Inside its `properties` object (between the existing `tuple_errors` property and the
closing `}` of `properties`), add:

```json
"cascade_deferred": {
  "type": "boolean",
  "description": "True when the Zitadel role-cascade decision was deferred because one or more delete-tuple rows are still in the outbox. CascadeReconciler runs the decision once the rows drain. See issue #632."
}
```

In the same schema's `required` array, append `"cascade_deferred"`.

- [ ] **Step 3: Add `cascade_deferred` to the permission-revoke response**

Repeat the same change for whichever schema name covers `DELETE /admin/permissions`. If the response is
declared inline at the path (no named schema component), add `cascade_deferred` to the inline `properties`
and `required` exactly the same way.

- [ ] **Step 4: Validate the schema**

Run: `composer lint:openapi`

Expected: `Woohoo! Your OpenAPI definition is valid.` (or equivalent success message). Fix any new errors Redocly reports; do not chase pre-existing warnings unless they're blockers.

- [ ] **Step 5: Commit**

```bash
git add jsondata/schemas/openapi.json
git commit -m "docs(openapi): add cascade_deferred to revoke response schemas

AdminRevokeAccessRequestResponse and the /admin/permissions DELETE
response gain a required cascade_deferred: boolean field. True means
the Zitadel role-cascade decision was skipped in-handler because the
outbox still has pending delete rows; CascadeReconciler runs it once
the rows drain.

Part of #632."
```

---

## Task 12: Final verification

**Files:** none modified — purely a verification pass.

- [ ] **Step 1: Run the full test suite**

Run: `composer test`

Expected: `OK` with the existing total count + 13–15 net new tests. No new skips. If anything fails, fix
in the relevant task's file and amend the corresponding commit (or add a follow-up fix commit if amend is
awkward).

- [ ] **Step 2: Run PHPStan level 10**

Run: `composer analyse`

Expected: `[OK] No errors`.

- [ ] **Step 3: Run phpcs + markdownlint + Redocly**

Run: `composer lint && composer lint:md && composer lint:openapi`

Expected: all three clean.

- [ ] **Step 4: Sanity-check branch state**

Run: `git log --oneline stable..HEAD`

Expected: the spec commits (2) + 12 task commits (one per task with code changes) = 14 total. If a task didn't produce a commit (e.g., Task 12 itself), expect 13.

Run: `git diff --stat stable..HEAD`

Expected: ~10–12 files changed, on the order of hundreds of lines added (mostly tests + the new reconciler + spec).

- [ ] **Step 5: Push the branch**

```bash
git push -u origin feature/issue-632-deferred-delete-cascade
```

- [ ] **Step 6: Open PR**

```bash
gh pr create --base development --title "fix(openfga): defer cascade when outbox has pending delete rows (#632)" --body "$(cat <<'EOF'
Closes #632.

Builds on PR #631 (async reconciliation outbox). Two CodeRabbit findings from that PR's review — both intentionally deferred for design work — are now addressed:

1. \`AccessRequestAdminHandler::revokeRequest\` no longer calls \`syncZitadelRoleRevoke\` when one or more delete-tuple rows are still pending in the outbox. Response carries \`cascade_deferred: true\`; \`CascadeReconciler\` runs the cascade once the consumer/backstop drains the siblings.
2. \`PermissionAdminHandler::revokePermission\` defers the same way when its single delete row didn't reach \`succeeded\` synchronously.

## Design + plan

- Spec: \`docs/superpowers/specs/2026-06-03-issue-632-deferred-delete-coordination-design.md\`
- Plan: \`docs/superpowers/plans/2026-06-03-issue-632-deferred-delete-cascade.md\`

## What changed

- **New service** \`CascadeReconciler\` (src/Services/Outbox/): post-success bridge between the outbox and \`RoleCascadeService::maybeCascadeRoleRevoke\`. Dispatches on a new \`metadata.cascade_kind\` discriminator.
- **New repo method** \`OutboxRepository::countSiblingNonTerminalDeletes\`: gates the access-request branch of the reconciler.
- **\`ConsumerLoop\` + \`BackstopRunner\`** accept an optional \`CascadeReconciler\` and invoke it on every BENIGN_SUCCESS; reconciler throws are swallowed so neither loop dies over a cascade decision.
- **Handlers** add cascade metadata to outbox rows, branch on \`outbox_pending\` / row terminal status, and surface \`cascade_deferred: bool\` in the response.
- **\`bin/reconcile-outbox\`** wires \`CascadeReconciler::fromEnv()\` into both modes.
- **OpenAPI** schemas for the two revoke endpoints gain \`cascade_deferred: boolean\` (required).
- **Tests** added at each layer (repository, service unit, handler integration, consumer-loop unit).

## Test plan

- [x] \`composer test\` — pre-existing + 13–15 new tests pass; no new skips
- [x] \`composer analyse\` — PHPStan L10 clean
- [x] \`composer lint\` — phpcs PSR-12 clean
- [x] \`composer lint:md\` — markdown lint clean
- [x] \`composer lint:openapi\` — Redocly clean
- [x] \`php bin/reconcile-outbox backstop\` smoke-runs without class/method errors
- [ ] Reviewer: exercise the deferred path manually on staging by failing the OpenFGA endpoint briefly during a revoke, then restoring it and confirming \`CascadeReconciler\` fires once

## Out of scope

Per spec §10:

- \`RoleCascadeService::cascadeTupleRevokeForRole\` (forward direction; no race)
- Bulk cascade-retry endpoint
- Metrics for "deferred cascade fired" (waits on the #630 framework)
- Surfacing \`cascade_deferred\` rows via \`/admin/outbox\` listing
EOF
)"
```

Expected: PR URL printed.

---

## Self-Review

### Spec coverage

Each spec section maps to a task:

| Spec section                                | Implemented by                                   |
| ------------------------------------------- | ------------------------------------------------ |
| §3 Components inventory                     | All tasks                                        |
| §4.1 `CascadeReconciler` public API         | Tasks 2, 5                                       |
| §4.2 `evaluate($rowId)` no-op branches      | Task 2                                           |
| §4.2 access_request_revoke dispatch         | Task 3                                           |
| §4.2 permission_revoke dispatch             | Task 4                                           |
| §4.3 metadata-over-fresh-read rationale     | Tasks 8, 9 (metadata writes)                     |
| §4.4 Idempotency (Zitadel + DB)             | Task 4 + relies on existing `RoleCascadeService` |
| §4.5 Error handling                         | Tasks 2, 3, 4, 6, 7                              |
| §5.1 `AccessRequestAdminHandler` changes    | Task 8                                           |
| §5.2 `PermissionAdminHandler` changes       | Task 9                                           |
| §6 `countSiblingNonTerminalDeletes`         | Task 1                                           |
| §7.1 ConsumerLoop / BackstopRunner wiring   | Tasks 6, 7                                       |
| §7.2 Construction in `bin/reconcile-outbox` | Task 10                                          |
| §8 Test plan (unit / handler / repo)        | Tasks 1–4, 6, 7, 8, 9                            |
| §8 optional integration test                | Out of scope (spec §8 marks it optional)         |
| §9 Migration / rollout (OpenAPI)            | Task 11                                          |

No gaps.

### Placeholder scan

Searched for: "TBD", "TODO", "implement later", "fill in details", "appropriate error handling", "similar to
Task". None found in concrete code blocks; the only references to other tasks are in the form "implemented
in Task N" inside the spec-described stub bodies in Task 2 (legitimate forward references), not as
substitutes for code.

### Type consistency

- `OutboxRepository::countSiblingNonTerminalDeletes(string $accessRequestId): int` — Task 1 defines, Task 3 calls with `string` arg and uses `int` return.
- `CascadeReconciler::evaluate(int $rowId): void` — Task 2 defines, Tasks 6 + 7 + 10 call with `int`.
- `CascadeReconciler::fromEnv(?LoggerInterface $logger = null): self` — Task 5 defines, Task 10 calls with no args.
- `ConsumerLoop::__construct(..., ?CascadeReconciler $cascade = null)` — Task 6 defines, Task 10 passes `cascade: $cascade`.
- `BackstopRunner::__construct(..., ?CascadeReconciler $cascade = null)` — Task 7 defines, Task 10 passes `cascade: $cascade`.
- `metadata.cascade_kind` values `'access_request_revoke'` and `'permission_revoke'` are stable strings
  used consistently across the reconciler (Tasks 3, 4) and the two handler writes (Tasks 8, 9).
- `cascade_user_id`, `cascade_role`, `cascade_role_candidates`, `access_request_id`, `cascade_object_type` — names match between reconciler reads and handler writes.

### Pre-existing OpenAPI drift

Task 11 explicitly notes that `AdminRevokeAccessRequestResponse.tuples_deleted` is typed `integer` in the
schema even though the handler returns an array — that's a PR #631 oversight, NOT this PR's
responsibility. The plan limits Task 11 to adding `cascade_deferred` only.

### Scope check

Single PR. ~12 files modified, ~hundreds of lines (mostly tests). Each task is independently committed and
the branch as a whole produces a working `composer test` / `composer analyse` / `composer lint*` clean
state. The change ships safely in a single PR per spec §9.
