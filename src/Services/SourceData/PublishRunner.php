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
 * again in the very same run.
 *
 * # Stop, don't hammer
 *
 * A failed publish stops the loop rather than moving on to the next batch. If GitHub is down
 * or the installation credential has gone stale, every remaining batch would fail the same
 * way; retrying immediately in-process would just exhaust the rate limit faster. The cron
 * interval that re-invokes {@see runOnce()}, not an in-process retry, is what re-attempts —
 * which is also why {@see \LiturgicalCalendar\Api\Services\Outbox\OutboxBackoff} is not used
 * here: there is no in-process retry loop to pace, only a single straight-line pass per tick.
 */
final class PublishRunner
{
    /**
     * A publish attempt is bounded by however long its sequence of GitHub API calls takes
     * (one `getRef`/`createRef`, one `createBlob` per changed file, `createTree`,
     * `createCommit`, `updateRef`, and `findOpenPullRequest`/`openPullRequest`) — minutes, not
     * seconds, is the right order of magnitude for "this process is almost certainly dead",
     * as opposed to {@see \LiturgicalCalendar\Api\Services\Outbox\BackstopRunner}'s 60-second
     * default for a single fire-and-forget OpenFGA call.
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
     * Returns as soon as the queue is exhausted, or as soon as one publish attempt fails —
     * whichever comes first. A failed attempt's claim is released before this returns, so
     * the batch is claimable again on the next run.
     */
    public function runOnce(int $limit = 10): PublishRunResult
    {
        $this->reclaimStaleClaims();

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
                    'Publishing source-data change request batch failed; claim released for retry.',
                    [
                        'batch_id'  => $batchId,
                        'exception' => $e::class,
                        'message'   => $e->getMessage(),
                    ]
                );
                $this->releaseClaimSafely($batchId);

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
     */
    private function releaseClaimSafely(string $batchId): void
    {
        try {
            $this->repository->releaseClaim($batchId);
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
        }
    }

    /**
     * Release any batch stranded `queued` past the grace period back to `none`, so it is
     * claimable again in this same run. See the class docblock's "A crash must not strand a
     * batch either" section for why this exists.
     */
    private function reclaimStaleClaims(): void
    {
        $cutoff = ( new \DateTimeImmutable('now', new \DateTimeZone('Europe/Vatican')) )
            ->modify("-{$this->graceSeconds} seconds");

        $reclaimed = $this->repository->reclaimStaleClaims($cutoff);
        if ($reclaimed > 0) {
            $this->logger->warning(
                'Reclaimed source-data change request rows stranded in queued past the grace period.',
                [
                    'rows_reclaimed' => $reclaimed,
                    'grace_seconds'  => $this->graceSeconds,
                ]
            );
        }
    }
}
