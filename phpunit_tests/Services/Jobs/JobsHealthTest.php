<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Services\Jobs;

use LiturgicalCalendar\Api\Services\Jobs\Job;
use LiturgicalCalendar\Api\Services\Jobs\JobDefinition;
use LiturgicalCalendar\Api\Services\Jobs\JobKind;
use LiturgicalCalendar\Api\Services\Jobs\JobRegistry;
use LiturgicalCalendar\Api\Services\Jobs\JobScheduleRow;
use LiturgicalCalendar\Api\Services\Jobs\JobsHealth;
use LiturgicalCalendar\Api\Services\Jobs\JobStatus;
use LiturgicalCalendar\Api\Services\Jobs\Supervisor;
use LiturgicalCalendar\Tests\Services\Jobs\Fixtures\LoopJob;
use LiturgicalCalendar\Tests\Services\Jobs\Fixtures\OkJob;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(JobScheduleRow::class)]
#[CoversClass(JobsHealth::class)]
final class JobsHealthTest extends TestCase
{
    private \DateTimeImmutable $now;

    private JobRegistry $registry;

    protected function setUp(): void
    {
        $this->now      = new \DateTimeImmutable('2026-09-29T12:00:00+00:00');
        $this->registry = new JobRegistry(
            new JobDefinition('tick', JobKind::INTERVAL, OkJob::class, static fn (): Job => new OkJob(), 300, 60),
            new JobDefinition('loop', JobKind::STREAM, LoopJob::class, static fn (): Job => new LoopJob()),
        );
    }

    private function at(string $offset): \DateTimeImmutable
    {
        return $this->now->modify($offset);
    }

    private function row(string $name, array $overrides = []): JobScheduleRow
    {
        $values = $overrides + [
            'nextDueAt'           => $this->at('+100 seconds'),
            'leaseOwner'          => null,
            'leaseUntil'          => null,
            'lastFinishedAt'      => $this->at('-200 seconds'),
            'lastSuccessAt'       => $this->at('-200 seconds'),
            'lastStatus'          => JobStatus::SUCCEEDED,
            'lastError'           => null,
            'consecutiveFailures' => 0,
        ];

        return new JobScheduleRow(
            $name,
            $values['nextDueAt'],
            $values['leaseOwner'],
            $values['leaseUntil'],
            null,
            $values['lastFinishedAt'],
            $values['lastSuccessAt'],
            $values['lastStatus'],
            $values['lastError'],
            12,
            $values['consecutiveFailures']
        );
    }

    /** @return array<string, JobScheduleRow> */
    private function healthyRows(): array
    {
        return [
            Supervisor::LEASE_NAME => $this->row(Supervisor::LEASE_NAME, ['leaseOwner' => 'h:1', 'leaseUntil' => $this->at('+50 seconds')]),
            'tick'                 => $this->row('tick'),
            'loop'                 => $this->row('loop', ['leaseOwner' => 'h:2', 'leaseUntil' => $this->at('+40 seconds')]),
        ];
    }

    /** @param array<string, JobScheduleRow> $rows */
    private function summarize(array $rows, array $disabled = []): array
    {
        return JobsHealth::summarize($this->registry, $rows, $this->now, $disabled);
    }

    public function testAllHealthyIsOk(): void
    {
        $result = $this->summarize($this->healthyRows());

        self::assertSame('ok', $result['status']);
        self::assertSame(['alive' => true, 'lease_age_seconds' => 10], $result['supervisor']);
        self::assertFalse($result['jobs']['tick']['overdue']);
        self::assertSame(20, $result['jobs']['loop']['heartbeat_age_seconds']);
        self::assertTrue($result['jobs']['loop']['running']);
        self::assertSame('interval', $result['jobs']['tick']['kind']);
        self::assertSame(300, $result['jobs']['tick']['interval_seconds']);
        self::assertSame('succeeded', $result['jobs']['tick']['last_status']);
        self::assertSame('2026-09-29T11:56:40+00:00', $result['jobs']['tick']['last_success_at']);
    }

