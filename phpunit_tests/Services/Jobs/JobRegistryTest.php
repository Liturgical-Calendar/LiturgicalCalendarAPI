<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Services\Jobs;

use LiturgicalCalendar\Api\Services\Jobs\Job;
use LiturgicalCalendar\Api\Services\Jobs\JobContext;
use LiturgicalCalendar\Api\Services\Jobs\JobDefinition;
use LiturgicalCalendar\Api\Services\Jobs\JobKind;
use LiturgicalCalendar\Api\Services\Jobs\JobRegistry;
use LiturgicalCalendar\Tests\Services\Jobs\Fixtures\GuardedJob;
use LiturgicalCalendar\Tests\Services\Jobs\Fixtures\OkJob;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversClass(JobRegistry::class)]
#[CoversClass(JobDefinition::class)]
#[CoversClass(JobContext::class)]
final class JobRegistryTest extends TestCase
{
    private static function interval(string $name = 'tick', string $class = OkJob::class, ?int $interval = 60, ?int $timeout = 30): JobDefinition
    {
        return new JobDefinition($name, JobKind::INTERVAL, $class, static fn (): Job => new $class(), $interval, $timeout);
    }

    public function testAValidRegistryAnswersByNameAndKind(): void
    {
        $registry = new JobRegistry(
            self::interval('tick'),
            new JobDefinition('loop', JobKind::STREAM, OkJob::class, static fn (): Job => new OkJob())
        );

        self::assertSame(['tick', 'loop'], $registry->names());
        self::assertSame(['loop'], $registry->namesOfKind(JobKind::STREAM));
        self::assertSame(['tick'], $registry->namesOfKind(JobKind::INTERVAL));
        self::assertSame('tick', $registry->get('tick')?->name);
        self::assertNull($registry->get('nope'));
        self::assertCount(2, $registry->all());
    }

    /** @return array<string, array{string}> */
    public static function badNames(): array
    {
        return ['uppercase' => ['Tick'], 'leading digit' => ['1tick'], 'space' => ['my job'], 'empty' => [''], 'underscore' => ['my_job']];
    }

    #[DataProvider('badNames')]
    public function testAnInvalidNameIsRejected(string $name): void
    {
        $this->expectException(\InvalidArgumentException::class);
        self::interval($name);
    }

    public function testTheReservedNameIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new JobRegistry(self::interval(JobRegistry::RESERVED_NAME));
    }

    public function testADuplicateNameIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new JobRegistry(self::interval('tick'), self::interval('tick'));
    }

    /** @return array<string, array{?int, ?int}> */
    public static function badIntervalSettings(): array
    {
        return ['no interval' => [null, 30], 'zero interval' => [0, 30], 'no timeout' => [60, null], 'negative timeout' => [60, -1]];
    }

    #[DataProvider('badIntervalSettings')]
    public function testAnIntervalJobNeedsAPositiveIntervalAndTimeout(?int $interval, ?int $timeout): void
    {
        $this->expectException(\InvalidArgumentException::class);
        self::interval('tick', OkJob::class, $interval, $timeout);
    }

    public function testAStreamJobTakesNoIntervalOrTimeout(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new JobDefinition('loop', JobKind::STREAM, OkJob::class, static fn (): Job => new OkJob(), 60, null);
    }

    public function testAClassThatIsNotAJobIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        // @phpstan-ignore argument.type
        new JobDefinition('tick', JobKind::INTERVAL, \stdClass::class, static fn (): Job => new OkJob(), 60, 30);
    }

    public function testDestructivenessFollowsTheClass(): void
    {
        self::assertFalse(self::interval('tick')->isDestructive());
        self::assertTrue(self::interval('sweep', GuardedJob::class)->isDestructive());
    }

    public function testMakeRejectsAFactoryThatBuildsAnotherClass(): void
    {
        $definition = new JobDefinition('sweep', JobKind::INTERVAL, GuardedJob::class, static fn (): Job => new OkJob(), 60, 30);

        $this->expectException(\LogicException::class);
        $definition->make();
    }

    public function testMakeBuildsTheJob(): void
    {
        self::assertInstanceOf(OkJob::class, self::interval('tick')->make());
    }

    public function testLeaseLengths(): void
    {
        self::assertSame(60, ( new JobDefinition('loop', JobKind::STREAM, OkJob::class, static fn (): Job => new OkJob()) )->leaseSeconds());
        self::assertSame(30 + JobDefinition::INTERVAL_LEASE_MARGIN_SECONDS, self::interval('tick', OkJob::class, 60, 30)->leaseSeconds());
    }

    public function testTheContextReportsStopAndWritesThroughItsWriter(): void
    {
        $stop    = false;
        $lines   = [];
        $context = new JobContext(true, static function () use (&$stop): bool {
            return $stop;
        }, new NullLogger(), static function (string $line) use (&$lines): void {
            $lines[] = $line;
        });

        self::assertTrue($context->dryRun);
        self::assertFalse($context->shouldStop());
        $stop = true;
        self::assertTrue($context->shouldStop());
        $context->say('hello');
        self::assertSame(['hello'], $lines);
    }

    public function testTheContextWritesToStandardOutputByDefault(): void
    {
        $this->expectOutputString("hello\n");
        ( new JobContext(false, static fn (): bool => false, new NullLogger()) )->say('hello');
    }
}
