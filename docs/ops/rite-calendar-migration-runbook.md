# Rite-calendar migration — operator runbook

## Background

Issue #955 generalises the `general_roman_calendar` OpenFGA object type into a rite-level
`rite_calendar` tier. `general_roman_calendar` modelled the rite-level calendar tier as though
only the Roman rite had one — `general_roman_calendar:temporale`, `general_roman_calendar:decrees`,
and so on, all bare ids with no rite qualifier. #953 added the Ambrosian typical edition
(`EDITIO_TYPICA_2024`), so a bare id can no longer stand for "the Roman one" by construction. Every
other object type that names a calendar already carries its rite this way
(`diocesan_calendar:ambrosian/lugano_ch`, `national_calendar:roman/US`); `rite_calendar` brings the
rite-level tier into the same shape.

This migration is a continuation of two earlier ones in the same family:
`migrate-rite-test-tuples.php` (#767, test scopes) and `migrate-rite-data-tuples.php` (#786, calendar
data resource types). It folds in one piece of leftover work from #767: `general_roman_calendar_test`
has had a successor, `rite_calendar_test`, since #767 shipped, but no operator window ever finished
moving its tuples. This migration does both renames in one pass so the legacy data type and the
legacy test type reach their end state together.

| Legacy object                                        | New object                   |
|------------------------------------------------------|------------------------------|
| `general_roman_calendar:<sub>`                       | `rite_calendar:<rite>/<sub>` |
| `general_roman_calendar_test:general_roman_calendar` | `rite_calendar_test:roman`   |

`<sub>` is one of `temporale`, `decrees`, `supported_locales`, or a missal edition id
(`EDITIO_TYPICA_1970`, `EDITIO_TYPICA_2002`, `EDITIO_TYPICA_2008`, `EDITIO_TYPICA_2024`, ...). Rite
inference is never a guess: a missal id's rite is whichever `MissalCatalog` source declares it —
exactly one does, asserted by `MissalCatalogTest::testTheRitesDoNotShareIds` — and `temporale`,
`decrees`, and `supported_locales` are Roman by construction, since they are the only sub-resources
the legacy type ever carried and it denoted the Roman tier.

The migration is **copy-only by default**, matching #786 rather than #767: these are production
calendar-editing grants, not test-authoring scopes, so nothing is deleted until an operator
explicitly passes `--prune`, and the legacy types stay valid in every PHP allow-list until then. A
rollback to pre-#955 code keeps authorizing throughout.

---

## Pre-flight checklist

Before starting, confirm:

- `OPENFGA_API_URL`, `OPENFGA_STORE_ID`, and `OPENFGA_MODEL_ID` are set in the environment (or in
  `.env.local` / `.env.development` / `.env.staging` / `.env.production`, as appropriate for the
  target).
- PHP ≥ 8.4 and Composer dependencies are installed (`composer install`).
- The target API instance is reachable and healthy:

  ```bash
  curl -s http://localhost:8000/calendars | jq .
  ```

- **The model actually contains `rite_calendar`.** This is not automatic — it does not exist in the
  model until Step 1 below lands. Check the store directly rather than assuming:

  ```bash
  curl -s "${OPENFGA_API_URL}/stores/${OPENFGA_STORE_ID}/authorization-models?page_size=1" \
    | jq '.authorization_models[0].type_definitions[].type'
  ```

  On the dev store today (before Step 1), this prints exactly: `"diocesan_calendar"`,
  `"diocesan_calendar_test"`, `"general_roman_calendar"`, `"general_roman_calendar_test"`,
  `"national_calendar"`, `"national_calendar_test"`, `"rite_calendar_test"`, `"user"`,
  `"wider_region"` — `rite_calendar` is missing. **Nothing past Step 1 can start until this list
  includes `"rite_calendar"`**: a tuple on a type the model does not carry cannot be written, and
  `--apply` will fail for every candidate.

---

## Step 1 — Apply the new OpenFGA model version

The authorization model is owned by `cdcf-infra`, not this repo, at
`auth/models/LiturgicalCalendar.json`. The change is **additive**: add `rite_calendar` with
`admin`/`viewer`/`editor` relations mirroring `general_roman_calendar`'s. `general_roman_calendar`
itself is left in the model unchanged — it is only removed at the prune milestone (Step 6).

1. Land the model change in `cdcf-infra/auth/models/LiturgicalCalendar.json`, get that PR merged,
   then have the operator upload the new model version (per `cdcf-infra`'s own deploy process for
   that repo — e.g. `./setup-openfga.sh --target <env> --create-litcal-store` in
   `/opt/cdcf-auth/auth` on the VPS, mirroring how the test-scope and RBAC rollouts shipped their
   model changes).
