# Test-scope migration — operator runbook

## Background

This branch (`feat/calendar-scoped-tests`) replaces the flat `test_definition`
OpenFGA type with three calendar-scoped types:

| New type                      | Scope                   | Object id                         |
| ----------------------------- | ----------------------- | --------------------------------- |
| `national_calendar_test`      | National-calendar tests | ISO nation code, e.g. `IT`        |
| `diocesan_calendar_test`      | Diocesan-calendar tests | Diocese id, e.g. `ROMA`           |
| `general_roman_calendar_test` | GRC / unscoped tests    | Fixed id `general_roman_calendar` |

The old `test_definition` type is **intentionally kept** in the model during the
migration window so existing tuples remain valid until all environments are
migrated. It is removed in a follow-up model version once every environment is
clean.

The migration is **write-before-delete** and **idempotent**. An interrupted run
leaves both the new and old tuples in place; re-running picks up where it left
off.

---

## Pre-flight checklist

Before starting, confirm:

- `OPENFGA_API_URL`, `OPENFGA_STORE_ID`, and `OPENFGA_MODEL_ID` are set in the
  environment (or in `.env.local` / `.env.development`).
- PHP ≥ 8.4 and Composer dependencies are installed.
- The target API instance is reachable and healthy:
  `curl -s http://localhost:8000/calendars | jq .`

---

## Step 1 — Apply the new OpenFGA model version

The new model adds the three scoped types (`national_calendar_test`,
`diocesan_calendar_test`, `general_roman_calendar_test`) alongside the existing
`test_definition` type. Applying a new model version is **additive** and
non-destructive; existing tuples continue to be valid.

Run against **each environment** in order: dev → staging → prod.

```bash
./scripts/setup-openfga.sh --update-env
```

What the script does:

1. Waits for OpenFGA to be ready.
2. Finds or creates the `LiturgicalCalendar` store.
3. Compares `scripts/openfga-model.json` with the current model version; if they
   differ, posts the file as a new model version.
4. With `--update-env`, writes the resulting `OPENFGA_STORE_ID` and
   `OPENFGA_MODEL_ID` into the `.env.*` file(s) and Docker Compose `.env` files
   in the API and sibling repos.

**Confirm the new model is active:**

```bash
curl -s "${OPENFGA_API_URL}/stores/${OPENFGA_STORE_ID}/authorization-models" \
  | jq '.authorization_models[0].type_definitions[].type'
```

The output should include all four types: `"national_calendar_test"`,
`"diocesan_calendar_test"`, `"general_roman_calendar_test"`, and
`"test_definition"`.

---

## Step 2 — Deploy this API version

Deploy the `feat/calendar-scoped-tests` build to the target environment. The new
`/tests` PATCH/PUT/DELETE middleware
(`OpenFgaAuthorizationMiddleware::forTestScopes`) reads each test's `applies_to`
field via `TestScopeResolver` and checks the **scoped** type. There is no code
path that still checks `test_definition` for write authorization after this
deploy.

Confirm the deployed API is healthy before proceeding:

```bash
curl -s http://localhost:8000/calendars | jq '.settings'
```

---

## Step 3 — Migrate tuples

### 3a. Dry run (always run this first)

```bash
php scripts/migrate-test-tuples.php
```

The default mode is `--dry-run`. The script paginates all `test_definition:*`
tuples in the store, resolves each via the test's `applies_to` field, and prints
what it would do:

```text
Mode: DRY RUN (pass --apply to apply changes)

[DRY RUN] test_definition:IT_2024 → national_calendar_test:IT
[DRY RUN] test_definition:ROMA_DIOCESE_2024 → diocesan_calendar_test:ROMA
[DRY RUN] test_definition:GRC_ADVENT_2024 → general_roman_calendar_test:general_roman_calendar
[UNRESOLVED] test_definition:ORPHAN_TEST (no test file found — skipping)

Summary:
  Total test_definition tuples : 4
  Would migrate                : 3
  Would skip (unresolved)      : 1

Unresolved test IDs (test JSON file not found):
  - ORPHAN_TEST
```

