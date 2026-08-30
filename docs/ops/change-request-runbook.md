# Source-data change requests — operator runbook

## What this is

`sourcedata_change_requests` (Postgres) holds proposed edits to `jsondata/sourcedata` — the calendar,
decree, and unit-test source files a `PUT`/`PATCH`/`POST`/`DELETE` write request would otherwise apply
straight to disk. On a deployment with the queue enabled, a caller who does not administer the targeted
resource has their write recorded as a row (or several rows, grouped into one **batch**) here instead of
touching the filesystem; a resource admin reviews the batch and approves or rejects it. A caller who
already administers the resource still gets a batch — it is just auto-approved in the same request.

See `docs/superpowers/specs/2026-08-28-sourcedata-change-requests-design.md` for the full design,
`docs/superpowers/plans/2026-08-28-sourcedata-change-requests-phase1.md` for the phase 1 implementation
plan, and `docs/superpowers/plans/2026-08-29-sourcedata-publisher-phase2.md` for the phase 2 (publisher)
implementation plan.

**Phase 1 stops at approval.** Without the phase 2 publisher configured and running, an approved batch
sits at `publication_status = 'none'` indefinitely — nothing opens a pull request, nothing writes to
GitHub, and `jsondata/sourcedata` on disk is never touched by queue-mode writes at all. "Approved" then
means "recorded as approved in Postgres," full stop — a durable staging area, not a change that has taken
effect anywhere a calendar consumer can see.

**Phase 2 adds a publisher**, `SourceDataPublisher` (`src/Services/SourceData/SourceDataPublisher.php`),
driven by a cron-invoked runner, `PublishRunner` (`src/Services/SourceData/PublishRunner.php`), via
`scripts/publish-sourcedata.php`. It turns each approved batch into one commit on a per-resource branch
plus a rolling pull request via a GitHub App. It requires that App to be registered and its credentials
configured (a one-time human step; not covered here — see the phase 2 plan directory above) before it does
anything at all. Until then, behaviour is identical to phase 1: `GET /health`'s `source_data_publisher`
block reports a `warning` if change-request queue mode is on and the publisher is not configured, and
approved batches accumulate unpublished exactly as they did before phase 2 existed. See
"Publishing to GitHub (phase 2)" below for the full lifecycle, failure modes, and a limitation that is
**not** closed by this phase.

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

## One submitted proposal per file, per submitter

Queue mode keeps **at most one *submitted* proposal per `(file path, submitter)`** — the invariant
`idx_scr_unique_pending_path_submitter` enforces, and only that. It is a partial index over
`review_status = 'submitted'`, so it says nothing about approved, rejected or withdrawn rows, of which any
number may share a path and submitter. Submitting a write that stages a path the same submitter already has
submitted therefore *supersedes* the batch that path belonged to. A batch is approved or rejected as a
unit, so it is never left half-superseded: every one of its rows is either replaced or moved.

Three consequences an operator will meet:

1. **Superseding folds the old batch into the new one; it does not throw the rest of it away.** If a batch
   staged both `decrees.json` and `decrees/i18n/de.json`, a later request staging only the former replaces
   the `decrees.json` row and *carries the `de.json` row forward* onto the new batch id, content and
   `created_at` intact. The old batch id stops existing — which is why every queue-mode write response
   lists the ids it folded in, as `change_request.superseded_batch_ids`, and why a listed id no longer
   appears in `GET /auth/change-requests`. Those ids do not name discarded work.

   Deleting the whole batch instead was silent data loss, and reachable through the ordinary API: a PATCH
   may omit `readings`, and a `setProperty`/`grade` write may not carry `i18n` or `readings` at all, so a
   perfectly ordinary follow-up request stages a strict subset of the paths it is superseding. The
   accumulation in consequence 2 cannot cover the difference, because a handler only ever rebuilds the
   paths it restages. Disk mode always kept both sidecars, so this was a queue-mode-only divergence.

2. **Superseding an aggregate file is accumulation, not replacement.** Some resources are stored as one
   file holding many editable items — the entire decree corpus is one `decrees.json`, and every decree
   translation for a locale is one `decrees/i18n/<locale>.json`. Handlers rebuilding one of those read the
   submitter's own not-yet-published content for that path first
   (`SourceDataChangeRequestRepository::findUnpublishedContent()`, reached through
   `WritesSourceData::unpublishedSourceContent()`), falling back to disk when there is none. So what a
   submitter has in flight is always their *cumulative* proposal.

   Without this, submitting decree B would rebuild `decrees.json` from disk — where decree A never landed,
   because a proposal is not a file — and decree A would be gone behind a `201`. Disk mode is unaffected:
   there is no queue there, the lookup always answers "nothing", and every read is the disk read it has
   always been.

3. **Approval is not publication, so approved work still accumulates.** Phase 1 has no publisher:
   approving a batch is a single status `UPDATE` that writes no files and leaves `publication_status` at
   `none`. An approved-but-unpublished batch is therefore exactly as absent from disk as a submitted one,
   and the accumulation base includes it. This matters most on the auto-approved paths — `Router` gates
   decree `DELETE` at the `admin` relation and `ChangeRequestReview::administers()` auto-approves on that
   same relation, so *every* decree `DELETE` in queue mode is approved the instant it is submitted. Were the
   base narrowed to `submitted`, the submitter's next decree write would rebuild `decrees.json` from a disk
   that still held the deleted decree and silently put it back.

The two predicates are deliberately different, and must stay so:

