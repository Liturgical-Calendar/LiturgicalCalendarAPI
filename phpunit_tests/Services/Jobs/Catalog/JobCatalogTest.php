<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Services\Jobs\Catalog;

use LiturgicalCalendar\Api\Services\Jobs\Catalog\JobCatalog;
use LiturgicalCalendar\Api\Services\Jobs\JobKind;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The registry must match the spec's table (docs/superpowers/specs/2026-09-29-job-runner-design.md §4.3):
 * operators schedule against these names, and /health reports against these intervals.
 */
#[CoversClass(JobCatalog::class)]
final class JobCatalogTest extends TestCase
{
    public function testTheCatalogMatchesTheSpec(): void
    {
        $expected = [
            'outbox-consumer'         => [JobKind::STREAM, null, null, false],
            'publish-consumer'        => [JobKind::STREAM, null, null, false],
            'outbox-backstop'         => [JobKind::INTERVAL, 300, 240, false],
            'publish-backstop'        => [JobKind::INTERVAL, 60, 900, false],
            'merge-poll'              => [JobKind::INTERVAL, 60, 300, false],
            'wider-region-membership' => [JobKind::INTERVAL, 86400, 900, true],
            'resource-tuple-sweep'    => [JobKind::INTERVAL, 86400, 1800, true],
        ];

        $registry = JobCatalog::default();
        self::assertSame(array_keys($expected), $registry->names());
        foreach ($expected as $name => [$kind, $interval, $timeout, $destructive]) {
            $definition = $registry->get($name);
            self::assertNotNull($definition);
            self::assertSame($kind, $definition->kind, $name);
            self::assertSame($interval, $definition->intervalSeconds, $name);
            self::assertSame($timeout, $definition->timeoutSeconds, $name);
            self::assertSame($destructive, $definition->isDestructive(), $name);
        }
    }

    /** Building the registry must not touch Redis, OpenFGA, GitHub or Postgres: the supervisor builds it too. */
    public function testBuildingTheRegistryBuildsNoJob(): void
    {
        $env = $_ENV;
        try {
            foreach (['DB_HOST', 'OPENFGA_API_URL', 'REDIS_HOST', 'REDIS_SOCKET', 'GITHUB_APP_ID'] as $key) {
                unset($_ENV[$key]);
            }
            self::assertCount(7, JobCatalog::default()->all());
        } finally {
            $_ENV = $env;
        }
    }
}
