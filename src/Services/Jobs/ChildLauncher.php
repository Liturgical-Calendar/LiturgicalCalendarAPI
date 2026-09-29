<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services\Jobs;

/** Starts `bin/litcal-jobs run <job>` (or a stand-in) as a child process. */
interface ChildLauncher
{
    /** @throws \RuntimeException When the process cannot be started. */
    public function start(string $jobName): ChildProcess;
}
