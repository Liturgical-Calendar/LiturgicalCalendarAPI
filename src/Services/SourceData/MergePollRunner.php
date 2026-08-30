<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services\SourceData;

use LiturgicalCalendar\Api\Repositories\SourceDataChangeRequestRepository;
use LiturgicalCalendar\Api\Services\GitHub\GitHubGitDataClient;
use LiturgicalCalendar\Api\Services\GitHub\PullRequestState;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Polls the rolling pull requests that carry published change-request batches and records what
 * became of them.
 *
 * # Polling, not webhooks
 *
 * A webhook would need a new public route, HMAC verification, and a second authentication mode on
 * `/_ops` — real attack surface for a transition nobody is waiting on. A missed webhook is a
 * silently stuck row; a missed poll is picked up on the next tick.
 *
 * # One poll per pull request, not per batch
 *
 * The rolling branch is per RESOURCE, and {@see SourceDataPublisher} reuses an already-open pull
 * request. Two batches for one resource therefore share one `pr_number`, and polling per batch
 * would ask GitHub the same question twice and answer it twice.
 *
 * # Sharing a pull request is not being in the merge
 *
 * A reviewer clicking Merge concurrently with a publish separates the two: the publish
 * fast-forwards the branch to batch C's commit, the merge takes the head it had a moment earlier,
 * and C is left recorded against a pull request that closed without carrying it.
 *
 * Marking C `merged` would ASSERT it reached the repository. The publisher selects approved rows
 * that are not yet `merged`, so C would never be attempted again and its content would be lost
 * silently — the same failure mode the age-based ancestor exclusion was chosen to avoid, reached
 * from the other direction. So containment is verified rather than assumed: a batch whose commit
 * is the merged head is contained (no extra call, the ordinary case), and any other batch on that
 * pull request is checked with one `compareCommits()`. A batch that is NOT contained is returned
 * to `none` and republished under a fresh pull request.
 *
 * A containment check that FAILS is read neither way. Assuming contained loses content; assuming
 * not-contained republishes work already in the repository. Both guesses are wrong, so the run
 * stops and the batch stays `open` for the next tick.
 *
 * # No claim protocol
 *
 * Unlike {@see PublishRunner}, this holds nothing and claims nothing. Its writes are `UPDATE`s
 * guarded on `publication_status = 'open'`, so two racing pollers produce one transition and one
 * no-op. There is no stranded state to reclaim, which is why there is no grace period, no attempt
 * bound and no reclaim step here. Do not add one for symmetry.
 *
 * # Stop, don't hammer
 *
 * A failed poll stops the run rather than moving to the next pull request. If GitHub is down or
 * the installation credential is stale, every remaining poll fails identically, and retrying
 * in-process would only exhaust the rate limit faster. The cron interval is what re-attempts.
 */
final class MergePollRunner
{
    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly SourceDataChangeRequestRepository $repository,
        private readonly GitHubGitDataClient $client,
        ?LoggerInterface $logger = null
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public function runOnce(int $limit = 50): MergePollRunResult
    {
        $unpollable = $this->unpollableCountSafely();

        try {
            $prNumbers = $this->repository->listOpenPullRequestNumbers();
        } catch (\Throwable $e) {
            $this->logger->error(
                'Listing open source-data pull requests failed; stopping this run rather than '
                    . 'polling against an unhealthy database.',
                ['exception' => $e::class, 'message' => $e->getMessage()]
            );

            return new MergePollRunResult(unpollable: $unpollable, stoppedOnFailure: true);
        }

        $merged = 0;
        $closed = 0;
        $reset  = 0;

        foreach (array_slice($prNumbers, 0, $limit) as $prNumber) {
            try {
                $tally = $this->pollOne($prNumber);
            } catch (\Throwable $e) {
                $this->logger->error(
                    'Polling a source-data pull request failed; stopping this run rather than '
                        . 'hammering a failing API with the rest of the queue.',
                    ['pr_number' => $prNumber, 'exception' => $e::class, 'message' => $e->getMessage()]
                );

                return new MergePollRunResult($merged, $closed, $reset, $unpollable, true);
            }

            $merged += $tally['merged'];
            $closed += $tally['closed'];
            $reset  += $tally['reset'];
        }

        return new MergePollRunResult($merged, $closed, $reset, $unpollable);
    }

