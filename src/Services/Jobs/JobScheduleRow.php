<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services\Jobs;

/**
 * Readonly snapshot of one job_schedule row. Re-read after an update to see the new state.
 */
final class JobScheduleRow
{
    public function __construct(
        public readonly string $name,
        public readonly \DateTimeImmutable $nextDueAt,
        public readonly ?string $leaseOwner,
        public readonly ?\DateTimeImmutable $leaseUntil,
        public readonly ?\DateTimeImmutable $lastStartedAt,
        public readonly ?\DateTimeImmutable $lastFinishedAt,
        public readonly ?\DateTimeImmutable $lastSuccessAt,
        public readonly ?JobStatus $lastStatus,
        public readonly ?string $lastError,
        public readonly ?int $lastDurationMs,
        public readonly int $consecutiveFailures,
    ) {
    }
}
