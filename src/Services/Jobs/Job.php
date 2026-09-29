<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services\Jobs;

/**
 * One unit of background work the job runner can run (#1008).
 *
 * `run()` throws on failure; returning normally is success. An interval job does one pass and returns. A
 * stream job loops and returns only once `$context->shouldStop()` is true — the supervisor restarts it if
 * it returns for any other reason.
 */
interface Job
{
    public function run(JobContext $context): void;
}
