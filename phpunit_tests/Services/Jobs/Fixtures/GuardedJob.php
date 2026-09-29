<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Services\Jobs\Fixtures;

use LiturgicalCalendar\Api\Services\Jobs\DestructiveJob;
use LiturgicalCalendar\Api\Services\Jobs\JobContext;

/** A destructive job whose preflight always allows the run, which says whether it was a dry run. */
final class GuardedJob implements DestructiveJob
{
    public function preflight(): ?string
    {
        return null;
    }

    public function run(JobContext $context): void
    {
        $context->say('dry=' . var_export($context->dryRun, true));
    }
}
