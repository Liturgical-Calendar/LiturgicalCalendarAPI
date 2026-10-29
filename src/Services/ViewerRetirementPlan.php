<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services;

/**
 * What {@see ViewerRetirementSweep::apply()} would change, as computed by {@see ViewerRetirementSweep::plan()}.
 *
 * @phpstan-type Permission array{object_type: string, object_id: string, relation: string}
 * @phpstan-type ViewerTuple array{user: string, object: string}
 * `keep` holds the non-viewer entries as stored, malformed ones included, so a rewrite never erases them.
 *
 * @phpstan-type PendingRequestChange array{
 *     id: string,
 *     user: string,
 *     keep: list<mixed>,
 *     removed: list<Permission>,
 *     action: 'rewrite'|'reject'
 * }
 * @phpstan-type RoleRevocation array{user: string, role: string}
 * @phpstan-type QueuedViewerWrite array{id: int, user: string, object: string, status: string}
 */
final readonly class ViewerRetirementPlan
{
    /**
     * @param list<ViewerTuple>          $viewerTuples    `viewer` tuples held by a `user:` subject, to delete
     * @param list<PendingRequestChange> $pendingRequests pending requests that name `viewer`, to rewrite or reject
     * @param list<RoleRevocation>       $roleRevocations scoped roles held only through `viewer` (user is a bare Zitadel id)
     * @param array<string, list<string>> $rolesRead     bare user id => the Zitadel roles read for each viewer holder,
     *                                                   so a zero is visibly a zero
     * @param list<QueuedViewerWrite>    $queuedViewerWrites viewer writes for people still queued in the outbox:
     *                                                   the sweep does not touch them, and each would recreate a
     *                                                   tuple once delivered
     */
    public function __construct(
        public array $viewerTuples,
        public array $pendingRequests,
        public array $roleRevocations,
        public array $rolesRead = [],
        public array $queuedViewerWrites = [],
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->viewerTuples === [] && $this->pendingRequests === [] && $this->roleRevocations === [];
    }
}
