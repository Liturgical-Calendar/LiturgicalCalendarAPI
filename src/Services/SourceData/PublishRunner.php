<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services\SourceData;

use LiturgicalCalendar\Api\Repositories\SourceDataChangeRequestRepository;
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
 * therefore only releases a row that is still `queued` (see that method's own docblock); once
 * another runner has moved it to `open`, this runner's release matches zero rows. That zero
 * is the signal, not an error: it means "this batch is not stranded, someone else finished
 * it", so `runOnce()` treats it as ordinary — it does NOT set `stoppedOnFailure`, and the loop
 * `continue`s to the next batch rather than stopping, because nothing about the underlying
 * GitHub API is actually failing. A release that DOES affect a row (or that throws) is a
 * genuine failure and still stops the loop with `stoppedOnFailure: true`, per "Stop, don't
 * hammer" below. See {@see \LiturgicalCalendar\Api\Services\GitHub\GitHubGitDataClient} /
 * `scripts/publish-sourcedata.php`'s HTTP client wiring for the timeout that keeps "still
 * running" and "abandoned" distinguishable in the first place — an unbounded request could
 * outlive the grace period indefinitely, making this race far more likely than intended.
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
     * so it must stay comfortably above the HTTP client's own request timeout
     * (`scripts/publish-sourcedata.php`'s Guzzle wiring), or a slow-but-alive publish becomes
     * the common case instead of a rare one.
     */
    private const DEFAULT_GRACE_SECONDS = 600;

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
            return new PublishRunResult(0, stoppedOnFailure: true);
        }

        $published = 0;

        for ($i = 0; $i < $limit; $i++) {
            $batchId = $this->repository->claimNextPublishableBatch();
            if (null === $batchId) {
                break;
            }

            try {
                $this->publisher->publish($batchId);
            } catch (\Throwable $e) {
                $this->logger->error(
                    'Publishing source-data change request batch failed.',
                    [
                        'batch_id'  => $batchId,
                        'exception' => $e::class,
                        'message'   => $e->getMessage(),
                    ]
                );

                $releasedRows = $this->releaseClaimSafely($batchId);

                if (0 === $releasedRows) {
                    // Guaranteed by SourceDataChangeRequestRepository::releaseClaim()'s own
                    // `publication_status = 'queued'` guard: zero rows means this batch was no
                    // longer queued by the time we tried to release it, i.e. another runner
                    // already published it successfully. Not this run's failure — move on.
                    $this->logger->info(
                        'Batch was already settled (published) by another runner before this '
                            . "run's own release could take effect; not counted as a failure.",
                        ['batch_id' => $batchId]
                    );
                    continue;
                }

                // Either releaseClaim() genuinely released a still-queued claim (a real
                // failure, now retryable), or it threw and releaseClaimSafely() already
                // logged that (still a real failure — the batch just could not be confirmed
                // un-stranded from here). Either way this is a real failure: stop rather than
                // hammer a failing API with the rest of the queue.
                return new PublishRunResult($published, stoppedOnFailure: true);
            }

            $published++;
        }

        return new PublishRunResult($published);
    }

    /**
     * Release a claim without letting a failure here escape as a raw fatal. It cannot
     * un-strand the batch either way if it throws — the same outage that failed the publish
     * very plausibly also breaks this call — but an operator greping logs should find a
     * structured entry, not a stack trace with no context.
     *
     * @return int|null The row count from `releaseClaim()` (0 means the batch was already
     *                   settled elsewhere, not a failure — see the class docblock), or null if
     *                   `releaseClaim()` itself threw.
     */
    private function releaseClaimSafely(string $batchId): ?int
    {
        try {
            return $this->repository->releaseClaim($batchId);
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
