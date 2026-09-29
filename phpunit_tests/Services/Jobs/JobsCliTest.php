<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Services\Jobs;

use LiturgicalCalendar\Api\Database\Connection;
use LiturgicalCalendar\Api\Services\Jobs\Catalog\JobCatalog;
use LiturgicalCalendar\Api\Services\Jobs\JobRunner;
use LiturgicalCalendar\Api\Services\Jobs\JobsCli;
use LiturgicalCalendar\Tests\Repositories\RepositoryTestCase;
use LiturgicalCalendar\Tests\Support\EnvIsolationTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Log\NullLogger;

#[CoversClass(JobsCli::class)]
final class JobsCliTest extends RepositoryTestCase
{
    use EnvIsolationTrait;

    private const ROOT = __DIR__ . '/../../..';

    /** @param list<string> $argv */
    private function main(array $argv): int
    {
        return JobsCli::main($argv, self::ROOT, new NullLogger());
    }

    public function testNoModeIsAUsageError(): void
    {
        self::assertSame(JobRunner::EXIT_USAGE, $this->main(['litcal-jobs']));
        self::assertSame(JobRunner::EXIT_USAGE, $this->main(['litcal-jobs', 'frobnicate']));
        self::assertSame(JobRunner::EXIT_USAGE, $this->main(['litcal-jobs', 'run']));
    }

    public function testRunningAnUnknownJobIsAUsageError(): void
    {
        ob_start();
        try {
            self::assertSame(JobRunner::EXIT_USAGE, $this->main(['litcal-jobs', 'run', 'nope']));
        } finally {
            ob_end_clean();
        }
    }

    public function testRunWithoutADatabaseIsAConfigurationError(): void
    {
        Connection::close();
        try {
            $code = $this->withoutEnv(
                ['DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASSWORD'],
                fn (): int => $this->main(['litcal-jobs', 'run', 'outbox-backstop'])
            );
        } finally {
            Connection::close();
        }

        self::assertSame(JobRunner::EXIT_USAGE, $code);
    }

    public function testStatusListsEveryJob(): void
    {
        ob_start();
        try {
            $code = $this->main(['litcal-jobs', 'status']);
        } finally {
            $out = (string) ob_get_clean();
        }

        self::assertSame(0, $code);
        foreach (JobCatalog::default()->names() as $name) {
            self::assertStringContainsString($name, $out);
        }
    }

    public function testTheEntryPointIsCliOnlyAndDelegates(): void
    {
        $source = file_get_contents(self::ROOT . '/bin/litcal-jobs');
        self::assertIsString($source);
        self::assertStringContainsString("PHP_SAPI !== 'cli'", $source);
        self::assertMatchesRegularExpression('/exit\(\s*JobsCli::main\(/', $source);
        self::assertTrue(is_executable(self::ROOT . '/bin/litcal-jobs'));
    }

    /** A real run through the CLI: takes the lease on its own connection and records the outcome. */
    public function testRunRecordsTheOutcomeOfARealJob(): void
    {
        ob_start();
        try {
            $code = $this->main(['litcal-jobs', 'run', 'outbox-backstop']);
        } finally {
            ob_end_clean();
        }

        self::assertContains($code, [JobRunner::EXIT_OK, JobRunner::EXIT_FAILED], 'built and ran, or failed to build on this host');
        $stmt = self::$pdo?->query("SELECT last_status FROM job_schedule WHERE name = 'outbox-backstop'");
        self::assertNotFalse($stmt);
        self::assertContains($stmt->fetchColumn(), ['succeeded', 'failed'], 'the run was recorded');
    }

    public function testStatusWithoutADatabaseIsAConfigurationError(): void
    {
        Connection::close();
        try {
            $code = $this->withoutEnv(['DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASSWORD'], fn (): int => $this->main(['litcal-jobs', 'status']));
        } finally {
            Connection::close();
        }

        self::assertSame(JobRunner::EXIT_USAGE, $code);
    }

    /** Without an injected logger the CLI opens its own `jobs` log. */
    public function testTheCliBuildsItsOwnLogger(): void
    {
        self::assertSame(JobRunner::EXIT_USAGE, JobsCli::main(['litcal-jobs', 'run', 'nope'], self::ROOT));
    }
}
