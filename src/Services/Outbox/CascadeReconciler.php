<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services\Outbox;

use LiturgicalCalendar\Api\Repositories\OutboxRepositoryInterface;
use LiturgicalCalendar\Api\Services\RoleCascadeService;
use Psr\Log\LoggerInterface;

/**
 * Post-success bridge between the outbox and Zitadel role cascade.
 *
 * Invoked by ConsumerLoop::tick and BackstopRunner::runOnce after every
 * processOne() returns BENIGN_SUCCESS. Reads the row, dispatches on a
 * metadata.cascade_kind discriminator, and calls
 * RoleCascadeService::maybeCascadeRoleRevoke when appropriate. Never
 * throws back to the caller — failures are logged and swallowed; the
 * row stays in 'succeeded' regardless.
 *
 * See docs/superpowers/specs/2026-06-03-issue-632-deferred-delete-coordination-design.md
 * for the design and acceptance criteria.
 */
final class CascadeReconciler
{
    public function __construct(
        private readonly OutboxRepositoryInterface $outboxRepo,
        /** @phpstan-ignore property.onlyWritten (read in Task 3 + Task 4 dispatchers) */
        private readonly RoleCascadeService $cascade,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function evaluate(int $rowId): void
    {
        $row = $this->outboxRepo->getById($rowId);
        if ($row === null) {
            return;
        }
        if ($row->status !== OutboxStatus::SUCCEEDED) {
            return;
        }
        if ($row->operation !== OutboxOperation::DELETE_TUPLE) {
            return;
        }

        $kind = is_string($row->metadata['cascade_kind'] ?? null)
            ? $row->metadata['cascade_kind']
            : null;
        if ($kind === null) {
            return;
        }

        match ($kind) {
            'access_request_revoke' => $this->dispatchAccessRequestRevoke($row),
            'permission_revoke'     => $this->dispatchPermissionRevoke($row),
            default                 => $this->logger?->warning(
                'CascadeReconciler: unknown cascade_kind, ignoring row',
                ['row_id' => $row->id, 'cascade_kind' => $kind],
            ),
        };
    }

    private function dispatchAccessRequestRevoke(OutboxRow $row): void // @phpstan-ignore void.pure
    {
        // Implemented in Task 3.
        unset($row);
    }

    private function dispatchPermissionRevoke(OutboxRow $row): void // @phpstan-ignore void.pure
    {
        // Implemented in Task 4.
        unset($row);
    }
}
