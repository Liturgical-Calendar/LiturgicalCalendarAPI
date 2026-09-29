<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Services\Jobs;

use LiturgicalCalendar\Api\Repositories\JobScheduleRepository;
use LiturgicalCalendar\Api\Services\Jobs\Job;
use LiturgicalCalendar\Api\Services\Jobs\JobDefinition;
use LiturgicalCalendar\Api\Services\Jobs\JobKind;
use LiturgicalCalendar\Api\Services\Jobs\JobRegistry;
use LiturgicalCalendar\Api\Services\Jobs\JobRunner;
use LiturgicalCalendar\Api\Services\Jobs\JobStatus;
use LiturgicalCalendar\Tests\Repositories\RepositoryTestCase;
use LiturgicalCalendar\Tests\Services\Jobs\Fixtures\BoomJob;
use LiturgicalCalendar\Tests\Services\Jobs\Fixtures\GuardedJob;
use LiturgicalCalendar\Tests\Services\Jobs\Fixtures\LoopJob;
use LiturgicalCalendar\Tests\Services\Jobs\Fixtures\OkJob;
use LiturgicalCalendar\Tests\Services\Jobs\Fixtures\RefuseJob;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use Psr\Log\NullLogger;

#[CoversClass(JobRunner::class)]
final class JobRunnerTest extends RepositoryTestCase
{
    private JobScheduleRepository $schedule;

    private JobRegistry $registry;

    /** @var list<string> */
    private array $said = [];

    protected function setUp(): void
    {
        parent::setUp();
        self::assertNotNull(self::$pdo);
        $this->schedule = new JobScheduleRepository(self::$pdo);
        $this->registry = new JobRegistry(
            self::interval('ok', OkJob::class),
            self::interval('boom', BoomJob::class),
            self::interval('refuse', RefuseJob::class),
            self::interval('echo', GuardedJob::class),
            new JobDefinition('loop', JobKind::STREAM, LoopJob::class, static fn (): Job => new LoopJob())
        );
        $this->schedule->ensureRows($this->registry->names());
        $this->said      = [];
        RefuseJob::$runs = 0;
        LoopJob::reset();
    }

    /** @param class-string<Job> $class */
    private static function interval(string $name, string $class): JobDefinition
    {
        return new JobDefinition($name, JobKind::INTERVAL, $class, static fn (): Job => new $class(), 300, 60);
    }

    private function runner(int $renewEverySeconds = JobRunner::RENEW_EVERY_SECONDS): JobRunner
    {
        return new JobRunner($this->registry, $this->schedule, new NullLogger(), 'test:1', $renewEverySeconds, function (string $line): void {
            $this->said[] = $line;
        });
    }

    public function testASuccessIsRecordedAndScheduledAnIntervalAhead(): void
    {
        self::assertSame(JobRunner::EXIT_OK, $this->runner()->run('ok'));

        $row = $this->schedule->all()['ok'];
        self::assertSame(JobStatus::SUCCEEDED, $row->lastStatus);
        self::assertNull($row->leaseOwner, 'the lease is released');
        self::assertSame([], $this->schedule->dueNames(['ok']), 'next run is an interval away');
    }

    public function testAFailureIsRecordedWithItsError(): void
    {
        self::assertSame(JobRunner::EXIT_FAILED, $this->runner()->run('boom'));

        $row = $this->schedule->all()['boom'];
        self::assertSame(JobStatus::FAILED, $row->lastStatus);
        self::assertSame('RuntimeException: kaput', $row->lastError);
        self::assertSame(1, $row->consecutiveFailures);
    }

    public function testARefusalIsRecordedAndTheJobNeverRuns(): void
    {
        self::assertSame(JobRunner::EXIT_REFUSED, $this->runner()->run('refuse'));

        $row = $this->schedule->all()['refuse'];
        self::assertSame(JobStatus::REFUSED, $row->lastStatus);
        self::assertSame('tree missing', $row->lastError);
        self::assertSame(0, RefuseJob::$runs);
    }

    public function testADryRunStillRefusesButRecordsNothing(): void
    {
        self::assertSame(JobRunner::EXIT_REFUSED, $this->runner()->run('refuse', dryRun: true));

        self::assertNull($this->schedule->all()['refuse']->lastStatus);
        self::assertSame(0, RefuseJob::$runs);
        self::assertStringContainsString('tree missing', implode("\n", $this->said));
    }

