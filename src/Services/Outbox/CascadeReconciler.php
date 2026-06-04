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

    private function dispatchAccessRequestRevoke(OutboxRow $row): void
    {
        $accessRequestId = is_string($row->metadata['access_request_id'] ?? null)
            ? $row->metadata['access_request_id']
            : null;
        $userId          = is_string($row->metadata['cascade_user_id'] ?? null)
            ? $row->metadata['cascade_user_id']
            : null;
        $role            = is_string($row->metadata['cascade_role'] ?? null)
            ? $row->metadata['cascade_role']
            : null;

        if ($accessRequestId === null || $userId === null || $role === null) {
            $this->logger?->warning(
                'CascadeReconciler: access_request_revoke row missing cascade fields',
                [
                    'row_id'         => $row->id,
                    'has_request_id' => $accessRequestId !== null,
                    'has_user_id'    => $userId !== null,
                    'has_role'       => $role !== null
                ],
            );
            return;
        }

        $pending = $this->outboxRepo->countSiblingNonTerminalDeletes($accessRequestId);
        if ($pending > 0) {
            $this->logger?->info(
                'CascadeReconciler: deferring access-request cascade — siblings still in flight',
                ['row_id' => $row->id, 'access_request_id' => $accessRequestId, 'pending' => $pending],
            );
            return;
        }

        try {
            $removed = $this->cascade->maybeCascadeRoleRevoke($userId, $role);
            $this->logger?->info(
                'CascadeReconciler: evaluated access-request cascade',
                [
                    'row_id'            => $row->id,
                    'access_request_id' => $accessRequestId,
                    'user_id'           => $userId,
                    'role'              => $role,
                    'role_removed'      => $removed
                ],
            );
        } catch (\Throwable $e) {
            $this->logger?->warning(
                'CascadeReconciler: maybeCascadeRoleRevoke threw — continuing',
                [
                    'row_id'            => $row->id,
                    'access_request_id' => $accessRequestId,
                    'user_id'           => $userId,
                    'role'              => $role,
                    'error'             => $e->getMessage()
                ],
            );
        }
    }

    private function dispatchPermissionRevoke(OutboxRow $row): void // @phpstan-ignore void.pure
    {
        // Implemented in Task 4.
        unset($row);
    }
}
