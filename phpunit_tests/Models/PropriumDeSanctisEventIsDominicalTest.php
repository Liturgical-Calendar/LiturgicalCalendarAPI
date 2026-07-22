<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Models;

use LiturgicalCalendar\Api\Enum\LitCommon;
use LiturgicalCalendar\Api\Enum\LitGrade;
use LiturgicalCalendar\Api\Models\Calendar\LitCommons;
use LiturgicalCalendar\Api\Models\Calendar\LiturgicalEvent;
use LiturgicalCalendar\Api\Models\Lectionary\ReadingsCommons;
use LiturgicalCalendar\Api\Models\PropriumDeSanctisEvent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PropriumDeSanctisEvent::class)]
#[CoversClass(LiturgicalEvent::class)]
final class PropriumDeSanctisEventIsDominicalTest extends TestCase
{
    /**
     * @param array<string,mixed> $overrides
     * @return \stdClass&object{event_key:string,day:int,month:int,color:string[],common:string[],grade:int}
     */
    private function makeRow(array $overrides = []): \stdClass
    {
        $row = (object) array_merge(
            [
                'event_key' => 'TestSanctisEvent',
                'day'       => 6,
                'month'     => 1,
                'color'     => ['white'],
                'common'    => [],
                'grade'     => LitGrade::FEAST_LORD->value,
            ],
            $overrides
        );

        /** @var \stdClass&object{event_key:string,day:int,month:int,color:string[],common:string[],grade:int} $row */
        return $row;
    }

    public function testIsDominicalIsSetTrueWhenPresentInRow(): void
    {
        $row   = $this->makeRow(['is_dominical' => true]);
        $event = PropriumDeSanctisEvent::fromObject($row);

        self::assertTrue($event->is_dominical);
    }

    public function testIsDominicalIsSetFalseWhenPresentInRow(): void
    {
        $row   = $this->makeRow(['is_dominical' => false]);
        $event = PropriumDeSanctisEvent::fromObject($row);

        self::assertFalse($event->is_dominical);
    }

    public function testIsDominicalDefaultsToNullWhenAbsentFromRow(): void
    {
        $row   = $this->makeRow();
        $event = PropriumDeSanctisEvent::fromObject($row);

        self::assertNull($event->is_dominical);
    }

    public function testLiturgicalEventFromObjectCarriesIsDominicalTrue(): void
    {
        $row          = $this->makeRow(['is_dominical' => true]);
        $sanctisEvent = PropriumDeSanctisEvent::fromObject($row);
        $sanctisEvent->setName('Test Sanctis Event');
        $sanctisEvent->setDate(new \LiturgicalCalendar\Api\DateTime('2026-01-06T00:00:00+00:00'));

        $litEvent = LiturgicalEvent::fromObject($sanctisEvent);

        self::assertTrue($litEvent->is_dominical);
    }

    public function testLiturgicalEventFromObjectLeavesIsDominicalNullWhenAbsentFromRow(): void
    {
        $row          = $this->makeRow();
        $sanctisEvent = PropriumDeSanctisEvent::fromObject($row);
        $sanctisEvent->setName('Test Sanctis Event');
        $sanctisEvent->setDate(new \LiturgicalCalendar\Api\DateTime('2026-01-06T00:00:00+00:00'));

        $litEvent            = LiturgicalEvent::fromObject($sanctisEvent);
        $litEvent->event_key = $sanctisEvent->event_key;
        $litEvent->setReadings(new ReadingsCommons(LitCommons::create([LitCommon::NONE])));

        self::assertNull($litEvent->is_dominical);
        self::assertArrayNotHasKey('is_dominical', $litEvent->jsonSerialize());
    }
}
