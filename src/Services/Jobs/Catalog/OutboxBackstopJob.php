<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services\Jobs\Catalog;

use LiturgicalCalendar\Api\Services\Jobs\Job;
use LiturgicalCalendar\Api\Services\Jobs\JobContext;

/**
 * Interval job: one outbox backstop pass — rows the consumer never took, or could not retry.
 */
final class OutboxBackstopJob implements Job
{
    /** @var \Closure(): int */
    private readonly \Closure $runOnce;

    /** @param \Closure(): int $runOnce One BackstopRunner pass; returns how many rows it processed. */
    public function __construct(\Closure $runOnce)
    {
        $this->runOnce = $runOnce;
    }

    public function run(JobContext $context): void
    {
        $context->say(sprintf('backstop processed=%d', ( $this->runOnce )()));
    }
}
