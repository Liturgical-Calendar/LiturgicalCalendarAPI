<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Services\SourceData;

use LiturgicalCalendar\Api\Repositories\SourceDataChangeRequestRepository;

/**
 * A real, Postgres-backed {@see SourceDataChangeRequestRepository} whose `releaseClaim()`
 * always throws — standing in for the same outage that plausibly failed the publish attempt
 * in the first place also breaking the release call. Every other method delegates to the real
 * implementation, so fixtures set up through the normal repository remain visible.
 *
 * Used by {@see PublishRunnerTest::testAFailureToReleaseTheClaimIsLoggedNotThrown()} to prove
 * {@see \LiturgicalCalendar\Api\Services\SourceData\PublishRunner} wraps `releaseClaim()`
 * itself rather than letting a second failure escape as a raw fatal.
 */
final class ThrowingReleaseClaimRepository extends SourceDataChangeRequestRepository
{
    public function releaseClaim(string $batchId): int
    {
        throw new \RuntimeException('simulated outage: releaseClaim failed too');
    }
}
