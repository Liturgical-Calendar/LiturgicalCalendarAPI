<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services\Jobs\Catalog;

use LiturgicalCalendar\Api\Services\Jobs\Job;
use LiturgicalCalendar\Api\Services\Jobs\JobContext;

/**
 * Stream job: consumes the source-data publish stream (PublishConsumerLoop) until the runner asks it to stop.
 */
final class PublishConsumerJob implements Job
{
    /** @var \Closure(callable(): bool): void */
    private readonly \Closure $loop;

    /** @param \Closure(callable(): bool): void $loop Runs the loop until the callback returns true. */
    public function __construct(\Closure $loop)
    {
        $this->loop = $loop;
    }

    public function run(JobContext $context): void
    {
        ( $this->loop )(static fn (): bool => $context->shouldStop());
    }
}
