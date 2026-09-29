<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services\Jobs;

/**
 * How a job's last run ended; the values of `job_schedule.last_status`.
 *
 * `REFUSED` is a destructive job whose preflight guard said no. Every status but `SUCCEEDED` counts
 * towards `consecutive_failures`.
 */
enum JobStatus: string
{
    case SUCCEEDED = 'succeeded';
    case FAILED    = 'failed';
    case TIMED_OUT = 'timed_out';
    case REFUSED   = 'refused';
}
