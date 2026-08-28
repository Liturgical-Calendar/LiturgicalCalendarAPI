# Source-data change requests: authoring edits on the server, publishing them to GitHub

**Status:** design approved, not yet implemented
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

Per call site, `file_put_contents(...)` becomes "insert or update a change request". Everything
upstream is unchanged: schema validation, OpenFGA authorization, and payload construction all stay
exactly as they are.

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

**Response shape changes.** A write no longer means "this is now the state of the calendar". The
handlers must return the change request (id, review status, and whether it was auto-approved)
rather than the written resource. This is a breaking change to the write endpoints and needs to be
reflected in the OpenAPI document and in the frontend editors.

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

### Reuse and failure handling

`ConsumerLoop` takes `OutboxProcessorInterface` by constructor injection
(`src/Services/Outbox/ConsumerLoop.php:26`), so `SourceDataPublishProcessor` drops in behind a
second Redis stream and a second systemd unit, with `BackstopRunner` covering the cracks.

| GitHub response          | `OutboxDisposition`                         |
| ------------------------ | ------------------------------------------- |
| 409 non-fast-forward     | `RETRY` — re-read the ref, rebuild the tree |
| PR already open for head | `BENIGN_SUCCESS`                            |
| 5xx, 429, network        | `RETRY` — existing backoff                  |
| 422 validation           | `TERMINAL` — DLQ, surfaced in the admin UI  |

Publishing must be **serialised per resource**: two approved changes to one calendar append to the
same branch. The existing idempotency-key unique index does not provide ordering, so the processor
takes a Postgres advisory lock keyed on `resource_id` for the duration of a publish.

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
| `base_sha` moved between submit and approve       | Approval blocked; admin is shown the diff and must request a rebase  |
| Schema drift between submit and approve           | Re-validated at the gate; approval blocked on failure                |
| GitHub unreachable                                | Outbox retry with existing backoff; change stays `approved`/`queued` |
| Publish terminal failure                          | DLQ row; surfaced in the admin UI with the GitHub error              |
| Two editors submit against the same path          | Distinct rows; the unique partial index scopes it per submitter      |
| Resource admin deleted between submit and approve | Approval re-checks OpenFGA; a stale scope cannot approve             |

## Testing

- Unit: change-request state transitions; `resource_id` derivation across rites and the `tests`
  partition; verified/unverified email fallback for the git author.
- Unit: `SourceDataPublishProcessor` disposition mapping, against a mocked GitHub client.
- Integration: handler write paths create rows and touch no files — assert
  `jsondata/sourcedata` is byte-identical after a write request.
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

| Phase | Content                                                                 |
| ----- | ----------------------------------------------------------------------- |
| 1     | Change requests, approval, RBAC-scoped admin UI and history. No GitHub. |
| 2     | GitHub App, `SourceDataPublishProcessor`, rolling PRs.                  |
| 3     | Merge polling, status transitions, notifications.                       |
| 4     | Preview: compute a calendar in memory with a pending change applied.    |

Phase 1 is independently valuable: the moment the handlers stop writing to disk, the silent
revert-on-deploy data loss is fixed, even with approved changes simply sitting as approved.

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

## Out of scope

- Restoring a git working tree on the server.
- Changing `rsync-exclude.txt` or `check-deploy-payload.sh`.
- Moving source data into Postgres as the canonical store.
