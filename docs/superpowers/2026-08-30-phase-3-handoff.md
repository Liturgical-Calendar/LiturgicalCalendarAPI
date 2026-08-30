# Source-data change requests — phase 3 handoff

Written 2026-08-30, at the end of the session that built phase 2. Phases 1 and 2 are merged to
`development`. This exists so a fresh session can start phase 3 without re-deriving decisions or
re-discovering traps.

**Read first:** `docs/superpowers/specs/2026-08-28-sourcedata-change-requests-design.md` — the
approved design, corrected during phase 2 wherever it described things that were never built. Its
"Phase 3: merge detection" section is the starting point.

## Where things stand

| Phase                                                      | State       | PR   |
| ---------------------------------------------------------- | ----------- | ---- |
| 1 — change request store, approval gate, RBAC-scoped views | merged      | #912 |
| 2 — the publisher (GitHub App, Git Data API, rolling PRs)  | merged      | #914 |
| 3 — merge detection                                        | not started | —    |

Issue #902 tracks the whole arc and stays open until phase 3 lands.

The GitHub App is registered and installed: `litcal-publisher[bot]`, bot user id `322643714`.
`GITHUB_APP_*`, `GITHUB_REPOSITORY` and `GITHUB_BASE_BRANCH` are set in `.env.staging` on the live
server.

**Not yet done, and worth confirming before assuming phase 2 is live:** no cron entry invokes
`scripts/publish-sourcedata.php`. Until one does, `/health` reports the publisher `ok` while
approved batches sit at `publication_status = 'none'` indefinitely. Check
`GET /health` → `source_data_publisher` on staging.

## What phase 3 owes, inherited explicitly

These are obligations phase 2 deferred **on the record**, not oversights. Each is documented in the
spec or the runbook; do not treat any of them as newly discovered.

1. **Purge OpenFGA tuples for deleted calendars and test definitions.** Today, deleting a calendar
   through a change request leaves its authorization tuples live: a deleted diocese's former editors
   retain edit access on an object whose files are gone. Only merge detection knows a deletion
   actually happened, which is why it was deferred here rather than done at publish time. Use the
   same resource-to-object mapping the disk path uses —
   `RegionalDataHandler::changeResourceForRequest()` and `TestsHandler::changeResourceForTest()`.
   CodeRabbit added a nuance worth keeping: consider **preserving the `admin` tuple** so ownership
   survives a recreation of the same resource id.

2. **Decide whether `closed` belongs in the accumulation-base exclusion.** The predicate excludes
   `merged` and nothing else. Phase 3 is what first writes `closed`, so phase 3 must decide, and stop
   relying on the `review_status = 'rejected'` filter to carry it by coincidence.

3. **Claim ownership.** `releaseClaim()`'s `publication_status = 'queued'` guard identifies *a* claim,
   not *whose*, so a late release can revoke another runner's live claim. Bounded and visible today.
   The fix is a claim-token column compared in the `WHERE` — and phase 3 must touch the same columns
   and the same claim/release/reclaim contract anyway, so doing both together costs one migration
   instead of two.

4. **`base_sha` is currently unusable for rebase detection.** `recordPublication()` overwrites every
   row's `base_sha` with the batch-level branch head, destroying the per-file bookkeeping a rebase
   check would need. Decide whether to keep per-file base shas before promising that feature.

5. **Schema re-validation before publication.** `approveBatch()` is a single status `UPDATE`. A batch
   approved against one schema and published after that schema changed will produce a PR whose CI
   fails `lint:jsondata` — visible, but a backstop on the wrong side of the gate.

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

## Related open issues

- **#915** — publish from a Redis stream with cron as the backstop. Latency, not correctness; the
  queue of record stays in Postgres. Note three of phase 2's four concurrency defects were in
  machinery Redis consumer groups provide natively.
- **#913** — `PATCH` on `/data/*` returns `201 Created` when updating. Pre-existing, published in
  `openapi.json`, so fixing it is a contract change needing its own CHANGELOG entry.

## Working agreements

Feature branches from `development`, PRs target `development`, never `main`. Never `--no-verify`.
Do not push immediately after committing — batch, because CodeRabbit is rate-limited. Never skip git
hooks.
