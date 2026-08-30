# Source-data change requests — phase 3 handoff

Written 2026-08-30, at the end of the session that built phase 2. Phases 1 and 2 are merged to
`development`. This exists so a fresh session can start phase 3 without re-deriving decisions or
re-discovering traps.

**Read first:** `docs/superpowers/specs/2026-08-28-sourcedata-change-requests-design.md` — the
approved design, corrected during phase 2 wherever it described things that were never built. Its
"Phase 3: merge detection" section is the starting point.

## Where things stand

| Phase                                                       | State  | PR             |
| ------------------------------------------------------------|--------|----------------|
| 1 — change request store, approval gate, RBAC-scoped views  | merged | #912           |
| 2 — the publisher (GitHub App, Git Data API, rolling PRs)   | merged | #914           |
| 3 — merge detection, the purge, claim ownership, the stream | built  | not yet opened |

Issue #902 tracks the whole arc and stays open until phase 3's pull request merges.

The GitHub App is registered and installed: `litcal-publisher[bot]`, bot user id `322643714`.
`GITHUB_APP_*`, `GITHUB_REPOSITORY` and `GITHUB_BASE_BRANCH` are set in `.env.staging` on the live
server.

**Not yet done, and worth confirming before assuming phase 2 is live:** no cron entry invokes
`scripts/publish-sourcedata.php`. Until one does, `/health` reports the publisher `ok` while
approved batches sit at `publication_status = 'none'` indefinitely. Check
`GET /health` → `source_data_publisher` on staging.

## What phase 3 owes, inherited explicitly

These were obligations phase 2 deferred **on the record**, not oversights. Each was documented in the
spec or the runbook; none was newly discovered mid-phase-3. Items 1–3 are now **done**; 4 and 5 remain
and each now has its own filed follow-up issue (see "Follow-up issues filed" below).

