<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services\Jobs;

use LiturgicalCalendar\Api\Repositories\JobScheduleRepository;
use Psr\Log\LoggerInterface;

/**
 * `bin/litcal-jobs run <job>`: runs one job once, under its lease, and records how it ended (#1008).
 *
 * The supervisor starts every child through this, and an operator's manual run goes through the same path,
 * so the two cannot drift: both take the lease, both record the outcome, and a manual run while the
 * supervisor's child holds the job steps aside with EXIT_NOT_ACQUIRED instead of running it twice.
 *
 * A dry run takes no lease and records nothing. It still runs a destructive job's preflight, so an operator
 * sees a refusal before applying.
 *
 * SIGTERM and SIGINT ask the job to stop (a stream job returns after the message in hand; an interval job
 * finishes its pass). A stream job's lease is renewed from a SIGALRM timer every `$renewEverySeconds`; a
 * renewal that finds the lease taken by someone else asks the job to stop, and the finish then records
 * nothing because this process no longer owns the row. Signal handlers are restored when the run ends.
 *
 * See docs/superpowers/specs/2026-09-29-job-runner-design.md §5.2 and §7.
 */
final class JobRunner
{
    public const EXIT_OK           = 0;
    public const EXIT_FAILED       = 1;
    public const EXIT_USAGE        = 2;
    public const EXIT_REFUSED      = 3;
    public const EXIT_NOT_ACQUIRED = 75;

    public const RENEW_EVERY_SECONDS = 15;

    /**
     * A stream job stops after this many renewals fail in a row: 3 × 15 s is 45 s, before its 60 s lease lapses.
     * After a Postgres restart the child's connection is dead and pgsql PDO never reconnects, so renewing on it can
     * never succeed again; stopping lets the supervisor restart the job on a fresh connection instead of leaving it
     * running without the lease that makes it exclusive.
     */
    public const MAX_FAILED_RENEWALS = 3;

    private bool $stop = false;

    /** @var (\Closure(string): void)|null */
    private readonly ?\Closure $writer;

    /**
     * @param (\Closure(string): void)|null $writer Operator-facing output; STDOUT when null.
     */
    public function __construct(
        private readonly JobRegistry $registry,
        private readonly JobScheduleRepository $schedule,
        private readonly LoggerInterface $logger,
        private readonly string $owner,
        private readonly int $renewEverySeconds = self::RENEW_EVERY_SECONDS,
        ?\Closure $writer = null
    ) {
        $this->writer = $writer;
    }

    /** `host:pid`, the lease owner a process records; the supervisor derives a child's from its pid. */
    public static function ownerId(?int $pid = null): string
    {
        return ( gethostname() ?: 'localhost' ) . ':' . ( $pid ?? getmypid() );
    }

    public function run(string $name, bool $dryRun = false): int
    {
        $definition = $this->registry->get($name);
        if ($definition === null) {
            $this->logger->error('Unknown job.', ['job' => $name]);
            $this->say("Unknown job '{$name}'. Known jobs: " . implode(', ', $this->registry->names()));

            return self::EXIT_USAGE;
        }
        if ($dryRun && $definition->kind === JobKind::STREAM) {
            $this->say("Job '{$name}' is a stream consumer and has no dry run.");

            return self::EXIT_USAGE;
        }

        $this->stop = false;
        $restore    = $this->installStopHandlers();
        try {
            return $dryRun ? $this->dryRun($definition) : $this->leasedRun($definition);
        } finally {
            $restore();
        }
    }

    private function dryRun(JobDefinition $definition): int
    {
        try {
            $job = $definition->make();
            if ($job instanceof DestructiveJob) {
                $refusal = $job->preflight();
                if ($refusal !== null) {
                    $this->say("Refused: {$refusal}");

                    return self::EXIT_REFUSED;
                }
            }
            $job->run($this->context(true));
        } catch (\Throwable $e) {
            $this->logger->error('Dry run failed.', ['job' => $definition->name, 'exception' => $e::class, 'message' => $e->getMessage()]);
            $this->say('Failed: ' . $e::class . ': ' . $e->getMessage());

            return self::EXIT_FAILED;
        }

        return self::EXIT_OK;
    }

