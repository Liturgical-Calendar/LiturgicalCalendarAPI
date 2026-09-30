<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services\Jobs;

use LiturgicalCalendar\Api\Repositories\JobScheduleRepository;
use Psr\Log\LoggerInterface;

/**
 * `bin/litcal-jobs supervise`: the one long-running process behind `litcal-jobs.service` (#1008).
 *
 * It starts every job as a child (`bin/litcal-jobs run <job>`), so a hung GitHub publish cannot delay the
 * OpenFGA outbox: each child is its own process. It never runs a job itself and never takes a job's lease —
 * the child does, through {@see JobRunner} — so a supervised run and a manual one behave the same.
 *
 * - **One supervisor at a time.** It holds the lease on the reserved `supervisor` row. A second supervisor
 *   waits as a standby, retrying every STANDBY_RETRY_SECONDS, and takes over when the lease lapses. A standby
 *   never exits, so a second host's unit does not crash-loop under `Restart=on-failure`.
 * - **A database error is not a lost lease.** A renewal that throws leaves the children running, drops the
 *   connection and reconnects on the next pass. Only a renewal that finds the lease taken by another owner
 *   stops the children and returns to standby.
 * - **Stream jobs** are kept running, restarted after an exit with a backoff that doubles from 1 s to 60 s
 *   and resets once a child has run STREAM_HEALTHY_SECONDS.
 * - **Interval jobs** are started when `job_schedule` says they are due and unleased, at most once per
 *   `min(interval, MIN_RELAUNCH_SECONDS)`: a child that dies before recording anything (an autoload fatal, bad
 *   configuration) leaves its job due, and must not be relaunched every second. A child past its timeout gets
 *   SIGTERM, then SIGKILL KILL_GRACE_SECONDS later, and is recorded `timed_out` under its own owner id.
 *
 * Time here is a monotonic clock, used only to pace this process's own actions; every lease and due-time
 * comparison happens in the database. See docs/superpowers/specs/2026-09-29-job-runner-design.md §6.
 */
final class Supervisor
{
    public const LEASE_NAME                 = JobRegistry::RESERVED_NAME;
    public const LEASE_SECONDS              = 60;
    public const RENEW_EVERY_SECONDS        = 15;
    public const STANDBY_RETRY_SECONDS      = 15;
    public const SHUTDOWN_WAIT_SECONDS      = 30;
    public const KILL_GRACE_SECONDS         = 10;
    public const MIN_RELAUNCH_SECONDS       = 60;
    public const STREAM_BACKOFF_MAX_SECONDS = 60;
    public const STREAM_HEALTHY_SECONDS     = 300;

    /** @var \Closure(): JobScheduleRepository */
    private readonly \Closure $connect;

    /** @var \Closure(): float */
    private readonly \Closure $clock;

    /** @var \Closure(float): void */
    private readonly \Closure $sleep;

    private ?JobScheduleRepository $schedule = null;

    private bool $holding = false;

    private bool $standbyLogged = false;

    private float $nextAcquireAt = 0.0;

    private float $lastRenewAt = 0.0;

    private bool $stopRequested = false;

    /** @var array<string, array{child: ChildProcess, startedAt: float, termAt: ?float, killed: bool}> */
    private array $children = [];

    /** @var array<string, float> */
    private array $lastLaunchAt = [];

    /** @var array<string, float> */
    private array $streamBackoff = [];

    /** @var array<string, float> */
    private array $streamNextStartAt = [];

    /** @var list<string> */
    private readonly array $disabled;

    /**
     * @param \Closure(): JobScheduleRepository $connect Opens a fresh connection; called on the first pass and
     *                                                   after any database error.
     * @param list<string>                      $disabled Job names never started (LITCAL_JOBS_DISABLED).
     * @param (\Closure(): float)|null          $clock   Monotonic seconds.
     * @param (\Closure(float): void)|null      $sleep
     */
    public function __construct(
        private readonly JobRegistry $registry,
        \Closure $connect,
        private readonly ChildLauncher $launcher,
        private readonly LoggerInterface $logger,
        private readonly string $owner,
        array $disabled = [],
        ?\Closure $clock = null,
        ?\Closure $sleep = null
    ) {
        $this->connect  = $connect;
        $this->disabled = $disabled;
        $this->clock    = $clock ?? static fn (): float => hrtime(true) / 1e9;
        $this->sleep    = $sleep ?? static function (float $seconds): void {
            usleep((int) ( $seconds * 1_000_000 ));
        };
        foreach ($disabled as $name) {
            if ($registry->get($name) === null) {
                $this->logger->warning('LITCAL_JOBS_DISABLED names a job that does not exist; ignoring it.', ['job' => $name]);
            }
        }
    }