    public function testADeadSupervisorWarns(): void
    {
        $rows                         = $this->healthyRows();
        $rows[Supervisor::LEASE_NAME] = $this->row(Supervisor::LEASE_NAME, ['leaseOwner' => 'h:1', 'leaseUntil' => $this->at('-5 seconds')]);

        $result = $this->summarize($rows);

        self::assertSame('warning', $result['status']);
        self::assertFalse($result['supervisor']['alive']);
        self::assertStringContainsString('supervisor', $result['message']);
    }

    public function testAnOverdueIntervalJobWarnsUnlessDisabled(): void
    {
        $rows         = $this->healthyRows();
        $rows['tick'] = $this->row('tick', ['nextDueAt' => $this->at('-301 seconds')]);

        $result = $this->summarize($rows);
        self::assertSame('warning', $result['status']);
        self::assertTrue($result['jobs']['tick']['overdue']);
        self::assertStringContainsString('tick', $result['message']);

        $result = $this->summarize($rows, ['tick']);
        self::assertSame('ok', $result['status']);
        self::assertTrue($result['jobs']['tick']['disabled']);
    }

    public function testAJobJustPastItsDueTimeIsNotYetOverdue(): void
    {
        $rows         = $this->healthyRows();
        $rows['tick'] = $this->row('tick', ['nextDueAt' => $this->at('-299 seconds')]);

        self::assertFalse($this->summarize($rows)['jobs']['tick']['overdue']);
    }

    public function testAStreamJobWithoutALiveLeaseWarns(): void
    {
        $rows         = $this->healthyRows();
        $rows['loop'] = $this->row('loop', ['leaseOwner' => 'h:2', 'leaseUntil' => $this->at('-1 second')]);

        $result = $this->summarize($rows);

        self::assertSame('warning', $result['status']);
        self::assertFalse($result['jobs']['loop']['running']);
        self::assertNull($result['jobs']['loop']['heartbeat_age_seconds']);
    }

    public function testALastRunThatDidNotSucceedWarns(): void
    {
        $rows         = $this->healthyRows();
        $rows['tick'] = $this->row('tick', ['lastStatus' => JobStatus::REFUSED, 'consecutiveFailures' => 2, 'lastError' => 'tree missing']);

        $result = $this->summarize($rows);

        self::assertSame('warning', $result['status']);
        self::assertSame('refused', $result['jobs']['tick']['last_status']);
        self::assertSame(2, $result['jobs']['tick']['consecutive_failures']);
    }

    /**
     * A stream job records `last_status` only when it exits, so one crash followed by a healthy restart would
     * otherwise warn until the next clean shutdown. A live lease is a stream job's health signal.
     */
    public function testARunningStreamJobDoesNotWarnAboutAnEarlierExit(): void
    {
        $rows         = $this->healthyRows();
        $rows['loop'] = $this->row('loop', ['leaseOwner' => 'h:2', 'leaseUntil' => $this->at('+40 seconds'), 'lastStatus' => JobStatus::FAILED, 'consecutiveFailures' => 1]);

        $result = $this->summarize($rows);

        self::assertSame('ok', $result['status']);
        self::assertSame('failed', $result['jobs']['loop']['last_status'], 'still reported, just not a warning');
    }

    public function testAJobWithNoRowYetIsReportedWithNulls(): void
    {
        $rows = $this->healthyRows();
        unset($rows['tick']);

        $tick = $this->summarize($rows)['jobs']['tick'];

        self::assertNull($tick['last_status']);
        self::assertNull($tick['next_due_at']);
        self::assertFalse($tick['overdue']);
    }

    /** Review Focus 4: /health is unauthenticated; error text never leaves through it. */
    public function testErrorTextIsNeverReported(): void
    {
        $rows         = $this->healthyRows();
        $rows['tick'] = $this->row('tick', ['lastStatus' => JobStatus::FAILED, 'lastError' => 'secret-token-abc123']);

        self::assertStringNotContainsString('secret-token-abc123', (string) json_encode($this->summarize($rows)));
    }
}
