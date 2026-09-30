<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services\Jobs\Catalog;

use LiturgicalCalendar\Api\Services\Jobs\Job;
use LiturgicalCalendar\Api\Services\Jobs\JobContext;
use LiturgicalCalendar\Api\Services\SourceData\PublishRunResult;

/**
 * Interval job: one publish run with no message — reclaims batches stranded `queued`, retries batches
 * whose backoff has elapsed, and publishes any approval whose `XADD` was lost.
 */
final class PublishBackstopJob implements Job
{
    /** @var \Closure(): PublishRunResult */
    private readonly \Closure $runOnce;

    /** @param \Closure(): PublishRunResult $runOnce */
    public function __construct(\Closure $runOnce)
    {
        $this->runOnce = $runOnce;
    }

    /** @throws \RuntimeException When the run stopped on a failure, so the job is recorded as failed. */
    public function run(JobContext $context): void
    {
        $result = ( $this->runOnce )();
        $context->say(sprintf(
            'publish-sourcedata published=%d stopped_on_failure=%s parked=%d',
            $result->published,
            $result->stoppedOnFailure ? 'true' : 'false',
            $result->parkedBatches
        ));
        if ($result->stoppedOnFailure) {
            throw new \RuntimeException('The publish run stopped on a failure; see the publish-backstop log.');
        }
    }
}
