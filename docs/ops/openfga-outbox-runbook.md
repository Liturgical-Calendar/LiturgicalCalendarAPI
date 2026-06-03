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
