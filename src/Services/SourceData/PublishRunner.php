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
 * stranded any more than an expected GitHub API failure would.
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
    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly SourceDataChangeRequestRepository $repository,
        private readonly SourceDataPublisherInterface $publisher,
        ?LoggerInterface $logger = null
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Claim, publish, and (via the publisher) record up to `$limit` approved batches.
     *
     * Returns as soon as the queue is exhausted, or as soon as one publish attempt fails —
     * whichever comes first. A failed attempt's claim is released before this returns, so
     * the batch is claimable again on the next run.
     *
     * @return int Batches actually published.
     */
    public function runOnce(int $limit = 10): int
    {
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
                $this->repository->releaseClaim($batchId);
                break;
            }

            $published++;
        }

        return $published;
    }
}
