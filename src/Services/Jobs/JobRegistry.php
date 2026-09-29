<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Api\Services\Jobs;

/**
 * The set of jobs the runner knows, by name. See {@see Catalog\JobCatalog::default()} for the real one.
 */
final class JobRegistry
{
    /** The `job_schedule` row whose lease keeps a single supervisor active; no job may take its name. */
    public const RESERVED_NAME = 'supervisor';

    /** @var array<string, JobDefinition> */
    private readonly array $definitions;

    public function __construct(JobDefinition ...$definitions)
    {
        $byName = [];
        foreach ($definitions as $definition) {
            if ($definition->name === self::RESERVED_NAME) {
                throw new \InvalidArgumentException("'" . self::RESERVED_NAME . "' is reserved for the supervisor's lease.");
            }
            if (array_key_exists($definition->name, $byName)) {
                throw new \InvalidArgumentException("Job '{$definition->name}' is registered twice.");
            }
            $byName[$definition->name] = $definition;
        }
        $this->definitions = $byName;
    }

    public function get(string $name): ?JobDefinition
    {
        return $this->definitions[$name] ?? null;
    }

    /** @return list<JobDefinition> */
    public function all(): array
    {
        return array_values($this->definitions);
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_keys($this->definitions);
    }

    /** @return list<string> */
    public function namesOfKind(JobKind $kind): array
    {
        $names = [];
        foreach ($this->definitions as $name => $definition) {
            if ($definition->kind === $kind) {
                $names[] = $name;
            }
        }

        return $names;
    }
}
