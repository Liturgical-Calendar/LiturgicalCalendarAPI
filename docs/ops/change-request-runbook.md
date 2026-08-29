# Source-data change requests — operator runbook

## What this is

`sourcedata_change_requests` (Postgres) holds proposed edits to `jsondata/sourcedata` — the calendar,
decree, and unit-test source files a `PUT`/`PATCH`/`POST`/`DELETE` write request would otherwise apply
straight to disk. On a deployment with the queue enabled, a caller who does not administer the targeted
resource has their write recorded as a row (or several rows, grouped into one **batch**) here instead of
touching the filesystem; a resource admin reviews the batch and approves or rejects it. A caller who
already administers the resource still gets a batch — it is just auto-approved in the same request.

See `docs/superpowers/specs/2026-08-28-sourcedata-change-requests-design.md` for the full design,
`.superpowers/sdd/2026-08-28-sourcedata-change-requests-phase1/` for the phase 1 implementation plan, and
`.superpowers/sdd/2026-08-29-sourcedata-publisher-phase2/` for the phase 2 (publisher) implementation plan.

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
  `closed`. The phase 2 publisher writes `queued` and `open`; `merged` and `closed` are reserved for phase
  3 merge polling, which does not exist yet. See "Publishing to GitHub (phase 2)" below for exactly what
  each value means and what a row's `branch`/`commit_sha`/`pr_number`/`base_sha` columns hold once filled.

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
   GitHub API with the rest of the queue. The next cron tick is the retry, not an in-process loop.

`GET /health`'s `source_data_publisher` block reports whether the publisher is actually configured,
independently of whether change-request queue mode itself is on — check it after any configuration change.

### The publish lifecycle: `none` → `queued` → `open`

`publication_status` moves through these values, all on the `sourcedata_change_requests` table, for every
row of a batch at once (a batch is never mixed-status):

| Value    | Set by                                 | Meaning                                                                                             |
|----------|----------------------------------------|-----------------------------------------------------------------------------------------------------|
| `none`   | phase 1 (submit), or a release/reclaim | Not yet claimed for publishing, or a failed/stranded attempt was put back so it is claimable again. |
| `queued` | `claimNextPublishableBatch()`          | A claim held by one runner, mid-publish. Not yet on GitHub.                                         |
| `open`   | `recordPublication()`                  | A pull request exists for this batch's commit.                                                      |
| `merged` | phase 3 (not built yet)                | Reserved. Nothing in phase 2 writes this.                                                           |
| `closed` | phase 3 (not built yet)                | Reserved. Nothing in phase 2 writes this.                                                           |

`queued` is a **claim**, not a milestone on GitHub — nothing has been pushed yet while a batch sits there.
`open` means a real pull request exists; it does not mean the PR merged, and does not mean CI passed.

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
- **1** — either misconfiguration (bad `limit` argument, or the GitHub App / `GITHUB_REPOSITORY` not
  configured), or a publish attempt genuinely failed and the run stopped early. In the latter case, approved
  work remains queued and unpublished with no further retry until the next cron tick.

A monitoring check on this script should alert on a **non-zero exit**, not merely on the presence of a log
entry — the "queued" state a failed batch is left in produces no user-visible symptom anywhere else. Detail
on which batch and why is in `logs/publish-sourcedata.log` (human-readable) and
`logs/publish-sourcedata.json.log` (structured), both rotated. `GitHubApiException` carries GitHub's HTTP
status alongside its message, so a `401`/`403` in either log is the signature of a stale or revoked
installation token, not a transient failure.

### Operational failure modes

- **An unconfigured publisher accumulates approved work silently.** If change-request queue mode is on but
  the GitHub App credentials are missing or incomplete, `SourceDataPublisher::isConfigured()` returns
  `false`, `GET /health`'s `source_data_publisher` block reports `warning`, and every approved batch simply
  stays `publication_status = 'none'` forever — nothing about this looks like an error to an editor or a
  resource admin, since their own review workflow (`review_status`) completes normally. Watch `/health`,
  not the editor-facing endpoints, to catch this.
