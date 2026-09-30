<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services\Jobs;

/**
 * A job whose run can remove access or data, such as a sweep that prunes OpenFGA tuples.
 *
 * The runner calls `preflight()` before `run()`, in a dry run too, and never calls `run()` when it returns
 * a reason. A refusal is recorded as `refused` and the job waits its full interval before trying again.
 * `run()` must honour `$context->dryRun` by listing what it would change and changing nothing.
 */
interface DestructiveJob extends Job
{
    /** Null when it is safe to run; otherwise why it must not. */
    public function preflight(): ?string;
}
