<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services\Jobs;

/**
 * A registered job: its name, kind, schedule, and how to build it.
 *
 * The factory runs only in the child that executes the job, so the supervisor never builds a job's
 * dependencies (OpenFGA, Redis, GitHub) and a misconfigured job fails only its own runs.
 */
final class JobDefinition
{
    /** Added to an interval job's timeout to give its lease; the supervisor kills the child at the timeout. */
    public const INTERVAL_LEASE_MARGIN_SECONDS = 30;

    /** A stream job's lease, renewed by the child every JobRunner::RENEW_EVERY_SECONDS. */
    public const STREAM_LEASE_SECONDS = 60;

    /** @var \Closure(): Job */
    private readonly \Closure $factory;

    /**
     * @param class-string<Job> $class   What the factory builds; decides whether the job is destructive.
     * @param \Closure(): Job   $factory
     */
    public function __construct(
        public readonly string $name,
        public readonly JobKind $kind,
        public readonly string $class,
        \Closure $factory,
        public readonly ?int $intervalSeconds = null,
        public readonly ?int $timeoutSeconds = null
    ) {
        if (preg_match('/^[a-z][a-z0-9-]*$/', $name) !== 1) {
            throw new \InvalidArgumentException("Invalid job name '{$name}': use lowercase letters, digits and hyphens.");
        }
        if (!is_subclass_of($class, Job::class)) {
            throw new \InvalidArgumentException("{$class} does not implement " . Job::class . '.');
        }
        if ($kind === JobKind::INTERVAL && ( $intervalSeconds === null || $intervalSeconds < 1 || $timeoutSeconds === null || $timeoutSeconds < 1 )) {
            throw new \InvalidArgumentException("Interval job '{$name}' needs a positive interval and timeout.");
        }
        if ($kind === JobKind::STREAM && ( $intervalSeconds !== null || $timeoutSeconds !== null )) {
            throw new \InvalidArgumentException("Stream job '{$name}' takes no interval or timeout.");
        }
        $this->factory = $factory;
    }

    public function isDestructive(): bool
    {
        return is_subclass_of($this->class, DestructiveJob::class);
    }

    public function leaseSeconds(): int
    {
        return $this->kind === JobKind::STREAM
            ? self::STREAM_LEASE_SECONDS
            : (int) $this->timeoutSeconds + self::INTERVAL_LEASE_MARGIN_SECONDS;
    }

    /** @throws \LogicException When the factory builds something other than the declared class. */
    public function make(): Job
    {
        $job = ( $this->factory )();
        if (!$job instanceof $this->class) {
            throw new \LogicException("The factory for job '{$this->name}' built " . $job::class . ", not {$this->class}.");
        }

        return $job;
    }
}