    public function testADryRunRunsTheJobWithoutALeaseOrARecord(): void
    {
        self::$pdo?->exec("UPDATE job_schedule SET lease_owner = 'other:1', lease_until = now() + interval '1 minute' WHERE name = 'echo'");

        self::assertSame(JobRunner::EXIT_OK, $this->runner()->run('echo', dryRun: true));

        self::assertSame(['dry=true'], $this->said);
        $row = $this->schedule->all()['echo'];
        self::assertSame('other:1', $row->leaseOwner, 'a dry run neither needs nor takes the lease');
        self::assertNull($row->lastStatus);
    }

    public function testAnUnknownJobIsAUsageError(): void
    {
        self::assertSame(JobRunner::EXIT_USAGE, $this->runner()->run('nope'));
    }

    public function testAStreamJobCannotBeDryRun(): void
    {
        self::assertSame(JobRunner::EXIT_USAGE, $this->runner()->run('loop', dryRun: true));
    }

    /**
     * Review Focus 3: an operator runs a job by hand while the supervisor's child holds it. The manual run
     * must step aside without touching the running child's row.
     */
    public function testAHeldLeaseMeansNotAcquiredAndNothingRecorded(): void
    {
        self::$pdo?->exec("UPDATE job_schedule SET lease_owner = 'other:1', lease_until = now() + interval '1 minute' WHERE name = 'ok'");

        self::assertSame(JobRunner::EXIT_NOT_ACQUIRED, $this->runner()->run('ok'));

        $row = $this->schedule->all()['ok'];
        self::assertSame('other:1', $row->leaseOwner);
        self::assertNull($row->lastStatus);
    }

    public function testTheOwnerIdIsHostAndPid(): void
    {
        self::assertSame(gethostname() . ':42', JobRunner::ownerId(42));
        self::assertSame(gethostname() . ':' . getmypid(), JobRunner::ownerId());
    }

    #[RequiresPhpExtension('pcntl')]
    public function testAStreamJobRenewsItsLeaseWhileItRuns(): void
    {
        LoopJob::$selfStopAfterSeconds = 2.5;
        $untilSeen                     = [];
        LoopJob::$onTick               = function () use (&$untilSeen): void {
            $until = $this->schedule->all()['loop']->leaseUntil;
            if ($until !== null) {
                $untilSeen[$until->format('U.u')] = true;
            }
        };

        self::assertSame(JobRunner::EXIT_OK, $this->runner(renewEverySeconds: 1)->run('loop'));

        self::assertGreaterThanOrEqual(2, count($untilSeen), 'the lease expiry moved while the job ran');
        $row = $this->schedule->all()['loop'];
        self::assertSame(JobStatus::SUCCEEDED, $row->lastStatus);
        self::assertNull($row->leaseOwner);
    }

    #[RequiresPhpExtension('pcntl')]
    public function testAStreamJobStopsWhenItsLeaseIsTakenAndRecordsNothing(): void
    {
        LoopJob::$selfStopAfterSeconds = 8.0;
        LoopJob::$onTick               = function (float $elapsed): void {
            if ($elapsed >= 1.2) {
                self::$pdo?->exec("UPDATE job_schedule SET lease_owner = 'thief:1' WHERE name = 'loop' AND lease_owner = 'test:1'");
            }
        };

        $this->runner(renewEverySeconds: 1)->run('loop');

        self::assertTrue(LoopJob::$stoppedByRequest, 'the failed renewal asked the job to stop');
        self::assertLessThan(4.0, LoopJob::$ranForSeconds);
        $row = $this->schedule->all()['loop'];
        self::assertSame('thief:1', $row->leaseOwner, 'the new holder keeps its lease');
        self::assertNull($row->lastStatus, 'the loser records nothing');
    }

    #[RequiresPhpExtension('pcntl')]
    public function testTheRunnerRestoresTheSignalHandlersItReplaced(): void
    {
        $before = [pcntl_signal_get_handler(SIGTERM), pcntl_signal_get_handler(SIGALRM)];

        $this->runner()->run('ok');

        self::assertSame($before, [pcntl_signal_get_handler(SIGTERM), pcntl_signal_get_handler(SIGALRM)]);
    }
}
