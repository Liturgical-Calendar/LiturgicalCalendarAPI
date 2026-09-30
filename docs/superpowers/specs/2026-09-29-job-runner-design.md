# One job runner for recurring and background jobs (#1008)

**Status:** Design approved, ready for implementation plan.
**Date:** 2026-09-29
**Author:** John R. D'Orazio (with Claude Code)
**Issue:** [#1008](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/issues/1008)
**Depends on:** [#1015](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/issues/1015) (empty-tree guard and
real dry run for `reconcile-resource-tuples`), which must land first.
**Related, out of scope:** [#1013](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/issues/1013) (outbox
consumer never retries), [#1014](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/issues/1014) (consumer
reads outbox rows without a lock).

## 1. Context

Background work runs today as two long-lived Redis Streams consumers under their own systemd units, plus four cron
lines. On staging (`api/dev` on catholicdigitalcommons.org, the only instance that runs jobs; `v4` and `v5` run none),
as surveyed on 2026-09-29:

| Job                               | Runs as                                                           | Installed by                        |
|-----------------------------------|-------------------------------------------------------------------|-------------------------------------|
| Outbox consumer                   | `liturgical-calendar-reconciler.service`, user `johnromandorazio` | hand-copied, per the outbox runbook |
| Publish consumer (+ merge poll)   | `litcal-publish-consumer.service`, user `johnromandorazio`        | `deploy/install.sh`                 |
| Outbox backstop, `*/5`            | `ubuntu` crontab                                                  | by hand                             |
| Publish backstop, `*/5`           | `ubuntu` crontab                                                  | by hand (change-request runbook)    |
| Merge poll backstop, `*/5`        | `ubuntu` crontab                                                  | by hand (change-request runbook)    |
| Resource-tuple sweep, daily 03:00 | `ubuntu` crontab                                                  | by hand (RBAC runbook Step 7)       |
| Wider region membership reconcile | not scheduled; run by hand                                        | —                                   |

Problems this design removes:

- **Jobs that exist only as runbook cron lines** are easy to miss on a new or rebuilt server, and nothing reports that
  one is not installed or not running.
- **Scheduling is re-implemented per consumer.** `PublishConsumerLoop` keeps in-memory timestamps to rate-limit a merge
  poll and a publish recovery run on its idle tick.
- **Nothing handles signals.** Both loops are `while (true)`, stopped only by systemd's kill.
- **Nothing prevents two copies of a job running at once**, beyond the row-level locking inside each job.
- **Deploys never restart the consumers.** `litcal-fpm-reload.sh` restarts only the WebSocket unit, so on 2026-09-29 both
  consumers were still running code from before that day's 15:18 deploy.
- **Crons and units run as different users** (`ubuntu` vs `johnromandorazio`), sharing the app's `logs/` directory and,
  for publishing, the GitHub App token cache.
- **#1005 needed a periodic membership reconcile** and left it manual rather than add another cron line.

## 2. Goals and non-goals

Goals, all four chosen together:

1. **Nothing silently not running.** Every job's last run, last success and next due time are visible in `/health`,
   and a job that never runs shows as overdue.
2. **One scheduling mechanism.** Adding a recurring job means registering it, not writing a loop or a cron line.
3. **Fewer moving parts on the server.** One systemd unit replaces two units and four cron lines.
4. **Isolation.** A hung GitHub publish must not delay OpenFGA writes.

Non-goals:

- **Fixing #1013 and #1014.** They are filed separately. This design does not change `OutboxProcessor`, `BackstopRunner`,
  `PublishRunner` or `MergePollRunner`; it only changes how they are started.
- **Clock-time schedules.** Jobs run on intervals only (§4.3). Safety of destructive jobs comes from their guards, not
  from running at a quiet hour.
- **Active-active runners across hosts.** One supervisor runs at a time (§5.3). Several hosts may run the unit; only one
  holds the supervisor lease.
- **Retiring `scripts/*.php` and `bin/reconcile-outbox`.** They stay as manual tools. Retiring them, or making them
  delegate to `bin/litcal-jobs run`, is a follow-up.
- **A metrics framework** (#630). `/health` reports state; it does not export time series.
- **Trimming the Redis streams.** Unchanged, still unbounded; a follow-up if needed.

## 3. Decisions

| Question (from #1008)            | Decision                                                                                                     |
|----------------------------------|--------------------------------------------------------------------------------------------------------------|
| One process or several?          | One unit running a supervisor that starts each job as a child process.                                       |
| Where schedule state lives       | Postgres, table `job_schedule`. Redis stays an accelerator, never a dependency (`RedisConnection` docblock). |
| Adopt a job library?             | No. Intervals need no parser; the supervisor is in-house and small. No new Composer dependency.              |
| Migration order                  | Backstops, then consumers, then the destructive sweeps (§8).                                                 |
| Safety rule for destructive jobs | A preflight guard, a shared empty-tree guard, and a real dry run (§7).                                       |

Why not the alternatives:

- **One process running jobs in turn** gives up isolation: PHP runs one thing at a time, so a GitHub call waiting on a
  timeout would hold up the outbox consumer.
- **Separate units with a shared scheduling library** keeps isolation but keeps several units, and still leaves each
  unit to be installed and restarted separately.
- **Redis for schedule state** would make every recurring job, including the backstops that exist to cover a Redis
  outage, stop during a Redis outage.

## 4. Architecture

### 4.1 Entry point

`bin/litcal-jobs`, a CLI script with the same bootstrap as `bin/publish-sourcedata-consumer` (CLI-SAPI guard, Dotenv
chain, logger). Three modes:

| Mode                    | Purpose                                                                                            |
|-------------------------|----------------------------------------------------------------------------------------------------|
| `supervise`             | The long-running parent. Run only by `litcal-jobs.service`.                                        |
| `run <job> [--dry-run]` | Runs one job once, in the foreground. The supervisor starts every child this way; so do operators. |
| `status`                | Prints every registered job's row, including `last_error`.                                         |

Exit codes, shared by all modes:

| Code | Meaning                                                                           |
|------|-----------------------------------------------------------------------------------|
| 0    | Success.                                                                          |
| 1    | The job failed (an exception, or a runner that reports it stopped on a failure).  |
| 2    | Usage or configuration error: unknown job, missing `pcntl`/`posix`, no database.  |
| 3    | A destructive job's preflight refused (§7).                                       |
| 75   | Lease not acquired: another process holds the job. Not a failure (`EX_TEMPFAIL`). |

### 4.2 Components

New code lives in `src/Services/Jobs/`, plus one repository.

| Unit                         | Responsibility                                                                                      |
|------------------------------|-----------------------------------------------------------------------------------------------------|
| `Job` (interface)            | `run(JobContext $ctx): void`. Throws on failure. Stream jobs return only when `$ctx->shouldStop()`. |
| `DestructiveJob` (interface) | Extends `Job` with `preflight(): ?string`, returning a refusal reason or `null` (§7).               |
| `JobContext`                 | `dryRun`, `shouldStop(): bool`, a logger.                                                           |
| `JobKind` (enum)             | `STREAM`, `INTERVAL`.                                                                               |
| `JobDefinition`              | Readonly: name, kind, interval seconds, timeout seconds, a factory `callable(): Job`.               |
| `JobRegistry`                | The list of definitions. Rejects duplicate names and the reserved name `supervisor`.                |
| `JobScheduleRepository`      | All SQL on `job_schedule` (§5). Lives in `src/Repositories/`.                                       |
| `JobRunner`                  | `run` mode: lease, signal handler, preflight, the job, recording the outcome (§5.2).                |
| `Supervisor`                 | `supervise` mode: its own lease, starting, timing out and reaping children (§6).                    |
| `JobsHealth`                 | Builds the `jobs` block of `/health` (§9).                                                          |
| `SourceTreeGuard`            | The shared empty-tree check used by destructive preflights (§7).                                    |

A job's factory builds its dependencies (PDO, OpenFGA client, Redis, GitHub publisher) inside the child, so the
supervisor itself needs only Postgres. A misconfigured job fails its own runs, visibly, without stopping the others.

### 4.3 The registry

| Job                       | Kind     | Interval | Timeout | Wraps                                                                    |
|---------------------------|----------|----------|---------|--------------------------------------------------------------------------|
| `outbox-consumer`         | stream   | —        | —       | `ConsumerLoop` (as `bin/reconcile-outbox consumer` builds it)            |
| `publish-consumer`        | stream   | —        | —       | `PublishConsumerLoop`, without its idle ticks (§4.4)                     |
| `outbox-backstop`         | interval | 300 s    | 240 s   | `BackstopRunner::runOnce(200)`                                           |
| `publish-backstop`        | interval | 60 s     | 900 s   | `PublishRunner::runOnce(10)`                                             |
| `merge-poll`              | interval | 60 s     | 300 s   | `MergePollRunner::runOnce()`                                             |
| `wider-region-membership` | interval | 86400 s  | 900 s   | `WiderRegionMembershipSeeder::reconcile(..., apply: !dryRun)`            |
| `resource-tuple-sweep`    | interval | 86400 s  | 1800 s  | `ResourceTuplePurgeReconciler::sweep()` (with #1015's guard and dry run) |

The last two are `DestructiveJob`s.

Intervals are measured from when a run finishes (§5.2), so a run that overruns its interval never causes the next runs
to pile up. `publish-backstop` and `merge-poll` at 60 s reproduce what `PublishConsumerLoop`'s idle tick does today;
`PublishBackoff` stays keyed to its own `BASE_SECONDS`, since a batch's backoff is stored on the row, not on the
schedule.

Each wrapper passes the same environment-derived settings its script passes today (`OUTBOX_MAX_ATTEMPTS`,
`OUTBOX_BACKSTOP_GRACE_SECONDS`, the stream, group and consumer-name variables), and keeps `umask(0o077)` around the
publish and merge jobs, as their scripts do, to protect the GitHub App token cache.

### 4.4 Changes to the existing loops

- `ConsumerLoop::run()` and `PublishConsumerLoop::run()` take a `callable(): bool $shouldStop` and loop
  `while (!$shouldStop())`. The 5 s blocking read bounds how long a stop takes.
- `PublishConsumerLoop` loses `runPublishRecoveryIfDue()`, `pollMergesIfDue()`, `$lastRecoveryTickAt`,
  `$lastMergePollAt`, and the two interval constructor parameters. It only consumes. Its `MergePollRunner` dependency
  goes with them.
- `bin/publish-sourcedata-consumer` and `bin/reconcile-outbox consumer` keep working for manual use, passing a stop
  callback that never fires.

## 5. Schedule state

### 5.1 Table

Created by a Doctrine migration in `src/Migrations/`.

| Column                 | Type          | Notes                                                                   |
|------------------------|---------------|-------------------------------------------------------------------------|
| `name`                 | `TEXT` PK     | The registry name, or the reserved `supervisor`.                        |
| `next_due_at`          | `TIMESTAMPTZ` | Not null.                                                               |
| `lease_owner`          | `TEXT`        | `host:pid` of the holder, or null.                                      |
| `lease_until`          | `TIMESTAMPTZ` | Null when free.                                                         |
| `last_started_at`      | `TIMESTAMPTZ` |                                                                         |
| `last_finished_at`     | `TIMESTAMPTZ` |                                                                         |
| `last_success_at`      | `TIMESTAMPTZ` |                                                                         |
| `last_status`          | `TEXT`        | `succeeded`, `failed`, `timed_out`, `refused`; `CHECK` constrained.     |
| `last_error`           | `TEXT`        | Truncated to 2000 characters.                                           |
| `last_duration_ms`     | `INTEGER`     |                                                                         |
| `consecutive_failures` | `INTEGER`     | Not null, default 0. Reset by a success. `refused` counts as a failure. |

**Every time comparison uses the database clock** (`now()` in SQL), never PHP's, so two hosts with skewed clocks agree
about who holds a lease and what is due.

At startup the supervisor upserts a row for each registered job, due immediately
(`INSERT … ON CONFLICT (name) DO NOTHING`), so existing rows keep their history. Rows for names no longer registered are
left alone and ignored by `/health` and `status`.

### 5.2 Leases

`JobRunner` (the `run` mode) takes the lease itself, so a supervisor-started child and a manual run behave identically.

- **Acquire:**

  ```sql
  UPDATE job_schedule
     SET lease_owner = :me, lease_until = now() + make_interval(secs => :ttl), last_started_at = now()
   WHERE name = :job AND (lease_until IS NULL OR lease_until < now())
  RETURNING name
  ```

  No row means another process holds the job: exit 75, record nothing.
- **Lease length:**
  - interval jobs: timeout + 30 s, never renewed, because the supervisor kills a child at its timeout;
  - stream jobs: 60 s, renewed every 15 s by a `SIGALRM` timer inside the child (`pcntl_async_signals`). The renewal is
    the job's heartbeat. A renewal that finds the lease taken by someone else sets the stop flag.
- **Finish:** one `UPDATE … WHERE name = :job AND lease_owner = :me` that clears the lease and sets `last_finished_at`,
  `last_status`, `last_error`, `last_duration_ms`, `consecutive_failures`, `last_success_at` on success, and
  `next_due_at = now() + interval` for interval jobs. If it updates no row, the lease was lost; log a warning.
- **Manual runs:** `run <job>` takes the lease and records its outcome like any run. `run <job> --dry-run` takes no lease
  and records nothing; it is refused (exit 2) for stream jobs.

## 6. The supervisor

### 6.1 Loop

`supervise` refuses to start (exit 2) without `pcntl` and `posix`, since staging has two PHP binaries. It then takes the
`supervisor` lease (60 s, renewed every 15 s). While another supervisor holds it, this one waits as a standby: it logs
once, retries the acquire every 15 s, starts nothing, and takes over when the lease lapses. A standby never exits on
its own, so a second host's unit does not crash-loop. Once it holds the lease, each pass, about once a second:

1. Renew its own lease, when 15 s have passed since the last renewal.
   - If the renewal errors (the database is unreachable), keep the children running and retry next pass. A brief
     database outage must not stop the consumers.
   - If the renewal updates no row (the lease lapsed and another supervisor took it), stop all children as on
     shutdown (§6.2) and return to standby. A takeover can overlap briefly after a long outage; job leases still stop
     two copies of one job, and the stream jobs' own lease renewals stop the losing side's consumers within 15 s.
2. Reap finished children with `proc_get_status`.
3. For each running interval child past its timeout: SIGTERM, then SIGKILL 10 s later, and record `timed_out` with the
   child's owner id (the child cannot record after SIGKILL).
4. For each stream job with no running child: start one, unless it is in backoff. Backoff after an exit doubles from
   1 s to 60 s, and resets once a child has run for 5 minutes.
5. For each interval job not running locally: start a child if `next_due_at <= now()` and the lease is free. One
   indexed `SELECT` answers this for all jobs.

Children are started with `proc_open([PHP_BINARY, 'bin/litcal-jobs', 'run', $name])`, inheriting stdout and stderr, so
their output reaches the unit's journal. Jobs listed in `LITCAL_JOBS_DISABLED` (comma-separated names) are never
started; unknown names in that list are logged once at startup.

### 6.2 Signals and shutdown

- **Supervisor**, on SIGTERM or SIGINT: stop starting children, send SIGTERM to every child, wait up to 30 s, SIGKILL
  what is left and record each as `failed` ("killed at shutdown"), release its lease, exit 0.
- **Child** (`run` mode), on SIGTERM: set the stop flag. A stream job returns after the message in hand; an interval job
  finishes its pass. The work is already transactional: the outbox backstop's transaction rolls back if it is killed,
  and an interrupted publish leaves a claim that `PublishRunner` reclaims after its 1800 s grace, as today.
- **Unit:** `KillMode=mixed`, so systemd signals only the supervisor and SIGKILLs everything only at the end;
  `TimeoutStopSec=45`, above the supervisor's 30 s wait.

### 6.3 Why one supervisor at a time

The `supervisor` lease makes a second supervisor, on another host or started by hand next to the unit, wait as a
standby instead of double-scheduling, and take over if the first one dies. Job leases would already prevent two copies of one job; the supervisor lease also keeps stream jobs
from bouncing between two supervisors, and gives `/health` the supervisor's heartbeat.

## 7. Destructive jobs

A job is destructive when a run can remove access or data. Both sweeps are. The runner enforces:

1. **Preflight before any write.** `JobRunner` calls `preflight()` before `run()`. A non-null reason is recorded as
   `refused` with the reason in `last_error`, `run` exits 3, and the job waits its full interval before trying again.
   Preflight also runs under `--dry-run`, so an operator sees a refusal before applying.
2. **A shared empty-tree guard.** `SourceTreeGuard::refusalReason(): ?string` refuses when the national calendars folder
   holds no national calendar file. It is lifted from `WiderRegionMembershipSeeder::reconcile()`, which keeps calling
   it, and #1015 applies it to the tuple sweep.
3. **A real dry run.** `--dry-run` lists what the job would change and changes nothing. The seeder already does this;
   #1015 gives the tuple sweep one.

The registry rejects a destructive job whose class does not implement `DestructiveJob`. There is no blast-radius cap in
this design; #1015 may add one to the tuple sweep.

## 8. Deploy and migration

### 8.1 Deploy files

- `deploy/systemd/litcal-jobs.service.in`: `WorkingDirectory=@API_ROOT@`, `User=@RUN_USER@`, `Group=@RUN_GROUP@`,
  `ExecStart=@PHP_BIN@ bin/litcal-jobs supervise`, `Restart=on-failure`, `RestartSec=5`, the `StartLimit*`,
  `KillMode` and `TimeoutStopSec` settings above, and the same hardening as `litcal-publish-consumer.service.in`.
- `deploy/install.sh` installs it when `JOBS_UNIT` is set in `/etc/litcal-deploy.env`, as it does for the publish
  consumer.
- `litcal-fpm-reload.sh` restarts a list of units after an API deploy, `WS_UNIT` plus `JOBS_UNIT` when set, applying its
  existing `NRestarts` crash-loop check to each. A unit that is not configured is skipped.

### 8.2 Rollout on staging

All the code ships at once; the rollout is staged with `LITCAL_JOBS_DISABLED`. At each step the new job starts before
the old piece is removed, except the consumer swap, whose few-second gap the backstops cover by design.

1. Install `litcal-jobs.service` with everything disabled except `outbox-backstop`. Running beside the `ubuntu` cron is
   safe: both pick rows with `FOR UPDATE SKIP LOCKED`. Once `/health` shows good runs, remove the cron line.
2. Enable `publish-backstop` and `merge-poll`; remove their two cron lines. Overlap is safe: publishing uses claim
   tokens, and the merge poll uses status-guarded updates. This ends the `ubuntu`-user runs.
3. Stop and disable `liturgical-calendar-reconciler.service` and `litcal-publish-consumer.service`, then enable
   `outbox-consumer` and `publish-consumer`. Old and new must not overlap: both default the consumer name to the
   hostname, so they would read as one consumer of the same group.
4. Enable `wider-region-membership`.
5. Enable `resource-tuple-sweep`; remove the 03:00 cron line.
6. Clear `LITCAL_JOBS_DISABLED`.

### 8.3 Cleanup in the same PR

- Delete `deploy/systemd/liturgical-calendar-reconciler.service`, `deploy/systemd/litcal-publish-consumer.service.in`,
  `deploy/cron/liturgical-calendar-backstop.cron`, and the `PUBLISH_CONSUMER_UNIT` branch of `install.sh`.
- Rewrite the operator docs to describe `litcal-jobs`: `docs/ops/openfga-outbox-runbook.md`,
  `docs/ops/change-request-runbook.md`, and `docs/ops/rbac-create-governance-runbook.md` Steps 4 and 7, including the
  rollout steps above and how to read `bin/litcal-jobs status`.
- Update the `CLAUDE.md` note on #1005, which tells operators to re-run the seeder by hand.

## 9. `/health`

`HealthHandler` gains a `jobs` block, built by `JobsHealth` from `job_schedule` and the registry:

- **`supervisor`:** `alive` (its lease is live) and `lease_age_seconds`. Not alive is a warning.
- **Per registered job:** `kind`, `interval_seconds`, `disabled`, `running` (lease live), `last_status`,
  `last_success_at`, `last_finished_at`, `consecutive_failures`, `next_due_at`, and:
  - `overdue`, for an interval job with no live lease more than one interval past `next_due_at` (at least 120 s) — the
    "a job that never runs is visible" signal; a warning;
  - `heartbeat_age_seconds`, for a stream job; a lapsed lease is a warning.
- A disabled job shows `disabled: true` and raises no warning.
- **No error text.** `/health` is unauthenticated, and `last_error` can hold paths, hostnames or token fragments;
  `bin/litcal-jobs status` shows it.
- **Top-level status unchanged.** Only an unreachable database returns 503, as today; job problems are warnings.

## 10. Testing

CI has Postgres but no Redis, so anything touching Redis uses the existing mocks and fakes (`ScriptedStreamConsumer`,
`ThrowingReadStreamConsumer`, `createMock(\Redis)`).

- **`JobScheduleRepository`** (`RepositoryTestCase`, real Postgres): acquire is exclusive; an expired lease can be
  taken over; finish is owner-guarded; a process that lost its lease records nothing; startup upsert keeps history; a
  two-process acquire race through `proc_open`, like `SourceDataChangeRequestPublishQueueTest`.
- **`JobRegistry` and `JobDefinition`:** duplicate and reserved names rejected; a destructive definition without
  `DestructiveJob` rejected; intervals and timeouts positive.
- **`JobRunner`:** success, failure and refusal are recorded as `succeeded`, `failed` and `refused` with exit codes 0,
  1 and 3; `--dry-run` records nothing and still runs preflight; exit 75 when the lease is held.
- **`Supervisor`** (integration, skipped without `pcntl`), with a test registry of fake jobs (sleep, fail, hang,
  exit-immediately stream): a due job starts; a job past its timeout is killed and recorded `timed_out`; SIGTERM shuts
  down in order and releases the lease; an exiting stream job restarts with backoff; a second supervisor waits as a
  standby and takes over once the first stops; a renewal error keeps children running; disabled jobs never start.
- **Loops:** `ConsumerLoop` and `PublishConsumerLoop` stop when the callback says so. The idle-tick tests in
  `PublishConsumerLoopTest` are removed with the idle ticks; `publish-backstop` and `merge-poll` get wrapper tests.
- **`JobsHealth`:** overdue, disabled, dead supervisor, stale heartbeat, and no `last_error` in the output.
- **`SourceTreeGuard`:** empty tree refused, populated tree allowed; `WiderRegionMembershipSeederTest` still passes.
- **Deploy script:** `litcal-fpm-reload.sh` passes `bash -n` and `shellcheck`.
- **Entry point:** a static check of `bin/litcal-jobs`, like `PublishSourceDataConsumerScriptTest`.
