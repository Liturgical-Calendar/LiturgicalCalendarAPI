<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services;

/**
 * What {@see ViewerRetirementSweep::apply()} would change, as computed by {@see ViewerRetirementSweep::plan()}.
 *
 * @phpstan-type Permission array{object_type: string, object_id: string, relation: string}
 * @phpstan-type ViewerTuple array{user: string, object: string}
 * @phpstan-type PendingRequestChange array{
 *     id: string,
 *     user: string,
 *     keep: list<Permission>,
 *     removed: list<Permission>,
 *     action: 'rewrite'|'reject'
 * }
 * @phpstan-type RoleRevocation array{user: string, role: string}
 */
final readonly class ViewerRetirementPlan
{
    /**
     * @param list<ViewerTuple>          $viewerTuples    `viewer` tuples held by a `user:` subject, to delete
     * @param list<PendingRequestChange> $pendingRequests pending requests that name `viewer`, to rewrite or reject
     * @param list<RoleRevocation>       $roleRevocations scoped roles held only through `viewer` (user is a bare Zitadel id)
     */
    public function __construct(
        public array $viewerTuples,
        public array $pendingRequests,
        public array $roleRevocations,
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->viewerTuples === [] && $this->pendingRequests === [] && $this->roleRevocations === [];
    }
}
