<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services\Jobs\Catalog;

use LiturgicalCalendar\Api\Services\Jobs\DestructiveJob;
use LiturgicalCalendar\Api\Services\Jobs\JobContext;
use LiturgicalCalendar\Api\Services\SourceTreeGuard;

/**
 * Destructive interval job: reconciles OpenFGA `member_nation` tuples against the wider regions each national
 * calendar declares (#1005), repairing a membership an applied write could not record. It prunes, so it
 * refuses to run against a missing source tree.
 */
final class WiderRegionMembershipJob implements DestructiveJob
{
    /** @var \Closure(bool): array{writes: list<string>, deletes: list<string>, skipped: list<string>} */
    private readonly \Closure $reconcile;

    /**
     * @param \Closure(bool): array{writes: list<string>, deletes: list<string>, skipped: list<string>} $reconcile
     *        Runs the reconcile, applying only when passed true.
     */
    public function __construct(private readonly SourceTreeGuard $guard, \Closure $reconcile)
    {
        $this->reconcile = $reconcile;
    }

    public function preflight(): ?string
    {
        return $this->guard->refusalReason();
    }

    public function run(JobContext $context): void
    {
        $apply  = !$context->dryRun;
        $result = ( $this->reconcile )($apply);
        foreach ($result['writes'] as $tuple) {
            $context->say("+ {$tuple}");
        }
        foreach ($result['deletes'] as $tuple) {
            $context->say("- {$tuple}");
        }
        foreach ($result['skipped'] as $nation) {
            $context->say("! {$nation}: its folder has no {$nation}.json (a partial tree?); its membership was left untouched");
        }
        $context->say(sprintf(
            '%s: %d writes, %d deletes%s',
            $apply ? 'Applied' : 'Planned',
            count($result['writes']),
            count($result['deletes']),
            $apply ? '' : ' (dry run)'
        ));
    }
}
