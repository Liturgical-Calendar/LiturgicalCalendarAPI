<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Repositories;

use LiturgicalCalendar\Api\Services\Jobs\JobScheduleRow;
use LiturgicalCalendar\Api\Services\Jobs\JobStatus;
use PDO;

/**
 * All SQL on `job_schedule`, the job runner's schedule state and leases (#1008).
 *
 * A lease is `lease_owner` + `lease_until`. It is taken with one guarded UPDATE that succeeds only when the
 * row is unleased or its lease has lapsed, and it is renewed, finished or released only by its owner. Every
 * time comparison happens in SQL against the database's `now()`, never PHP's clock, so two hosts with skewed
 * clocks still agree on who holds a job and what is due. See docs/superpowers/specs/2026-09-29-job-runner-design.md §5.
 *
 * Placeholder names are never repeated within a statement: native prepares (ATTR_EMULATE_PREPARES off) reject
 * a reused named parameter.
 */
final class JobScheduleRepository
{
    /** `last_error` is cut to this many characters, so a runaway exception message cannot bloat the row. */
    public const MAX_ERROR_LENGTH = 2000;

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Creates a row, due immediately, for each name that has none. Existing rows keep their history.
     *
     * @param list<string> $names
     */
    public function ensureRows(array $names): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO job_schedule (name) VALUES (:name) ON CONFLICT (name) DO NOTHING');
        foreach ($names as $name) {
            $stmt->execute([':name' => $name]);
        }
    }

    /** Takes the lease when the row is unleased or its lease has lapsed; stamps `last_started_at`. */
    public function acquire(string $name, string $owner, int $ttlSeconds): bool
    {
        $stmt = $this->pdo->prepare(<<<'SQL'
            UPDATE job_schedule
               SET lease_owner = :owner, lease_until = now() + make_interval(secs => :ttl), last_started_at = now()
             WHERE name = :name AND (lease_until IS NULL OR lease_until < now())
            SQL);
        $stmt->bindValue(':owner', $owner);
        $stmt->bindValue(':ttl', $ttlSeconds, PDO::PARAM_INT);
        $stmt->bindValue(':name', $name);
        $stmt->execute();

        return $stmt->rowCount() === 1;
    }

    /**
     * Extends the lease while `$owner` still holds it. False means another process took it over (or it was
     * released), and the caller must stop acting as its holder.
     */
    public function renew(string $name, string $owner, int $ttlSeconds): bool
    {
        $stmt = $this->pdo->prepare(<<<'SQL'
            UPDATE job_schedule SET lease_until = now() + make_interval(secs => :ttl)
             WHERE name = :name AND lease_owner = :owner
            SQL);
        $stmt->bindValue(':ttl', $ttlSeconds, PDO::PARAM_INT);
        $stmt->bindValue(':name', $name);
        $stmt->bindValue(':owner', $owner);
        $stmt->execute();

        return $stmt->rowCount() === 1;
    }

    /**
     * Records a run's outcome and clears the lease, only while `$owner` holds it.
     *
     * `$intervalSeconds` null leaves `next_due_at` alone (a stream job has no schedule); otherwise the next run
     * is due that long after this one FINISHED, so a run that overruns its interval cannot make runs pile up.
     */
    public function finish(string $name, string $owner, JobStatus $status, ?string $error, int $durationMs, ?int $intervalSeconds): bool
    {
        $succeeded = $status === JobStatus::SUCCEEDED;
        $stmt      = $this->pdo->prepare(<<<'SQL'
            UPDATE job_schedule
               SET lease_owner = NULL, lease_until = NULL, last_finished_at = now(),
                   last_status = :status, last_error = :error, last_duration_ms = :duration,
                   last_success_at = CASE WHEN :succeeded THEN now() ELSE last_success_at END,
                   consecutive_failures = CASE WHEN :succeeded_again THEN 0 ELSE consecutive_failures + 1 END,
                   next_due_at = CASE WHEN CAST(:interval AS INTEGER) IS NULL THEN next_due_at
                                      ELSE now() + make_interval(secs => CAST(:interval_again AS INTEGER)) END
             WHERE name = :name AND lease_owner = :owner
            SQL);
        $stmt->bindValue(':status', $status->value);
        if ($error === null) {
            $stmt->bindValue(':error', null, PDO::PARAM_NULL);
        } else {
            $stmt->bindValue(':error', mb_substr($error, 0, self::MAX_ERROR_LENGTH));
        }
        $stmt->bindValue(':duration', $durationMs, PDO::PARAM_INT);
        $stmt->bindValue(':succeeded', $succeeded, PDO::PARAM_BOOL);
        $stmt->bindValue(':succeeded_again', $succeeded, PDO::PARAM_BOOL);
        foreach ([':interval', ':interval_again'] as $placeholder) {
            if ($intervalSeconds === null) {
                $stmt->bindValue($placeholder, null, PDO::PARAM_NULL);
            } else {
                $stmt->bindValue($placeholder, $intervalSeconds, PDO::PARAM_INT);
            }
        }
        $stmt->bindValue(':name', $name);
        $stmt->bindValue(':owner', $owner);
        $stmt->execute();

        return $stmt->rowCount() === 1;
    }

    /** Clears the lease without recording an outcome, only while `$owner` holds it. */
    public function release(string $name, string $owner): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE job_schedule SET lease_owner = NULL, lease_until = NULL WHERE name = :name AND lease_owner = :owner'
        );
        $stmt->execute([':name' => $name, ':owner' => $owner]);
    }

    /**
     * Which of `$names` are due and not leased, sorted.
     *
     * @param list<string> $names
     * @return list<string>
     */
    public function dueNames(array $names): array
    {
        if ($names === []) {
            return [];
        }
        $placeholders = [];
        $params       = [];
        foreach (array_values($names) as $i => $name) {
            $placeholders[]   = ":n{$i}";
            $params[":n{$i}"] = $name;
        }
        $stmt = $this->pdo->prepare(sprintf(
            'SELECT name FROM job_schedule WHERE name IN (%s) AND next_due_at <= now() '
            . 'AND (lease_until IS NULL OR lease_until < now()) ORDER BY name',
            implode(', ', $placeholders)
        ));
        $stmt->execute($params);

        $due = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $name) {
            if (is_string($name)) {
                $due[] = $name;
            }
        }

        return $due;
    }

    /** @return array<string, JobScheduleRow> Every row, keyed by name. */
    public function all(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM job_schedule ORDER BY name');
        $rows = [];
        if ($stmt === false) {
            return $rows;
        }
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $raw) {
            if (!is_array($raw)) {
                continue;
            }
            /** @var array<string, mixed> $raw */
            $row              = $this->hydrate($raw);
            $rows[$row->name] = $row;
        }

        return $rows;
    }

    /** The database's clock, the one every lease and due time is measured against. */
    public function now(): \DateTimeImmutable
    {
        $stmt  = $this->pdo->query('SELECT now()');
        $value = $stmt === false ? false : $stmt->fetchColumn();
        if (!is_string($value)) {
            throw new \RuntimeException('Could not read the database clock.');
        }

        return new \DateTimeImmutable($value);
    }

    /** @param array<string, mixed> $raw */
    private function hydrate(array $raw): JobScheduleRow
    {
        $status = self::nullableString($raw, 'last_status');

        return new JobScheduleRow(
            name: (string) self::nullableString($raw, 'name'),
            nextDueAt: self::timestamp($raw, 'next_due_at') ?? throw new \RuntimeException('job_schedule.next_due_at is null.'),
            leaseOwner: self::nullableString($raw, 'lease_owner'),
            leaseUntil: self::timestamp($raw, 'lease_until'),
            lastStartedAt: self::timestamp($raw, 'last_started_at'),
            lastFinishedAt: self::timestamp($raw, 'last_finished_at'),
            lastSuccessAt: self::timestamp($raw, 'last_success_at'),
            lastStatus: $status === null ? null : JobStatus::from($status),
            lastError: self::nullableString($raw, 'last_error'),
            lastDurationMs: is_numeric($raw['last_duration_ms'] ?? null) ? (int) $raw['last_duration_ms'] : null,
            consecutiveFailures: is_numeric($raw['consecutive_failures'] ?? null) ? (int) $raw['consecutive_failures'] : 0,
        );
    }

    /** @param array<string, mixed> $raw */
    private static function nullableString(array $raw, string $key): ?string
    {
        $value = $raw[$key] ?? null;

        return is_string($value) ? $value : null;
    }

    /** @param array<string, mixed> $raw */
    private static function timestamp(array $raw, string $key): ?\DateTimeImmutable
    {
        $value = self::nullableString($raw, $key);

        return $value === null ? null : new \DateTimeImmutable($value);
    }
}
