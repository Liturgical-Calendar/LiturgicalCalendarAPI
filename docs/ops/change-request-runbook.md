# Source-data change requests — operator runbook

## What this is

`sourcedata_change_requests` (Postgres) holds proposed edits to `jsondata/sourcedata` — the calendar,
decree, and unit-test source files a `PUT`/`PATCH`/`POST`/`DELETE` write request would otherwise apply
straight to disk. On a deployment with the queue enabled, a caller who does not administer the targeted
resource has their write recorded as a row (or several rows, grouped into one **batch**) here instead of
touching the filesystem; a resource admin reviews the batch and approves or rejects it. A caller who
already administers the resource still gets a batch — it is just auto-approved in the same request.

See `docs/superpowers/specs/2026-08-28-sourcedata-change-requests-design.md` for the full design, and
`.superpowers/sdd/2026-08-28-sourcedata-change-requests-phase1/` for the phase 1 implementation plan.

**Phase 1 stops at approval.** There is no publisher yet: an approved batch sits at
`publication_status = 'none'` indefinitely — nothing opens a pull request, nothing writes to GitHub, and
`jsondata/sourcedata` on disk is never touched by queue-mode writes at all. The Phase 2 GitHub App and
`SourceDataPublishProcessor` are what will eventually walk approved batches and turn them into commits.
Until that lands, "approved" means "recorded as approved in Postgres," full stop — treat it as a durable
staging area, not as a change that has taken effect anywhere a calendar consumer can see.

## Which write mode a deployment is in

Every write handler under `/data`, `/decrees` and `/tests` goes through `SourceDataWriteMode` (in
`src/Services/SourceData/SourceDataWriteMode.php`), which decides between two writers:

- `DiskSourceDataWriter` — writes files straight to `jsondata/sourcedata`, exactly as the API has always
  done. This is what runs whenever the flag below is unset, or when it is set but the stack behind queue
  mode is not available.
- `ChangeRequestSourceDataWriter` — records a batch in `sourcedata_change_requests` and touches no files.

The flag is `SOURCEDATA_CHANGE_REQUESTS` (an environment variable, checked case-insensitively against the
literal string `true`). Queue mode requires the flag **and** both of the following to be reachable:
Postgres (`Connection::isConfigured()`) and OpenFGA (`OpenFgaClient::isConfigured()`). The flag alone is
never enough — a deployment that queues change requests nobody could ever review, because there is no
OpenFGA behind it to decide who is an admin, would be worse than not queuing them at all.

Query the deployment's actual mode with:

```bash
curl -s http://localhost:8000/health | jq .source_data_writes
```

The `source_data_writes` block reports one of four states:

- `status: "ok"`, message `source data writes are recorded as change requests` — queue mode: flag set and
  stack available.
- `status: "ok"`, message `source data writes go to disk (no change request stack configured)` — disk
  mode: flag unset, no stack configured either.
- `status: "warning"` — the flag is set but Postgres or OpenFGA is not configured. **Falls back to disk
  mode**; writes still succeed, and this warning is `Health`'s way of flagging the misconfiguration so it
  does not go unnoticed.
- `status: "warning"` — the flag is unset but the stack IS available. Writes still go to disk. Almost
  always a forgotten flag on a host that deploys by `rsync --delete` from git, where the next deploy
  silently reverts an edit nobody meant to make transient.

The fallback in the first `warning` case is deliberate: a misconfigured queue must never turn into a 5xx
for every editor's write, or into edits accepted and then quietly lost. It writes to disk, exactly as
before this feature existed, and leaves a `warning` in `/health` for an operator to notice.

Every write response also carries the same signal per-request, as a `disposition` field: `applied` (disk
mode), `submitted` (queue mode, not auto-approved), or `approved` (queue mode, auto-approved). See the
`ChangeRequestDisposition` schema in `jsondata/schemas/openapi.json` for the full contract.

## One pending proposal per file, per submitter

Queue mode keeps **at most one pending proposal per `(file path, submitter)`** — the invariant
`idx_scr_unique_pending_path_submitter` enforces. Submitting a write that stages a path the same submitter
already has pending therefore *supersedes* the batch that path belonged to. Deletion is whole-batch, never
per-row, because a batch is approved or rejected as a unit and a half-batch would be incoherent.

Two consequences an operator will meet:

