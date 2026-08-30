# Source-data change requests, phase 3: merge detection, and publishing from a Redis stream

**Status:** implemented, in [#916](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/pull/916)
**Extends:** [`2026-08-28-sourcedata-change-requests-design.md`](2026-08-28-sourcedata-change-requests-design.md)
**Tracked by:** [#902](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/issues/902) (phase 3),
[#915](https://github.com/Liturgical-Calendar/LiturgicalCalendarAPI/issues/915) (Redis stream)
**Date:** 2026-08-30
**Repos affected:** `LiturgicalCalendarAPI`; a follow-up issue in `LiturgicalCalendarFrontend` for the inbox UI

The parent design's "Phase 3: merge detection" section is three paragraphs, and it defers four
decisions to this phase by name. This document makes those decisions and specifies what phase 3
builds. It does not restate the parent design; read that first.

## Where phases 1 and 2 left off

| Phase | Content                                                      | State  | PR   |
| ----- | ------------------------------------------------------------ | ------ | ---- |
| 1     | Change request store, approval gate, RBAC-scoped views       | merged | #912 |
| 2     | The publisher: GitHub App, Git Data API, rolling PRs         | merged | #914 |
| 3     | Merge detection, publication side effects, stream publishing | this   | —    |

Nothing writes `merged` or `closed` today. `publication_status` reaches `open` and stops there,
which means every side effect that depends on a change actually landing has never fired.

## Scope

In:

1. Merge detection — poll the rolling pull requests, write `merged` / `closed`.
2. The OpenFGA operational-tuple purge for a merged resource deletion.
3. A decision on whether `closed` belongs in the accumulation-base exclusion.
4. Claim ownership — a claim token, so a late release cannot revoke a live claim.
5. Notifying the submitter, API side.
6. Publishing from a Redis stream, with cron demoted to the backstop (#915).

Deliberately out, each an issue of its own:

- **Per-file `base_sha` and rebase detection.** `recordPublication()` overwrites every row's
  `base_sha` with the batch-level branch head, so the per-file bookkeeping a rebase check needs is
  already gone. Restoring it changes what phase 2 persists per row, which is a larger change than
  merge detection and is not a prerequisite for it.
- **Schema re-validation at the approval gate.** A batch approved against one schema and published
  after that schema changed produces a pull request whose CI fails `lint:jsondata` — visible, on
  the wrong side of the gate, and unchanged by anything here.
- **Moving the claim protocol into Redis consumer groups.** #915 puts this out of scope explicitly,
  and it would make Redis mandatory for a self-hoster. See "Why the queue stays in Postgres".

## Merge detection

### Poll pull requests, not batches

The rolling branch is per **resource**, and `SourceDataPublisher` reuses an already-open pull
request via `findOpenPullRequest()`. So two batches for one resource — the same submitter's
successive proposals, or two different editors' — are published onto one branch and recorded with
one `pr_number`. Iterating batches would issue N identical `GET /pulls/{number}` calls and answer
the same question N times.

`MergePollRunner` therefore selects the DISTINCT `pr_number` values among rows at
`publication_status = 'open'`, oldest first, and polls each once. One GitHub call decides the fate
of every batch on that pull request.

`GitHubGitDataClient` grows one method, `getPullRequest(int $number): PullRequestState`, returning
a small readonly DTO of `state`, `merged`, `mergeCommitSha` and `headSha`. A 404 here is a real
failure, not a value — the only 404-as-value in that class stays `getRef()`.

`pr_number` is nullable in the schema but is never null in practice on an `open` row:
`SourceDataPublisher` opens a pull request whenever `findOpenPullRequest()` returns null, and
`openPullRequest()` either returns an `int` or throws. An `open` row with a null `pr_number` is
therefore an unexplained state — unpollable, and stuck forever if the poller silently skipped it —
so the runner counts and logs those rows rather than filtering them out of its query and moving on.

| Observed                         | Written                                                                                                |
| -------------------------------- | ------------------------------------------------------------------------------------------------------ |
| `state: open`                    | nothing                                                                                                |
| `merged: true`                   | `publication_status = merged`, `merge_commit_sha`, `publication_settled_at`                            |
| `state: closed`, `merged: false` | `publication_status = closed`, `review_status = rejected`, `rejected_reason`, `publication_settled_at` |

On close-unmerged, nothing needs reverting because nothing was ever live. `rejected_reason` is
generated rather than left null, so the editor's history says why a batch they never withdrew is
rejected: `Pull request #N was closed without merging.`

### Containment is verified, not assumed

A batch's rows share a pull request number, but sharing a pull request is not the same as being
IN the merge. A human clicking Merge concurrently with a publish is enough to separate them:

1. The publisher fast-forwards `litcal-data/<type>/<id>` to batch C's commit.
2. Before `findOpenPullRequest()`/`recordPublication()` complete, a reviewer merges PR N at the
   head it had a moment earlier.
3. C is recorded `open` with `pr_number = N`, its commit sits on the branch, and PR N is merged
   without it.

Marking C `merged` would assert that C reached the repository. The publisher selects approved rows
that are not yet `merged`, so C would never be attempted again and its content would be lost
silently. That is the same failure mode phase 2's age-based ancestor exclusion was chosen to avoid
— "marking ancestors merged asserts they were published" — reached from the other direction, and
it deserves the same answer: assert only what is observed.

So containment is checked per batch:

- `commit_sha === headSha` — contained, no extra call. This is the ordinary case: one batch per
  pull request, or the newest batch on a shared one.
- otherwise, one `GET /repos/{o}/{r}/compare/{commit_sha}...{merge_commit_sha}`. A `status` of
  `ahead` or `identical` means the merge commit's history contains the batch's commit.

Cost is bounded by the number of batches on the merged pull request whose commit is not its head —
typically zero, occasionally one or two.

A batch found NOT contained is reset to `publication_status = 'none'` with `publish_attempts`
cleared and `pr_number` / `commit_sha` / `branch` left in place for forensics. Its commit is still
on the branch; the next publish for that resource finds no open pull request and opens a fresh one
that carries the stranded commit along with the new work. This asserts nothing false, self-heals
on the next tick, and degrades — as everything else in this feature does — toward re-publishing
rather than toward losing content.

### Failure handling

`MergePollRunner` follows `PublishRunner`'s rules, because they were arrived at the hard way and
the failure modes are identical:

- **Stop, don't hammer.** A `\Throwable` from a poll stops the run. If GitHub is down or the
  installation credential is stale, every remaining pull request fails identically.
- **A run reports what it did.** `MergePollRunResult{merged, closed, reset, stoppedOnFailure}`,
  and a non-zero exit code when it stopped early, so a stale credential cannot pile up silently.
- **No new claim protocol.** Polling holds nothing and claims nothing: the writes are guarded
  `UPDATE`s keyed on `(batch_id, publication_status = 'open')`, so two pollers racing produce one
  transition and one no-op. There is no stranded state to reclaim, which is why polling needs no
  grace period and no attempt bound.

## The OpenFGA purge, and the trap in its trigger

In disk mode, deleting a calendar or a test definition purges that resource's operational
(editor/viewer) OpenFGA tuples, and deliberately keeps the `admin` tuple so ownership survives
recreation. In queue mode the purge is gated on `commit()` returning `disposition === 'applied'`,
which never happens — so the tuples stay live. Until this ships, a diocese deleted through a change
request keeps its former editors' access to an object whose files are gone.

Only merge detection knows the deletion actually happened, which is why the parent design put the
purge here rather than at publish time: publishing a pull request does not make a deletion true,
and a pull request can still be closed unmerged.

### `operation = 'delete'` is the wrong trigger

The obvious trigger — "the batch contains a delete" — is wrong, and wrong in the dangerous
direction.

`RegionalDataHandler::writeI18nFiles()` stages a `DELETE` for every locale file in a calendar's
`i18n/` folder that is no longer named in `metadata.locales` (`src/Handlers/RegionalDataHandler.php:1262`).
That is an ordinary update: a translator dropped a language from a calendar that very much still
exists. Purging on it would revoke every editor and viewer on a live calendar because one
translation was removed.

### The handler that deletes a resource says so

`SourceDataWriter::commit()` takes a new `bool $deletesResource = false`.

- `DiskSourceDataWriter` ignores it. It already purges inline, gated on the write having landed.
- `ChangeRequestSourceDataWriter` merges `deletes_resource: true` into the row `metadata` JSONB,
  beside the `authorizing_relation` key already stored there.

Only two call sites pass `true`: `RegionalDataHandler::deleteCalendar()` and
`TestsHandler::handleDeleteRequest()` — the two places that remove a whole resource rather than one
of its files. The signal is set by the code that knows, at the moment it knows.

On `merged`, a batch whose metadata carries the flag gets
`ResourceTuplePurgeServiceInterface::purgeForObject("{resource_type}:{resource_id}")`. The row
already stores both halves of that object string rite-qualified, so nothing is re-derived and
nothing can double-qualify (`roman/roman/US`) the way rebuilding a `ChangeResource` would.

An absent flag means no purge. Every row written before this ships lacks it, so the pre-existing
corpus fails closed toward NOT revoking — the safe direction for an authorization decision.

The purge is best-effort and must never fail the transition: the batch is already `merged`, that
is a fact about the repository, and a reachable OpenFGA is not a precondition for recording it.
A failure logs and leaves the tuples for `ResourceTuplePurgeReconciler`'s sweep, exactly as the
disk-mode path does.

The `rmdir()` half of disk-mode deletion has no analogue and needs none: the redeploy that follows
a merge replaces `jsondata/sourcedata` from the merged tree, and rsync removes the directory.

## `closed` in the accumulation base: decided, with no SQL change

The parent design records this as an open question phase 3 must settle, and warns against leaving
it carried "by coincidence".

The accumulation base is `review_status IN ('submitted','approved') AND publication_status <> 'merged'`.
A `closed` batch is admitted by the publication half and excluded by the review half, because
phase 3 writes `review_status = 'rejected'` alongside `closed`.

**That is correct, not coincidental, and the predicate does not change.** The publication axis
answers "is this row's content in the repository?" — and for a pull request closed unmerged the
answer is no, so `closed` genuinely belongs in the base on that axis. What excludes it is the
review axis answering a different question: a rejected batch is no longer a proposal, whatever
happened to its pull request. Two axes, two answers, and the parent design's own reason for
keeping them as two columns rather than one enum.

What changes is that this stops being an accident:

- `SourceDataChangeRequestRepository`'s `UNPUBLISHED_PREDICATE` docblock states it.
- Two tests pin it: a `closed` + `rejected` row is excluded from the base, and a `closed` row that
  is still `approved` — constructible only by direct SQL — IS included, proving the exclusion comes
  from the review axis and not from `closed` being mistaken for published.
- `NOT_SUPERSEDED_BY_PUBLISHED`'s floor stays `publication_status = 'merged'` alone. A `closed`
  batch published nothing, so it must never become the floor that excludes older rows.

## Claim ownership

`releaseClaim()`'s `publication_status = 'queued'` guard identifies *a* claim, not *whose*. The
reachable consequence, recorded in that method's docblock: runner A is merely slow, the grace
period elapses, `reclaimStaleClaims()` releases A's claim and spends an attempt, B claims and
starts publishing, then A's own doomed call returns and A's release revokes B's LIVE claim and
spends a second attempt. A batch that is simply large parks after three cycles instead of five.

`publish_claim_token UUID NULL` closes it:

- `claimNextPublishableBatch()` generates a token inside the claiming transaction and returns
  `?PublishClaim{batchId, token}` instead of `?string`.
- `releaseClaim(string $batchId, string $token)` adds `AND publish_claim_token = :token` to the
  guarded `UPDATE`, so a stale runner's release matches nothing and — the point — spends no attempt.
- `recordPublication()` and `reclaimStaleClaims()` both clear the token, so no token outlives the
  claim it names.

`ClaimReleaseOutcome` gains `CLAIM_LOST`: the row is `queued` under a different token, so another
runner holds it. That is not `SETTLED_ELSEWHERE` — nothing is published — and not `NOT_CLAIMED`.
`PublishRunner` treats it as a failure and stops the run: rare, visible, and safe, since the runner
that actually holds the claim carries on.

This is bundled with phase 3 rather than issued separately because it touches the same table, the
same claim/release/reclaim contract, and the same migration as `publication_settled_at`. One
migration instead of two, as the phase-3 handoff argued.

## Notifying the submitter

`GET /auth/notifications` exists, but `UserNotificationRepository::fetchInbox()` reads
`access_requests` and nothing else. Change-request notifications are new work, not a wiring change.

Phase 3 notifies on **publication settling** — `merged` or `closed` — which is what phase 3
produces. Review decisions (approve, reject at the gate) are equally notification-worthy and equally
unnotified today, but that is a phase-1 gap and belongs to its own issue rather than being smuggled
in here.

### Merged in PHP, not in SQL

`fetchInbox()` queries each source separately, merges the two lists in PHP, sorts by timestamp
descending, and slices to `limit`. Totals and unread counts come from two aggregate queries and are
summed.

A single `UNION` with `COUNT(*) OVER ()` window functions over two differently-shaped row sets is
the alternative, and it is the wrong trade here: the shapes genuinely differ, the window functions
would have to span the union to keep `total` and `unread_count` honest, and the result is one query
that is hard to read and harder to prove correct. Two straightforward queries and an `usort` are
verifiable by inspection. Neither source can exceed the 50-row contract, so the merge is bounded.

### The cursor is a new column, not `updated_at`

`updated_at` moves on every write to the row — claim, release, reclaim, record, transition — so it
answers "when was this row last touched", not "when did this become news for the submitter".
`publication_settled_at TIMESTAMPTZ NULL` is written once, by the transition to `merged` or
`closed`, and is what the unread comparison against `user_notification_state.last_notification_seen_at`
uses.

One item per batch, not per file: an editor who changed a calendar and its fourteen i18n files
proposed one thing.

```json
{
  "type": "change_request_published",
  "batch_id": "…",
  "resource_type": "diocesan_calendar",
  "resource_id": "roman/dioceseoflondon_gb",
  "publication_status": "merged",
  "pr_number": 1234,
  "settled_at": "2026-08-30T10:15:00+00:00",
  "unread": true
}
```

`items` therefore becomes a discriminated list — `access_request_reviewed` and
`change_request_published` carry different keys — and the handler documents that clients must switch
on `type`. Rendering it is a follow-up issue in `LiturgicalCalendarFrontend`.

## Publishing from a Redis stream (#915)

### Why the queue stays in Postgres

`sourcedata_change_requests.publication_status` remains the source of truth. A batch can be
withdrawn or rejected in Postgres, so a second queue would be a second truth that can disagree with
it. Redis is an accelerator over a durable database queue, never a replacement for one — the same
arrangement the OpenFGA outbox already documents in `BackstopRunner`.

The gain is latency: the delay between an approval and its pull request existing drops from up to
one cron interval to sub-second on the happy path.

### The message is a hint, never a work item

`SourceDataPublishNotifier` mirrors `OutboxNotifier` exactly: a best-effort `XADD` carrying
`batch_id`, a null `\Redis` disabling it, and a `\RedisException` logged at warning rather than
raised. It is called from **two** places, and missing either is the easy mistake:

1. `ChangeRequestAdminHandler::approve()`, after the status `UPDATE` commits — the same ordering
   constraint the outbox already documents.
2. `ChangeRequestSourceDataWriter::commit()` on auto-approval, which is the common path whenever an
   admin edits a resource they administer. A batch approved there never passes through the admin
   handler at all.

The consumer **ignores the batch id except for logging**. On a message it calls
`PublishRunner::runOnce()`, which claims from Postgres exactly as cron does: the stream says *when*
to look, the database says *what* is claimable and by whom. Three consequences, all of them the
point:

- A lost `XADD` costs latency, never correctness. The backstop finds the batch.
- A duplicate or out-of-order message costs one wasted claim attempt against an empty queue.
- The consumer inherits every guarantee phase 2 built — the claim protocol, bounded attempts,
  parking, stop-don't-hammer — without reimplementing any of it.

### The stream seam widens by one type

`StreamConsumerInterface::readOnce()` is `callable(int): void` and `RedisStreamConsumer` hardcodes
the payload field `row_id`, because the outbox's unit of work is an integer row id. The unit of
publication is a batch id, which is a UUID.

`readOnce()` widens to `callable(string): void`, and `RedisStreamConsumer` takes its payload field
name as a constructor argument defaulting to `row_id`. The outbox's `ConsumerLoop` keeps its `(int)`
cast and inherits the `<= 0` bad-message guard that moves with it — the consumer no longer knows
what a valid id looks like, so the loop that does must say.

`ConsumerLoop` itself is not reused: it is constructor-typed to `OutboxProcessorInterface`.
`PublishConsumerLoop` is its sibling — same `tick()` / `run()` split, same `ensureGroup()` memoisation,
same "systemd restarts on crash" contract.

### Cron becomes the backstop it already resembles

`scripts/publish-sourcedata.php` keeps its CLI (`[limit]`), its exit codes and its documented
behaviour. Its ROLE changes: it catches a lost `XADD` or a dead consumer, which is what
`BackstopRunner` does for the outbox, and its grace period is already sized for exactly that.

Redis stays optional. `REDIS_SOCKET` / `REDIS_HOST` are commented out in `.env.example`, and a
self-hoster running cron only keeps working unchanged — the same promise the disk-write path makes
to a self-hoster without Postgres or OpenFGA.

## Entry points, and the trap they exist to avoid

Phase 2's most expensive defect was a script CONSTRUCTING what every test INJECTED: the cron entry
point took `LoggerFactory::create()`'s default processors, which throw on non-request context, so
every log line the runner wrote would have thrown in production — including the ones inside its
catch blocks, before `releaseClaim()` ran. Every test passed. No test crossed that seam.

Phase 3 adds two more entry points to the same wiring, which triples the surface for that defect.
So the wiring moves out of the scripts:

`SourceDataPublisherFactory`, in `src/`, builds the logger, the PDO connection, the repository, the
timeout-bounded Guzzle client, the umask-protected token cache, the publisher, the runners and the
optional notifier — and returns them assembled. `phpstan.neon.dist` scans `paths: [src]` only, so
this also brings the wiring under the same level-10 pass as everything else, instead of leaving it
in a file that needs a standalone run to be checked at all.

| Entry point                          | Kind       | Does                                                  |
| ------------------------------------ | ---------- | ----------------------------------------------------- |
| `scripts/publish-sourcedata.php`     | cron       | `PublishRunner::runOnce()` — the backstop             |
| `scripts/poll-sourcedata-merges.php` | cron       | `MergePollRunner::runOnce()`                          |
| `bin/publish-sourcedata-consumer`    | long-lived | `PublishConsumerLoop::run()`, plus the idle-tick poll |

The consumer's idle tick — a `readOnce()` that blocked and returned nothing — also runs
`MergePollRunner`, as the parent design describes. It is rate-limited to at most once per 60
seconds: `blockMs` is 5000, so an unlimited idle tick would poll GitHub 720 times an hour to watch
for a transition nobody is waiting on.

## Data model

One migration, two columns:

| Column                   | Type               | Written by                                                   |
| ------------------------ | ------------------ | ------------------------------------------------------------ |
| `publish_claim_token`    | `UUID NULL`        | `claimNextPublishableBatch()`; cleared on record and reclaim |
| `publication_settled_at` | `TIMESTAMPTZ NULL` | the transition to `merged` or `closed`                       |

No new table. No enum change: `chk_scr_publication_status` already admits `merged` and `closed`,
which phase 1 wrote in anticipation of exactly this.

A partial index on `(pr_number) WHERE publication_status = 'open'` serves the poller's distinct-PR
scan, and a partial index on `(submitted_by_sub, publication_settled_at DESC) WHERE publication_settled_at IS NOT NULL`
serves the inbox.

## Health

`GET /health`'s `source_data_publisher` block gains `open_batches` and
`oldest_open_age_seconds`. A batch sitting `open` is not an error — a pull request awaiting review
is the normal state — so this is reported, not alarmed on, and only becomes a `warning` past a
generous threshold that means the poller is not running at all rather than that a reviewer is slow.

The block keeps degrading to zeroes behind `Connection::isConfigured()` and a catch-everything
guard, for the reason `buildOutboxStats()` does: a database that is down must not break the endpoint
monitoring relies on.

## Error handling

| Failure                                             | Behaviour                                                                        |
| --------------------------------------------------- | -------------------------------------------------------------------------------- |
| GitHub unreachable during a poll                    | Log, stop the run, exit 1. No status is written; the next tick retries           |
| `open` row with a null `pr_number`                  | Unpollable and unexplained. Counted and logged; never silently skipped           |
| `GET /pulls/{n}` returns 404                        | A configuration or repository problem, not a value. Stop the run                 |
| `compare` fails for a containment check             | The batch is NOT marked merged. Left `open`; the next tick re-checks             |
| Batch on a merged PR, commit not contained          | Reset to `none`, attempts cleared. Re-published under a fresh pull request       |
| `purgeForObject()` throws after a merge             | Logged. The transition stands; `ResourceTuplePurgeReconciler` sweeps the tuples  |
| Redis unavailable at approval                       | `XADD` logged at warning and skipped. Cron publishes it                          |
| Redis unavailable to the consumer                   | The consumer crashes; systemd restarts it. Cron publishes meanwhile              |
| Late `releaseClaim()` whose token no longer matches | `CLAIM_LOST`. No attempt spent. The run stops; the live claim's runner continues |

## Testing

Beyond the ordinary unit coverage, four cases are worth naming because they are the ones that hide:

- **Two batches, one pull request.** The merged transition must move both, and exactly one GitHub
  call must be made.
- **The not-contained batch.** A batch whose `commit_sha` is neither the head nor an ancestor is
  reset to `none`, NOT marked merged. Asserted against the repository row, not against a log line.
- **The locale-delete false positive.** A batch that deletes an i18n file without deleting the
  calendar must NOT purge tuples when it merges. This is the defect the `deletes_resource` flag
  exists to prevent, and it is invisible unless a test names it.
- **Claim ownership under real concurrency.** Two real OS processes via `proc_open`, against the
  actual `claimNextPublishableBatch()` / `releaseClaim()` methods rather than hand-copied SQL.
  Phase 2 found four concurrency defects and every one needed a genuinely concurrent runner; a test
  that duplicates the fixed query passes even with the fix reverted.

Both entry points and the consumer must be RUN, not only unit-tested, for the reason the wiring
moved into a factory: the two defects that shipped with green suites were both at a seam no test
crossed.