- **A non-fast-forward `422` is the expected symptom of two editors racing on one resource, not data
  loss.** `GitHubGitDataClient::updateRef()` hardcodes `force: false` and is never given a way to force —
  intentionally, so that a branch another publish landed on between this publish's `getRef()` and its own
  `updateRef()` fails loudly, with GitHub's `422`, instead of silently overwriting that other editor's
  commit. The failure propagates as a `GitHubApiException`, is caught by `PublishRunner`, releases the
  claim, and stops the run; the batch is retried — by the next claim, once the branch is quiet — rather
  than clobbering what is already there.
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

## Known limitation: a deleted resource's editors keep access

**Not closed by phase 2:** deleting a calendar or test through a change request does not purge its OpenFGA
authorization tuples. Nothing between "PR merged" and "next redeploy" performs that purge, so a deleted
diocese's former editors retain edit access on an object whose files are gone. This is deliberate — only
merge detection (phase 3) knows the deletion actually happened — and it is a known limitation, not an
oversight.

In disk mode, `RegionalDataHandler::deleteCalendar()` (and `TestsHandler`'s equivalent
`handleDeleteRequest()`) both remove the resource's files **and** purge its OpenFGA editor/viewer
operational tuples via `ResourceTuplePurgeServiceInterface`, in the same request. In queue mode, that same
purge is gated on the write actually having landed on disk (`disposition === 'applied'`) — which a queued
delete never is. Publishing a delete-operation batch's PR does not make the deletion true on disk, and
even an open PR can still be closed unmerged; purging authorization at publish time would revoke real
access on the strength of a proposal rather than a fact. The corresponding purge is deferred to phase 3,
at merge detection — the same moment the redeploy that follows a merge would actually remove the files, so
authorization and files become true together instead of drifting apart.

**Operator consequence:** if a change request deletes a diocesan or national calendar, or a test
definition, before phase 3 ships, its former editors and admins keep their OpenFGA `editor`/`viewer`
relations on that resource even after the deleting PR is merged and the files are gone from
`jsondata/sourcedata`. If this needs to be closed out manually before phase 3 exists, purge the resource's
operational tuples by hand through the same `ResourceTuplePurgeServiceInterface` mapping
(`RegionalDataHandler::fgaObjectForRequest()` / `changeResourceForRequest()` for calendars,
`TestsHandler::changeResourceForTest()` for test definitions) — the admin/governance tuple is deliberately
retained by that purge, not removed, so the resource can be recreated without losing ownership.

## Deliberately not in phase 1 or 2

- **Re-validating a payload against its JSON schema at approval or publish time.** Both phases validate
  once, at submit. Nothing re-checks a batch's content against its JSON schema between then and the
  commit `SourceDataPublisher::publish()` pushes, even though real time — and potentially a schema
  change — can pass in between.
- **Purging OpenFGA authorization tuples for a deleted calendar or test definition once its deletion is
  actually live on GitHub.** See "Known limitation: a deleted resource's editors keep access" above —
  this is deliberate, not an oversight, and is scoped to phase 3.

Neither is an oversight to be quietly forgotten; both are required before this system is considered
complete.

**`base_sha` was speculative in phase 1 and is now defined.** Phase 1's runbook predicted the column
would eventually hold "GitHub's blob sha." That is not what phase 2 actually wrote: `recordPublication()`
overwrites `base_sha` on **every row of a published batch** with the branch head commit sha the publish
branched from (the `getRef()`/`getCommitTreeSha()` starting point in `SourceDataPublisher::publish()`),
not a per-file blob sha and not per-file rebase bookkeeping. See "Publishing to GitHub (phase 2)" above.

## Retention / pruning

There is no automated prune. Unlike the OpenFGA outbox, rows here are not transient operational state —
they are the durable record of every proposal, decided or not, and the paper trail between a submission
and the pull request it became. Do not delete rows out of band.