2. Re-pin `OPENFGA_MODEL_ID` from the newly uploaded model:

   ```bash
   ./scripts/setup-openfga.sh --update-env
   ```

   This waits for OpenFGA to be ready, finds or creates the `LiturgicalCalendar` store, reads back
   the latest authorization model already in that store (it does not create or upload one), and —
   with `--update-env` — writes the resulting `OPENFGA_STORE_ID` and `OPENFGA_MODEL_ID` into the
   `.env.*` file(s) and Docker Compose `.env` files in this repo and its siblings.

   **That command is for a checkout, not for the deployed vhost, and running it in the wrong place
   re-pins the wrong store.** The deployed API's pin against the PRODUCTION store lives in exactly
   one file — `api/dev/.env.staging` on the VPS, the only `.env*` that deployment has, so there is
   no Dotenv precedence to reason about. `api/v4` and `api/v5` carry no `OPENFGA_*` keys at all.
   Run from a local checkout the command above resolves whatever store that checkout points at
   (a local dev store, not production) and writes those values, so edit the one VPS file instead.

   Edit it as its owner, and never point a shell redirect at the live file: a redirect truncates
   its destination BEFORE the command on its left runs, so `sed ... > .env.staging` or
   `cat tmp > .env.staging` leaves the file EMPTY if that command then fails. A zero-byte
   `.env.staging` takes the API down immediately, since phpdotenv reads it per request (observed
   2026-09-01).

   Write a temp copy in the SAME directory instead, validate it, and `mv` it into place. `cp -p`
   seeds the temp with the live file's owner, group and mode, so the replacement keeps them; the
   redirect then truncates only that temp; and `mv` within one directory is an atomic rename, so
   no in-flight request can read a half-written file. Build the temp as the same user that
   performs the write — a `mktemp` file made by `ubuntu` is mode 600 and unreadable to the user
   performing the write.

   ```bash
   sudo -u <owner> bash -c 'cd <deploy-dir> \
     && cp -p .env.staging .env.staging.bak.<label> \
     && cp -p .env.staging .env.staging.new \
     && sed "s/^OPENFGA_MODEL_ID=<old>$/OPENFGA_MODEL_ID=<new>/" .env.staging.bak.<label> > .env.staging.new \
     && grep -q "^OPENFGA_MODEL_ID=<new>$" .env.staging.new \
     && mv .env.staging.new .env.staging \
     || rm -f .env.staging.new'
   ```

   Then confirm owner/group/mode are unchanged (`stat -c %U:%G,%a .env.staging`) and that the API
   still answers (`curl -o /dev/null -w '%{http_code}' <base>/calendars`). No restart is needed.
3. Re-run the pre-flight model check above and confirm `"rite_calendar"` now appears in the output.

**Nothing else in this runbook can start until this step is confirmed done.** Steps 2 and 3 both
write or expect `rite_calendar:*` tuples; against a model that has not been updated, `--apply` fails
outright rather than silently doing nothing.

---

## Step 2 — Deploy this API version

Deploy the #955 build to the target environment. Both `OpenFgaAuthorizationMiddleware::forRiteCalendar()`
and `::forMissals()` check the object `rite_calendar:{rite}/{subResource}` first, with a legacy
fallback — but the two do **not** fall back the same way, and conflating them is a real
operational hazard (an operator could wrongly conclude Ambrosian missal grants stop being honoured
on deploy, and rush an unnecessary re-grant or `--apply`):

- **`forRiteCalendar()`** — the shared sub-resource names `temporale`, `decrees`,
  `supported_locales` — falls back **Roman-only**, to `general_roman_calendar:{subResource}`.
  Every id the legacy type ever held for these was Roman by construction, so there is no
  non-Roman legacy tuple to find, and pairing a fallback for another rite would re-introduce the
  un-qualification #955 exists to remove.
- **`forMissals()`** — typical editions — falls back **unconditionally across rites**, to
  `general_roman_calendar:{missalId}` regardless of which rite is being checked. Missal ids are
  unique across rites (`MissalCatalogTest::testTheRitesDoNotShareIds`), so the legacy bare id
  genuinely denoted the Ambrosian typical edition too — `general_roman_calendar:EDITIO_TYPICA_2024`
  really was, and still is, the Ambrosian edition's legacy object. The asymmetry is about what the
  legacy ids actually denoted, not about the rite (see the middleware's own docblock at
  `src/Http/Middleware/OpenFgaAuthorizationMiddleware.php:390-408`).

