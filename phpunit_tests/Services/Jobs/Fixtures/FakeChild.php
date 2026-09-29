<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Services\Jobs\Fixtures;

use LiturgicalCalendar\Api\Services\Jobs\ChildProcess;

/** A child process whose lifetime the test controls. */
final class FakeChild implements ChildProcess
{
    private static int $nextPid = 90000;

    public bool $running = true;

    public ?int $code = null;

    /** @var list<int> */
    public array $signals = [];

    public readonly int $pid;

    public function __construct(public readonly string $jobName, public bool $exitOnTerm = false)
    {
        $this->pid = ++self::$nextPid;
    }

    public function pid(): int
    {
        return $this->pid;
    }

    public function isRunning(): bool
    {
        return $this->running;
    }

    public function exitCode(): ?int
    {
        return $this->running ? null : $this->code;
    }

    public function signal(int $signal): void
    {
        $this->signals[] = $signal;
        if ($this->exitOnTerm && $signal === SIGTERM) {
            $this->exit(0);
        }
        if ($signal === SIGKILL) {
            $this->exit(137);
        }
    }

    public function exit(int $code): void
    {
        $this->running = false;
        $this->code    = $code;
    }
}
