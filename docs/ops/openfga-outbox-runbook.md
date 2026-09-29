# OpenFGA outbox — operator runbook

## What this is

The OpenFGA outbox (`openfga_outbox` table) holds every tuple write/delete the API has committed to perform. The
job runner's `outbox-consumer` job drains it via Redis Streams; its `outbox-backstop` job catches the cracks every
five minutes. Together they guarantee at-least-once application of tuple operations even across multi-minute
network partitions.

This runbook is also where **the job runner** itself is documented (below): the one systemd unit that runs every
background job of the API, the outbox's included.

See `docs/superpowers/specs/2026-06-02-openfga-async-reconciliation-design.md` for the outbox design, and
`docs/superpowers/specs/2026-09-29-job-runner-design.md` for the job runner's.

## Install

### 1. Apply the migrations

The `litcal-migrate` one-shot container handles this on `docker compose up -d --build`. Manual fallback:

```bash
composer db:migrate
```

This creates both `openfga_outbox` and `job_schedule`, the job runner's schedule table.

### 2. Install the job runner

Set `JOBS_UNIT` in `/etc/litcal-deploy.env` (see `deploy/litcal-deploy.env.example`), then run the installer as
root from a checkout:

```bash
sudo deploy/install.sh
sudo systemctl status litcal-jobs.service
```

`PHP_BIN` in that file must have the `pcntl` and `posix` extensions; the supervisor refuses to start without
them (exit 2, visible in `journalctl -u litcal-jobs.service`).

### 3. Confirm

```bash
curl -s http://localhost:8000/health | jq '.openfga_outbox, .jobs'
php bin/litcal-jobs status
```

`openfga_outbox` should show all-zero counts on a fresh install, and `jobs.supervisor.alive` should be `true`.

## The job runner

`bin/litcal-jobs supervise`, run by `litcal-jobs.service`, starts every background job as its own child process
(`bin/litcal-jobs run <job>`), so a hung GitHub call cannot delay the OpenFGA outbox. Each job's schedule, lease
and last run live in the `job_schedule` table.

| Job                       | Kind       | Interval | Timeout | What it does                                                          |
|---------------------------|------------|----------|---------|-----------------------------------------------------------------------|
| `outbox-consumer`         | stream     | —        | —       | Applies outbox rows as soon as a handler announces them on Redis      |
| `publish-consumer`        | stream     | —        | —       | Publishes an approved change request as soon as it is approved        |
| `outbox-backstop`         | interval   | 300 s    | 240 s   | Applies outbox rows the consumer missed or could not retry            |
| `publish-backstop`        | interval   | 60 s     | 900 s   | Publishes stranded or due change requests with no message             |
| `merge-poll`              | interval   | 60 s     | 300 s   | Settles open change-request pull requests to merged or closed         |
| `wider-region-membership` | interval   | 86400 s  | 900 s   | Reconciles `member_nation` tuples with the national calendar files    |
| `resource-tuple-sweep`    | interval   | 86400 s  | 1800 s  | Revokes editor/viewer tuples on resources no longer on disk           |

The last two are **destructive**: before every run, a guard refuses when no national calendar file exists (a
missing or half-deployed data tree would read as "everything was deleted"). A refusal is recorded as `refused`,
shows as a warning in `/health`, and the job waits a full interval before trying again.

**How it schedules.** An interval job is due one interval after its last run *finished*, so an overrunning run
never piles up runs. A child still running at its timeout gets SIGTERM, then SIGKILL ten seconds later, and is
recorded `timed_out`. A stream job that exits is restarted after 1 s, doubling to 60 s while it keeps exiting
quickly. Every comparison uses the database clock.

**One supervisor at a time.** The supervisor holds a lease on the `supervisor` row of `job_schedule`. A second
one — another host, or `supervise` run by hand next to the unit — waits as a standby and takes over within about
a minute if the first dies. A database outage does not stop the running jobs; the supervisor reconnects.

**Deploys restart it.** `litcal-fpm-reload.sh` restarts `JOBS_UNIT` on every API deploy, after the WebSocket
server, so every job re-reads `src/` (see `docs/ops/deploy-sentinel-runbook.md`). On SIGTERM the supervisor stops
its children in order before exiting.

