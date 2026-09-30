<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services\Jobs;

/**
 * `STREAM`: a long-lived child the supervisor keeps running (a Redis Streams consumer).
 * `INTERVAL`: a short-lived child the supervisor starts when it is due and kills at its timeout.
 */
enum JobKind: string
{
    case STREAM   = 'stream';
    case INTERVAL = 'interval';
}
