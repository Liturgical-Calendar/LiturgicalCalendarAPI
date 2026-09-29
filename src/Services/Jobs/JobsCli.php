<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services\Jobs;

use LiturgicalCalendar\Api\Database\Connection;
use LiturgicalCalendar\Api\Http\Logs\LoggerFactory;
use LiturgicalCalendar\Api\Repositories\JobScheduleRepository;
use LiturgicalCalendar\Api\Router;
use LiturgicalCalendar\Api\Services\Jobs\Catalog\JobCatalog;
use LiturgicalCalendar\Api\Services\SourceData\SourceDataPublisherFactory;
use Psr\Log\LoggerInterface;

/**
 * Everything `bin/litcal-jobs` does, kept in `src/` so PHPStan covers it (#1008).
 *
 *     bin/litcal-jobs supervise                 the long-running supervisor (litcal-jobs.service)
 *     bin/litcal-jobs run <job> [--dry-run]     run one job once, under its lease
 *     bin/litcal-jobs status                    every job's schedule, last run and last error
 *
 * Exit codes: 0 success, 1 failed, 2 usage or configuration, 3 refused, 75 held by another process.
 */
final class JobsCli
{
    private const USAGE = <<<'TXT'
        Usage:
          litcal-jobs supervise
          litcal-jobs run <job> [--dry-run]
          litcal-jobs status
        TXT;

    /** @param list<string> $argv */
    public static function main(array $argv, string $projectRoot, ?LoggerInterface $logger = null): int
    {
        // JsonData paths, which the destructive jobs' SourceTreeGuard reads, resolve against this.
        Router::$apiFilePath = rtrim($projectRoot, '/') . '/';
        $mode                = $argv[1] ?? '';

        try {
            return match ($mode) {
                'supervise' => self::supervise($projectRoot, $logger ?? self::logger()),
                'run'       => self::run($argv, $logger ?? self::logger()),
                'status'    => self::status(),
                default     => self::usage(),
            };
        } catch (\Throwable $e) {
            fwrite(STDERR, 'Error: ' . $e::class . ': ' . $e->getMessage() . PHP_EOL);

            return JobRunner::EXIT_USAGE;
        }
    }

    private static function logger(): LoggerInterface
    {
        // includeProcessors: false, for the reason SourceDataPublisherFactory::logger() documents.
        return LoggerFactory::create('jobs', null, 30, false, true, false);
    }

    private static function usage(): int
    {
        fwrite(STDERR, self::USAGE . PHP_EOL);

        return JobRunner::EXIT_USAGE;
    }

    private static function supervise(string $projectRoot, LoggerInterface $logger): int
    {
        if (!extension_loaded('pcntl') || !extension_loaded('posix')) {
            fwrite(STDERR, 'Error: the job supervisor needs the pcntl and posix extensions in ' . PHP_BINARY . PHP_EOL);

            return JobRunner::EXIT_USAGE;
        }

        return ( new Supervisor(
            JobCatalog::default(),
            static function (): JobScheduleRepository {
                // Always a fresh connection: the supervisor calls this again only after a database error,
                // and reusing the old PDO would keep using a dead socket.
                Connection::close();

                return new JobScheduleRepository(Connection::getInstance());
            },
            new ProcOpenChildLauncher([PHP_BINARY, rtrim($projectRoot, '/') . '/bin/litcal-jobs', 'run']),
            $logger,
            JobRunner::ownerId(),
            Supervisor::parseDisabled(SourceDataPublisherFactory::envString('LITCAL_JOBS_DISABLED'))
        ) )->run();
    }

    /** @param list<string> $argv */
    private static function run(array $argv, LoggerInterface $logger): int
    {
        $name = $argv[2] ?? '';
        if ($name === '') {
            return self::usage();
        }
        $dryRun   = in_array('--dry-run', array_slice($argv, 3), true);
        $registry = JobCatalog::default();
        if ($registry->get($name) === null) {
            // Answered before any database access, so a typo gets a useful reply even without Postgres.
            fwrite(STDERR, "Unknown job '{$name}'. Known jobs: " . implode(', ', $registry->names()) . PHP_EOL);

            return JobRunner::EXIT_USAGE;
        }
        try {
            // Its own connection, not the shared one the job's code uses: a lease renewal must never join, or be
            // rolled back with, a transaction the job opened.
            $schedule = new JobScheduleRepository(Connection::openDedicated());
        } catch (\Throwable $e) {
            fwrite(STDERR, 'Error: cannot reach the database: ' . $e->getMessage() . PHP_EOL);

            return JobRunner::EXIT_USAGE;
        }

        return ( new JobRunner($registry, $schedule, $logger, JobRunner::ownerId()) )->run($name, $dryRun);
    }

    private static function status(): int
    {
        $schedule = new JobScheduleRepository(Connection::getInstance());
        $rows     = $schedule->all();
        $now      = $schedule->now();
        $disabled = Supervisor::parseDisabled(SourceDataPublisherFactory::envString('LITCAL_JOBS_DISABLED'));
        $format   = static fn (?\DateTimeImmutable $t): string => $t === null ? '-' : $t->format(DATE_RFC3339);

        $supervisor = $rows[Supervisor::LEASE_NAME] ?? null;
        $active     = $supervisor?->leaseUntil !== null && $supervisor->leaseUntil > $now;
        echo 'supervisor: ' . ( $active ? 'active (' . $supervisor->leaseOwner . ')' : 'not running' ) . PHP_EOL . PHP_EOL;

        foreach (JobCatalog::default()->all() as $definition) {
            $row     = $rows[$definition->name] ?? null;
            $running = $row?->leaseUntil !== null && $row->leaseUntil > $now;
            echo $definition->name . PHP_EOL;
            echo '  kind:                 ' . $definition->kind->value
                . ( $definition->intervalSeconds === null ? '' : ", every {$definition->intervalSeconds} s, timeout {$definition->timeoutSeconds} s" ) . PHP_EOL;
            echo '  disabled:             ' . ( in_array($definition->name, $disabled, true) ? 'yes' : 'no' ) . PHP_EOL;
            echo '  running:              ' . ( $running ? 'yes (' . $row->leaseOwner . ')' : 'no' ) . PHP_EOL;
            echo '  last status:          ' . ( $row?->lastStatus->value ?? '-' ) . PHP_EOL;
            echo '  last finished:        ' . $format($row?->lastFinishedAt) . PHP_EOL;
            echo '  last success:         ' . $format($row?->lastSuccessAt) . PHP_EOL;
            echo '  consecutive failures: ' . ( $row->consecutiveFailures ?? 0 ) . PHP_EOL;
            echo '  next due:             ' . ( $definition->kind === JobKind::STREAM ? '-' : $format($row?->nextDueAt) ) . PHP_EOL;
            echo '  last error:           ' . ( $row->lastError ?? '-' ) . PHP_EOL . PHP_EOL;
        }

        return JobRunner::EXIT_OK;
    }
}
