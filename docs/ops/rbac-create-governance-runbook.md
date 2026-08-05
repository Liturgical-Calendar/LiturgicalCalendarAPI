# RBAC create-governance — operator runbook

## What this is

This runbook covers the no-downtime rollout of the RBAC create-governance feature (PR #668 / PR #669). The feature:

- Rewrites the OpenFGA model so `admin` absorbs delete capability (dropping the standalone `deleter` relation after migration).
- Adds a `member_nation` tuple-to-userset (TTU) so national-calendar admins inherit `wider_region:*#admin` automatically.
- Wires create-time and delete-time OpenFGA tuple management into the API handlers.
- Provides CLI scripts for one-time data migrations and ongoing reconciliation.

**Admin bypass:** system admins bypass OpenFGA checks throughout. The migration window is therefore non-breaking for operators.

> **Model ownership note (added after this rollout completed):** the OpenFGA model now lives in `cdcf-infra`
> (`auth/models/LiturgicalCalendar.json`), and neither `scripts/openfga-model.json` nor
> `scripts/openfga-model.additive.json` exists in this repo any more. Model changes go through a PR in
> `cdcf-infra`, followed by an operator running `./setup-openfga.sh --target production --create-litcal-store`
> in `/opt/cdcf-auth/auth` on the VPS. The steps below are retained unchanged as a record of how this specific rollout was performed.

---

## Governance chain

| Actor                   | How they gain access                                                               |
| ----------------------- | ---------------------------------------------------------------------------------- |
| System admin            | Approves the first `admin` grant on a (possibly non-existent) national calendar.   |
| National-calendar admin | Self-governs scoped `editor` / `viewer` requests once the first grant is in place. |
| Wider-region admin      | Inherited automatically via `member_nation` TTU — no separate grant needed.        |

**Diocesan create-governance** and **frontend `deleter`-removal** are deferred coordinated follow-ups and are out of scope for this rollout.

---

## Pre-flight checklist

Before starting, confirm:

- `OPENFGA_API_URL`, `OPENFGA_STORE_ID`, and `OPENFGA_MODEL_ID` are set in the environment (or in `.env.local` / `.env.development`).
- PHP ≥ 8.4 and Composer dependencies are installed (`composer install`).
- The target API instance is reachable and healthy:

  ```bash
  curl -s http://localhost:8000/calendars | jq '.settings'
  ```

- A low-traffic window is chosen for Steps 3–4 (brief authz window — see Step 2).

---

## Step 1 — Apply the additive OpenFGA model

The additive model (`scripts/openfga-model.additive.json`) adds the `member_nation` TTU and rewrites union relations while **retaining** the `deleter` relation.
This keeps existing `deleter` tuples valid during the migration window.

Applying a new model version is non-destructive; existing tuples continue to be valid under the new model.

```bash
curl -s -X POST \
  "${OPENFGA_API_URL}/stores/${OPENFGA_STORE_ID}/authorization-models" \
  -H "Content-Type: application/json" \
  --data-binary @scripts/openfga-model.additive.json \
  | jq '.authorization_model_id'
```

Update `OPENFGA_MODEL_ID` in your `.env.local` (and any Docker Compose `.env` files) with the returned id.

**Confirm:**

```bash
curl -s "${OPENFGA_API_URL}/stores/${OPENFGA_STORE_ID}/authorization-models" \
  | jq '.authorization_models[0].type_definitions[].type'
```

The output must include `"wider_region"`, `"national_calendar"`, `"diocesan_calendar"`, and `"general_roman_calendar"`.
Grep for `deleter` — it must still appear (retained in this model version).

> **Note:** `scripts/openfga-model.additive.json` is a transient rollout artifact. It is replaced by `scripts/openfga-model.json` in Step 6.

---

## Step 2 — Migrate deleter tuples to admin

Run this **before** deploying the API. Once the new API is live it enforces `DELETE → admin`, so any pre-existing
`deleter`-only grant must already have an equivalent `admin` tuple — otherwise it would lose delete access during the
window. The additive model from Step 1 still defines both `deleter` and `admin`, so this migration is valid here.

This script paginates all `*#deleter@user` tuples in the store and, for each one, writes the equivalent `*#admin@user` tuple
(write-before-delete). Users retain delete capability after the model update.

The migration is **idempotent** — re-running after a partial migration is safe.

### 2a. Dry run (always run this first)

```bash
php scripts/migrate-deleter-tuples.php
```

Review the output. Each line reports what would be written and deleted. An exit code of `0` means all tuples are accounted for.

### 2b. Apply

```bash
php scripts/migrate-deleter-tuples.php --apply
```

Confirm that every `deleter` tuple in the store now has a corresponding `admin` tuple, and that the original `deleter` tuples have been removed.

---

## Step 3 — Deploy the API

Deploy this branch (`feat/rbac-create-governance`) to the target environment.

```bash
docker compose up -d --build
```

Confirm the deployed API is healthy before proceeding:

```bash
curl -s http://localhost:8000/calendars | jq '.settings'
```

The API now enforces `DELETE → admin`. Because Step 2 already migrated every `deleter` grant to `admin`, there is **no
delete-access gap**. **Brief create-authz window:** until Step 4 seeds `member_nation` tuples, a non-admin user creating a
national calendar via `PUT /data/nation/{calendar}` may find a grant temporarily absent. Admins bypass OpenFGA and are
unaffected. Run Step 4 immediately after this deploy.

---

## Step 4 — Seed wider-region membership tuples

This script reads each national calendar definition and writes a `wider_region:{REGION}#member_nation@national_calendar:{ISO}` tuple
for every nation that declares a `wider_region`. It is idempotent.

### 4a. Dry run (always run this first)

```bash
php scripts/seed-wider-region-membership.php
```

Review the output. Each line shows a tuple that would be written. The summary line reports `Planned` (the number of tuples
that would be written) and `Written` (`0` in dry-run).

### 4b. Apply

```bash
php scripts/seed-wider-region-membership.php --apply
```

Confirm the summary shows `Written > 0` (or `0` if all tuples already existed — idempotent re-runs are safe).

---

## Step 5 — Deploy the coordinated frontend PR

This is a **separate-repository** step. The frontend PR removes the `deleter` relation option from any UI that allows users
to request OpenFGA grants. Deploy it after Steps 3 and 4 are complete.

Until this frontend PR is deployed, users may still be able to request `deleter` grants via the UI. Those grants will be accepted
by the store (the additive model still carries `deleter`), but the API itself no longer issues `deleter` tuples on create/delete paths.

---

## Step 6 — Apply the final OpenFGA model

Once Step 5 is deployed and no `deleter` tuples or usages remain in any environment, apply the final model (`scripts/openfga-model.json`), which drops the `deleter` relation entirely.

```bash
curl -s -X POST \
  "${OPENFGA_API_URL}/stores/${OPENFGA_STORE_ID}/authorization-models" \
  -H "Content-Type: application/json" \
  --data-binary @scripts/openfga-model.json \
  | jq '.authorization_model_id'
```

Update `OPENFGA_MODEL_ID` in your `.env.local` and Docker Compose `.env` files with the returned id.

**Confirm `deleter` is gone:**

```bash
curl -s "${OPENFGA_API_URL}/stores/${OPENFGA_STORE_ID}/authorization-models" \
  | jq '[.authorization_models[0].type_definitions[].relations | keys] | flatten | unique'
```

The output must **not** contain `"deleter"`.

Verify no orphaned `deleter` tuples remain:

```bash
curl -s -X POST \
  "${OPENFGA_API_URL}/stores/${OPENFGA_STORE_ID}/read" \
  -H "Content-Type: application/json" \
  -d '{"tuple_key": {"relation": "deleter"}}' \
  | jq '.tuples | length'
```

Expected: `0`. If any remain, re-run `php scripts/migrate-deleter-tuples.php --apply` and repeat.

---

## Step 7 — Schedule the reconciler

The reconciler (`scripts/reconcile-resource-tuples.php`) scans all OpenFGA tuples and enqueues purge rows for every `editor` / `viewer`
tuple whose backing resource no longer exists on disk. `admin` tuples on deleted resources are intentional governance and are never touched.

Schedule it as a daily cron:

```cron
0 3 * * * www-data php /path/to/api/scripts/reconcile-resource-tuples.php --apply >> /var/log/litcal-reconciler.log 2>&1
```

Or add it to `/etc/cron.d/litcal-reconciler`:

```bash
sudo tee /etc/cron.d/litcal-reconciler > /dev/null <<'EOF'
0 3 * * * www-data php /srv/liturgical-calendar-api/scripts/reconcile-resource-tuples.php --apply >> /var/log/litcal-reconciler.log 2>&1
EOF
sudo systemctl restart cron
```

Run a manual sweep immediately after completing the rollout:

```bash
php scripts/reconcile-resource-tuples.php --apply
```

---

## Rollback notes

The migration is **write-before-delete** throughout. An interrupted run leaves both old and new tuples in place; no permissions are lost.

- **Model rollback (Steps 1–2):** Re-apply the previous model version. The `deleter` type is retained in the additive model, so existing `deleter` tuples remain valid.
- **Tuple migration rollback (Step 4):** No automated reverse script exists — prefer rolling forward.
  If mid-migration, redeploy the previous API; `deleter` tuples from the incomplete run are still present and valid.
- **Seeder rollback (Step 3):** Removing `member_nation` tuples requires manual OpenFGA DELETE calls or a reverse script. Prefer rolling forward.

---

## Verification commands

```bash
# Check all tuples for a national calendar
curl -s -X POST \
  "${OPENFGA_API_URL}/stores/${OPENFGA_STORE_ID}/read" \
  -H "Content-Type: application/json" \
  -d '{"tuple_key": {"object": "national_calendar:IT"}}' \
  | jq '.tuples[].key'

# Check member_nation tuples for a wider region
curl -s -X POST \
  "${OPENFGA_API_URL}/stores/${OPENFGA_STORE_ID}/read" \
  -H "Content-Type: application/json" \
  -d '{"tuple_key": {"object": "wider_region:Europe", "relation": "member_nation"}}' \
  | jq '.tuples[].key.user'

# Confirm admin inherits wider_region admin via TTU
curl -s -X POST \
  "${OPENFGA_API_URL}/stores/${OPENFGA_STORE_ID}/check" \
  -H "Content-Type: application/json" \
  -d '{"tuple_key": {"user": "user:OPERATOR_ID", "relation": "admin", "object": "wider_region:Europe"}}' \
  | jq '.allowed'
```