    /**
     * @return array{merged: int, closed: int, reset: int}
     */
    private function pollOne(int $prNumber): array
    {
        $pr     = $this->client->getPullRequest($prNumber);
        $tally  = ['merged' => 0, 'closed' => 0, 'reset' => 0];
        $states = $this->repository->listOpenBatchesForPullRequest($prNumber);

        if ('open' === $pr->state) {
            return $tally;
        }

        if ($pr->isClosedUnmerged()) {
            foreach ($states as $batch) {
                $reason = sprintf('Pull request #%d was closed without merging.', $prNumber);
                if ($this->repository->markBatchClosedUnmerged($batch['batch_id'], $reason) > 0) {
                    $tally['closed']++;
                    $this->logger->info(
                        'Source-data change request batch closed unmerged.',
                        ['batch_id' => $batch['batch_id'], 'pr_number' => $prNumber]
                    );
                }
            }

            return $tally;
        }

        // Merged. `mergeCommitSha` is non-null for a merged pull request; a merged PR without one
        // is GitHub contradicting itself, and guessing a sha is worse than stopping.
        $mergeCommitSha = $pr->mergeCommitSha;
        if (null === $mergeCommitSha) {
            throw new \RuntimeException(sprintf('Pull request #%d reports merged but carries no merge_commit_sha', $prNumber));
        }

        foreach ($states as $batch) {
            if ($this->isContained($batch['commit_sha'], $pr, $mergeCommitSha)) {
                if ($this->repository->markBatchMerged($batch['batch_id'], $mergeCommitSha) > 0) {
                    $tally['merged']++;
                    $this->logger->info(
                        'Source-data change request batch merged.',
                        ['batch_id' => $batch['batch_id'], 'pr_number' => $prNumber, 'merge_commit_sha' => $mergeCommitSha]
                    );
                }
                continue;
            }

            if ($this->repository->returnBatchToUnpublished($batch['batch_id']) > 0) {
                $tally['reset']++;
                $this->logger->warning(
                    'A pull request merged WITHOUT one of the batches recorded against it — most '
                        . 'likely a review that landed concurrently with a publish. The batch is '
                        . 'claimable again and the next publish opens a fresh pull request carrying '
                        . 'it; it is deliberately NOT marked merged, which would assert it reached '
                        . 'the repository and lose its content silently.',
                    [
                        'batch_id'         => $batch['batch_id'],
                        'pr_number'        => $prNumber,
                        'batch_commit_sha' => $batch['commit_sha'],
                        'merge_commit_sha' => $mergeCommitSha,
                    ]
                );
            }
        }

        return $tally;
    }

    /**
     * Is this batch's commit inside the merged pull request's history?
     *
     * Equality with the merged head is decided locally and costs nothing — the ordinary case, since
     * most pull requests carry one batch. Anything else costs one `compareCommits()`, read as
     * "$head is <status> $base": `ahead` or `identical` means the merge commit's history contains
     * the batch's commit.
     *
     * A null `commit_sha` on an `open` row is unexplained (the publisher writes one for every row
     * it records), so it is read conservatively as NOT contained rather than optimistically.
     */
    private function isContained(?string $batchCommitSha, PullRequestState $pr, string $mergeCommitSha): bool
    {
        if (null === $batchCommitSha || '' === $batchCommitSha) {
            return false;
        }

        if ($batchCommitSha === $pr->headSha) {
            return true;
        }

        $status = $this->client->compareCommits($batchCommitSha, $mergeCommitSha);

        return in_array($status, ['ahead', 'identical'], true);
    }

    /**
     * Wrapped for the same reason every DB call in {@see PublishRunner} is: reporting how much work
     * is in an odd state must never be the thing that turns a completed run into a raw fatal.
     */
    private function unpollableCountSafely(): int
    {
        try {
            $unpollable = $this->repository->countOpenBatchesWithoutPullRequest();
        } catch (\Throwable $e) {
            $this->logger->error(
                'Counting unpollable open source-data batches failed; this run reports 0, which may '
                    . 'understate what is actually stuck.',
                ['exception' => $e::class, 'message' => $e->getMessage()]
            );

            return 0;
        }

        if ($unpollable > 0) {
            $this->logger->warning(
                'Source-data change request batches are `open` with no pull request number, so '
                    . 'nothing will ever poll them. The publisher always records one, so this is an '
                    . 'unexplained state and needs an operator.',
                ['unpollable_batches' => $unpollable]
            );
        }

        return $unpollable;
    }
}
