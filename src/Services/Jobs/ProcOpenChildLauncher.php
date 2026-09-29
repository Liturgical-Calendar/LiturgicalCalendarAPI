<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services\Jobs;

/**
 * Starts children with proc_open(), without a shell, inheriting this process's stdout and stderr so a child's
 * output reaches the supervisor's journal.
 */
final class ProcOpenChildLauncher implements ChildLauncher
{
    /**
     * @param list<string> $commandPrefix The command before the job name, e.g. [PHP_BINARY, '/app/bin/litcal-jobs', 'run'].
     */
    public function __construct(private readonly array $commandPrefix)
    {
    }

    public function start(string $jobName): ChildProcess
    {
        $command = [...$this->commandPrefix, $jobName];
        $proc    = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => STDOUT, 2 => STDERR], $pipes);
        if (!is_resource($proc)) {
            throw new \RuntimeException("Could not start job '{$jobName}'.");
        }

        return new ProcOpenChildProcess($proc);
    }
}