| Operation                          | Predicate                                                                      |
|------------------------------------|--------------------------------------------------------------------------------|
| Supersede (replace / carry forward)| `review_status = 'submitted'`                                                  |
| Accumulation base (content/paths)  | `review_status IN ('submitted','approved') AND publication_status <> 'merged'` |

The supersede is narrow because an approved batch is a decision and must survive; the accumulation base is
wide because "approved" does not mean "on disk". `rejected` and `withdrawn` are excluded from the base for
the mirror-image reason — that is work the queue threw away, and accumulating it would resurrect content a
reviewer refused. `merged` is excluded because merged content *is* the repository and a later deploy brings
it to disk; accumulating it on top of the disk read would double-count it.

Both are scoped to `submitted_by_sub`: one submitter never reads or supersedes another's work.

To see what a submitter currently has unpublished for a given path:

```sql
SELECT batch_id, review_status, publication_status, operation, LENGTH(content) AS bytes, created_at
FROM sourcedata_change_requests
WHERE path = '<repo-relative-path>'
  AND submitted_by_sub = '<sub>'
  AND review_status IN ('submitted', 'approved')
  AND publication_status <> 'merged'
ORDER BY (review_status = 'submitted') DESC, created_at DESC, id DESC;
```

A rebuild starts from the first row this returns. What is *guaranteed* about it is narrower than "the
submitter's latest work": when a `submitted` row exists it is always first, and it is genuinely the newest
unpublished row for that `(path, submitter)` — a review decision is one-way, so a row that is `submitted`
now has been since it was created, and the unique index forbade any other row for that pair from being
created during its lifetime. Note that its `created_at` may still be older than its batch's other rows, as
a carried-forward row keeps the timestamp of the content it holds.

Among *decided* rows there is no such guarantee. `created_at DESC, id DESC` makes the choice deterministic
and repeatable, not meaningful: two approved rows written in the same microsecond are separated by `id`,
which is a random UUID. If you are trying to work out which of several approved rows is the real one, sort
them yourself and look at the content — do not read the first row as authoritative.

Several rows is normal once batches have been approved; more than one with `review_status = 'submitted'`
means the unique index is missing or disabled.

## Review status vs. publication status

Every row (and therefore every batch, since a batch shares these columns across all its rows) carries two
separate status columns, and conflating them is the most common way to misread this table:

- **`review_status`** — OUR review workflow: `submitted` → `approved` / `rejected` / `withdrawn`. This is
  what `POST /admin/change-requests/{batchId}/approve|reject` and
  `POST /auth/change-requests/{batchId}/withdraw` move.
- **`publication_status`** — GitHub's side of the same change: `none` → `queued` → `open` → `merged` /
  `closed`. The phase 2 publisher writes `queued` and `open`; the phase 3 merge poller writes `merged` and
  `closed`. See "Publishing to GitHub (phase 2)" below for exactly what each value means and what a row's
  `branch`/`commit_sha`/`pr_number`/`base_sha` columns hold once filled, and "Merge detection (phase 3)"
  below for how `merged` and `closed` are decided.

They are kept separate on purpose: an `approved` batch that failed to push must stay distinguishable from
one still awaiting review, and from one whose PR is open and waiting on CI. Flattening the two into one
column would make "approved but the push failed" indistinguishable from "approved, awaiting review on the
pull request."

**Without the phase 2 publisher configured and running, every row in the table has
`publication_status = 'none'`, full stop.** There is no other value to see. Do not read `'none'` as an
error state — on a deployment that has not yet registered the GitHub App, it is the only state that
exists.

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

### Approval re-validates against the current schemas (422)

Approval is not only a status change: before flipping anything, the handler re-checks every
still-`submitted` row of the batch against the JSON schema that governs its path *right now*
(`ChangeRequestSchemaValidator`, `SourceDataSchemaResolver`). If any row no longer validates, the
approval is refused with a **422** naming each offending file, the schema that refused it, and the
violation — and the batch is left completely untouched: still `submitted`, still holding every row.

The window this closes is real but easy to misread. Content is validated once, by the handler that
accepted it, and a batch can then sit in the queue for weeks. If a schema tightens in between, the batch
is still `submitted` and looks approvable, and before #918 it *was* — the mismatch only surfaced later, as
a failing CI run on the pull request the publisher had already opened. The check now happens where the
decision happens.

Two consequences worth knowing before you go looking for a bug:

- **404 / 400 / 409 still take precedence.** The 422 is reached only after existence, authorization and
  batch-id shape have passed, and it checks only rows a transition would actually move. A batch someone
  else already decided answers **409**, not 422, even when its content would now fail — the transition is
  not going to happen, so re-litigating its content would replace the true answer ("already decided") with
  a misleading one.
- **A refused batch is not stuck, but it cannot be repaired from the reviewer's side.** There is no
  "approve anyway". Either the submitter withdraws it (`POST /auth/change-requests/{batchId}/withdraw`)
  and re-submits content that satisfies the current schema, or you reject it — rejection is deliberately
  *not* gated on validation, precisely so an invalid batch can always be cleared out of the queue.

A path no schema claims — `SourceDataSchemaResolver` covers every family a write handler stages, and
nothing else — is treated as *not validated*, never as invalid. Refusing there would jam the queue on a
batch nothing has found fault with, for a reason no administrator could act on. If you add a new kind of
stageable source data, add its family to that resolver or its content will pass this gate unchecked.

Rows with no content are not checked, because there are no bytes for a schema to have an opinion about.
The predicate is `content IS NULL`, **not** `operation = 'delete'` and **not**
`metadata.deletes_resource` — see "Closed: a deleted resource's editors used to keep access" below for
why those two mean different things and why neither is a safe stand-in here.