### Commands

```bash
php bin/litcal-jobs status                                   # every job: schedule, last run, last error
php bin/litcal-jobs run outbox-backstop                      # run one job now, under its lease
php bin/litcal-jobs run resource-tuple-sweep --dry-run       # list what it would change; change nothing
composer jobs:status
```

A manual `run` takes the job's lease and records its outcome exactly as a supervised run does, so it shows in
`/health`. If the supervisor's child is running the job at that moment, the manual run steps aside with exit 75.
A `--dry-run` takes no lease and records nothing, but still runs a destructive job's guard. Only the two
destructive jobs have a dry run; any other job refuses `--dry-run` with exit 2 rather than doing its real work.

| Exit | Meaning                                                                   |
|------|---------------------------------------------------------------------------|
| 0    | Success                                                                   |
| 1    | The job failed; `status` shows the error                                  |
| 2    | Usage or configuration: unknown job, no database, missing `pcntl`/`posix` |
| 3    | A destructive job's guard refused to run                                  |
| 75   | Another process holds the job right now; nothing was done                 |

### Turning jobs off

`LITCAL_JOBS_DISABLED` in the API's `.env` file is a comma-separated list of job names the supervisor never
starts, for example `LITCAL_JOBS_DISABLED=outbox-consumer,publish-consumer` on a host without Redis (the
backstops then do all the work, on their own schedule — the interval is when the next run starts, measured from
when the last one finished, not a bound on how long the work takes). Restart `litcal-jobs.service` after changing it.
`/health` lists disabled jobs as `disabled` and raises no warning for them.

### Reading `/health`'s `jobs` block

`status` is `warning` when the supervisor is not running, an interval job is `overdue` (unleased and more than one
interval, at least two minutes, past its due time — what a job that never runs looks like), a stream job is not
running, or a job's last run did not succeed. `message` names each problem. Error text is deliberately absent,
since `/health` is public; read it with `bin/litcal-jobs status`. As for every block of `/health`, a warning here
does not change the endpoint's own status code.

### Moving an existing server onto the job runner

A server installed before the job runner has `liturgical-calendar-reconciler.service`, possibly
`litcal-publish-consumer.service`, and cron lines for the outbox backstop, `publish-sourcedata.php`,
`poll-sourcedata-merges.php` and `reconcile-resource-tuples.php --apply`. Move one group at a time, starting the
new job before removing the old piece. Every step is safe to overlap except the consumer swap:

1. Deploy, then add `LITCAL_JOBS_DISABLED` listing every job except `outbox-backstop` to the API's `.env`, set
   `JOBS_UNIT`, and run `sudo deploy/install.sh`. The job and the cron backstop overlapping is harmless (both lock
   rows with `FOR UPDATE SKIP LOCKED`). Once `bin/litcal-jobs status` shows good runs, delete the outbox backstop
   cron line.
2. Remove `publish-backstop` and `merge-poll` from `LITCAL_JOBS_DISABLED`, restart `litcal-jobs.service`, then
   delete the `publish-sourcedata.php` and `poll-sourcedata-merges.php` cron lines. Overlap is safe: publishing
   uses claim tokens, and the poll uses guarded updates.
3. Stop and disable the old consumers first — `sudo systemctl disable --now liturgical-calendar-reconciler.service
   litcal-publish-consumer.service` — then remove `outbox-consumer` and `publish-consumer` from the list and
   restart. They must not overlap: old and new both default their consumer name to the hostname, so they would read
   as one consumer of the same group. The few seconds' gap is covered by the backstops.
4. Remove `wider-region-membership` from the list and restart.
5. Remove `resource-tuple-sweep` from the list, restart, and delete the `reconcile-resource-tuples.php` cron line.
6. When the list is empty, delete the `LITCAL_JOBS_DISABLED` line, remove the old unit files from
   `/etc/systemd/system/`, and run `sudo systemctl daemon-reload`.

## Redis connection settings

Every Redis connection in the codebase — this consumer, the publish consumer, the best-effort notifiers
on the request path, and the WebSocket cache — is built by one helper, `src/Services/RedisConnection.php`.
Configure it once and all of them follow.