1. **DONE — purge OpenFGA tuples for deleted calendars and test definitions.** Closed by
   `0142f820` (flag a batch that deletes a resource, not merely one of its files — `metadata.deletes_resource`),
   `22cfa70a` (purge a deleted resource's tuples once its deletion has merged), and `f85b321b`
   (require unanimous `deletes_resource` across a batch before purging — the CodeRabbit nuance below
   was folded into this same commit, not left as a follow-up). `MergePollRunner::purgeIfResourceDeletion()`
   uses the same resource-to-object mapping the disk path uses —
   `RegionalDataHandler::changeResourceForRequest()` and `TestsHandler::changeResourceForTest()` — and
   is called only once a batch is confirmed `merged`. The `admin` tuple is preserved, exactly as
   CodeRabbit's nuance asked, so ownership survives a recreation of the same resource id. See the
   runbook's "Closed: a deleted resource's editors used to keep access".

2. **DONE — decided whether `closed` belongs in the accumulation-base exclusion.** Closed by
   `024c9f21` (pin the closed-in-accumulation-base decision). `closed` is deliberately **not** added to
   the publication-axis exclusion (`publication_status <> 'merged'` stays as-is): a closed-unmerged
   batch published nothing, so the publication axis correctly still admits it to the accumulation base.
   What excludes it is the review axis — phase 3 always writes `review_status = 'rejected'` alongside
   `closed`, in the same update, rather than relying on that pairing as a coincidence the exclusion
   predicate happens to benefit from. Pinned by
   `SourceDataChangeRequestRepositoryTest::testClosedAndRejectedRowIsExcludedFromTheAccumulationBase()`
   and its deliberate mirror image proving a `closed`-but-still-`approved` row stays in the base.

3. **DONE — claim ownership.** Closed by `9df17e80` (add `publish_claim_token` and
   `publication_settled_at`), `2031ed8a` (a claim now identifies whose it is, not merely that one
   exists), `75ab5056` (fix `releaseClaim()`'s docblock to describe the token it now checks), and
   `56d57896` (`CLAIM_LOST` continues under branch contention, not stops). `claimNextPublishableBatch()`
   stamps a fresh token on every claim; `releaseClaim()` and `reclaimStaleClaims()` both compare it in
   their `WHERE` before clearing, so a late release from one runner can no longer revoke a different
   runner's live claim. One migration did carry both this and the merge-detection schema work, as
   anticipated.

4. **Remains — `base_sha` is currently unusable for rebase detection.** `recordPublication()` still
   overwrites every row's `base_sha` with the batch-level branch head, destroying the per-file
   bookkeeping a rebase check would need. Not attempted in phase 3; filed as #917.

   **Since addressed, in part.** #917 restored the bookkeeping: `base_sha` is written per file at
   submission and the branch head moved to its own `publish_base_sha` column. The rebase check itself
   is still unbuilt — see the runbook's "`base_sha` and `publish_base_sha` are two different shas".

5. **Remains — schema re-validation before publication.** `approveBatch()` is still a single status
   `UPDATE`. A batch approved against one schema and published after that schema changed will produce a
   PR whose CI fails `lint:jsondata` — visible, but a backstop on the wrong side of the gate. Not
   attempted in phase 3; filed as #918.

### Follow-up issues filed

Task 15 deliberately did not run `gh issue create` — creating public GitHub issues was left to the
repository owner. Both are now filed:

- **#917 — "Keep per-file `base_sha` so rebase detection is possible"** — Deferred from phase 3 (#902).
  `recordPublication()` overwrites every row's `base_sha` with the batch-level branch head, destroying
  the per-file bookkeeping a rebase check needs. See
  `docs/superpowers/specs/2026-08-30-sourcedata-merge-detection-design.md`, Scope.
- **#918 — "Re-validate a change request against the current schema before publishing"** — Deferred from
  phase 3 (#902). `approveBatch()` is a single status `UPDATE`, so a batch approved against one schema
  and published after that schema changed produces a pull request whose CI fails `lint:jsondata` — a
  backstop on the wrong side of the gate. See
  `docs/superpowers/specs/2026-08-30-sourcedata-merge-detection-design.md`, Scope.

## Decisions already made — do not re-open

Each cost real analysis; the reasoning is in the spec and in the phase 2 commit messages.

- **Ancestor exclusion is by age**, not by rewriting status. Marking an ancestor `merged` would assert
  it was published, and the publisher skips already-merged rows — so a broken containment assumption
  would lose content silently. An `id`-based tiebreak was tried and rejected: `id` is
  `gen_random_uuid()` and carries no temporal information.
- **`markBatchPublicationStatus()` is deliberately unconditional** — it is what marks `merged`.
  `releaseClaim()` is the conditional one.
- **`force: false` is load-bearing, not defensive.** Serialisation per resource is optimistic
  concurrency: the loser of a same-resource race gets a 422 and retries against the moved ref.
- **A 422 is not an outage** — GitHub answering in detail is evidence the API is healthy. It logs a
  warning, continues, and still consumes an attempt.
- **The decrees layout stays aggregate.** Splitting `decrees.json` would not remove the collision, as
  the `i18n/` and `lectionary/` sidecars are aggregates across 14 locales too.
- **There is no dead-letter queue.** A repeatedly failing batch is *parked* after
  `MAX_PUBLISH_ATTEMPTS` and reported as `parked_batches` in `/health`.

## Traps this project has already paid for

Every one of these cost at least one round. They are not hypothetical.

- **Green tests, dead in production.** Twice: an OIDC sub-route allowlist that no test could exercise
  (tests inject `oidc_user` directly), and a logger whose default processors threw on every record the
  runner wrote (tests injected a logger with no processors). When a script *constructs* what tests
  *inject*, no test crosses that seam. Run the actual entry point.
- **`GITHUB_REPOSITORY` is a GitHub Actions built-in.** It is injected into every job as `owner/repo`.
  Tests that clear only `$_ENV` are undone by `getEnvString()`'s `getenv()` fallback — this failed CI
  while passing locally every time. Clear both layers.
- **Concurrency defects hide from single-threaded tests.** Four were found in this feature, each only
  by *running* the concurrent case. PHP is single-threaded: use two connections, or `proc_open` two
  real processes. A test that hand-duplicates the fixed SQL instead of calling the real method passes
  even with the fix reverted.
- **`openapi.json` is canonical literal UTF-8, zero `\uXXXX` escapes.** Never round-trip it through
  `json_decode`/`json_encode` — PHP escapes non-ASCII by default and you get a ~14,000-line phantom
  diff. Edit as text. `composer lint:jsondata`, not `lint:openapi`, is what catches encoding drift.
- **`phpstan.neon.dist` scans `paths: [src]` only.** Anything under `scripts/` needs a standalone run.
- **Unquoted heredocs run command substitution** and silently eat backticked words from commit
  messages and docs. Use `<<'EOF'`.
- **PostgreSQL rejects `FOR UPDATE` with `GROUP BY`.** The candidate-then-lock pattern in
  `claimNextPublishableBatch()` exists for that reason, and its lock query repeats the claimability
  predicate — without it, two runners claim the same batch once the first commits.

### What phase 3 added to this list

- **`operation = 'delete'` does not mean a resource was deleted.**
  `RegionalDataHandler::writeI18nFiles()` stages a `DELETE` row for every locale file dropped from
  `metadata.locales`, on a calendar that still exists — a translator removing one language from a
  calendar's `i18n` set produces the same operation an actual calendar deletion does. Keying an
  authorization decision on the operation, rather than on the purpose-built
  `metadata.deletes_resource` flag, would revoke every editor on a live calendar because of an ordinary
  translation edit. This is the exact mistake `purgeIfResourceDeletion()` was written to avoid — see the
  runbook's "Closed: a deleted resource's editors used to keep access".
- **Sharing a pull request number is not being in its merge.** The rolling branch is per resource, so
  two batches for that resource can share one `pr_number`. A reviewer clicking Merge concurrently with a
  publish separates them: the merge takes the branch head a moment before the publish's own commit
  lands, and that batch is left recorded against a pull request that closed without actually carrying
  it. Marking it `merged` on the strength of the shared `pr_number` alone would assert it reached the
  repository and lose its content silently — the publisher never re-attempts a row it believes already
  `merged`. `MergePollRunner` verifies containment instead (an equality check against the merge head, or
  one `compareCommits()` call), and a batch that fails the check is returned to `publication_status =
  'none'` and republished under a fresh pull request rather than trusted. See the runbook's "`reset=N`
  on the poll summary line".
- **A DB-level state combination with no code path to reach it still needs a test.** `closed` paired
  with `approved` (rather than the `rejected` phase 3 always writes alongside it) cannot be produced by
  any handler today, but the accumulation-base predicate would silently do the wrong thing if someone
  ever "simplified" it to exclude `closed` directly. `024c9f21` constructs that state with a raw `UPDATE`
  in the test itself specifically to pin the current, correct behaviour against that future edit — see
  `SourceDataChangeRequestRepositoryTest::testClosedButStillApprovedRowRemainsInTheAccumulationBase()`.

## Related open issues

- **#913** — `PATCH` on `/data/*` returns `201 Created` when updating. Pre-existing, published in
  `openapi.json`, so fixing it is a contract change needing its own CHANGELOG entry.

`#915` (publish from a Redis stream with cron as the backstop) is no longer open here: phase 3 built it
— `bin/publish-sourcedata-consumer`, `SourceDataPublishNotifier`, `PublishConsumerLoop` — as the
"consumer as an optional accelerator" the runbook's "Merge detection (phase 3)" section documents. Close
`#915` alongside phase 3's own pull request, not as a separate follow-up.

## Working agreements

Feature branches from `development`, PRs target `development`, never `main`. Never `--no-verify`.
Do not push immediately after committing — batch, because CodeRabbit is rate-limited. Never skip git
hooks.
