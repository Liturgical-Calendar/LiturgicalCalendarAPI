<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Services\Jobs;

use LiturgicalCalendar\Api\Database\Connection;
use LiturgicalCalendar\Api\Services\Jobs\Catalog\JobCatalog;
use LiturgicalCalendar\Api\Services\Jobs\JobsHealth;
use LiturgicalCalendar\Tests\Repositories\RepositoryTestCase;
use LiturgicalCalendar\Tests\Support\EnvIsolationTrait;
use PHPUnit\Framework\Attributes\CoversClass;

/** JobsHealth::build(), which reads the real job_schedule table the way GET /health does. */
#[CoversClass(JobsHealth::class)]
final class JobsHealthBuildTest extends RepositoryTestCase
{
    use EnvIsolationTrait;

    protected function tearDown(): void
    {
        Connection::close();
    }

    public function testAnEmptyScheduleReportsEveryJobAndNoSupervisor(): void
    {
        $result = JobsHealth::build();

        self::assertSame('warning', $result['status']);
        self::assertFalse($result['supervisor']['alive']);
        self::assertSame(JobCatalog::default()->names(), array_keys($result['jobs']));
    }

    public function testWithoutADatabaseItSaysSo(): void
    {
        Connection::close();
        $result = $this->withoutEnv(['DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASSWORD'], static fn (): array => JobsHealth::build());

        self::assertSame('not_configured', $result['status']);
        self::assertSame([], $result['jobs']);
    }

    public function testAnUnreadableScheduleIsUnavailableNotAnError(): void
    {
        Connection::close();
        $result = $this->withoutEnv(['DB_HOST'], static function (): array {
            $_ENV['DB_HOST'] = '127.0.0.1';
            $_ENV['DB_PORT'] = '1';
            try {
                return JobsHealth::build();
            } finally {
                unset($_ENV['DB_PORT']);
            }
        });

        self::assertSame('unavailable', $result['status']);
    }
}
