<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Models\Calendar;

use LiturgicalCalendar\Api\DateTime;
use LiturgicalCalendar\Api\Enum\LitColor;
use LiturgicalCalendar\Api\Enum\LitCommon;
use LiturgicalCalendar\Api\Enum\LitEventType;
use LiturgicalCalendar\Api\Enum\LitGrade;
use LiturgicalCalendar\Api\Enum\LitMassVariousNeeds;
use LiturgicalCalendar\Api\Models\Calendar\LiturgicalEvent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(LiturgicalEvent::class)]
final class LiturgicalEventConstructorCommonTest extends TestCase
{
    /**
     * @param LitCommon[]|LitMassVariousNeeds[] $common
     */
    private function makeEvent(array $common): LiturgicalEvent
    {
        return new LiturgicalEvent(
            'Test Event',
            new DateTime('2026-07-20T00:00:00+00:00'),
            LitColor::GREEN,
            LitEventType::FIXED,
            LitGrade::WEEKDAY,
            $common
        );
    }

    public function testAnArrayOfLitMassVariousNeedsIsKeptAsGiven(): void
    {
        $common = [LitMassVariousNeeds::PRO_ECCLESIA, LitMassVariousNeeds::PRO_PAPA];

        self::assertSame($common, $this->makeEvent($common)->common);
    }

    public function testALitCommonMixedIntoALitMassVariousNeedsArrayIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        // Both are enum cases, so gettype() calls both 'object'; the check must compare their classes.
        $this->makeEvent([LitMassVariousNeeds::PRO_ECCLESIA, LitCommon::NONE]);
    }
}