The auto-approval path (`ChangeRequestSourceDataWriter::commit()`, for a submitter who already administers
the resource) does **not** re-validate, and does not need to: it approves in the very same request that
just validated the payload, so there is no interval in which a schema could have moved.

If the API is unreachable, a direct SQL decision is the fallback — do this only when you have already
confirmed (via the queries above) that you are looking at the right batch and that `review_status` is
still `submitted`. Note that this bypasses the schema re-validation above as well as the audit log, so
satisfy yourself that the content is still valid before running it:

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

## Publishing to GitHub (phase 2)

Once the GitHub App is registered and its credentials are configured (see `.env.example`'s "Source Data
Publisher (phase 2)" block, and `docs/superpowers/plans/2026-08-29-sourcedata-publisher-phase2.md`'s
"Task 8: Register the GitHub App" for the one-time registration procedure), a cron job invokes
`scripts/publish-sourcedata.php` on an interval. Each run:

1. Reclaims any batch stranded `queued` past a grace period (see below).
2. Claims up to `limit` (default 10) approved-and-unpublished batches, oldest first.
3. Publishes each claimed batch via `SourceDataPublisher::publish()` — one commit on a per-resource
   branch, plus a pull request that stays open across every later batch for that same resource (a
   "rolling" PR).
4. Stops early the moment one publish attempt genuinely fails, rather than hammering a possibly-broken
   GitHub API with the rest of the queue. The next cron tick is the retry, not an in-process loop. Two
   failures are deliberately NOT treated that way, because neither says anything about the health of the
   API: a batch another runner already published (nothing to do), and a lost race for a resource's branch
   (a GitHub `422` — see "Operational failure modes"). Both log and continue.
5. Counts the attempt against the batch. After
   `SourceDataChangeRequestRepository::MAX_PUBLISH_ATTEMPTS` (5) CONSECUTIVE failed attempts a batch is
   *parked*: no longer claimed, so the rest of the queue drains past it. See "Parked batches" below.

`GET /health`'s `source_data_publisher` block reports whether the publisher is actually configured,
independently of whether change-request queue mode itself is on — check it after any configuration change.
**Read the nested block, not the top level.** A `warning` there does not change `/health`'s own top-level
`status` field and does not change the HTTP status code: the endpoint still answers `200 ok` while the
publisher is unconfigured or batches are parked. A monitoring check that only looks at the HTTP code, or
only at `.status`, will never see either. Parse `.source_data_publisher.status` and
`.source_data_publisher.parked_batches`.

### The publish lifecycle: `none` → `queued` → `open`

`publication_status` moves through these values, all on the `sourcedata_change_requests` table, for every
row of a batch at once (a batch is never mixed-status):

| Value    | Set by                                 | Meaning                                                                                             |
|----------|----------------------------------------|-----------------------------------------------------------------------------------------------------|
| `none`   | phase 1 (submit), or a release/reclaim | Not yet claimed for publishing, or a failed/stranded attempt was put back so it is claimable again. |
| `queued` | `claimNextPublishableBatch()`          | A claim held by one runner, mid-publish. Not yet on GitHub.                                         |
| `open`   | `recordPublication()`                  | A pull request exists for this batch's commit.                                                      |
| `merged` | phase 3 (`MergePollRunner`)            | The pull request merged, and this batch's commit is verified contained in that merge.               |
| `closed` | phase 3 (`MergePollRunner`)            | The pull request closed without merging. `review_status` is set to `rejected` alongside it.         |

`queued` is a **claim**, not a milestone on GitHub — nothing has been pushed yet while a batch sits there.
`open` means a real pull request exists; it does not mean the PR merged, and does not mean CI passed.

`publish_attempts` is a fourth thing again, and orthogonal to all of them: how many CONSECUTIVE attempts
the publisher has spent on the batch. It is incremented when a claim is released (a failure) or reclaimed
(a crash), reset when the batch publishes, and once it reaches 5 the batch is no longer claimed at all —
see "Parked batches" below.

Once a batch reaches `open`, `recordPublication()` also stamps four columns, on every row of the batch:

- **`branch`** — `litcal-data/<resource_type>/<resource_id>`, e.g. `litcal-data/national_calendar/roman/US`.
  Stable per resource, which is what makes the pull request "rolling": every later approved batch for the
  same resource lands on this same branch and reuses the same open PR instead of opening a competing one.
- **`commit_sha`** — the sha of the commit this batch produced on that branch (the *latest* one, if the
  batch was ever re-published — see "The benign double-publish" below).
- **`pr_number`** — the pull request's number on GitHub.
- **`base_sha`** — the commit the publish branched from: the branch's own head if the branch already
  existed, or the configured base branch's head (`GITHUB_BASE_BRANCH`, default `development`) if this was
  the resource's first publish. This is a **batch-level** value, written across every row of the batch,
  and is distinct from what a phase 1 reading of this column might suggest — it is not a per-file blob sha
  and not per-file rebase bookkeeping.

### Stranded-claim recovery and the grace period

A crash between `claimNextPublishableBatch()` and the publish finishing — a SIGKILL, an OOM kill, a cron
timeout — leaves a batch `queued` with no process left running to release it. Without recovery, that batch
is invisible to the operator, invisible to the editor, and indistinguishable from success on the editor's
side, forever. `PublishRunner::runOnce()` reclaims any batch still `queued` past
`PublishRunner::DEFAULT_GRACE_SECONDS` (1800 seconds) at the start of every run, before claiming anything
new. A reclaim is ordinary recovery, not a failure: it never causes the run to stop early, and a batch it
reclaims is immediately claimable again in that same run.