**Authorization** is safe in either order relative to Step 3: because of both fallbacks, a caller
holding only the old `general_roman_calendar:{id}` tuple keeps being **authorized to write** — on
the Roman rite for `forRiteCalendar()`'s sub-resources, and on any rite for `forMissals()`'s
typical editions — even before the tuple migration runs.

**The fallbacks do not reach any further than that.** They live in the authorization middleware, so
they preserve *whether a caller may write*, and nothing else. In particular they do **not** preserve:

- **Change-request auto-approval.** `ChangeRequestReview::administers()` →
  `ResourceAdminService::administersAllResources()` checks the rite-qualified object with no legacy
  fallback. Between this deploy and Step 3, a user whose only admin tuple is the legacy
  `general_roman_calendar:{id}` may still submit the write, but their change request is **queued for
  a reviewer instead of being auto-approved**.
- **Reviewer-queue visibility.** Which batches a reviewer sees is driven by the `resource_type` /
  `resource_id` stored on the change-request row. After Step 4 rewrites those to the new type, a
  user still holding only the legacy tuple stops seeing those batches in their review queue.

Both resume as soon as Step 3 has run, but not in the same way, and the difference decides what
an operator has to chase afterwards. Reviewer-queue visibility is evaluated per query, so it
returns in full on its own. Auto-approval is evaluated ONCE, on the way in:
`ChangeRequestSourceDataWriter` calls `ChangeRequestReview::administers()` at submit time and
records the batch as `submitted` when it is false, and nothing re-evaluates it later. So a request
that queued during the window stays queued — it needs an explicit reviewer approval; only new
submissions auto-approve again. This is deliberate and fail-closed: that path decides governance
rather than access, and silently auto-approving off a legacy tuple during the migration window
would be worse than queueing for a human. Nothing is lost, but "approved normally" means by a
human, not retroactively.

**Therefore: run Step 3 immediately after Step 2.** Do not leave the two separated by a maintenance
window or a working day; every minute in between is a minute in which legitimate change requests
queue up instead of auto-approving.

Confirm the deployed API is healthy before proceeding:

```bash
curl -s http://localhost:8000/calendars | jq '.settings'
```

---

## Step 3 — Migrate tuples

### 3a. Dry run (always run this first)

```bash
php scripts/migrate-rite-calendar-tuples.php
```

The default mode is `--dry-run`. The script paginates every tuple in the store, selects the ones
whose object starts with `general_roman_calendar:` or `general_roman_calendar_test:`, and prints
what it would do. Verified against the dev store (`01KVZQ2FR833RQE75EPRKJ8M3Y`) on 2026-09-01:

```text
Mode: DRY RUN (pass --apply to apply changes)
Prune superseded tuples: no (copy only)

[DRY RUN] user:381590331201683463 admin general_roman_calendar:temporale → rite_calendar:roman/temporale
[DRY RUN] user:388855725075464198 admin general_roman_calendar:temporale → rite_calendar:roman/temporale

Summary:
  Candidate tuples    : 2
  Would copy          : 2
  Already qualified   : 0
  Skipped             : 0
```

**Review the output before continuing.** Exit code `0` means every candidate tuple resolved. Exit
code `2` means some tuples were skipped — printed as `[SKIPPED] ... matches no known
rite-inference rule` and listed again under "Left untouched (resolve by hand)". Skipped tuples are
**never deleted**; they stay on the legacy type and are safe to investigate separately, since prune
(Step 6) is not run yet regardless.

### 3b. Apply

```bash
php scripts/migrate-rite-calendar-tuples.php --apply
```

For each candidate: writes the `rite_calendar:{rite}/{sub}` (or `rite_calendar_test:roman`) tuple
first, preserving the original `user` and `relation` and changing only the object; a tuple that
already exists is reported as `[ALREADY EXISTS]` and treated as a benign no-op, never an error. The
legacy tuple is **not** deleted — `--prune` is not passed. Safe to re-run: an already-migrated tuple
is recognised via `RiteScopedObjectId::parse()` and reported as `[ALREADY MIGRATED]`, not
double-qualified.

Exit code `0` = every candidate tuple copied or already qualified. Exit code `2` = some tuples
skipped (same meaning as in the dry run).

---

## Step 4 — Apply the Doctrine migration

**On a deployed vhost this step runs itself**, and cannot be run the way the command below
suggests: `bin/doctrine-migrations` is excluded from the rsync payload
(`.github/deploy/rsync-exclude.txt`) precisely because migrations are applied in-process, and
`deploy.yaml` POSTs `/_ops/migrate` after every rsync. So on `api/dev` the migration lands at
deploy time, as part of Step 2. Check the state rather than assuming.