| Variable                | Meaning                                                    |
| ----------------------- | ---------------------------------------------------------- |
| `REDIS_SOCKET`          | UNIX socket path. Wins over `REDIS_HOST` when set.         |
| `REDIS_HOST`            | Hostname or IP. May carry a `tls://` (or `ssl://`) prefix. |
| `REDIS_PORT`            | TCP port. Default 6379.                                    |
| `REDIS_PASSWORD`        | Optional `AUTH` credential.                                |
| `REDIS_TLS`             | `true` to use TLS without a scheme prefix on `REDIS_HOST`. |
| `REDIS_TLS_CA_FILE`     | CA bundle for verifying the server certificate.            |
| `REDIS_TLS_VERIFY_PEER` | `false` disables peer verification. Development only.      |

All of them are read from `$_ENV` **and** from the process environment, so a systemd `Environment=` or
`EnvironmentFile=` directive reaches them even when PHP CLI runs with `variables_order` excluding `E`.

The connect timeout is 2 seconds at every site. It bounds the TCP handshake only — the consumer's
blocking `XREADGROUP` is not affected by it.

### `REDIS_PASSWORD` over plain TCP

Redis `AUTH` sends the password as an ordinary command. Over an unencrypted TCP connection the credential,
and every command after it, crosses the network in cleartext. On a UNIX socket or a loopback address that
does not matter; the moment `REDIS_HOST` points at a managed Redis, a sidecar on another node, or anything
across a network segment, it does.

So when `REDIS_PASSWORD` is set and the endpoint is neither a socket, nor loopback, nor TLS, the process
logs a warning **once per process** (once per FPM worker lifetime; once per run for a CLI entry point):

```text
REDIS_PASSWORD is being sent to redis.example.com:6379 over an unencrypted TCP connection: ...
```

It warns, it does not refuse — the connection is still made, so an upgrade never breaks a running
deployment. To silence it honestly, pick one:

- `REDIS_SOCKET=/var/run/redis/redis.sock` — never leaves the host.
- Keep `REDIS_HOST` on loopback (`127.0.0.0/8`, `::1`, `localhost`) — never leaves the interface.
- `REDIS_HOST=tls://redis.example.com`, or `REDIS_TLS=true` with a plain host — encrypted on the wire.

For a managed Redis with a private CA, add `REDIS_TLS_CA_FILE=/path/to/ca.pem`. `REDIS_TLS_VERIFY_PEER=false`
exists for local debugging and defeats the point of TLS in production.

## Diagnostic queries

```sql
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
```

## Common incidents

### `oldest_pending_age_seconds` growing past 60s

The consumer is wedged or down. Check `/health`'s `jobs.jobs.outbox-consumer` (`running`, `heartbeat_age_seconds`)
and `journalctl -u litcal-jobs.service --since '10 min ago'`; an `outbox-consumer` child that keeps exiting is
restarted with a growing backoff, and each exit is logged. Common causes: Redis unreachable (the consumer exits;
the `outbox-backstop` job still drains, every five minutes); PG unreachable (handlers also fail, /health surfaces
it). A row whose OpenFGA call failed is retried by the consumer itself, on its backoff schedule (#1013), so a
transient OpenFGA error shows up here only briefly. While the consumer is down, retries fall to the backstop too:
around six minutes each (the 300 s interval plus its 60 s grace).

### Rows piling up in failed_terminal

````bash
curl -H "Authorization: Bearer $ADMIN_TOKEN" \
  http://localhost:8000/admin/outbox?status=failed_terminal | jq .items
````

The `last_error_code` tells you why each row failed. Typical: `validation_error` (the API built a bad tuple — fix
upstream), `auth_failure` (OpenFGA credentials wrong — fix env).

After fixing the upstream issue:

````bash
# Retry one row:
curl -X POST -H "Authorization: Bearer $ADMIN_TOKEN" \
  http://localhost:8000/admin/outbox/42/retry
````

## Retention / pruning

There is no automated prune in v1. The table grows by one row per tuple operation. For typical admin-action volume
that is years before any concern. When it does become one:

```sql
DELETE FROM openfga_outbox
WHERE status = 'succeeded'
  AND completed_at < NOW() - INTERVAL '30 days';
```

Don't delete `failed_terminal` rows automatically — they are the audit trail for "what didn't apply".