**The grace period's value is not arbitrary and must not be lowered without re-deriving it.** The
invariant is:

```text
grace_seconds > max_requests_per_batch * request_timeout_seconds
```

A publish issues one `createBlob` call per changed file, serially, followed by a fixed sequence
(`getRef`/`createRef`, `getCommitTreeSha`, `createTree`, `createCommit`, `updateRef`,
`findOpenPullRequest`/`openPullRequest`) — six calls. The widest batch this repository can produce today is
the decrees corpus: `decrees.json` plus 14 `i18n/` locale files plus 7 `lectionary/` locale files, 22 blob
writes, plus the six fixed calls — 28 requests. At `scripts/publish-sourcedata.php`'s 30-second per-request
timeout, that is a worst case of 840 seconds for one publish that is merely slow, not dead. 1800 leaves
comfortable headroom above 840.

If either the request timeout or the widest batch this repository can produce grows, the grace period must
grow with it. **Lowering `DEFAULT_GRACE_SECONDS` without checking this arithmetic will reclaim live work:**
a publish that is still genuinely in flight gets treated as abandoned, a second runner republishes the same
batch, and the first runner's now-stale `updateRef()` call fails with a non-fast-forward error when it
finally returns — recoverable (see below), but entirely avoidable by respecting the invariant.

### Parked batches

A batch can fail *deterministically*: an illegal git-ref character in its `resource_id`, a tree-path
conflict, a payload a later validation change rejects. Nothing about retrying such a batch will ever
succeed, and because candidates are claimed oldest-first and the runner stops the tick on a failure, an
unbounded retry means that one batch is re-attempted first on every tick and **every other editor's
approved work is never reached at all** — with no error of its own, and no editor-visible symptom.

So each batch gets `MAX_PUBLISH_ATTEMPTS` (5) CONSECUTIVE attempts, counted in the
`publish_attempts` column. Both a caught failure (`releaseClaim()`) and an abandoned one
(`reclaimStaleClaims()`, i.e. a crash) count — a batch that OOM-kills the publisher is caught by no
error handler, so if a reclaim were free it would re-crash forever. A successful publish clears the
counter, so a transient GitHub blip can never park a batch. Once the counter reaches 5 the batch stops
being claimed and the queue drains past it.

**A batch that is merely slow can spend two attempts per cycle, not one**, so it parks after three
cycles rather than five. The claim guard is `publication_status = 'queued'`, which identifies *a*
claim and not *whose*: when the grace period elapses on a publish that is still alive, the reclaim
spends one attempt, a second runner picks the batch up, and the first runner's own late failure then
releases the second runner's live claim and spends another. This is bounded and visible (the batch
parks and `/health` reports it) and it lands on the benign double-publish rather than on lost work,
but it is why a batch that habitually runs near the grace period parks sooner than the number 5
suggests. The fix would be a claim token compared on release — a schema change, deliberately not
made. If you see this, raise the grace period or narrow the batch before raising the attempt bound.

**Parking is not a dead-letter queue.** The design spec's error table promises a DLQ row for terminal
failures; that was never built, and there is no DLQ table, no dead-letter status, and no automatic
notification to the submitter. What actually happens is exactly and only this: the rows are left
untouched, still `review_status = 'approved'` and `publication_status = 'none'`, with
`publish_attempts >= 5`, and the publisher stops picking them up. Nothing is lost and nothing is
rewritten; the batch simply waits for an operator.

Because a parked batch produces no failure of its own, it is reported out of band in three places:

- `GET /health` — `.source_data_publisher.parked_batches`, and `.source_data_publisher.status` becomes
  `warning` (which does NOT change the top-level `status` or the HTTP code — see above);
- the run's summary line — `publish-sourcedata published=… stopped_on_failure=… parked=N`;
- `logs/publish-sourcedata.log` — a warning naming `parked_batches` on every run where N > 0.

The exit code is deliberately unaffected: parking is what lets the rest of the queue drain, so a run that
publishes everything it can and reports parked batches has done its job. **Monitor `parked`, not only the
exit code.**

To inspect and retry:

```sql
-- Which batches have stopped being attempted, and how much work is in them
SELECT batch_id, resource_type, resource_id, submitted_by_sub,
       MAX(publish_attempts) AS attempts, COUNT(*) AS file_count, MIN(created_at) AS submitted_at
FROM sourcedata_change_requests
WHERE review_status = 'approved' AND publication_status = 'none' AND publish_attempts >= 5
GROUP BY batch_id, resource_type, resource_id, submitted_by_sub
ORDER BY submitted_at ASC;

-- Retry one, AFTER fixing whatever made it fail (see the log for the GitHub error)
UPDATE sourcedata_change_requests SET publish_attempts = 0, updated_at = NOW()
WHERE batch_id = '<batch-id>';
```

Clearing the counter without fixing the cause simply spends five more attempts and parks it again — the
five failures are in `logs/publish-sourcedata.json.log` with the batch id and GitHub's own error text.
If the proposal itself is unpublishable (a malformed `resource_id`, say), reject it through
`POST /admin/change-requests/{batchId}/reject` with a reason, so the submitter learns why, rather than
leaving it parked indefinitely.

### Credentials on disk

Two secrets touch the filesystem, and they have different lifetimes:

- **The GitHub App private key** (`GITHUB_APP_PRIVATE_KEY_PATH`) must live OUTSIDE the deployed tree and
  never under the web root — `/etc/litcal/github-app.pem`, owned by the user the cron job runs as, mode
  `0600`. Only the path is ever put in the environment; the key bytes are read at use time and are never
  logged, never placed in an exception message, and never committed.