1. **Superseding can sweep up files the new request never mentioned.** If a batch staged both
   `decrees.json` and `decrees/i18n/de.json`, a later request staging only the former supersedes the whole
   batch. This is never silent: every queue-mode write response lists the batch ids it replaced in
   `change_request.superseded_batch_ids`, and a listed id no longer appears in `GET /auth/change-requests`.

2. **Superseding an aggregate file is accumulation, not replacement.** Some resources are stored as one
   file holding many editable items — the entire decree corpus is one `decrees.json`, and every decree
   translation for a locale is one `decrees/i18n/<locale>.json`. Handlers rebuilding one of those read the
   submitter's own pending content for that path first (`SourceDataChangeRequestRepository::findPendingContent()`,
   reached through `WritesSourceData::pendingSourceContent()`), falling back to disk when there is none. So
   the single pending batch is always that submitter's *cumulative* proposal, and a reviewer approves or
   rejects the whole current state rather than a chain of increments.

   Without this, submitting decree B would rebuild `decrees.json` from disk — where decree A never landed,
   because a proposal is not a file — and decree A would be gone behind a `201`. Disk mode is unaffected:
   there is no pending state there, the lookup always answers "nothing", and every read is the disk read it
   has always been.

The lookups are scoped to `(path, submitted_by_sub, review_status = 'submitted')`. One submitter never
reads or supersedes another's pending work, and an approved, rejected or withdrawn proposal is never read
back — a rebuild after a decision starts from disk again.

To see what a submitter currently has pending for a given path:

```sql
SELECT batch_id, operation, LENGTH(content) AS bytes, created_at
FROM sourcedata_change_requests
WHERE path = '<repo-relative-path>'
  AND submitted_by_sub = '<sub>'
  AND review_status = 'submitted';
```

That query returns at most one row. More than one means the unique index is missing or disabled.

## Review status vs. publication status

Every row (and therefore every batch, since a batch shares these columns across all its rows) carries two
separate status columns, and conflating them is the most common way to misread this table:

- **`review_status`** — OUR review workflow: `submitted` → `approved` / `rejected` / `withdrawn`. This is
  what `POST /admin/change-requests/{batchId}/approve|reject` and
  `POST /auth/change-requests/{batchId}/withdraw` move.
- **`publication_status`** — GitHub's side of the same change: `none` → `queued` → `open` → `merged` /
  `closed`. Phase 1 writes only `none`; the rest are reserved for the Phase 2 publisher (moves to `queued`
  once a PR is being opened, `open` once it exists, then `merged` or `closed`) and Phase 3 merge polling.

They are kept separate on purpose: an `approved` batch that failed to push (Phase 2's problem, not yet
possible in Phase 1) must stay distinguishable from one still awaiting review, and from one whose PR is
open and waiting on CI. Flattening the two into one column would make "approved but the push failed"
indistinguishable from "approved, awaiting review on the pull request."

**As of Phase 1: every row in the table has `publication_status = 'none'`, full stop.** There is no other
value to see yet. Do not read `'none'` as an error state — it is the only state that exists so far.

## Inspecting the queue in SQL

```sql
-- Queue depth by review status
SELECT review_status, COUNT(DISTINCT batch_id) AS batches, COUNT(*) AS rows
FROM sourcedata_change_requests
GROUP BY review_status;

-- Everything still awaiting review, oldest first
SELECT batch_id, resource_type, resource_id, submitted_by_sub, submitted_by_name,
       COUNT(*) AS file_count, MIN(created_at) AS submitted_at
FROM sourcedata_change_requests
WHERE review_status = 'submitted'
GROUP BY batch_id, resource_type, resource_id, submitted_by_sub, submitted_by_name
ORDER BY submitted_at ASC;

-- Every file in one batch, in the order the reviewer would see it
SELECT path, operation, LENGTH(content) AS content_length
FROM sourcedata_change_requests
WHERE batch_id = '<batch-id>'
ORDER BY path ASC;

-- Full detail on one batch's decision
SELECT batch_id, review_status, approved_by_sub, approved_at, rejected_reason
FROM sourcedata_change_requests
WHERE batch_id = '<batch-id>'
LIMIT 1;
```

