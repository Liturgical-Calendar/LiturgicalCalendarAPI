<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services\Outbox;

/**
 * Readonly snapshot of one openfga_outbox row in transit between
 * OutboxRepository and OutboxProcessor.
 *
 * Repository hydrates these from PG; processor reads them, performs the
 * OpenFGA call, then calls back into the repository to update the
 * underlying row. The row object itself does not mutate — re-read after
 * an update to see the new state.
 */
final class OutboxRow
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public readonly int $id,
        public readonly OutboxOperation $operation,
        public readonly string $fgaUser,
        public readonly string $fgaRelation,
        public readonly string $fgaObject,
        public readonly OutboxStatus $status,
        public readonly int $attempts,
        public readonly \DateTimeImmutable $nextAttemptAt,
        public readonly ?string $lastError,
        public readonly ?string $lastErrorCode,
        public readonly array $metadata,
        public readonly \DateTimeImmutable $createdAt,
        public readonly ?\DateTimeImmutable $completedAt,
    ) {
    }
}
