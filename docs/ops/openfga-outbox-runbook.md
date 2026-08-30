# OpenFGA outbox — operator runbook

## What this is

The OpenFGA outbox (`openfga_outbox` table) holds every tuple write/delete the API has committed to perform. A
systemd-managed consumer drains it via Redis Streams; a cron-driven backstop catches the cracks. Together they
guarantee at-least-once application of tuple operations even across multi-minute network partitions.

See `docs/superpowers/specs/2026-06-02-openfga-async-reconciliation-design.md` for the full design.

## Install

### 1. Apply the migration

The `litcal-migrate` one-shot container handles this on `docker compose up -d --build`. Manual fallback:

```bash
composer db:migrate
```

### 2. Install the systemd unit

```bash
sudo cp deploy/systemd/liturgical-calendar-reconciler.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now liturgical-calendar-reconciler.service
sudo systemctl status liturgical-calendar-reconciler.service
```

### 3. Install the cron backstop

```bash
sudo cp deploy/cron/liturgical-calendar-backstop.cron /etc/cron.d/litcal-outbox-backstop
sudo systemctl restart cron
```

### 4. Confirm

```bash
curl -s http://localhost:8000/health | jq .openfga_outbox
```

Should return an object with all-zero counts on a fresh install.

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

The consumer is wedged. Check `journalctl -u liturgical-calendar-reconciler.service --since '10 min ago'`. If it
is crash-looping, you will see RestartSec=5 cycles. Common causes: Redis unreachable (consumer logs ERROR + exits;
backstop still drains, just every 5 minutes); PG unreachable (handlers also fail, /health surfaces it).

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
