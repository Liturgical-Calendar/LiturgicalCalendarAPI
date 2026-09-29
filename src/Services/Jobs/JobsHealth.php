<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services\Jobs;

use LiturgicalCalendar\Api\Database\Connection;
use LiturgicalCalendar\Api\Repositories\JobScheduleRepository;
use LiturgicalCalendar\Api\Services\Jobs\Catalog\JobCatalog;
use LiturgicalCalendar\Api\Services\SourceData\SourceDataPublisherFactory;

/**
 * The `jobs` block of `GET /health`: is the job runner running, and is every job running on schedule (#1008)?
 *
 * Warnings, each a reason a background job may silently not be happening:
 *
 * - the supervisor's lease has lapsed, so no supervisor is running;
 * - an interval job is `overdue` — unleased and more than one interval (at least OVERDUE_FLOOR_SECONDS) past its
 *   due time, which is what a job that never runs looks like;
 * - a stream job holds no live lease, so its consumer is not running;
 * - a job's last run did not succeed (failed, timed out, or refused by its preflight guard).
 *
 * A job disabled with LITCAL_JOBS_DISABLED is reported as `disabled` and never warns. `last_error` is never
 * included: this endpoint is unauthenticated and error text can carry paths, hostnames or token fragments;
 * `bin/litcal-jobs status` shows it. As for every block of `/health`, a warning here does not change the
 * endpoint's own status.
 */
final class JobsHealth
{
    public const OVERDUE_FLOOR_SECONDS = 120;

    /**
     * @return array{status: string, message: string, supervisor: array{alive: bool, lease_age_seconds: ?int}, jobs: array<string, array<string, mixed>>}
     */
    public static function build(): array
    {
        if (!Connection::isConfigured()) {
            return self::empty('not_configured', 'No database is configured, so no job can run.');
        }
        try {
            $schedule = new JobScheduleRepository(Connection::getInstance());
            $rows     = $schedule->all();
            $now      = $schedule->now();
        } catch (\Throwable) {
            return self::empty('unavailable', 'The job schedule could not be read; is the job_schedule migration applied?');
        }

        return self::summarize(
            JobCatalog::default(),
            $rows,
            $now,
            Supervisor::parseDisabled(SourceDataPublisherFactory::envString('LITCAL_JOBS_DISABLED'))
        );
    }

    /**
     * @param array<string, JobScheduleRow> $rows     Keyed by name, as JobScheduleRepository::all() returns them.
     * @param \DateTimeImmutable            $now      The database clock.
     * @param list<string>                  $disabled
     * @return array{status: string, message: string, supervisor: array{alive: bool, lease_age_seconds: ?int}, jobs: array<string, array<string, mixed>>}
     */
    public static function summarize(JobRegistry $registry, array $rows, \DateTimeImmutable $now, array $disabled): array
    {
        $warnings = [];

        $supervisorRow = $rows[Supervisor::LEASE_NAME] ?? null;
        $alive         = self::isLive($supervisorRow, $now);
        if (!$alive) {
            $warnings[] = 'the job supervisor is not running';
        }

        $jobs = [];
        foreach ($registry->all() as $definition) {
            $name       = $definition->name;
            $row        = $rows[$name] ?? null;
            $isDisabled = in_array($name, $disabled, true);
            $running    = self::isLive($row, $now);
            $overdue    = false;
            $heartbeat  = null;

            if ($definition->kind === JobKind::INTERVAL && $row !== null && !$running) {
                $grace   = max((int) $definition->intervalSeconds, self::OVERDUE_FLOOR_SECONDS);
                $overdue = $now->getTimestamp() > $row->nextDueAt->getTimestamp() + $grace;
            }
            if ($definition->kind === JobKind::STREAM && $running) {
                $heartbeat = self::leaseAge($row, $now, JobDefinition::STREAM_LEASE_SECONDS);
            }

            if (!$isDisabled) {
                if ($overdue) {
                    $warnings[] = "{$name} is overdue";
                }
                if ($definition->kind === JobKind::STREAM && !$running) {
                    $warnings[] = "{$name} is not running";
                }
                if ($row?->lastStatus !== null && $row->lastStatus !== JobStatus::SUCCEEDED) {
                    $warnings[] = "{$name}'s last run {$row->lastStatus->value}";
                }
            }

            $jobs[$name] = [
                'kind'                  => $definition->kind->value,
                'interval_seconds'      => $definition->intervalSeconds,
                'disabled'              => $isDisabled,
                'running'               => $running,
                'last_status'           => $row?->lastStatus?->value,
                'last_success_at'       => $row?->lastSuccessAt?->format(DATE_RFC3339),
                'last_finished_at'      => $row?->lastFinishedAt?->format(DATE_RFC3339),
                'consecutive_failures'  => $row->consecutiveFailures ?? 0,
                'next_due_at'           => $definition->kind === JobKind::INTERVAL ? $row?->nextDueAt->format(DATE_RFC3339) : null,
                'overdue'               => $overdue,
                'heartbeat_age_seconds' => $heartbeat,
            ];
        }

        return [
            'status'     => $warnings === [] ? 'ok' : 'warning',
            'message'    => $warnings === [] ? 'All jobs are running on schedule.' : 'Needs attention: ' . implode('; ', $warnings) . '.',
            'supervisor' => [
                'alive'             => $alive,
                'lease_age_seconds' => $alive ? self::leaseAge($supervisorRow, $now, Supervisor::LEASE_SECONDS) : null,
            ],
            'jobs'       => $jobs,
        ];
    }

    private static function isLive(?JobScheduleRow $row, \DateTimeImmutable $now): bool
    {
        return $row?->leaseUntil !== null && $row->leaseUntil > $now;
    }

    /** Seconds since the lease was last taken or renewed, from how much of its fixed length remains. */
    private static function leaseAge(?JobScheduleRow $row, \DateTimeImmutable $now, int $leaseSeconds): ?int
    {
        if ($row?->leaseUntil === null) {
            return null;
        }

        return max(0, $leaseSeconds - ( $row->leaseUntil->getTimestamp() - $now->getTimestamp() ));
    }

    /**
     * @return array{status: string, message: string, supervisor: array{alive: bool, lease_age_seconds: ?int}, jobs: array<string, array<string, mixed>>}
     */
    private static function empty(string $status, string $message): array
    {
        return ['status' => $status, 'message' => $message, 'supervisor' => ['alive' => false, 'lease_age_seconds' => null], 'jobs' => []];
    }
}
