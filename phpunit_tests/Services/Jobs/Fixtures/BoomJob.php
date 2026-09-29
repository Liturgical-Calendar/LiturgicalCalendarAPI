<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Services\Jobs\Fixtures;

use LiturgicalCalendar\Api\Services\Jobs\Job;
use LiturgicalCalendar\Api\Services\Jobs\JobContext;

/** Always fails. */
final class BoomJob implements Job
{
    public function run(JobContext $context): void
    {
        throw new \RuntimeException('kaput');
    }
}
