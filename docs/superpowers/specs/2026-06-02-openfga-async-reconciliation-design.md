# OpenFGA async reconciliation — Options B + C follow-up to issue #567

**Status:** Design approved, ready for implementation plan.
**Date:** 2026-06-02
**Author:** John R. D'Orazio (with Claude Code)
**Predecessor:** Issue [#567](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/issues/567) Option A
shipped in PR [#628](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/pull/628) — typed `OpenFgaApiException`
hierarchy + fail-fast in approve/revoke flows.
**Related issues:** [#571](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/issues/571) (ListObjects in
`filterByAdminAccess`, shipped in #628), [#630](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/issues/630)
(tracking issue for future metrics framework).

## 1. Context

PR #628 closed the most pressing failure mode in OpenFGA tuple writes/deletes: the handler no longer returns
`success: true` when an OpenFGA call fails outright (Option A). What remains, from the same issue's "Pick one (or
layer them)" framing:

- **Option B — Compensating actions.** Mutate DB first (single source of truth for "this approval has been committed"),
  then call OpenFGA. On FGA failure, attempt a compensating action.
- **Option C — Outbox / async reconciliation.** Persist the intent in an outbox table inside the DB transaction, then
  reconcile to OpenFGA asynchronously with retry. Heaviest lift, most operationally robust under partial-network
  failures.

This spec lands B and C **layered together in one PR**, because designing the contracts in isolation would either (a)
make B's compensation logic dead code once C arrives, or (b) leave C without the synchronous fast path that keeps the
common case sub-millisecond. The combined design is the smallest correct one.

### Why not B alone

Compensation requires the OpenFGA write to be reversible inside the request lifetime. A network partition that lasts
longer than the request's wall-clock budget (e.g., HTTP gateway timeouts of 30s) leaves the admin with a 5xx response
even though the DB write committed. Compensation alone trades silent under-provisioning for noisy partial-success — an
improvement, but not the durable convergence the issue asks for.

### Why not C alone

Pure-async writes (every approve returns "queued") shift the latency cost onto every admin action, even when OpenFGA
is healthy. For the 99% case where the OpenFGA call would succeed in 5ms, making admins wait for the reconciler to
drain is operationally absurd. The hybrid (sync fast-path + async fallback) is strictly better.

## 2. Goals and non-goals

### Goals

1. **Atomicity:** the business write (`access_requests.status = 'approved'`) and the "OpenFGA needs to know about
   this" intent commit in a single Postgres transaction. Either both happen or neither does.
2. **Convergence:** every DB-committed approve/revoke eventually applies in OpenFGA, even across multi-minute network
   partitions, process crashes, or Redis outages.
3. **Visibility:** admins see, in the response body and via `/admin/outbox`, the per-tuple status of each request's
   side effects.
4. **Operational legibility:** a single number (`oldest_pending_age_seconds`) tells on-call whether the system is
   reconciling.
5. **No silent failures.** Validation-error 4xx surfaces in the DLQ on first attempt; transient 5xx retries
   ~17 minutes before joining it.

### Non-goals

- **Zitadel role-sync** stays on its existing `access_requests.zitadel_sync_status` column. Migrating it into the
  outbox is a candidate future use of this pattern but is explicitly deferred (per #567's out-of-scope note).
- **Distributed transactions** across PG + OpenFGA + Zitadel are not in scope. At-least-once with idempotent
  reconciliation is what we ship.
- **Metrics framework.** Reconciliation health is surfaced via `/health` only. A real metrics layer is tracked in
  #630 and ships when more signals justify it.
- **Multi-consumer scale.** One consumer + one cron backstop is the v1 topology. The schema is laid out so adding a
  `lease_until` column later is trivial, but YAGNI for the current admin-action volume.

## 3. Architecture

Three new components, four modified components, one new DB table.

```text
┌─────────────────────────────────────────────────────────────────────────┐
│ HTTP request: PATCH /access-requests/{id}/approve                       │
└──────────────────────────────────┬──────────────────────────────────────┘
                                   ▼
       ┌──────────────────────────────────────────────────────────┐
       │ AccessRequestAdminHandler::approveRequest (MODIFIED)     │
       │  BEGIN tx                                                │
       │    repo.approve()                  ◄── DB business write │
       │    outbox.insertBatch(tuples)      ◄── outbox rows       │
       │  COMMIT                                                  │
       │  ────── fast path (sync attempt) ──────                  │
       │  foreach row: OutboxProcessor::processSync(row)          │
       │    → marks row succeeded / retrying / failed_terminal    │
       │  XADD reconcile-stream (best effort, fire-and-forget)    │
       │  return response (per-tuple state from in-memory rows)   │
       └────────────────────┬─────────────────────────────────────┘
                            │
                            ▼
       ┌──────────────────────────────────────────────────────────┐
       │ Redis Stream: litcal:reconcile-stream                    │
       │   consumer group: reconciler                             │
       └────────────────────┬─────────────────────────────────────┘
                            │ XREADGROUP BLOCK 5000
                            ▼
       ┌──────────────────────────────────────────────────────────┐
       │ bin/reconcile-outbox consumer (NEW, systemd service)     │
       │   loop:                                                  │
       │     XCLAIM stale PEL entries (idle > 30s)                │
       │     msg ← XREADGROUP                                     │
       │     OutboxProcessor::processOne(row_id)                  │
       │     XACK                                                 │
       └──────────────────────────────────────────────────────────┘

       ┌──────────────────────────────────────────────────────────┐
       │ bin/reconcile-outbox backstop (NEW, cron every 5 min)    │
       │   SELECT * FROM openfga_outbox                           │
       │     WHERE status IN ('pending', 'retrying')              │
       │       AND next_attempt_at ≤ NOW() - INTERVAL '60 seconds'│
       │     FOR UPDATE SKIP LOCKED                               │
       │   foreach row: OutboxProcessor::processOne(row_id)       │
       │   (catches "DB committed but XADD never fired")          │
       └──────────────────────────────────────────────────────────┘

       ┌──────────────────────────────────────────────────────────┐
       │ OutboxAdminHandler (NEW)                                 │
       │   GET  /admin/outbox?status=…&access_request_id=…        │
       │   GET  /admin/outbox?summary=1                           │
       │   POST /admin/outbox/{id}/retry                          │
       └──────────────────────────────────────────────────────────┘
```

### Invariants

| Invariant                               | How it's enforced                                                                                             |
| --------------------------------------- | ------------------------------------------------------------------------------------------------------------- |
| Postgres is the source of truth.        | All work derives from `openfga_outbox` rows. Redis Stream is a latency optimization.                          |
| Atomicity of business write and intent. | `BEGIN ... INSERT access_requests ... INSERT openfga_outbox ... COMMIT` is one transaction.                   |
| At-least-once application.              | Consumer's PEL + backstop's `pickupPending()` together ensure every row is eventually attempted.              |
| No double-apply.                        | `SELECT FOR UPDATE` + state machine. Terminal rows no-op when re-processed.                                   |
| Graceful degradation.                   | Redis unreachable → handler skips XADD, logs WARNING, backstop drains within 5 min. PG durability unaffected. |

## 4. Data flow — four scenarios

### Scenario A — Happy path (OpenFGA healthy, Redis healthy)

```text
t=0ms     PG BEGIN
t=0ms     repo.approve(requestId, adminId, notes)           ─┐
t=1ms     outbox.insertBatch(rows)  ── status='pending'      ├── one tx
t=2ms     PG COMMIT                                          ─┘
t=3ms     XADD reconcile-stream * row_id=… op=write_tuple …  (best effort)
t=4ms     fga.writeTuple()  →  OK
t=4ms     outbox.markSucceeded(row_id)  ── status='succeeded'
t=5ms     respond {success: true, tuples_created: [...], outbox_pending: 0}
```

The consumer wakes immediately on XREADGROUP, `SELECT FOR UPDATE`s the row, finds `status='succeeded'`, XACKs without
acting. No double-apply.

### Scenario B — Fast path fails on a transient (OpenFGA returns 503)

```text
t=0–3ms   same as Scenario A through XADD
t=4ms     fga.writeTuple()  →  OpenFgaApiException(httpStatus=503)
t=4ms     outbox.markRetryable(row_id, attempts=1, next_attempt_at=NOW()+1s, ...)
t=5ms     respond {success: true,
                   outbox_pending: 3,
                   message: "Approval recorded; 3 of 5 permissions queued for retry"}

t≈1005ms  consumer XCLAIMs the row (idle > backoff window) OR backstop wakes first
          OutboxProcessor::processOne retries fga.writeTuple() → OK
          outbox.markSucceeded()
```

Admin sees `success: true` plus an explicit deferral count. No need to retry the API call.

### Scenario C — API process dies between PG COMMIT and XADD

```text
t=0ms     PG BEGIN, business write, outbox.insertBatch
t=2ms     PG COMMIT (durable)
t=3ms     PHP-FPM worker dies — XADD never executes
          
t=300s    cron fires bin/reconcile-outbox backstop
          SELECT outbox WHERE status IN ('pending','retrying')
            AND next_attempt_at ≤ NOW() - INTERVAL '60 seconds'
          finds the orphan rows, processes them, markSucceeded
```

The admin's HTTP request hung up with a 5xx. They re-issue the request: `repo.approve()` is idempotent on
`access_requests.status`, `outbox.insertBatch()` is idempotent on `metadata->>'idempotency_key'` (§5). The re-issued
request returns the now-converged state. **No work lost. No double-apply.**

### Scenario D — OpenFGA outage longer than the retry budget

```text
t=0–4ms   same as Scenario B
[consumer + backstop alternate: 1s, 2s, 4s, 8s, ..., 512s — total ~17 min]
t≈17min   10th attempt fails — outbox.markFailedTerminal(row_id, last_error="…")
```

Admin discovers via `/admin/outbox?status=failed_terminal` or `/health`'s `failed_terminal` count. Remediation: fix
OpenFGA, then `POST /admin/outbox/{id}/retry` (resets to `pending`, attempts=0, gets a fresh 10-attempt budget).

## 5. Outbox schema and state machine

### Migration

```sql
-- src/Migrations/Version<TIMESTAMP>.php  (filename generated via `composer db:migrations:generate`)

CREATE TYPE outbox_op AS ENUM ('write_tuple', 'delete_tuple');
CREATE TYPE outbox_status AS ENUM ('pending', 'retrying', 'succeeded', 'failed_terminal');

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
);

CREATE INDEX idx_outbox_pickup ON openfga_outbox (status, next_attempt_at)
    WHERE status IN ('pending', 'retrying');

CREATE INDEX idx_outbox_dlq ON openfga_outbox (status, created_at)
    WHERE status = 'failed_terminal';

CREATE INDEX idx_outbox_metadata_request ON openfga_outbox ((metadata->>'access_request_id'))
    WHERE metadata ? 'access_request_id';
```

### Schema notes

| Choice                                       | Rationale                                                                                                                                                                                        |
| -------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `BIGSERIAL` not UUID                         | Internal ID only; smaller index, natural ordering by insert time.                                                                                                                                |
| Partial indexes on `(status, …)`             | Terminal rows are excluded from the pickup index; index stays tight forever even at high cumulative volume.                                                                                      |
| UNIQUE on `metadata->>'idempotency_key'`     | Handler-built deterministic key (`"access_request:{id}:write_tuple:{user}:{relation}:{object}"`) makes `insertBatch()` safe to call twice with `ON CONFLICT DO NOTHING`.                         |
| `last_error_code` separate from `last_error` | OpenFGA's structured `errorCode` drives retry classification; keeping it separate from the human message makes the consumer's branching legible and queryable.                                   |
| `metadata` JSONB                             | Stores `access_request_id`, `admin_user`, etc. — provenance info, not domain attributes of the operation. Future extension (e.g., Zitadel) can grow new metadata keys without schema migrations. |

### State machine

```text
                       ┌───────────────────────────────────────────┐
                       │                                           │
                       ▼                                           │
                  ┌─────────┐  fga.op() succeeds OR benign      ┌─────────────┐
   handler        │ pending │ ────────────────────────────────► │  succeeded  │
   inserts ─────► │ att=0   │                                   │ (terminal)  │
                  └────┬────┘                                   └─────────────┘
                       │
                       │ fga.op() throws OpenFgaApiException(5xx | network | 429)
                       │ → attempts++, next_attempt_at = NOW() + backoff(attempts)
                       ▼
                  ┌──────────┐
                  │ retrying │ ◄──┐
                  │ att=1..9 │    │ next attempt fires (XREADGROUP or backstop)
                  └────┬─────┘    │ → transient again
                       │──────────┘
                       │
                       │ attempts == 10 AND transient again
                       │   OR  any attempt throws validation_error 4xx
                       ▼
                  ┌─────────────────┐
                  │ failed_terminal │
                  │ (terminal)      │ ◄──── POST /admin/outbox/{id}/retry
                  └─────────────────┘       resets to pending, attempts=0
```

### Backoff schedule

```php
private static function backoffSeconds(int $attempts): int
{
    // attempts is the NEW count (just incremented), 1..10.
    // 1s, 2s, 4s, 8s, 16s, 32s, 64s, 128s, 256s, 512s — ~17 min total budget.
    return 1 << min($attempts - 1, 9);
}
```

### Classification table

`OutboxClassifier::classify(\Throwable $e): OutboxDisposition` returns one of `BENIGN_SUCCESS`, `RETRY`, `TERMINAL`:

| Signal                                                                                      | Disposition            |
| ------------------------------------------------------------------------------------------- | ---------------------- |
| `TupleAlreadyExistsException`                                                               | `BENIGN_SUCCESS`       |
| `TupleNotFoundException`                                                                    | `BENIGN_SUCCESS`       |
| `errorCode IN (validation_error, invalid_input_format, type_not_found, relation_not_found)` | `TERMINAL`             |
| `errorCode IN (auth_failure, unauthenticated)`                                              | `TERMINAL`             |
| `httpStatus == 429`                                                                         | `RETRY`                |
| `httpStatus >= 500`                                                                         | `RETRY`                |
| Network error (no httpStatus)                                                               | `RETRY`                |
| Anything else                                                                               | `RETRY` (safe default) |

## 6. Components

### New files

| Path                                                                                      | Purpose                                                                                                                                                                                                                                                         |
| ----------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `src/Migrations/Version<TIMESTAMP>.php` (generated via `composer db:migrations:generate`) | Creates `openfga_outbox` table, enums, partial indexes.                                                                                                                                                                                                         |
| `src/Repositories/OutboxRepository.php`                                                   | `insertBatch`, `markSucceeded`, `markRetryable`, `markFailedTerminal`, `pickupPending`, `getById`, `countByStatus`, `resetForRetry`.                                                                                                                            |
| `src/Services/Outbox/OutboxClassifier.php`                                                | Stateless: `classify(\Throwable $e): OutboxDisposition`.                                                                                                                                                                                                        |
| `src/Services/Outbox/OutboxDisposition.php`                                               | Enum: `BENIGN_SUCCESS`, `RETRY`, `TERMINAL`.                                                                                                                                                                                                                    |
| `src/Services/Outbox/OutboxProcessor.php`                                                 | Shared processing logic. Takes one outbox row, attempts OpenFGA op, dispatches to classifier, updates row, returns disposition. Called by both fast-path inline attempt and consumer/backstop. **Single point of OpenFGA contact** for request and async paths. |
| `src/Services/Outbox/OutboxNotifier.php`                                                  | Wraps `\Redis::xAdd()`. `notify(int $outboxId, string $operation): void` — best-effort, never throws to caller. Reuses `Health.php` connection pattern.                                                                                                         |
| `src/Services/Outbox/RedisStreamConsumer.php`                                             | Reusable loop: `consume(callable $process, int $blockMs = 5000): void`. XREADGROUP, XCLAIM stale PEL entries, XACK. Pure infrastructure.                                                                                                                        |
| `src/Services/Outbox/ConsumerLoop.php`                                                    | Long-lived loop body invoked by the consumer binary. `tick()` does one read+process cycle (the testable unit); `run()` is the outer `while (true)`.                                                                                                             |
| `src/Services/Outbox/BackstopRunner.php`                                                  | One-shot scan invoked by the backstop binary. `runOnce()` does the FOR UPDATE SKIP LOCKED + foreach process cycle and exits.                                                                                                                                    |
| `bin/reconcile-outbox`                                                                    | CLI entry point. Subcommands `consumer` and `backstop`. Thin wrapper over `ConsumerLoop::run()` and `BackstopRunner::runOnce()`.                                                                                                                                |
| `src/Handlers/Admin/OutboxAdminHandler.php`                                               | `GET /admin/outbox?status=…&access_request_id=…&summary=1`, `POST /admin/outbox/{id}/retry`.                                                                                                                                                                    |
| `deploy/systemd/liturgical-calendar-reconciler.service`                                   | Example unit: `ExecStart=php /path/bin/reconcile-outbox consumer`, `Restart=on-failure`, `RestartSec=5`, runs as app user.                                                                                                                                      |
| `deploy/cron/liturgical-calendar-backstop.cron`                                           | Example cron: `*/5 * * * * appuser php /path/bin/reconcile-outbox backstop`.                                                                                                                                                                                    |
| `docs/ops/openfga-outbox-runbook.md`                                                      | Operator runbook: install systemd unit, install cron, diagnostic SQL, retention guidance.                                                                                                                                                                       |
| `phpunit_tests/Repositories/OutboxRepositoryTest.php`                                     | Insert dedup, pickup ordering + SKIP LOCKED, status transitions, terminal stickiness.                                                                                                                                                                           |
| `phpunit_tests/Services/Outbox/OutboxClassifierTest.php`                                  | One test per classification table row.                                                                                                                                                                                                                          |
| `phpunit_tests/Services/Outbox/OutboxProcessorTest.php`                                   | Happy path, transient retry with correct backoff, terminal-on-validation, benign already-exists/not-found, 10th attempt → terminal, no-op on already-terminal rows.                                                                                             |
| `phpunit_tests/Services/Outbox/BackstopRunnerTest.php`                                    | `runOnce()` picks up only rows past the grace window, processes them via OutboxProcessor.                                                                                                                                                                       |
| `phpunit_tests/Services/Outbox/ConsumerLoopTest.php`                                      | `tick()` against a mocked Redis: reads, processes, acks. Real-Redis variant gated behind `@group needs-redis`.                                                                                                                                                  |
| `phpunit_tests/Handlers/Admin/OutboxAdminHandlerTest.php`                                 | List/summary/retry endpoints, auth guards.                                                                                                                                                                                                                      |

### Modified files

| Path                                               | Changes                                                                                                                                                                                                                                                                                                                                                                                                     |
| -------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `src/Handlers/Admin/AccessRequestAdminHandler.php` | `approveRequest()` and `revokeRequest()` switch to the outbox pattern. PG tx wraps `repo.approve/revoke` + `outbox.insertBatch`. After commit, loop calls `OutboxProcessor::processSync()` per row, then XADD via `OutboxNotifier`. Response body grows `outbox_pending`, `outbox_failed`, `outbox_ids`. `fga_errors` preserved for back-compat. Constructor gains `?OutboxRepository` + `?OutboxNotifier`. |
| `src/Handlers/Admin/PermissionAdminHandler.php`    | `grantPermission()` and `revokePermission()` get the same treatment — per #567's note that typed-error work was a prerequisite for this handler.                                                                                                                                                                                                                                                            |
| `src/Services/RoleCascadeService.php`              | The silent best-effort `try/catch` around `deleteTuple` (currently logs WARNING and moves on) replaces its catch with an outbox enqueue on transient failures. Cascade caller stays synchronous; only the per-tuple failure path goes async.                                                                                                                                                                |
| `src/Router.php`                                   | Two new routes behind existing admin JWT middleware: `GET /admin/outbox`, `POST /admin/outbox/{id}/retry`.                                                                                                                                                                                                                                                                                                  |
| `src/Health.php`                                   | Adds `openfga_outbox` block: `pending`, `retrying`, `failed_terminal`, `oldest_pending_age_seconds`, and a `consumer` sub-block with `redis_reachable`, `pending_entries`, `oldest_pel_idle_seconds`.                                                                                                                                                                                                       |
| `.env.example`                                     | New keys: `REDIS_OUTBOX_STREAM=litcal:reconcile-stream`, `REDIS_OUTBOX_GROUP=reconciler`, `REDIS_OUTBOX_CONSUMER_NAME=` (default = hostname), `OUTBOX_MAX_ATTEMPTS=10`, `OUTBOX_BACKSTOP_GRACE_SECONDS=60`.                                                                                                                                                                                                 |
| `composer.json`                                    | New scripts: `reconciler:consumer`, `reconciler:backstop` for local dev parity with systemd/cron.                                                                                                                                                                                                                                                                                                           |

### Notable non-changes

- **`OpenFgaClient` is untouched.** Typed exceptions from #628 are consumed, not extended.
- **No new dependencies.** `ext-redis`, `ext-pdo_pgsql`, Doctrine migrations — all already present.
- **No changes to Zitadel sync.** Out of scope per #567.

### Response shape

Approve/revoke and permission grant/revoke handlers return:

```json
{
  "success": true,
  "role_assigned": true,
  "zitadel_error": null,
  "tuples_created": [{ "user": "...", "relation": "...", "object": "..." }],
  "outbox_pending": 0,
  "outbox_failed": 0,
  "outbox_ids": [42, 43, 44],
  "fga_errors": [],
  "message": "Access request approved; permissions granted."
}
```

`success` is `true` whenever the DB write committed, regardless of OpenFGA status. The `outbox_*` fields tell the
admin what happened to the side effects. This is a deliberate semantic shift from #628: with the outbox in place, the
DB write IS the commitment, and OpenFGA application is a tracked side effect.

When `outbox_pending > 0`, the message becomes `"… N of M permissions queued for retry (OpenFGA was temporarily
unavailable)."`. When `outbox_failed > 0`, additionally `"… N permissions require admin review:
/admin/outbox?status=failed_terminal."`.

## 7. Error handling — the edges

| Failure                                                     | Behavior                                                                                                                                                | Safety argument                                                                                                                        |
| ----------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------- |
| Redis unreachable at XADD                                   | `OutboxNotifier` catches `\RedisException`, logs WARNING, returns void.                                                                                 | Outbox row is durable in PG; backstop drains within 5 min.                                                                             |
| Redis unreachable in consumer                               | Consumer logs ERROR, exits 1; systemd cycles.                                                                                                           | Backstop continues to drain via PG.                                                                                                    |
| Consumer group does not exist                               | `XGROUP CREATE litcal:reconcile-stream reconciler $ MKSTREAM` on startup; BUSYGROUP ignored.                                                            | Idempotent setup; no manual pre-step.                                                                                                  |
| PG fault between business write and outbox insert           | Whole `BEGIN ... COMMIT` rolls back.                                                                                                                    | Atomicity is the contract.                                                                                                             |
| PG unreachable in consumer/backstop                         | Logs ERROR, exits 1; systemd cycles.                                                                                                                    | Handlers commit DB + return responses unaffected; reconciliation stalls until PG returns. State machine resumes from current `status`. |
| Consumer crashes mid-process (after PG update, before XACK) | Next pass XCLAIMs the message; `processOne` sees row in terminal state, no-ops, XACKs.                                                                  | State machine is the idempotency anchor.                                                                                               |
| Consumer crashes between OpenFGA call and PG update         | Next pass retries OpenFGA op. Write → `TupleAlreadyExistsException` (benign). Delete → `TupleNotFoundException` (benign).                               | This is exactly what #567's typed exceptions enable.                                                                                   |
| Two runners pick the same row simultaneously                | `SELECT FOR UPDATE` serializes; second runner re-checks status, no-ops if terminal.                                                                     | PG row lock is the serialization point. Backstop's 60s grace window makes the race vanishingly rare.                                   |
| Validation error 4xx from OpenFGA                           | `OutboxClassifier` returns `TERMINAL`; row marked `failed_terminal` on first attempt.                                                                   | Retrying 4xx 9 more times wastes work and pollutes metrics.                                                                            |
| OpenFGA returns 429                                         | Classified as RETRY; backoff schedule self-throttles.                                                                                                   | If sustained, revisit (honor `Retry-After`). Not premature in v1.                                                                      |
| Idempotency-key collision on retry                          | `INSERT ... ON CONFLICT (metadata->>'idempotency_key') DO NOTHING RETURNING id`; for duplicate keys, `insertBatch` falls back to `SELECT id WHERE ...`. | Makes the handler safe to re-invoke.                                                                                                   |
| Admin retries a non-failed row                              | 409 Conflict. Only `failed_terminal` is retryable via the admin endpoint.                                                                               | Avoids counter desync with in-flight processing.                                                                                       |
| Stale PEL entries from dead consumer                        | `XCLAIM` (or `XAUTOCLAIM`) reassigns messages idle > 30s on each loop iteration.                                                                        | At-least-once guarantee depends on this.                                                                                               |

## 8. Observability

### `/health` adds an `openfga_outbox` block

```json
{
  "openfga_outbox": {
    "pending": 0,
    "retrying": 0,
    "failed_terminal": 0,
    "oldest_pending_age_seconds": 0,
    "consumer": {
      "redis_reachable": true,
      "stream_name": "litcal:reconcile-stream",
      "group_name": "reconciler",
      "pending_entries": 0,
      "oldest_pel_idle_seconds": 0
    }
  }
}
```

The two operational signals that matter most:

- `oldest_pending_age_seconds` — sustained growth past ~60s means the consumer is wedged. Single number to alert on.
- `oldest_pel_idle_seconds` — sustained growth past ~30s means the consumer is alive but not XACK'ing (different
  failure mode).

### `/admin/outbox`

| Query                           | Returns                                                                                       |
| ------------------------------- | --------------------------------------------------------------------------------------------- |
| `?status=failed_terminal`       | DLQ list with `last_error`/`last_error_code`.                                                 |
| `?access_request_id={uuid}`     | All rows for one approve/revoke — the operationally important one.                            |
| `?summary=1`                    | Counts per status + oldest age. Same data as `/health` for ops without health-scraping.       |
| `POST /admin/outbox/{id}/retry` | Resets one `failed_terminal` row to `pending`, attempts=0. Returns 409 for non-terminal rows. |

Pagination follows the existing access-requests pattern (limit/offset, default 50, max 200).

### Logging discipline

| Level   | Site                                       | Shape                                                                           |
| ------- | ------------------------------------------ | ------------------------------------------------------------------------------- |
| INFO    | OutboxProcessor succeeded a row            | `outbox.row.succeeded id=42 op=write_tuple user=… attempts=1`                   |
| INFO    | OutboxProcessor marked terminal            | `outbox.row.failed_terminal id=42 attempts=10 last_error_code=validation_error` |
| WARNING | OutboxNotifier XADD failed                 | `outbox.redis.notify_failed row_ids=[42,43] error="…"`                          |
| WARNING | OutboxProcessor scheduled retry            | `outbox.row.retry_scheduled id=42 attempts=3 next_attempt_at=… error_code=…`    |
| WARNING | RedisStreamConsumer XCLAIM'd stale message | `outbox.consumer.xclaim id=42 idle_ms=45000 from_consumer=…`                    |
| ERROR   | RedisStreamConsumer lost Redis             | `outbox.consumer.redis_lost error="…" — exiting for systemd restart`            |
| ERROR   | OutboxProcessor couldn't update PG row     | `outbox.processor.pg_update_failed id=42 error="…"`                             |

Uses the existing `LoggerFactory` from `src/Http/Logs/`.

### Audit trail

The `openfga_outbox` table is the durable record of every tuple grant/revoke that ever passed through the API.
Substantial improvement over today's scattered log lines. Retention defaults to "keep everything" until the table
becomes a real concern; pruning SQL is documented in the runbook (a one-shot `DELETE FROM openfga_outbox WHERE
status = 'succeeded' AND completed_at < NOW() - INTERVAL '30 days'`). Automated pruning, if needed, ships as a separate
append-only migration — never folded into `init-db.sql`.

## 9. Testing strategy

Five testing layers. The codebase already has the infrastructure for all of them.

### Layer 1 — Pure logic

`OutboxClassifierTest` (one test per classification row) and a parametric `backoffSeconds()` test. Fast, deterministic.

### Layer 2 — Repository (PG only)

`OutboxRepositoryTest` uses the existing test-DB-with-rollback fixture pattern from `AccessRequestRepositoryTest`. The
load-bearing test: `testPickupPendingHonorsForUpdateSkipLocked()` — two concurrent transactions must get distinct
rows.

### Layer 3 — Processor (PG + mocked OpenFGA)

`OutboxProcessorTest` uses `MockHandler`-backed `OpenFgaClient` (the pattern from `OpenFgaClientTest` established
in PR #628). Load-bearing test: `testProcessSync10thAttemptOnTransientMarksFailedTerminal()` pins the retry budget.

### Layer 4 — Handler integration (PG + mocked OpenFGA + mocked Redis)

Additions to `AccessRequestAdminHandlerTest` and `PermissionAdminHandlerTest`. Load-bearing tests:

- `testApproveCommitsAtomicallyOrNotAtAll()` — PG fault between `repo.approve` and `outbox.insertBatch` rolls back
  both.
- `testApproveIsIdempotentOnReissue()` — same request twice → same outbox row IDs, no duplicate rows.
- `testApproveSucceedsEvenWhenRedisNotifyFails()` — `OutboxNotifier` throwing does not affect the response.

### Layer 5 — Runner classes (BackstopRunner, ConsumerLoop)

- `BackstopRunnerTest::runOnce()` against a real PG: only rows past the grace window are picked up and processed.
- `ConsumerLoopTest::tick()` against a mocked Redis (default) and a real Redis (`@group needs-redis`, opt-in).

The binaries' outer `while (true)` and CLI arg parsing are excluded from coverage and trusted to be obvious.

### Not tested

- systemd unit behavior (Restart, RestartSec) — operational, manually verified.
- cron-driven invocation — same.
- XADD-to-process latency — not a correctness concern; add only if we adopt a sub-second-reconciliation SLO.

### Quality gates (same as #628)

- `composer test` clean.
- `composer analyse` (PHPStan L10) clean.
- `composer lint` (phpcs PSR-12) clean.
- `composer parallel-lint` clean.
- `composer db:migrate` applies cleanly in CI.

## 10. Out of scope / future work

| Item                                          | Why deferred                                                                                                                               |
| --------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------ |
| Zitadel role-sync moves into the outbox       | Out of scope per #567. Pattern is designed to extend (op enum + metadata JSONB) but Zitadel has its own `zitadel_sync_status` story today. |
| Automated pruning of `succeeded` rows         | Premature optimization. Runbook documents the SQL; ship automation only when table size becomes a real concern.                            |
| Bulk admin retry (`/retry-all`)               | YAGNI. Single-row retry covers v1. Admins can script bulk via `GET /admin/outbox?status=failed_terminal` + per-row POST.                   |
| `lease_until` column for multi-consumer scale | YAGNI. One consumer + one backstop is sufficient for admin-action volume. Trivial to add later.                                            |
| Metrics framework (Prometheus / StatsD)       | Tracked in #630. Separate design pass when more signals justify it.                                                                        |
| `Retry-After` header honoring for 429s        | Premature optimization until we observe sustained 429s. Exponential backoff already self-throttles.                                        |
| Frontend UI for the DLQ                       | JSON endpoint suffices for v1 ops. Cosmetic; separate concern in `LiturgicalCalendarFrontend`.                                             |

## 11. References

- Predecessor PR: [#628](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/pull/628) — typed exceptions +
  ListObjects.
- Originating issue: [#567](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/issues/567) — Options A/B/C
  framing.
- Sibling issue: [#571](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/issues/571) — ListObjects in
  `filterByAdminAccess` (shipped in #628).
- Tracking issue: [#630](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/issues/630) — future metrics
  framework.
- Original CR thread: [PR #555 discussion_r3197872349](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/pull/555#discussion_r3197872349).
- CLAUDE.md schema-authority rule: migrations are append-only, never folded into `init-db.sql`.
