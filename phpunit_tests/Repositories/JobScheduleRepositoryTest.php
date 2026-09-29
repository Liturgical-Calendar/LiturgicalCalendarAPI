<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Repositories;

use LiturgicalCalendar\Api\Services\Jobs\JobStatus;
use LiturgicalCalendar\Api\Repositories\JobScheduleRepository;
use LiturgicalCalendar\Tests\Support\LiveDbSubprocessTrait;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(JobScheduleRepository::class)]
final class JobScheduleRepositoryTest extends RepositoryTestCase
{
    use LiveDbSubprocessTrait;

    private function repo(): JobScheduleRepository
    {
        self::assertNotNull(self::$pdo);

        return new JobScheduleRepository(self::$pdo);
    }

    public function testEnsureRowsCreatesDueRowsAndKeepsHistory(): void
    {
        $repo = $this->repo();
        $repo->ensureRows(['a']);
        self::assertSame(['a'], $repo->dueNames(['a']));

        self::assertTrue($repo->acquire('a', 'h:1', 60));
        self::assertTrue($repo->finish('a', 'h:1', JobStatus::SUCCEEDED, null, 5, 300));
        $repo->ensureRows(['a', 'b']);

        $rows = $repo->all();
        self::assertSame(JobStatus::SUCCEEDED, $rows['a']->lastStatus, 're-ensuring a row keeps its history');
        self::assertSame([], $repo->dueNames(['a']), 'next_due_at moved 300 s ahead');
        self::assertSame(['b'], $repo->dueNames(['a', 'b']));
    }

    public function testAcquireIsExclusiveUntilTheLeaseLapses(): void
    {
        $repo = $this->repo();
        $repo->ensureRows(['a']);

        self::assertTrue($repo->acquire('a', 'h:1', 60));
        self::assertFalse($repo->acquire('a', 'h:2', 60));
        self::assertSame([], $repo->dueNames(['a']), 'a leased job is not due');

        self::$pdo?->exec("UPDATE job_schedule SET lease_until = now() - interval '1 second'");
        self::assertTrue($repo->acquire('a', 'h:2', 60), 'a lapsed lease can be taken over');
        self::assertSame('h:2', $repo->all()['a']->leaseOwner);
    }

    public function testAcquireOnAnUnknownNameFails(): void
    {
        self::assertFalse($this->repo()->acquire('never-ensured', 'h:1', 60));
    }

    public function testRenewAndFinishAreOwnerGuarded(): void
    {
        $repo = $this->repo();
        $repo->ensureRows(['a']);
        self::assertTrue($repo->acquire('a', 'h:1', 60));

        self::assertFalse($repo->renew('a', 'h:2', 60));
        self::assertFalse($repo->finish('a', 'h:2', JobStatus::FAILED, 'x', 1, 60));
        self::assertTrue($repo->renew('a', 'h:1', 60));

        $row = $repo->all()['a'];
        self::assertNull($row->lastStatus, 'a stranger recorded nothing');
        self::assertSame('h:1', $row->leaseOwner);
    }

    public function testFailuresCountUpAndASuccessResetsThem(): void
    {
        $repo = $this->repo();
        $repo->ensureRows(['a']);
        foreach ([JobStatus::FAILED, JobStatus::REFUSED, JobStatus::TIMED_OUT] as $status) {
            self::assertTrue($repo->acquire('a', 'h:1', 60));
            self::assertTrue($repo->finish('a', 'h:1', $status, 'boom', 1, 60));
        }
        $row = $repo->all()['a'];
        self::assertSame(3, $row->consecutiveFailures);
        self::assertSame(JobStatus::TIMED_OUT, $row->lastStatus);
        self::assertNull($row->lastSuccessAt);

        self::assertTrue($repo->acquire('a', 'h:1', 60));
        self::assertTrue($repo->finish('a', 'h:1', JobStatus::SUCCEEDED, null, 7, 60));
        $row = $repo->all()['a'];
        self::assertSame(0, $row->consecutiveFailures);
        self::assertNotNull($row->lastSuccessAt);
        self::assertNull($row->lastError);
        self::assertSame(7, $row->lastDurationMs);
        self::assertNull($row->leaseOwner);
        self::assertNull($row->leaseUntil);
    }

    public function testAStreamFinishLeavesNextDueAlone(): void
    {
        $repo = $this->repo();
        $repo->ensureRows(['s']);
        $before = $repo->all()['s']->nextDueAt;

        self::assertTrue($repo->acquire('s', 'h:1', 60));
        self::assertTrue($repo->finish('s', 'h:1', JobStatus::SUCCEEDED, null, 1, null));

        self::assertEquals($before, $repo->all()['s']->nextDueAt);
    }

    /** Review Focus 4: a huge error message is stored truncated, by characters, not bytes. */
    public function testErrorsAreTruncated(): void
    {
        $repo = $this->repo();
        $repo->ensureRows(['a']);
        self::assertTrue($repo->acquire('a', 'h:1', 60));
        self::assertTrue($repo->finish('a', 'h:1', JobStatus::FAILED, str_repeat('é', 5000), 1, 60));

        self::assertSame(JobScheduleRepository::MAX_ERROR_LENGTH, mb_strlen((string) $repo->all()['a']->lastError));
    }

    public function testReleaseClearsOnlyTheOwnersLease(): void
    {
        $repo = $this->repo();
        $repo->ensureRows(['supervisor']);
        self::assertTrue($repo->acquire('supervisor', 'h:1', 60));

        $repo->release('supervisor', 'h:2');
        self::assertSame('h:1', $repo->all()['supervisor']->leaseOwner);

        $repo->release('supervisor', 'h:1');
        self::assertNull($repo->all()['supervisor']->leaseOwner);
        self::assertNull($repo->all()['supervisor']->lastStatus, 'release records no outcome');
    }

    public function testNowIsTheDatabaseClock(): void
    {
        $now = $this->repo()->now();
        self::assertLessThan(5, abs($now->getTimestamp() - time()));
    }

    public function testTwoProcessesRaceForOneLeaseAndExactlyOneWins(): void
    {
        $this->repo()->ensureRows(['race']);
        $code = $this->pdoBootstrap() . <<<'PHP'

            $repo = new \LiturgicalCalendar\Api\Repositories\JobScheduleRepository($pdo);
            echo $repo->acquire('race', 'h:' . getmypid(), 60) ? '1' : '0';
            PHP;

        $results = $this->runConcurrently([[PHP_BINARY, '-r', $code], [PHP_BINARY, '-r', $code]]);
        foreach ($results as [$out, $err, $exit]) {
            self::assertSame(0, $exit, "a racer failed:\n{$err}");
        }

        self::assertSame(1, (int) $results[0][0] + (int) $results[1][0], 'exactly one process may hold the lease');
    }
}
