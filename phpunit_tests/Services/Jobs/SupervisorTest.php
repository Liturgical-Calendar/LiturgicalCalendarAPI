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
use LiturgicalCalendar\Api\Services\Jobs\Supervisor;
use LiturgicalCalendar\Tests\Repositories\RepositoryTestCase;
use LiturgicalCalendar\Tests\Services\Jobs\Fixtures\FakeLauncher;
use LiturgicalCalendar\Tests\Services\Jobs\Fixtures\LoopJob;
use LiturgicalCalendar\Tests\Services\Jobs\Fixtures\OkJob;
use LiturgicalCalendar\Tests\Services\Jobs\Fixtures\RecordingLogger;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;

/**
 * The supervisor's scheduling, against a real job_schedule table but fake children and a fake clock, so
 * every timing rule is exercised without sleeping. {@see SupervisorProcessTest} runs real processes.
 */
#[CoversClass(Supervisor::class)]
#[RequiresPhpExtension('pcntl')]
final class SupervisorTest extends RepositoryTestCase
{
    private float $now = 1000.0;

    private FakeLauncher $launcher;

    private RecordingLogger $logger;

    private int $connectCalls = 0;

    private ?PDO $supervisorPdo = null;

    private JobRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();
        $this->now          = 1000.0;
        $this->launcher     = new FakeLauncher();
        $this->logger       = new RecordingLogger();
        $this->connectCalls = 0;
        $this->registry     = new JobRegistry(
            new JobDefinition('tick-job', JobKind::INTERVAL, OkJob::class, static fn (): Job => new OkJob(), 60, 5),
            new JobDefinition('stream-job', JobKind::STREAM, LoopJob::class, static fn (): Job => new LoopJob()),
            new JobDefinition('off-job', JobKind::INTERVAL, OkJob::class, static fn (): Job => new OkJob(), 60, 5),
        );
    }

    /** Each connect opens its own connection, as the real one does, so a test can kill it. */
    private function connect(): JobScheduleRepository
    {
        ++$this->connectCalls;
        $this->supervisorPdo = new PDO(
            sprintf('pgsql:host=%s;port=%s;dbname=%s', self::env('DB_HOST'), self::env('DB_PORT') ?? '5432', self::env('DB_NAME')),
            (string) self::env('DB_USER'),
            (string) self::env('DB_PASSWORD'),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );

        return new JobScheduleRepository($this->supervisorPdo);
    }

    /** @param list<string> $disabled */
    private function supervisor(array $disabled = ['off-job'], string $owner = 'sup:1'): Supervisor
    {
        return new Supervisor(
            $this->registry,
            $this->connect(...),
            $this->launcher,
            $this->logger,
            $owner,
            $disabled,
            fn (): float => $this->now,
            function (float $seconds): void {
                $this->now += $seconds;
            }
        );
    }

    private function schedule(): JobScheduleRepository
    {
        self::assertNotNull(self::$pdo);

        return new JobScheduleRepository(self::$pdo);
    }

    public function testTheFirstTickTakesTheLeaseAndStartsEnabledJobs(): void
    {
        $supervisor = $this->supervisor();
        $supervisor->tick();

        self::assertTrue($supervisor->isHoldingLease());
        self::assertSame('sup:1', $this->schedule()->all()[Supervisor::LEASE_NAME]->leaseOwner);
        self::assertEqualsCanonicalizing(['tick-job', 'stream-job'], $this->launcher->startedNames(), 'off-job is disabled');
    }

    /**
     * A supervisor that was SIGKILLed (an OOM kill) leaves its children's leases held until they lapse — up to
     * timeout + 30 s, 30 minutes for the sweep. The next supervisor frees any lease on this host whose process
     * is gone, and only those: a live process's lease and another host's are left alone.
     */
    public function testBecomingActiveFreesLeasesLeftByDeadProcessesOnThisHost(): void
    {
        $proc = proc_open([PHP_BINARY, '-r', 'echo getmypid();'], [1 => ['pipe', 'w']], $pipes);
        self::assertIsResource($proc);
        $deadPid = (int) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        proc_close($proc);

        $schedule = $this->schedule();
        $schedule->ensureRows(['tick-job', 'off-job', 'stream-job']);
        self::assertTrue($schedule->acquire('tick-job', JobRunner::ownerId($deadPid), 1800));
        self::assertTrue($schedule->acquire('off-job', JobRunner::ownerId(), 1800), 'this live test process');
        self::assertTrue($schedule->acquire('stream-job', 'elsewhere:' . $deadPid, 60), 'another host');

        $this->supervisor()->tick();

        $rows = $schedule->all();
        self::assertContains('tick-job', $this->launcher->startedNames(), 'the freed job is due again at once');
        self::assertSame(JobRunner::ownerId(), $rows['off-job']->leaseOwner);
        self::assertSame('elsewhere:' . $deadPid, $rows['stream-job']->leaseOwner);
    }

    public function testRunningChildrenAreNotStartedAgain(): void
    {
        $supervisor = $this->supervisor();
        $supervisor->tick();
        $this->now += 1;
        $supervisor->tick();

        self::assertCount(2, $this->launcher->started);
    }

    /**
     * Review Focus 1: a child that dies before taking its lease leaves the job still due. The supervisor must
     * not relaunch it every second; it waits min(interval, 60) seconds between launches.
     */
    public function testAnIntervalJobThatDiesWithoutRecordingIsNotRelaunchedEverySecond(): void
    {
        $supervisor = $this->supervisor();
        $supervisor->tick();
        $this->launcher->last('tick-job')->exit(255);

        for ($i = 0; $i < 59; ++$i) {
            $this->now += 1;
            $supervisor->tick();
        }
        self::assertSame(1, count(array_keys($this->launcher->startedNames(), 'tick-job', true)));

        $this->now += 1;
        $supervisor->tick();
        self::assertSame(2, count(array_keys($this->launcher->startedNames(), 'tick-job', true)));
    }

    /**
     * A fatal error, an OOM kill or a segfault ends a child without reaching JobRunner's finish(). The
     * supervisor must record that run as failed and free the lease, or /health keeps showing the last success.
     */
    public function testAChildThatDiesWithoutRecordingIsRecordedFailedAndFreesItsLease(): void
    {
        $supervisor = $this->supervisor();
        $supervisor->tick();
        $child = $this->launcher->last('tick-job');
        self::assertTrue($this->schedule()->acquire('tick-job', JobRunner::ownerId($child->pid()), 35), 'the child took its lease');

        $child->exit(255);
        $this->now += 1;
        $supervisor->tick();

        $row = $this->schedule()->all()['tick-job'];
        self::assertSame(JobStatus::FAILED, $row->lastStatus);
        self::assertStringContainsString('255', (string) $row->lastError);
        self::assertSame(1, $row->consecutiveFailures);
        self::assertNull($row->leaseOwner, 'the lease is freed at once, not after timeout + 30 s');
    }

    public function testAChildThatRecordedItsOwnFailureIsNotRecordedTwice(): void
    {
        $supervisor = $this->supervisor();
        $supervisor->tick();
        $child = $this->launcher->last('tick-job');
        $owner = JobRunner::ownerId($child->pid());
        self::assertTrue($this->schedule()->acquire('tick-job', $owner, 35));
        self::assertTrue($this->schedule()->finish('tick-job', $owner, JobStatus::FAILED, 'its own error', 5, 60));

        $child->exit(1);
        $this->now += 1;
        $supervisor->tick();

        $row = $this->schedule()->all()['tick-job'];
        self::assertSame('its own error', $row->lastError);
        self::assertSame(1, $row->consecutiveFailures);
    }

    public function testAStreamJobIsRestartedWithBackoff(): void
    {
        $supervisor = $this->supervisor();
        $supervisor->tick();
        $countStarts = fn (): int => count(array_keys($this->launcher->startedNames(), 'stream-job', true));

        $this->now += 0.5;
        $this->launcher->last('stream-job')->exit(1);
        $supervisor->tick();
        $this->now += 0.9;
        $supervisor->tick();
        self::assertSame(1, $countStarts(), 'not before the 1 s backoff');
        $this->now += 0.2;
        $supervisor->tick();
        self::assertSame(2, $countStarts());

        $this->launcher->last('stream-job')->exit(1);
        $supervisor->tick();
        $this->now += 1.5;
        $supervisor->tick();
        self::assertSame(2, $countStarts(), 'the second quick exit doubles the wait to 2 s');
        $this->now += 0.6;
        $supervisor->tick();
        self::assertSame(3, $countStarts());

        $this->now += Supervisor::STREAM_HEALTHY_SECONDS;
        $this->launcher->last('stream-job')->exit(1);
        $supervisor->tick();
        $this->now += 1.1;
        $supervisor->tick();
        self::assertSame(4, $countStarts(), 'a child that ran 5 minutes resets the backoff to 1 s');
    }

    public function testAnIntervalChildPastItsTimeoutIsTerminatedThenKilledAndRecorded(): void
    {
        $supervisor = $this->supervisor();
        $supervisor->tick();
        $child = $this->launcher->last('tick-job');
        self::assertTrue($this->schedule()->acquire('tick-job', JobRunner::ownerId($child->pid()), 35), 'the child holds its lease');

        $this->now += 5.5;
        $supervisor->tick();
        self::assertSame([SIGTERM], $child->signals);

        $this->now += Supervisor::KILL_GRACE_SECONDS;
        $supervisor->tick();
        self::assertSame([SIGTERM, SIGKILL], $child->signals);
        $row = $this->schedule()->all()['tick-job'];
        self::assertSame(JobStatus::TIMED_OUT, $row->lastStatus);
        self::assertNull($row->leaseOwner);
    }

    public function testASecondSupervisorWaitsAsAStandbyAndTakesOverWhenTheLeaseLapses(): void
    {
        $this->schedule()->ensureRows([Supervisor::LEASE_NAME]);
        self::assertTrue($this->schedule()->acquire(Supervisor::LEASE_NAME, 'other:1', 60));

        $supervisor = $this->supervisor();
        $supervisor->tick();
        self::assertFalse($supervisor->isHoldingLease());
        self::assertSame([], $this->launcher->started);
        $this->now += 5;
        $supervisor->tick();
        self::assertSame(1, $this->logger->count('standby'), 'standby is logged once');

        self::$pdo?->exec("UPDATE job_schedule SET lease_until = now() - interval '1 second' WHERE name = 'supervisor'");
        $this->now += Supervisor::STANDBY_RETRY_SECONDS;
        $supervisor->tick();
        self::assertTrue($supervisor->isHoldingLease());
        self::assertNotSame([], $this->launcher->started);
    }

    public function testALostLeaseStopsEveryChild(): void
    {
        $this->launcher->exitOnTerm = true;
        $supervisor                 = $this->supervisor();
        $supervisor->tick();

        self::$pdo?->exec("UPDATE job_schedule SET lease_owner = 'thief:1' WHERE name = 'supervisor'");
        $this->now += Supervisor::RENEW_EVERY_SECONDS;
        $supervisor->tick();

        self::assertFalse($supervisor->isHoldingLease());
        foreach ($this->launcher->started as $child) {
            self::assertContains(SIGTERM, $child->signals, $child->jobName);
            self::assertFalse($child->isRunning());
        }
    }

    /** Review Focus 2: the database restarts under a running supervisor. */
    public function testADatabaseErrorKeepsChildrenRunningAndReconnects(): void
    {
        $supervisor = $this->supervisor();
        $supervisor->tick();
        self::assertSame(1, $this->connectCalls);
        // The interval child finishes normally, so only the long-lived stream child is running: the one a
        // supervisor that mistook a database blip for a lost lease would wrongly stop.
        $this->launcher->last('tick-job')->exit(0);

        $pid = $this->supervisorPdo?->query('SELECT pg_backend_pid()')->fetchColumn();
        self::$pdo?->exec('SELECT pg_terminate_backend(' . (int) $pid . ')');
        $this->now += Supervisor::RENEW_EVERY_SECONDS;
        $supervisor->tick();

        $stream = $this->launcher->last('stream-job');
        self::assertSame([], $stream->signals, 'the stream consumer was left alone');
        self::assertTrue($stream->isRunning());
        self::assertTrue($supervisor->isHoldingLease(), 'an error is not a lost lease');

        $this->now += 1;
        $supervisor->tick();
        self::assertSame(2, $this->connectCalls, 'the next pass reconnects instead of reusing the dead connection');
    }

    /** Review Focus 5. */
    public function testTheDisabledListIsTrimmedAndUnknownNamesAreLoggedOnce(): void
    {
        $disabled = Supervisor::parseDisabled(' off-job, ,unknown-job,');
        self::assertSame(['off-job', 'unknown-job'], $disabled);

        $supervisor = $this->supervisor($disabled);
        $supervisor->tick();
        $this->now += 1;
        $supervisor->tick();

        self::assertSame(1, $this->logger->count('unknown-job'));
        self::assertNotContains('off-job', $this->launcher->startedNames());
        self::assertSame([], Supervisor::parseDisabled(''));
    }

    /**
     * Anything escaping a pass (a logger failing on a full disk, say) must still stop the children in order and
     * release the lease on the way out, not leave them unsupervised until systemd's final SIGKILL.
     */
    public function testAnExceptionOutOfAPassStillShutsDownInOrder(): void
    {
        $this->launcher->exitOnTerm = true;
        $logger                     = new class extends \Psr\Log\AbstractLogger {
            /** @param array<mixed> $context */
            public function log($level, string|\Stringable $message, array $context = []): void
            {
                if ((string) $message === 'Job child started.') {
                    throw new \RuntimeException('disk full');
                }
            }
        };
        $supervisor                 = new Supervisor(
            $this->registry,
            $this->connect(...),
            $this->launcher,
            $logger,
            'sup:1',
            ['off-job'],
            fn (): float => $this->now,
            function (float $seconds): void {
                $this->now += $seconds;
            }
        );

        try {
            $supervisor->run();
            self::fail('the exception must still propagate');
        } catch (\RuntimeException $e) {
            self::assertSame('disk full', $e->getMessage());
        } finally {
            pcntl_signal(SIGTERM, SIG_DFL);
            pcntl_signal(SIGINT, SIG_DFL);
        }

        self::assertNotSame([], $this->launcher->started);
        foreach ($this->launcher->started as $child) {
            self::assertSame([SIGTERM], $child->signals, $child->jobName . ' was stopped in order');
        }
        self::assertNull($this->schedule()->all()[Supervisor::LEASE_NAME]->leaseOwner, 'the lease was released');
    }

    public function testShutdownStopsChildrenAndReleasesTheLease(): void
    {
        $this->launcher->exitOnTerm = true;
        $supervisor                 = $this->supervisor();
        $supervisor->tick();

        $supervisor->shutdown();

        foreach ($this->launcher->started as $child) {
            self::assertSame([SIGTERM], $child->signals, $child->jobName);
        }
        self::assertNull($this->schedule()->all()[Supervisor::LEASE_NAME]->leaseOwner);
    }

    public function testAChildThatIgnoresShutdownIsKilledAndRecorded(): void
    {
        $supervisor = $this->supervisor();
        $supervisor->tick();
        $child = $this->launcher->last('tick-job');
        self::assertTrue($this->schedule()->acquire('tick-job', JobRunner::ownerId($child->pid()), 35));

        $supervisor->shutdown();

        self::assertSame([SIGTERM, SIGKILL], $child->signals);
        self::assertSame(JobStatus::FAILED, $this->schedule()->all()['tick-job']->lastStatus);
        self::assertSame('killed at shutdown', $this->schedule()->all()['tick-job']->lastError);
    }

    public function testAnUnreachableDatabaseLeavesTheSupervisorWaitingWithoutCrashing(): void
    {
        $supervisor = new Supervisor(
            $this->registry,
            function (): JobScheduleRepository {
                ++$this->connectCalls;
                throw new \RuntimeException('connection refused');
            },
            $this->launcher,
            $this->logger,
            'sup:1',
            [],
            fn (): float => $this->now,
        );

        $supervisor->tick();
        $this->now += Supervisor::STANDBY_RETRY_SECONDS;
        $supervisor->tick();

        self::assertFalse($supervisor->isHoldingLease());
        self::assertSame([], $this->launcher->started);
        self::assertSame(2, $this->connectCalls);
        self::assertGreaterThanOrEqual(1, $this->logger->count('Cannot reach the database'));
    }

    public function testALauncherFailureIsLoggedAndTheOtherJobsStillStart(): void
    {
        $launcher   = new class ($this->launcher) implements \LiturgicalCalendar\Api\Services\Jobs\ChildLauncher {
            public function __construct(private readonly FakeLauncher $inner)
            {
            }

            public function start(string $jobName): \LiturgicalCalendar\Api\Services\Jobs\ChildProcess
            {
                if ($jobName === 'stream-job') {
                    throw new \RuntimeException('EMFILE');
                }

                return $this->inner->start($jobName);
            }
        };
        $supervisor = new Supervisor($this->registry, $this->connect(...), $launcher, $this->logger, 'sup:1', ['off-job'], fn (): float => $this->now);

        $supervisor->tick();

        self::assertSame(['tick-job'], $this->launcher->startedNames());
        self::assertSame(1, $this->logger->count('EMFILE'));
    }

    /** The database stays down after a blip: every pass copes with having no connection at all. */
    public function testADatabaseThatStaysDownAfterActivationIsSurvived(): void
    {
        $up         = true;
        $supervisor = new Supervisor(
            $this->registry,
            function () use (&$up): JobScheduleRepository {
                if (!$up) {
                    throw new \RuntimeException('connection refused');
                }

                return $this->connect();
            },
            $this->launcher,
            $this->logger,
            'sup:1',
            ['off-job'],
            fn (): float => $this->now,
        );
        $supervisor->tick();
        $this->launcher->last('tick-job')->exit(0);

        $up  = false;
        $pid = $this->supervisorPdo?->query('SELECT pg_backend_pid()')->fetchColumn();
        self::$pdo?->exec('SELECT pg_terminate_backend(' . (int) $pid . ')');
        for ($i = 0; $i < 3; ++$i) {
            $this->now += Supervisor::RENEW_EVERY_SECONDS;
            $supervisor->tick();
        }

        self::assertTrue($supervisor->isHoldingLease());
        self::assertSame([], $this->launcher->last('stream-job')->signals);
    }

    public function testRunExitsCleanlyWhenAskedToStop(): void
    {
        $this->launcher->exitOnTerm = true;
        $supervisor                 = null;
        $passes                     = 0;
        $supervisor                 = new Supervisor(
            $this->registry,
            $this->connect(...),
            $this->launcher,
            $this->logger,
            'sup:1',
            ['off-job'],
            fn (): float => $this->now,
            function (float $seconds) use (&$supervisor, &$passes): void {
                $this->now += $seconds;
                if (++$passes >= 2) {
                    $supervisor?->requestStop();
                }
            }
        );

        try {
            self::assertSame(0, $supervisor->run());
        } finally {
            pcntl_signal(SIGTERM, SIG_DFL);
            pcntl_signal(SIGINT, SIG_DFL);
        }
        self::assertSame(1, $this->logger->count('Job supervisor stopped'));
        self::assertNull($this->schedule()->all()[Supervisor::LEASE_NAME]->leaseOwner);
    }
}
