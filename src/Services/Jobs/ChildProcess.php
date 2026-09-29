<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services\Jobs;

/** A job child the supervisor started. */
interface ChildProcess
{
    public function pid(): int;

    public function isRunning(): bool;

    /** The exit code once the child has exited; null while it runs. */
    public function exitCode(): ?int;

    public function signal(int $signal): void;
}