    private function leasedRun(JobDefinition $definition): int
    {
        $name = $definition->name;
        // Only the supervisor creates rows otherwise, so a manual run on a host where it has never run would
        // find no row and wrongly report the job as held. Idempotent; an existing row keeps its history.
        $this->schedule->ensureRows([$name]);
        if (!$this->schedule->acquire($name, $this->owner, $definition->leaseSeconds())) {
            $this->logger->info('Job is held by another process; not running it.', ['job' => $name]);

            return self::EXIT_NOT_ACQUIRED;
        }

        $started   = hrtime(true);
        $status    = JobStatus::SUCCEEDED;
        $error     = null;
        $exitCode  = self::EXIT_OK;
        $stopTimer = static function (): void {
        };
        try {
            $job = $definition->make();
            if ($job instanceof DestructiveJob) {
                $refusal = $job->preflight();
                if ($refusal !== null) {
                    $status   = JobStatus::REFUSED;
                    $error    = $refusal;
                    $exitCode = self::EXIT_REFUSED;
                    $this->say("Refused: {$refusal}");
                }
            }
            if ($status === JobStatus::SUCCEEDED) {
                if ($definition->kind === JobKind::STREAM) {
                    $stopTimer = $this->startRenewalTimer($definition);
                }
                $job->run($this->context(false));
            }
        } catch (\Throwable $e) {
            $status   = JobStatus::FAILED;
            $error    = $e::class . ': ' . $e->getMessage();
            $exitCode = self::EXIT_FAILED;
            $this->logger->error('Job failed.', ['job' => $name, 'exception' => $e::class, 'message' => $e->getMessage()]);
        } finally {
            $stopTimer();
        }

        $durationMs = (int) ( ( hrtime(true) - $started ) / 1_000_000 );
        $interval   = $definition->kind === JobKind::INTERVAL ? $definition->intervalSeconds : null;
        try {
            if (!$this->schedule->finish($name, $this->owner, $status, $error, $durationMs, $interval)) {
                $this->logger->warning('Lease lost before the outcome could be recorded; another process holds the job now.', ['job' => $name]);
            }
        } catch (\Throwable $e) {
            // The run happened but its outcome cannot be recorded (a dead connection). Its lease lapses on its own,
            // and the supervisor records the non-zero exit. A failed run, not a usage error.
            $this->logger->error('Could not record the outcome of the run.', ['job' => $name, 'status' => $status->value, 'message' => $e->getMessage()]);

            return self::EXIT_FAILED;
        }

        return $exitCode;
    }

    private function context(bool $dryRun): JobContext
    {
        return new JobContext($dryRun, fn (): bool => $this->stop, $this->logger, $this->writer);
    }

    private function say(string $line): void
    {
        if ($this->writer !== null) {
            ( $this->writer )($line);

            return;
        }
        fwrite(STDOUT, $line . PHP_EOL);
    }

    /**
     * @return \Closure(): void Restores the handlers that were in place before.
     */
    private function installStopHandlers(): \Closure
    {
        if (!extension_loaded('pcntl')) {
            return static function (): void {
            };
        }
        $previousAsync = pcntl_async_signals(true);
        $previous      = [SIGTERM => pcntl_signal_get_handler(SIGTERM), SIGINT => pcntl_signal_get_handler(SIGINT)];
        $handler       = function (): void {
            $this->stop = true;
        };
        pcntl_signal(SIGTERM, $handler);
        pcntl_signal(SIGINT, $handler);

        return static function () use ($previous, $previousAsync): void {
            foreach ($previous as $signal => $old) {
                pcntl_signal($signal, $old);
            }
            pcntl_async_signals($previousAsync);
        };
    }

    /**
     * Renews a stream job's lease every `$renewEverySeconds` from SIGALRM.
     *
     * @return \Closure(): void Disarms the timer and restores the previous SIGALRM handler.
     */
    private function startRenewalTimer(JobDefinition $definition): \Closure
    {
        if (!extension_loaded('pcntl')) {
            return static function (): void {
            };
        }
        $previous = pcntl_signal_get_handler(SIGALRM);
        $failures = 0;
        pcntl_signal(SIGALRM, function () use ($definition, &$failures): void {
            try {
                if (!$this->schedule->renew($definition->name, $this->owner, $definition->leaseSeconds())) {
                    $this->logger->warning('Lease taken over by another process; stopping.', ['job' => $definition->name]);
                    $this->stop = true;

                    return;
                }
                $failures = 0;
            } catch (\Throwable $e) {
                // One failure is a blip the lease absorbs; repeated failures mean this connection is dead.
                ++$failures;
                $this->logger->warning('Lease renewal failed.', ['job' => $definition->name, 'failures' => $failures, 'message' => $e->getMessage()]);
                if ($failures >= self::MAX_FAILED_RENEWALS) {
                    $this->logger->error('Lease renewal keeps failing; stopping so the job restarts on a fresh connection.', ['job' => $definition->name]);
                    $this->stop = true;

                    return;
                }
            }
            pcntl_alarm($this->renewEverySeconds);
        });
        pcntl_alarm($this->renewEverySeconds);

        return static function () use ($previous): void {
            pcntl_alarm(0);
            pcntl_signal(SIGALRM, $previous);
        };
    }
}
