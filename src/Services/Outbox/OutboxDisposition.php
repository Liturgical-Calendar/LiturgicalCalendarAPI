<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services\Outbox;

/**
 * What OutboxClassifier::classify decided about an exception.
 *
 * The processor consults this to decide whether to mark the row
 * succeeded, schedule a retry, or mark it failed_terminal.
 */
enum OutboxDisposition
{
    case BENIGN_SUCCESS;  // TupleAlreadyExists on write, TupleNotFound on delete
    case RETRY;           // 5xx, 429, network — schedule with backoff
    case TERMINAL;        // 4xx validation/auth — no retry, surface in DLQ
}
