# Test-scope migration — operator runbook

## Background

This branch (`feat/calendar-scoped-tests`) replaces the flat `test_definition`
OpenFGA type with three calendar-scoped types:

| New type                      | Scope                   | Object id                         |
|-------------------------------|-------------------------|-----------------------------------|
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

The authorization model itself is owned by `cdcf-infra`, not this repo: to ship
this model change, edit `cdcf-infra/auth/models/LiturgicalCalendar.json`, get
that PR merged, then have the operator run
`./setup-openfga.sh --target production --create-litcal-store` in
`/opt/cdcf-auth/auth` on the VPS to upload the new model version.

Once the new model is uploaded, read it back and re-pin `OPENFGA_MODEL_ID` from
the new model ID:

```bash
./scripts/setup-openfga.sh --update-env
```

What `./scripts/setup-openfga.sh --update-env` (run from this repo) does:

1. Waits for OpenFGA to be ready.
2. Finds or creates the `LiturgicalCalendar` store.
3. Reads back the latest authorization model already in that store — it no
   longer creates or uploads a model.
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

**Brief authz window:** Between this step and the `--apply` completion in Step 3,
non-admin `test_editor` writes to `/tests` will return 403 — scoped tuples are not yet
written. Admins bypass OpenFGA and are unaffected. Impact is negligible (new feature,
~no existing grants). Run the migration immediately after deploy, in a low-traffic window.

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

1. Remove the `test_definition` type from `cdcf-infra/auth/models/LiturgicalCalendar.json`,
   get that change merged, then have the operator run
   `./setup-openfga.sh --target production --create-litcal-store` in
   `/opt/cdcf-auth/auth` on the VPS to upload the new model version.
2. Re-pin `OPENFGA_MODEL_ID` in each environment from the new model ID (run
   `./scripts/setup-openfga.sh --update-env` in this repo to pick it up).
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

---

## Follow-up migration — rite-qualified test scopes

Issue #767 made the rite a first-class scope for liturgical tests. Two things
follow, and one migration covers both.

**The rite-level calendar needed a type.** The Ambrosian rite-level calendar
(`GET /calendar/ambrosian`) is neither national nor diocesan, so
`general_roman_calendar_test` — a type with exactly one id,
`general_roman_calendar` — could not name it.

**Scoped calendar ids needed a rite.** A bare calendar id does not identify a
calendar: the source tree is partitioned as
`jsondata/sourcedata/rite/{rite}/calendars/...`, so `lugano_ch` could name an
Ambrosian calendar or a Roman one, and granting `diocesan_calendar_test` on a
bare `lugano_ch` would be an ambiguous grant. National scopes are qualified too,
for one uniform rule — even though only the Roman rite has a national tier.

| Type                          | Object id before         | Object id after                       |
|-------------------------------|--------------------------|---------------------------------------|
| `general_roman_calendar_test` | `general_roman_calendar` | → `rite_calendar_test:roman`          |
| `rite_calendar_test`          | *(new)*                  | A `Rite` value: `roman` / `ambrosian` |
| `national_calendar_test`      | `US`                     | `roman/US`                            |
| `diocesan_calendar_test`      | `lugano_ch`              | `ambrosian/lugano_ch`                 |

The rite of an existing national or diocesan tuple is inferred from the
rite-partitioned source tree, which is the authority on which rite a calendar is
defined under. An id defined under two rites is reported and skipped — the script
never guesses which grant was meant.

The shape of this migration mirrors the one above, with one deliberate
difference: it is **copy-only by default**. The superseded tuples are left in
place so that rolling the API back to pre-#767 code keeps authorizing.

### Step 1 — apply the model

`rite_calendar_test` is added to `scripts/openfga-model.additive.json` alongside
`general_roman_calendar_test`, with an identical relation set. As above, the
authoritative model lives in `cdcf-infra`: land the equivalent change to
`cdcf-infra/auth/models/LiturgicalCalendar.json`, then upload the new model
version on the VPS.

### Step 2 — copy the tuples

