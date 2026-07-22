<?php

declare(strict_types=1);

namespace LiturgicalCalendar\Tests\Models\Calendar;

use LiturgicalCalendar\Api\DateTime;
use LiturgicalCalendar\Api\Enum\LitCommon;
use LiturgicalCalendar\Api\Models\Calendar\LitCommons;
use LiturgicalCalendar\Api\Models\Calendar\LiturgicalEvent;
use LiturgicalCalendar\Api\Models\Lectionary\ReadingsCommons;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(LiturgicalEvent::class)]
final class LiturgicalEventIsAliturgicalTest extends TestCase
{
    private function makeEvent(): LiturgicalEvent
    {
        $event            = new LiturgicalEvent(
            'Test Event',
            new DateTime('2026-07-20T00:00:00+00:00')
        );
        $event->event_key = 'TestEvent';
        $event->setReadings(new ReadingsCommons(LitCommons::create([LitCommon::NONE])));

        return $event;
    }

    public function testIsAliturgicalDefaultsNullAndIsOmittedFromSerialization(): void
    {
        $event = $this->makeEvent();

        self::assertNull($event->is_aliturgical);
        self::assertArrayNotHasKey('is_aliturgical', $event->jsonSerialize());
    }

    public function testIsAliturgicalSerializesWhenSet(): void
    {
        $event                 = $this->makeEvent();
        $event->is_aliturgical = true;

        self::assertTrue($event->jsonSerialize()['is_aliturgical']);
    }
}
