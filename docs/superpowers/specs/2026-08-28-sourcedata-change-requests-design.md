# Source-data change requests: authoring edits on the server, publishing them to GitHub

**Status:** design approved, not yet implemented
**Tracked by:** [#902](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/issues/902)
**Date:** 2026-08-28
**Repos affected:** `LiturgicalCalendarAPI` (bulk), `LiturgicalCalendarFrontend` (admin UI)

## Problem

Authorized users edit calendars, decrees and other source data through the deployed site.
`RegionalDataHandler` and `DecreesHandler` write those edits straight to disk with
`file_put_contents()` into `jsondata/sourcedata/` and `i18n/`.

Two things are broken:

1. **The edits do not survive.** `.github/workflows/deploy.yaml` rsyncs `--archive --delete`
   from a fresh runner checkout. `jsondata/` is on the allowlist in
   `.github/deploy/check-deploy-payload.sh` and appears nowhere in
   `.github/deploy/rsync-exclude.txt`, so every deploy to a target silently reverts server-side
   edits — and `--delete` removes calendar files the server created. Staging redeploys on every
   push to `development`, so on staging that window is hours.
2. **The edits never reach the repository.** Deploys are an rsync of a checkout; `.git/` is
   excluded and the deploy user is chrooted. There is no working tree on the server to commit
   from, and the old "edit on the server, commit from the server" path no longer exists.

## What this design does

It reframes the deployed site: it is no longer a live editing surface that must be reconciled
with the repository. It is a **proposal-authoring UI over a repository that remains the single
source of truth**.

An authorized edit becomes a **change request** row in Postgres. It does not touch
`jsondata/sourcedata` at all. An administrator for that resource approves it; approval enqueues
an outbox row; the outbox consumer opens or updates a pull request on GitHub using the GitHub
Git Data API. When the PR merges, the change reaches the server the way every other data change
does — through the normal deploy.

That is **queue mode**. A deployment without the supporting stack keeps writing to disk exactly as
it does today — see "Deployment modes" below. The write handlers do not know which mode is active.

## Decisions taken during brainstorming

| Question                      | Decision                                                       |
| ----------------------------- | -------------------------------------------------------------- |
| Do edits go live immediately? | No. Nothing is written to disk until a PR merges and deploys.  |
| Where do pending edits live?  | Postgres. No filesystem overlay.                               |
| Scope                         | Everything under `jsondata/sourcedata`, plus test definitions. |
| Attribution                   | The authenticated editor is the git commit **author**.         |
| PR lifecycle                  | One rolling PR per resource; approved changes append commits.  |
| Admin self-approval           | Allowed. GitHub PR review is the second pair of eyes.          |
| Drafts                        | No. Save equals submit.                                        |
| Git on the server             | Not required, and deliberately not reintroduced.               |

### Why no overlay

An earlier iteration of this design kept edits live by writing them to an rsync-excluded overlay
directory resolved ahead of the base tree. Dropping "live immediately" removes all of it:

- the overlay path-resolution layer
- whiteout tombstones for the `unlink()` delete paths in `RegionalDataHandler`
- merged directory listings, so that `glob()`/`scandir()` sites report base ∪ overlay − whiteouts
- an APCu base/overlay cache-key collision hazard in `Utilities::jsonFileToObject()`
- retiring the per-worker memoisation of `CalendarHandler::engineCacheDataVersion()`
- base↔overlay divergence detection, and reverting the live site when a PR is closed

Most importantly it means **the deploy pipeline is untouched**. No new exclusion in
`rsync-exclude.txt`, no new allowlist entry, and no exposure to the fossilizing gotcha documented
at the top of that file.

### Why the server does not need git

Three independent reasons:

1. The GitHub Git Data API creates commits with no working tree: blob → tree → commit → ref → PR,
   all over HTTPS. `guzzlehttp/guzzle ^7.10` and `firebase/php-jwt ^6.11 || ^7.0` are already
   required in `composer.json`, so there is no new dependency.
2. A GitHub App installation token is short-lived (one hour), auto-expiring, and scoped to a
   single repository — strictly safer than an SSH key with push rights on an internet-facing host.
3. Reintroducing a working tree would undo the deploy hardening that
   `check-deploy-payload.sh` exists to enforce.

## Deployment modes

The API is self-hostable. A diocese or bishops' conference running it without Zitadel, OpenFGA and
Postgres must keep working exactly as it does today — `Router.php:775` already branches on
`Connection::isConfigured()` ("Without a database, API key validation is unavailable"), and
`AuthorizationMiddleware` gates `/data` writes on JWT roles rather than OpenFGA. A Postgres-less
authoring deployment is a supported shape, not a hypothetical one.

So source-data writes go through a `SourceDataWriter` seam with two implementations:

| Mode      | Implementation                  | Behaviour                                             |
| --------- | ------------------------------- | ----------------------------------------------------- |
| **disk**  | `DiskSourceDataWriter`          | Today's `file_put_contents()` / `unlink()`, unchanged |
| **queue** | `ChangeRequestSourceDataWriter` | Stages files, submits a batch, never touches disk     |

Selection:

```php
$queueMode = 'true' === strtolower(trim($_ENV['SOURCEDATA_CHANGE_REQUESTS'] ?? 'false'))
    && Connection::isConfigured()
    && OpenFgaClient::isConfigured();
```

Default **false**, so nothing changes for an existing deployment until it is turned on. The
capability checks are not redundant with the flag: queue mode needs OpenFGA because
`ChangeRequestReview::administers()` fails closed, and without it every edit would queue with nobody
able to approve one. A flag set without the stack behind it logs and falls back to disk rather than
returning 500s.

**Health warning.** Disk mode on a deployment that _does_ have the full stack means someone forgot
the flag on a host that rsyncs `--delete` from git — the silent revert this design exists to
eliminate. `Health` reports that combination as a warning. Disk mode with no stack present is
reported as normal, because that is a self-hosted instance behaving correctly.

**This is a compatibility floor, not the federated answer.** A self-hosting diocese authoring to
local disk has forked, not federated: its edits are invisible upstream and its calendar drifts from
the canonical one. The eventual federated shape is a third `SourceDataWriter` that submits change
requests to the upstream canonical API instead of writing locally. That is why the seam is an
interface rather than a boolean scattered through ten call sites. It is **not** built in this design.

## Architecture

```text
  editor / admin
        │  PUT/PATCH/POST  (unchanged: schema validation, OpenFGA authz, payload construction)
        ▼
  RegionalDataHandler / DecreesHandler / TestsHandler
        │  insert instead of file_put_contents()
        ▼
  sourcedata_change_request           review_status: submitted
        │
        │  admin approves (auto-approved when the submitter administers the resource)
        ▼
  sourcedata_publish_outbox           decided intent — reuses the existing outbox machinery
        │
        │  SourceDataPublishProcessor  (implements OutboxProcessorInterface)
        ▼
  GitHub: branch per resource, one commit per change request, rolling PR
        │
        │  human merges to development
        ▼
  deploy.yaml → staging  →  jsondata/sourcedata on the server
```

### The change request store is not an outbox

An outbox holds durable intent to perform an already-decided action; its rows must eventually
succeed. A change request can be **rejected**, so it does not belong in that machinery.

An outbox does appear, at exactly one step: once an administrator approves, "push this to GitHub"
_is_ decided intent, and gets a row that reuses `ConsumerLoop`, `BackstopRunner`, `OutboxBackoff`
and `OutboxClassifier` unchanged.

## Data model

### `sourcedata_change_request`

Sibling of the existing `access_requests` table (`src/Migrations/Version20260518120000.php:83`),
which already models a request with a status, an admin approval, and RBAC-scoped visibility.

| Column                        | Type         | Notes                                                           |
| ----------------------------- | ------------ | --------------------------------------------------------------- |
| `id`                          | BIGSERIAL    | primary key                                                     |
| `resource_id`                 | TEXT         | via `RiteScopedObjectId`; also keys the rolling PR branch       |
| `path`                        | TEXT         | repo-relative path of the file being changed                    |
| `operation`                   | ENUM         | `create` \| `update` \| `delete`                                |
| `content`                     | TEXT NULL    | full proposed file body; NULL for `delete`                      |
| `base_sha`                    | TEXT NULL    | blob sha the edit was authored against; drives the rebase check |
| `submitted_by_sub`            | TEXT         | Zitadel `sub` — the durable identity key                        |
| `submitted_by_name`           | TEXT NULL    | git author name                                                 |
| `submitted_by_email`          | TEXT NULL    | git author email                                                |
| `submitted_by_email_verified` | BOOLEAN      | gates use of the email; see Security                            |
| `review_status`               | ENUM         | `submitted` \| `approved` \| `rejected` \| `withdrawn`          |
| `publication_status`          | ENUM         | `none` \| `queued` \| `open` \| `merged` \| `closed`            |
| `approved_by_sub`             | TEXT NULL    | equals `submitted_by_sub` on admin self-approval                |
| `approved_at`                 | TIMESTAMPTZ  |                                                                 |
| `rejected_reason`             | TEXT NULL    |                                                                 |
| `pr_number`                   | INTEGER NULL | filled by the publisher                                         |
| `branch`                      | TEXT NULL    | filled by the publisher                                         |
| `commit_sha`                  | TEXT NULL    | filled by the publisher                                         |
| `merge_commit_sha`            | TEXT NULL    | filled by merge polling                                         |
| `metadata`                    | JSONB        | authorising OpenFGA relation, schema-validation result          |
| `created_at`                  | TIMESTAMPTZ  |                                                                 |
| `updated_at`                  | TIMESTAMPTZ  |                                                                 |

Content is stored as the **full proposed file body**, not a patch: the write handlers already
build a complete document via `JsonFormatter::encode($rawPayload)`
(`src/Handlers/RegionalDataHandler.php:391` and siblings), so the whole file is what they hand us.

**Review and publication are deliberately two columns, not one enum.** Flattening them makes
"approved but the GitHub push failed", "approved, PR open, awaiting review" and "merged but not
yet released to production" indistinguishable in the admin UI.

Indexes:

- `(review_status, created_at)` — the admin queue
- `(submitted_by_sub, created_at DESC)` — an editor's own history
- `(resource_id, review_status)` — per-resource views and the publisher's serialisation
- unique partial on `(path, submitted_by_sub) WHERE review_status = 'submitted'` — enforces
  save-equals-submit updating in place rather than stacking rows

### `sourcedata_publish_outbox`

Mirrors `openfga_outbox` (`src/Migrations/Version20260602202504.php:38`): `status`, `attempts`,
`next_attempt_at`, `last_error`, `last_error_code`, `metadata` JSONB with an idempotency key, and
the same partial indexes for pickup and DLQ. Adds `change_request_id` and `resource_id`.

## Lifecycle

```text
editor saves ──→ submitted ──→ (admin approves) ──→ approved
admin saves  ──→ submitted ──→ auto-approved     ──→ approved
                     │                                  │
                     └──→ rejected / withdrawn      outbox row
                                                        │
                             queued ──→ open ──→ merged │ closed
```

Terminology: `published` is the user-facing term for a change that has reached the repository.
`accepted` is avoided because an administrator already _accepted_ it at the approval gate.

Because `deploy.yaml` deploys every push to `development` to staging, `merged` **is** "live on
staging" with no additional tracking. Production deploys from tagged releases, so a merged change
may not be on production yet; phase one states this in UI copy rather than tracking it. See
"Deferred".

## Write path changes

Per call site, `file_put_contents(...)` becomes a `stage()` call on the injected
`SourceDataWriter`, and each request path ends with one `commit()`. Everything upstream is
unchanged: schema validation, OpenFGA authorization, and payload construction all stay exactly as
they are — and so does the on-disk behaviour, which now lives in `DiskSourceDataWriter` rather than
inline in the handlers.

| Handler               | Sites                                           |
| --------------------- | ----------------------------------------------- |
| `RegionalDataHandler` | 7 writes, 3 `unlink()` → `operation = 'delete'` |
| `DecreesHandler`      | 4 writes                                        |
| `TestsHandler`        | test-definition writes                          |

The `unlink()` paths (`RegionalDataHandler.php:1108,1124,1302`) become delete-operation rows. This
is the case the overlay design needed whiteout tombstones for; here it is just an enum value.

**Note on test definitions.** They are in scope, but they live under `jsondata/tests`, not
`jsondata/sourcedata` (see the comment on `JsonDataConstants::ROMAN_TESTS_FOLDER`). The `path`
column is repo-relative, so this costs nothing — but `resource_id` derivation must handle the
`tests` partition, and `TestScopeResolver` is the authority for that scoping, not
`RiteScopedObjectId` alone.

**Response shape.** A write no longer necessarily means "this is now the state of the calendar", so
every write response carries a `disposition` discriminator:

```jsonc
// disk mode — the existing body, plus one field
{ "…existing resource body…", "disposition": "applied" }

// queue mode
{ "disposition": "submitted", "change_request": { "batch_id": "…", "review_status": "submitted", … } }
```

Because disk mode's body is otherwise byte-identical to today's, **this is not a breaking change for
an existing deployment** — with one deliberate exception, also carved out in `CHANGELOG.md`:
`DELETE /tests/{rite}/{test_name}` moves from `204 No Content` to `200` with a body, in disk mode
too, because a 204 cannot carry `disposition`. That aligns it with `deleteCalendar()`, which already
returns 200 with a body. The OpenAPI document gains the discriminator and the queue-mode variant;
frontend editors switch on one field.

## Authorization and visibility

The visibility rules are an OpenFGA query, not new machinery:

| Actor          | Sees                                                          |
| -------------- | ------------------------------------------------------------- |
| Editor         | their own change requests and their own history               |
| Resource admin | all change requests and history for resources they administer |
| Zitadel admin  | everything                                                    |

`ResourceAdminService::filterByAdminAccess(array $requests, string $adminUserId)` already
implements exactly this filtering for `access_requests` and generalises directly.
`resolveScopes()`, `resolveViewerScopes()` and `resolveTestScopes()` cover the scope resolution.

Approval requires the `admin` relation on the resource. A submitter holding that relation is
auto-approved at submit time; `approved_by_sub` equals `submitted_by_sub`, which makes
self-approval visible in the audit trail and in the generated PR body without extra work.

Every transition appends to the existing `audit_log` table.

## Admin interface (frontend)

Phase 1 adds two views. Both are served by the same RBAC-scoped endpoints, so the API decides what
each actor may see and the UI renders whatever it is handed — there is no client-side filtering.

- **Pending queue** — change requests with `review_status = submitted`, with approve and reject
  actions. `permission-requests.php` is the closest existing analogue: a request queue with an
  approve/reject flow and admin scoping already built.
- **History** — change requests in any state, filterable by resource and submitter, showing
  `review_status` and `publication_status` separately, plus a link to the pull request once one
  exists.

Editors see both views scoped to their own submissions, resource admins to the resources they
administer, Zitadel admins to everything.

New pages follow the existing convention: `admin-changes.php` with
`assets/js/admin-changes.js` extending `admin-module-base.js`.

The resource filter should use `CalendarResourcePicker`, not a hand-wired `RiteSelect` plus
`CalendarSelect` — the frontend `CLAUDE.md` records three call sites still hand-wiring that pair
as debt awaiting migration, explicitly not a pattern to copy. A hand-wired `RiteSelect` needs two
wires and silently misroutes every request to `/calendar/roman/` if only the first is made.

Existing editors need updating too: the calendar, decree and test forms currently expect a write
to return the resource that was written. They must now render "submitted for review", or
"approved, queued for publication" when the submitter is an administrator of the resource.

## Phase 2: the publisher

### Credential

A GitHub App, not a personal access token. Permissions: Contents RW, Pull requests RW, Metadata R,
on the one repository. The App JWT is RS256-signed with `firebase/php-jwt`; the resulting
installation token is valid one hour and cached in the existing PSR-6 `/cache` (already used for
JWKS and Zitadel roles), refreshed at roughly 50 minutes.

The App private key is referenced by path and stored **outside the deployed tree**. The nginx
location has since been narrowed to `public/`, but a signing key should not depend on that
narrowing.

### Publishing one approved change

```text
GET   /repos/{o}/{r}/git/ref/heads/{branch}   404 → create from heads/development
POST  /repos/{o}/{r}/git/blobs                the proposed content
POST  /repos/{o}/{r}/git/trees                base_tree = parent commit's tree
                                              delete = entry with sha: null
POST  /repos/{o}/{r}/git/commits              author    = the editor
                                              committer = the GitHub App
PATCH /repos/{o}/{r}/git/refs/heads/{branch}  force: false
POST  /repos/{o}/{r}/pulls                    only when none is open for this head
```

`force: false` turns the per-resource race into a retryable error rather than silent data loss: a
non-fast-forward update fails instead of clobbering.

One commit per change request, not batched. Batching would be fewer API calls but would merge two
editors into a single commit and lose the per-editor authorship this design exists to preserve.

Branch names derive from `resource_id`, e.g. `litcal-data/roman/nation/USA`. Stable per resource,
so the rolling PR falls out for free.

The PR body is generated: each included change with submitter, approver, timestamp, and the
authorising OpenFGA relation from `metadata`.

### Two landmines in the accumulation base that only phase 2 can defuse

Phase 1's accumulation base — "the submitter's rows that are not yet in the repository",
`review_status IN ('submitted','approved') AND publication_status <> 'merged'` — is correct only for as
long as nothing ever reaches `merged`, which in phase 1 is always. Both of the following are latent, not
present bugs, and neither can be fixed before there is a publisher; both must be settled as part of
building one.

#### A merged batch's own unmerged ancestor becomes a stale base

Accumulation makes each batch the submitter's cumulative proposal, so a later batch's content already
contains an earlier one's. Publication then marks batches merged one at a time, and the earlier one is
never marked:

1. Batch A is approved. Its `decrees.json` row holds decree A.
2. Batch B is submitted; it accumulated onto A, so its `decrees.json` row holds A **and** B. It is
   approved.
3. Phase 2 publishes B and sets `publication_status = 'merged'` on B's rows. A's rows are untouched:
   they are still `approved`, still `none`.
4. A is now the newest row in the base for that path — B has left it — so the next submission rebuilds
   `decrees.json` from A's content and silently reverts everything B added.

Note the ordering makes this worse rather than better: `( review_status = 'submitted' ) DESC` puts a
submitted row first, and A and B are both approved, so the tie falls to `created_at DESC` — and A, being
older, only wins here because B has been excluded. Whichever way it is fixed, the rule needed is
"exclude anything superseded by published content", not "take the newest unpublished row".

Two candidate fixes, to be decided when the publisher is built:

- mark the ancestors merged too — publishing a batch marks every older row for the same
  `(path, submitter)` merged, since its content is contained in what was just published; or
- exclude rows older than the newest merged row for that `(path, submitter)`, leaving the ancestors'
  status alone and making the base `created_at`-aware instead.

The first is simpler to read in SQL; the second does not depend on the containment assumption holding for
every future handler. Neither is free: the containment assumption is exactly what accumulation guarantees
today, but a handler that stages a path _without_ reading its own unpublished content first would break
it, and nothing enforces that it must.

**Decided (2026-08-29): the second.** The base excludes any row older than the newest `merged` row for
that `(path, submitter)`; no ancestor's status is rewritten.

The deciding difference is not the SQL but what each option _writes_. Marking ancestors `merged` asserts
that they were published. Under containment that is effectively true — their bytes are inside the
published batch — but if containment ever breaks, the publisher, which selects approved rows that are not
yet `merged`, skips a batch that was never published and its content is silently lost. Excluding by age
rewrites nothing: the ancestor stays `approved` / `none`, so it remains visible, remains truthful, and
remains publishable. The failure mode degrades from losing data to choosing a suboptimal rebuild base.

That asymmetry decided it. Every serious defect on this branch was an invariant that was asserted but not
enforced — the migration comment claiming one pending proposal per path, the repository docblock naming a
decree case it had not fixed, the `#[CoversClass]` list that silently discarded real coverage. Containment
is another unenforced assertion, so the option that does not depend on it wins.

**The decrees layout stays as it is.** Splitting `decrees.json` into one file per decree was considered
and rejected for now: the `i18n/<locale>.json` and `lectionary/<locale>.json` sidecars are aggregates too,
across fourteen locales, so a decree edit would still rewrite shared files and the collision would remain.
Removing it entirely means per-decree per-locale files — roughly 15 × 14 and growing — against ten source
files that read `decrees.json`, plus schemas, the frontend editor and a data migration. Two facts also
narrow the problem: the unique index is `(path, submitted_by_sub)`, so two editors editing two different
decrees never collide, and APCu already caches the aggregate. The real argument for splitting is
git-level: two rolling PRs both touching `decrees.json` will conflict even though the database rows do
not. `force: false` turns that into a retryable error rather than data loss, so phase 2 can find out
safely; revisit the layout only if it proves painful in practice.

#### `publication_status <> 'merged'` admits `closed`

The predicate excludes `merged` and nothing else, but `chk_scr_publication_status` also allows `closed` —
phase 3 sets it when a PR is closed unmerged (and sets `review_status = 'rejected'` with it). An approved
batch whose PR was closed unmerged therefore stays in the accumulation base forever, since `closed` is not
`merged`.

`review_status = 'rejected'` happens to keep it out of the base today, because the base filters review
status as well. That is a coincidence of phase 3's current design, not a decision: the predicate's
justification (recorded in `SourceDataChangeRequestRepository`'s class docblock) reasons only about
`merged` content being the repository, and never considered `closed` at all.

**Decided (2026-08-29):** phase 2 does not change this predicate, because phase 2 never writes `closed` —
only phase 3 does. Phase 3 must state explicitly whether `closed` belongs in the exclusion and stop
relying on the review-status filter to carry it. Phase 2's age-based ancestor exclusion, decided above,
is independent of this and does not depend on `closed` being handled either way.

### Reuse and failure handling

**Superseded by what was built (2026-08-29).** This section originally proposed reusing the outbox
machinery wholesale. That did not survive contact with the code, and the paragraphs below record what
exists so phase 3 is not designed against a fiction.

`BackstopRunner` constructor-types the concrete `OutboxRepository`, and `ConsumerLoop` needs a Redis
stream plus an integer row id — while the unit of publication is a **batch**. The outbox is also
OpenFGA-shaped down to its enum, whose only cases are `write_tuple` and `delete_tuple`. Of the three,
only `OutboxBackoff::secondsForAttempt()` was genuinely reusable. There is likewise no second queue
table: `sourcedata_change_requests` already carries `publication_status` and the `branch`,
`commit_sha`, `pr_number` and `base_sha` columns, so it is the queue.

What exists instead is `PublishRunner`, a cron-driven loop that claims one batch at a time
(`FOR UPDATE SKIP LOCKED` inside one transaction, with the claimability predicate repeated on the
lock query), publishes it, and on **any** `\Throwable` logs, releases the claim and stops rather than
hammering a failing API. There is no `OutboxDisposition` mapping and no dead-letter queue: a failed
batch simply returns to `none` and is retried on the next tick. Note the status codes differ from the
table this replaced — a non-fast-forward `updateRef` is **422**, not 409.

Serialisation per resource is achieved by `force: false` rather than by an advisory lock. Two runners
publishing different batches of the same resource both target one branch; the loser gets a
non-fast-forward 422, releases its claim, and retries against the moved ref. The claim itself is not
per-resource, so this is serialisation by optimistic concurrency, not by mutual exclusion — which is
why `force: false` is load-bearing rather than merely defensive.

A crash between claim and record leaves a batch `queued`, which no exception handler can catch, so
`PublishRunner` reclaims batches idle longer than `DEFAULT_GRACE_SECONDS`. That grace must exceed a
whole publish's worst case — `maxRequestsPerBatch × requestTimeout`, since a publish issues one
`createBlob` per changed file serially — not a single request's timeout.

### Side effects a merged deletion must still perform

In disk mode, `RegionalDataHandler::deleteCalendar()` does two things beyond removing the calendar
and i18n files: it `rmdir()`s the now-empty calendar (and, for a diocese, nation) folder, and it
purges the resource's OpenFGA editor/viewer operational tuples via `ResourceTuplePurgeServiceInterface`
(the admin/governance tuple is deliberately retained, so the resource can be recreated without
losing ownership). Both run only when the deletion actually landed on disk — gated on
`commit()`'s `disposition === 'applied'`, i.e. `DiskSourceDataWriter`'s return value — because in
queue mode nothing has been removed yet; see the gate in `deleteCalendar()` around its
`commitStagedFiles()` call.

In queue mode today, neither runs at submit/approve time, and **that gap is not closed by Phase 2 or
Phase 3 as designed so far**. Once a deletion batch is published, merges, and a redeploy replaces
`jsondata/sourcedata` from the merged tree, the file-level side effect (the calendar and i18n files,
and their now-empty directories) is naturally covered — redeploy IS the disk-mode `rmdir()`
equivalent, just accomplished by rsync rather than a syscall. But the OpenFGA purge is an
**in-process authorization action against a live service, not a file artifact**. Nothing in the
publish-PR-merge-redeploy path touches OpenFGA. A deployment's file tree can be perfectly in sync
with `main` while stale editor/viewer tuples for a deleted calendar remain live in OpenFGA
indefinitely, because no step between "PR merged" (Phase 2/3) and "next redeploy" (infrastructure,
outside this design) ever calls `purgeForObject()`.

**Decided (2026-08-29): Phase 3, at merge detection.** Only merge detection knows the deletion actually
happened — publishing a PR does not make it true, and a PR can still be closed unmerged. Purging at
publish time would revoke real authorization on the strength of a proposal. This also matches how the
file side already works: the redeploy that follows a merge is what removes the files, so authorization
and files become true at the same moment rather than drifting apart in opposite directions.

The cost is explicit and accepted: **until phase 3 ships, a calendar or test deleted through a change
request keeps its OpenFGA tuples live, so its former editors retain edit access on an object whose files
are gone.** That is a known limitation to be stated in the phase 2 runbook, not a surprise to be
discovered later. Phase 2 must not silently appear to have closed it.

The original requirement, which phase 3 inherits unchanged:

**Requirement for Phase 2 (or, if merge detection ends up the more natural trigger, Phase 3):** when
a `delete`-operation change request's batch is applied — either at publish time if publishing is
made effectively synchronous with disk truth, or at merge-detection time in Phase 3, whichever phase
actually knows the deletion is live — the publisher/merge-poller MUST perform the same OpenFGA
operational-tuple purge `deleteCalendar()` performs today in disk mode, using the same resource-to-object
mapping (`RegionalDataHandler::fgaObjectForRequest()` / `changeResourceForRequest()`). It should also
consider whether the diocese-nation-folder emptiness check has any equivalent worth preserving, though
that one is more plausibly subsumed by redeploy alone. This cannot be inferred from the existing
Phase 2/3 design as written — it was written before this gap was identified — so it is recorded here
rather than left to be rediscovered when a deleted diocese's former editors turn out to still have
edit access.

**Addendum — the same gap exists for test definitions.** Task 11 (`TestsHandler`) introduced the
identical gate on its own `handleDeleteRequest()`: the post-delete `ResourceTuplePurgeServiceInterface`
purge there is likewise gated on `commit()`'s `disposition === 'applied'`, using
`TestsHandler::changeResourceForTest()` as its resource-to-object mapping (the test-definition analogue
of `RegionalDataHandler::changeResourceForRequest()`). In queue mode, deleting a test via a change
request leaves its operational tuples live in OpenFGA for exactly the same reason `deleteCalendar()`'s
do — nothing between "PR merged" and "next redeploy" calls `purgeForObject()` for it either. Phase 2 (or
Phase 3) must perform this purge for both `RegionalDataHandler`'s calendar deletions and
`TestsHandler`'s test-definition deletions when a delete-operation batch is applied.

## Phase 3: merge detection

Polling, not webhooks. `GET /repos/{o}/{r}/pulls/{number}` returns `merged`, `merged_at` and
`merge_commit_sha`; it runs on the consumer's idle tick and on the existing five-minute cron
backstop.

A webhook would require a new public route, HMAC verification, and a second authentication mode on
`/_ops` (currently deploy-token gated) — real attack surface for a transition that is not
latency-sensitive. A missed webhook is a silently stuck row; a poll is self-healing.

On merge: `publication_status = merged`, record `merge_commit_sha`, notify the submitter through
the existing `user_notification_state` mechanism, append to `audit_log`.

On closed-unmerged: `publication_status = closed`, `review_status = rejected`, notify. **Nothing
needs reverting, because nothing was ever live.**

## Error handling

| Failure                                           | Behaviour                                                            |
| ------------------------------------------------- | -------------------------------------------------------------------- |
| Schema-invalid payload at submit                  | Rejected at the handler, as today — no row created                   |
| `base_sha` moved between submit and approve       | **Phase 2.** Approval blocked; admin shown the diff and must rebase  |
| Schema drift between submit and approve           | **Phase 2.** Re-validated at the gate; approval blocked on failure   |
| GitHub unreachable                                | Outbox retry with existing backoff; change stays `approved`/`queued` |
| Publish terminal failure                          | DLQ row; surfaced in the admin UI with the GitHub error              |
| Two editors submit against the same path          | Distinct rows; the unique partial index scopes it per submitter      |
| Resource admin deleted between submit and approve | Approval re-checks OpenFGA; a stale scope cannot approve             |

## Testing

- Unit: change-request state transitions; `resource_id` derivation across rites and the `tests`
  partition; verified/unverified email fallback for the git author.
- Unit: `SourceDataPublishProcessor` disposition mapping, against a mocked GitHub client.
- Unit: mode selection — flag off, flag on with a full stack, and flag on with the stack missing
  (which must fall back to disk, not throw).
- Integration: in queue mode, handler write paths create rows and touch no files — assert
  `jsondata/sourcedata` is byte-identical after a write request.
- Integration: in disk mode, the existing on-disk write behaviour is unchanged. These are the
  current handler tests, retargeted at `DiskSourceDataWriter` rather than deleted.
- Integration: RBAC-scoped listing and history for each of the three actor classes.
- Integration: advisory-lock serialisation — two concurrent publishes for one resource.
- E2E (frontend, `rbac` project): submit → approve → row reaches `queued`.

## Security

- **Only set the git author email when `email_verified` is true.** Otherwise anyone able to set an
  email in Zitadel could forge commit authorship of a third party in a public repository. Unverified
  falls back to a `…@users.noreply` form keyed on `sub`.
- The App private key lives outside the deployed tree.
- Installation tokens are cached in `/cache`, which is already rsync-excluded and outside
  `public/`.
- The generated PR body includes the authorising OpenFGA relation, so a reviewer can see why the
  submitter was permitted to make the change.

## Phasing

| Phase | Content                                                                                |
| ----- | -------------------------------------------------------------------------------------- |
| 1     | `SourceDataWriter` seam, change requests, approval, RBAC admin UI, history. No GitHub. |
| 2     | GitHub App, `SourceDataPublishProcessor`, rolling PRs.                                 |
| 3     | Merge polling, status transitions, notifications.                                      |
| 4     | Preview: compute a calendar in memory with a pending change applied.                   |

Phase 1 is independently valuable: once `SOURCEDATA_CHANGE_REQUESTS` is enabled on a deployment,
the silent revert-on-deploy data loss is fixed, even with approved changes simply sitting as
approved and no publisher yet. Until it is enabled, and on every self-hosted instance, behaviour is
exactly what it is today.

## Deferred

- **Production deployment visibility.** `merged` equals live on staging. Production deploys from
  tagged releases, so a merged change can lag there. Phase one states this in UI copy; tracking it
  properly means comparing `merge_commit_sha` against the running deployment's sha.
- **GitHub account linkage.** A commit author email links to a GitHub profile only if it is
  registered on that account. Optional per-user `github_login` would give avatars and mentions on
  the PR.
- **Preview.** Request-scoped, read-only, in-memory application of a pending change to the calendar
  engine. Easy precisely because there is no overlay: a parameter, not a storage layer.
- **i18n and Weblate.** Calendar i18n files are in scope as data, but they overlap Weblate's
  territory. How the two paths coexist is not settled here.
- **Federated upstream submission.** A third `SourceDataWriter` that submits change requests to the
  upstream canonical API, so a self-hosting diocese pools its edits rather than forking to local
  disk. The interface exists for this; the implementation does not.
- **OpenFGA operational-tuple purge for merged deletions.** See "Side effects a merged deletion must
  still perform" under Phase 2 — the redeploy that follows a merge covers the file side of a
  deletion but never calls OpenFGA, so a merged deletion's editor/viewer tuples stay live until
  Phase 2 or 3 grows an explicit purge step.

## Out of scope

- Restoring a git working tree on the server.
- Changing `rsync-exclude.txt` or `check-deploy-payload.sh`.
- Moving source data into Postgres as the canonical store.
