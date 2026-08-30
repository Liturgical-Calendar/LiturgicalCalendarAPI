<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Services\SourceData;

use LiturgicalCalendar\Api\Repositories\SourceDataChangeRequestRepository;

/**
 * A real, Postgres-backed {@see SourceDataChangeRequestRepository} whose
 * `claimNextPublishableBatch()` always throws — standing in for a database that goes away
 * mid-run (a connection reset, a failover, a statement timeout).
 *
 * Used by {@see PublishRunnerTest::testAFailureToClaimIsLoggedNotThrown()} to prove
 * {@see \LiturgicalCalendar\Api\Services\SourceData\PublishRunner} wraps the claim call the
 * same way it wraps its two siblings, `releaseClaim()` and `reclaimStaleClaims()`. It was the
 * one unwrapped DB call in the loop, so a DB outage there escaped as a raw fatal with no
 * `published=... stopped_on_failure=...` summary line for the cron script to report — the
 * exact defect that class's own docblock justifies wrapping the other two against.
 */
final class ThrowingClaimRepository extends SourceDataChangeRequestRepository
{
    /**
     * @param list<string> $skipBatchIds
     */
    public function claimNextPublishableBatch(array $skipBatchIds = []): ?string
    {
        throw new \RuntimeException('simulated outage: claimNextPublishableBatch failed');
    }
}
