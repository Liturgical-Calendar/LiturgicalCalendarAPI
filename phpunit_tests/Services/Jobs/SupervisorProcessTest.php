<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Services\Jobs;

use LiturgicalCalendar\Api\Services\Jobs\ProcOpenChildProcess;
use LiturgicalCalendar\Api\Repositories\JobScheduleRepository;
use LiturgicalCalendar\Api\Services\Jobs\ChildLauncher;
use LiturgicalCalendar\Api\Services\Jobs\ChildProcess;
use LiturgicalCalendar\Api\Services\Jobs\Job;
use LiturgicalCalendar\Api\Services\Jobs\JobDefinition;
use LiturgicalCalendar\Api\Services\Jobs\JobKind;
use LiturgicalCalendar\Api\Services\Jobs\JobRegistry;
use LiturgicalCalendar\Api\Services\Jobs\JobStatus;
use LiturgicalCalendar\Api\Services\Jobs\ProcOpenChildLauncher;
use LiturgicalCalendar\Api\Services\Jobs\Supervisor;
use LiturgicalCalendar\Tests\Repositories\RepositoryTestCase;
use LiturgicalCalendar\Tests\Services\Jobs\Fixtures\HangJob;
use LiturgicalCalendar\Tests\Services\Jobs\Fixtures\OkJob;
use LiturgicalCalendar\Tests\Support\LiveDbSubprocessTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use Psr\Log\NullLogger;

/**
 * Real processes: the supervisor starting, timing out and killing a child through proc_open, and a child's
 * own SIGTERM handling through JobRunner. The fixture script is the child command.
 */
#[CoversClass(ProcOpenChildProcess::class)]
#[CoversClass(Supervisor::class)]
#[CoversClass(ProcOpenChildLauncher::class)]
#[RequiresPhpExtension('pcntl')]
final class SupervisorProcessTest extends RepositoryTestCase
{
    use LiveDbSubprocessTrait;

    private const FIXTURE = __DIR__ . '/../../Support/job-runner-fixture.php';

    private function schedule(): JobScheduleRepository
    {
        self::assertNotNull(self::$pdo);

        return new JobScheduleRepository(self::$pdo);
    }

    private function launcher(): ProcOpenChildLauncher
    {
        foreach ($this->dbEnv() as $key => $value) {
            putenv("{$key}={$value}");
        }

        return new ProcOpenChildLauncher([PHP_BINARY, self::FIXTURE]);
    }

    /** Measured at ~11 s: a 1 s timeout, then the 10 s kill grace, in real time. */
    #[Group('slow')]
    public function testAHungChildIsKilledAtItsTimeoutAndRecordedTimedOut(): void
    {
        $registry   = new JobRegistry(
            new JobDefinition('hang', JobKind::INTERVAL, HangJob::class, static fn (): Job => new HangJob(), 60, 1),
            new JobDefinition('quick', JobKind::INTERVAL, OkJob::class, static fn (): Job => new OkJob(), 60, 5),
        );
        $real       = $this->launcher();
        $launcher   = new class ($real) implements ChildLauncher {
            /** @var array<string, ChildProcess> */
            public array $children = [];

            public function __construct(private readonly ChildLauncher $inner)
            {
            }

            public function start(string $jobName): ChildProcess
            {
                return $this->children[$jobName] = $this->inner->start($jobName);
            }
        };
        $supervisor = new Supervisor($registry, fn (): JobScheduleRepository => $this->schedule(), $launcher, new NullLogger(), 'sup:test');

        $deadline = microtime(true) + 20;
        do {
            $supervisor->tick();
            usleep(200_000);
            $rows = $this->schedule()->all();
        } while (
            ( $rows['hang']->lastStatus ?? null ) !== JobStatus::TIMED_OUT
            && microtime(true) < $deadline
        );
        $hung     = $launcher->children['hang'] ?? null;
        $deadline = microtime(true) + 2;
        while ($hung !== null && $hung->isRunning() && microtime(true) < $deadline) {
            usleep(100_000);
        }
        $stillRunning = $hung?->isRunning();
        $exitCode     = $hung?->exitCode();
        $supervisor->shutdown();

        self::assertSame(JobStatus::TIMED_OUT, $rows['hang']->lastStatus);
        self::assertNotNull($hung);
        self::assertFalse($stillRunning, 'SIGTERM was ignored, so SIGKILL must actually have ended the process');
        self::assertSame(128 + SIGKILL, $exitCode);
        self::assertSame(JobStatus::SUCCEEDED, $rows['quick']->lastStatus, 'the other job ran in its own child meanwhile');
    }

    public function testAStreamChildStopsCleanlyOnSigterm(): void
    {
        $this->schedule()->ensureRows(['loop']);
        $child = $this->launcher()->start('loop');

        $deadline = microtime(true) + 10;
        while ($this->schedule()->all()['loop']->leaseOwner === null && microtime(true) < $deadline) {
            usleep(100_000);
        }
        self::assertNotNull($this->schedule()->all()['loop']->leaseOwner, 'the child took its lease');

        $child->signal(SIGTERM);
        $deadline = microtime(true) + 7;
        while ($child->isRunning() && microtime(true) < $deadline) {
            usleep(100_000);
        }

        self::assertFalse($child->isRunning());
        self::assertSame(0, $child->exitCode());
        $row = $this->schedule()->all()['loop'];
        self::assertSame(JobStatus::SUCCEEDED, $row->lastStatus);
        self::assertNull($row->leaseOwner);
    }
}