**Review the output before continuing.** An exit code of `2` means there are
unresolved tuples (the JSON file for that test is missing). Unresolved tuples
are **never deleted** — they stay as `test_definition:*` and are safe to
investigate separately. Fix any unresolved tests before applying (or accept that
they remain under the old type until cleaned up).

### 3b. Apply

```bash
php scripts/migrate-test-tuples.php --apply
```

The script:

1. Writes the new scoped tuple (e.g. `national_calendar_test:IT`).
2. Deletes the old `test_definition:*` tuple.
3. If the new tuple already exists, write is silently skipped.
4. If the old tuple is already gone, delete is silently skipped.

Exit code `0` = all tuples migrated. Exit code `2` = some tuples unresolved (old
tuples kept).

---

## Step 4 — Verify `/tests` PATCH authorization

After migrating, confirm that the scoped authorization is enforced correctly.

### 4a. Out-of-scope user must receive 403

An authenticated user who holds `national_calendar_test:DE#editor` (Germany
scope) must **not** be allowed to PATCH an Italian national-calendar test.

```bash
# TOKEN_OUT = a valid OIDC bearer token for a user scoped to DE, not IT
curl -s -o /dev/null -w "%{http_code}" \
  -X PATCH \
  -H "Authorization: Bearer ${TOKEN_OUT}" \
  -H "Content-Type: application/json" \
  -d '{"missal": "IT_1983"}' \
  http://localhost:8000/tests/IT_2024
```

Expected: **`403`**

### 4b. In-scope user must receive 2xx

An authenticated user who holds `national_calendar_test:IT#editor` must be
allowed to PATCH the same test.

```bash
# TOKEN_IT = a valid OIDC bearer token for a user with national_calendar_test:IT#editor grant
curl -s -o /dev/null -w "%{http_code}" \
  -X PATCH \
  -H "Authorization: Bearer ${TOKEN_IT}" \
  -H "Content-Type: application/json" \
  -d '{"missal": "IT_1983"}' \
  http://localhost:8000/tests/IT_2024
```

Expected: **`200`** or **`204`**

### 4c. Admin user always bypasses OpenFGA

A user with the `admin` role in the OIDC token passes all OpenFGA checks without
a tuple lookup.

```bash
curl -s -o /dev/null -w "%{http_code}" \
  -X PATCH \
  -H "Authorization: Bearer ${TOKEN_ADMIN}" \
  -H "Content-Type: application/json" \
  -d '{"missal": "IT_1983"}' \
  http://localhost:8000/tests/IT_2024
```

Expected: **`200`** or **`204`**

---

## Step 5 — Repeat for staging and production

Repeat Steps 1–4 for each remaining environment. The migration is safe to
re-run; already-migrated tuples are treated as no-ops.

---

## Step 6 — Deferred: drop `test_definition` (follow-up)

After **all** environments are migrated and verified:

1. Remove the `test_definition` type from `scripts/openfga-model.json`.
2. Apply the updated model via `./scripts/setup-openfga.sh --update-env` in each
   environment.
3. Remove the `test_definition` constant from any PHP enum or constant that
   still references it (see `src/Enum/JsonDataConstants.php`).

This step is **out of scope for the current change** and tracked as a follow-up.

---

## Rollback notes

The migration writes the new tuple **before** deleting the old one, so:

- An interrupted run leaves **both** tuples present. No permissions are lost.
- Re-running `--apply` will find the new tuple already exists (no-op write) and
  will delete the old tuple (or find it already gone). Safe and idempotent.
- If you need to fully roll back this API deploy (revert to a version that still
  uses `test_definition` for authz), the old tuples are still present unless the
  migration completed **and** you deleted them. If you are mid-migration, simply
  redeploy the previous API version — the `test_definition` type is still in the
  model and all existing grants still apply.
- A complete rollback after a successful migration requires re-creating the
  `test_definition` tuples from the scoped tuples. There is no automated script
  for this; manual OpenFGA tuple writes or a reverse migration script would be
  needed. Prefer rolling forward rather than rolling back once all environments
  are migrated.