- **The derived installation token** is cached under `<project>/cache/github_app_tokens/` so that a
  cron-invoked, short-lived process does not re-authenticate on every tick. It is a bearer credential
  carrying `contents: write` and `pull_requests: write` on the repository and is valid for up to 50
  minutes — it deserves the same care as the key it comes from. `scripts/publish-sourcedata.php` sets a
  restrictive umask around the whole window in which the cache writes entries and chmods the namespace
  directory to `0700`, so entries land at `0600` inside a `0700` directory (an earlier revision left them
  at `0644` inside `0755`, world-readable to any local user). The chmod also repairs a directory an older
  run created.

Verify after a run, rather than assuming:

```bash
stat -c '%a %n' cache/github_app_tokens cache/github_app_tokens/*
# expected: 700 cache/github_app_tokens, then 600 for each entry
```

If the directory is anything wider than `700`, something outside the publisher widened it (a `chmod -R`
over `cache/`, a deploy step, a backup restore). Fix the mode and rotate the App's private key if the
host is shared, since any token cached in that window may have been read.

### The benign double-publish

If the GitHub writes inside `publish()` all succeed — commit created, ref updated, PR open or reused — but
the final `recordPublication()` call then fails (a DB blip, a connection drop), the batch is left `queued`
with real work already on GitHub that Postgres does not know about yet. That batch is retried, either by
the grace-period reclaim or by a subsequent run's own failure handling. The retry re-runs `publish()` from
scratch against the branch's new head — which is the commit the first attempt already pushed. Since the
batch's content has not changed, the retry's tree is identical to its parent's tree, producing **one extra
commit with an empty diff and the same commit message**, and it reuses the already-open pull request rather
than opening a second one (`findOpenPullRequest()` finds it).

This is not data loss and not a duplicate PR — it is a git artifact you should recognize on sight, not
investigate as a bug: two adjacent commits on a `litcal-data/...` branch with identical content and the
same message is this benign double-publish, not evidence of a broken retry.

### Exit codes and monitoring

`scripts/publish-sourcedata.php` exits:

- **0** — every batch this run claimed was published, or the queue was empty. A reclaimed stale claim is
  ordinary recovery and does not affect this; a run that reclaimed a batch and then successfully published
  everything else still exits 0.
- **1** — misconfiguration (bad `limit` argument, or the GitHub App / `GITHUB_REPOSITORY` not configured),
  a database failure, or a publish attempt genuinely failed and the run stopped early.

**A failed batch is left at `publication_status = 'none'`, NOT `'queued'`.** `releaseClaim()` gives the
claim up; `queued` means a claim someone still holds, which is exactly what the failure path releases. An
operator who reacts to an exit-1 alert by hunting for `publication_status = 'queued'` will find nothing —
these are the two columns this runbook opens by warning you not to conflate. What to look for instead:

```sql
-- Approved work that has been attempted and is waiting for another tick
SELECT batch_id, resource_id, MAX(publish_attempts) AS attempts, MAX(updated_at) AS last_attempt
FROM sourcedata_change_requests
WHERE review_status = 'approved' AND publication_status = 'none' AND publish_attempts > 0
GROUP BY batch_id, resource_id
ORDER BY last_attempt DESC;
```

(A row genuinely sitting in `queued` means a claim is in flight right now, or a process died holding one —
that is the grace-period reclaim's job, not this alert's.)

