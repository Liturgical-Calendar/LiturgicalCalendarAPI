<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Services\SourceData;

use LiturgicalCalendar\Api\Repositories\SourceDataChangeRequestRepository;
use LiturgicalCalendar\Api\Services\SourceData\PublishResult;
use LiturgicalCalendar\Api\Services\SourceData\SourceDataPublisherInterface;

/**
 * Stands in for the real, `final` `SourceDataPublisher` in {@see PublishRunnerTest} (see
 * {@see SourceDataPublisherInterface} for why a fake is used here rather than a mocked Guzzle
 * stack). Mirrors the one side effect `PublishRunnerTest` depends on: a real
 * `SourceDataPublisher::publish()` call records the publication on the repository before
 * returning, so this fake does the same when it is not configured to throw, keeping the
 * fixture honest about what a real publish leaves behind.
 */
final class FakeSourceDataPublisher implements SourceDataPublisherInterface
{
    public const BRANCH = 'litcal-data/national_calendar/roman/US';

    public const COMMIT_SHA = 'deadbeef';

    public const PR_NUMBER = 7;

    public const BASE_SHA = 'base-sha';

    public int $calls = 0;

    /**
     * @param \Throwable|null $throws          Thrown instead of publishing.
     * @param string|null     $throwsForBatchId Narrows `$throws` to ONE batch id; every other
     *                                          batch publishes normally. Null (the default)
     *                                          means every batch throws. Needed to exercise a
     *                                          failure the runner is expected to CONTINUE past,
     *                                          which is only observable when there is a
     *                                          subsequent batch left to publish.
     */
    public function __construct(
        private readonly SourceDataChangeRequestRepository $repo,
        private readonly ?\Throwable $throws = null,
        private readonly ?string $throwsForBatchId = null
    ) {
    }

    public function publish(string $batchId): PublishResult
    {
        $this->calls++;

        if (null !== $this->throws && ( null === $this->throwsForBatchId || $batchId === $this->throwsForBatchId )) {
            throw $this->throws;
        }

        $this->repo->recordPublication(
            $batchId,
            self::BRANCH,
            self::COMMIT_SHA,
            self::PR_NUMBER,
            self::BASE_SHA
        );

        return new PublishResult(
            self::BRANCH,
            self::COMMIT_SHA,
            self::PR_NUMBER,
            self::BASE_SHA
        );
    }
}
