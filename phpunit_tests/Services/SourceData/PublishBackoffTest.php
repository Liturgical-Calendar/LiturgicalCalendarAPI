<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Services\SourceData;

use LiturgicalCalendar\Api\Repositories\SourceDataChangeRequestRepository;
use LiturgicalCalendar\Api\Services\Outbox\OutboxBackoff;
use LiturgicalCalendar\Api\Services\SourceData\PublishBackoff;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PublishBackoff::class)]
final class PublishBackoffTest extends TestCase
{
    public function testTheScheduleDoublesFromTheCronIntervalAndThenCaps(): void
    {
        self::assertSame(300, PublishBackoff::secondsForAttempt(1));
        self::assertSame(600, PublishBackoff::secondsForAttempt(2));
        self::assertSame(1200, PublishBackoff::secondsForAttempt(3));
        self::assertSame(2400, PublishBackoff::secondsForAttempt(4));
        self::assertSame(4800, PublishBackoff::secondsForAttempt(5));
        self::assertSame(4800, PublishBackoff::secondsForAttempt(99), 'the cap is total, not open-ended');
    }

    public function testAttemptCountsBelowOneAreRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        PublishBackoff::secondsForAttempt(0);
    }

    /**
     * The reason this class exists rather than reusing the outbox's curve, asserted rather than
     * only argued in a docblock: the outbox schedule is budgeted across ten attempts, so spending
     * the publisher's five would park a batch in seconds — on a GitHub blip a human would call
     * brief. Guards against a future "deduplicate these two backoffs" simplification.
     */
    public function testItIsStrictlyMorePatientThanTheOutboxCurveAcrossThePublisherSAttemptBound(): void
    {
        $bound  = SourceDataChangeRequestRepository::MAX_PUBLISH_ATTEMPTS;
        $ours   = 0;
        $outbox = 0;

        // The gaps a batch actually experiences: the final failure parks it, so there is no wait
        // after it.
        for ($attempt = 1; $attempt < $bound; $attempt++) {
            $ours   += PublishBackoff::secondsForAttempt($attempt);
            $outbox += OutboxBackoff::secondsForAttempt($attempt);
        }

        self::assertSame(4500, $ours);
        self::assertSame(15, $outbox);
        self::assertGreaterThan(
            $bound * 300,
            $ours,
            'the budget to park must exceed what the cron interval the bound was sized against gave it'
        );
    }
}