`DEPLOY_TOKEN` is a shared secret and is not set in an operator shell by default — read it from
that host's env file rather than assuming it is exported, or the request goes out with an empty
header and `DeployTokenMiddleware` fails closed on it. Pass it through a curl config on stdin
rather than in `-H`, which would put it in the process argument list where any other user on the
VPS can read it with `ps`. `BASE` must be an `https://` URL; `--proto '=https'` makes curl refuse
to send the token over anything else rather than trusting a route-level redirect:

```bash
DEPLOY_TOKEN=$(sed -n 's/^DEPLOY_TOKEN=//p' .env.staging)
printf 'header = "X-Deploy-Token: %s"\n' "$DEPLOY_TOKEN" \
  | curl -fsS --proto '=https' -K - "${BASE}/${SUBDIR}/_ops/migrate/status"
unset DEPLOY_TOKEN
```

Only where you have a CLI checkout (local, CI) is the command:

```bash
composer db:migrate
```

**This inverts the Step 3 → Step 4 order on any auto-migrating deployment**, and the sequence
cannot be rearranged: the deploy applies the migration, so Step 4 completes at Step 2 time,
before the tuples move. The effect is to widen the Step 2 window described above rather than to
create a new hazard — change-request rows name `rite_calendar` while their tuples are still
legacy, so those requests queue for a reviewer instead of auto-approving. New submissions
auto-approve again as soon as Step 3 runs, but the ones that queued during the window stay
queued and need an explicit reviewer approval: auto-approval is decided once, at submit time.
Nothing is corrupted and no row is lost. It is one more reason to run Step 3 immediately after
Step 2.

`Version20260901130000` rewrites two Postgres tables from `general_roman_calendar[_test]` onto
`rite_calendar[_test]`: `sourcedata_change_requests.resource_type`/`resource_id`, and the JSONB
`permissions` array on `access_requests` (element-wise, order-preserving). Both statements are
idempotent — re-running the migration changes nothing further.

**`audit_log` is deliberately NOT rewritten.** It records what an operator actually did, under the
name in force at the time; rewriting it would falsify the historical record. That means a reader of
an old `audit_log` row needs the cutover date to know which name was current when that row was
written.

**Record the cutover date here, at the moment this step is run in each environment:**

