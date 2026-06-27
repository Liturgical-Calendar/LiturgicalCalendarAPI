<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Repositories;

use LiturgicalCalendar\Api\Services\Outbox\OutboxOperation;

/**
 * Minimal write interface for batch-inserting outbox rows.
 *
 * Extracted so that consumers that only need insertBatch (e.g.
 * ResourceTuplePurgeService) can be tested with plain PHPUnit mocks without
 * depending on the final OutboxRepository class.
 */
interface OutboxBatchInsertInterface
{
    /**
     * Insert a batch of outbox rows, idempotent on idempotency_key.
     *
     * Returns the row IDs in the same order as the input payload.
     *
     * @param list<array{
     *     operation: OutboxOperation,
     *     fga_user: string,
     *     fga_relation: string,
     *     fga_object: string,
     *     idempotency_key: string,
     *     metadata: array<string, mixed>
     * }> $rows
     * @return list<int>
     */
    public function insertBatch(array $rows): array;
}
