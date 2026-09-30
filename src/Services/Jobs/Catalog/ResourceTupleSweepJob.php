<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services\Jobs\Catalog;

use LiturgicalCalendar\Api\Services\Jobs\DestructiveJob;
use LiturgicalCalendar\Api\Services\Jobs\JobContext;
use LiturgicalCalendar\Api\Services\SourceTreeGuard;

/**
 * Destructive interval job: revokes editor and viewer tuples whose resource no longer exists on disk. It
 * prunes, so it refuses to run against a missing source tree (#1015).
 */
final class ResourceTupleSweepJob implements DestructiveJob
{
    /** @var \Closure(bool): array{scanned: int, purgedObjects: int, enqueued: int, objects: array<string, int>} */
    private readonly \Closure $sweep;

    /**
     * @param \Closure(bool): array{scanned: int, purgedObjects: int, enqueued: int, objects: array<string, int>} $sweep
     *        Runs the sweep, purging only when passed true.
     */
    public function __construct(private readonly SourceTreeGuard $guard, \Closure $sweep)
    {
        $this->sweep = $sweep;
    }

    public function preflight(): ?string
    {
        return $this->guard->refusalReason();
    }

    public function run(JobContext $context): void
    {
        $apply  = !$context->dryRun;
        $result = ( $this->sweep )($apply);
        foreach ($result['objects'] as $object => $count) {
            $context->say(sprintf('- %s (%d operational tuple%s)', $object, $count, $count === 1 ? '' : 's'));
        }
        $context->say($apply
            ? sprintf('Applied: scanned %d tuples, purged %d objects, enqueued %d rows', $result['scanned'], $result['purgedObjects'], $result['enqueued'])
            : sprintf('Planned: scanned %d tuples, %d objects to purge (dry run)', $result['scanned'], $result['purgedObjects']));
    }
}
