<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Services\SourceData;

use LiturgicalCalendar\Api\Services\GitHub\GitHubApiException;
use LiturgicalCalendar\Api\Services\SourceData\PublishResult;
use LiturgicalCalendar\Api\Services\SourceData\SourceDataPublisherInterface;
use PDO;

/**
 * Deletes the batch's rows outright and then throws, so the runner's release genuinely
 * observes {@see \LiturgicalCalendar\Api\Enum\ClaimReleaseOutcome::BATCH_MISSING} against real
 * Postgres rather than being handed a stubbed return value.
 *
 * Nothing in this feature deletes a claimed batch, which is exactly the point: an unexplained
 * state must be read conservatively wherever it comes from — an out-of-band `DELETE`, a
 * restore, a future feature — and never optimistically because the accompanying GitHub status
 * happened to be one the runner otherwise forgives.
 */
final class VanishingBatchSourceDataPublisher implements SourceDataPublisherInterface
{
    public int $calls = 0;

    public function __construct(
        private readonly PDO $pdo,
        private readonly GitHubApiException $throws
    ) {
    }

    public function publish(string $batchId): PublishResult
    {
        $this->calls++;

        $this->pdo->prepare('DELETE FROM sourcedata_change_requests WHERE batch_id = :batch_id')
            ->execute(['batch_id' => $batchId]);

        throw $this->throws;
    }
}