The summary line also carries `parked=N`, which does **not** affect the exit code. A monitoring check on
this script should therefore alert on a non-zero exit **and** on `parked` being non-zero (or, equivalently,
on `/health`'s `.source_data_publisher.parked_batches`) — a run can exit 0 with work stuck, which is the
whole point of parking it. Neither state produces a user-visible symptom anywhere else. Detail on which
batch and why is in `logs/publish-sourcedata.log` (human-readable) and
`logs/publish-sourcedata.json.log` (structured), both rotated. `GitHubApiException` carries GitHub's HTTP
status alongside its message, so a `401`/`403` in either log is the signature of a stale or revoked
installation token, not a transient failure.

### Operational failure modes

- **An unconfigured publisher accumulates approved work silently.** If change-request queue mode is on but
  the GitHub App credentials are missing, incomplete, or **malformed**, `SourceDataPublisher::isConfigured()` returns
  `false`, `GET /health`'s `source_data_publisher` block reports `warning`, and every approved batch simply
  stays `publication_status = 'none'` forever — nothing about this looks like an error to an editor or a
  resource admin, since their own review workflow (`review_status`) completes normally. Watch `/health`,
  not the editor-facing endpoints, to catch this.

  A set-but-malformed value lands here too, not only an absent one: `GITHUB_REPOSITORY` must be exactly
  `owner/repo`, so a pasted repository URL or a trailing slash reports identically to leaving it blank.
  `isConfigured()` and the publisher's own construction share one shape check precisely so `/health`
  cannot call a value configured that a run would then reject.
- **A non-fast-forward `422` is the expected symptom of two editors racing on one resource, not data
  loss.** `GitHubGitDataClient::updateRef()` hardcodes `force: false` and is never given a way to force —
  intentionally, so that a branch another publish landed on between this publish's `getRef()` and its own
  `updateRef()` fails loudly, with GitHub's `422`, instead of silently overwriting that other editor's
  commit. The failure propagates as a `GitHubApiException`, is caught by `PublishRunner`, and releases the
  claim; the batch is retried — by the next claim, once the branch is quiet — rather than clobbering what
  is already there. A `422` does **not** stop the run and does **not** make it exit 1: it is expected,
  self-healing, and proof that GitHub is up and answering, so treating it like a revoked credential would
  page an operator for the design working. It is logged as a warning (`lost a race for its resource
  branch`), and the attempt is still counted — so a batch that `422`s on every attempt is eventually
  parked rather than retried forever, which is the case worth investigating: a branch nobody is
  fast-forwarding, rather than two busy editors.
- **A stale installation token.** GitHub installation tokens live one hour; `GitHubAppAuth` caches one for
  50 minutes so a token already in flight is never used in its final ten minutes. A token can still go bad
  before its cache entry expires if the App's installation is suspended or removed from the repository — the
  next token exchange or API call then fails with a `401`/`403` `GitHubApiException`, the run stops, and
  every subsequent tick fails identically until the installation (or `GITHUB_APP_PRIVATE_KEY_PATH`) is
  fixed. This is indistinguishable, from the exit code alone, from any other genuine publish failure —
  check the log message for the HTTP status and GitHub's own error text.

### Author vs. committer, and the unverified-email rule

Every commit `SourceDataPublisher::publish()` creates has the editor who submitted the batch as its
**author** (`submitted_by_name`, `submitted_by_email`) and the GitHub App as its **committer**
(`GITHUB_APP_COMMITTER_NAME`/`GITHUB_APP_COMMITTER_EMAIL`). This split is the entire reason the design
exists: it is what lets the repository history attribute a change to the person who actually made it, while
the App remains the party that actually pushed it. Do not "simplify" this to a single identity — that would
erase per-editor authorship from the git history entirely.

An unverified email must never become the commit author email: presenting it as an authenticated identity
would let anyone who can set an arbitrary address in their own profile forge authorship of a third party in
a public repository. `authorFor()` uses `submitted_by_email` as the author email only when
`submitted_by_email_verified` is true; otherwise it substitutes a fixed placeholder
(`noreply@users.noreply.github.com`). This is an honest limitation, not a real per-editor identity: editors
authenticate through Zitadel, not a GitHub account, so there is no identity mapping available to produce a
genuine `<id>+<login>@users.noreply.github.com` address the way GitHub's own UI would. Every commit
authored under an unverified email therefore carries the same placeholder address regardless of who
actually submitted it — the author *name* still varies, but the email does not.

## Merge detection (phase 3)

Phase 2 stops at `open`: a pull request exists, and nothing watches what happens to it. Phase 3 adds a
second cron-invoked script, `scripts/poll-sourcedata-merges.php`, driven by `MergePollRunner`
(`src/Services/SourceData/MergePollRunner.php`), that polls every `open` batch's pull request and records
what became of it.

### The lifecycle, completed: `none` → `queued` → `open` → `merged` | `closed`

`merged` and `closed` are the two states "Publishing to GitHub (phase 2)" above left reserved. Both are
written by `MergePollRunner`, never by the publisher:

- **`merged`** — the pull request merged, and this batch's commit is verified **contained** in the merge.
  Containment is checked, not assumed: a batch whose commit sha equals the pull request's own head is
  contained for free (the ordinary case, one batch per pull request), and any other batch on that pull
  request is checked with one GitHub compare-commits call. See "`reset=N`" below for what happens when a
  batch is NOT contained.
- **`closed`** — the pull request closed without merging. `review_status` is set to `rejected` alongside
  it, in the same update, so a closed-unmerged batch is unambiguously a decided, dead proposal — not
  merely "no longer open."

A poll that cannot decide either way (the GitHub call itself fails) changes nothing: the batch stays
`open` for the next tick rather than guessing, because both wrong guesses have a real cost — assuming
`merged` on a batch that never landed loses no data itself, but assuming `closed` on a batch that actually
merged would mark good work rejected.

### The two cron entries

Both scripts are idempotent and safe to run concurrently with themselves — a second overlapping
invocation finds nothing left to claim or poll and exits cleanly. A five-minute interval is what this
deployment runs in practice:

```cron
*/5 * * * * cd /path/to/api && php scripts/publish-sourcedata.php >> logs/cron-publish.log 2>&1
*/5 * * * * cd /path/to/api && php scripts/poll-sourcedata-merges.php >> logs/cron-poll.log 2>&1
```

Both require the same GitHub App credentials as phase 2 (`GITHUB_APP_ID`, `GITHUB_APP_INSTALLATION_ID`,
`GITHUB_APP_PRIVATE_KEY_PATH`, `GITHUB_REPOSITORY`) and the same database connection. The poller's
OpenFGA purge step (see "Closed", below) is optional — left unconfigured, merge detection still works and
the purge is a quiet no-op.

### The consumer as an optional accelerator

`bin/publish-sourcedata-consumer` is a long-lived process, managed by systemd, that wakes on a Redis
`XADD` the instant a batch becomes publishable and also runs a merge poll on its own idle tick — the same
two jobs the cron entries above perform, just event-driven rather than interval-driven. It shares one
`GuzzleClient` and one GitHub App installation-token cache between the publish and poll sides
(`SourceDataPublisherFactory::publishRunner()` / `::mergePollRunner()`).

**It is optional. Cron alone is a complete, correct deployment.** A self-hoster who has not configured
Redis (`REDIS_SOCKET`/`REDIS_HOST` both unset, or `ext-redis` not installed) is not running a degraded or
broken feature — every approved batch still publishes, and every merge is still detected, within one
cron interval. The consumer only removes that interval's latency; it introduces no new correctness the
cron scripts do not already provide on their own.

Install it exactly as `deploy/systemd/liturgical-calendar-reconciler.service` installs the OpenFGA outbox
consumer:

```ini
[Unit]
Description=Liturgical Calendar source-data publish/merge consumer
After=network-online.target postgresql.service redis-server.service
Wants=network-online.target

[Service]
Type=simple
User=litcal
Group=litcal
WorkingDirectory=/opt/liturgical-calendar
EnvironmentFile=/opt/liturgical-calendar/.env.local
ExecStart=/usr/bin/php /opt/liturgical-calendar/bin/publish-sourcedata-consumer
Restart=on-failure
RestartSec=5
StandardOutput=journal
StandardError=journal
SyslogIdentifier=litcal-sourcedata-consumer

# Hardening (adjust per ops policy)
ProtectSystem=full
PrivateTmp=true
NoNewPrivileges=true

[Install]
WantedBy=multi-user.target
```

Save that as `/etc/systemd/system/liturgical-calendar-sourcedata-consumer.service` (there is no
`deploy/systemd/` copy of it the way there is for the OpenFGA outbox reconciler — this consumer is
optional in a way the outbox reconciler is not, so it is documented here rather than shipped as a unit
this repository installs by default), then:

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now liturgical-calendar-sourcedata-consumer.service
sudo systemctl status liturgical-calendar-sourcedata-consumer.service
```

If it is not installed, or `ext-redis` is missing, or it exits (its exit code 2 specifically means
`ext-redis` is not installed — see the script's own docblock), do nothing: the two cron entries above
keep publishing and polling on their own schedule with no operator action required. Running the consumer
alongside the cron entries is also safe — the claim protocol (below) and the poller's `WHERE
publication_status = 'open'` guard both make a redundant attempt a no-op, not a double-publish or a
double-decision.

### `reset=N` on the poll summary line

`scripts/poll-sourcedata-merges.php`'s summary line —
`poll-sourcedata-merges merged=… closed=… reset=… unpollable=… stopped_on_failure=…` — carries a `reset`
counter for a specific, expected race: **a concurrent merge**. The rolling branch is per resource, and a
reviewer clicking Merge on the pull request at the same moment a publish lands a new commit on that same
branch separates the two — the merge takes the head the branch had a moment earlier, and the publish's
batch is left recorded against a pull request that closed without actually carrying it.

**The batch is republished under a fresh pull request, never marked `merged`.** Marking it `merged` would
assert its content reached the repository. The publisher only ever selects rows that are not yet
`merged`, so a wrongly-`merged` batch would never be attempted again and its content would be lost
silently — exactly the failure mode containment verification exists to prevent, reached from the other
direction. Instead, `returnBatchToUnpublished()` puts the batch back to `publication_status = 'none'`; the
next `publish-sourcedata.php` tick claims it again and opens a new pull request carrying it.

A `reset` count of zero or an occasional one-off is unremarkable — it is what this race looks like when
it happens rarely. **A value that keeps climbing means publishes and merges are racing routinely** on one
or more resources, which usually means either the publish cron and a human reviewer are both very active
on the same resource, or the publish interval is short enough to collide with typical review latency.
Investigate which resource's branch is repeatedly involved before assuming this is expected background
noise.

### `unpollable=N`

The same summary line's `unpollable` count should **always be zero**. The publisher records a pull
request number on every row it publishes (`recordPublication()` stamps `pr_number` unconditionally when a
batch reaches `open`), so an `open` batch with `pr_number IS NULL` is not a race or a timing window — it
is an unexplained state that needs an operator, because nothing in the normal lifecycle can produce it.

```sql
SELECT batch_id, resource_type, resource_id, commit_sha, updated_at
  FROM sourcedata_change_requests
 WHERE publication_status = 'open' AND pr_number IS NULL;
```

**`unpollable` does NOT affect the exit code.** Like `reset`, and like `parked` on the publisher's own
summary line, it is a value to monitor over time via this summary line and `GET /health`, not a failure of
the run that reported it — a poll run that finishes cleanly and merely reports a persistent `unpollable`
count has still done its job. Do not rely on the exit code alone to catch this.

### The two new `/health` keys, and reading the stale warning honestly

`GET /health`'s `source_data_publisher` block gains two keys alongside `parked_batches`:

- **`open_batches`** — how many batches currently sit at `publication_status = 'open'`, awaiting a merge
  decision. This is the ordinary state for a healthy pipeline and never alarms on its own — a pull request
  waiting on human review is exactly what is supposed to happen.
- **`oldest_open_age_seconds`** — how long the oldest of them has been waiting.

`/health` turns `warning` once `oldest_open_age_seconds` exceeds `Health::STALE_OPEN_BATCH_SECONDS` (30
days). Read that warning as naming **two** possible causes, not one: **either a slow reviewer, or a
stopped poller.** An undetected merge is invisible from this side of the system — a pull request that
merged three weeks ago but was never polled looks, in `sourcedata_change_requests`, exactly like a pull
request still genuinely awaiting review, and every editor waiting on it is told it is still open. Before
concluding "the reviewers are just slow," confirm `scripts/poll-sourcedata-merges.php` is actually running
on schedule (check `logs/cron-poll.log`, or `journalctl` for the consumer unit if one is installed) —
30 days of silence from the poller produces the identical symptom to 30 days of silence from a reviewer.

### Un-parking and claim tokens

The "Parked batches" SQL above (`UPDATE sourcedata_change_requests SET publish_attempts = 0, …`) clears a
batch's attempt counter so the publisher will claim it again. It is not the whole recovery: the same
grace-period reclaim actually *increments* `publish_attempts` — that increment is precisely the mechanism
by which a merely-slow batch parks in the first place — but in that same statement it also clears
`publish_claim_token` back to `NULL` — the column `claimNextPublishableBatch()` stamps with a fresh token
on every claim and `releaseClaim()` / `reclaimStaleClaims()` compare against before clearing, so that a
late release from one runner can never revoke a different runner's live claim (see "Claim ownership" in
`docs/superpowers/2026-08-30-phase-3-handoff.md`). A row `queued` with a token older than
`PublishRunner::DEFAULT_GRACE_SECONDS` is reclaimed automatically on the next run — an operator does not
need to touch `publish_claim_token` by hand; clearing `publish_attempts` on a genuinely stuck batch is
sufficient, because the next run's stranded-claim recovery step handles the token itself.

## Closed: a deleted resource's editors used to keep access

**Closed by phase 3.** Phase 2 shipped with a known limitation: deleting a calendar or test through a
change request did not purge its OpenFGA authorization tuples, so a deleted diocese's former editors
retained edit access on an object whose files were gone. Phase 3's merge poller
(`MergePollRunner::purgeIfResourceDeletion()`, called once a batch is confirmed `merged`) closes it: the
same moment the batch's deletion becomes real in the repository, its editor/viewer operational tuples are
purged via `ResourceTuplePurgeServiceInterface`.

**The trigger is `metadata.deletes_resource`, NEVER `operation = 'delete'`.** The operation column cannot
answer "did this batch delete the resource" — `RegionalDataHandler::writeI18nFiles()` stages a `DELETE`
row for every locale file dropped from `metadata.locales`, on a calendar that still exists, so a translator
removing one language from a calendar's `i18n` set produces `DELETE` rows exactly as a real calendar
deletion does. Keying the purge on the operation would revoke every editor and viewer on a live calendar
because of an ordinary translation edit. `metadata.deletes_resource` is the flag `RegionalDataHandler` and
`TestsHandler` set specifically to distinguish "the resource itself is gone" from "some of its files
changed."

**The purge requires EVERY row of the batch to carry the flag — it fails closed.** A batch that mixes a
flagged row with an unflagged one (an exotic carry-forward can produce this) purges nothing; the tuples
stay live until an operator or `ResourceTuplePurgeReconciler`'s sweep removes them. This asymmetry is
deliberate: wrongly purging revokes real access on a live calendar, while wrongly not purging only leaves
tuples live — exactly phase 2's status quo, which was already visible and recoverable.

**`admin` tuples deliberately survive the purge.** Only the operational `editor`/`viewer` relations are
removed, so ownership outlives a deletion — if the same resource id is recreated later (a diocese
re-added, a test redefined), whoever administered it before the deletion still does, rather than the
resource coming back ownerless.

To find a deletion batch that merged, and confirm what it purged:

```sql
SELECT DISTINCT batch_id, resource_type, resource_id, merge_commit_sha, publication_settled_at
  FROM sourcedata_change_requests
 WHERE publication_status = 'merged'
   AND metadata->>'deletes_resource' = 'true'
 ORDER BY publication_settled_at DESC;
```

If a deletion batch merged before phase 3 was deployed, or the purge's own best-effort OpenFGA call
failed (logged, not retried in-process — see `purgeIfResourceDeletion()`'s docblock), its former editors
may still hold live tuples. Purge them by hand through the same `ResourceTuplePurgeServiceInterface`
mapping (`RegionalDataHandler::fgaObjectForRequest()` / `changeResourceForRequest()` for calendars,
`TestsHandler::changeResourceForTest()` for test definitions) — the admin tuple is retained by that purge,
not removed, exactly as the automatic path retains it.

## Deliberately not in phase 1, 2, or 3

- **Re-validating at *publish* time.** #918 closed the approval half of this: approval now re-checks a
  batch against the schemas in force at that moment (see "Approval re-validates against the current
  schemas" above). The publisher still does not. The remaining window is between approval and the commit
  `SourceDataPublisher::publish()` pushes — much narrower than the submit-to-publish window it replaced,
  since a batch is normally published within minutes of approval, but not zero.
- **Per-file `base_sha` and rebase detection.** `recordPublication()` overwrites every row's `base_sha`
  with the batch-level branch head, destroying the per-file bookkeeping a rebase check would need. See
  "`base_sha` was speculative in phase 1 and is now defined" below.

Neither is an oversight to be quietly forgotten; both are required before this system is considered
complete. Each has its own follow-up issue — see `docs/superpowers/2026-08-30-phase-3-handoff.md`.

Purging OpenFGA authorization tuples for a deleted calendar or test definition, once mentioned here as
deferred to phase 3, is no longer on this list — see "Closed: a deleted resource's editors used to keep
access" above.

**`base_sha` was speculative in phase 1 and is now defined.** Phase 1's runbook predicted the column
would eventually hold "GitHub's blob sha." That is not what phase 2 actually wrote: `recordPublication()`
overwrites `base_sha` on **every row of a published batch** with the branch head commit sha the publish
branched from (the `getRef()`/`getCommitTreeSha()` starting point in `SourceDataPublisher::publish()`),
not a per-file blob sha and not per-file rebase bookkeeping. See "Publishing to GitHub (phase 2)" above.

## Retention / pruning

There is no automated prune. Unlike the OpenFGA outbox, rows here are not transient operational state —
they are the durable record of every proposal, decided or not, and the paper trail between a submission
and the pull request it became. Do not delete rows out of band.