    /**
     * Splits LITCAL_JOBS_DISABLED: comma-separated, trimmed, empty entries dropped.
     *
     * @return list<string>
     */
    public static function parseDisabled(string $raw): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $raw)), static fn (string $n): bool => $n !== ''));
    }

    public function isHoldingLease(): bool
    {
        return $this->holding;
    }

    public function requestStop(): void
    {
        $this->stopRequested = true;
    }

    /** Until SIGTERM or SIGINT; then shuts down in order. */
    public function run(): int
    {
        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, fn () => $this->requestStop());
        pcntl_signal(SIGINT, fn () => $this->requestStop());
        $this->logger->info('Job supervisor starting.', ['owner' => $this->owner]);

        // Whatever ends the loop — a signal, or something escaping a pass — the children are stopped in order and
        // the lease released, rather than left unsupervised until systemd's final SIGKILL.
        try {
            while (!$this->stopRequested) {
                $this->tick();
                ( $this->sleep )(1.0);
            }
        } finally {
            $this->shutdown();
        }
        $this->logger->info('Job supervisor stopped.');

        return 0;
    }

    /** One pass. */
    public function tick(): void
    {
        $now = $this->now();
        $this->reap($now);

        if (!$this->holding) {
            $this->tryToBecomeActive($now);
            if (!$this->holding) {
                return;
            }
        } elseif ($now - $this->lastRenewAt >= self::RENEW_EVERY_SECONDS) {
            if (!$this->renew($now)) {
                return;
            }
        }

        $this->enforceTimeouts($now);
        $this->startStreamJobs($now);
        $this->startDueIntervalJobs($now);
    }

    /** SIGTERM every child, wait for them, SIGKILL and record the rest, release the supervisor lease. */
    public function shutdown(): void
    {
        $this->stopChildren('killed at shutdown');
        if ($this->holding) {
            try {
                $this->repository()?->release(self::LEASE_NAME, $this->owner);
            } catch (\Throwable $e) {
                $this->logger->warning('Could not release the supervisor lease; it will lapse.', ['message' => $e->getMessage()]);
            }
            $this->holding = false;
        }
    }

    private function now(): float
    {
        return ( $this->clock )();
    }

    private function repository(): ?JobScheduleRepository
    {
        if ($this->schedule === null) {
            try {
                $this->schedule = ( $this->connect )();
            } catch (\Throwable $e) {
                $this->logger->error('Cannot reach the database; children keep running.', ['message' => $e->getMessage()]);

                return null;
            }
        }

        return $this->schedule;
    }

    private function databaseFailed(\Throwable $e): void
    {
        $this->logger->error('Database error; will reconnect on the next pass. Children keep running.', ['message' => $e->getMessage()]);
        $this->schedule = null;
    }

    private function tryToBecomeActive(float $now): void
    {
        if ($now < $this->nextAcquireAt) {
            return;
        }
        $this->nextAcquireAt = $now + self::STANDBY_RETRY_SECONDS;
        $schedule            = $this->repository();
        if ($schedule === null) {
            return;
        }
        try {
            $schedule->ensureRows([self::LEASE_NAME]);
            if (!$schedule->acquire(self::LEASE_NAME, $this->owner, self::LEASE_SECONDS)) {
                if (!$this->standbyLogged) {
                    $this->logger->info('Another supervisor is active; waiting as a standby.');
                    $this->standbyLogged = true;
                }

                return;
            }
            $schedule->ensureRows($this->registry->names());
            $this->releaseLeasesOfDeadLocalProcesses($schedule);
        } catch (\Throwable $e) {
            $this->databaseFailed($e);

            return;
        }
        $this->holding       = true;
        $this->standbyLogged = false;
        $this->lastRenewAt   = $now;
        $this->logger->info('Job supervisor active.', ['owner' => $this->owner]);
    }

    /**
     * A supervisor that was SIGKILLed (an OOM kill) leaves its children's leases held until they lapse — up to
     * timeout + 30 s, which is 30 minutes for the sweep. On becoming active, free every lease owned by a process
     * on this host that no longer exists. A live process (a manual run, say) keeps its lease, and so does any
     * other host's: only this host can tell whether one of its own pids is alive.
     */
    private function releaseLeasesOfDeadLocalProcesses(JobScheduleRepository $schedule): void
    {
        $prefix = JobRunner::ownerId(0);
        $prefix = substr($prefix, 0, (int) strrpos($prefix, ':') + 1);
        foreach ($schedule->all() as $name => $row) {
            $owner = $row->leaseOwner;
            if ($name === self::LEASE_NAME || $owner === null || !str_starts_with($owner, $prefix)) {
                continue;
            }
            $pid = substr($owner, strlen($prefix));
            if (!ctype_digit($pid) || self::processIsAlive((int) $pid)) {
                continue;
            }
            $schedule->release($name, $owner);
            $this->logger->warning('Freed a lease left by a process that no longer exists.', ['job' => $name, 'owner' => $owner]);
        }
    }

    private static function processIsAlive(int $pid): bool
    {
        if ($pid <= 0 || $pid === getmypid()) {
            return $pid > 0;
        }
        if (posix_kill($pid, 0)) {
            return true;
        }

        // EPERM: it exists but belongs to another user. Only ESRCH means it is gone.
        return posix_get_last_error() !== PCNTL_ESRCH;
    }

    /** False when the lease was lost and the pass must end. */
    private function renew(float $now): bool
    {
        $schedule = $this->repository();
        if ($schedule === null) {
            return true;
        }
        try {
            $renewed = $schedule->renew(self::LEASE_NAME, $this->owner, self::LEASE_SECONDS);
        } catch (\Throwable $e) {
            $this->databaseFailed($e);

            return true;
        }
        if (!$renewed) {
            $this->logger->warning('Supervisor lease lost to another supervisor; stopping children and standing by.');
            $this->stopChildren('supervisor lease lost');
            $this->holding       = false;
            $this->nextAcquireAt = $now + self::STANDBY_RETRY_SECONDS;

            return false;
        }
        $this->lastRenewAt = $now;

        return true;
    }

    private function reap(float $now): void
    {
        foreach ($this->children as $name => $entry) {
            if ($entry['child']->isRunning()) {
                continue;
            }
            unset($this->children[$name]);
            $exitCode = $entry['child']->exitCode();
            $this->logger->info('Job child exited.', ['job' => $name, 'exit_code' => $exitCode]);
            if ($exitCode !== JobRunner::EXIT_OK && $exitCode !== JobRunner::EXIT_NOT_ACQUIRED && !$entry['killed']) {
                $this->recordUnrecordedExit($name, $entry, $exitCode, $now);
            }
            if ($this->registry->get($name)?->kind === JobKind::STREAM) {
                $ran                            = $now - $entry['startedAt'];
                $previous                       = $this->streamBackoff[$name] ?? null;
                $backoff                        = ( $previous === null || $ran >= self::STREAM_HEALTHY_SECONDS )
                    ? 1.0
                    : min($previous * 2, (float) self::STREAM_BACKOFF_MAX_SECONDS);
                $this->streamBackoff[$name]     = $backoff;
                $this->streamNextStartAt[$name] = $now + $backoff;
            }
        }
    }

    /**
     * A fatal error, an OOM kill or a segfault ends a child without reaching JobRunner's finish(), leaving its
     * last success on record and its lease held until it lapses. Record the run as failed and free the lease.
     * The finish is owner-guarded, so it changes nothing when the child did record its own outcome (which also
     * cleared its lease).
     *
     * @param array{child: ChildProcess, startedAt: float, termAt: ?float, killed: bool} $entry
     */
    private function recordUnrecordedExit(string $name, array $entry, ?int $exitCode, float $now): void
    {
        $definition = $this->registry->get($name);
        try {
            $this->repository()?->finish(
                $name,
                JobRunner::ownerId($entry['child']->pid()),
                JobStatus::FAILED,
                sprintf('exited with code %s without recording an outcome', $exitCode ?? 'unknown'),
                (int) ( ( $now - $entry['startedAt'] ) * 1000 ),
                $definition?->kind === JobKind::INTERVAL ? $definition->intervalSeconds : null
            );
        } catch (\Throwable $e) {
            $this->databaseFailed($e);
        }
    }

    private function enforceTimeouts(float $now): void
    {
        foreach ($this->children as $name => $entry) {
            $definition = $this->registry->get($name);
            if ($definition === null || $definition->kind !== JobKind::INTERVAL || $entry['killed']) {
                continue;
            }
            $timeout = (int) $definition->timeoutSeconds;
            if ($entry['termAt'] === null && $now - $entry['startedAt'] > $timeout) {
                $this->logger->warning('Job exceeded its timeout; sending SIGTERM.', ['job' => $name, 'timeout_seconds' => $timeout]);
                $entry['child']->signal(SIGTERM);
                $this->children[$name]['termAt'] = $now;
            } elseif ($entry['termAt'] !== null && $now - $entry['termAt'] >= self::KILL_GRACE_SECONDS) {
                $this->kill($name, $entry, JobStatus::TIMED_OUT, "killed after {$timeout} s", $now);
            }
        }
    }

    /**
     * @param array{child: ChildProcess, startedAt: float, termAt: ?float, killed: bool} $entry
     */
    private function kill(string $name, array $entry, JobStatus $status, string $reason, float $now): void
    {
        $this->logger->warning('Killing job child.', ['job' => $name, 'reason' => $reason]);
        $entry['child']->signal(SIGKILL);
        if (isset($this->children[$name])) {
            $this->children[$name]['killed'] = true;
        }
        $definition = $this->registry->get($name);
        $interval   = $definition?->kind === JobKind::INTERVAL ? $definition->intervalSeconds : null;
        try {
            $this->repository()?->finish(
                $name,
                JobRunner::ownerId($entry['child']->pid()),
                $status,
                $reason,
                (int) ( ( $now - $entry['startedAt'] ) * 1000 ),
                $interval
            );
        } catch (\Throwable $e) {
            $this->databaseFailed($e);
        }
    }

    private function startStreamJobs(float $now): void
    {
        foreach ($this->registry->namesOfKind(JobKind::STREAM) as $name) {
            if (isset($this->children[$name]) || in_array($name, $this->disabled, true)) {
                continue;
            }
            if ($now < ( $this->streamNextStartAt[$name] ?? 0.0 )) {
                continue;
            }
            $this->launch($name, $now);
        }
    }

    private function startDueIntervalJobs(float $now): void
    {
        $candidates = [];
        foreach ($this->registry->namesOfKind(JobKind::INTERVAL) as $name) {
            if (isset($this->children[$name]) || in_array($name, $this->disabled, true)) {
                continue;
            }
            $minGap = min((int) $this->registry->get($name)?->intervalSeconds, self::MIN_RELAUNCH_SECONDS);
            if (isset($this->lastLaunchAt[$name]) && $now - $this->lastLaunchAt[$name] < $minGap) {
                continue;
            }
            $candidates[] = $name;
        }
        if ($candidates === []) {
            return;
        }
        $schedule = $this->repository();
        if ($schedule === null) {
            return;
        }
        try {
            $due = $schedule->dueNames($candidates);
        } catch (\Throwable $e) {
            $this->databaseFailed($e);

            return;
        }
        foreach ($due as $name) {
            $this->launch($name, $now);
        }
    }

    private function launch(string $name, float $now): void
    {
        try {
            $child = $this->launcher->start($name);
        } catch (\Throwable $e) {
            $this->logger->error('Could not start job child.', ['job' => $name, 'message' => $e->getMessage()]);

            return;
        }
        $this->children[$name]     = ['child' => $child, 'startedAt' => $now, 'termAt' => null, 'killed' => false];
        $this->lastLaunchAt[$name] = $now;
        $this->logger->info('Job child started.', ['job' => $name, 'pid' => $child->pid()]);
    }

    private function stopChildren(string $reason): void
    {
        foreach ($this->children as $entry) {
            $entry['child']->signal(SIGTERM);
        }
        $deadline = $this->now() + self::SHUTDOWN_WAIT_SECONDS;
        while ($this->children !== [] && $this->now() < $deadline) {
            $this->reapQuietly();
            if ($this->children !== []) {
                ( $this->sleep )(0.1);
            }
        }
        $now = $this->now();
        foreach ($this->children as $name => $entry) {
            $this->kill($name, $entry, JobStatus::FAILED, $reason, $now);
        }
        $this->children = [];
    }

    /** Drops exited children without scheduling restarts: used only while stopping. */
    private function reapQuietly(): void
    {
        foreach ($this->children as $name => $entry) {
            if (!$entry['child']->isRunning()) {
                unset($this->children[$name]);
            }
        }
    }
}
