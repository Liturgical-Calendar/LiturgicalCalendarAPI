<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services\SourceData;

use LiturgicalCalendar\Api\Repositories\AuditLogRepository;
use LiturgicalCalendar\Api\Repositories\SourceDataChangeRequestRepository;
use LiturgicalCalendar\Api\Services\GitHub\GitHubGitDataClient;
use LiturgicalCalendar\Api\Services\GitHub\PullRequestState;
use LiturgicalCalendar\Api\Services\ResourceTuplePurgeServiceInterface;
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
        /**
         * Null on a deployment without OpenFGA. Merge detection must still work there — the whole
         * point of the write-mode seam is that the stack is optional — so a null purge service is
         * a quiet no-op, not a failure.
         */
        private readonly ?ResourceTuplePurgeServiceInterface $purge = null,
        private readonly ?AuditLogRepository $auditLog = null,
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
        $pr    = $this->client->getPullRequest($prNumber);
        $tally = ['merged' => 0, 'closed' => 0, 'reset' => 0];

        if ('open' === $pr->state) {
            // The steady-state majority: most polled pull requests are still awaiting review.
            // Checked before listOpenBatchesForPullRequest() so an open PR costs one GitHub call
            // and no wasted database query.
            return $tally;
        }

        $states = $this->repository->listOpenBatchesForPullRequest($prNumber);

        if ($pr->isClosedUnmerged()) {
            foreach ($states as $batch) {
                $reason = sprintf('Pull request #%d was closed without merging.', $prNumber);
                if ($this->repository->markBatchClosedUnmerged($batch['batch_id'], $reason) > 0) {
                    $tally['closed']++;
                    $this->logger->info(
                        'Source-data change request batch closed unmerged.',
                        ['batch_id' => $batch['batch_id'], 'pr_number' => $prNumber]
                    );
                    $this->audit('change_request.closed_unmerged', $batch['batch_id'], ['pr_number' => $prNumber]);
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
                    $this->audit('change_request.merged', $batch['batch_id'], ['pr_number' => $prNumber, 'merge_commit_sha' => $mergeCommitSha]);
                    $this->purgeIfResourceDeletion($batch['batch_id']);
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

    /**
     * Purge a deleted resource's operational OpenFGA tuples, now that the deletion is real.
     *
     * The trigger is `metadata.deletes_resource`, NOT `operation = 'delete'`. The operation cannot
     * answer this: `RegionalDataHandler::writeI18nFiles()` stages a DELETE for every locale file
     * dropped from `metadata.locales`, on a calendar that still exists, so keying on it would
     * revoke every editor on a live calendar because a translator removed a language.
     *
     * EVERY row of the batch must carry the flag, not merely one. `getBatch()` orders `BY path
     * ASC` under the database's collation — see its own docblock — so which row lands at index 0
     * is an accident of string comparison, not a signal that it belongs to the submission that
     * actually deleted the resource. Worse, `submitBatch()`'s carry-forward UPDATE re-parents an
     * untouched row onto a new batch id but leaves its `metadata` exactly as it was, so a batch
     * can genuinely mix a flagged row from one submission with an unflagged row carried forward
     * from an earlier, non-deleting one. `array_find()` (accept any flagged row) does not close
     * this: a batch that mixes an unflagged UPDATE of the calendar file with flagged, carried-
     * forward i18n DELETE rows is a locale removal, not a resource deletion, and `array_find()`
     * would purge it. So this requires unanimity via `array_all()` — a pure resource deletion has
     * every row flagged, and any mixture, in either direction, does not purge.
     *
     * This fails CLOSED, deliberately, and that asymmetry is the whole point for an authorization
     * decision: wrongly purging revokes real access on a live calendar, while wrongly not purging
     * merely leaves tuples live — exactly the status quo before this task, visible and
     * recoverable. The residual: an exotic carry-forward can leave a genuine deletion batch
     * holding one unflagged row, in which case the purge does not fire and the tuples stay live
     * until an operator or `ResourceTuplePurgeReconciler`'s sweep removes them. That is accepted,
     * not an oversight — do not "tighten" this back to `array_find()` without re-reading this.
     *
     * The object string is rebuilt from a flagged row's own `resource_type` and `resource_id`,
     * which are already rite-qualified (all rows of a batch share one resource, so any flagged row
     * gives the same answer — but it is read only once unanimity is established, never from
     * `$rows[0]` by position). Do NOT reconstruct a `ChangeResource` here: its factories RE-qualify
     * a bare id, so `roman/US` would become `roman/roman/US` and fail closed for the wrong reason.
     *
     * `admin` tuples survive — that is `ResourceTuplePurgeService`'s own contract — so ownership
     * outlives a deletion and a recreated resource id belongs to the same person.
     *
     * Best-effort, and deliberately so: the batch is already `merged`, which is a fact about the
     * repository, and a reachable OpenFGA is not a precondition for recording it. A failure logs
     * and leaves the tuples for `ResourceTuplePurgeReconciler`'s sweep, exactly as the disk-mode
     * path does.
     */
    private function purgeIfResourceDeletion(string $batchId): void
    {
        if (null === $this->purge) {
            return;
        }

        try {
            $rows = $this->repository->getBatch($batchId);
        } catch (\Throwable $e) {
            $this->logger->error(
                'Could not read a merged batch to decide whether it deleted a resource; any '
                    . 'operational tuples it orphaned stay live until the reconciler sweep.',
                ['batch_id' => $batchId, 'exception' => $e::class, 'message' => $e->getMessage()]
            );

            return;
        }

        if ([] === $rows) {
            return;
        }

        $isFlagged = static function (array $row): bool {
            $metadata = is_array($row['metadata'] ?? null) ? $row['metadata'] : [];

            return true === ( $metadata['deletes_resource'] ?? false );
        };

        if (!array_all($rows, $isFlagged)) {
            // Not unanimous: either no row is flagged (an ordinary batch), or the flag is mixed
            // with an unflagged row — a locale removal or a carry-forward mismatch. Neither
            // deletes the resource, so nothing is purged.
            return;
        }

        // Unanimity established: every row is flagged, so the first one is a row this method has
        // actually tested, not a `$rows[0]` read by bare position.
        $flagged = $rows[0];

        $resourceType = $flagged['resource_type'] ?? null;
        $resourceId   = $flagged['resource_id'] ?? null;
        if (!is_string($resourceType) || !is_string($resourceId)) {
            return;
        }

        $fgaObject = $resourceType . ':' . $resourceId;

        try {
            $purged = $this->purge->purgeForObject($fgaObject);
            $this->logger->info(
                'Purged operational tuples for a resource whose deletion has merged.',
                ['batch_id' => $batchId, 'object' => $fgaObject, 'tuples' => $purged]
            );
        } catch (\Throwable $e) {
            $this->logger->error(
                'Purging operational tuples for a merged resource deletion failed; the merge stands '
                    . 'and the reconciler sweep will retry. Until it does, the deleted resource\'s '
                    . 'former editors retain access to an object whose files are gone.',
                ['batch_id' => $batchId, 'object' => $fgaObject, 'exception' => $e::class, 'message' => $e->getMessage()]
            );
        }
    }

    /**
     * Best-effort audit entry. A logging failure must never turn a recorded transition into a
     * failed run — same rule, and same reasoning, as `ChangeRequestAdminHandler::audit()`.
     *
     * The actor is null: nobody at this deployment performed the merge. The reviewer who clicked
     * Merge did so on GitHub, and attributing it to the approving admin would be a fabrication.
     *
     * @param array<string, mixed> $details
     */
    private function audit(string $action, string $batchId, array $details): void
    {
        if (null === $this->auditLog) {
            return;
        }

        try {
            $this->auditLog->log(null, $action, 'sourcedata_change_request', $batchId, $details);
        } catch (\Throwable) {
            // Deliberately swallowed — see method docblock.
        }
    }
}
