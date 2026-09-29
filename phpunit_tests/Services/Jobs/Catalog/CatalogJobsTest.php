<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Services\Jobs\Catalog;

use LiturgicalCalendar\Api\Services\Jobs\Catalog\MergePollJob;
use LiturgicalCalendar\Api\Services\Jobs\Catalog\OutboxBackstopJob;
use LiturgicalCalendar\Api\Services\Jobs\Catalog\OutboxConsumerJob;
use LiturgicalCalendar\Api\Services\Jobs\Catalog\PublishBackstopJob;
use LiturgicalCalendar\Api\Services\Jobs\Catalog\PublishConsumerJob;
use LiturgicalCalendar\Api\Services\Jobs\Catalog\ResourceTupleSweepJob;
use LiturgicalCalendar\Api\Services\Jobs\Catalog\WiderRegionMembershipJob;
use LiturgicalCalendar\Api\Services\Jobs\JobContext;
use LiturgicalCalendar\Api\Services\SourceData\MergePollRunResult;
use LiturgicalCalendar\Api\Services\SourceData\PublishRunResult;
use LiturgicalCalendar\Api\Services\SourceTreeGuard;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversClass(OutboxConsumerJob::class)]
#[CoversClass(PublishConsumerJob::class)]
#[CoversClass(OutboxBackstopJob::class)]
#[CoversClass(PublishBackstopJob::class)]
#[CoversClass(MergePollJob::class)]
#[CoversClass(WiderRegionMembershipJob::class)]
#[CoversClass(ResourceTupleSweepJob::class)]
final class CatalogJobsTest extends TestCase
{
    /** @var list<string> */
    private array $said = [];

    private bool $stop = false;

    private string $emptyDir;

    private string $populatedDir;

    protected function setUp(): void
    {
        $this->said         = [];
        $this->stop         = false;
        $base               = sys_get_temp_dir() . '/cjt_' . uniqid();
        $this->emptyDir     = $base . '/empty';
        $this->populatedDir = $base . '/populated';
        mkdir($this->emptyDir, 0777, true);
        mkdir($this->populatedDir . '/IT', 0777, true);
        file_put_contents($this->populatedDir . '/IT/IT.json', '{}');
    }

    protected function tearDown(): void
    {
        @unlink($this->populatedDir . '/IT/IT.json');
        @rmdir($this->populatedDir . '/IT');
        @rmdir($this->populatedDir);
        @rmdir($this->emptyDir);
        @rmdir(dirname($this->emptyDir));
    }

    private function context(bool $dryRun = false): JobContext
    {
        return new JobContext($dryRun, fn (): bool => $this->stop, new NullLogger(), function (string $line): void {
            $this->said[] = $line;
        });
    }

    public function testTheConsumerJobsHandTheStopRequestToTheirLoop(): void
    {
        foreach ([OutboxConsumerJob::class, PublishConsumerJob::class] as $class) {
            $seen       = [];
            $job        = new $class(function (callable $shouldStop) use (&$seen): void {
                $seen[]     = $shouldStop();
                $this->stop = true;
                $seen[]     = $shouldStop();
            });
            $this->stop = false;
            $job->run($this->context());
            self::assertSame([false, true], $seen, $class . ' forwards the context\'s stop flag');
        }
    }

    public function testTheOutboxBackstopReportsWhatItProcessed(): void
    {
        ( new OutboxBackstopJob(static fn (): int => 3) )->run($this->context());

        self::assertSame(['backstop processed=3'], $this->said);
    }

    public function testAPublishRunThatStoppedOnAFailureFailsTheJob(): void
    {
        ( new PublishBackstopJob(static fn (): PublishRunResult => new PublishRunResult(2, false, 1)) )->run($this->context());
        self::assertSame(['publish-sourcedata published=2 stopped_on_failure=false parked=1'], $this->said);

        $this->expectException(\RuntimeException::class);
        ( new PublishBackstopJob(static fn (): PublishRunResult => new PublishRunResult(0, true)) )->run($this->context());
    }

    public function testAMergePollThatStoppedOnAFailureFailsTheJob(): void
    {
        ( new MergePollJob(static fn (): MergePollRunResult => new MergePollRunResult(1, 2, 3, 4)) )->run($this->context());
        self::assertSame(['poll-sourcedata-merges merged=1 closed=2 reset=3 unpollable=4 stopped_on_failure=false'], $this->said);

        $this->expectException(\RuntimeException::class);
        ( new MergePollJob(static fn (): MergePollRunResult => new MergePollRunResult(stoppedOnFailure: true)) )->run($this->context());
    }

    public function testTheDestructiveJobsRefuseAnEmptyTreeAndAllowAPopulatedOne(): void
    {
        $never = static function (bool $apply): array {
            throw new \LogicException('must not run');
        };

        self::assertNotNull(( new WiderRegionMembershipJob(new SourceTreeGuard($this->emptyDir), $never) )->preflight());
        self::assertNotNull(( new ResourceTupleSweepJob(new SourceTreeGuard($this->emptyDir), $never) )->preflight());
        self::assertNull(( new WiderRegionMembershipJob(new SourceTreeGuard($this->populatedDir), $never) )->preflight());
        self::assertNull(( new ResourceTupleSweepJob(new SourceTreeGuard($this->populatedDir), $never) )->preflight());
    }

    public function testTheMembershipJobAppliesOnlyOutsideADryRunAndListsItsChanges(): void
    {
        $applied = [];
        $job     = new WiderRegionMembershipJob(new SourceTreeGuard($this->populatedDir), static function (bool $apply) use (&$applied): array {
            $applied[] = $apply;

            return ['writes' => ['wider_region:Europe#member_nation@nation:IT'], 'deletes' => ['wider_region:Asia#member_nation@nation:IT'], 'skipped' => ['XX']];
        });

        $job->run($this->context(dryRun: true));
        $job->run($this->context());

        self::assertSame([false, true], $applied);
        self::assertContains('+ wider_region:Europe#member_nation@nation:IT', $this->said);
        self::assertContains('- wider_region:Asia#member_nation@nation:IT', $this->said);
        self::assertContains('Planned: 1 writes, 1 deletes (dry run)', $this->said);
        self::assertContains('Applied: 1 writes, 1 deletes', $this->said);
    }

    public function testTheSweepJobAppliesOnlyOutsideADryRunAndListsItsObjects(): void
    {
        $applied = [];
        $job     = new ResourceTupleSweepJob(new SourceTreeGuard($this->populatedDir), static function (bool $apply) use (&$applied): array {
            $applied[] = $apply;

            return ['scanned' => 9, 'purgedObjects' => 1, 'enqueued' => $apply ? 2 : 0, 'objects' => ['national_calendar:ZZ' => 2]];
        });

        $job->run($this->context(dryRun: true));
        $job->run($this->context());

        self::assertSame([false, true], $applied);
        self::assertContains('- national_calendar:ZZ (2 operational tuples)', $this->said);
        self::assertContains('Planned: scanned 9 tuples, 1 objects to purge (dry run)', $this->said);
        self::assertContains('Applied: scanned 9 tuples, purged 1 objects, enqueued 2 rows', $this->said);
    }
}
