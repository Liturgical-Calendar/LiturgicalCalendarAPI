# Deferred-delete coordination for OpenFGA cascade revokes — design

- **Date:** 2026-06-03
- **Issue:** [#632](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/issues/632)
- **Builds on:** [#631](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/pull/631)
  (Options B+C async reconciliation; spec `2026-06-02-openfga-async-reconciliation-design.md`)
- **Status:** Approved, ready for implementation plan

## 1. Problem

PR #631 made OpenFGA tuple writes/deletes durable via a Postgres outbox: handlers commit the DB write and the
outbox row in one transaction, attempt the FGA call synchronously, and surface deferred work via
`outbox_pending` / `outbox_failed` counters in the response.

Two cascade-revoke call sites still assume "the FGA state is consistent at handler-return time" and break
in the new asynchronous world:

### 1a. `AccessRequestAdminHandler::revokeRequest` (~L632–L827)

After enqueueing one outbox `DELETE_TUPLE` row per permission and attempting them inline, the handler
**unconditionally** calls `syncZitadelRoleRevoke` → `RoleCascadeService::maybeCascadeRoleRevoke`. The cascade
reads OpenFGA's tuples for the (user, role) scope and only revokes the role if zero tuples remain.

When one or more delete rows stayed in `pending` / `retrying`, the FGA read still sees those tuples. Cascade
declines, response carries `role_removed: false` — even though the deletes are queued and the role *will* be
eligible for revoke as soon as the consumer drains them. Nothing then triggers a re-check; the role stays
indefinitely until an admin re-revokes.

### 1b. `PermissionAdminHandler::revokePermission` (~L631–L667)

Same shape with a single-row outbox batch. Handler attempts the row synchronously, then iterates the user's
Zitadel roles and calls `maybeCascadeRoleRevoke` for each role whose scope covers the deleted `object_type`.
If the one row is still pending, every cascade call reads stale FGA state.

### 1c. Acceptance criteria (from issue)

1. A revoke with one or more pending outbox delete rows does NOT prematurely declare `role_removed: true`.
2. Once all delete rows for a given access_request reach `succeeded` (or `failed_terminal`), the cascade
   decision runs exactly once (idempotent on re-runs).
3. The handler's response distinguishes "role removed synchronously" from "role removal deferred — will
   happen after backstop drains".

## 2. Decisions

| Decision                                            | Choice                                                                       |
| --------------------------------------------------- | ---------------------------------------------------------------------------- |
| Handler timing                                      | **Hybrid** — sync cascade when `outbox_pending == 0`, defer when > 0         |
| Where consumer-side cascade lives                   | **New `CascadeReconciler` service** called from ConsumerLoop + Backstop      |
| Reconciler reads user/role from                     | **Outbox metadata** written at handler time (no Zitadel call in reconciler)  |
| "All siblings settled" semantics for access_request | All `delete_tuple` siblings in `succeeded` OR `failed_terminal`              |
| Response shape                                      | New `cascade_deferred: bool` field, always present (stable schema)           |

The hybrid handler timing preserves today's happy-path admin feedback (`role_removed: true` arrives in the
response when the sync fast-path drains everything) while making the racy path correct. The deferred path
is rare and only fires when FGA was unhealthy at handler time.

## 3. Architecture

```text
                            ┌─ outbox_pending == 0 ─► syncZitadelRoleRevoke (today's path)
revokeRequest (handler) ────┤
                            └─ outbox_pending  > 0 ─► skip; mark cascade_deferred=true

                            ┌─ row.status == succeeded (sync) ─► in-handler cascade loop
revokePermission (handler) ─┤
                            └─ row still pending/retrying     ─► skip; mark cascade_deferred=true

ConsumerLoop::tick ─────► processOne ─► (BENIGN_SUCCESS) ─► CascadeReconciler::evaluate(rowId)
BackstopRunner::runOnce ─► processOne ─► (BENIGN_SUCCESS) ─► CascadeReconciler::evaluate(rowId)
```

### Components

**Services**

- `CascadeReconciler` (new) — reads a freshly-succeeded row, dispatches on metadata shape, calls
  `RoleCascadeService::maybeCascadeRoleRevoke`.
- `RoleCascadeService` — **no change**; already idempotent.
- `OutboxProcessor` — **no contract change**; still returns disposition.
- `ConsumerLoop`, `BackstopRunner` — accept optional `CascadeReconciler`; call `evaluate($rowId)` on
  BENIGN_SUCCESS.

**Repository**

- `OutboxRepository` — add `countSiblingNonTerminalDeletes(string $accessRequestId): int`.

**Handlers**

- `AccessRequestAdminHandler::revokeRequest` — branch on `$outboxPending`; when > 0, skip
  `syncZitadelRoleRevoke`, set `cascade_deferred: true`.
- `PermissionAdminHandler::revokePermission` — branch on single row's terminal status; defer when not
  `succeeded`. Resolve cascade candidate roles up-front and persist them in metadata.

**Schema**

- OpenAPI `AccessRequestRevokeResponse`, `PermissionRevokeResponse` — add `cascade_deferred: boolean`
  (required).

## 4. `CascadeReconciler` service

### 4.1 Public API

```php
final class CascadeReconciler
{
    public function __construct(
        private readonly OutboxRepository $outboxRepo,
        private readonly RoleCascadeService $cascade,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    public static function fromEnv(?LoggerInterface $logger = null): self;

    public function evaluate(int $rowId): void;
}
```

### 4.2 `evaluate($rowId)` behaviour

Read the row once via `OutboxRepository::getById`. Both new metadata shapes carry a
`cascade_kind: string` discriminator so the reconciler's dispatch is a single `match` on one key. Switch on
operation + metadata:

1. **Row missing, status != `succeeded`, or operation != `DELETE_TUPLE`** → no-op (log INFO at debug-ish
   detail).
2. **`metadata.cascade_kind === 'access_request_revoke'`** (access-request revoke path):
   - Read `access_request_id`, `cascade_user_id`, and `cascade_role` from metadata. If any missing, log
     WARNING and no-op (defensive — handler is the only writer and always sets all three).
   - Call `outboxRepo->countSiblingNonTerminalDeletes($accessRequestId)`. If > 0, defer — a later sibling's
     success will re-trigger evaluation.
   - If 0, call `cascade->maybeCascadeRoleRevoke($cascadeUserId, $cascadeRole)`. Log INFO with the boolean
     result.
3. **`metadata.cascade_kind === 'permission_revoke'`** (direct permission revoke path):
   - Read `cascade_user_id` and `cascade_role_candidates: string[]` from metadata.
   - For each role in candidates: call `cascade->maybeCascadeRoleRevoke($cascadeUserId, $role)`. Each call
     is independently idempotent.
4. **No `cascade_kind` key** (rows enqueued by `RoleCascadeService::cascadeTupleRevokeForRole`, or pre-#632
   rows in flight during deploy): no-op. The forward role-revoke direction already removed the role from
   Zitadel at its call site; pre-#632 rows have no cascade hint and operator can re-revoke if needed.
5. **Unknown `cascade_kind` value** (e.g., from a newer schema rolled back): log WARNING and no-op.

### 4.3 Why metadata over a fresh Zitadel/DB read

The handler already has `userId`, `role` (access-request path) or `userId`, candidate roles (permission
path) in scope at decision time. Writing them as JSONB (~50 bytes) makes the reconciler:

- Pure: `(row, metadata) → cascade decision`. No Zitadel/DB dependency in tests.
- Always-available: metadata is captured atomically with the row; can't disappear.
- Free of a second Zitadel `getUserRoles` failure mode.

Staleness window is bounded by the consumer (sub-second on happy path) plus backstop (5 min). If an admin
changes the user's role assignments inside that window, candidate roles are stale → `maybeCascadeRoleRevoke`
no-ops on a role the user no longer has. Harmless.

### 4.4 Idempotency

- `RoleCascadeService::maybeCascadeRoleRevoke` is already idempotent:
  - Zitadel revoke tolerates "role already absent" (try/catch + WARNING log inside the service).
  - `AccessRequestRepository::cascadeRevokeByRole` is a SQL `UPDATE … WHERE status = 'approved'` — no-op
    once all rows are revoked.
- Two-worker race: if the consumer and backstop both finish the last sibling concurrently, both call
  `maybeCascadeRoleRevoke`. Both run idempotently. No locking required.

### 4.5 Error handling

- **Reconciler throws (PG / programming error):** caught in `ConsumerLoop::tick` /
  `BackstopRunner::runOnce`; WARNING logged; loop continues.
- **`maybeCascadeRoleRevoke` throws:** existing try/catch inside `RoleCascadeService` logs WARNING;
  reconciler returns normally.
- **Row metadata malformed / missing cascade hints:** reconciler logs INFO and no-ops. Operator can
  re-revoke to retry.
- **Sibling row in `failed_terminal`:** counts as "settled". Cascade fires; `maybeCascadeRoleRevoke`'s own
  FGA read sees the orphan tuple → declines. Correct.

## 5. Handler changes

### 5.1 `AccessRequestAdminHandler::revokeRequest`

**Outbox row metadata** grows three keys (already has `access_request_id`):

```php
$outboxRows[] = [
    'operation'       => OutboxOperation::DELETE_TUPLE,
    'fga_user'        => $fgaUser,
    'fga_relation'    => $relation,
    'fga_object'      => $fgaObject,
    'idempotency_key' => $idempotencyKey,
    'metadata'        => [
        'access_request_id' => $requestId,
        'cascade_kind'      => 'access_request_revoke', // NEW — discriminator
        'cascade_user_id'   => $userId,                 // NEW
        'cascade_role'      => $requestedRole,          // NEW
    ],
];
```

**Cascade-call branch** replaces today's unconditional `syncZitadelRoleRevoke(...)` call near the end of the
non-empty-permissions path:

```php
$cascadeDeferred = false;
$roleRemoved     = false;
$zitadelError    = null;

if ($outboxPending === 0) {
    [$roleRemoved, $zitadelError] = $this->syncZitadelRoleRevoke(
        $repo, $requestId, $userId, $requestedRole
    );
} else {
    $cascadeDeferred = true;
    $repo->updateZitadelSyncStatus($requestId, 'pending');
}
```

**Response shape** gains `cascade_deferred: bool` (always present):

```json
{
  "success": true,
  "role_removed": false,
  "cascade_deferred": true,
  "zitadel_error": null,
  "tuples_deleted": [],
  "fga_errors": [],
  "outbox_ids": [11, 12],
  "outbox_pending": 2,
  "outbox_failed":  0,
  "message": "Access revoked, permissions queued for deletion; role revocation deferred until 2 permission tuple(s) drain"
}
```

`revocationMessage()` gains a `bool $cascadeDeferred` parameter. New branch (when `$cascadeDeferred &&
$outboxPending > 0`):

> "Access revoked, permissions queued for deletion; role revocation deferred until N permission tuple(s) drain"

Existing branches (role removed / role retained / Zitadel failed / Zitadel not configured) are unchanged.

**Empty-permissions fast-path** (no outbox rows): unchanged. `cascade_deferred` is always `false` here.

### 5.2 `PermissionAdminHandler::revokePermission`

**Cascade candidate resolution** moves before the outbox insert (today it runs *after* the sync attempt).
This lets the metadata carry candidates whether or not the row succeeded synchronously:

```php
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
            ['exception' => $e]
        );
    }
}
```

**Outbox row metadata**:

```php
$outboxRow = [
    'operation'       => OutboxOperation::DELETE_TUPLE,
    // ...tuple fields, idempotency_key...
    'metadata' => [
        'admin_user'              => "user:{$userId}",          // existing
        'cascade_kind'            => 'permission_revoke',       // NEW — discriminator
        'cascade_user_id'         => $bareUserId,               // NEW
        'cascade_object_type'     => $objectType,               // NEW — for audit
        'cascade_role_candidates' => $cascadeRoleCandidates,    // NEW
    ],
];
```

**Cascade-call branch** replaces today's unconditional block at L631–667:

```php
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

**Response shape** gains `cascade_deferred: bool`. When deferred, `cascaded_roles: []` and the message
reads:

> "Permission revoked, queued for async deletion; role cascade deferred"

## 6. `OutboxRepository::countSiblingNonTerminalDeletes`

New repository method, ~10 lines:

```sql
SELECT COUNT(*)
FROM   openfga_outbox
WHERE  metadata->>'access_request_id' = :access_request_id
  AND  operation = 'delete_tuple'
  AND  status IN ('pending', 'retrying')
```

Returns `int`. Uses the same JSONB filter as the existing `list()` method; no new index needed.

The "non-terminal" definition deliberately excludes `failed_terminal` so the cascade fires once a row gives
up retrying. `maybeCascadeRoleRevoke`'s own FGA read then correctly declines if the failed-terminal tuple
still exists.

## 7. Wiring

### 7.1 `ConsumerLoop` and `BackstopRunner`

Both grow an optional `?CascadeReconciler $cascade` constructor parameter (default `null` keeps existing
tests working). `ConsumerLoop::tick`'s `readOnce` callback wraps `processOne` and dispatches:

```php
$this->consumer->readOnce($this->blockMs, function (int $rowId): void {
    $disposition = $this->processor->processOne($rowId);
    if ($disposition === OutboxDisposition::BENIGN_SUCCESS && $this->cascade !== null) {
        try {
            $this->cascade->evaluate($rowId);
        } catch (\Throwable $e) {
            // Never fail the consumer over a cascade decision.
        }
    }
});
```

`BackstopRunner::runOnce`'s loop gets the same treatment: capture the disposition from each `processOne`
call and invoke `cascade?->evaluate($row->id)` on BENIGN_SUCCESS.

`OutboxProcessor` and `StreamConsumerInterface` are **not** modified — the seam is the callback inside
`tick()`.

### 7.2 Construction sites

- `bin/reconcile-outbox` (backstop entrypoint): build `CascadeReconciler::fromEnv()` and pass it to
  `BackstopRunner::__construct`.
- Consumer systemd entrypoint: same — build `CascadeReconciler::fromEnv()` and pass it to `ConsumerLoop`.
- Tests: pass `null` to keep existing setup minimal; new tests pass a mock reconciler.

## 8. Test plan

### Unit

- **`CascadeReconcilerTest`** (new) — mocked `OutboxRepository` + mocked `RoleCascadeService`:
  - row missing → no-op
  - row in non-`succeeded` state → no-op
  - access-request shape, siblings still pending → no cascade call
  - access-request shape, all siblings terminal → exactly one `maybeCascadeRoleRevoke($userId, $role)`
  - permission-revoke shape, 2 candidate roles → 2 `maybeCascadeRoleRevoke` calls in candidate order
  - unknown metadata shape → no-op
  - `maybeCascadeRoleRevoke` throws → reconciler returns normally; WARNING logged

### Handler

Layer: `AbstractHandlerTestCase` per `LiturgicalCalendarAPI/CLAUDE.md`.

- **`AccessRequestAdminHandlerTest::testRevokeWithPendingOutboxDefersCascade`**: inject a processor that
  leaves the row in `pending` (e.g., FGA mock raises a retryable error). Assert response has
  `cascade_deferred: true`, `role_removed: false`, message contains the deferred phrasing, and
  `updateZitadelSyncStatus($requestId, 'pending')` was called (verifying `syncZitadelRoleRevoke` was NOT
  reached — it would write `synced` or `failed` instead).
- **`AccessRequestAdminHandlerTest::testRevokeWithCleanSyncStillCascadesSynchronously`**: regression — when
  all rows go to `succeeded` sync-path, behave exactly as today (`cascade_deferred: false`, original
  cascade ran).
- **`PermissionAdminHandlerTest::testRevokeWithDeferredRowDefersCascade`**: same pattern with a single row
  left in `pending`. Assert `cascade_deferred: true`, `cascaded_roles: []`, no Zitadel `getUserRoles` /
  `revokeUserRole` calls (use the existing Guzzle `MockHandler` pattern from `OpenFgaClientTest`).

### Reconciliation

- **`ConsumerLoopTest`** update: case with a fake `CascadeReconciler` whose `evaluate` is invoked once per
  BENIGN_SUCCESS row. Case where reconciler throws — `tick()` does not propagate.
- **`BackstopRunnerTest`** update: same shape for `runOnce`.

### Repository

- **`OutboxRepositoryTest::testCountSiblingNonTerminalDeletes`** (new, layered on `RepositoryTestCase`):
  seed three rows with the same `access_request_id`, varied statuses; assert the count matches
  expectations. Skipped when `DB_*` env unset, like other repo tests.

### Optional integration

- `@group slow` Routes-level test that exercises the full deferred path end to end (handler returns
  deferred → manually drive consumer to drain rows → assert cascade fired once). Defer to a follow-up if
  time-budget is tight.

## 9. Migration / rollout

- **No DB schema change.** Schema is via Doctrine migrations per `LiturgicalCalendarAPI/CLAUDE.md`; this
  change is JSONB-payload-only.
- **In-flight outbox rows from before this PR** (no cascade metadata): reconciler no-ops them. Operators
  can re-revoke any access request submitted during the deploy window to trigger the cascade automatically;
  otherwise the manual re-revoke path always works.
- **No new env vars.**
- **OpenAPI:** add `cascade_deferred: boolean` (required) to `AccessRequestRevokeResponse` and
  `PermissionRevokeResponse`. Run `composer lint:openapi` before commit.
- **Order of merge:** ship as one PR. The reconciler is harmless when no metadata has the new keys (no-ops);
  the handler changes are harmless when the consumer doesn't yet run the reconciler (cascade is simply
  never fired for the deferred-path rows, matching current buggy behaviour — strictly no worse).

## 10. Out of scope

- Touching `RoleCascadeService::cascadeTupleRevokeForRole` (the forward role-revoke path). Its outbox rows
  have a different metadata shape and the caller drives Zitadel revoke synchronously after — no race.
- Bulk cascade-retry endpoint. Single re-revoke is sufficient for v1.
- Telemetry / counter for "deferred cascade fired" — out of scope until the `/health` metrics framework
  lands (tracked separately in #630).
- Surfacing `cascade_deferred` rows via `/admin/outbox` listing. Operators already see pending rows via
  existing filters.
- `Retry-After` header honoring or any other rate-limiting refinement (premature until observed).

## 11. References

- Issue #632 — Deferred-delete coordination follow-ups
- PR #631 — OpenFGA async reconciliation outbox (the surface this builds on)
- Spec `2026-06-02-openfga-async-reconciliation-design.md` — the §10 "out of scope" placeholder for the
  Zitadel-cascade work; this design formalises that scope
- `src/Handlers/Admin/AccessRequestAdminHandler.php` — `revokeRequest`, `syncZitadelRoleRevoke`,
  `revocationMessage`
- `src/Handlers/Admin/PermissionAdminHandler.php` — `revokePermission`
- `src/Services/RoleCascadeService.php` — `maybeCascadeRoleRevoke` (unchanged)
- `src/Services/Outbox/ConsumerLoop.php`, `src/Services/Outbox/BackstopRunner.php` — consumer wiring
  surfaces
- `src/Repositories/OutboxRepository.php` — `list()` uses the same `metadata->>'access_request_id'` filter
  shape that `countSiblingNonTerminalDeletes` will reuse
