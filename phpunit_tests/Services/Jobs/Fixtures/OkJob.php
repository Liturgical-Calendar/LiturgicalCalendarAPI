<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Services\Jobs\Fixtures;

use LiturgicalCalendar\Api\Services\Jobs\Job;
use LiturgicalCalendar\Api\Services\Jobs\JobContext;

/** Succeeds without doing anything. */
final class OkJob implements Job
{
    public function run(JobContext $context): void
    {
    }
}
