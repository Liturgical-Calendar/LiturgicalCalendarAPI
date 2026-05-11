<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Models;

use LiturgicalCalendar\Api\Enum\DateRelation;
use LiturgicalCalendar\Api\Models\RelativeLiturgicalDate;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RelativeLiturgicalDate::class)]
final class RelativeLiturgicalDateTest extends TestCase
{
    public function testFromArrayHappyPath(): void
    {
        $rel = RelativeLiturgicalDate::fromArray([
            'day_of_the_week' => 'Monday',
            'relative_time'   => 'after',
            'event_key'       => 'Pentecost',
        ]);
        self::assertSame('Monday', $rel->day_of_the_week);
        self::assertSame(DateRelation::After, $rel->relative_time);
        self::assertSame('Pentecost', $rel->event_key);
        self::assertSame('Monday after Pentecost', (string) $rel);
    }

    public function testFromArrayRejectsMissingKeys(): void
    {
        $this->expectException(\ValueError::class);
        $this->expectExceptionMessage('event_key');
        RelativeLiturgicalDate::fromArray([
            'day_of_the_week' => 'Monday',
            'relative_time'   => 'after',
        ]);
    }

    public function testFromArrayRejectsInvalidRelativeTime(): void
    {
        $this->expectException(\ValueError::class);
        RelativeLiturgicalDate::fromArray([
            'day_of_the_week' => 'Monday',
            'relative_time'   => 'around',
            'event_key'       => 'Pentecost',
        ]);
    }

    public function testFromObjectHappyPath(): void
    {
        $rel = RelativeLiturgicalDate::fromObject((object) [
            'day_of_the_week' => 'Sunday',
            'relative_time'   => 'before',
            'event_key'       => 'CorpusChristi',
        ]);
        self::assertSame(DateRelation::Before, $rel->relative_time);
        self::assertSame('Sunday before CorpusChristi', (string) $rel);
    }

    public function testFromObjectRejectsMissingProps(): void
    {
        $this->expectException(\ValueError::class);
        RelativeLiturgicalDate::fromObject((object) ['day_of_the_week' => 'Monday']);
    }
}
