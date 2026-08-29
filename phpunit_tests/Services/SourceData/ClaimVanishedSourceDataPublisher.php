<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Services\SourceData;

use LiturgicalCalendar\Api\Enum\ChangePublicationStatus;
use LiturgicalCalendar\Api\Repositories\SourceDataChangeRequestRepository;
use LiturgicalCalendar\Api\Services\GitHub\GitHubApiException;
use LiturgicalCalendar\Api\Services\SourceData\PublishResult;
use LiturgicalCalendar\Api\Services\SourceData\SourceDataPublisherInterface;

/**
 * The SIBLING of the race {@see RaceLosingSourceDataPublisher} covers, and the one the final
 * whole-branch review found unhandled.
 *
 * There, a second runner *published* this batch while this one was slow, so the batch is
 * `open` by the time the release runs. Here, the second runner's own publish also FAILED — a
 * GitHub outage fails every runner, not just one — so the batch is back at `none`, exactly as
 * {@see SourceDataChangeRequestRepository::reclaimStaleClaims()} and
 * {@see SourceDataChangeRequestRepository::releaseClaim()} both leave it. `publish()` here
 * therefore moves the batch to `none` (standing in for that other runner) and then throws a
 * 500 (standing in for this runner's own, equally-doomed call finally returning).
 *
 * Both cases make `releaseClaim()` affect zero rows. Only one of them means "settled by
 * someone else". Reading a bare row count cannot tell them apart, which is why
 * `releaseClaim()` reports the OBSERVED STATUS ({@see \LiturgicalCalendar\Api\Enum\ClaimReleaseOutcome})
 * rather than an integer.
 */
final class ClaimVanishedSourceDataPublisher implements SourceDataPublisherInterface
{
    public int $calls = 0;

    public function __construct(private readonly SourceDataChangeRequestRepository $repo)
    {
    }

    public function publish(string $batchId): PublishResult
    {
        $this->calls++;

        // markBatchPublicationStatus() is unconditional, which is precisely what a concurrent
        // reclaimStaleClaims() / releaseClaim() pair does to this row while this publish is in
        // flight: it ends up `none`, claimable again, with nothing published anywhere.
        $this->repo->markBatchPublicationStatus($batchId, ChangePublicationStatus::NONE);

        throw new GitHubApiException(500, 'Server Error');
    }
}