```bash
# Dry run first — prints every tuple that would be copied.
php scripts/migrate-rite-test-tuples.php

# Apply.
php scripts/migrate-rite-test-tuples.php --apply
```

Each superseded tuple gains a counterpart on its rite-qualified successor,
preserving user and relation. Re-running is a no-op: already-qualified ids are
recognised and skipped. Anything the script cannot resolve — an unexpected id on
the legacy type, or a calendar id defined under two rites or none — is reported
and left untouched, and the run exits with status `2` so the anomaly is not lost
in CI output.

### Step 3 — prune (later)

Only once **every** deployment runs post-#767 code:

```bash
php scripts/migrate-rite-test-tuples.php --apply --prune
```

This deletes both the legacy `general_roman_calendar_test` tuples and the
unqualified `national_calendar_test` / `diocesan_calendar_test` ones.

Then drop `general_roman_calendar_test` from the model and from the PHP
allow-lists that still name it:

- `src/Services/TestScopeResolver.php` (docblock only — it no longer emits it)
- `src/Services/ResourceExistenceChecker.php`
- `src/Services/ResourceAdminService.php`
- `src/Repositories/AccessRequestRepository.php`
- `authz/openfga-expectations.json`
- `jsondata/schemas/openapi.json`

This step is **out of scope for the current change** and tracked as a follow-up.

---

## Follow-up migration — rite-qualified *data* resource scopes

Issue #786 applied the same reasoning to the calendar **data** resource types that #767
applied to the test scopes. A bare calendar id does not identify a calendar: the source
tree is partitioned as `jsondata/sourcedata/rite/{rite}/calendars/...`, so `lugano_ch`
could name an Ambrosian calendar or a Roman one, and a grant on the bare id would
silently widen to cover whichever was added later.

| Type                     | Object id before | Object id after       |
|--------------------------|------------------|-----------------------|
| `diocesan_calendar`      | `lugano_ch`      | `ambrosian/lugano_ch` |
| `national_calendar`      | `US`             | `roman/US`            |
| `wider_region`           | `Europe`         | `roman/Europe`        |
| `general_roman_calendar` | `temporale`      | *(unchanged)*         |

`general_roman_calendar` is untouched: its ids are `temporale`, `decrees` and missal
editions, which are not calendars and are Roman by construction.

**No model change is needed.** Unlike the test-scope migration above, the object *types*
are unchanged — only their ids move — so there is no new type to add and no model version
to upload.

These are **production calendar-editing grants**, not test-authoring scopes. Treat the
rollback story accordingly: the unqualified ids stay valid in every PHP allow-list until
the prune step, so a rollback to pre-#786 code keeps authorizing.

### Step 1 — copy the tuples

```bash
# Dry run first — prints every tuple that would be rewritten.
php scripts/migrate-rite-data-tuples.php

# Apply.
php scripts/migrate-rite-data-tuples.php --apply
```

National calendars and wider regions exist only in the Roman rite, so their rite is a
constant. A diocese's rite is inferred from the rite-partitioned source tree. A diocese id
defined under two rites, or under none, is reported and left untouched, and the run exits
with status `2` so the anomaly is not lost in CI output.

`member_nation` tuples are rewritten on **both** sides: their user side is a
`national_calendar:` object rather than a `user:`, so it needs qualifying too.

### Step 2 — verify

Editors should retain access. Spot-check a diocesan grant of each rite:

```bash
curl -s "$OPENFGA_API_URL/stores/$OPENFGA_STORE_ID/check" \
  -H 'Content-Type: application/json' \
  -d '{"tuple_key":{"user":"user:<sub>","relation":"editor","object":"diocesan_calendar:ambrosian/lugano_ch"}}'
```

### Step 3 — prune (later)

Only once **every** deployment runs post-#786 code:

```bash
php scripts/migrate-rite-data-tuples.php --apply --prune
```

Then the unqualified ids can be rejected outright rather than merely superseded. The same
step for the #785 test scopes is described above; the two can be pruned independently.
