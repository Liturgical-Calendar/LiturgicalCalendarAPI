<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Services\SourceData;

use LiturgicalCalendar\Api\Repositories\SourceDataChangeRequestRepository;
use LiturgicalCalendar\Api\Services\SourceData\PublishResult;
use LiturgicalCalendar\Api\Services\SourceData\SourceDataPublisherInterface;
use PDO;

/**
 * Simulates the CLAIM_LOST sequence {@see SourceDataChangeRequestRepository::releaseClaim()}'s
 * own docblock narrates: for one designated batch id, `publish()` first overwrites the row's
 * `publish_claim_token` with a fresh value — standing in for the grace-period reclaim freeing
 * THIS runner's claim and a SECOND runner claiming the very same batch with a new token,
 * exactly what `claimNextPublishableBatch()` would generate for a real second process — and
 * THEN throws, standing in for this (merely SLOW, not dead) runner's own, now-doomed GitHub
 * call finally returning.
 *
 * Reproduces the race directly against the row rather than needing two real OS processes,
 * mirroring {@see RaceLosingSourceDataPublisher}'s approach for the `SETTLED_ELSEWHERE`
 * sibling of this same defect class. Real inter-process concurrency for
 * `claimNextPublishableBatch()` itself is covered separately by
 * {@see \LiturgicalCalendar\Tests\Repositories\SourceDataChangeRequestPublishQueueTest}'s
 * `proc_open` races.
 *
 * The exception thrown is caller-supplied so the same double drives both the branch-contention
 * (`GitHubApiException` 422) and the genuine-failure (anything else) sides of the CLAIM_LOST
 * behaviour in {@see \LiturgicalCalendar\Api\Services\SourceData\PublishRunner::runOnce()}.
 *
 * Every other batch id is published normally, mirroring {@see FakeSourceDataPublisher}'s
 * successful path, so a single test can drive one CLAIM_LOST batch alongside an ordinary one.
 */
final class ClaimStolenSourceDataPublisher implements SourceDataPublisherInterface
{
    public function __construct(
        private readonly SourceDataChangeRequestRepository $repo,
        private readonly PDO $pdo,
        private readonly string $raceBatchId,
        private readonly \Throwable $throws
    ) {
    }

    public function publish(string $batchId): PublishResult
    {
        if ($batchId === $this->raceBatchId) {
            // Steal the claim: a fresh token, as if a second runner had just won it via
            // claimNextPublishableBatch(). The row stays `queued`, now under a token this
            // runner does not hold.
            $this->pdo
                ->prepare('UPDATE sourcedata_change_requests SET publish_claim_token = gen_random_uuid() WHERE batch_id = :batch_id')
                ->execute(['batch_id' => $batchId]);

            throw $this->throws;
        }

        $this->repo->recordPublication(
            $batchId,
            FakeSourceDataPublisher::BRANCH,
            FakeSourceDataPublisher::COMMIT_SHA,
            FakeSourceDataPublisher::PR_NUMBER,
            FakeSourceDataPublisher::BASE_SHA
        );

        return new PublishResult(
            FakeSourceDataPublisher::BRANCH,
            FakeSourceDataPublisher::COMMIT_SHA,
            FakeSourceDataPublisher::PR_NUMBER,
            FakeSourceDataPublisher::BASE_SHA
        );
    }
}