`resource_id` is rite-qualified as `<rite>/<calendarId>` for every calendar-naming `resource_type`
(`national_calendar`, `diocesan_calendar`, `wider_region`, and the two rite-qualified test types) — e.g.
`roman/US`, `ambrosian/lugano_ch` — because the source tree is partitioned by rite and a bare id does not
identify a calendar. `general_roman_calendar` ids (`decrees`, temporale, missal editions) stay bare; they
are Roman by construction.

## Approving or rejecting out of band

The supported path is the API itself:

```bash
# Approve
curl -X POST -H "Authorization: Bearer $ADMIN_TOKEN" \
  http://localhost:8000/admin/change-requests/<batch-id>/approve

# Reject, with a reason
curl -X POST -H "Authorization: Bearer $ADMIN_TOKEN" -H "Content-Type: application/json" \
  -d '{"reason": "Wrong feast rank"}' \
  http://localhost:8000/admin/change-requests/<batch-id>/reject
```

Both require the `admin` relation on the batch's resource (or the Zitadel global admin role, which
bypasses the OpenFGA check). Both answer **404**, never 403, when the caller does not administer the
resource — identical to an unknown batch id, so a 403 can never confirm to a caller that a batch they
cannot touch exists at all. A malformed (non-UUID) batch id is a 400, since UUID-shapedness is decidable
from the input alone and discloses nothing. Both return **409** if the batch was already decided by
someone else between the caller reading the queue and acting on it — `review_status = 'submitted'` is the
`WHERE` predicate that makes a decision single-shot; re-deciding an already-decided batch matches zero
rows.

If the API is unreachable, a direct SQL decision is the fallback — do this only when you have already
confirmed (via the queries above) that you are looking at the right batch and that `review_status` is
still `submitted`:

```sql
UPDATE sourcedata_change_requests
   SET review_status = 'approved',
       approved_by_sub = '<your-sub>',
       approved_at = NOW(),
       updated_at = NOW()
 WHERE batch_id = '<batch-id>'
   AND review_status = 'submitted';
```

This bypasses the audit log entry the handler writes on every decision (`change_request.approve` /
`change_request.reject` in `AuditLogRepository`), so treat it as a last resort and note the manual decision
somewhere the audit trail would otherwise have captured it.

## GET /admin/change-requests pagination — read this before writing a client

The response envelope is `{change_requests, count, total, limit, offset, has_more}`. For a **global**
admin, `count` always equals the SQL page size and everything behaves the way you would expect from any
offset-paginated endpoint. For a **resource** (non-global) admin, the page is filtered to the batches they
administer **after** it is fetched from SQL — filtering happens in application code, because it depends on
OpenFGA relations no SQL query here can express — so:

- `count` may be smaller than both `limit` and `total`.
- `total` is always the **pre-filter** SQL count, never the post-filter one.
- `has_more` is derived from the pre-filter SQL page size, **never** from `count`.

**Clients must page on `has_more`, never on whether the current page came back empty.** A single SQL page
that filters down to zero visible batches for a resource admin still reports `has_more: true` when the SQL
paginator has more pages behind it. A client that stops paging on an empty page will silently stop seeing
reviewable batches that exist on later pages — this is the specific failure the `has_more` derivation
exists to prevent. See the docblock on `ChangeRequestAdminHandler::list()` (around lines 168–180) for the
full reasoning, and `AdminChangeRequestListResponse` in `jsondata/schemas/openapi.json` for the documented
contract.

`GET /auth/change-requests` (a caller's own batches) has no such filtering step and therefore no `count` or
`has_more` at all — just `change_requests`, `total`, `limit`, `offset`. Comparing `offset + <page length>`
against `total` is sufficient there.

## Deliberately not in phase 1

- **`base_sha` / rebase detection.** The column exists (`Version20260828120000` migration) but nothing
  writes it yet. Its only source is GitHub's blob sha, which arrives with the Phase 2 publisher.
- **Re-validating a payload against its JSON schema at approval time.** Phase 1 validates once, at submit.
  Re-validation at the approval gate only becomes consequential once time can meaningfully pass between
  submission and a publish action — which needs the Phase 2 publisher to exist first.

Neither is an oversight to be quietly forgotten; both are required before the publisher opens its first
pull request.

## Retention / pruning

There is no automated prune. Unlike the OpenFGA outbox, rows here are not transient operational state —
they are the durable record of every proposal, decided or not, and (once Phase 2 exists) the paper trail
between a submission and the pull request it became. Do not delete rows out of band.
