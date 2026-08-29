<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services;

/**
 * The two authorization questions the change request workflow asks.
 *
 * Both delegate to ResourceAdminService, which already caches and budgets its
 * OpenFGA fan-out. This class exists so the auto-approval rule lives in exactly
 * one place: a submitter who administers the resource is approved at submit
 * time, and GitHub pull request review is the second pair of eyes.
 */
final readonly class ChangeRequestReview
{
    public function __construct(private ResourceAdminService $resourceAdmin)
    {
    }

    /**
     * Whether $sub holds the `admin` relation on $resource.
     *
     * Fails closed: an unreachable OpenFGA yields false, so the change is
     * recorded as `submitted` and waits for a human. It must never yield true.
     */
    public function administers(ChangeResource $resource, string $sub): bool
    {
        /** @var array<string, bool> $cache */
        $cache = [];

        try {
            return $this->resourceAdmin->administersAllResources(
                [$resource->fgaPermission()],
                'user:' . $sub,
                $cache
            );
        } catch (\RuntimeException) {
            return false;
        }
    }

    /**
     * Keep only the batches whose resource $adminSub administers.
     *
     * Each batch carries the synthetic `permissions` key that
     * SourceDataChangeRequestRepository attaches, which is exactly the shape
     * filterByAdminAccess() reads — so no translation happens here.
     *
     * @param array<int, array<string, mixed>> $batches
     * @return array<int, array<string, mixed>>
     */
    public function filterForAdmin(array $batches, string $adminSub): array
    {
        return $this->resourceAdmin->filterByAdminAccess($batches, $adminSub);
    }
}
