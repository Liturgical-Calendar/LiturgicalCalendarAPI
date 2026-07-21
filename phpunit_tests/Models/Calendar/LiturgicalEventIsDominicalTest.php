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
final class LiturgicalEventIsDominicalTest extends TestCase
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

    public function testIsDominicalDefaultsToNull(): void
    {
        $event = $this->makeEvent();

        self::assertNull($event->is_dominical);
    }

    public function testIsDominicalIsOmittedFromJsonSerializeWhenNull(): void
    {
        $event = $this->makeEvent();

        $serialized = $event->jsonSerialize();

        self::assertArrayNotHasKey('is_dominical', $serialized);
    }

    public function testIsDominicalIsIncludedInJsonSerializeWhenSetTrue(): void
    {
        $event               = $this->makeEvent();
        $event->is_dominical = true;

        $serialized = $event->jsonSerialize();

        self::assertArrayHasKey('is_dominical', $serialized);
        self::assertTrue($serialized['is_dominical']);
    }

    public function testIsDominicalIsIncludedInJsonSerializeWhenSetFalse(): void
    {
        $event               = $this->makeEvent();
        $event->is_dominical = false;

        $serialized = $event->jsonSerialize();

        self::assertArrayHasKey('is_dominical', $serialized);
        self::assertFalse($serialized['is_dominical']);
    }
}