| Environment | Cutover date (this step run) | Operator                                      |
|-------------|------------------------------|-----------------------------------------------|
| Dev         |                              |                                               |
| Staging     | 2026-09-01T20:23:37Z         | `deploy.yaml` (automatic, at the #965 deploy) |
| Production  |                              |                                               |

An `audit_log` row timestamped before its environment's date above names `general_roman_calendar` /
`general_roman_calendar_test`; a row timestamped after names `rite_calendar` / `rite_calendar_test`.

---

## Step 5 — Deploy the Frontend

Deploy the `LiturgicalCalendarFrontend` build that mirrors this vocabulary change (22 files, tracked
as a follow-up issue against that repo — cannot merge there before this branch's OpenAPI schema
lands). Confirm the deployed frontend can still request and display rite-level calendar grants after
this deploy.

---

## Step 6 — Prune (later, deferred)

Only once **every** deployment — every environment, both repos — runs merged post-#955 code.

**Nothing automatically fails when the fallback outlives this milestone.** A stale
`general_roman_calendar` tuple left in the store after every deployment has moved on just keeps
authorizing silently through the legacy fallback in `OpenFgaAuthorizationMiddleware`. There is no
error, no log line, nothing that surfaces on its own — this section existing and being acted on is
the only thing that will remind anyone to finish the job.

**Share this operator window with the deferred RBAC `deleter` drop** (see
`docs/ops/rbac-create-governance-runbook.md`). Both wait on the identical condition — every
deployment running merged code — and neither depends on the other, so there is no reason to spend
two separate windows on them.

Prune entails, in order:

1. Run the migration script with `--prune`:

   ```bash
   php scripts/migrate-rite-calendar-tuples.php --apply --prune
   ```

   This deletes the superseded `general_roman_calendar:*` and `general_roman_calendar_test:*`
   tuples, only after their `rite_calendar` / `rite_calendar_test` counterparts are confirmed
   written (same copy-then-prune ordering as Steps 3a/3b).

2. Open an API PR dropping the legacy types from every allow-list that still names them:
   - `AccessRequestRepository::VALID_OBJECT_TYPES` (and the associated `GRC_OBJECT_IDS` constant
     and validation branches)
   - `ResourceAdminService`
   - `ResourceExistenceChecker`
   - The middleware's legacy fallback in `OpenFgaAuthorizationMiddleware::forRiteCalendar()` and
     `::forMissals()`

3. Ship a `cdcf-infra` model version dropping both legacy types (`general_roman_calendar`,
   `general_roman_calendar_test`) from `auth/models/LiturgicalCalendar.json`, and re-pin
   `OPENFGA_MODEL_ID` in every environment (`./scripts/setup-openfga.sh --update-env`).

4. Move both legacy types from `required_types` to `forbidden_types` in
   `authz/openfga-expectations.json`, so a future regression that re-introduces either type is
   caught rather than silently tolerated.

---

## Rollback notes

Steps 1–3 are **all non-destructive without `--prune`** — nothing is deleted, so each is
independently reversible:

- **Step 1 (model version):** Additive; the previous model version remains available in the store's
  history. If the new model must be withdrawn, re-pin `OPENFGA_MODEL_ID` to the prior version with
  `./scripts/setup-openfga.sh --update-env` run against that version (or manually, if the tooling
  always resolves "latest"). Existing `general_roman_calendar` tuples and code paths are unaffected
  either way.
- **Step 2 (API deploy):** Redeploy the previous API version. The legacy fallback in
  `OpenFgaAuthorizationMiddleware` means grants made under either type keep authorizing through this
  rollback, in either direction.
- **Step 3 (tuple copy):** Nothing to undo — the legacy tuples are untouched, only new ones were
  added alongside them. If a copied tuple must be removed for some other reason, delete it directly
  via `OpenFgaClient` / the OpenFGA API; there is no dedicated "uncopy" script since copy-only is
  already reversible by construction.
- **Step 4 (Doctrine migration):** `composer db:migrations:migrate prev` (or the specific prior
  version) runs `Version20260901130000::down()`. Two things it does not do, stated rather than
  papered over:

  - **It refuses to run at all if any persisted `rite_calendar` value names a rite other than
    `roman`.** `down()` strips the rite prefix, so a row written by post-#955 code *after* cutover —
    `rite_calendar:ambrosian/temporale`, say — would come back as `general_roman_calendar:temporale`,
    an Ambrosian resource silently reinterpreted as Roman. Nothing in the row records which side of
    the cutover it was written on, so rather than corrupt it the migration counts such values first
    and aborts naming the count. `ambrosian/EDITIO_TYPICA_2024` is exempt and does not trigger the
    refusal: `up()` produces that prefix itself and `down()` returns it to the bare id it came from.
    See "Targeted manual rollback" below for what to do when the refusal fires.
  - **It does not restore `general_roman_calendar_test` from `rite_calendar_test`**, since that type
    has had two possible provenances since #767 and reverting it would corrupt rows the migration
    never touched.

  Prefer rolling forward once any environment is past this step.

  **Targeted manual rollback.** When `down()` refuses, decide row by row rather than forcing it.
  List the offending values first:

  ```sql
  SELECT id, resource_type, resource_id
    FROM sourcedata_change_requests
   WHERE resource_type = 'rite_calendar'
     AND position('/' in resource_id) > 0
     AND resource_id NOT LIKE 'roman/%'
     AND resource_id <> 'ambrosian/EDITIO_TYPICA_2024';

  SELECT ar.id, elem
    FROM access_requests ar,
         LATERAL jsonb_array_elements(ar.permissions) AS elem
   WHERE elem->>'object_type' = 'rite_calendar'
     AND position('/' in elem->>'object_id') > 0
     AND elem->>'object_id' NOT LIKE 'roman/%'
     AND elem->>'object_id' <> 'ambrosian/EDITIO_TYPICA_2024';
  ```

  Each row listed names a resource that has **no** pre-#955 spelling — the legacy type could not
  represent a non-Roman rite — so there is nothing to roll it back *to*. Resolve each one before
  re-running the down migration: delete the row if it is a draft or a pending request that can be
  re-submitted after the rollback, or close/reject it if it is a live proposal. Do not rewrite it to
  a Roman id; that is precisely the corruption the refusal exists to prevent.
- **Step 5 (Frontend deploy):** Redeploy the previous Frontend build; nothing in the API stack
  depends on the Frontend having moved.

**Step 6 (prune) is destructive and not straightforwardly reversible.** Once `--prune` runs, the
deleted legacy tuples are gone; the only way back is to re-derive and re-write them from the
surviving `rite_calendar` / `rite_calendar_test` tuples by hand (there is no reverse-migration
script), and the model/allow-list/`forbidden_types` changes in the same window would need their own
reverts. This is why Step 6 is gated on every deployment already running merged code — by that point
there should be nothing left depending on the legacy type to roll back to.
