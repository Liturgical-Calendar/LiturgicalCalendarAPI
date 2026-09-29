<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Services\Jobs\Fixtures;

use LiturgicalCalendar\Api\Services\Jobs\Job;
use LiturgicalCalendar\Api\Services\Jobs\JobContext;

/** Ignores every stop request for 30 s, so only SIGKILL ends it. */
final class HangJob implements Job
{
    public function run(JobContext $context): void
    {
        for ($i = 0; $i < 300; ++$i) {
            usleep(100_000);
        }
    }
}
