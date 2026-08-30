<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Services\SourceData;

use LiturgicalCalendar\Api\Repositories\SourceDataChangeRequestRepository;
use LiturgicalCalendar\Api\Services\GitHub\GitHubApiException;
use LiturgicalCalendar\Api\Services\SourceData\PublishResult;
use LiturgicalCalendar\Api\Services\SourceData\SourceDataPublisherInterface;

/**
 * Simulates the concurrent race a phase-2 review round caught: for one designated batch id,
 * `publish()` first records a publication on the repository — standing in for a SECOND runner
 * successfully finishing that same batch, exactly as {@see \LiturgicalCalendar\Api\Repositories\SourceDataChangeRequestRepository::reclaimStaleClaims()}
 * would let happen to a merely SLOW (not dead) first runner — and THEN throws, standing in for
 * that first runner's own, now-doomed GitHub call (a non-fast-forward `updateRef()`, since the
 * branch moved under it) finally returning.
 *
 * Every other batch id is published normally, mirroring {@see FakeSourceDataPublisher}'s
 * successful path, so a single test can drive one race batch alongside ordinary ones.
 */
final class RaceLosingSourceDataPublisher implements SourceDataPublisherInterface
{
    /** What the SECOND runner recorded before the FIRST runner's own call fails. */
    public const OTHER_RUNNER_BRANCH = 'litcal-data/national_calendar/roman/US';

    public const OTHER_RUNNER_COMMIT_SHA = 'other-runner-sha';

    public const OTHER_RUNNER_PR_NUMBER = 99;

    public const OTHER_RUNNER_BASE_SHA = 'other-runner-base-sha';

    public function __construct(
        private readonly SourceDataChangeRequestRepository $repo,
        private readonly string $raceBatchId
    ) {
    }

    public function publish(string $batchId): PublishResult
    {
        if ($batchId === $this->raceBatchId) {
            $this->repo->recordPublication(
                $batchId,
                self::OTHER_RUNNER_BRANCH,
                self::OTHER_RUNNER_COMMIT_SHA,
                self::OTHER_RUNNER_PR_NUMBER,
                self::OTHER_RUNNER_BASE_SHA
            );

            throw new GitHubApiException(422, 'Update is not a fast forward');
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
