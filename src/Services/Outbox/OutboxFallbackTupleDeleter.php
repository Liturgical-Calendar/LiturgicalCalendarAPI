<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services\Outbox;

use LiturgicalCalendar\Api\Repositories\OutboxBatchInsertInterface;
use LiturgicalCalendar\Api\Services\OpenFgaClient;

/**
 * Deletes one OpenFGA tuple so that a failed delete is never lost.
 *
 * A benign failure (the tuple is already gone) counts as done. Any other failure is enqueued as a DELETE_TUPLE
 * outbox row, and the outbox notifier is told, so the reconciler retries it. Callers log the outcome themselves,
 * in their own words.
 *
 * Shared by {@see \LiturgicalCalendar\Api\Services\RoleCascadeService} and
 * {@see \LiturgicalCalendar\Api\Services\ViewerRetirementSweep}.
 *
 * @phpstan-type TupleDeleteResult array{
 *     outcome: 'deleted'|'already_gone'|'enqueued'|'failed',
 *     outbox_row_ids: list<int>,
 *     error: ?\Throwable
 * }
 */
final class OutboxFallbackTupleDeleter
{
    public function __construct(
        private readonly OpenFgaClient $fga,
        private readonly ?OutboxBatchInsertInterface $outbox = null,
        private readonly ?OutboxNotifier $notifier = null,
    ) {
    }

    /**
     * Outcomes:
     *   - `deleted`      — the tuple was deleted synchronously;
     *   - `already_gone` — OpenFGA said the tuple does not exist (benign);
     *   - `enqueued`     — the delete failed and a DELETE_TUPLE outbox row now carries it;
     *   - `failed`       — the delete failed and no outbox row could be written (no outbox configured).
     *
     * @param array<string, mixed> $metadata Outbox row metadata; the idempotency key is added to it.
     * @return TupleDeleteResult
     */
    public function delete(
        string $fgaUser,
        string $relation,
        string $fgaObject,
        string $idempotencyKey,
        array $metadata = [],
    ): array {
        try {
            $this->fga->deleteTuple($fgaUser, $relation, $fgaObject);
            return ['outcome' => 'deleted', 'outbox_row_ids' => [], 'error' => null];
        } catch (\Throwable $e) {
            if (OutboxClassifier::classify($e) === OutboxDisposition::BENIGN_SUCCESS) {
                return ['outcome' => 'already_gone', 'outbox_row_ids' => [], 'error' => $e];
            }

            $ids = $this->outbox?->insertBatch([
                [
                    'operation'       => OutboxOperation::DELETE_TUPLE,
                    'fga_user'        => $fgaUser,
                    'fga_relation'    => $relation,
                    'fga_object'      => $fgaObject,
                    'idempotency_key' => $idempotencyKey,
                    'metadata'        => ['idempotency_key' => $idempotencyKey] + $metadata,
                ]
            ]) ?? [];
            foreach ($ids as $rowId) {
                $this->notifier?->notify($rowId, OutboxOperation::DELETE_TUPLE->value);
            }

            return ['outcome' => $ids === [] ? 'failed' : 'enqueued', 'outbox_row_ids' => $ids, 'error' => $e];
        }
    }
}
