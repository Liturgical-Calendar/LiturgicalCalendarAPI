<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services\Outbox;

/**
 * What OutboxClassifier::classify decided about an exception, and so what
 * OutboxProcessor::processOne did with the row.
 *
 * The processor consults this to decide whether to mark the row
 * succeeded, schedule a retry, or mark it failed_terminal. LOCKED is the
 * processor's alone: the classifier never returns it.
 */
enum OutboxDisposition
{
    case BENIGN_SUCCESS;  // TupleAlreadyExists on write, TupleNotFound on delete
    case RETRY;           // 5xx, 429, network — schedule with backoff
    case TERMINAL;        // 4xx validation/auth — no retry, surface in DLQ
    case LOCKED;          // another runner holds the row (#1014) — this call did nothing; the holder settles it
}
