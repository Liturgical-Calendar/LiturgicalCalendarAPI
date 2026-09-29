<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services\Jobs\Catalog;

use LiturgicalCalendar\Api\Services\Jobs\Job;
use LiturgicalCalendar\Api\Services\Jobs\JobContext;
use LiturgicalCalendar\Api\Services\SourceData\MergePollRunResult;

/**
 * Interval job: polls open change-request pull requests for merges and closures. Nothing notifies this
 * deployment that a reviewer clicked Merge, so polling is the only way to learn it.
 */
final class MergePollJob implements Job
{
    /** @var \Closure(): MergePollRunResult */
    private readonly \Closure $runOnce;

    /** @param \Closure(): MergePollRunResult $runOnce */
    public function __construct(\Closure $runOnce)
    {
        $this->runOnce = $runOnce;
    }

    /** @throws \RuntimeException When the poll stopped on a failure, so the job is recorded as failed. */
    public function run(JobContext $context): void
    {
        $result = ( $this->runOnce )();
        $context->say(sprintf(
            'poll-sourcedata-merges merged=%d closed=%d reset=%d unpollable=%d stopped_on_failure=%s',
            $result->merged,
            $result->closed,
            $result->reset,
            $result->unpollable,
            $result->stoppedOnFailure ? 'true' : 'false'
        ));
        if ($result->stoppedOnFailure) {
            throw new \RuntimeException('The merge poll stopped on a failure; see the merge-poll log.');
        }
    }
}
