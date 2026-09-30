<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services\Jobs;

/**
 * A proc_open() child. proc_get_status() reports a real exit code only on the first call after the child
 * exits, so the first one seen is kept.
 */
final class ProcOpenChildProcess implements ChildProcess
{
    private readonly int $pid;

    private ?int $exitCode = null;

    private bool $exited = false;

    /** @param resource $proc */
    public function __construct(private $proc)
    {
        $this->pid = proc_get_status($proc)['pid'];
    }

    public function pid(): int
    {
        return $this->pid;
    }

    public function isRunning(): bool
    {
        $this->poll();

        return !$this->exited;
    }

    public function exitCode(): ?int
    {
        $this->poll();

        return $this->exitCode;
    }

    public function signal(int $signal): void
    {
        if ($this->isRunning()) {
            proc_terminate($this->proc, $signal);
        }
    }

    public function __destruct()
    {
        if (is_resource($this->proc)) {
            proc_close($this->proc);
        }
    }

    private function poll(): void
    {
        if ($this->exited || !is_resource($this->proc)) {
            return;
        }
        $status = proc_get_status($this->proc);
        if ($status['running']) {
            return;
        }
        $this->exited   = true;
        $this->exitCode = $status['signaled'] ? 128 + $status['termsig'] : $status['exitcode'];
    }
}
