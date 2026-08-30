<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services\SourceData;

use LiturgicalCalendar\Api\Enum\ClaimReleaseOutcome;
use LiturgicalCalendar\Api\Repositories\SourceDataChangeRequestRepository;
use LiturgicalCalendar\Api\Services\GitHub\GitHubApiException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Cron-driven runner: claims approved change-request batches one at a time and publishes
 * each as a commit and rolling pull request via {@see SourceDataPublisherInterface}.
 *
 * # A batch must never be stranded
 *
 * `SourceDataChangeRequestRepository::claimNextPublishableBatch()` marks a batch `queued`.
 * If publication then fails and nothing puts the batch back to `none`, that batch is never
 * picked up again — invisible to the operator, invisible to the editor, and indistinguishable
 * from success on the editor's side. That is strictly worse than a batch that retries. So
 * every path out of a failed publish here calls `releaseClaim()` before returning — for ANY
 * `\Throwable`, not only {@see \LiturgicalCalendar\Api\Services\GitHub\GitHubApiException}. A
 * `TypeError` or an out-of-memory error thrown from inside the publisher must not leave work
 * stranded any more than an expected GitHub API failure would. `releaseClaim()` itself is
 * wrapped too: if the same outage that failed the publish also breaks that call, the batch
 * still cannot be un-stranded from here, but the exception is logged rather than escaping as
 * a raw fatal that an operator would have to find by grepping stack traces instead of logs.
 *
 * # A crash must not strand a batch either
 *
 * The above only covers a `\Throwable` this process actually catches. A SIGKILL, an OOM kill,
 * or a cron timeout between `claimNextPublishableBatch()` and the publish finishing leaves a
 * batch `queued` with nothing left running to release it — the same silent-loss failure mode,
 * reached a different way. `runOnce()` therefore reclaims any batch still `queued` past a
 * grace period at the START of every run, before claiming anything new, mirroring
 * {@see \LiturgicalCalendar\Api\Services\Outbox\BackstopRunner}'s grace-window pattern rather
 * than reusing it (that class is constructor-typed to the OpenFGA outbox's own repository).
 * A reclaim is ordinary recovery, not a failure: it never sets
 * {@see PublishRunResult::$stoppedOnFailure}, and a batch it reclaims is simply claimable
 * again in the very same run. `reclaimStaleClaims()` is itself wrapped for the same reason
 * `releaseClaim()` is: a DB outage during the reclaim step must not surface as a raw PHP
 * fatal with no `published=... stopped_on_failure=...` line for the cron script to report.
 *
 * # A merely SLOW process is not a dead one — the reclaim's own sharp edge
 *
 * The grace period distinguishes "dead" from "alive" only probabilistically: a publish that
 * is simply taking longer than the grace window (a large batch, a slow GitHub response) is
 * still alive when a second runner reclaims and re-publishes its batch. When the first
 * runner's own, now-doomed GitHub call finally returns — a non-fast-forward `updateRef()`,
 * since the branch moved under it — its catch calls `releaseClaim()` too. If that call were
 * unconditional, it would revert a batch that a *different* runner had, in the meantime,
 * genuinely and successfully published — recorded `open` with a real `commit_sha` and
 * `pr_number` — back to `none`, causing it to be silently republished next tick while still
 * carrying the first publish's real identifiers. `SourceDataChangeRequestRepository::releaseClaim()`
 * therefore only releases a row that is still `queued` (see that method's own docblock).
 *
 * What that guard does NOT do is make "released nothing" mean "someone else published it".
 * The guard fails to match for several reasons, and they are semantic opposites:
 * `open`/`merged`/`closed` means another runner genuinely finished the batch, while `none`
 * means nothing is published anywhere — reached whenever another runner's own publish failed
 * too (one GitHub outage fails every runner identically) or whenever `reclaimStaleClaims()`
 * released this batch out from under a merely-slow runner, which it does on every tick. An
 * earlier revision of this class inferred "settled" from a zero row count, so a real outage
 * logged success, left `stoppedOnFailure` false, and re-claimed the same batch on every
 * iteration of the loop that exists to stop hammering — the exact opposite of both rules
 * below. `releaseClaim()` therefore reports the OBSERVED STATUS as a
 * {@see \LiturgicalCalendar\Api\Enum\ClaimReleaseOutcome}, and `runOnce()` branches on it:
 * only `SETTLED_ELSEWHERE` continues without failing; `RELEASED`, `NOT_CLAIMED`,
 * `BATCH_MISSING`, `CLAIM_LOST` and a release that throws are all genuine failures that stop
 * the loop with `stoppedOnFailure: true`, per "Stop, don't hammer" below. See
 * {@see \LiturgicalCalendar\Api\Services\GitHub\GitHubGitDataClient} /
 * `scripts/publish-sourcedata.php`'s HTTP client wiring for the timeout that keeps "still
 * running" and "abandoned" distinguishable in the first place — an unbounded request could
 * outlive the grace period indefinitely, making this race far more likely than intended.
 *
 * # Contention is not an outage
 *
 * Two runners publishing DIFFERENT batches of the SAME resource both target that resource's
 * one branch, and `updateRef()`'s hardcoded `force: false` means the loser gets a `422`
 * non-fast-forward rather than clobbering the winner's commit. That is the design working:
 * expected, self-healing (the next tick republishes onto the head the winner pushed), and
 * proof that GitHub is healthy and answering. Stopping the whole tick for it — and exiting 1,
 * paging an operator — would report an outage for a race the design deliberately allows. A
 * `422` therefore logs a WARNING (it must stay visible; a silenced race is how a genuinely
 * stuck branch hides) and continues with the rest of the queue, while every other failure
 * still stops. The batch is not let off: the attempt is counted, so a batch that `422`s
 * forever is parked by the bounded-attempts rule below rather than retried forever.
 *
 * # One bad batch must not strand the queue
 *
 * "Stop, don't hammer" below has a sharp edge of its own: candidates are ordered oldest-first
 * and a failed publish returns its batch to `none`, so a batch that fails DETERMINISTICALLY —
 * an illegal git-ref character in a `resource_id`, a tree-path conflict, a payload a later
 * validation change rejects — is re-claimed first on every tick and every tick aborts before
 * reaching anything else. One editor's broken proposal then blocks every other editor's good
 * work indefinitely, and the only symptom is a log line repeating.
 *
 * Two bounds close that. Across runs,
 * {@see SourceDataChangeRequestRepository::MAX_PUBLISH_ATTEMPTS} caps CONSECUTIVE attempts per
 * batch (`releaseClaim()` and `reclaimStaleClaims()` count them, `recordPublication()` resets
 * them, so a transient GitHub blip cannot park anything); once a batch reaches the cap it stops
 * being claimable and the queue drains past it. Within one run, a batch this pass already
 * attempted is passed to `claimNextPublishableBatch()` as a skip id, because a batch just
 * released to `none` is instantly the oldest candidate again — so a `continue` would otherwise
 * re-claim the very batch it just failed on, which is in-process retry by another name.
 *
 * A parked batch is NOT a dead-lettered one — this feature has no dead-letter queue, and the
 * rows are untouched and still `approved`. But it must never be a SILENT one, which would be
 * the same class of defect as the stranded batch above: every run reports
 * {@see PublishRunResult::$parkedBatches}, logs a warning when it is non-zero, and
 * `GET /health`'s `source_data_publisher` block reports it out of band.
 *
 * # Stop, don't hammer
 *
 * A genuinely failed publish stops the loop rather than moving on to the next batch. If
 * GitHub is down or the installation credential has gone stale, every remaining batch would
 * fail the same way; retrying immediately in-process would just exhaust the rate limit
 * faster. The cron interval that re-invokes {@see runOnce()}, not an in-process retry, is
 * what re-attempts — which is also why {@see \LiturgicalCalendar\Api\Services\Outbox\OutboxBackoff}
 * is not used here: there is no in-process retry loop to pace, only a single straight-line
 * pass per tick.
 */
final class PublishRunner
{
    /**
     * A publish attempt is bounded by however long its sequence of GitHub API calls takes
     * (one `getRef`/`createRef`, one `createBlob` per changed file, `createTree`,
     * `createCommit`, `updateRef`, and `findOpenPullRequest`/`openPullRequest`) — minutes, not
     * seconds, is the right order of magnitude for "this process is almost certainly dead",
     * as opposed to {@see \LiturgicalCalendar\Api\Services\Outbox\BackstopRunner}'s 60-second
     * default for a single fire-and-forget OpenFGA call. This is a probabilistic cutoff, not a
     * guarantee — see the class docblock's "A merely SLOW process is not a dead one" section —
     * so it must stay comfortably above the WHOLE publish's worst-case duration, not above a
     * single request's timeout.
     *
     * That distinction matters, because a publish issues one `createBlob` per changed file,
     * serially, and only then the six fixed calls (getRef, getCommitTreeSha, createTree,
     * createCommit, updateRef, findOpenPullRequest). The decrees corpus is the widest batch
     * this repository can produce today — `decrees.json` plus 14 `i18n/` locales plus 7
     * `lectionary/` locales — so 22 blob writes plus 6 fixed calls is 28 requests. At the
     * script's 30-second request timeout that is 840 seconds worst case, which a 600-second
     * grace would have cut straight through: the reclaim would have fired on live work
     * whenever GitHub was merely slow, rather than only on work that was abandoned.
     *
     * 1800 leaves headroom above that 840 while still bounding how long a genuinely crashed
     * batch waits to be picked back up. If either the request timeout or the widest batch
     * grows, this must grow with them — the invariant is
     * `grace > maxRequestsPerBatch * requestTimeout`, not any particular number.
     */
    private const DEFAULT_GRACE_SECONDS = 1800;

    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly SourceDataChangeRequestRepository $repository,
        private readonly SourceDataPublisherInterface $publisher,
        private readonly int $graceSeconds = self::DEFAULT_GRACE_SECONDS,
        ?LoggerInterface $logger = null
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Reclaim stale claims, then claim, publish, and (via the publisher) record up to
     * `$limit` approved batches.
     *
     * Returns as soon as the queue is exhausted, or as soon as one publish attempt
     * *genuinely* fails — whichever comes first. A batch whose own release turns out to be a
     * no-op (already settled by another runner — see the class docblock) is not treated as a
     * failure: this run moves on to the next batch instead of stopping.
     */
    public function runOnce(int $limit = 10): PublishRunResult
    {
        if (!$this->reclaimStaleClaimsSafely()) {
            return $this->result(0, stoppedOnFailure: true);
        }

        $published = 0;
        /** @var list<string> $attempted Batches this run already tried; never tried twice. */
        $attempted = [];

        for ($i = 0; $i < $limit; $i++) {
            try {
                $claim = $this->repository->claimNextPublishableBatch($attempted);
            } catch (\Throwable $e) {
                $this->logger->error(
                    'Claiming the next publishable source-data change request batch failed; '
                        . 'stopping this run rather than looping against an unhealthy database.',
                    [
                        'exception' => $e::class,
                        'message'   => $e->getMessage(),
                    ]
                );

                return $this->result($published, stoppedOnFailure: true);
            }

            if (null === $claim) {
                break;
            }

            $attempted[] = $claim->batchId;

            try {
                $this->publisher->publish($claim->batchId);
            } catch (\Throwable $e) {
                $this->logger->error(
                    'Publishing source-data change request batch failed.',
                    [
                        'batch_id'  => $claim->batchId,
                        'exception' => $e::class,
                        'message'   => $e->getMessage(),
                    ]
                );

                $outcome = $this->releaseClaimSafely($claim->batchId, $claim->token);

                if (null !== $outcome && $outcome->isSettled()) {
                    // The ONLY non-failure reading of a zero-row release, and it is a positive
                    // observation rather than an inference: the batch is `open`/`merged`/
                    // `closed`, so another runner genuinely published it while this runner's
                    // doomed attempt was still in flight. Nothing about GitHub is failing;
                    // this attempt was simply redundant. Move on to the next batch.
                    $this->logger->info(
                        'Batch was already settled (published) by another runner before this '
                            . "run's own release could take effect; not counted as a failure.",
                        ['batch_id' => $claim->batchId, 'release_outcome' => $outcome->value]
                    );
                    continue;
                }

                if (
                    $this->isBranchContention($e)
                    && null !== $outcome
                    && ClaimReleaseOutcome::BATCH_MISSING !== $outcome
                    && ClaimReleaseOutcome::CLAIM_LOST !== $outcome
                ) {
                    // Two batches of the SAME resource target one branch, so the loser of that
                    // race gets a 422. See the class docblock's "Contention is not an outage".
                    //
                    // Gated on the release having actually been OBSERVED, not on the GitHub
                    // status alone. The message below promises the batch "stays claimable" and
                    // that "the next tick republishes it" — true when the release reported
                    // RELEASED or NOT_CLAIMED, and false when the release itself threw (null:
                    // the same outage broke both, and the batch is left `queued` until the
                    // grace-period reclaim), when the batch has vanished (BATCH_MISSING), or
                    // when another runner already holds the live claim (CLAIM_LOST) — that
                    // reading is excluded here for the same reason the class docblock's "A
                    // merely SLOW process is not a dead one" section gives: this runner's own
                    // grace period already elapsed and a second runner has since taken over,
                    // so THIS runner's attempt genuinely failed regardless of how benign the
                    // 422 looks, and the run must stop rather than claim the batch "stays
                    // claimable" when it is actively held by someone else. Continuing on
                    // BATCH_MISSING or CLAIM_LOST would exit 0 on an unexplained or
                    // already-spoken-for state purely because the GitHub error that
                    // accompanied it happened to be a 422 — the same DB failure beside a 500
                    // stops the run — and would read that state optimistically, against
                    // ClaimReleaseOutcome's own rule.
                    // "stays claimable" holds for every attempt but the last: the release that
                    // reports RELEASED is also the one that spends an attempt, so the attempt that
                    // reaches the bound parks the batch in the same breath as this message. Said
                    // plainly here rather than left to contradict the parked warning and the
                    // `parked` count that the very same run emits.
                    $this->logger->warning(
                        'Publishing this batch lost a race for its resource branch (GitHub 422); '
                            . 'the batch stays claimable and the next tick republishes it onto the '
                            . 'branch head the winner pushed — unless this attempt was its last, in '
                            . 'which case it is parked and this run reports it as such. '
                            . 'Continuing with the rest of the queue.',
                        [
                            'batch_id'        => $claim->batchId,
                            'message'         => $e->getMessage(),
                            'release_outcome' => $outcome->value ?? 'release_failed',
                        ]
                    );
                    continue;
                }

                // Everything else is a real failure, whichever way the release read:
                // RELEASED (this runner held the live claim and its publish failed),
                // NOT_CLAIMED (`none` — nobody published it and nobody holds it, so the work
                // is still undone), BATCH_MISSING (an unexplained state, never read
                // optimistically), CLAIM_LOST (this runner was merely slow, the grace-period
                // reclaim already freed the batch, and another runner has since claimed it —
                // this runner's own publish still genuinely failed, and the run stops even
                // though the runner that actually holds the claim carries on regardless), or
                // null (the release itself threw, already logged by releaseClaimSafely()).
                // Stop rather than hammer a failing API with the rest of the queue.
                $this->logger->warning(
                    'Stopping this run after a failed publish attempt.',
                    ['batch_id' => $claim->batchId, 'release_outcome' => $outcome->value ?? 'release_failed']
                );

                return $this->result($published, stoppedOnFailure: true);
            }

            $published++;
        }

        return $this->result($published);
    }

    /**
     * Assemble a {@see PublishRunResult}, attaching the parked-batch count every run reports.
     *
     * Every return from {@see runOnce()} goes through here so that no exit path can drop the
     * count: a batch that has stopped being attempted is exactly as invisible as one stranded
     * `queued`, and the runs most likely to leave batches parked are the ones that end early.
     */
    private function result(int $published, bool $stoppedOnFailure = false): PublishRunResult
    {
        return new PublishRunResult($published, $stoppedOnFailure, $this->parkedBatchCountSafely());
    }

    /**
     * Count (and, when non-zero, log) batches that have exhausted
     * {@see SourceDataChangeRequestRepository::MAX_PUBLISH_ATTEMPTS}.
     *
     * Wrapped for the same reason every other DB call in this class is: reporting how much work
     * is stuck must never be the thing that turns a completed run into a raw fatal. A count
     * that cannot be read is reported as 0 and logged, rather than guessed at.
     */
    private function parkedBatchCountSafely(): int
    {
        try {
            $parked = $this->repository->countParkedBatches();
        } catch (\Throwable $e) {
            $this->logger->error(
                'Counting parked source-data change request batches failed; this run reports 0 '
                    . 'parked batches, which may understate what is actually stuck.',
                [
                    'exception' => $e::class,
                    'message'   => $e->getMessage(),
                ]
            );

            return 0;
        }

        if ($parked > 0) {
            $this->logger->warning(
                'Approved source-data change request batches have exhausted their publish attempts '
                    . 'and are no longer being attempted; they need an operator. See the '
                    . 'change-request runbook, "Parked batches".',
                [
                    'parked_batches' => $parked,
                    'max_attempts'   => SourceDataChangeRequestRepository::MAX_PUBLISH_ATTEMPTS,
                ]
            );
        }

        return $parked;
    }

    /**
     * Is this failure the expected symptom of two runners racing for one resource's branch,
     * rather than an unhealthy GitHub?
     *
     * `updateRef()` hardcodes `force: false`, so the loser of that race gets a `422` instead
     * of clobbering the winner's commit — see the class docblock's "Contention is not an
     * outage". Matched on the HTTP status alone, never on GitHub's message text, which is not
     * a contract. Other `422`s exist (a validation error on `createRef()` or
     * `openPullRequest()`), and they get the same treatment for the same reason: a `422` is
     * GitHub answering, in detail, about THIS request. It says nothing about the next batch,
     * which is what makes continuing safe — and the attempt is still counted against the
     * batch's bounded attempts, so a batch that `422`s forever is eventually parked rather
     * than retried forever.
     */
    private function isBranchContention(\Throwable $e): bool
    {
        return $e instanceof GitHubApiException && 422 === $e->status;
    }

    /**
     * Release a claim without letting a failure here escape as a raw fatal. It cannot
     * un-strand the batch either way if it throws — the same outage that failed the publish
     * very plausibly also breaks this call — but an operator greping logs should find a
     * structured entry, not a stack trace with no context.
     *
     * @return ClaimReleaseOutcome|null What `releaseClaim()` observed (see that enum: only
     *                   {@see ClaimReleaseOutcome::SETTLED_ELSEWHERE} is a non-failure), or
     *                   null if `releaseClaim()` itself threw. Null is treated as a failure by
     *                   the caller: a thrown exception gives no positive "already settled"
     *                   observation the way a clean status read does, and an unknown state is
     *                   read conservatively.
     */
    private function releaseClaimSafely(string $batchId, string $token): ?ClaimReleaseOutcome
    {
        try {
            return $this->repository->releaseClaim($batchId, $token);
        } catch (\Throwable $e) {
            $this->logger->error(
                'Releasing the claim on a failed source-data publish batch also failed; '
                    . 'the batch remains queued and needs manual recovery or the grace-period reclaim.',
                [
                    'batch_id'  => $batchId,
                    'exception' => $e::class,
                    'message'   => $e->getMessage(),
                ]
            );

            return null;
        }
    }

    /**
     * Release any batch stranded `queued` past the grace period back to `none`, so it is
     * claimable again in this same run. See the class docblock's "A crash must not strand a
     * batch either" section for why this exists.
     *
     * Wrapped for the same reason {@see releaseClaimSafely()} is: a DB outage here must not
     * escape as a raw PHP fatal with no structured log and no `PublishRunResult` for the
     * caller to report.
     *
     * @return bool False if `reclaimStaleClaims()` itself threw (logged here); true otherwise,
     *              regardless of how many rows (if any) were actually reclaimed.
     */
    private function reclaimStaleClaimsSafely(): bool
    {
        $cutoff = ( new \DateTimeImmutable('now', new \DateTimeZone('Europe/Vatican')) )
            ->modify("-{$this->graceSeconds} seconds");

        try {
            $reclaimed = $this->repository->reclaimStaleClaims($cutoff);
        } catch (\Throwable $e) {
            $this->logger->error(
                'Reclaiming stale source-data publish claims failed; skipping this run rather '
                    . 'than claiming new work against a possibly-unhealthy database.',
                [
                    'exception'     => $e::class,
                    'message'       => $e->getMessage(),
                    'grace_seconds' => $this->graceSeconds,
                ]
            );

            return false;
        }

        if ($reclaimed > 0) {
            $this->logger->warning(
                'Reclaimed source-data change request rows stranded in queued past the grace period.',
                [
                    'rows_reclaimed' => $reclaimed,
                    'grace_seconds'  => $this->graceSeconds,
                ]
            );
        }

        return true;
    }
}
