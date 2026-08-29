<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services\SourceData;

/**
 * What one {@see PublishRunner::runOnce()} pass did.
 *
 * `published` alone cannot distinguish "the queue was empty" from "a publish attempt failed
 * and the loop stopped early" — both can produce the same count (zero, or some smaller number
 * than `$limit`). That distinction is exactly what a cron-invoked script's exit code needs to
 * signal: an empty queue is success, but a stopped-early run means approved work is piling up
 * unpublished and an operator should be paged, not just logged past.
 */
final readonly class PublishRunResult
{
    public function __construct(
        /** Batches actually published during this run, whether or not it later stopped early. */
        public int $published,
        /** True if the run stopped because a publish attempt threw, rather than an empty queue. */
        public bool $stoppedOnFailure = false
    ) {
    }
}
